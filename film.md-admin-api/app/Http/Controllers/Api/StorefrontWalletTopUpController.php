<?php

namespace App\Http\Controllers\Api;

use App\Models\PaymentTopUp;
use App\Services\PayFilmotecaPaymentService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class StorefrontWalletTopUpController extends ApiController
{
    public function __construct(
        protected WalletService $wallets,
        protected PayFilmotecaPaymentService $payments,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $wallet = $this->wallets->ensureWallet($user);
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:20', 'max:20000'],
            'currency' => ['nullable', 'string', Rule::in(['MDL', 'EUR', 'USD'])],
            'phone' => ['nullable', 'string', 'max:32'],
            'locale' => ['nullable', 'string', Rule::in(['ro', 'en', 'ru'])],
        ]);

        $data['currency'] = strtoupper((string) ($data['currency'] ?? $wallet->currency));

        if ($data['currency'] !== $wallet->currency) {
            return response()->json([
                'message' => 'Top-up currency must match wallet currency.',
                'errors' => [
                    'currency' => ['Top-up currency must match wallet currency.'],
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $topUp = $this->payments->initiateTopUp($user, $wallet, $data, $request);

        return response()->json([
            'top_up' => $this->payments->topUpData($topUp),
        ], Response::HTTP_CREATED);
    }

    public function show(Request $request, PaymentTopUp $topUp): JsonResponse
    {
        if ((int) $topUp->user_id !== (int) $request->user()->id) {
            return response()->json(['message' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        $topUp = $this->payments->rememberProviderOrderId($topUp, $this->providerOrderIdFromRequest($request));

        if (! $topUp->isTerminal()) {
            $topUp = $this->payments->refreshStatus($topUp);
        }

        return response()->json([
            'top_up' => $this->payments->topUpData($topUp),
        ]);
    }

    public function latest(Request $request): JsonResponse
    {
        $topUp = PaymentTopUp::query()
            ->where('user_id', $request->user()->id)
            ->latest('id')
            ->first();

        if ($topUp === null) {
            return response()->json(['message' => 'No top-up found.'], Response::HTTP_NOT_FOUND);
        }

        $topUp = $this->payments->rememberProviderOrderId($topUp, $this->providerOrderIdFromRequest($request));

        if (! $topUp->isTerminal()) {
            $topUp = $this->payments->refreshStatus($topUp);
        }

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
}
