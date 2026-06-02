<?php

namespace App\Services;

use App\Models\PaymentTopUp;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\Process\Process;

class PayFilmotecaPaymentService
{
    public function __construct(
        protected WalletService $wallets,
    ) {}

    public function initiateTopUp(User $user, Wallet $wallet, array $payload, Request $request): PaymentTopUp
    {
        Log::channel('payments')->info('PayFilmoteca top-up initiation entered service', [
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'wallet_currency' => $wallet->currency,
            'payload_amount' => $payload['amount'] ?? null,
            'payload_currency' => $payload['currency'] ?? null,
            'payload_locale' => $payload['locale'] ?? null,
            'has_phone' => ! empty($payload['phone'] ?? null),
        ]);

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

        Log::channel('payments')->info('PayFilmoteca top-up record created', [
            'top_up_uuid' => $topUp->uuid,
            'top_up_id' => $topUp->id,
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'subscriber_id' => $subscriberId,
            'amount' => $amount,
            'currency' => $currency,
            'status' => $topUp->status,
        ]);

        $successUrl = $this->appendQuery((string) config('services.pay_filmoteca.success_url'), ['topup_id' => $topUp->uuid]);
        $failedUrl = $this->appendQuery((string) config('services.pay_filmoteca.failed_url'), ['topup_id' => $topUp->uuid]);
        $callbackUrl = $this->appendQuery((string) config('services.pay_filmoteca.callback_url'), ['topup_id' => $topUp->uuid]);
        $phone = $this->normalizePhoneForProvider((string) ($payload['phone'] ?? ''));
        $userAgent = $this->normalizeUserAgentForProvider();
        $providerPayload = [
            'subscriber_id' => $subscriberId,
            'amount' => 1,
            'currency' => $currency,
            'total' => number_format($amount, 2, '.', ''),
            'description' => $description,
            'name' => $user->name ?: $user->email,
            'email' => $user->email,
            'phone' => $phone,
            'client_ip_addr' => $this->resolveClientIp($request),
            'user_agent' => $userAgent,
            'lang' => $this->normalizeLocale((string) ($payload['locale'] ?? $user->preferred_locale ?? 'ro')),
        ];

        $topUp->update([
            'raw_request' => $providerPayload,
        ]);

        Log::channel('payments')->info('PayFilmoteca payment request starting', [
            'top_up_uuid' => $topUp->uuid,
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'subscriber_id' => $subscriberId,
            'amount' => $amount,
            'currency' => $currency,
            'provider_url' => rtrim((string) config('services.pay_filmoteca.base_url'), '/').'/payment-request',
            'documented_payload_only' => true,
            'provider_payload' => $this->sanitizeProviderPayloadForLog($providerPayload),
            'callback_url' => $callbackUrl,
            'success_url' => $successUrl,
            'failed_url' => $failedUrl,
            'client_ip_addr' => $providerPayload['client_ip_addr'],
            'lang' => $providerPayload['lang'],
            'phone_diagnostics' => $this->phoneDiagnostics($phone),
            'original_user_agent_length' => strlen((string) $request->userAgent()),
            'normalized_user_agent' => $userAgent,
        ]);

        try {
            $response = $this->providerPost('payment-request', $providerPayload);
            $rawResponse = $this->responsePayload($response);

            if ($response->failed()) {
                Log::channel('payments')->error('PayFilmoteca payment request rejected by provider', [
                    'top_up_uuid' => $topUp->uuid,
                    'http_status' => $response->status(),
                    'provider_response' => $rawResponse,
                ]);

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

            Log::channel('payments')->info('PayFilmoteca payment response parsed', [
                'top_up_uuid' => $topUp->uuid,
                'http_status' => $response->status(),
                'provider_order_id' => $orderId,
                'has_payment_url' => $paymentUrl !== null,
                'payment_url_host' => is_string($paymentUrl) ? parse_url($paymentUrl, PHP_URL_HOST) : null,
            ]);

            if ($paymentUrl === null) {
                Log::channel('payments')->error('PayFilmoteca payment request missing redirect URL', [
                    'top_up_uuid' => $topUp->uuid,
                    'http_status' => $response->status(),
                    'provider_order_id' => $orderId,
                    'provider_response' => $rawResponse,
                ]);

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

            Log::channel('payments')->info('PayFilmoteca payment redirect created', [
                'top_up_uuid' => $topUp->uuid,
                'provider_order_id' => $orderId,
                'http_status' => $response->status(),
                'payment_url' => $paymentUrl,
                'old_status' => PaymentTopUp::STATUS_PENDING,
                'new_status' => PaymentTopUp::STATUS_PROCESSING,
            ]);

            return $topUp->fresh();
        } catch (ValidationException $exception) {
            Log::channel('payments')->warning('PayFilmoteca payment request stopped by validation exception', [
                'top_up_uuid' => $topUp->uuid,
                'errors' => $exception->errors(),
            ]);

            throw $exception;
        } catch (\Throwable $exception) {
            Log::channel('payments')->error('PayFilmoteca payment request failed with exception', [
                'top_up_uuid' => $topUp->uuid,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
                'exception_file' => $exception->getFile(),
                'exception_line' => $exception->getLine(),
                'exception' => $exception,
            ]);

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
            Log::channel('payments')->info('PayFilmoteca status refresh skipped', [
                'top_up_uuid' => $topUp->uuid,
                'provider_order_id' => $topUp->provider_order_id,
                'status' => $topUp->status,
                'is_terminal' => $topUp->isTerminal(),
                'has_provider_order_id' => ! empty($topUp->provider_order_id),
            ]);

            return $topUp->fresh();
        }

        $this->ensureConfigured();

        Log::channel('payments')->info('PayFilmoteca status refresh starting', [
            'top_up_uuid' => $topUp->uuid,
            'provider_order_id' => $topUp->provider_order_id,
            'current_status' => $topUp->status,
        ]);

        try {
            $response = $this->providerPost('payment-details', [
                'order_id' => $topUp->provider_order_id,
            ]);
            $rawDetails = $this->responsePayload($response);
        } catch (\Throwable $exception) {
            Log::channel('payments')->error('PayFilmoteca status refresh failed with exception', [
                'top_up_uuid' => $topUp->uuid,
                'provider_order_id' => $topUp->provider_order_id,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
                'exception_file' => $exception->getFile(),
                'exception_line' => $exception->getLine(),
                'exception' => $exception,
            ]);

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
            Log::channel('payments')->error('PayFilmoteca status refresh details mismatch', [
                'top_up_uuid' => $topUp->uuid,
                'provider_order_id' => $topUp->provider_order_id,
                'current_status' => $topUp->status,
                'provider_status' => $providerStatus,
                'provider_details' => $rawDetails,
            ]);

            $topUp->forceFill([
                'raw_details' => $rawDetails,
                'provider_status' => 'details_mismatch',
                'status' => PaymentTopUp::STATUS_FAILED,
            ])->save();

            return $topUp->fresh();
        }

        $oldStatus = $topUp->status;
        $newStatus = $this->mapProviderStatus($providerStatus, $topUp->status);

        $topUp->forceFill([
            'raw_details' => $rawDetails,
            'provider_status' => $providerStatus,
            'status' => $newStatus,
        ])->save();

        Log::channel('payments')->info('PayFilmoteca status refresh completed', [
            'top_up_uuid' => $topUp->uuid,
            'provider_order_id' => $topUp->provider_order_id,
            'http_status' => $rawDetails['status'] ?? null,
            'provider_status' => $providerStatus,
            'old_status' => $oldStatus,
            'new_status' => $topUp->status,
            'provider_details' => $rawDetails,
        ]);

        if ($this->isSuccessfulStatus($providerStatus)) {
            Log::channel('payments')->info('PayFilmoteca status is successful; crediting wallet', [
                'top_up_uuid' => $topUp->uuid,
                'provider_order_id' => $topUp->provider_order_id,
                'provider_status' => $providerStatus,
            ]);

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
            Log::channel('payments')->info('PayFilmoteca provider order id remember skipped', [
                'top_up_uuid' => $topUp->uuid,
                'incoming_provider_order_id' => $orderId,
                'current_provider_order_id' => $topUp->provider_order_id,
                'status' => $topUp->status,
                'is_terminal' => $topUp->isTerminal(),
            ]);

            return $topUp->fresh();
        }

        if ((string) $topUp->provider_order_id === $orderId) {
            Log::channel('payments')->info('PayFilmoteca provider order id already known', [
                'top_up_uuid' => $topUp->uuid,
                'provider_order_id' => $orderId,
                'status' => $topUp->status,
            ]);

            return $topUp->fresh();
        }

        $oldProviderOrderId = $topUp->provider_order_id;
        $oldStatus = $topUp->status;

        $topUp->forceFill([
            'provider_order_id' => $orderId,
            'status' => PaymentTopUp::STATUS_PROCESSING,
        ])->save();

        Log::channel('payments')->info('PayFilmoteca provider order id remembered from return URL', [
            'top_up_uuid' => $topUp->uuid,
            'incoming_provider_order_id' => $orderId,
            'old_provider_order_id' => $oldProviderOrderId,
            'old_status' => $oldStatus,
            'new_status' => $topUp->status,
        ]);

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

        Log::channel('payments')->info('PayFilmoteca callback received', [
            'top_up_uuid' => $uuid,
            'provider_order_id' => $orderId,
            'method' => $request->method(),
            'client_ip' => $request->ip(),
            'payload' => $payload,
        ]);

        if ($uuid === null && $orderId === null) {
            Log::channel('payments')->warning('PayFilmoteca callback ignored because no top-up or order id was provided', [
                'payload' => $payload,
            ]);

            return null;
        }

        $topUp = PaymentTopUp::query()
            ->when($uuid !== null, fn ($query) => $query->orWhere('uuid', $uuid))
            ->when($orderId !== null, fn ($query) => $query->orWhere('provider_order_id', $orderId))
            ->first();

        if ($topUp === null) {
            Log::channel('payments')->warning('PayFilmoteca callback did not match a top-up', [
                'top_up_uuid' => $uuid,
                'provider_order_id' => $orderId,
                'payload' => $payload,
            ]);

            return null;
        }

        $callbackStatus = $this->extractStatus($flat);
        $oldStatus = $topUp->status;
        $oldProviderStatus = $topUp->provider_status;
        $topUp->forceFill([
            'raw_callback' => $payload,
            'provider_status' => $callbackStatus ?? $topUp->provider_status,
            'status' => $this->mapProviderStatus($callbackStatus, $topUp->status),
        ])->save();

        Log::channel('payments')->info('PayFilmoteca callback matched top-up', [
            'top_up_uuid' => $topUp->uuid,
            'provider_order_id' => $topUp->provider_order_id,
            'callback_status' => $callbackStatus,
            'old_provider_status' => $oldProviderStatus,
            'new_provider_status' => $topUp->provider_status,
            'old_status' => $oldStatus,
            'new_status' => $topUp->status,
        ]);

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
                Log::channel('payments')->info('PayFilmoteca wallet credit skipped because top-up was already credited', [
                    'top_up_uuid' => $lockedTopUp->uuid,
                    'wallet_id' => $lockedTopUp->wallet_id,
                    'credited_at' => $lockedTopUp->credited_at?->toIso8601String(),
                    'status' => $lockedTopUp->status,
                ]);

                return;
            }

            $wallet = Wallet::query()
                ->whereKey($lockedTopUp->wallet_id)
                ->lockForUpdate()
                ->firstOrFail();

            Log::channel('payments')->info('PayFilmoteca wallet credit starting', [
                'top_up_uuid' => $lockedTopUp->uuid,
                'wallet_id' => $wallet->id,
                'user_id' => $lockedTopUp->user_id,
                'amount' => $lockedTopUp->amount,
                'currency' => $lockedTopUp->currency,
                'provider_order_id' => $lockedTopUp->provider_order_id,
                'provider_status' => $providerStatus,
            ]);

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

            Log::channel('payments')->info('PayFilmoteca wallet credit completed', [
                'top_up_uuid' => $lockedTopUp->uuid,
                'wallet_id' => $wallet->id,
                'user_id' => $lockedTopUp->user_id,
                'amount' => $lockedTopUp->amount,
                'currency' => $lockedTopUp->currency,
                'provider_order_id' => $lockedTopUp->provider_order_id,
                'provider_status' => $providerStatus,
                'new_status' => $lockedTopUp->status,
                'credited_at' => $lockedTopUp->credited_at?->toIso8601String(),
            ]);
        });
    }

    protected function ensureSubscriber(string $subscriberId): void
    {
        Log::channel('payments')->info('PayFilmoteca subscriber registration starting', [
            'subscriber_id' => $subscriberId,
            'provider_url' => rtrim((string) config('services.pay_filmoteca.base_url'), '/').'/new-subscriber',
        ]);

        try {
            $response = $this->providerPost('new-subscriber', [
                'subscriber_id' => $subscriberId,
            ]);
        } catch (\Throwable $exception) {
            Log::channel('payments')->error('PayFilmoteca subscriber registration failed with exception', [
                'subscriber_id' => $subscriberId,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
                'exception_file' => $exception->getFile(),
                'exception_line' => $exception->getLine(),
                'exception' => $exception,
            ]);

            throw $exception;
        }

        Log::channel('payments')->info('PayFilmoteca subscriber registration response received', [
            'subscriber_id' => $subscriberId,
            'http_status' => $response->status(),
            'provider_response' => $this->responsePayload($response),
        ]);

        if ($response->successful() || in_array($response->status(), [409, 422], true)) {
            return;
        }

        Log::channel('payments')->error('PayFilmoteca subscriber registration rejected by provider', [
            'subscriber_id' => $subscriberId,
            'http_status' => $response->status(),
            'provider_response' => $this->responsePayload($response),
        ]);

        throw new RuntimeException('Subscriber could not be registered.');
    }

    protected function resolveSubscriberId(User $user, Wallet $wallet): string
    {
        $meta = $wallet->meta ?? [];
        $existing = is_array($meta) ? ($meta['pay_filmoteca_subscriber_id'] ?? null) : null;

        if (is_string($existing) && $existing !== '') {
            Log::channel('payments')->info('PayFilmoteca existing subscriber id resolved', [
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
                'subscriber_id' => $existing,
            ]);

            return $existing;
        }

        $subscriberId = 'email|'.$user->email;
        $wallet->forceFill([
            'meta' => array_merge(is_array($meta) ? $meta : [], [
                'pay_filmoteca_subscriber_id' => $subscriberId,
            ]),
        ])->save();

        Log::channel('payments')->info('PayFilmoteca subscriber id created for wallet', [
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'subscriber_id' => $subscriberId,
        ]);

        return $subscriberId;
    }

    protected function providerPost(string $path, array $payload): Response
    {
        $config = $this->config();
        $url = rtrim($config['base_url'], '/').'/'.ltrim($path, '/');

        if ($path === 'payment-request') {
            return $this->providerPostWithCurlFallback($path, $url, $payload, $config, 'payment_request_curl_primary');
        }

        $formBody = http_build_query($payload, '', '&', PHP_QUERY_RFC3986);

        Log::channel('payments')->info('PayFilmoteca provider POST starting', [
            'provider_path' => $path,
            'provider_url' => $url,
            'timeout' => $config['timeout'],
            'transport' => 'raw_form_body_curl_like',
            'http_user_agent' => 'curl/8.7.1',
            'body_bytes' => strlen($formBody),
            'has_username' => ! empty($config['username']),
            'has_password' => ! empty($config['password']),
            'has_api_key' => ! empty($config['api_key']),
            'payload' => $this->sanitizeProviderPayloadForLog($payload),
        ]);

        try {
            $response = Http::withBody($formBody, 'application/x-www-form-urlencoded')
                ->timeout($config['timeout'])
                ->connectTimeout(min(10, (int) $config['timeout']))
                ->withBasicAuth($config['username'], $config['password'])
                ->withUserAgent('curl/8.7.1')
                ->withHeaders([
                    'Auth-API-Key' => $config['api_key'],
                    'Accept' => 'application/json, */*',
                    'Expect' => '',
                    'Content-Length' => (string) strlen($formBody),
                ])
                ->withOptions([
                    'expect' => false,
                    'version' => 1.1,
                    'http_errors' => false,
                    'curl' => [
                        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                        CURLOPT_TCP_KEEPALIVE => 0,
                        CURLOPT_FORBID_REUSE => 1,
                        CURLOPT_FRESH_CONNECT => 1,
                    ],
                ])
                ->post($url);
        } catch (\Throwable $exception) {
            Log::channel('payments')->error('PayFilmoteca provider POST failed before response', [
                'provider_path' => $path,
                'provider_url' => $url,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);

            if ($path === 'payment-request') {
                return $this->providerPostWithCurlFallback($path, $url, $payload, $config, $exception->getMessage());
            }

            throw $exception;
        }

        Log::channel('payments')->info('PayFilmoteca provider POST completed', [
            'provider_path' => $path,
            'provider_url' => $url,
            'http_status' => $response->status(),
            'successful' => $response->successful(),
            'failed' => $response->failed(),
            'response' => $this->responsePayload($response),
        ]);

        if ($path === 'payment-request' && $response->status() === 504) {
            return $this->providerPostWithCurlFallback($path, $url, $payload, $config, 'laravel_http_504');
        }

        return $response;
    }

    protected function providerPostWithCurlFallback(string $path, string $url, array $payload, array $config, string $reason): Response
    {
        $timeout = (int) $config['timeout'];

        $command = [
            'curl',
            '-sS',
            '--connect-timeout',
            '10',
            '--max-time',
            (string) $timeout,
            '--user-agent',
            'curl/8.7.1',
            '-u',
            $config['username'].':'.$config['password'],
            '-H',
            'Auth-API-Key: '.$config['api_key'],
            '-H',
            'Content-Type: application/x-www-form-urlencoded',
            '-H',
            'Accept: */*',
            '-H',
            'Expect:',
            '-w',
            "\n__PAYFILMOTECA_HTTP_CODE__:%{http_code}",
        ];

        foreach ($payload as $key => $value) {
            $command[] = '--data-urlencode';
            $command[] = $key.'='.(string) $value;
        }

        $command[] = $url;

        Log::channel('payments')->info('PayFilmoteca provider POST via curl', [
            'provider_path' => $path,
            'provider_url' => $url,
            'reason' => $reason,
            'timeout' => $timeout,
            'payload' => $this->sanitizeProviderPayloadForLog($payload),
        ]);

        $process = new Process($command);
        $process->setTimeout($timeout + 15);

        try {
            $process->run();
        } catch (\Throwable $exception) {
            Log::channel('payments')->error('PayFilmoteca provider POST curl process crashed', [
                'provider_path' => $path,
                'provider_url' => $url,
                'reason' => $reason,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);

            return new Response(new PsrResponse(599, [], 'curl process crashed: '.$exception->getMessage()));
        }

        $response = $this->responseFromCurlFallbackOutput($process->getOutput());
        $exitCode = $process->getExitCode();
        $stderr = trim($process->getErrorOutput());

        Log::channel('payments')->info('PayFilmoteca provider POST via curl completed', [
            'provider_path' => $path,
            'provider_url' => $url,
            'reason' => $reason,
            'exit_code' => $exitCode,
            'successful_process' => $process->isSuccessful(),
            'stderr' => $stderr,
            'http_status' => $response->status(),
            'successful' => $response->successful(),
            'failed' => $response->failed(),
            'response' => $this->responsePayload($response),
        ]);

        if (! $process->isSuccessful() && $response->status() === 599) {
            return new Response(new PsrResponse(599, [], 'curl exit '.$exitCode.': '.$stderr));
        }

        return $response;
    }

    protected function responseFromCurlFallbackOutput(string $output): Response
    {
        $marker = "\n__PAYFILMOTECA_HTTP_CODE__:";
        $status = 599;
        $body = $output;

        if (str_contains($output, $marker)) {
            [$body, $statusText] = explode($marker, $output, 2);
            $status = (int) trim($statusText);
        }

        if ($status < 100) {
            $status = 599;
        }

        return new Response(new PsrResponse($status, [], $body));
    }

    protected function resolveClientIp(Request $request): string
    {
        foreach (['CF-Connecting-IP', 'True-Client-IP', 'X-Real-IP'] as $header) {
            $value = trim((string) $request->headers->get($header, ''));
            if ($this->isPublicIp($value)) {
                return $value;
            }
        }

        $forwardedFor = (string) $request->headers->get('X-Forwarded-For', '');
        foreach (explode(',', $forwardedFor) as $value) {
            $ip = trim($value);
            if ($this->isPublicIp($ip)) {
                return $ip;
            }
        }

        return (string) ($request->ip() ?: '');
    }

    protected function isPublicIp(string $value): bool
    {
        return filter_var(
            $value,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
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
        $missingKeys = [];

        foreach (['base_url', 'username', 'password', 'api_key'] as $key) {
            if (empty($config[$key])) {
                $missingKeys[] = $key;
            }
        }

        if ($missingKeys !== []) {
            Log::channel('payments')->error('PayFilmoteca payment integration is missing configuration', [
                'missing_keys' => $missingKeys,
                'base_url' => $config['base_url'] ?? null,
                'timeout' => $config['timeout'] ?? null,
                'has_username' => ! empty($config['username'] ?? null),
                'has_password' => ! empty($config['password'] ?? null),
                'has_api_key' => ! empty($config['api_key'] ?? null),
                'has_callback_url' => ! empty($config['callback_url'] ?? null),
                'has_success_url' => ! empty($config['success_url'] ?? null),
                'has_failed_url' => ! empty($config['failed_url'] ?? null),
            ]);

            throw ValidationException::withMessages([
                'payment' => ['Integrarea pay.filmoteca.md nu este configurată complet.'],
            ]);
        }

        Log::channel('payments')->info('PayFilmoteca payment integration configuration checked', [
            'base_url' => $config['base_url'] ?? null,
            'timeout' => $config['timeout'] ?? null,
            'has_username' => ! empty($config['username'] ?? null),
            'has_password' => ! empty($config['password'] ?? null),
            'has_api_key' => ! empty($config['api_key'] ?? null),
            'has_callback_url' => ! empty($config['callback_url'] ?? null),
            'has_success_url' => ! empty($config['success_url'] ?? null),
            'has_failed_url' => ! empty($config['failed_url'] ?? null),
            'callback_url' => $config['callback_url'] ?? null,
            'success_url' => $config['success_url'] ?? null,
            'failed_url' => $config['failed_url'] ?? null,
        ]);
    }

    protected function config(): array
    {
        return config('services.pay_filmoteca');
    }

    protected function sanitizeProviderPayloadForLog(array $payload): array
    {
        $sanitized = $payload;

        if (isset($sanitized['email'])) {
            $sanitized['email'] = $this->maskEmail((string) $sanitized['email']);
        }

        if (isset($sanitized['phone'])) {
            $sanitized['phone'] = $this->maskPhone((string) $sanitized['phone']);
            $sanitized['phone_diagnostics'] = $this->phoneDiagnostics((string) $payload['phone']);
        }

        if (isset($sanitized['user_agent'])) {
            $sanitized['user_agent'] = substr((string) $sanitized['user_agent'], 0, 160);
        }

        return $sanitized;
    }

    protected function normalizePhoneForProvider(string $phone): string
    {
        $phone = trim($phone);

        if ($phone === '') {
            return '';
        }

        $phone = preg_replace('/[\s().-]+/', '', $phone) ?? $phone;

        if (str_starts_with($phone, '00')) {
            $phone = '+'.substr($phone, 2);
        }

        if (str_starts_with($phone, '+')) {
            return '+'.preg_replace('/\D+/', '', substr($phone, 1));
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '373')) {
            return '+'.$digits;
        }

        if (str_starts_with($digits, '0') && strlen($digits) === 9) {
            return '+373'.substr($digits, 1);
        }

        if (strlen($digits) === 8 && preg_match('/^[67]\d{7}$/', $digits) === 1) {
            return '+373'.$digits;
        }

        return $digits !== '' ? '+'.$digits : '';
    }

    protected function phoneDiagnostics(string $phone): array
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return [
            'provided' => $phone !== '',
            'is_e164_like' => preg_match('/^\+\d{8,15}$/', $phone) === 1,
            'length' => strlen($phone),
            'digits_length' => strlen($digits),
            'last4' => $digits !== '' ? substr($digits, -4) : null,
        ];
    }

    protected function maskPhone(string $phone): string
    {
        if ($phone === '') {
            return '';
        }

        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return $digits !== ''
            ? '[provided:last4='.substr($digits, -4).']'
            : '[provided]';
    }

    protected function normalizeUserAgentForProvider(): string
    {
        return 'Mozilla/5.0';
    }

    protected function maskEmail(string $email): string
    {
        if (! str_contains($email, '@')) {
            return '[provided]';
        }

        [$local, $domain] = explode('@', $email, 2);
        $visible = substr($local, 0, 2);

        return $visible.'***@'.$domain;
    }
}
