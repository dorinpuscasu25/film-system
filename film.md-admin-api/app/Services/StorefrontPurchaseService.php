<?php

namespace App\Services;

use Carbon\CarbonInterface;
use App\Models\Content;
use App\Models\ContentEntitlement;
use App\Models\Offer;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StorefrontPurchaseService
{
    public function __construct(
        protected WalletService $wallets,
    ) {
    }

    /**
     * @return array{wallet: Wallet, transaction: WalletTransaction|null, entitlement: ContentEntitlement, already_owned: bool}
     */
    public function purchase(User $user, Offer $offer): array
    {
        $offer->loadMissing('content');
        $content = $offer->content;

        if ($content === null || $content->status !== Content::STATUS_PUBLISHED) {
            throw ValidationException::withMessages([
                'offer' => ['This title is not available for purchase.'],
            ]);
        }

        if (! $offer->isCurrentlyAvailable()) {
            throw ValidationException::withMessages([
                'offer' => ['This offer is not active right now.'],
            ]);
        }

        $this->wallets->ensureWallet($user);

        return DB::transaction(function () use ($user, $offer, $content): array {
            $wallet = $this->wallets->lockWallet($user);
            $lockedOffer = Offer::query()
                ->with('content')
                ->lockForUpdate()
                ->findOrFail($offer->id);
            $now = now();

            $existingEntitlement = ContentEntitlement::query()
                ->where('user_id', $user->id)
                ->where('content_id', $content->id)
                ->where('offer_id', $lockedOffer->id)
                ->active($now)
                ->latest('granted_at')
                ->first();

            if ($existingEntitlement !== null) {
                return [
                    'wallet' => $wallet->fresh(),
                    'transaction' => null,
                    'entitlement' => $existingEntitlement->loadMissing('content.taxonomies', 'offer'),
                    'already_owned' => true,
                ];
            }

            $transaction = null;
            $priceAmount = round((float) $lockedOffer->price_amount, 2);

            if ($priceAmount > 0) {
                $transaction = $this->wallets->debit(
                    $wallet,
                    $priceAmount,
                    WalletTransaction::TYPE_PURCHASE,
                    sprintf('Purchase access for %s', $content->original_title),
                    [
                        'content_id' => $content->id,
                        'content_slug' => $content->slug,
                        'offer_id' => $lockedOffer->id,
                        'quality' => $lockedOffer->quality,
                        'offer_type' => $lockedOffer->offer_type,
                    ],
                    $lockedOffer,
                );
            } else {
                $transaction = $this->wallets->recordTransaction(
                    $wallet,
                    WalletTransaction::TYPE_PURCHASE,
                    0,
                    sprintf('Claim free access for %s', $content->original_title),
                    [
                        'content_id' => $content->id,
                        'content_slug' => $content->slug,
                        'offer_id' => $lockedOffer->id,
                        'quality' => $lockedOffer->quality,
                        'offer_type' => $lockedOffer->offer_type,
                        'is_free_claim' => true,
                    ],
                    $lockedOffer,
                );
            }

            $entitlement = ContentEntitlement::query()->create([
                'user_id' => $user->id,
                'content_id' => $content->id,
                'offer_id' => $lockedOffer->id,
                'access_type' => $lockedOffer->offer_type,
                'quality' => $lockedOffer->quality,
                'status' => ContentEntitlement::STATUS_ACTIVE,
                'currency' => $lockedOffer->currency ?: Wallet::DEFAULT_CURRENCY,
                'price_amount' => $priceAmount,
                'granted_at' => $now,
                'starts_at' => $now,
                'expires_at' => $this->resolveExpiry($lockedOffer, $now),
                'meta' => [
                    'content_slug' => $content->slug,
                    'offer_name' => $lockedOffer->name,
                    'playback_url' => $lockedOffer->playback_url,
                ],
            ]);

            return [
                'wallet' => $wallet->fresh(),
                'transaction' => $transaction?->fresh(),
                'entitlement' => $entitlement->loadMissing('content.taxonomies', 'offer'),
                'already_owned' => false,
            ];
        });
    }

    public function resolveActiveEntitlement(User $user, Content $content): ?ContentEntitlement
    {
        return ContentEntitlement::query()
            ->where('user_id', $user->id)
            ->where('content_id', $content->id)
            ->with('offer')
            ->active()
            ->orderByRaw("case when access_type = 'lifetime' then 0 else 1 end")
            ->orderByDesc('expires_at')
            ->orderByDesc('granted_at')
            ->first();
    }

    protected function resolveExpiry(Offer $offer, CarbonInterface $now): ?CarbonInterface
    {
        if ($offer->offer_type === Offer::TYPE_LIFETIME) {
            return null;
        }

        if ($offer->offer_type === Offer::TYPE_FREE && empty($offer->rental_days)) {
            return null;
        }

        $rentalDays = max((int) ($offer->rental_days ?? 0), 0);

        return $rentalDays > 0
            ? $now->copy()->addDays($rentalDays)
            : null;
    }
}
