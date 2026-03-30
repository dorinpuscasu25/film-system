<?php

namespace App\Http\Controllers\Api;

use App\Services\AccountProfileService;
use App\Models\Content;
use App\Models\ContentEntitlement;
use App\Models\Offer;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\StorefrontPurchaseService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

class StorefrontController extends ApiController
{
    public function __construct(
        protected AccountProfileService $profiles,
        protected WalletService $wallets,
        protected StorefrontPurchaseService $purchases,
    ) {
    }

    public function account(Request $request): JsonResponse
    {
        $user = $request->user();
        $locale = $this->resolveRequestedLocale($request, $user);
        $wallet = $this->wallets->ensureWallet($user);
        $this->profiles->ensureDefaultProfile($user);
        $transactions = $wallet->transactions()->latest('id')->limit(100)->get();
        $library = $this->resolveLibraryItems($user, $locale);
        $user->loadMissing('roles.permissions', 'wallet', 'profiles.favorites');

        return response()->json([
            'user' => $this->userData($user),
            'wallet' => $this->walletSummaryData($wallet->fresh()),
            'transactions' => $transactions
                ->map(fn (WalletTransaction $transaction): array => $this->walletTransactionData($transaction))
                ->values(),
            'library' => $library,
            'favorites_by_profile' => $this->favoriteMapData($user->profiles),
        ]);
    }

    public function purchase(Request $request, Offer $offer): JsonResponse
    {
        $user = $request->user();
        $locale = $this->resolveRequestedLocale($request, $user);
        $purchase = $this->purchases->purchase($user, $offer);
        $libraryItem = $this->libraryItemData($purchase['entitlement'], $locale);

        return response()->json([
            'message' => $purchase['already_owned']
                ? 'You already have active access for this offer.'
                : 'Purchase completed successfully.',
            'already_owned' => $purchase['already_owned'],
            'wallet' => $this->walletSummaryData($purchase['wallet']),
            'transaction' => $purchase['transaction']
                ? $this->walletTransactionData($purchase['transaction'])
                : null,
            'library_item' => $libraryItem,
        ]);
    }

