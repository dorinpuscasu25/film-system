<?php

namespace App\Services\Payments\CyberSource;

use App\Models\PaymentRefund;
use App\Models\PaymentTopUp;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Payments\TopUpPaymentProvider;
use App\Services\Payments\TopUpSettlementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Victoriabank\CyberSource\Exception\UnknownStatusException;
use Victoriabank\CyberSource\Exception\VbRuleException;
use Victoriabank\CyberSource\Requests;
use Victoriabank\CyberSource\VbCyberSource;
use Victoriabank\CyberSource\Webhook;

/**
 * Card top-ups through Victoriabank / CyberSource Unified Checkout — the bank's scenario A
 * (direct payment: nothing stored, no Account Verification, no MIT).
 *
 *   server  POST /uc/v1/sessions (autoProcessing, completeMandate CAPTURE|AUTH + 3DS)   initiateTopUp()
 *   browser VAS.UnifiedCheckout(jwt).createCheckout({autoProcessing:true}).mount()     → result JWT
 *   server  verify RS256 + GET /tss/v2/transactions/{id}, match reference/amount/3DS   submitResult()
 *   webhook uc.orders.transactionresults, then the same confirmation through the API    handleWebhook()
 *   cron    search by reference for sessions whose result never arrived (R10)          pollPendingTopUps()
 *
 * The browser never decides the outcome (R9); a payment without full 3-D Secure, or one that
 * does not belong to this top-up, is voided / reversed instead of credited (R4).
 */
class CyberSourcePaymentService implements TopUpPaymentProvider
{
    /** Unified Checkout sessions live 15 minutes; after that an unpaid top-up is abandoned. */
    public const SESSION_TTL_MINUTES = 15;

    public function __construct(
        protected CyberSourceSdkFactory $sdk,
        protected TopUpSettlementService $settlement,
    ) {}

    public function isAvailable(): bool
    {
        return (bool) config('services.cybersource.enabled') && $this->sdk->isConfigured();
    }

