<?php

namespace App\Http\Controllers\Api;

use App\Models\ContentEntitlement;
use App\Models\PaymentTopUp;
use App\Services\BillingAddressService;
use App\Services\PayFilmotecaPaymentService;
use App\Services\Payments\CyberSource\CyberSourcePaymentService;
use App\Services\Payments\Mia\MiaPaymentService;
use App\Services\Payments\TopUpPaymentGateway;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;
use Symfony\Component\HttpFoundation\Response;

class StorefrontWalletTopUpController extends ApiController
{
    public function __construct(
        protected WalletService $wallets,
        protected PayFilmotecaPaymentService $payments,
        protected BillingAddressService $billingAddresses,
        protected TopUpPaymentGateway $gateway,
        protected MiaPaymentService $mia,
        protected CyberSourcePaymentService $cyberSource,
    ) {}

    /** Which top-up methods the web should offer right now. */
    public function methods(): JsonResponse
    {
        $methods = $this->gateway->availableMethods();

        return response()->json([
            'methods' => collect($methods)
                ->map(fn (bool $enabled, string $method): array => [
                    'id' => $method,
                    'enabled' => $enabled,
                    'provider' => $method === PaymentTopUp::METHOD_CARD ? $this->gateway->cardProvider() : PaymentTopUp::PROVIDER_MIA,
                    'requires_phone' => $method === PaymentTopUp::METHOD_CARD && $this->gateway->cardRequiresPhone(),
                ])
                ->values(),
            'default' => collect($methods)->filter()->keys()->first(),
        ]);
    }

