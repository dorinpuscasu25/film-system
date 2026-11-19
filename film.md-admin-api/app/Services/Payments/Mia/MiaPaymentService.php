<?php

namespace App\Services\Payments\Mia;

use App\Models\PaymentRefund;
use App\Models\PaymentTopUp;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Payments\TopUpPaymentProvider;
use App\Services\Payments\TopUpSettlementService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Wallet top-ups paid through MIA (BNM instant payments) with a Victoriabank dynamic QR.
 *
 * Flow: create a single-use QR for the exact amount → the customer scans it (or opens the
 * deeplink on mobile) → the bank posts a signed signal to /api/signals → we credit the
 * wallet. The bank never re-sends a signal, so pollPendingTopUps() asks for the status of
 * every QR still open (the documented, mandatory backup).
 */
class MiaPaymentService implements TopUpPaymentProvider
{
    public const STATUS_PAID = 'Paid';

    /** Statuses after which the QR can no longer be paid. */
    public const CLOSED_STATUSES = ['Expired', 'Cancelled', 'Inactive'];

    public function __construct(
        protected MiaClient $client,
        protected MiaSignalVerifier $verifier,
        protected TopUpSettlementService $settlement,
    ) {}

    public function isAvailable(): bool
    {
        return (bool) config('services.mia.enabled') && $this->client->isConfigured();
    }

