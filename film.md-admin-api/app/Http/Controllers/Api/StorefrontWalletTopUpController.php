<?php

namespace App\Http\Controllers\Api;

use App\Models\PaymentTopUp;
use App\Services\PayFilmotecaPaymentService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class StorefrontWalletTopUpController extends ApiController
{
    public function __construct(
        protected WalletService $wallets,
        protected PayFilmotecaPaymentService $payments,
    ) {}

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
                'amount' => ['required', 'numeric', 'min:20', 'max:20000'],
                'currency' => ['nullable', 'string', Rule::in(['MDL', 'EUR', 'USD'])],
                'phone' => ['nullable', 'string', 'max:32'],
                'locale' => ['nullable', 'string', Rule::in(['ro', 'en', 'ru'])],
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
            ]);

            throw $exception;
        }

        $data['currency'] = strtoupper((string) ($data['currency'] ?? $wallet->currency));

        Log::channel('payments')->info('PayFilmoteca wallet top-up request validated', [
            'user_id' => $user?->id,
            'wallet_id' => $wallet->id,
            'amount' => $data['amount'],
            'currency' => $data['currency'],
            'wallet_currency' => $wallet->currency,
            'locale' => $data['locale'] ?? null,
            'has_phone' => ! empty($data['phone'] ?? null),
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

        $topUp = $this->payments->initiateTopUp($user, $wallet, $data, $request);

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
            'top_up' => $this->payments->topUpData($topUp),
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
        ]);

        if ((int) $topUp->user_id !== (int) $request->user()->id) {
            Log::channel('payments')->warning('PayFilmoteca wallet top-up show forbidden', [
                'top_up_uuid' => $topUp->uuid,
                'top_up_user_id' => $topUp->user_id,
                'request_user_id' => $request->user()?->id,
            ]);

            return response()->json(['message' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        $topUp = $this->payments->rememberProviderIdentifiers(
            $topUp,
            $this->providerCheckoutIdFromRequest($request),
            $this->providerOrderIdFromRequest($request),
            $this->providerRrnFromRequest($request),
        );

        if (! $topUp->isTerminal()) {
            $topUp = $this->payments->refreshStatus($topUp);
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
            'top_up' => $this->payments->topUpData($topUp),
        ]);
    }

    public function latest(Request $request): JsonResponse
    {
        Log::channel('payments')->info('PayFilmoteca latest wallet top-up endpoint reached', [
            'request_user_id' => $request->user()?->id,
            'query_order_id' => $this->providerOrderIdFromRequest($request),
            'query_checkout_id' => $this->providerCheckoutIdFromRequest($request),
        ]);

        $topUp = PaymentTopUp::query()
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->first();

        if ($topUp === null) {
            Log::channel('payments')->warning('PayFilmoteca latest wallet top-up not found', [
                'request_user_id' => $request->user()?->id,
                'query_order_id' => $this->providerOrderIdFromRequest($request),
                'query_checkout_id' => $this->providerCheckoutIdFromRequest($request),
            ]);

            return response()->json(['message' => 'No top-up found.'], Response::HTTP_NOT_FOUND);
        }

        $topUp = $this->payments->rememberProviderIdentifiers(
            $topUp,
            $this->providerCheckoutIdFromRequest($request),
            $this->providerOrderIdFromRequest($request),
            $this->providerRrnFromRequest($request),
        );

        if (! $topUp->isTerminal()) {
            $topUp = $this->payments->refreshStatus($topUp);
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
            'top_up' => $this->payments->topUpData($topUp),
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
}
