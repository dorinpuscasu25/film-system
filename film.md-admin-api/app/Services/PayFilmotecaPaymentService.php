<?php

namespace App\Services;

use App\Models\PaymentTopUp;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class PayFilmotecaPaymentService
{
    public function __construct(
        protected WalletService $wallets,
    ) {
    }

    public function initiateTopUp(User $user, Wallet $wallet, array $payload, Request $request): PaymentTopUp
    {
        $this->ensureConfigured();

        $subscriberId = $this->resolveSubscriberId($user, $wallet);
        $this->ensureSubscriber($subscriberId);

        $amount = round((float) $payload['amount'], 2);
        $currency = strtoupper((string) ($payload['currency'] ?? $wallet->currency ?? Wallet::DEFAULT_CURRENCY));
        $description = 'Suplinire cont Filmoteca.md';
        $topUp = PaymentTopUp::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'subscriber_id' => $subscriberId,
            'amount' => $amount,
            'currency' => $currency,
            'status' => PaymentTopUp::STATUS_PENDING,
            'description' => $description,
        ]);
        $successUrl = $this->appendQuery((string) config('services.pay_filmoteca.success_url'), ['topup_id' => $topUp->uuid]);
        $failedUrl = $this->appendQuery((string) config('services.pay_filmoteca.failed_url'), ['topup_id' => $topUp->uuid]);
        $callbackUrl = $this->appendQuery((string) config('services.pay_filmoteca.callback_url'), ['topup_id' => $topUp->uuid]);
        $providerPayload = [
            'subscriber_id' => $subscriberId,
            'amount' => 1,
            'currency' => $currency,
            'total' => number_format($amount, 2, '.', ''),
            'description' => $description,
            'name' => $user->name ?: $user->email,
            'email' => $user->email,
            'phone' => (string) ($payload['phone'] ?? ''),
            'client_ip_addr' => $request->ip() ?: '',
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
            'lang' => $this->normalizeLocale((string) ($payload['locale'] ?? $user->preferred_locale ?? 'ro')),
            'callback_url' => $callbackUrl,
            'CallbackURL' => $callbackUrl,
            'success_url' => $successUrl,
            'SuccessURL' => $successUrl,
            'failed_url' => $failedUrl,
            'FailedURL' => $failedUrl,
        ];

        $topUp->update([
            'raw_request' => $providerPayload,
        ]);

        try {
            $response = $this->providerPost('payment-request', $providerPayload);
            $rawResponse = $this->responsePayload($response);

            if ($response->failed()) {
                $topUp->update([
                    'status' => PaymentTopUp::STATUS_FAILED,
                    'raw_response' => $rawResponse,
                    'provider_status' => 'request_failed',
                ]);

                throw ValidationException::withMessages([
                    'payment' => [$this->providerErrorMessage($rawResponse, 'Providerul de plată a refuzat cererea.')],
                ]);
            }

            $paymentUrl = $this->extractUrl($rawResponse);
            $orderIdValue = $this->extractValue($rawResponse, ['order_id', 'orderId', 'OrderID', 'payment_id', 'paymentId', 'id']);
            $orderId = is_scalar($orderIdValue) && (string) $orderIdValue !== ''
                ? (string) $orderIdValue
                : null;

            if ($paymentUrl === null) {
                $topUp->update([
                    'status' => PaymentTopUp::STATUS_FAILED,
                    'raw_response' => $rawResponse,
                    'provider_order_id' => $orderId,
                    'provider_status' => 'missing_redirect_url',
                ]);

                throw ValidationException::withMessages([
                    'payment' => ['Providerul nu a returnat URL-ul de plată.'],
                ]);
            }

            $orderId ??= $this->extractUuidFromText($paymentUrl);

            $topUp->update([
                'status' => PaymentTopUp::STATUS_PROCESSING,
                'provider_order_id' => $orderId,
                'provider_payment_url' => $paymentUrl,
                'raw_response' => $rawResponse,
            ]);

            return $topUp->fresh();
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $topUp->update([
                'status' => PaymentTopUp::STATUS_FAILED,
                'provider_status' => 'request_error',
                'raw_response' => [
                    'message' => $exception->getMessage(),
                ],
            ]);

            throw ValidationException::withMessages([
                'payment' => ['Nu am putut iniția plata. Încearcă din nou.'],
            ]);
        }
    }

    public function refreshStatus(PaymentTopUp $topUp): PaymentTopUp
    {
        if ($topUp->isTerminal() || empty($topUp->provider_order_id)) {
            return $topUp->fresh();
        }

        $this->ensureConfigured();

        try {
            $response = $this->providerPost('payment-details', [
                'order_id' => $topUp->provider_order_id,
            ]);
            $rawDetails = $this->responsePayload($response);
        } catch (\Throwable $exception) {
            $topUp->update([
                'raw_details' => [
                    'message' => $exception->getMessage(),
                ],
            ]);

            return $topUp->fresh();
        }

        $detailsPayload = is_array($rawDetails['json'] ?? null) ? $rawDetails['json'] : $rawDetails;
        $providerStatus = $this->extractStatus($detailsPayload);

        if ($providerStatus !== null && ! $this->detailsMatchTopUp($topUp, $detailsPayload)) {
            $topUp->forceFill([
                'raw_details' => $rawDetails,
                'provider_status' => 'details_mismatch',
                'status' => PaymentTopUp::STATUS_FAILED,
            ])->save();

            return $topUp->fresh();
        }

        $topUp->forceFill([
            'raw_details' => $rawDetails,
            'provider_status' => $providerStatus,
            'status' => $this->mapProviderStatus($providerStatus, $topUp->status),
        ])->save();

        if ($this->isSuccessfulStatus($providerStatus)) {
            $this->creditWallet($topUp->fresh(), $rawDetails, $providerStatus);
        }

        return $topUp->fresh();
    }

    public function pollPendingTopUps(int $limit = 50): array
    {
        $stats = [
            'checked' => 0,
            'paid' => 0,
            'failed' => 0,
            'canceled' => 0,
            'refunded' => 0,
            'processing' => 0,
        ];

        PaymentTopUp::query()
            ->whereIn('status', [
                PaymentTopUp::STATUS_PENDING,
                PaymentTopUp::STATUS_REDIRECT_CREATED,
                PaymentTopUp::STATUS_PROCESSING,
            ])
            ->whereNotNull('provider_order_id')
            ->where('created_at', '>=', now()->subDays(2))
            ->oldest('id')
            ->limit(max(1, $limit))
            ->get()
            ->each(function (PaymentTopUp $topUp) use (&$stats): void {
                $refreshed = $this->refreshStatus($topUp);
                $stats['checked']++;

                if (isset($stats[$refreshed->status])) {
                    $stats[$refreshed->status]++;
                }
            });

        return $stats;
    }

    public function rememberProviderOrderId(PaymentTopUp $topUp, ?string $orderId): PaymentTopUp
    {
        $orderId = is_string($orderId) ? trim($orderId) : '';

        if ($orderId === '' || $topUp->isTerminal()) {
            return $topUp->fresh();
        }

        if ((string) $topUp->provider_order_id === $orderId) {
            return $topUp->fresh();
        }

        $topUp->forceFill([
            'provider_order_id' => $orderId,
            'status' => PaymentTopUp::STATUS_PROCESSING,
        ])->save();

        return $topUp->fresh();
    }

    public function handleCallback(Request $request): ?PaymentTopUp
    {
        $payload = [
            'query' => $request->query(),
            'body' => $request->all(),
            'headers' => collect($request->headers->all())
                ->only(['content-type', 'user-agent'])
                ->all(),
        ];
        $flat = array_merge($request->query(), $request->all());
        $uuid = $this->firstValue($flat, ['topup_id', 'top_up_id', 'uuid', 'payment_topup_uuid']);
        $orderId = $this->firstValue($flat, ['order_id', 'OrderID', 'orderId', 'payment_id', 'paymentId', 'id']);

        if ($uuid === null && $orderId === null) {
            return null;
        }

        $topUp = PaymentTopUp::query()
            ->when($uuid !== null, fn ($query) => $query->orWhere('uuid', $uuid))
            ->when($orderId !== null, fn ($query) => $query->orWhere('provider_order_id', $orderId))
            ->first();

        if ($topUp === null) {
            return null;
        }

        $callbackStatus = $this->extractStatus($flat);
        $topUp->forceFill([
            'raw_callback' => $payload,
            'provider_status' => $callbackStatus ?? $topUp->provider_status,
            'status' => $this->mapProviderStatus($callbackStatus, $topUp->status),
        ])->save();

        return $this->refreshStatus($topUp->fresh());
    }

    public function topUpData(PaymentTopUp $topUp): array
    {
        return [
            'id' => $topUp->uuid,
            'amount' => round((float) $topUp->amount, 2),
            'currency' => $topUp->currency,
            'status' => $topUp->status,
            'provider_status' => $topUp->provider_status,
            'provider_order_id' => $topUp->provider_order_id,
            'payment_url' => $topUp->provider_payment_url,
            'credited_at' => $topUp->credited_at?->toIso8601String(),
            'created_at' => $topUp->created_at?->toIso8601String(),
            'updated_at' => $topUp->updated_at?->toIso8601String(),
        ];
    }

    protected function creditWallet(PaymentTopUp $topUp, array $details, ?string $providerStatus): void
    {
        DB::transaction(function () use ($topUp, $details, $providerStatus): void {
            $lockedTopUp = PaymentTopUp::query()
                ->whereKey($topUp->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedTopUp->credited_at !== null) {
                return;
            }

            $wallet = Wallet::query()
                ->whereKey($lockedTopUp->wallet_id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->wallets->credit(
                $wallet,
                (float) $lockedTopUp->amount,
                WalletTransaction::TYPE_TOP_UP,
                'Wallet top-up via pay.filmoteca.md',
                [
                    'payment_top_up_id' => $lockedTopUp->uuid,
                    'provider' => 'pay.filmoteca.md',
                    'provider_order_id' => $lockedTopUp->provider_order_id,
                    'provider_status' => $providerStatus,
                    'details' => $details,
                ],
                $lockedTopUp,
            );

            $lockedTopUp->forceFill([
                'status' => PaymentTopUp::STATUS_PAID,
                'provider_status' => $providerStatus,
                'raw_details' => $details,
                'credited_at' => now(),
            ])->save();
        });
    }

    protected function ensureSubscriber(string $subscriberId): void
    {
        $response = $this->providerPost('new-subscriber', [
            'subscriber_id' => $subscriberId,
        ]);

        if ($response->successful() || in_array($response->status(), [409, 422], true)) {
            return;
        }

        throw new RuntimeException('Subscriber could not be registered.');
    }

    protected function resolveSubscriberId(User $user, Wallet $wallet): string
    {
        $meta = $wallet->meta ?? [];
        $existing = is_array($meta) ? ($meta['pay_filmoteca_subscriber_id'] ?? null) : null;

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $subscriberId = 'email|'.$user->email;
        $wallet->forceFill([
            'meta' => array_merge(is_array($meta) ? $meta : [], [
                'pay_filmoteca_subscriber_id' => $subscriberId,
            ]),
        ])->save();

        return $subscriberId;
    }

    protected function providerPost(string $path, array $payload): Response
    {
        $config = $this->config();

        return Http::asForm()
            ->timeout($config['timeout'])
            ->withBasicAuth($config['username'], $config['password'])
            ->withHeaders([
                'Auth-API-Key' => $config['api_key'],
            ])
            ->post(rtrim($config['base_url'], '/').'/'.ltrim($path, '/'), $payload);
    }

    protected function appendQuery(string $url, array $query): string
    {
        if ($url === '') {
            return '';
        }

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query($query);
    }

    protected function responsePayload(Response $response): array
    {
        $json = $response->json();

        return [
            'status' => $response->status(),
            'json' => is_array($json) ? $json : null,
            'body' => $response->body(),
        ];
    }

    protected function providerErrorMessage(array $payload, string $fallback): string
    {
        $message = $this->extractValue($payload, ['message', 'Message', 'error', 'Error']);

        return is_string($message) && trim($message) !== '' ? $message : $fallback;
    }

    protected function extractUrl(array $payload): ?string
    {
        $preferred = $this->extractValue($payload, [
            'payment_url',
            'paymentUrl',
            'bank_url',
            'bankUrl',
            'redirect_url',
            'redirectUrl',
            'checkout_url',
            'checkoutUrl',
            'url',
            'URL',
        ]);

        if (is_string($preferred) && str_starts_with($preferred, 'http')) {
            return $preferred;
        }

        foreach ($this->flattenValues($payload) as $value) {
            if (is_string($value) && preg_match('~^https?://~i', $value) === 1) {
                return $value;
            }
        }

        return null;
    }

    protected function extractStatus(array $payload): ?string
    {
        $status = $this->extractValue($payload, [
            'payment_status',
            'paymentStatus',
            'status',
            'Status',
            'state',
            'State',
            'result',
            'Result',
        ]);

        return is_string($status) || is_numeric($status)
            ? $this->normalizeProviderStatus((string) $status)
            : null;
    }

    protected function extractUuidFromText(string $value): ?string
    {
        preg_match('~[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}~i', $value, $matches);

        return $matches[0] ?? null;
    }

    protected function extractValue(array $payload, array $keys): mixed
    {
        foreach ($payload as $key => $value) {
            if (in_array((string) $key, $keys, true)) {
                return $value;
            }

            if (is_array($value)) {
                $found = $this->extractValue($value, $keys);
                if ($found !== null) {
                    return $found;
                }
            }
        }

        return null;
    }

    protected function firstValue(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;
            if (is_scalar($value) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    protected function flattenValues(array $payload): array
    {
        $values = [];
        array_walk_recursive($payload, function ($value) use (&$values): void {
            $values[] = $value;
        });

        return $values;
    }

    protected function detailsMatchTopUp(PaymentTopUp $topUp, array $details): bool
    {
        $orderId = $this->extractValue($details, ['order_id', 'orderId', 'OrderID', 'payment_id', 'paymentId', 'id']);
        if (is_scalar($orderId) && (string) $orderId !== '' && (string) $orderId !== (string) $topUp->provider_order_id) {
            return false;
        }

        $currency = $this->extractValue($details, ['currency', 'Currency']);
        if (is_scalar($currency) && strtoupper((string) $currency) !== strtoupper((string) $topUp->currency)) {
            return false;
        }

        $subscriberId = $this->extractValue($details, ['subscriber_id', 'subscriberId', 'SubscriberID']);
        if (is_scalar($subscriberId) && (string) $subscriberId !== '' && (string) $subscriberId !== (string) $topUp->subscriber_id) {
            return false;
        }

        $total = $this->extractValue($details, ['total', 'Total']);
        if (is_numeric($total) && abs(((float) $total) - ((float) $topUp->amount)) > 0.01) {
            return false;
        }

        return true;
    }

    protected function mapProviderStatus(?string $status, string $currentStatus): string
    {
        if ($status === null || $status === '') {
            return $currentStatus;
        }

        if ($this->isSuccessfulStatus($status)) {
            return PaymentTopUp::STATUS_PROCESSING;
        }

        if (in_array($status, ['failed', 'fail', 'error', 'declined', 'rejected', 'expired', 'timeout'], true)) {
            return PaymentTopUp::STATUS_FAILED;
        }

        if (in_array($status, ['canceled', 'cancelled', 'cancel'], true)) {
            return PaymentTopUp::STATUS_CANCELED;
        }

        if (in_array($status, ['refunded', 'partiallyrefunded', 'partially-refunded', 'partially_refunded'], true)) {
            return PaymentTopUp::STATUS_REFUNDED;
        }

        return PaymentTopUp::STATUS_PROCESSING;
    }

    protected function isSuccessfulStatus(?string $status): bool
    {
        return in_array($status, [
            'paid',
            'success',
            'successful',
            'succeeded',
            'completed',
            'complete',
            'approved',
            'captured',
            'processed',
            'settled',
            'ok',
            '1',
            'true',
        ], true);
    }

    protected function normalizeProviderStatus(string $status): string
    {
        return strtolower(trim($status));
    }

    protected function normalizeLocale(string $locale): string
    {
        return in_array($locale, ['ro', 'en', 'ru'], true) ? $locale : 'ro';
    }

    protected function ensureConfigured(): void
    {
        $config = $this->config();

        foreach (['base_url', 'username', 'password', 'api_key'] as $key) {
            if (empty($config[$key])) {
                throw ValidationException::withMessages([
                    'payment' => ['Integrarea pay.filmoteca.md nu este configurată complet.'],
                ]);
            }
        }
    }

    protected function config(): array
    {
        return config('services.pay_filmoteca');
    }
}