    public function initiateTopUp(User $user, Wallet $wallet, array $payload): PaymentTopUp
    {
        if (! $this->isAvailable()) {
            throw ValidationException::withMessages([
                'method' => ['Plata prin MIA nu este disponibilă momentan.'],
            ]);
        }

        $amount = round((float) $payload['amount'], 2);
        $currency = strtoupper((string) ($payload['currency'] ?? $wallet->currency ?? Wallet::DEFAULT_CURRENCY));

        if ($currency !== 'MDL') {
            throw ValidationException::withMessages([
                'currency' => ['Plata prin MIA este disponibilă doar în lei (MDL).'],
            ]);
        }

        $ttlMinutes = max(1, (int) config('services.mia.qr_ttl_minutes', 15));

        $topUp = PaymentTopUp::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'provider' => PaymentTopUp::PROVIDER_MIA,
            'payment_method' => PaymentTopUp::METHOD_MIA,
            'billing_address_id' => $payload['billing_address_id'] ?? null,
            'subscriber_id' => 'user-'.$user->id,
            'amount' => $amount,
            'currency' => $currency,
            'status' => PaymentTopUp::STATUS_PENDING,
            'description' => 'Suplinire cont Filmoteca.md',
            'billing_address' => $payload['billing_address'] ?? null,
            'access_location' => $payload['access_location'] ?? null,
        ]);

        // creditorRef: ≤ 35 chars, letters and digits only. Shows up in reconciliation. The random
        // tail keeps it unique even when a test database starts its ids over.
        $reference = 'FLM'.str_pad((string) $topUp->id, 9, '0', STR_PAD_LEFT).strtoupper(Str::random(4));
        $request = [
            'header' => [
                'qrType' => 'DYNM',
                'amountType' => 'Fixed',
                'pmtContext' => 'e',
            ],
            'extension' => [
                'creditorAccount' => ['iban' => (string) config('services.mia.iban')],
                'amount' => ['sum' => number_format($amount, 2, '.', ''), 'currency' => 'MDL'],
                'dba' => $this->sanitizeText((string) config('services.mia.dba', 'Filmoteca'), 25),
                'remittanceInfo4Payer' => $this->sanitizeText('Suplinire cont '.$reference, 35),
                'creditorRef' => $reference,
                'ttl' => ['length' => $ttlMinutes, 'units' => 'mm'],
            ],
        ];

        $topUp->update([
            'provider_reference' => $reference,
            'raw_request' => $request,
        ]);

        try {
            $qr = $this->client->createQr($request);
        } catch (\Throwable $exception) {
            Log::channel('payments')->error('MIA QR creation failed', [
                'top_up_uuid' => $topUp->uuid,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
            ]);

            $topUp->update([
                'status' => PaymentTopUp::STATUS_FAILED,
                'provider_status' => 'qr_request_failed',
                'raw_response' => ['message' => $exception->getMessage()],
            ]);

            throw ValidationException::withMessages([
                'payment' => ['Nu am putut genera codul QR MIA. Încearcă din nou.'],
            ]);
        }

        $topUp->update([
            'status' => PaymentTopUp::STATUS_PROCESSING,
            'provider_status' => 'Active',
            'qr_header_uuid' => (string) $qr['qrHeaderUUID'],
            'qr_extension_uuid' => (string) $qr['qrExtensionUUID'],
            'qr_as_text' => $qr['qrAsText'] ?? null,
            'qr_expires_at' => now()->addMinutes($ttlMinutes),
            'raw_response' => $qr,
        ]);

        Log::channel('payments')->info('MIA QR created', [
            'top_up_uuid' => $topUp->uuid,
            'user_id' => $user->id,
            'amount' => $amount,
            'provider_reference' => $reference,
            'qr_header_uuid' => $qr['qrHeaderUUID'],
            'qr_extension_uuid' => $qr['qrExtensionUUID'],
        ]);

        return $topUp->fresh();
    }

    /**
     * Handles POST /api/signals. The body is a JSON string holding an RS256 JWT.
     *
     * @return array{status: int, top_up: ?PaymentTopUp}
     */
    public function handleSignal(string $rawBody): array
    {
        $decoded = json_decode($rawBody, true);
        $jwt = is_string($decoded) ? $decoded : trim($rawBody, " \t\n\r\0\x0B\"");

        $signal = $this->verifier->verify($jwt);
        if ($signal === null) {
            Log::channel('payments')->warning('MIA signal rejected: invalid signature', [
                'body_length' => strlen($rawBody),
            ]);

            return ['status' => 400, 'top_up' => null];
        }

        $extensionUuid = (string) ($signal['qrExtensionUUID'] ?? '');
        $headerUuid = (string) ($signal['qrHeaderUUID'] ?? '');

        $topUp = PaymentTopUp::query()
            ->where('provider', PaymentTopUp::PROVIDER_MIA)
            ->where(function ($query) use ($extensionUuid, $headerUuid): void {
                if ($extensionUuid !== '') {
                    $query->orWhere('qr_extension_uuid', $extensionUuid);
                }
                if ($headerUuid !== '') {
                    $query->orWhere('qr_header_uuid', $headerUuid);
                }
            })
            ->when($extensionUuid === '' && $headerUuid === '', fn ($query) => $query->whereRaw('1 = 0'))
            ->first();

        Log::channel('payments')->info('MIA signal received', [
            'signal_code' => $signal['signalCode'] ?? null,
            'signal_dt' => $signal['signalDtTm'] ?? null,
            'qr_extension_uuid' => $extensionUuid,
            'qr_header_uuid' => $headerUuid,
            'top_up_uuid' => $topUp?->uuid,
            'amount' => $signal['payment']['amount'] ?? null,
        ]);

        if ($topUp === null) {
            return ['status' => 404, 'top_up' => null];
        }

        $topUp->update(['raw_callback' => $signal]);

        $code = (string) ($signal['signalCode'] ?? '');
        if ($code === 'Payment' && is_array($signal['payment'] ?? null)) {
            $topUp = $this->applyPayment($topUp, $signal['payment'], 'signal');
        } elseif (in_array($code, ['Expiration', 'Inactivation'], true)) {
            $topUp = $this->markClosed($topUp, $code);
        }

        return ['status' => 204, 'top_up' => $topUp];
    }

    public function refreshStatus(PaymentTopUp $topUp, bool $force = false): PaymentTopUp
    {
        if ($topUp->provider !== PaymentTopUp::PROVIDER_MIA || blank($topUp->qr_extension_uuid)) {
            return $topUp;
        }

        if ($topUp->isTerminal() && ! $force) {
            return $topUp;
        }

        // The web polls while the QR is on screen; don't relay every poll to the bank.
        if (! $force && $topUp->last_checked_at !== null && $topUp->last_checked_at->gt(now()->subSeconds(5))) {
            return $topUp;
        }

        $topUp->forceFill(['last_checked_at' => now()])->save();

        try {
            $status = $this->client->extensionStatus((string) $topUp->qr_extension_uuid, 1);
        } catch (\Throwable $exception) {
            Log::channel('payments')->warning('MIA status check failed', [
                'top_up_uuid' => $topUp->uuid,
                'exception_message' => $exception->getMessage(),
            ]);

            return $topUp;
        }

        $providerStatus = (string) ($status['status'] ?? '');

        if ($providerStatus === self::STATUS_PAID) {
            $payment = $status['payments'][0] ?? null;

            if (is_array($payment)) {
                return $this->applyPayment($topUp, $payment, 'status');
            }

            Log::channel('payments')->error('MIA QR is Paid but carries no payment', [
                'top_up_uuid' => $topUp->uuid,
                'status' => $status,
            ]);

            return $topUp;
        }

        if (in_array($providerStatus, self::CLOSED_STATUSES, true)) {
            return $this->markClosed($topUp, $providerStatus);
        }

        if ($providerStatus !== '' && $topUp->provider_status !== $providerStatus && $topUp->credited_at === null) {
            $topUp->forceFill(['provider_status' => $providerStatus])->save();
        }

        return $topUp->fresh();
    }

    /** Cancels a QR the customer walked away from, so it cannot be paid later. */
    public function cancelTopUp(PaymentTopUp $topUp): PaymentTopUp
    {
        if ($topUp->provider !== PaymentTopUp::PROVIDER_MIA || $topUp->isTerminal() || blank($topUp->qr_header_uuid)) {
            return $topUp;
        }

        try {
            $this->client->cancelQr((string) $topUp->qr_header_uuid);
        } catch (\Throwable $exception) {
            Log::channel('payments')->warning('MIA QR cancel failed', [
                'top_up_uuid' => $topUp->uuid,
                'exception_message' => $exception->getMessage(),
            ]);
        }

        // The customer may have paid a moment before cancelling: the bank's status decides.
        return $this->refreshStatus($topUp->fresh(), true);
    }

    public function pollPendingTopUps(int $limit = 50): array
    {
        $stats = ['checked' => 0, 'paid' => 0, 'canceled' => 0, 'processing' => 0, 'refunds_reconciled' => 0];

        PaymentTopUp::query()
            ->where('provider', PaymentTopUp::PROVIDER_MIA)
            ->whereIn('status', [PaymentTopUp::STATUS_PENDING, PaymentTopUp::STATUS_PROCESSING])
            ->whereNotNull('qr_extension_uuid')
            ->where('created_at', '>=', now()->subDays(2))
            ->oldest('id')
            ->limit(max(1, $limit))
            ->get()
            ->each(function (PaymentTopUp $topUp) use (&$stats): void {
                $refreshed = $this->refreshStatus($topUp, true);
                $stats['checked']++;

                if (isset($stats[$refreshed->status])) {
                    $stats[$refreshed->status]++;
                }
            });

        PaymentRefund::query()
            ->where('provider', PaymentTopUp::PROVIDER_MIA)
            ->where('status', PaymentRefund::STATUS_REQUESTED)
            ->where('created_at', '<=', now()->subMinute())
            ->oldest('id')
            ->limit(20)
            ->get()
            ->each(function (PaymentRefund $refund) use (&$stats): void {
                if ($this->reconcileRefund($refund)) {
                    $stats['refunds_reconciled']++;
                }
            });

        return $stats;
    }

    public function refundTopUp(PaymentTopUp $topUp, float $amount, string $reason, ?User $admin = null): PaymentRefund
    {
        $amount = round(abs($amount), 2);
        $reason = trim($reason);
        $transactionId = trim((string) $topUp->provider_rrn);

        if ($topUp->provider !== PaymentTopUp::PROVIDER_MIA) {
            throw ValidationException::withMessages(['top_up' => ['Tranzacția nu a fost plătită prin MIA.']]);
        }

        if ($topUp->status !== PaymentTopUp::STATUS_PAID) {
            throw ValidationException::withMessages(['top_up' => ['Doar plățile achitate pot fi rambursate.']]);
        }

        if ($transactionId === '') {
            throw ValidationException::withMessages(['top_up' => ['Lipsește ID-ul tranzacției MIA pentru această plată.']]);
        }

        // The bank allows one refund per payment: a partial refund consumes the transaction.
        if ($topUp->refunds()->whereIn('status', [PaymentRefund::STATUS_SUCCEEDED, PaymentRefund::STATUS_REQUESTED])->exists()) {
            throw ValidationException::withMessages([
                'refund' => ['MIA permite o singură rambursare per plată, iar aceasta a fost deja făcută sau este în curs.'],
            ]);
        }

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => ['Suma refundului trebuie să fie pozitivă.']]);
        }

        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['reason' => ['Motivul refundului este obligatoriu (cel mult 500 de caractere).']]);
        }

        $maxRefundable = $this->settlement->refundableAmount($topUp);
        if ($amount > $maxRefundable) {
            throw ValidationException::withMessages([
                'amount' => [sprintf('Suma maximă disponibilă pentru refund este %.2f %s.', $maxRefundable, $topUp->currency)],
            ]);
        }

        $isFull = abs($amount - round((float) $topUp->amount, 2)) < 0.005;

        $refund = PaymentRefund::query()->create([
            'uuid' => (string) Str::uuid(),
            'payment_top_up_id' => $topUp->id,
            'user_id' => $topUp->user_id,
            'wallet_id' => $topUp->wallet_id,
            'provider' => PaymentTopUp::PROVIDER_MIA,
            'requested_by_admin_id' => $admin?->id,
            'provider_order_id' => $topUp->provider_order_id,
            'provider_checkout_id' => (string) $topUp->qr_extension_uuid,
            'provider_rrn' => $transactionId,
            'amount' => $amount,
            'currency' => $topUp->currency,
            'reason' => $reason,
            'status' => PaymentRefund::STATUS_REQUESTED,
            'raw_request' => ['type' => $isFull ? 'full' : 'partial', 'transaction_id' => $transactionId, 'amount' => $amount],
        ]);

        try {
            $response = $isFull
                ? $this->client->fullRefund($transactionId)
                : $this->client->partialRefund($transactionId, $amount);
        } catch (ConnectionException $exception) {
            return $this->leaveRefundUnknown($refund, $exception->getMessage());
        }

        if ($response->successful()) {
            return $this->settlement->settleRefund($refund, ['http_status' => $response->status()], 'accepted', 'MIA refund (Victoriabank)');
        }

        if ($response->serverError()) {
            return $this->leaveRefundUnknown($refund, 'HTTP '.$response->status());
        }

        $refund->forceFill([
            'status' => PaymentRefund::STATUS_FAILED,
            'provider_status' => 'rejected_'.$response->status(),
            'raw_response' => ['http_status' => $response->status(), 'body' => $response->json() ?? $response->body()],
            'processed_at' => now(),
        ])->save();

        $description = $response->json('description');

        throw ValidationException::withMessages([
            'refund' => [is_string($description) && $description !== '' ? $description : 'Banca a refuzat refundul (HTTP '.$response->status().').'],
        ]);
    }

    /**
     * Looks a refund whose outcome we never learned up in the bank's reconciliation list
     * (a QRREFUND row naming the original transaction) and settles it if it is there.
     */
    public function reconcileRefund(PaymentRefund $refund): bool
    {
        if ($refund->status !== PaymentRefund::STATUS_REQUESTED || blank($refund->provider_rrn)) {
            return false;
        }

        try {
            $rows = $this->client->reconciliation(
                $refund->created_at->copy()->timezone('Europe/Chisinau')->toDateString(),
                now()->timezone('Europe/Chisinau')->toDateString(),
            );
        } catch (\Throwable $exception) {
            Log::channel('payments')->warning('MIA refund reconciliation failed', [
                'payment_refund_uuid' => $refund->uuid,
                'exception_message' => $exception->getMessage(),
            ]);

            return false;
        }

        $match = collect($rows)->first(fn (array $row): bool => ($row['transactionType'] ?? null) === 'QRREFUND'
            && ($row['transactionStatus'] ?? null) === 'Approved'
            && str_contains((string) ($row['transactionMessage'] ?? ''), (string) $refund->provider_rrn));

        if ($match === null) {
            if ($refund->created_at->lt(now()->subDay())) {
                $refund->forceFill([
                    'status' => PaymentRefund::STATUS_FAILED,
                    'provider_status' => 'not_found_in_reconciliation',
                    'processed_at' => now(),
                ])->save();
            }

            return false;
        }

        $bankAmount = round((float) ($match['transactionAmount'] ?? 0), 2);
        if ($bankAmount > 0 && abs($bankAmount - (float) $refund->amount) >= 0.005) {
            Log::channel('payments')->error('MIA refund reconciled with a different amount', [
                'payment_refund_uuid' => $refund->uuid,
                'requested_amount' => $refund->amount,
                'bank_amount' => $bankAmount,
            ]);
            $refund->forceFill(['amount' => $bankAmount])->save();
        }

        $this->settlement->settleRefund($refund, ['reconciliation' => $match], 'reconciled', 'MIA refund (Victoriabank)');

        return true;
    }

    /**
     * Fields the web needs to show the QR while it can still be paid.
     */
    public function qrData(PaymentTopUp $topUp): ?array
    {
        if ($topUp->provider !== PaymentTopUp::PROVIDER_MIA || $topUp->isTerminal() || blank($topUp->qr_extension_uuid)) {
            return null;
        }

        $image = $topUp->raw_response['qrAsImage'] ?? null;

        return [
            'image' => is_string($image) && $image !== '' ? 'data:image/png;base64,'.$image : null,
            'text' => $topUp->qr_as_text,
            'expires_at' => $topUp->qr_expires_at?->toIso8601String(),
        ];
    }

    protected function applyPayment(PaymentTopUp $topUp, array $payment, string $source): PaymentTopUp
    {
        $reference = (string) ($payment['reference'] ?? '');
        $paidAmount = round((float) ($payment['amount']['sum'] ?? 0), 2);
        $paidCurrency = strtoupper((string) ($payment['amount']['currency'] ?? 'MDL'));
        $segments = explode('|', $reference);
        $transactionId = trim((string) ($segments[3] ?? ''));

        // A Fixed dynamic QR can only be paid for its exact amount; anything else needs a human.
        if (abs($paidAmount - round((float) $topUp->amount, 2)) >= 0.005 || $paidCurrency !== strtoupper((string) $topUp->currency)) {
            Log::channel('payments')->critical('MIA payment does not match the top-up', [
                'top_up_uuid' => $topUp->uuid,
                'source' => $source,
                'expected_amount' => $topUp->amount,
                'expected_currency' => $topUp->currency,
                'paid_amount' => $paidAmount,
                'paid_currency' => $paidCurrency,
                'reference' => $reference,
            ]);

            $topUp->forceFill(['provider_status' => 'amount_mismatch'])->save();

            return $topUp->fresh();
        }

        if ($topUp->credited_at === null) {
            $topUp->forceFill([
                'provider_order_id' => $reference !== '' ? $reference : $topUp->provider_order_id,
                'provider_rrn' => $transactionId !== '' ? $transactionId : $topUp->provider_rrn,
            ])->save();
        }

        $this->settlement->creditWallet($topUp, ['source' => $source, 'payment' => $payment], self::STATUS_PAID);

        return $topUp->fresh();
    }

    protected function markClosed(PaymentTopUp $topUp, string $providerStatus): PaymentTopUp
    {
        if ($topUp->credited_at !== null || $topUp->isTerminal()) {
            return $topUp;
        }

        $topUp->forceFill([
            'status' => PaymentTopUp::STATUS_CANCELED,
            'provider_status' => $providerStatus,
        ])->save();

        return $topUp->fresh();
    }

    protected function leaveRefundUnknown(PaymentRefund $refund, string $reason): PaymentRefund
    {
        Log::channel('payments')->warning('MIA refund outcome unknown; will reconcile', [
            'payment_refund_uuid' => $refund->uuid,
            'reason' => $reason,
        ]);

        $refund->forceFill([
            'provider_status' => 'unknown',
            'raw_response' => ['message' => $reason],
        ])->save();

        // The refund may already be in the reconciliation list; settle it now if so.
        $this->reconcileRefund($refund);

        return $refund->fresh();
    }

    protected function sanitizeText(string $value, int $maxLength): string
    {
        $ascii = Str::ascii($value);
        $clean = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^A-Za-z0-9 ]/', '', $ascii)));

        return mb_substr($clean, 0, $maxLength);
    }
}
