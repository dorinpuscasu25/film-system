<?php

namespace App\Services;

use App\Models\PaymentTopUp;
use App\Models\PaymentRefund;
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
            $identifiers = $this->extractProviderIdentifiers($rawResponse);
            $orderId = $identifiers['order_id'];
            $checkoutId = $identifiers['checkout_id'];

            Log::channel('payments')->info('PayFilmoteca payment response parsed', [
                'top_up_uuid' => $topUp->uuid,
                'http_status' => $response->status(),
                'provider_order_id' => $orderId,
                'provider_checkout_id' => $checkoutId,
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
                    'provider_checkout_id' => $checkoutId,
                    'provider_status' => 'missing_redirect_url',
                ]);

                throw ValidationException::withMessages([
                    'payment' => ['Providerul nu a returnat URL-ul de plată.'],
                ]);
            }

            $checkoutId ??= $this->extractUuidFromText($paymentUrl);

            $topUp->update([
                'status' => PaymentTopUp::STATUS_PROCESSING,
                'provider_order_id' => $orderId,
                'provider_checkout_id' => $checkoutId,
                'provider_rrn' => $identifiers['rrn'],
                'provider_payment_url' => $paymentUrl,
                'raw_response' => $rawResponse,
            ]);

            Log::channel('payments')->info('PayFilmoteca payment redirect created', [
                'top_up_uuid' => $topUp->uuid,
                'provider_order_id' => $orderId,
                'provider_checkout_id' => $checkoutId,
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

    public function refreshStatus(PaymentTopUp $topUp, bool $force = false): PaymentTopUp
    {
        $detailsOrderId = $topUp->provider_order_id ?: $topUp->provider_checkout_id;

        if ((! $force && $topUp->isTerminal()) || empty($detailsOrderId)) {
            Log::channel('payments')->info('PayFilmoteca status refresh skipped', [
                'top_up_uuid' => $topUp->uuid,
                'provider_order_id' => $topUp->provider_order_id,
                'provider_checkout_id' => $topUp->provider_checkout_id,
                'status' => $topUp->status,
                'is_terminal' => $topUp->isTerminal(),
                'force' => $force,
                'has_provider_order_id' => ! empty($detailsOrderId),
            ]);

            return $topUp->fresh();
        }

        $this->ensureConfigured();

        Log::channel('payments')->info('PayFilmoteca status refresh starting', [
            'top_up_uuid' => $topUp->uuid,
            'provider_order_id' => $topUp->provider_order_id,
            'provider_checkout_id' => $topUp->provider_checkout_id,
            'current_status' => $topUp->status,
            'force' => $force,
        ]);

        $rawDetails = null;
        $detailsRequestSucceeded = false;
        $detailsLookupId = null;
        $lastException = null;
        $detailsLookupIds = array_values(array_unique(array_filter([
            $topUp->provider_order_id,
            $topUp->provider_checkout_id,
        ], fn ($value): bool => is_string($value) && trim($value) !== '')));

        foreach ($detailsLookupIds as $lookupId) {
            try {
                $response = $this->providerPost('payment-details', [
                    'order_id' => $lookupId,
                ]);
                $candidateDetails = $this->responsePayload($response);
                $candidatePayload = is_array($candidateDetails['json'] ?? null) ? $candidateDetails['json'] : $candidateDetails;
                $candidateStatus = $this->extractStatus($candidatePayload);

                if ($rawDetails === null || ($response->successful() && ! $detailsRequestSucceeded)) {
                    $rawDetails = $candidateDetails;
                    $detailsRequestSucceeded = $response->successful();
                    $detailsLookupId = $lookupId;
                }

                if ($response->successful() && $candidateStatus !== null) {
                    $rawDetails = $candidateDetails;
                    $detailsRequestSucceeded = true;
                    $detailsLookupId = $lookupId;
                    break;
                }
            } catch (\Throwable $exception) {
                $lastException = $exception;
                Log::channel('payments')->warning('PayFilmoteca status refresh lookup failed', [
                    'top_up_uuid' => $topUp->uuid,
                    'lookup_order_id' => $lookupId,
                    'provider_order_id' => $topUp->provider_order_id,
                    'provider_checkout_id' => $topUp->provider_checkout_id,
                    'exception_class' => $exception::class,
                    'exception_message' => $exception->getMessage(),
                ]);
            }
        }

        if ($rawDetails === null) {
            Log::channel('payments')->error('PayFilmoteca status refresh failed with exception', [
                'top_up_uuid' => $topUp->uuid,
                'provider_order_id' => $topUp->provider_order_id,
                'provider_checkout_id' => $topUp->provider_checkout_id,
                'exception_class' => $lastException ? $lastException::class : null,
                'exception_message' => $lastException?->getMessage(),
                'exception_file' => $lastException?->getFile(),
                'exception_line' => $lastException?->getLine(),
                'exception' => $lastException,
            ]);

            $topUp->update([
                'raw_details' => [
                    'message' => $lastException?->getMessage() ?? 'Payment details lookup failed.',
                ],
            ]);

            return $topUp->fresh();
        }

        $detailsPayload = is_array($rawDetails['json'] ?? null) ? $rawDetails['json'] : $rawDetails;
        $providerStatus = $this->extractStatus($detailsPayload);
        $providerStatus ??= $detailsRequestSucceeded && is_string($topUp->provider_status) && $topUp->provider_status !== ''
            ? $topUp->provider_status
            : null;
        $identifiers = $this->extractProviderIdentifiers($detailsPayload);

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
        if ($this->isSuccessfulStatus($providerStatus) && $topUp->credited_at !== null) {
            $newStatus = PaymentTopUp::STATUS_PAID;
        }
        $resolvedOrderId = $topUp->provider_order_id;
        $resolvedCheckoutId = $topUp->provider_checkout_id;

        if ($identifiers['order_id'] !== null) {
            if ($resolvedOrderId === null || $resolvedOrderId === '') {
                $resolvedOrderId = $identifiers['order_id'];
            } elseif ($identifiers['order_id'] !== $resolvedOrderId && ($resolvedCheckoutId === null || $resolvedCheckoutId === '')) {
                $resolvedCheckoutId = $identifiers['order_id'];
            }
        }

        if ($identifiers['checkout_id'] !== null) {
            $resolvedCheckoutId = $identifiers['checkout_id'];
        }

        $topUp->forceFill([
            'raw_details' => $rawDetails,
            'provider_status' => $providerStatus,
            'provider_order_id' => $resolvedOrderId,
            'provider_checkout_id' => $resolvedCheckoutId,
            'provider_rrn' => $identifiers['rrn'] ?? $topUp->provider_rrn,
            'status' => $newStatus,
        ])->save();

        Log::channel('payments')->info('PayFilmoteca status refresh completed', [
            'top_up_uuid' => $topUp->uuid,
            'provider_order_id' => $topUp->provider_order_id,
            'provider_checkout_id' => $topUp->provider_checkout_id,
            'provider_rrn' => $topUp->provider_rrn,
            'details_lookup_id' => $detailsLookupId,
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
            ->where(function ($query): void {
                $query->whereNotNull('provider_checkout_id')
                    ->orWhereNotNull('provider_order_id');
            })
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
        return $this->rememberProviderIdentifiers($topUp, null, $orderId, null);
    }

    public function rememberProviderIdentifiers(PaymentTopUp $topUp, ?string $checkoutId, ?string $orderId, ?string $rrn = null, ?string $providerStatus = null): PaymentTopUp
    {
        $orderId = is_string($orderId) ? trim($orderId) : '';
        $checkoutId = is_string($checkoutId) ? trim($checkoutId) : '';
        $rrn = is_string($rrn) ? trim($rrn) : '';
        $providerStatus = is_string($providerStatus) && trim($providerStatus) !== ''
            ? $this->normalizeProviderStatus($providerStatus)
            : null;

        if ($orderId === '' && $checkoutId === '' && $rrn === '' && $providerStatus === null) {
            Log::channel('payments')->info('PayFilmoteca provider order id remember skipped', [
                'top_up_uuid' => $topUp->uuid,
                'incoming_provider_order_id' => $orderId,
                'incoming_provider_checkout_id' => $checkoutId,
                'incoming_provider_rrn' => $rrn,
                'incoming_provider_status' => $providerStatus,
                'current_provider_order_id' => $topUp->provider_order_id,
                'current_provider_checkout_id' => $topUp->provider_checkout_id,
                'current_provider_rrn' => $topUp->provider_rrn,
                'status' => $topUp->status,
            ]);

            return $topUp->fresh();
        }

        if (
            ($orderId === '' || (string) $topUp->provider_order_id === $orderId)
            && ($checkoutId === '' || (string) $topUp->provider_checkout_id === $checkoutId)
            && ($rrn === '' || (string) $topUp->provider_rrn === $rrn)
            && ($providerStatus === null || (string) $topUp->provider_status === $providerStatus)
        ) {
            Log::channel('payments')->info('PayFilmoteca provider order id already known', [
                'top_up_uuid' => $topUp->uuid,
                'provider_order_id' => $orderId,
                'provider_checkout_id' => $checkoutId,
                'provider_rrn' => $rrn,
                'provider_status' => $providerStatus,
                'status' => $topUp->status,
            ]);

            return $topUp->fresh();
        }

        $oldProviderOrderId = $topUp->provider_order_id;
        $oldProviderCheckoutId = $topUp->provider_checkout_id;
        $oldProviderRrn = $topUp->provider_rrn;
        $oldProviderStatus = $topUp->provider_status;
        $oldStatus = $topUp->status;

        $updates = [];

        if ($orderId !== '') {
            $updates['provider_order_id'] = $orderId;
        }

        if ($checkoutId !== '') {
            $updates['provider_checkout_id'] = $checkoutId;
        }

        if ($rrn !== '') {
            $updates['provider_rrn'] = $rrn;
        }

        if ($providerStatus !== null) {
            $updates['provider_status'] = $providerStatus;
        }

        if (! $topUp->isTerminal()) {
            $updates['status'] = PaymentTopUp::STATUS_PROCESSING;
        }

        $topUp->forceFill($updates)->save();

        Log::channel('payments')->info('PayFilmoteca provider order id remembered from return URL', [
            'top_up_uuid' => $topUp->uuid,
            'incoming_provider_order_id' => $orderId,
            'incoming_provider_checkout_id' => $checkoutId,
            'incoming_provider_rrn' => $rrn,
            'incoming_provider_status' => $providerStatus,
            'old_provider_order_id' => $oldProviderOrderId,
            'old_provider_checkout_id' => $oldProviderCheckoutId,
            'old_provider_rrn' => $oldProviderRrn,
            'old_provider_status' => $oldProviderStatus,
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
        $checkoutId = $this->firstValue($flat, ['checkout_id', 'checkoutId', 'CheckoutID', 'checkoutID']);
        $rrn = $this->firstValue($flat, ['rrn', 'RRN']);

        Log::channel('payments')->info('PayFilmoteca callback received', [
            'top_up_uuid' => $uuid,
            'provider_order_id' => $orderId,
            'provider_checkout_id' => $checkoutId,
            'provider_rrn' => $rrn,
            'method' => $request->method(),
            'client_ip' => $request->ip(),
            'payload' => $payload,
        ]);

        if ($uuid === null && $orderId === null && $checkoutId === null) {
            Log::channel('payments')->warning('PayFilmoteca callback ignored because no top-up or order id was provided', [
                'payload' => $payload,
            ]);

            return null;
        }

        $topUp = PaymentTopUp::query()
            ->when($uuid !== null, fn ($query) => $query->orWhere('uuid', $uuid))
            ->when($orderId !== null, fn ($query) => $query->orWhere('provider_order_id', $orderId))
            ->when($checkoutId !== null, fn ($query) => $query->orWhere('provider_checkout_id', $checkoutId))
            ->first();

        if ($topUp === null) {
            Log::channel('payments')->warning('PayFilmoteca callback did not match a top-up', [
                'top_up_uuid' => $uuid,
                'provider_order_id' => $orderId,
                'provider_checkout_id' => $checkoutId,
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
            'provider_order_id' => $orderId ?? $topUp->provider_order_id,
            'provider_checkout_id' => $checkoutId ?? $topUp->provider_checkout_id,
            'provider_rrn' => $rrn ?? $topUp->provider_rrn,
            'status' => $this->mapProviderStatus($callbackStatus, $topUp->status),
        ])->save();

        Log::channel('payments')->info('PayFilmoteca callback matched top-up', [
            'top_up_uuid' => $topUp->uuid,
            'provider_order_id' => $topUp->provider_order_id,
            'provider_checkout_id' => $topUp->provider_checkout_id,
            'provider_rrn' => $topUp->provider_rrn,
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
            'provider_checkout_id' => $topUp->provider_checkout_id,
            'provider_rrn' => $topUp->provider_rrn,
            'payment_url' => $topUp->provider_payment_url,
            'credited_at' => $topUp->credited_at?->toIso8601String(),
            'created_at' => $topUp->created_at?->toIso8601String(),
            'updated_at' => $topUp->updated_at?->toIso8601String(),
        ];
    }

    public function refundTopUp(PaymentTopUp $topUp, float $amount, string $reason, ?User $admin = null): PaymentRefund
    {
        $this->ensureConfigured();

        $amount = round(abs($amount), 2);
        $reason = trim($reason);
        if (empty($topUp->provider_rrn) && (! empty($topUp->provider_checkout_id) || ! empty($topUp->provider_order_id))) {
            $topUp = $this->refreshStatus($topUp, true);
        }

        $topUp->loadMissing('wallet');
        $checkoutId = trim((string) $topUp->provider_checkout_id);
        $orderId = trim((string) $topUp->provider_order_id);
        $rrn = trim((string) $topUp->provider_rrn);

        if ($topUp->status !== PaymentTopUp::STATUS_PAID) {
            throw ValidationException::withMessages([
                'top_up' => ['Doar plățile achitate pot fi rambursate.'],
            ]);
        }

        if ($checkoutId === '') {
            throw ValidationException::withMessages([
                'checkout_id' => ['Lipsește checkoutId-ul Pay.Filmoteca pentru această tranzacție.'],
            ]);
        }

        if ($rrn === '') {
            throw ValidationException::withMessages([
                'rrn' => ['Lipsește RRN-ul tranzacției bancare. Reîmprospătează detaliile plății înainte de refund.'],
            ]);
        }

        if ($amount < 20) {
            throw ValidationException::withMessages([
                'amount' => ['Suma minimă de refund acceptată de Pay.Filmoteca este 20.00.'],
            ]);
        }

        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => ['Motivul refundului este obligatoriu.'],
            ]);
        }

        if (mb_strlen($reason) > 500) {
            throw ValidationException::withMessages([
                'reason' => ['Motivul refundului poate avea cel mult 500 de caractere.'],
            ]);
        }

        $maxRefundable = $this->refundableAmount($topUp);
        if ($amount > $maxRefundable) {
            throw ValidationException::withMessages([
                'amount' => [sprintf('Suma maximă disponibilă pentru refund este %.2f %s.', $maxRefundable, $topUp->currency)],
            ]);
        }

        $providerPayload = [
            'order_id' => $orderId !== '' ? $orderId : $checkoutId,
            'refund_total' => number_format($amount, 2, '.', ''),
            'currency' => $topUp->currency ?: Wallet::DEFAULT_CURRENCY,
            'refund_reason' => $reason,
            'rrn' => $rrn,
        ];

        $refund = PaymentRefund::query()->create([
            'uuid' => (string) Str::uuid(),
            'payment_top_up_id' => $topUp->id,
            'user_id' => $topUp->user_id,
            'wallet_id' => $topUp->wallet_id,
            'requested_by_admin_id' => $admin?->id,
            'provider_order_id' => $topUp->provider_order_id,
            'provider_checkout_id' => $checkoutId,
            'provider_rrn' => $rrn,
            'amount' => $amount,
            'currency' => $topUp->currency ?: Wallet::DEFAULT_CURRENCY,
            'reason' => $reason,
            'status' => PaymentRefund::STATUS_REQUESTED,
            'raw_request' => $providerPayload,
        ]);

        Log::channel('payments')->info('PayFilmoteca refund request starting', [
            'payment_refund_uuid' => $refund->uuid,
            'top_up_uuid' => $topUp->uuid,
            'provider_order_id' => $topUp->provider_order_id,
            'provider_checkout_id' => $checkoutId,
            'provider_rrn' => $rrn,
            'amount' => $amount,
            'currency' => $topUp->currency,
            'admin_id' => $admin?->id,
        ]);

        try {
            $refundOrderIds = array_values(array_unique(array_filter([
                $orderId,
                $checkoutId,
            ], fn (string $value): bool => trim($value) !== '')));
            $rawResponse = null;
            $successfulPayload = null;
            $attempts = [];

            foreach ($refundOrderIds as $refundOrderId) {
                $attemptPayload = [
                    ...$providerPayload,
                    'order_id' => $refundOrderId,
                ];
                $response = $this->providerPost('refund-request', $attemptPayload);
                $attemptResponse = $this->responsePayload($response);
                $attempts[] = [
                    'order_id' => $refundOrderId,
                    'http_status' => $response->status(),
                    'response' => $attemptResponse,
                ];

                Log::channel('payments')->info('PayFilmoteca refund request attempt completed', [
                    'payment_refund_uuid' => $refund->uuid,
                    'top_up_uuid' => $topUp->uuid,
                    'refund_order_id' => $refundOrderId,
                    'http_status' => $response->status(),
                    'successful' => $response->successful(),
                    'accepted' => $this->providerResponseAccepted($response, $attemptResponse),
                    'provider_response' => $attemptResponse,
                ]);

                $rawResponse = $attemptResponse;

                if ($this->providerResponseAccepted($response, $attemptResponse)) {
                    $successfulPayload = $attemptPayload;
                    break;
                }
            }

            if ($successfulPayload === null || $rawResponse === null) {
                $refund->forceFill([
                    'status' => PaymentRefund::STATUS_FAILED,
                    'provider_status' => 'request_failed',
                    'raw_request' => [
                        'attempted_order_ids' => $refundOrderIds,
                        'payload' => $providerPayload,
                    ],
                    'raw_response' => [
                        'attempts' => $attempts,
                        'last_response' => $rawResponse,
                    ],
                    'processed_at' => now(),
                ])->save();

                Log::channel('payments')->error('PayFilmoteca refund request rejected by provider', [
                    'payment_refund_uuid' => $refund->uuid,
                    'top_up_uuid' => $topUp->uuid,
                    'attempted_order_ids' => $refundOrderIds,
                    'provider_response' => $rawResponse,
                ]);

                throw ValidationException::withMessages([
                    'refund' => [$this->providerErrorMessage($rawResponse, 'Providerul de plată a refuzat refundul.')],
                ]);
            }

            $refund->forceFill([
                'raw_request' => $successfulPayload,
                'raw_response' => [
                    'attempts' => $attempts,
                    'successful_order_id' => $successfulPayload['order_id'],
                    'last_response' => $rawResponse,
                ],
            ])->save();

            return DB::transaction(function () use ($refund, $rawResponse): PaymentRefund {
                $lockedRefund = PaymentRefund::query()
                    ->whereKey($refund->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $lockedTopUp = PaymentTopUp::query()
                    ->whereKey($lockedRefund->payment_top_up_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $wallet = Wallet::query()
                    ->whereKey($lockedRefund->wallet_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $maxRefundable = $this->refundableAmount($lockedTopUp);
                if ((float) $lockedRefund->amount > $maxRefundable) {
                    $lockedRefund->forceFill([
                        'status' => PaymentRefund::STATUS_FAILED,
                        'provider_status' => 'local_refund_limit_exceeded',
                        'raw_response' => $rawResponse,
                        'processed_at' => now(),
                    ])->save();

                    throw ValidationException::withMessages([
                        'amount' => [sprintf('Suma maximă disponibilă pentru refund este %.2f %s.', $maxRefundable, $lockedRefund->currency)],
                    ]);
                }

                $transaction = $this->wallets->debitOwnCredit(
                    $wallet,
                    (float) $lockedRefund->amount,
                    WalletTransaction::TYPE_REFUND,
                    'Pay.Filmoteca card refund',
                    [
                        'payment_refund_id' => $lockedRefund->uuid,
                        'payment_top_up_id' => $lockedTopUp->uuid,
                        'provider' => 'pay.filmoteca.md',
                        'provider_order_id' => $lockedRefund->provider_order_id,
                        'provider_checkout_id' => $lockedRefund->provider_checkout_id,
                        'provider_rrn' => $lockedRefund->provider_rrn,
                        'refund_reason' => $lockedRefund->reason,
                    ],
                    $lockedRefund,
                );

                $lockedRefund->forceFill([
                    'wallet_transaction_id' => $transaction->id,
                    'status' => PaymentRefund::STATUS_SUCCEEDED,
                    'provider_status' => $this->extractStatus(is_array($rawResponse['json'] ?? null) ? $rawResponse['json'] : $rawResponse),
                    'raw_response' => $rawResponse,
                    'processed_at' => now(),
                ])->save();

                $refundedAmount = $this->refundedAmount($lockedTopUp->fresh());
                if ($refundedAmount >= round((float) $lockedTopUp->amount, 2)) {
                    $lockedTopUp->forceFill([
                        'status' => PaymentTopUp::STATUS_REFUNDED,
                    ])->save();
                }

                Log::channel('payments')->info('PayFilmoteca refund request completed', [
                    'payment_refund_uuid' => $lockedRefund->uuid,
                    'top_up_uuid' => $lockedTopUp->uuid,
                    'wallet_transaction_id' => $transaction->id,
                    'amount' => $lockedRefund->amount,
                    'currency' => $lockedRefund->currency,
                    'provider_checkout_id' => $lockedRefund->provider_checkout_id,
                ]);

                return $lockedRefund->fresh();
            });
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            $refund->forceFill([
                'status' => PaymentRefund::STATUS_FAILED,
                'provider_status' => 'request_error',
                'raw_response' => [
                    'message' => $exception->getMessage(),
                ],
                'processed_at' => now(),
            ])->save();

            Log::channel('payments')->error('PayFilmoteca refund request failed with exception', [
                'payment_refund_uuid' => $refund->uuid,
                'top_up_uuid' => $topUp->uuid,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
                'exception' => $exception,
            ]);

            throw ValidationException::withMessages([
                'refund' => ['Nu am putut iniția refundul. Încearcă din nou.'],
            ]);
        }
    }

    public function refundedAmount(PaymentTopUp $topUp): float
    {
        return round((float) $topUp->refunds()
            ->where('status', PaymentRefund::STATUS_SUCCEEDED)
            ->sum('amount'), 2);
    }

    public function refundableAmount(PaymentTopUp $topUp): float
    {
        $topUp->loadMissing('wallet');
        $alreadyRefunded = $this->refundedAmount($topUp);
        $remainingTopUpAmount = max(0, round((float) $topUp->amount - $alreadyRefunded, 2));
        $ownBalance = $this->ownCreditBalance($topUp->wallet);

        return round(min($remainingTopUpAmount, $ownBalance), 2);
    }

    public function ownCreditBalance(?Wallet $wallet): float
    {
        if ($wallet === null) {
            return 0.0;
        }

        $meta = $wallet->meta ?? [];

        return round((float) ($meta['own_credit_balance'] ?? $wallet->balance_amount ?? 0), 2);
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
                'provider_checkout_id' => $lockedTopUp->provider_checkout_id,
                'provider_rrn' => $lockedTopUp->provider_rrn,
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
                'provider_checkout_id' => $lockedTopUp->provider_checkout_id,
                'provider_rrn' => $lockedTopUp->provider_rrn,
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
        $attempts = $path === 'payment-request' ? 4 : 1;
        $perAttemptTimeout = $path === 'payment-request' ? 20 : (int) $config['timeout'];
        $retryableStatuses = [502, 503, 504, 599];
        $response = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $response = $this->runCurlAttempt(
                path: $path,
                url: $url,
                payload: $payload,
                config: $config,
                reason: $reason,
                attempt: $attempt,
                attempts: $attempts,
                timeout: $perAttemptTimeout,
            );

            if (! in_array($response->status(), $retryableStatuses, true)) {
                return $response;
            }

            if ($attempt < $attempts) {
                $sleepMs = min(2000, 250 * (2 ** ($attempt - 1)));
                Log::channel('payments')->warning('PayFilmoteca provider POST curl retry scheduled', [
                    'provider_path' => $path,
                    'attempt' => $attempt,
                    'next_attempt' => $attempt + 1,
                    'http_status' => $response->status(),
                    'sleep_ms' => $sleepMs,
                ]);
                usleep($sleepMs * 1000);
            }
        }

        return $response;
    }

    protected function runCurlAttempt(string $path, string $url, array $payload, array $config, string $reason, int $attempt, int $attempts, int $timeout): Response
    {
        $command = [
            'curl',
            '-sS',
            '-v',
            '--noproxy',
            '*',
            '--ipv4',
            '--connect-timeout',
            '10',
            '--max-time',
            (string) $timeout,
            '-u',
            $config['username'].':'.$config['password'],
            '-H',
            'Auth-API-Key: '.$config['api_key'],
            '-w',
            "\n__PAYFILMOTECA_HTTP_CODE__:%{http_code}\n__PAYFILMOTECA_SERVER__:%header{server}\n__PAYFILMOTECA_REMOTE_IP__:%{remote_ip}\n__PAYFILMOTECA_TIME__:%{time_total}",
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
            'attempt' => $attempt,
            'attempts' => $attempts,
            'timeout' => $timeout,
            'payload' => $this->sanitizeProviderPayloadForLog($payload),
        ]);

        $env = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && is_string($value) && stripos($key, 'proxy') === false) {
                $env[$key] = $value;
            }
        }
        $env['NO_PROXY'] = '*';
        $env['no_proxy'] = '*';

        $process = new Process($command, null, $env);
        $process->setTimeout($timeout + 15);

        try {
            $process->run();
        } catch (\Throwable $exception) {
            Log::channel('payments')->error('PayFilmoteca provider POST curl process crashed', [
                'provider_path' => $path,
                'provider_url' => $url,
                'reason' => $reason,
                'attempt' => $attempt,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);

            return new Response(new PsrResponse(599, [], 'curl process crashed: '.$exception->getMessage()));
        }

        $output = $process->getOutput();
        $response = $this->responseFromCurlFallbackOutput($output);
        $exitCode = $process->getExitCode();
        $stderr = $process->getErrorOutput();
        $upstreamServer = $this->extractUpstreamServer($output);
        $remoteIp = $this->extractMarker($output, 'REMOTE_IP');
        $curlTime = $this->extractMarker($output, 'TIME');

        Log::channel('payments')->info('PayFilmoteca provider POST via curl completed', [
            'provider_path' => $path,
            'provider_url' => $url,
            'reason' => $reason,
            'attempt' => $attempt,
            'attempts' => $attempts,
            'exit_code' => $exitCode,
            'successful_process' => $process->isSuccessful(),
            'http_status' => $response->status(),
            'successful' => $response->successful(),
            'failed' => $response->failed(),
            'upstream_server' => $upstreamServer,
            'remote_ip' => $remoteIp,
            'curl_time' => $curlTime,
            'verbose_trace' => substr($this->sanitizeCurlVerbose($stderr), 0, 4000),
            'response' => $this->responsePayload($response),
        ]);

        if (! $process->isSuccessful() && $response->status() === 599) {
            return new Response(new PsrResponse(599, [], 'curl exit '.$exitCode.': '.$stderr));
        }

        return $response;
    }

    protected function extractUpstreamServer(string $output): ?string
    {
        return $this->extractMarker($output, 'SERVER');
    }

    protected function extractMarker(string $output, string $name): ?string
    {
        if (preg_match('~^__PAYFILMOTECA_'.preg_quote($name, '~').'__:(.*)$~m', $output, $matches) === 1) {
            $value = trim($matches[1]);

            return $value !== '' ? $value : null;
        }

        return null;
    }

    protected function sanitizeCurlVerbose(string $stderr): string
    {
        $lines = preg_split('~\r?\n~', $stderr) ?: [];
        $kept = [];

        foreach ($lines as $line) {
            if (preg_match('~^>\s*Authorization:~i', $line) === 1) {
                $kept[] = '> Authorization: [redacted]';
                continue;
            }
            if (preg_match('~^>\s*Auth-API-Key:~i', $line) === 1) {
                $kept[] = '> Auth-API-Key: [redacted]';
                continue;
            }
            $kept[] = $line;
        }

        return implode("\n", $kept);
    }

    protected function responseFromCurlFallbackOutput(string $output): Response
    {
        $status = 599;
        $body = $output;

        if (preg_match('~^__PAYFILMOTECA_HTTP_CODE__:(\d+)$~m', $output, $matches) === 1) {
            $status = (int) $matches[1];
        }

        $firstMarkerPos = strpos($body, "\n__PAYFILMOTECA_");
        if ($firstMarkerPos !== false) {
            $body = substr($body, 0, $firstMarkerPos);
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

    protected function providerResponseAccepted(Response $response, array $payload): bool
    {
        if (! $response->successful()) {
            return false;
        }

        $success = $this->extractValue($payload, ['success', 'Success', 'ok', 'OK']);
        if (is_bool($success)) {
            return $success;
        }

        if (is_string($success)) {
            return in_array(strtolower(trim($success)), ['1', 'true', 'yes', 'ok', 'success'], true);
        }

        if (is_numeric($success)) {
            return (int) $success === 1;
        }

        return true;
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
            'checkout_status',
            'checkoutStatus',
            'CheckoutStatus',
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

    /**
     * @return array{order_id: ?string, checkout_id: ?string, rrn: ?string}
     */
    protected function extractProviderIdentifiers(array $payload): array
    {
        $orderId = $this->extractValue($payload, ['order_id', 'orderId', 'OrderID', 'payment_id', 'paymentId']);
        $checkoutId = $this->extractValue($payload, ['checkout_id', 'checkoutId', 'CheckoutID', 'checkoutID', 'checkout']);
        $rrn = $this->extractValue($payload, ['rrn', 'RRN']);

        return [
            'order_id' => is_scalar($orderId) && trim((string) $orderId) !== '' ? trim((string) $orderId) : null,
            'checkout_id' => is_scalar($checkoutId) && trim((string) $checkoutId) !== '' ? trim((string) $checkoutId) : null,
            'rrn' => is_scalar($rrn) && trim((string) $rrn) !== '' ? trim((string) $rrn) : null,
        ];
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
        if (
            is_scalar($orderId)
            && (string) $orderId !== ''
            && (string) $topUp->provider_order_id !== ''
            && (string) $topUp->provider_checkout_id !== ''
            && ! in_array((string) $orderId, [(string) $topUp->provider_order_id, (string) $topUp->provider_checkout_id], true)
        ) {
            return false;
        }

        $checkoutId = $this->extractValue($details, ['checkout_id', 'checkoutId', 'CheckoutID', 'checkoutID']);
        if (is_scalar($checkoutId) && (string) $checkoutId !== '' && (string) $topUp->provider_checkout_id !== '' && (string) $checkoutId !== (string) $topUp->provider_checkout_id) {
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