    public function initiateTopUp(User $user, Wallet $wallet, array $payload, Request $request): PaymentTopUp
    {
        if (! $this->isAvailable()) {
            throw ValidationException::withMessages(['method' => ['Plata cu cardul este temporar indisponibilă.']]);
        }

        $amount = round((float) $payload['amount'], 2);
        $currency = strtoupper((string) ($payload['currency'] ?? $wallet->currency ?? Wallet::DEFAULT_CURRENCY));
        $origin = $this->targetOrigin($request);
        $capture = (bool) config('services.cybersource.capture', false);
        $paymentTypes = (array) config('services.cybersource.payment_types', ['PANENTRY']);

        $topUp = PaymentTopUp::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'provider' => PaymentTopUp::PROVIDER_CYBERSOURCE,
            'payment_method' => PaymentTopUp::METHOD_CARD,
            'billing_address_id' => $payload['billing_address_id'] ?? null,
            'subscriber_id' => 'user-'.$user->id,
            'amount' => $amount,
            'currency' => $currency,
            'status' => PaymentTopUp::STATUS_PENDING,
            'description' => 'Suplinire cont Filmoteca.md',
            'billing_address' => $payload['billing_address'] ?? null,
            'access_location' => $payload['access_location'] ?? null,
        ]);

        // clientReferenceInformation.code: one per authorization attempt; follow-ons get suffixes (R10).
        // The random tail keeps references unique even when a test database starts its ids over.
        $reference = 'FLM'.str_pad((string) $topUp->id, 9, '0', STR_PAD_LEFT).'-'.strtoupper(Str::random(4));
        $topUp->update([
            'provider_reference' => $reference,
            'raw_request' => [
                'target_origin' => $origin,
                'capture' => $capture,
                'payment_types' => $paymentTypes,
            ],
        ]);

        try {
            $session = $this->vb($currency)->createUcDirectSession(
                $origin,
                $reference,
                number_format($amount, 2, '.', ''),
                $this->billTo($user, $payload['billing_address'] ?? null),
                $this->locale((string) ($payload['locale'] ?? $user->preferred_locale ?? 'ro')),
                $paymentTypes,
                $capture,
            );
        } catch (\Throwable $exception) {
            Log::channel('payments')->error('CyberSource session creation failed', [
                'top_up_uuid' => $topUp->uuid,
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
                'violations' => $exception instanceof VbRuleException ? $exception->violations : null,
            ]);

            $topUp->update([
                'status' => PaymentTopUp::STATUS_FAILED,
                'provider_status' => $exception instanceof VbRuleException ? 'session_rule_violation' : 'session_error',
                'raw_response' => ['message' => $exception->getMessage()],
            ]);

            throw ValidationException::withMessages(['payment' => ['Nu am putut iniția plata cu cardul. Încearcă din nou.']]);
        }

        if (! ($session['ok'] ?? false)) {
            Log::channel('payments')->error('CyberSource session rejected', [
                'top_up_uuid' => $topUp->uuid,
                'http_status' => $session['httpStatus'] ?? null,
                'response' => $session['data'] ?? null,
            ]);

            $topUp->update([
                'status' => PaymentTopUp::STATUS_FAILED,
                'provider_status' => 'session_rejected',
                'raw_response' => ['http_status' => $session['httpStatus'] ?? null, 'body' => $session['data'] ?? null],
            ]);

            throw ValidationException::withMessages(['payment' => ['Banca a refuzat inițierea plății cu cardul.']]);
        }

        $topUp->update([
            'status' => PaymentTopUp::STATUS_PROCESSING,
            'provider_status' => 'session_created',
            'raw_response' => [
                'session_jwt' => $session['sessionJwt'],
                'client_library' => $session['clientLibrary'],
                'client_library_integrity' => $session['clientLibraryIntegrity'],
                'session_created_at' => now()->toIso8601String(),
            ],
        ]);

        Log::channel('payments')->info('CyberSource Unified Checkout session created', [
            'top_up_uuid' => $topUp->uuid,
            'user_id' => $user->id,
            'provider_reference' => $reference,
            'amount' => $amount,
            'currency' => $currency,
            'target_origin' => $origin,
            'capture' => $capture,
        ]);

        return $topUp->fresh();
    }

    /** The browser hands back the signed Unified Checkout result; we confirm it through the API (R9). */
    public function submitResult(PaymentTopUp $topUp, string $resultJwt): PaymentTopUp
    {
        if ($topUp->provider !== PaymentTopUp::PROVIDER_CYBERSOURCE) {
            throw ValidationException::withMessages(['top_up' => ['Această plată nu este o plată cu cardul.']]);
        }

        if ($topUp->credited_at !== null || $topUp->isTerminal()) {
            return $topUp;
        }

        try {
            $confirmation = $this->vb($topUp->currency)->confirmUcResult(
                $resultJwt,
                (string) $topUp->provider_reference,
                number_format((float) $topUp->amount, 2, '.', ''),
                strtoupper((string) $topUp->currency),
            );
        } catch (UnknownStatusException $exception) {
            $this->markConfirming($topUp, $exception->getMessage());

            return $topUp->fresh();
        } catch (\Throwable $exception) {
            Log::channel('payments')->warning('CyberSource result rejected', [
                'top_up_uuid' => $topUp->uuid,
                'exception_message' => $exception->getMessage(),
            ]);

            throw ValidationException::withMessages(['result_jwt' => ['Rezultatul plății nu a putut fi verificat.']]);
        }

        // The SDK verified the signature and fetched the transaction. The decision itself is taken
        // from the transaction (the source of truth, R9) with our normalisation: real result JWTs
        // carry a UC status (not AUTHORIZED) and real TSS answers have no applicationInformation.status.
        if (($confirmation['state'] ?? null) !== 'PENDING' && is_array($confirmation['transaction'] ?? null)) {
            $confirmation = $this->confirmationFromTransaction(
                $this->vb($topUp->currency),
                $topUp,
                (string) $confirmation['id'],
                $confirmation['transaction'],
            );
        }

        return $this->applyConfirmation($topUp, $confirmation, 'browser');
    }

    public function refreshStatus(PaymentTopUp $topUp, bool $force = false): PaymentTopUp
    {
        if ($topUp->provider !== PaymentTopUp::PROVIDER_CYBERSOURCE || ($topUp->isTerminal() && ! $force) || $topUp->credited_at !== null) {
            return $topUp;
        }

        if (! $force && $topUp->last_checked_at !== null && $topUp->last_checked_at->gt(now()->subSeconds(5))) {
            return $topUp;
        }

        $topUp->forceFill(['last_checked_at' => now()])->save();
        $vb = $this->vb($topUp->currency);

        try {
            $paymentId = $topUp->provider_checkout_id ?: $this->findPaymentId($vb, (string) $topUp->provider_reference);

            if ($paymentId === null) {
                // No transaction yet: the customer is still on the form, or walked away.
                if ($topUp->created_at->lt(now()->subMinutes(self::SESSION_TTL_MINUTES + 15))) {
                    $topUp->forceFill(['status' => PaymentTopUp::STATUS_CANCELED, 'provider_status' => 'session_expired'])->save();
                }

                return $topUp->fresh();
            }

            $response = $vb->getTransaction($paymentId);
        } catch (UnknownStatusException $exception) {
            return $topUp->fresh();
        } catch (\Throwable $exception) {
            Log::channel('payments')->warning('CyberSource status check failed', [
                'top_up_uuid' => $topUp->uuid,
                'exception_message' => $exception->getMessage(),
            ]);

            return $topUp->fresh();
        }

        if (($response['httpStatus'] ?? 0) !== 200 || ! is_array($response['data'] ?? null)) {
            return $topUp->fresh();
        }

        return $this->applyConfirmation($topUp, $this->confirmationFromTransaction($vb, $topUp, $paymentId, $response['data']), 'status');
    }

    public function pollPendingTopUps(int $limit = 50): array
    {
        $stats = ['checked' => 0, 'paid' => 0, 'failed' => 0, 'canceled' => 0, 'processing' => 0, 'refunds_reconciled' => 0];

        PaymentTopUp::query()
            ->where('provider', PaymentTopUp::PROVIDER_CYBERSOURCE)
            ->whereIn('status', [PaymentTopUp::STATUS_PENDING, PaymentTopUp::STATUS_PROCESSING])
            ->whereNotNull('provider_reference')
            // R10: give the gateway at least 60 s before searching.
            ->where('created_at', '<=', now()->subMinute())
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
            ->where('provider', PaymentTopUp::PROVIDER_CYBERSOURCE)
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

        if ($topUp->provider !== PaymentTopUp::PROVIDER_CYBERSOURCE) {
            throw ValidationException::withMessages(['top_up' => ['Tranzacția nu a fost plătită cu cardul prin Victoriabank.']]);
        }

        if ($topUp->status !== PaymentTopUp::STATUS_PAID) {
            throw ValidationException::withMessages(['top_up' => ['Doar plățile achitate pot fi rambursate.']]);
        }

        // A sale is refunded on its payment id; an AUTH + capture is refunded on the capture id.
        $refundableId = (string) ($topUp->raw_details['capture_id'] ?? $topUp->provider_checkout_id ?? '');
        if ($refundableId === '') {
            throw ValidationException::withMessages(['top_up' => ['Lipsește ID-ul tranzacției CyberSource.']]);
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

        $refundReference = $topUp->provider_reference.'-RF'.($topUp->refunds()->count() + 1);

        $refund = PaymentRefund::query()->create([
            'uuid' => (string) Str::uuid(),
            'payment_top_up_id' => $topUp->id,
            'user_id' => $topUp->user_id,
            'wallet_id' => $topUp->wallet_id,
            'provider' => PaymentTopUp::PROVIDER_CYBERSOURCE,
            'requested_by_admin_id' => $admin?->id,
            'provider_order_id' => $refundReference,
            'provider_checkout_id' => $refundableId,
            'provider_rrn' => (string) ($topUp->provider_rrn ?? ''),
            'amount' => $amount,
            'currency' => $topUp->currency,
            'reason' => $reason,
            'status' => PaymentRefund::STATUS_REQUESTED,
            'raw_request' => ['reference' => $refundReference, 'refunded_id' => $refundableId, 'amount' => $amount],
        ]);

        try {
            $response = $this->vb($topUp->currency)->refund($refundableId, $refundReference, number_format($amount, 2, '.', ''));
        } catch (UnknownStatusException $exception) {
            Log::channel('payments')->warning('CyberSource refund outcome unknown; will reconcile', [
                'payment_refund_uuid' => $refund->uuid,
                'message' => $exception->getMessage(),
            ]);
            $refund->forceFill(['provider_status' => 'unknown', 'raw_response' => ['message' => $exception->getMessage()]])->save();

            return $refund->fresh();
        } catch (VbRuleException $exception) {
            $refund->forceFill(['status' => PaymentRefund::STATUS_FAILED, 'provider_status' => 'rule_violation', 'processed_at' => now()])->save();

            throw ValidationException::withMessages(['refund' => ['Cererea de refund nu respectă regulile băncii: '.$exception->getMessage()]]);
        }

        $status = is_array($response['data'] ?? null) ? ($response['data']['status'] ?? null) : null;

        if (in_array($response['httpStatus'] ?? 0, [200, 201], true) && in_array($status, ['PENDING', 'TRANSMITTED', 'REFUNDED'], true)) {
            $refund->forceFill(['provider_checkout_id' => $response['data']['id'] ?? $refundableId])->save();

            return $this->settlement->settleRefund($refund, $response['data'], $status, 'Victoriabank card refund');
        }

        $refund->forceFill([
            'status' => PaymentRefund::STATUS_FAILED,
            'provider_status' => strtolower((string) ($status ?? 'http_'.($response['httpStatus'] ?? 0))),
            'raw_response' => $response['data'] ?? null,
            'processed_at' => now(),
        ])->save();

        $message = is_array($response['data'] ?? null) ? ($response['data']['message'] ?? $response['data']['errorInformation']['message'] ?? null) : null;

        throw ValidationException::withMessages(['refund' => [is_string($message) && $message !== '' ? $message : 'Banca a refuzat refundul.']]);
    }

    /**
     * POST webhook (uc.orders.transactionresults and payment events). Only a signal: the state
     * changes after the transaction is confirmed through the API, exactly like the poller does.
     *
     * @return int HTTP status for CyberSource
     */
    public function handleWebhook(string $rawBody, ?string $signatureHeader): int
    {
        $config = (array) config('services.cybersource', []);

        try {
            $valid = Webhook::verifySignature($signatureHeader, $rawBody, (string) ($config['webhook_key'] ?? ''), $config['webhook_key_id'] ?? null);
        } catch (\Throwable) {
            $valid = false;
        }

        if (! $valid) {
            Log::channel('payments')->warning('CyberSource webhook rejected: invalid signature');

            return 401;
        }

        try {
            $event = $this->decodeWebhook($rawBody);
        } catch (\Throwable $exception) {
            // Still acknowledged: the poller settles the payment from the API either way.
            Log::channel('payments')->warning('CyberSource webhook could not be decoded', ['message' => $exception->getMessage()]);

            return 200;
        }

        $payload = is_array($event['payload'] ?? null) ? $event['payload'] : [];
        $paymentId = (string) ($payload['id'] ?? $payload['details']['id'] ?? '');
        $reference = (string) ($payload['details']['clientReferenceInformation']['code'] ?? $payload['clientReferenceInformation']['code'] ?? '');

        Log::channel('payments')->info('CyberSource webhook received', [
            'event_type' => $event['eventType'] ?? null,
            'webhook_id' => $event['webhookId'] ?? null,
            'payment_id' => $paymentId,
            'reference' => $reference,
        ]);

        $topUp = $this->topUpForWebhook($paymentId, $reference);
        if ($topUp === null) {
            return 200;
        }

        $topUp->forceFill(['raw_callback' => ['event' => $event['eventType'] ?? null, 'payment_id' => $paymentId, 'received_at' => now()->toIso8601String()]])->save();

        if ($topUp->credited_at !== null || $topUp->isTerminal()) {
            return 200;   // duplicate delivery: already settled
        }

        if ($paymentId === '') {
            $this->refreshStatus($topUp, true);

            return 200;
        }

        // Confirm through the API before changing anything (§12).
        try {
            $vb = $this->vb($topUp->currency);
            $response = $vb->getTransaction($paymentId);
        } catch (\Throwable) {
            return 200;
        }

        if (($response['httpStatus'] ?? 0) === 200 && is_array($response['data'] ?? null)) {
            $this->applyConfirmation($topUp, $this->confirmationFromTransaction($vb, $topUp, $paymentId, $response['data']), 'webhook');
        }

        return 200;
    }

    /**
     * Data the browser needs to mount Unified Checkout, while the session can still be used.
     */
    public function sessionData(PaymentTopUp $topUp): ?array
    {
        if ($topUp->provider !== PaymentTopUp::PROVIDER_CYBERSOURCE || $topUp->isTerminal() || filled($topUp->provider_checkout_id)) {
            return null;
        }

        $raw = (array) ($topUp->raw_response ?? []);
        if (blank($raw['session_jwt'] ?? null) || $topUp->created_at->lt(now()->subMinutes(self::SESSION_TTL_MINUTES))) {
            return null;
        }

        return [
            'session_jwt' => $raw['session_jwt'],
            'client_library' => $raw['client_library'] ?? null,
            'client_library_integrity' => $raw['client_library_integrity'] ?? null,
        ];
    }

    public function reconcileRefund(PaymentRefund $refund): bool
    {
        if ($refund->status !== PaymentRefund::STATUS_REQUESTED || blank($refund->provider_order_id)) {
            return false;
        }

        try {
            $found = $this->vb($refund->currency)->searchByReference((string) $refund->provider_order_id, ['from' => 'NOW/DAY-7DAYS']);
        } catch (\Throwable $exception) {
            Log::channel('payments')->warning('CyberSource refund reconciliation failed', [
                'payment_refund_uuid' => $refund->uuid,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }

        $credit = collect($found['transactions'] ?? [])->first(function (array $summary): bool {
            $apps = collect($summary['applicationInformation']['applications'] ?? []);

            return $apps->contains(fn (array $app): bool => ($app['name'] ?? null) === 'ics_credit' && ($app['rCode'] ?? null) === '1');
        });

        if ($credit === null) {
            if ($refund->created_at->lt(now()->subDay())) {
                $refund->forceFill(['status' => PaymentRefund::STATUS_FAILED, 'provider_status' => 'not_found_by_search', 'processed_at' => now()])->save();
            }

            return false;
        }

        $refund->forceFill(['provider_checkout_id' => $credit['id'] ?? $refund->provider_checkout_id])->save();
        $this->settlement->settleRefund($refund, ['search' => $credit], 'reconciled', 'Victoriabank card refund');

        return true;
    }

    /**
     * @param  array<string, mixed>  $c  confirmUcResult() result (or the same shape from confirmationFromTransaction())
     */
    protected function applyConfirmation(PaymentTopUp $topUp, array $c, string $source): PaymentTopUp
    {
        $paymentId = (string) ($c['id'] ?? '');
        $next = (string) ($c['outcome']['next'] ?? 'UNKNOWN');

        Log::channel('payments')->info('CyberSource confirmation', [
            'top_up_uuid' => $topUp->uuid,
            'source' => $source,
            'payment_id' => $paymentId,
            'state' => $c['state'] ?? null,
            'next' => $next,
            'paid' => $c['paid'] ?? false,
            'must_release' => $c['mustRelease'] ?? false,
            'eci' => $c['evidence']['eci'] ?? null,
            'full_3ds' => $c['evidence']['full3ds'] ?? null,
        ]);

        if (($c['state'] ?? null) === 'PENDING') {
            $this->markConfirming($topUp, 'transaction_not_searchable_yet', $paymentId);

            return $topUp->fresh();
        }

        if (($c['state'] ?? null) === 'MISMATCH') {
            // A valid payment that is not this top-up's (e.g. an old session finished later): release it.
            Log::channel('payments')->critical('CyberSource result does not match the top-up', [
                'top_up_uuid' => $topUp->uuid,
                'payment_id' => $paymentId,
                'reference' => $c['transaction']['clientReferenceInformation']['code'] ?? null,
            ]);

            if (in_array($next, ['APPROVED', 'REVIEW', 'PARTIAL'], true)) {
                $this->release($topUp, $paymentId, $c['authorizedAmount'] ?? null, 'MS');
            }

            $topUp->forceFill(['provider_status' => 'result_mismatch'])->save();

            return $topUp->fresh();
        }

        if ($paymentId !== '' && ! $this->paymentIdTaken($paymentId, $topUp)) {
            $topUp->forceFill([
                'provider_checkout_id' => $paymentId,
                'provider_rrn' => $c['transaction']['processorInformation']['retrievalReferenceNumber'] ?? $topUp->provider_rrn,
            ])->save();
        }

        if ($c['paid'] ?? false) {
            return $this->settlePaid($topUp->fresh(), $paymentId, $c);
        }

        if ($c['mustRelease'] ?? false) {
            $this->release($topUp, $paymentId, $c['authorizedAmount'] ?? $topUp->amount, 'RV');
            $topUp->forceFill([
                'status' => PaymentTopUp::STATUS_FAILED,
                'provider_status' => ($c['mustReverse'] ?? false) ? 'released_without_full_3ds' : 'released_'.strtolower($next),
                'raw_details' => $this->details($c),
            ])->save();

            return $topUp->fresh();
        }

        if (in_array($next, ['DECLINED', 'AUTH_FAILED', 'FIX_REQUEST'], true)) {
            $topUp->forceFill([
                'status' => PaymentTopUp::STATUS_FAILED,
                'provider_status' => strtolower($next).($c['outcome']['reason'] ?? null ? ':'.$c['outcome']['reason'] : ''),
                'raw_details' => $this->details($c),
            ])->save();

            return $topUp->fresh();
        }

        $this->markConfirming($topUp, 'outcome_'.strtolower($next), $paymentId);

        return $topUp->fresh();
    }

    protected function settlePaid(PaymentTopUp $topUp, string $paymentId, array $c): PaymentTopUp
    {
        $details = $this->details($c);

        if (! (bool) ($topUp->raw_request['capture'] ?? true) && blank($topUp->raw_details['capture_id'] ?? null)) {
            // AUTH mode: capture before crediting, so the wallet only holds money that will settle.
            try {
                $capture = $this->vb($topUp->currency)->capture($paymentId, $topUp->provider_reference.'-CP1', number_format((float) $topUp->amount, 2, '.', ''));
            } catch (UnknownStatusException $exception) {
                $this->markConfirming($topUp, 'capture_unknown', $paymentId);

                return $topUp->fresh();
            }

            if (! in_array($capture['httpStatus'] ?? 0, [200, 201], true) || ! in_array($capture['data']['status'] ?? null, ['PENDING', 'TRANSMITTED'], true)) {
                $this->release($topUp, $paymentId, $topUp->amount, 'RV');
                $topUp->forceFill(['status' => PaymentTopUp::STATUS_FAILED, 'provider_status' => 'capture_failed', 'raw_details' => $details])->save();

                return $topUp->fresh();
            }

            $details['capture_id'] = $capture['data']['id'] ?? null;
            $topUp->forceFill(['raw_details' => $details])->save();
        }

        $this->settlement->creditWallet($topUp, $details, (string) ($c['status'] ?? 'AUTHORIZED'));

        return $topUp->fresh();
    }

    /** Void a sale (CAPTURE) or reverse an authorization (AUTH). Never a new attempt. */
    protected function release(PaymentTopUp $topUp, string $paymentId, mixed $amount, string $suffix): void
    {
        if ($paymentId === '') {
            return;
        }

        $vb = $this->vb($topUp->currency);
        $capture = (bool) ($topUp->raw_request['capture'] ?? true);

        try {
            $response = $capture
                ? $vb->voidPayment($paymentId, $topUp->provider_reference.'-VD'.($suffix === 'MS' ? '9' : '1'))
                : $vb->reverse($paymentId, $topUp->provider_reference.'-'.$suffix.'1', number_format((float) ($amount ?? $topUp->amount), 2, '.', ''));

            Log::channel('payments')->info('CyberSource payment released', [
                'top_up_uuid' => $topUp->uuid,
                'payment_id' => $paymentId,
                'operation' => $capture ? 'void' : 'reversal',
                'http_status' => $response['httpStatus'] ?? null,
                'status' => $response['data']['status'] ?? null,
            ]);
        } catch (\Throwable $exception) {
            Log::channel('payments')->critical('CyberSource payment could not be released — needs manual void/reversal', [
                'top_up_uuid' => $topUp->uuid,
                'payment_id' => $paymentId,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * Same decision as the SDK's confirmUcResult(), from GET /tss/v2/transactions/{id} alone
     * (used by the poller and the webhook, when there is no browser result).
     */
    protected function confirmationFromTransaction(VbCyberSource $vb, PaymentTopUp $topUp, string $paymentId, array $tx): array
    {
        $amounts = $tx['orderInformation']['amountDetails'] ?? [];
        $normalize = static function (mixed $value): ?string {
            try {
                return Requests::amount(is_numeric($value) && ! is_string($value) ? (string) $value : $value, true);
            } catch (\Throwable) {
                return null;
            }
        };

        $matched = ($tx['clientReferenceInformation']['code'] ?? null) === $topUp->provider_reference
            && $normalize($amounts['totalAmount'] ?? $amounts['authorizedAmount'] ?? null) === number_format((float) $topUp->amount, 2, '.', '')
            && strtoupper((string) ($amounts['currency'] ?? '')) === strtoupper((string) $topUp->currency);

        $status = $this->transactionStatus($tx);

        if (in_array($status, ['REVERSED', 'VOIDED'], true)) {
            // Already released (by us, or by CyberSource's own timeout protection).
            return [
                'state' => $matched ? 'MATCHED' : 'MISMATCH',
                'id' => $paymentId,
                'status' => $status,
                'outcome' => ['next' => 'DECLINED', 'reason' => strtolower($status)],
                'evidence' => $vb->evidence($this->withPaddedEci($tx)),
                'mustReverse' => false,
                'mustRelease' => false,
                'paid' => false,
                'authorizedAmount' => $amounts['authorizedAmount'] ?? $amounts['totalAmount'] ?? null,
                'transaction' => $tx,
            ];
        }

        $outcome = Requests::interpretPayment(201, ['status' => $status, 'errorInformation' => $tx['errorInformation'] ?? null]);
        $evidence = $vb->evidence($this->withPaddedEci($tx));
        $mustReverse = in_array($outcome['next'], ['APPROVED', 'REVIEW'], true) && ! $evidence['full3ds'];

        return [
            'state' => $matched ? 'MATCHED' : 'MISMATCH',
            'id' => $paymentId,
            'status' => $status,
            'outcome' => $outcome,
            'evidence' => $evidence,
            'mustReverse' => $mustReverse,
            'mustRelease' => $mustReverse || $outcome['next'] === 'PARTIAL' || ($outcome['riskDeclined'] ?? false) === true,
            'paid' => $matched && $outcome['next'] === 'APPROVED' && ! $mustReverse,
            'authorizedAmount' => $amounts['authorizedAmount'] ?? $amounts['totalAmount'] ?? null,
            'transaction' => $tx,
        ];
    }

    /**
     * Payment status of a GET /tss/v2/transactions/{id} answer. The real API has no
     * applicationInformation.status; the result is in applicationInformation.applications
     * (ics_auth rCode "1" = authorized). Seen on apitest.cybersource.com, 2026-11-19.
     */
    protected function transactionStatus(array $tx): ?string
    {
        $info = (array) ($tx['applicationInformation'] ?? []);
        if (is_string($info['status'] ?? null) && $info['status'] !== '') {
            return $info['status'];
        }

        $apps = collect($info['applications'] ?? [])->filter(fn ($app): bool => is_array($app))->keyBy(fn (array $app): string => (string) ($app['name'] ?? ''));
        $ok = fn (string $name): bool => (string) ($apps[$name]['rCode'] ?? '') === '1';
        $failed = fn (string $name): bool => isset($apps[$name]) && (string) ($apps[$name]['rCode'] ?? '') === '0';
        $reason = (string) ($info['reasonCode'] ?? ($apps['ics_auth']['reasonCode'] ?? ''));

        if ($ok('ics_void')) {
            return 'VOIDED';
        }
        if ($ok('ics_auth_reversal')) {
            return 'REVERSED';
        }
        if ($ok('ics_auth')) {
            // 480 = accepted but flagged for review by Decision Manager.
            return $reason === '480' ? 'AUTHORIZED_PENDING_REVIEW' : 'AUTHORIZED';
        }
        if ($failed('ics_pa_enroll') || $failed('ics_pa_validate')) {
            return 'AUTHENTICATION_FAILED';
        }
        if ($reason === '475') {
            return 'PENDING_AUTHENTICATION';
        }
        if ($failed('ics_auth')) {
            return 'DECLINED';
        }

        return null;
    }

    /** ECI comes back as "5" / "2" from the real API; the bank's rule table expects "05" / "02". */
    protected function withPaddedEci(array $tx): array
    {
        foreach (['eciRaw', 'eci'] as $key) {
            $value = $tx['consumerAuthenticationInformation'][$key] ?? null;
            if ((is_string($value) || is_int($value)) && preg_match('/^\d$/', (string) $value) === 1) {
                $tx['consumerAuthenticationInformation'][$key] = '0'.$value;
            }
        }

        return $tx;
    }

    /** First authorization (ics_auth) carrying our reference, from POST /tss/v2/searches. */
    protected function findPaymentId(VbCyberSource $vb, string $reference): ?string
    {
        if ($reference === '') {
            return null;
        }

        $found = $vb->searchByReference($reference);

        $auth = collect($found['transactions'] ?? [])->first(function (array $summary) use ($reference): bool {
            $apps = collect($summary['applicationInformation']['applications'] ?? []);

            return ($summary['clientReferenceInformation']['code'] ?? null) === $reference
                && ($apps->isEmpty() || $apps->contains(fn (array $app): bool => ($app['name'] ?? null) === 'ics_auth'));
        });

        return is_array($auth) && filled($auth['id'] ?? null) ? (string) $auth['id'] : null;
    }

    protected function topUpForWebhook(string $paymentId, string $reference): ?PaymentTopUp
    {
        $query = PaymentTopUp::query()->where('provider', PaymentTopUp::PROVIDER_CYBERSOURCE);

        if ($reference !== '') {
            $topUp = (clone $query)->where('provider_reference', $reference)->first();
            if ($topUp !== null) {
                return $topUp;
            }
        }

        if ($paymentId !== '') {
            $topUp = (clone $query)->where('provider_checkout_id', $paymentId)->first();
            if ($topUp !== null) {
                return $topUp;
            }

            // Encrypted UC events may carry only the id: read the reference from the transaction.
            try {
                $tx = $this->sdk->make('MDL')->getTransaction($paymentId);
                $code = $tx['data']['clientReferenceInformation']['code'] ?? null;

                if (is_string($code) && $code !== '') {
                    return (clone $query)->where('provider_reference', $code)->first();
                }
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    protected function decodeWebhook(string $rawBody): array
    {
        $path = (string) config('services.cybersource.webhook_mle_private_key_path', '');
        $json = json_decode($rawBody, true);

        if ($path === '' || ! is_readable($path)) {
            if (is_array($json) && is_array($json['payload'] ?? null)) {
                return $json;
            }

            throw new \RuntimeException('Encrypted webhook but no MLE private key configured');
        }

        return $this->vb('MDL')->decryptWebhook(
            $rawBody,
            (string) file_get_contents($path),
            config('services.cybersource.webhook_mle_private_key_passphrase'),
        );
    }

    protected function markConfirming(PaymentTopUp $topUp, string $reason, ?string $paymentId = null): void
    {
        $topUp->forceFill(['provider_status' => 'confirming:'.$reason])->save();

        if ($paymentId !== null && $paymentId !== '' && blank($topUp->provider_checkout_id) && ! $this->paymentIdTaken($paymentId, $topUp)) {
            $topUp->forceFill(['provider_checkout_id' => $paymentId])->save();
        }
    }

    protected function paymentIdTaken(string $paymentId, PaymentTopUp $topUp): bool
    {
        return PaymentTopUp::query()
            ->where('provider_checkout_id', $paymentId)
            ->whereKeyNot($topUp->id)
            ->exists();
    }

    protected function details(array $c): array
    {
        return [
            'payment_id' => $c['id'] ?? null,
            'status' => $c['status'] ?? null,
            'next' => $c['outcome']['next'] ?? null,
            'reason' => $c['outcome']['reason'] ?? null,
            'eci' => $c['evidence']['eci'] ?? null,
            'brand' => $c['evidence']['brand'] ?? null,
            'full_3ds' => $c['evidence']['full3ds'] ?? null,
            'authorized_amount' => $c['authorizedAmount'] ?? null,
            'payment_solution' => $c['transaction']['processingInformation']['paymentSolution'] ?? null,
        ];
    }

    protected function vb(?string $currency): VbCyberSource
    {
        return $this->sdk->make((string) ($currency ?: Wallet::DEFAULT_CURRENCY));
    }

    /**
     * targetOrigins comes from config (§5.4). The request's Origin only picks one of the
     * configured values; it is never trusted on its own.
     */
    protected function targetOrigin(Request $request): string
    {
        $allowed = (array) config('services.cybersource.target_origins', []);
        $origin = rtrim((string) $request->headers->get('Origin', ''), '/');

        return in_array($origin, $allowed, true) ? $origin : (string) ($allowed[0] ?? '');
    }

    protected function billTo(User $user, ?array $address): array
    {
        $name = trim((string) ($address['full_name'] ?? $user->name ?? ''));
        $parts = preg_split('/\s+/', $name, 2) ?: [];

        return array_filter([
            'firstName' => $parts[0] ?? null,
            'lastName' => $parts[1] ?? ($parts[0] ?? null),
            'address1' => $address['address_line1'] ?? null,
            'address2' => $address['address_line2'] ?? null,
            'locality' => $address['city'] ?? null,
            'administrativeArea' => $address['administrative_area'] ?? null,
            'postalCode' => $address['postal_code'] ?? null,
            'country' => isset($address['country_code']) ? strtoupper((string) $address['country_code']) : null,
            'email' => $user->email,
        ], fn ($value): bool => is_string($value) && trim($value) !== '');
    }

    protected function locale(string $locale): string
    {
        return match (strtolower(substr($locale, 0, 2))) {
            'en' => 'en_US',
            'ru' => 'ru_RU',
            default => 'ro_RO',
        };
    }
}
