<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\Content;
use App\Models\ContentEntitlement;
use App\Models\Invitation;
use App\Models\Offer;
use App\Models\Role;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class DashboardController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $range = $this->resolveRange((string) $request->query('range', '30days'));
        $rangeStart = now()->startOfDay()->subDays($range['days'] - 1);
        $rangeEnd = now()->endOfDay();

        $periodPurchaseTransactions = WalletTransaction::query()
            ->where('type', WalletTransaction::TYPE_PURCHASE)
            ->whereBetween('processed_at', [$rangeStart, $rangeEnd])
            ->orderBy('processed_at')
            ->get();

        $allTimePaidRevenue = abs((float) WalletTransaction::query()
            ->where('type', WalletTransaction::TYPE_PURCHASE)
            ->where('amount', '<', 0)
            ->sum('amount'));

        $allTimeOrders = WalletTransaction::query()
            ->where('type', WalletTransaction::TYPE_PURCHASE)
            ->count();

        $paidPeriodTransactions = $periodPurchaseTransactions
            ->filter(fn (WalletTransaction $transaction): bool => (float) $transaction->amount < 0)
            ->values();

        $freeClaimTransactions = $periodPurchaseTransactions
            ->filter(function (WalletTransaction $transaction): bool {
                return (float) $transaction->amount === 0.0
                    || (bool) data_get($transaction->meta ?? [], 'is_free_claim', false);
            })
            ->values();

        $periodEntitlements = ContentEntitlement::query()
            ->with('content')
            ->whereBetween('granted_at', [$rangeStart, $rangeEnd])
            ->orderByDesc('granted_at')
            ->get();

        $recentTransactions = WalletTransaction::query()
            ->with('user')
            ->latest('processed_at')
            ->latest('id')
            ->limit(50)
            ->get();

        [$contentsBySlug, $offersById] = $this->resolveTransactionContext($recentTransactions);

        return response()->json([
            'range' => [
                'value' => $range['value'],
                'label' => $range['label'],
                'days' => $range['days'],
                'from' => $rangeStart->toDateString(),
                'to' => $rangeEnd->toDateString(),
            ],
            'stats' => [
                'users_total' => User::query()->count(),
                'admins_total' => User::query()
                    ->whereHas('roles', fn ($query) => $query->where('admin_panel_access', true))
                    ->count(),
                'roles_total' => Role::query()->count(),
                'pending_invitations' => Invitation::query()
                    ->where('status', 'pending')
                    ->where(function ($query): void {
                        $query->whereNull('expires_at')
                            ->orWhere('expires_at', '>', now());
                    })
                    ->count(),
                'total_revenue_amount' => round($allTimePaidRevenue, 2),
                'period_revenue_amount' => round(abs((float) $paidPeriodTransactions->sum('amount')), 2),
                'orders_total' => $allTimeOrders,
                'period_orders_count' => $periodPurchaseTransactions->count(),
                'paid_orders_count' => $paidPeriodTransactions->count(),
                'free_claims_count' => $freeClaimTransactions->count(),
                'unique_buyers_count' => $paidPeriodTransactions->pluck('user_id')->unique()->count(),
                'average_order_value' => $paidPeriodTransactions->count() > 0
                    ? round(abs((float) $paidPeriodTransactions->sum('amount')) / $paidPeriodTransactions->count(), 2)
                    : 0,
                'active_entitlements_count' => ContentEntitlement::query()->active()->count(),
                'wallet_balance_total' => round((float) Wallet::query()->sum('balance_amount'), 2),
            ],
            'breakdown' => [
                'rental_orders_count' => $periodPurchaseTransactions
                    ->filter(fn (WalletTransaction $transaction): bool => data_get($transaction->meta ?? [], 'offer_type') === Offer::TYPE_RENTAL)
                    ->count(),
                'lifetime_orders_count' => $periodPurchaseTransactions
                    ->filter(fn (WalletTransaction $transaction): bool => data_get($transaction->meta ?? [], 'offer_type') === Offer::TYPE_LIFETIME)
                    ->count(),
                'free_orders_count' => $periodPurchaseTransactions
                    ->filter(fn (WalletTransaction $transaction): bool => data_get($transaction->meta ?? [], 'offer_type') === Offer::TYPE_FREE || (float) $transaction->amount === 0.0)
                    ->count(),
                'rental_revenue_amount' => round(abs((float) $periodPurchaseTransactions
                    ->filter(fn (WalletTransaction $transaction): bool => data_get($transaction->meta ?? [], 'offer_type') === Offer::TYPE_RENTAL)
                    ->sum('amount')), 2),
                'lifetime_revenue_amount' => round(abs((float) $periodPurchaseTransactions
                    ->filter(fn (WalletTransaction $transaction): bool => data_get($transaction->meta ?? [], 'offer_type') === Offer::TYPE_LIFETIME)
                    ->sum('amount')), 2),
            ],
            'sales_timeline' => $this->buildSalesTimeline($periodPurchaseTransactions, $range['days']),
            'recent_transactions' => $recentTransactions
                ->map(fn (WalletTransaction $transaction): array => $this->transactionData($transaction, $contentsBySlug, $offersById))
                ->values(),
            'top_titles' => $this->topTitlesData($periodEntitlements),
            'recent_sales' => $recentTransactions
                ->filter(fn (WalletTransaction $transaction): bool => $transaction->type === WalletTransaction::TYPE_PURCHASE)
                ->take(10)
                ->map(fn (WalletTransaction $transaction): array => $this->transactionData($transaction, $contentsBySlug, $offersById))
                ->values(),
            'summary' => [
                'catalog_titles_total' => Content::query()->count(),
                'published_titles_total' => Content::query()->where('status', Content::STATUS_PUBLISHED)->count(),
                'buyers_total' => WalletTransaction::query()
                    ->where('type', WalletTransaction::TYPE_PURCHASE)
                    ->where('amount', '<', 0)
                    ->distinct('user_id')
                    ->count('user_id'),
            ],
        ]);
    }

    /**
     * @return array{value: string, label: string, days: int}
     */
    protected function resolveRange(string $value): array
    {
        return match ($value) {
            '7days' => ['value' => '7days', 'label' => 'Last 7 days', 'days' => 7],
            '3months' => ['value' => '3months', 'label' => 'Last 90 days', 'days' => 90],
            default => ['value' => '30days', 'label' => 'Last 30 days', 'days' => 30],
        };
    }

    /**
     * @return array{0: \Illuminate\Support\Collection<string, Content>, 1: \Illuminate\Support\Collection<int, Offer>}
     */
    protected function resolveTransactionContext(Collection $transactions): array
    {
        $contentSlugs = $transactions
            ->map(fn (WalletTransaction $transaction): ?string => data_get($transaction->meta ?? [], 'content_slug'))
            ->filter()
            ->unique()
            ->values();

        $offerIds = $transactions
            ->map(fn (WalletTransaction $transaction): ?int => data_get($transaction->meta ?? [], 'offer_id'))
            ->filter(fn (?int $offerId): bool => $offerId !== null)
            ->unique()
            ->values();

        $contentsBySlug = Content::query()
            ->whereIn('slug', $contentSlugs)
            ->get()
            ->keyBy('slug');

        $offersById = Offer::query()
            ->whereIn('id', $offerIds)
            ->get()
            ->keyBy('id');

        return [$contentsBySlug, $offersById];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function buildSalesTimeline(Collection $transactions, int $days): array
    {
        $grouped = $transactions
            ->groupBy(fn (WalletTransaction $transaction): string => $transaction->processed_at?->toDateString() ?? now()->toDateString());

        return collect(range($days - 1, 0))
            ->map(function (int $offset) use ($grouped): array {
                $date = now()->startOfDay()->subDays($offset);
                $bucket = $grouped->get($date->toDateString(), collect());

                return [
                    'date' => $date->toDateString(),
                    'label' => $date->format('M j'),
                    'revenue_amount' => round(abs((float) $bucket
                        ->filter(fn (WalletTransaction $transaction): bool => (float) $transaction->amount < 0)
                        ->sum('amount')), 2),
                    'orders_count' => $bucket->count(),
                    'free_claims_count' => $bucket
                        ->filter(fn (WalletTransaction $transaction): bool => (float) $transaction->amount === 0.0 || (bool) data_get($transaction->meta ?? [], 'is_free_claim', false))
                        ->count(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function topTitlesData(Collection $entitlements): array
    {
        return $entitlements
            ->groupBy('content_id')
            ->map(function (Collection $group): array {
                /** @var \App\Models\ContentEntitlement $sample */
                $sample = $group->first();
                $content = $sample->content;

                return [
                    'content_id' => $content?->id,
                    'slug' => $content?->slug,
                    'title' => $content ? $this->resolveContentTitle($content) : 'Unknown title',
                    'type' => $content?->type,
                    'poster_url' => $content?->poster_url,
                    'orders_count' => $group->count(),
                    'paid_orders_count' => $group->filter(fn (ContentEntitlement $entitlement): bool => (float) $entitlement->price_amount > 0)->count(),
                    'free_claims_count' => $group->filter(fn (ContentEntitlement $entitlement): bool => (float) $entitlement->price_amount === 0.0)->count(),
                    'unique_buyers_count' => $group->pluck('user_id')->unique()->count(),
                    'revenue_amount' => round((float) $group->sum('price_amount'), 2),
                ];
            })
            ->sort(function (array $left, array $right): int {
                $revenueComparison = ($right['revenue_amount'] <=> $left['revenue_amount']);
                if ($revenueComparison !== 0) {
                    return $revenueComparison;
                }

                return ($right['orders_count'] <=> $left['orders_count']);
            })
            ->take(5)
            ->values()
            ->all();
    }

    /**
     * @param  \Illuminate\Support\Collection<string, Content>  $contentsBySlug
     * @param  \Illuminate\Support\Collection<int, Offer>  $offersById
     * @return array<string, mixed>
     */
    protected function transactionData(WalletTransaction $transaction, Collection $contentsBySlug, Collection $offersById): array
    {
        $contentSlug = data_get($transaction->meta ?? [], 'content_slug');
        $offerId = data_get($transaction->meta ?? [], 'offer_id');

        /** @var \App\Models\Content|null $content */
        $content = is_string($contentSlug) ? $contentsBySlug->get($contentSlug) : null;
        /** @var \App\Models\Offer|null $offer */
        $offer = is_numeric($offerId) ? $offersById->get((int) $offerId) : null;

        return [
            'id' => $transaction->id,
            'type' => $transaction->type,
            'type_label' => match ($transaction->type) {
                WalletTransaction::TYPE_PURCHASE => (float) $transaction->amount === 0.0 ? 'Free claim' : 'Purchase',
                WalletTransaction::TYPE_REFUND => 'Refund',
                WalletTransaction::TYPE_TOP_UP => 'Top up',
                WalletTransaction::TYPE_WELCOME_BONUS => 'Welcome credit',
                default => 'Adjustment',
            },
            'amount' => round((float) $transaction->amount, 2),
            'amount_absolute' => round(abs((float) $transaction->amount), 2),
            'balance_after' => round((float) $transaction->balance_after, 2),
            'currency' => $transaction->currency,
            'description' => $transaction->description,
            'processed_at' => $transaction->processed_at?->toIso8601String(),
            'user' => [
                'id' => $transaction->user?->id,
                'name' => $transaction->user?->name,
                'email' => $transaction->user?->email,
            ],
            'content' => $content ? [
                'id' => $content->id,
                'slug' => $content->slug,
                'title' => $this->resolveContentTitle($content),
                'type' => $content->type,
                'poster_url' => $content->poster_url,
            ] : null,
            'offer' => [
                'id' => $offer?->id ?? $offerId,
                'name' => $offer?->name ?? data_get($transaction->meta ?? [], 'offer_name'),
                'quality' => $offer?->quality ?? data_get($transaction->meta ?? [], 'quality'),
                'offer_type' => $offer?->offer_type ?? data_get($transaction->meta ?? [], 'offer_type'),
            ],
        ];
    }

    protected function resolveContentTitle(Content $content): string
    {
        return $content->getTranslation('title', $content->default_locale, false)
            ?? $content->getTranslation('title', 'ro', false)
            ?? $content->original_title;
    }
}
