<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\BillingAddress;
use App\Models\Content;
use App\Models\PlaybackSession;
use App\Models\Taxonomy;
use App\Models\WalletTransaction;
use App\Services\ContentScopeService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AnalyticsController extends ApiController
{
    public function __construct(
        protected ContentScopeService $contentScope,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'range' => ['nullable', Rule::in(['7days', '30days', '3months'])],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'content_id' => ['nullable', 'integer', 'exists:contents,id'],
            'genre_id' => ['nullable', 'integer', 'exists:taxonomies,id'],
            'country_code' => ['nullable', 'string', 'max:5'],
            'administrative_area' => ['nullable', 'string', 'max:120'],
            'group_by' => ['nullable', Rule::in(['day', 'month'])],
        ]);

        [$from, $to, $rangeValue] = $this->resolveDateRange($filters);
        $groupBy = (string) ($filters['group_by'] ?? ($from->diffInDays($to) > 62 ? 'month' : 'day'));
        $countryCode = strtoupper(trim((string) ($filters['country_code'] ?? '')));
        $administrativeArea = trim((string) ($filters['administrative_area'] ?? ''));
        $user = $request->user();
        $isScoped = $this->contentScope->isScoped($user);

        $contentQuery = $this->contentScope
            ->scopeContentQuery($user, Content::query())
            ->with(['taxonomies' => fn ($query) => $query->where('type', Taxonomy::TYPE_GENRE)])
            ->orderBy('original_title');

        if (! empty($filters['content_id'])) {
            $contentQuery->whereKey((int) $filters['content_id']);
        }

        if (! empty($filters['genre_id'])) {
            $contentQuery->whereHas(
                'taxonomies',
                fn (Builder $query) => $query->whereKey((int) $filters['genre_id'])->where('type', Taxonomy::TYPE_GENRE),
            );
        }

        $contents = $contentQuery->get();
        $contentIds = $contents->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $contentSlugs = $contents->pluck('slug')->filter()->values()->all();
        $contentsById = $contents->keyBy('id');
        $contentIdsBySlug = $contents
            ->filter(fn (Content $content): bool => filled($content->slug))
            ->mapWithKeys(fn (Content $content): array => [(string) $content->slug => (int) $content->id]);

        $transactions = $this->transactionQuery($contentIds, $contentSlugs)
            ->with('user.defaultBillingAddress')
            ->where('type', WalletTransaction::TYPE_PURCHASE)
            ->whereBetween('processed_at', [$from, $to])
            ->when($countryCode !== '', function (Builder $query) use ($countryCode): void {
                $query->whereHas(
                    'user.billingAddresses',
                    fn (Builder $addressQuery) => $addressQuery->where('country_code', $countryCode),
                );
            })
            ->when($administrativeArea !== '', function (Builder $query) use ($administrativeArea): void {
                $query->whereHas(
                    'user.billingAddresses',
                    fn (Builder $addressQuery) => $addressQuery->where('administrative_area', $administrativeArea),
                );
            })
            ->orderBy('processed_at')
            ->get();

        $analyticsQuery = DB::connection('analytics')
            ->table('video_daily_aggregates')
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->when($contentIds !== [], fn ($query) => $query->whereIn('content_id', $contentIds))
            ->when($contentIds === [], fn ($query) => $query->whereRaw('1 = 0'))
            ->when($countryCode !== '', fn ($query) => $query->where('country_code', $countryCode))
            ->orderBy('date');

        $analyticsRows = collect($analyticsQuery->get());

        $playbackQuery = PlaybackSession::query()
            ->whereBetween('started_at', [$from, $to])
            ->when($contentIds !== [], fn (Builder $query) => $query->whereIn('content_id', $contentIds))
            ->when($contentIds === [], fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->when($countryCode !== '', fn (Builder $query) => $query->where('country_code', $countryCode))
            ->when($administrativeArea !== '', function (Builder $query) use ($administrativeArea): void {
                $query->whereHas(
                    'user.billingAddresses',
                    fn (Builder $addressQuery) => $addressQuery->where('administrative_area', $administrativeArea),
                );
            });

        $playbackRows = (clone $playbackQuery)
            ->get(['content_id', 'user_id', 'started_at', 'watch_time_seconds', 'counted_as_view', 'country_code']);

        $paidTransactions = $transactions
            ->filter(fn (WalletTransaction $transaction): bool => (float) $transaction->amount < 0)
            ->values();

        $contentPerformance = $this->contentPerformance(
            $contents,
            $transactions,
            $analyticsRows,
            $playbackRows,
            $contentIdsBySlug,
        );

        $countryBreakdown = $this->countryBreakdown($analyticsRows, $transactions);

        return response()->json([
            'range' => [
                'value' => $rangeValue,
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'group_by' => $groupBy,
                'days' => $from->diffInDays($to) + 1,
            ],
            'stats' => [
                'revenue_amount' => round(abs((float) $paidTransactions->sum('amount')), 2),
                'sales_count' => $paidTransactions->count(),
                'orders_count' => $transactions->count(),
                'free_claims_count' => $transactions
                    ->filter(fn (WalletTransaction $transaction): bool => (float) $transaction->amount === 0.0)
                    ->count(),
                'unique_buyers_count' => $paidTransactions->pluck('user_id')->filter()->unique()->count(),
                'views_count' => (int) $analyticsRows->sum('views'),
                'sessions_count' => $playbackRows->count(),
                'unique_viewers_count' => $playbackRows->pluck('user_id')->filter()->unique()->count(),
                'watch_time_seconds' => (int) $analyticsRows->sum('watch_time_seconds'),
                'bandwidth_gb' => round((float) $analyticsRows->sum('bandwidth_gb'), 2),
                'content_count' => $contents->count(),
            ],
            'timeline' => $this->timeline(
                $from,
                $to,
                $groupBy,
                $transactions,
                $analyticsRows,
                $playbackRows,
            ),
            'content_performance' => $contentPerformance,
            'country_breakdown' => $countryBreakdown,
            'filters' => [
                'applied' => [
                    'content_id' => isset($filters['content_id']) ? (int) $filters['content_id'] : null,
                    'genre_id' => isset($filters['genre_id']) ? (int) $filters['genre_id'] : null,
                    'country_code' => $countryCode !== '' ? $countryCode : null,
                    'administrative_area' => $administrativeArea !== '' ? $administrativeArea : null,
                ],
                'options' => $this->filterOptions(
                    $user,
                    $isScoped,
                    $transactions,
                    $contentIds,
                ),
            ],
            'currency' => 'MDL',
            'scope' => [
                'is_content_scoped' => $isScoped,
                'content_ids' => $contentIds,
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{0: Carbon, 1: Carbon, 2: string}
     */
    protected function resolveDateRange(array $filters): array
    {
        if (! empty($filters['from']) || ! empty($filters['to'])) {
            $from = ! empty($filters['from'])
                ? Carbon::parse((string) $filters['from'])->startOfDay()
                : Carbon::parse((string) $filters['to'])->subDays(29)->startOfDay();
            $to = ! empty($filters['to'])
                ? Carbon::parse((string) $filters['to'])->endOfDay()
                : now()->endOfDay();

            return [$from, $to, 'custom'];
        }

        $range = (string) ($filters['range'] ?? '30days');
        $days = match ($range) {
            '7days' => 7,
            '3months' => 90,
            default => 30,
        };

        return [now()->startOfDay()->subDays($days - 1), now()->endOfDay(), $range];
    }

    protected function transactionQuery(array $contentIds, array $contentSlugs): Builder
    {
        $query = WalletTransaction::query();

        if ($contentIds === [] && $contentSlugs === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $builder) use ($contentIds, $contentSlugs): void {
            foreach ($contentIds as $contentId) {
                $builder->orWhere('meta->content_id', $contentId);
            }

            foreach ($contentSlugs as $slug) {
                $builder->orWhere('meta->content_slug', $slug);
            }
        });
    }

    /**
     * @param  Collection<int, Content>  $contents
     * @param  Collection<int, WalletTransaction>  $transactions
     * @param  Collection<int, object>  $analyticsRows
     * @param  Collection<int, PlaybackSession>  $playbackRows
     * @param  Collection<string, int>  $contentIdsBySlug
     * @return array<int, array<string, mixed>>
     */
    protected function contentPerformance(
        Collection $contents,
        Collection $transactions,
        Collection $analyticsRows,
        Collection $playbackRows,
        Collection $contentIdsBySlug,
    ): array {
        $transactionsByContent = $transactions->groupBy(
            fn (WalletTransaction $transaction): int => $this->transactionContentId($transaction, $contentIdsBySlug),
        );
        $analyticsByContent = $analyticsRows->groupBy(fn (object $row): int => (int) $row->content_id);
        $playbackByContent = $playbackRows->groupBy(fn (PlaybackSession $row): int => (int) $row->content_id);

        return $contents
            ->map(function (Content $content) use ($transactionsByContent, $analyticsByContent, $playbackByContent): array {
                $contentTransactions = collect($transactionsByContent->get((int) $content->id, []));
                $paidTransactions = $contentTransactions
                    ->filter(fn (WalletTransaction $transaction): bool => (float) $transaction->amount < 0);
                $contentAnalytics = collect($analyticsByContent->get((int) $content->id, []));
                $contentPlayback = collect($playbackByContent->get((int) $content->id, []));

                return [
                    'content_id' => (int) $content->id,
                    'slug' => $content->slug,
                    'title' => $this->contentTitle($content),
                    'type' => $content->type,
                    'poster_url' => $content->poster_url,
                    'genres' => $content->taxonomies
                        ->map(fn (Taxonomy $taxonomy): string => $this->taxonomyName($taxonomy))
                        ->filter()
                        ->values()
                        ->all(),
                    'revenue_amount' => round(abs((float) $paidTransactions->sum('amount')), 2),
                    'sales_count' => $paidTransactions->count(),
                    'orders_count' => $contentTransactions->count(),
                    'free_claims_count' => $contentTransactions->count() - $paidTransactions->count(),
                    'unique_buyers_count' => $paidTransactions->pluck('user_id')->filter()->unique()->count(),
                    'views_count' => (int) $contentAnalytics->sum('views'),
                    'sessions_count' => $contentPlayback->count(),
                    'unique_viewers_count' => $contentPlayback->pluck('user_id')->filter()->unique()->count(),
                    'watch_time_seconds' => (int) $contentAnalytics->sum('watch_time_seconds'),
                    'bandwidth_gb' => round((float) $contentAnalytics->sum('bandwidth_gb'), 2),
                ];
            })
            ->sort(function (array $left, array $right): int {
                return [$right['revenue_amount'], $right['views_count'], $right['sessions_count']]
                    <=> [$left['revenue_amount'], $left['views_count'], $left['sessions_count']];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, object>  $analyticsRows
     * @param  Collection<int, WalletTransaction>  $transactions
     * @return array<int, array<string, mixed>>
     */
    protected function countryBreakdown(Collection $analyticsRows, Collection $transactions): array
    {
        $analyticsByCountry = $analyticsRows
            ->groupBy(fn (object $row): string => strtoupper((string) ($row->country_code ?: 'GLOBAL')));
        $salesByCountry = $transactions
            ->groupBy(function (WalletTransaction $transaction): string {
                return strtoupper((string) ($transaction->user?->defaultBillingAddress?->country_code ?: 'UNKNOWN'));
            });
        $countryCodes = $analyticsByCountry->keys()->merge($salesByCountry->keys())->unique();

        return $countryCodes
            ->map(function (string $countryCode) use ($analyticsByCountry, $salesByCountry): array {
                $analytics = collect($analyticsByCountry->get($countryCode, []));
                $sales = collect($salesByCountry->get($countryCode, []));
                $paidSales = $sales->filter(fn (WalletTransaction $transaction): bool => (float) $transaction->amount < 0);

                return [
                    'country_code' => $countryCode,
                    'views_count' => (int) $analytics->sum('views'),
                    'watch_time_seconds' => (int) $analytics->sum('watch_time_seconds'),
                    'bandwidth_gb' => round((float) $analytics->sum('bandwidth_gb'), 2),
                    'sales_count' => $paidSales->count(),
                    'revenue_amount' => round(abs((float) $paidSales->sum('amount')), 2),
                ];
            })
            ->sort(function (array $left, array $right): int {
                return [$right['views_count'], $right['revenue_amount']]
                    <=> [$left['views_count'], $left['revenue_amount']];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, WalletTransaction>  $transactions
     * @param  Collection<int, object>  $analyticsRows
     * @param  Collection<int, PlaybackSession>  $playbackRows
     * @return array<int, array<string, mixed>>
     */
    protected function timeline(
        CarbonInterface $from,
        CarbonInterface $to,
        string $groupBy,
        Collection $transactions,
        Collection $analyticsRows,
        Collection $playbackRows,
    ): array {
        $bucketKey = fn (CarbonInterface $date): string => $groupBy === 'month' ? $date->format('Y-m') : $date->toDateString();
        $transactionsByPeriod = $transactions->groupBy(
            fn (WalletTransaction $transaction): string => $bucketKey($transaction->processed_at ?? $from),
        );
        $analyticsByPeriod = $analyticsRows->groupBy(
            fn (object $row): string => $bucketKey(Carbon::parse((string) $row->date)),
        );
        $playbackByPeriod = $playbackRows->groupBy(
            fn (PlaybackSession $row): string => $bucketKey($row->started_at ?? $from),
        );

        $periods = $groupBy === 'month'
            ? $this->monthPeriods($from, $to)
            : collect(CarbonPeriod::create($from->copy()->startOfDay(), '1 day', $to->copy()->startOfDay()))
                ->map(fn (CarbonInterface $date): CarbonInterface => $date);

        return $periods
            ->map(function (CarbonInterface $date) use ($groupBy, $bucketKey, $transactionsByPeriod, $analyticsByPeriod, $playbackByPeriod): array {
                $key = $bucketKey($date);
                $sales = collect($transactionsByPeriod->get($key, []));
                $paidSales = $sales->filter(fn (WalletTransaction $transaction): bool => (float) $transaction->amount < 0);
                $analytics = collect($analyticsByPeriod->get($key, []));
                $playback = collect($playbackByPeriod->get($key, []));

                return [
                    'period' => $key,
                    'label' => $groupBy === 'month' ? $date->translatedFormat('M Y') : $date->format('d M'),
                    'revenue_amount' => round(abs((float) $paidSales->sum('amount')), 2),
                    'sales_count' => $paidSales->count(),
                    'orders_count' => $sales->count(),
                    'views_count' => (int) $analytics->sum('views'),
                    'sessions_count' => $playback->count(),
                    'watch_time_seconds' => (int) $analytics->sum('watch_time_seconds'),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, Carbon>
     */
    protected function monthPeriods(CarbonInterface $from, CarbonInterface $to): Collection
    {
        $months = collect();
        $cursor = Carbon::parse($from->toDateString())->startOfMonth();
        $lastMonth = Carbon::parse($to->toDateString())->startOfMonth();

        while ($cursor->lte($lastMonth)) {
            $months->push($cursor->copy());
            $cursor->addMonth();
        }

        return $months;
    }

    /**
     * @param  Collection<int, WalletTransaction>  $transactions
     * @return array<string, mixed>
     */
    protected function filterOptions(
        mixed $user,
        bool $isScoped,
        Collection $transactions,
        array $contentIds,
    ): array {
        $availableContents = $this->contentScope
            ->scopeContentQuery($user, Content::query())
            ->orderBy('original_title')
            ->get()
            ->map(fn (Content $content): array => [
                'value' => (int) $content->id,
                'label' => $this->contentTitle($content),
            ])
            ->values();

        $genres = Taxonomy::query()
            ->where('type', Taxonomy::TYPE_GENRE)
            ->whereHas('contents', function (Builder $query) use ($contentIds, $isScoped): void {
                if ($isScoped) {
                    $query->whereIn('contents.id', $contentIds);
                }
            })
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Taxonomy $taxonomy): array => [
                'value' => (int) $taxonomy->id,
                'label' => $this->taxonomyName($taxonomy),
            ])
            ->values();

        $countryCodes = DB::connection('analytics')
            ->table('video_daily_aggregates')
            ->whereNotNull('country_code')
            ->when($isScoped, fn ($query) => $query->whereIn('content_id', $contentIds))
            ->distinct()
            ->pluck('country_code')
            ->map(fn (mixed $code): string => strtoupper((string) $code))
            ->filter()
            ->unique()
            ->sort()
            ->values();

        $regions = $isScoped
            ? $transactions
                ->map(fn (WalletTransaction $transaction): ?string => $transaction->user?->defaultBillingAddress?->administrative_area)
                ->filter()
                ->unique()
                ->sort()
                ->values()
            : BillingAddress::query()
                ->whereNotNull('administrative_area')
                ->distinct()
                ->orderBy('administrative_area')
                ->pluck('administrative_area');

        return [
            'contents' => $availableContents,
            'genres' => $genres,
            'countries' => $countryCodes
                ->map(fn (string $code): array => ['value' => $code, 'label' => $code])
                ->values(),
            'regions' => $regions
                ->map(fn (string $region): array => ['value' => $region, 'label' => $region])
                ->values(),
        ];
    }

    /**
     * @param  Collection<string, int>  $contentIdsBySlug
     */
    protected function transactionContentId(WalletTransaction $transaction, Collection $contentIdsBySlug): int
    {
        $contentId = data_get($transaction->meta ?? [], 'content_id');

        if (is_numeric($contentId)) {
            return (int) $contentId;
        }

        $slug = (string) data_get($transaction->meta ?? [], 'content_slug', '');

        return (int) ($contentIdsBySlug->get($slug) ?? 0);
    }

    protected function contentTitle(Content $content): string
    {
        return $content->getTranslation('title', $content->default_locale, false)
            ?? $content->getTranslation('title', 'ro', false)
            ?? $content->original_title;
    }

    protected function taxonomyName(Taxonomy $taxonomy): string
    {
        return $taxonomy->getTranslation('name', 'ro', false)
            ?? $taxonomy->getTranslation('name', 'en', false)
            ?? $taxonomy->slug;
    }
}
