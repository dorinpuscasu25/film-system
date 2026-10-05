<?php

namespace App\Http\Controllers\Api;

use App\Services\AppleIapService;
use App\Services\IapCreditPackService;
use App\Services\WalletService;
use AppStoreServerLibrary\SignedDataVerifier\VerificationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class StorefrontAppleIapController extends ApiController
{
    public function __construct(
        protected AppleIapService $appleIap,
        protected WalletService $wallets,
        protected IapCreditPackService $creditPacks,
    ) {}

    /**
     * The StoreKit product IDs the app should offer and how many credits each grants. The app
     * asks StoreKit for these IDs (Apple owns the displayed price) and shows the credit amount
     * from here, so the admin can change the catalog without shipping a new app build.
     */
    public function packs(): JsonResponse
    {
        return response()->json([
            'packs' => collect($this->creditPacks->packs())
                ->map(fn (array $pack): array => [
                    'product_id' => $pack['product_id'],
                    'credits_mdl' => $pack['credits_mdl'],
                    'sort_order' => $pack['sort_order'],
                ])
                ->values(),
        ]);
    }

    /**
     * Redeems a StoreKit 2 signed transaction (the `jwsRepresentation` of a `Transaction`) and
     * credits the wallet. Called by the iOS app right after `Transaction.updates`/a successful
     * `Product.purchase()`; the app only calls `transaction.finish()` once this returns 200.
     */
    public function redeem(Request $request): JsonResponse
    {
        $data = $request->validate([
            'signed_transaction' => ['required', 'string'],
        ]);

        $user = $request->user();

        try {
            $record = $this->appleIap->redeem($user, $data['signed_transaction']);
        } catch (VerificationException $exception) {
            Log::channel('payments')->warning('Apple IAP signed transaction failed verification', [
                'user_id' => $user->id, 'status' => $exception->getStatus()->name,
            ]);

            return response()->json([
                'message' => 'Chitanța Apple nu a putut fi verificată.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $wallet = $this->wallets->ensureWallet($user);

        return response()->json([
            'status' => $record->status,
            'credits_granted' => $record->credits_granted,
            'wallet' => [
                'id' => $wallet->id,
                'balance_amount' => $wallet->balance_amount,
                'currency' => $wallet->currency,
            ],
        ], Response::HTTP_CREATED);
    }
}