    public function playback(Request $request, string $identifier): JsonResponse
    {
        $user = $request->user();
        $locale = $this->resolveRequestedLocale($request, $user);
        $content = Content::query()
            ->published()
            ->with('offers', 'taxonomies')
            ->where(function ($builder) use ($identifier): void {
                $builder->where('slug', $identifier);

                if (ctype_digit($identifier)) {
                    $builder->orWhere('id', (int) $identifier);
                }
            })
            ->first();

        if ($content === null) {
            return response()->json([
                'message' => 'The requested content was not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $entitlement = $this->purchases->resolveActiveEntitlement($user, $content);
        $hasFreeAccess = (bool) $content->is_free
            || $content->offers->contains(fn (Offer $offer): bool => $offer->offer_type === Offer::TYPE_FREE && $offer->isCurrentlyAvailable());

        if ($entitlement === null && ! $hasFreeAccess) {
            return response()->json([
                'message' => 'You do not have active access to watch this title.',
            ], Response::HTTP_FORBIDDEN);
        }

        $episodeId = $request->query('episode_id');
        $playback = $this->resolvePlaybackPayload($content, $entitlement, is_string($episodeId) ? $episodeId : null, $locale);

        if ($playback['url'] === null) {
            return response()->json([
                'message' => 'Playback is not configured for this title yet.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return response()->json([
            'content' => [
                'id' => $content->id,
                'slug' => $content->slug,
                'title' => $content->getTranslation('title', $locale, false)
                    ?? $content->getTranslation('title', $content->default_locale, false)
                    ?? $content->original_title,
                'type' => $content->type,
                'poster_url' => $content->poster_url,
                'backdrop_url' => $content->backdrop_url,
            ],
            'episode' => $playback['episode'],
            'playback' => [
                'url' => $playback['url'],
                'quality' => $playback['quality'],
                'offer_type' => $entitlement?->access_type ?? ($hasFreeAccess ? Offer::TYPE_FREE : null),
                'expires_at' => $entitlement?->expires_at?->toIso8601String(),
                'is_lifetime' => $entitlement?->expires_at === null,
            ],
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function resolveLibraryItems(User $user, string $locale): array
    {
        $entitlements = ContentEntitlement::query()
            ->where('user_id', $user->id)
            ->with('content.taxonomies', 'offer')
            ->orderByDesc('granted_at')
            ->orderByDesc('id')
            ->get();

        return $entitlements
            ->groupBy('content_id')
            ->map(function (Collection $group) use ($locale): array {
                /** @var \App\Models\ContentEntitlement $entitlement */
                $entitlement = $group
                    ->sort(function (ContentEntitlement $left, ContentEntitlement $right): int {
                        $leftActiveScore = $left->isActive() ? 0 : 1;
                        $rightActiveScore = $right->isActive() ? 0 : 1;

                        if ($leftActiveScore !== $rightActiveScore) {
                            return $leftActiveScore <=> $rightActiveScore;
                        }

                        $leftLifetimeScore = $left->expires_at === null ? 0 : 1;
                        $rightLifetimeScore = $right->expires_at === null ? 0 : 1;

                        if ($leftLifetimeScore !== $rightLifetimeScore) {
                            return $leftLifetimeScore <=> $rightLifetimeScore;
                        }

                        return ($right->granted_at?->getTimestamp() ?? 0) <=> ($left->granted_at?->getTimestamp() ?? 0);
                    })
                    ->first();

                return $this->libraryItemData($entitlement, $locale);
            })
            ->sortByDesc(fn (array $item): string => (string) ($item['granted_at'] ?? ''))
            ->values()
            ->all();
    }

    /**
     * @return array{url: ?string, quality: ?string, episode: ?array<string, mixed>}
     */
    protected function resolvePlaybackPayload(
        Content $content,
        ?ContentEntitlement $entitlement,
        ?string $episodeId,
        string $locale,
    ): array {
        $defaultLocale = in_array($content->default_locale, Content::supportedLocales(), true)
            ? $content->default_locale
            : Content::supportedLocales()[0];
        $resolvedLocale = in_array($locale, Content::supportedLocales(), true)
            ? $locale
            : $defaultLocale;
        $sortedVideos = collect($content->videos ?? [])->sortBy('sort_order')->values();
        $primaryVideo = $sortedVideos->firstWhere('is_primary', true) ?? $sortedVideos->first();
        $primaryVideoUrl = data_get($primaryVideo, 'video_url') ?: $content->trailer_url;

        if ($content->type === Content::TYPE_SERIES) {
            $seasonRecords = collect($content->seasons ?? [])->sortBy('sort_order')->values();
            $episode = $seasonRecords
                ->flatMap(function (array $season): array {
                    $seasonNumber = (int) data_get($season, 'season_number', 1);

                    return collect(data_get($season, 'episodes', []))
                        ->sortBy('sort_order')
                        ->map(fn (array $episode): array => [
                            ...$episode,
                            'id' => (string) (data_get($episode, 'id') ?: "season-{$seasonNumber}-episode-".((int) data_get($episode, 'episode_number', 1))),
                            '_season_number' => $seasonNumber,
                        ])
                        ->values()
                        ->all();
                })
                ->first(function (array $episode) use ($episodeId): bool {
                    if ($episodeId === null) {
                        return true;
                    }

                    return (string) data_get($episode, 'id') === $episodeId;
                });

            if ($episode !== null) {
                $episodeTitle = data_get($episode, 'title');
                $episodeDescription = data_get($episode, 'description');

                return [
                    'url' => data_get($episode, 'video_url')
                        ?: data_get($episode, 'trailer_url')
                        ?: $entitlement?->offer?->playback_url
                        ?: $primaryVideoUrl,
                    'quality' => $entitlement?->quality,
                    'episode' => [
                        'id' => (string) data_get($episode, 'id'),
                        'season_number' => (int) data_get($episode, '_season_number', 1),
                        'episode_number' => (int) data_get($episode, 'episode_number', 1),
                        'title' => $this->localizedValue($episodeTitle, $resolvedLocale, $defaultLocale),
                        'description' => $this->localizedValue($episodeDescription, $resolvedLocale, $defaultLocale),
                        'runtime_minutes' => data_get($episode, 'runtime_minutes'),
                        'thumbnail_url' => data_get($episode, 'thumbnail_url'),
                        'backdrop_url' => data_get($episode, 'backdrop_url'),
                    ],
                ];
            }
        }

        return [
            'url' => $entitlement?->offer?->playback_url ?: $primaryVideoUrl,
            'quality' => $entitlement?->quality,
            'episode' => null,
        ];
    }

    protected function resolveRequestedLocale(Request $request, User $user): string
    {
        $locale = (string) $request->query('locale', $user->preferred_locale ?: Content::supportedLocales()[0]);

        return in_array($locale, Content::supportedLocales(), true)
            ? $locale
            : Content::supportedLocales()[0];
    }
}