    /** Unified Checkout finished in the browser: the server confirms the signed result (R9). */
    public function cardResult(Request $request, PaymentTopUp $topUp): JsonResponse
    {
        if ((int) $topUp->user_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        $data = $request->validate([
            'result_jwt' => ['required', 'string', 'max:20000'],
        ]);

        $topUp = $this->cyberSource->submitResult($topUp, (string) $data['result_jwt']);

        return response()->json([
            'top_up' => $this->gateway->topUpData($topUp),
        ]);
    }

    /** The customer closed the MIA QR without paying: cancel it at the bank. */
    public function cancel(Request $request, PaymentTopUp $topUp): JsonResponse
    {
        if ((int) $topUp->user_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        if ($topUp->provider === PaymentTopUp::PROVIDER_MIA) {
            $topUp = $this->mia->cancelTopUp($topUp);
        }

        return response()->json([
            'top_up' => $this->gateway->topUpData($topUp),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $wallet = $this->wallets->ensureWallet($user);

        Log::channel('payments')->info('PayFilmoteca wallet top-up endpoint reached', [
            'user_id' => $user?->id,
            'wallet_id' => $wallet->id,
            'wallet_currency' => $wallet->currency,
            'request_amount' => $request->input('amount'),
            'request_currency' => $request->input('currency'),
            'client_ip' => $request->ip(),
            'cf_connecting_ip' => $request->headers->get('CF-Connecting-IP'),
            'x_real_ip' => $request->headers->get('X-Real-IP'),
            'x_forwarded_for' => $request->headers->get('X-Forwarded-For'),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);

        try {
            $data = $request->validate([
                'method' => ['nullable', 'string', Rule::in([PaymentTopUp::METHOD_CARD, PaymentTopUp::METHOD_MIA])],
                'amount' => ['required', 'numeric', 'min:20', 'max:20000'],
                'currency' => ['nullable', 'string', Rule::in(['MDL', 'EUR', 'USD'])],
                'account_profile_id' => ['nullable', 'integer'],
                // pay.filmoteca.md needs a phone for the card checkout; MIA and CyberSource do not.
                'phone' => [
                    $this->gateway->cardRequiresPhone() ? 'required_unless:method,'.PaymentTopUp::METHOD_MIA : 'nullable',
                    'nullable',
                    'string',
                    'max:32',
                    function (string $attribute, mixed $value, \Closure $fail): void {
                        if (! is_scalar($value) || $this->normalizePhoneNumber((string) $value) === null) {
                            $fail('Introduceți un număr de telefon valid.');
                        }
                    },
                ],
                'billing_address' => ['required', 'array'],
                ...BillingAddressService::rules('billing_address.'),
                'locale' => ['nullable', 'string', Rule::in(['ro', 'en', 'ru'])],
                // Place of supply (art. 111 Cod fiscal). The web always asks; other clients may not yet.
                'access_location' => ['nullable', 'string', Rule::in(ContentEntitlement::ACCESS_LOCATIONS)],
            ]);
        } catch (ValidationException $exception) {
            Log::channel('payments')->warning('PayFilmoteca wallet top-up request validation failed', [
                'user_id' => $user?->id,
                'wallet_id' => $wallet->id,
                'errors' => $exception->errors(),
                'request_amount' => $request->input('amount'),
                'request_currency' => $request->input('currency'),
                'request_locale' => $request->input('locale'),
                'has_phone' => filled($request->input('phone')),
                'has_billing_address' => filled($request->input('billing_address')),
            ]);

            throw $exception;
        }

        $profileId = (int) ($data['account_profile_id'] ?? 0);
        if ($profileId > 0) {
            $profile = $user->profiles()->whereKey($profileId)->first();

            if ($profile === null) {
                return response()->json([
                    'message' => 'The requested profile was not found.',
                ], Response::HTTP_NOT_FOUND);
            }

            if ($profile->is_kids) {
                return response()->json([
                    'message' => 'Suplinirea contului este disponibilă numai dintr-un profil pentru adulți.',
                ], Response::HTTP_FORBIDDEN);
            }
        }

        $data['currency'] = strtoupper((string) ($data['currency'] ?? $wallet->currency));
        $data['phone'] = filled($data['phone'] ?? null) ? $this->normalizePhoneNumber((string) $data['phone']) : null;
        $method = (string) ($data['method'] ?? PaymentTopUp::METHOD_CARD);

        if (! ($this->gateway->availableMethods()[$method] ?? false)) {
            return response()->json([
                'message' => $method === PaymentTopUp::METHOD_MIA
                    ? 'Plata prin MIA nu este disponibilă momentan.'
                    : 'Plata cu cardul este temporar indisponibilă. Folosește plata prin MIA.',
                'errors' => ['method' => ['Metoda de plată nu este disponibilă.']],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        Log::channel('payments')->info('PayFilmoteca wallet top-up request validated', [
            'user_id' => $user?->id,
            'wallet_id' => $wallet->id,
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'wallet_currency' => $wallet->currency,
            'locale' => $data['locale'] ?? null,
            'has_phone' => ! empty($data['phone'] ?? null),
            'billing_country_code' => strtoupper((string) ($data['billing_address']['country_code'] ?? '')),
        ]);

        if ($data['currency'] !== $wallet->currency) {
            Log::channel('payments')->warning('PayFilmoteca wallet top-up currency mismatch', [
                'user_id' => $user?->id,
                'wallet_id' => $wallet->id,
                'request_currency' => $data['currency'],
                'wallet_currency' => $wallet->currency,
            ]);

            return response()->json([
                'message' => 'Top-up currency must match wallet currency.',
                'errors' => [
                    'currency' => ['Top-up currency must match wallet currency.'],
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($data['phone'] !== null && $user->payment_phone !== $data['phone']) {
            $user->update(['payment_phone' => $data['phone']]);
        }

        $billingAddress = $this->billingAddresses->upsertDefault($user, $data['billing_address']);
        $data['billing_address_id'] = $billingAddress->id;
        $data['billing_address'] = $this->billingAddresses->snapshot($billingAddress);

        $topUp = match (true) {
            $method === PaymentTopUp::METHOD_MIA => $this->mia->initiateTopUp($user, $wallet, $data),
            $this->gateway->cardProvider() === PaymentTopUp::PROVIDER_CYBERSOURCE => $this->cyberSource->initiateTopUp($user, $wallet, $data, $request),
            default => $this->payments->initiateTopUp($user, $wallet, $data, $request),
        };

        Log::channel('payments')->info('PayFilmoteca wallet top-up endpoint returning response', [
            'top_up_uuid' => $topUp->uuid,
            'user_id' => $user?->id,
            'wallet_id' => $wallet->id,
            'status' => $topUp->status,
            'provider_order_id' => $topUp->provider_order_id,
            'has_payment_url' => ! empty($topUp->provider_payment_url),
            'payment_url' => $topUp->provider_payment_url,
        ]);

        return response()->json([
            'top_up' => $this->gateway->topUpData($topUp),
        ], Response::HTTP_CREATED);
    }

    public function show(Request $request, PaymentTopUp $topUp): JsonResponse
    {
        Log::channel('payments')->info('PayFilmoteca wallet top-up show endpoint reached', [
            'top_up_uuid' => $topUp->uuid,
            'top_up_user_id' => $topUp->user_id,
            'request_user_id' => $request->user()?->id,
            'status' => $topUp->status,
            'provider_order_id' => $topUp->provider_order_id,
            'provider_checkout_id' => $topUp->provider_checkout_id,
            'query_order_id' => $this->providerOrderIdFromRequest($request),
            'query_checkout_id' => $this->providerCheckoutIdFromRequest($request),
            'query_checkout_status' => $this->providerCheckoutStatusFromRequest($request),
        ]);

        if ((int) $topUp->user_id !== (int) $request->user()->id) {
            Log::channel('payments')->warning('PayFilmoteca wallet top-up show forbidden', [
                'top_up_uuid' => $topUp->uuid,
                'top_up_user_id' => $topUp->user_id,
                'request_user_id' => $request->user()?->id,
            ]);

            return response()->json(['message' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        if ($topUp->provider === PaymentTopUp::PROVIDER_PAY_FILMOTECA) {
            $topUp = $this->payments->rememberProviderIdentifiers(
                $topUp,
                $this->providerCheckoutIdFromRequest($request),
                $this->providerOrderIdFromRequest($request),
                $this->providerRrnFromRequest($request),
                $this->providerCheckoutStatusFromRequest($request),
            );
        }

        if (! $topUp->isTerminal()) {
            $topUp = $this->gateway->refreshStatus($topUp);
        }

        Log::channel('payments')->info('PayFilmoteca wallet top-up show endpoint returning response', [
            'top_up_uuid' => $topUp->uuid,
            'status' => $topUp->status,
            'provider_status' => $topUp->provider_status,
            'provider_order_id' => $topUp->provider_order_id,
            'provider_checkout_id' => $topUp->provider_checkout_id,
            'provider_rrn' => $topUp->provider_rrn,
            'credited_at' => $topUp->credited_at?->toIso8601String(),
        ]);

        return response()->json([
            'top_up' => $this->gateway->topUpData($topUp),
        ]);
    }

    public function latest(Request $request): JsonResponse
    {
        Log::channel('payments')->info('PayFilmoteca latest wallet top-up endpoint reached', [
            'request_user_id' => $request->user()?->id,
            'query_order_id' => $this->providerOrderIdFromRequest($request),
            'query_checkout_id' => $this->providerCheckoutIdFromRequest($request),
            'query_checkout_status' => $this->providerCheckoutStatusFromRequest($request),
        ]);

        $providerOrderId = $this->providerOrderIdFromRequest($request);
        $providerCheckoutId = $this->providerCheckoutIdFromRequest($request);

        $topUp = PaymentTopUp::query()
            ->where('user_id', $request->user()->id)
            ->when($providerOrderId !== null || $providerCheckoutId !== null, function ($query) use ($providerOrderId, $providerCheckoutId): void {
                $query->where(function ($nested) use ($providerOrderId, $providerCheckoutId): void {
                    if ($providerOrderId !== null) {
                        $nested->orWhere('provider_order_id', $providerOrderId);
                    }

                    if ($providerCheckoutId !== null) {
                        $nested->orWhere('provider_checkout_id', $providerCheckoutId);
                    }
                });
            })
            ->latest('id')
            ->first();

        if ($topUp === null && ($providerOrderId !== null || $providerCheckoutId !== null)) {
            $topUp = PaymentTopUp::query()
                ->where('user_id', $request->user()->id)
                ->latest('id')
                ->first();
        }

        if ($topUp === null) {
            Log::channel('payments')->warning('PayFilmoteca latest wallet top-up not found', [
                'request_user_id' => $request->user()?->id,
                'query_order_id' => $this->providerOrderIdFromRequest($request),
                'query_checkout_id' => $this->providerCheckoutIdFromRequest($request),
            ]);

            return response()->json(['message' => 'No top-up found.'], Response::HTTP_NOT_FOUND);
        }

        if ($topUp->provider === PaymentTopUp::PROVIDER_PAY_FILMOTECA) {
            $topUp = $this->payments->rememberProviderIdentifiers(
                $topUp,
                $this->providerCheckoutIdFromRequest($request),
                $this->providerOrderIdFromRequest($request),
                $this->providerRrnFromRequest($request),
                $this->providerCheckoutStatusFromRequest($request),
            );
        }

        if (! $topUp->isTerminal()) {
            $topUp = $this->gateway->refreshStatus($topUp);
        }

        Log::channel('payments')->info('PayFilmoteca latest wallet top-up endpoint returning response', [
            'top_up_uuid' => $topUp->uuid,
            'status' => $topUp->status,
            'provider_status' => $topUp->provider_status,
            'provider_order_id' => $topUp->provider_order_id,
            'provider_checkout_id' => $topUp->provider_checkout_id,
            'provider_rrn' => $topUp->provider_rrn,
            'credited_at' => $topUp->credited_at?->toIso8601String(),
        ]);

        return response()->json([
            'top_up' => $this->gateway->topUpData($topUp),
        ]);
    }

    protected function providerOrderIdFromRequest(Request $request): ?string
    {
        foreach (['order_id', 'orderId', 'OrderID'] as $key) {
            $value = $request->query($key);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    protected function normalizePhoneNumber(string $phone): ?string
    {
        $phone = trim($phone);

        if ($phone === '') {
            return null;
        }

        if (str_starts_with($phone, '00')) {
            $phone = '+'.substr($phone, 2);
        }

        if (preg_match('/^\+?\d{7,15}$/', $phone) !== 1) {
            return null;
        }

        try {
            $util = PhoneNumberUtil::getInstance();
            $number = $util->parse($phone, str_starts_with($phone, '+') ? null : 'MD');

            if (! $util->isValidNumber($number)) {
                return null;
            }

            return $util->format($number, PhoneNumberFormat::E164);
        } catch (NumberParseException) {
            return null;
        }
    }

    protected function providerCheckoutIdFromRequest(Request $request): ?string
    {
        foreach (['checkout_id', 'checkoutId', 'CheckoutID', 'checkoutID'] as $key) {
            $value = $request->query($key);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    protected function providerRrnFromRequest(Request $request): ?string
    {
        foreach (['rrn', 'RRN'] as $key) {
            $value = $request->query($key);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }

    protected function providerCheckoutStatusFromRequest(Request $request): ?string
    {
        foreach (['checkout_status', 'checkoutStatus', 'CheckoutStatus'] as $key) {
            $value = $request->query($key);
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return null;
    }
}
