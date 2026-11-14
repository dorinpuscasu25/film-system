<?php

namespace App\Services;

use App\Models\Content;
use App\Models\Offer;
use App\Models\PricePlan;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Price plans are copied, not referenced: every offer linked to a plan stores the plan's price
 * in offers.price_amount, so purchases, search, coupons and reports keep reading one column.
 * This service is the only place that writes those copies — when a plan is saved and when a
 * plan is applied to titles.
 */
class PricePlanService
{
    public function __construct(
        protected ContentSearchService $contentSearch,
        protected StorefrontCacheService $storefrontCache,
        protected OfferContentSyncService $offerContentSync,
    ) {}

    /**
     * What saving the plan with these items would do to the offers already linked to it.
     *
     * @param  array<int, array{offer_type: string, quality: string, price_amount: float, rental_days: ?int}>  $items
     * @return array{updated: array<int, array<string, mixed>>, detached: array<int, array<string, mixed>>, unchanged_count: int}
     */
    public function previewChanges(PricePlan $plan, array $items, string $currency): array
    {
        $draft = (new PricePlan)->forceFill(['items' => $items, 'currency' => $currency]);
        $updated = [];
        $detached = [];
        $unchanged = 0;

        foreach ($plan->offers()->with('content')->get() as $offer) {
            $item = $draft->itemFor($offer->offer_type, $offer->quality);

            if ($item === null) {
                $detached[] = $this->offerSummary($offer);

                continue;
            }

            $next = $this->valuesFromItem($item, $currency);
            if ($this->differs($offer, $next)) {
                $updated[] = $this->offerSummary($offer) + [
                    'new_price_amount' => $next['price_amount'],
                    'new_rental_days' => $next['rental_days'],
                    'new_currency' => $next['currency'],
                ];
            } else {
                $unchanged++;
            }
        }

        return ['updated' => $updated, 'detached' => $detached, 'unchanged_count' => $unchanged];
    }

    /**
     * Saves the plan and re-prices every linked offer. Offers whose (type, quality) the plan no
     * longer prices are detached and keep their current price.
     *
     * @return array{updated_offers: int, detached_offers: int, affected_contents: int}
     */
    public function save(PricePlan $plan, array $attributes): array
    {
        $result = DB::transaction(function () use ($plan, $attributes): array {
            $plan->fill($attributes)->save();
            $plan->refresh();

            $updated = 0;
            $detached = 0;
            $contentIds = [];

            foreach ($plan->offers()->lockForUpdate()->get() as $offer) {
                $item = $plan->itemFor($offer->offer_type, $offer->quality);

                if ($item === null) {
                    $offer->forceFill(['price_plan_id' => null])->save();
                    $detached++;
                    $contentIds[] = $offer->content_id;

                    continue;
                }

                $next = $this->valuesFromItem($item, $plan->currency);
                if (! $this->differs($offer, $next)) {
                    continue;
                }

                $offer->forceFill($next + ['name' => $this->nameAfterChange($offer, $next['rental_days'])])->save();
                $updated++;
                $contentIds[] = $offer->content_id;
            }

            return [
                'updated_offers' => $updated,
                'detached_offers' => $detached,
                'content_ids' => array_values(array_unique($contentIds)),
            ];
        });

        $this->afterOffersChanged($result['content_ids']);

        return [
            'updated_offers' => $result['updated_offers'],
            'detached_offers' => $result['detached_offers'],
            'affected_contents' => count($result['content_ids']),
        ];
    }

    /**
     * Links each title's offers to the plan, re-pricing them, and creates the offers the plan
     * prices but the title doesn't have yet — only for qualities the title can actually play.
     *
     * @param  Collection<int, Content>  $contents
     * @return array<int, array{content_id: int, content_title: string, linked: int, created: int, skipped_qualities: array<int, string>}>
     */
    public function applyToContents(PricePlan $plan, Collection $contents): array
    {
        $report = DB::transaction(function () use ($plan, $contents): array {
            $report = [];

            foreach ($contents as $content) {
                $report[] = $this->applyToContent($plan, $content);
            }

            return $report;
        });

        $this->afterOffersChanged($contents->pluck('id')->all());

        return $report;
    }

    protected function applyToContent(PricePlan $plan, Content $content): array
    {
        $offers = $content->offers()->lockForUpdate()->get();
        $playableQualities = $content->formats()
            ->where('format_type', 'main')
            ->where('is_active', true)
            ->pluck('quality')
            ->all();
        $hasEpisodePlayback = $content->hasSeriesEpisodePlayback();
        $linked = 0;
        $created = 0;
        $skipped = [];
        $nextSortOrder = (int) $offers->max('sort_order') + 1;

        foreach ($plan->items ?? [] as $item) {
            $values = $this->valuesFromItem($item, $plan->currency);
            $offer = $this->matchingOffer($offers, $plan, $item);

            if ($offer !== null) {
                $offer->forceFill($values + [
                    'price_plan_id' => $plan->id,
                    'name' => $this->nameAfterChange($offer, $values['rental_days']),
                ])->save();
                $linked++;

                continue;
            }

            if (! $hasEpisodePlayback && ! in_array($item['quality'], $playableQualities, true)) {
                $skipped[] = $item['quality'].' · '.$item['offer_type'];

                continue;
            }

            $offers->push($content->offers()->create($values + [
                'price_plan_id' => $plan->id,
                'name' => Offer::defaultName($item['offer_type'], $item['quality'], $values['rental_days']),
                'offer_type' => $item['offer_type'],
                'quality' => $item['quality'],
                'is_active' => true,
                'sort_order' => $nextSortOrder++,
            ]));
            $created++;
        }

        return [
            'content_id' => $content->id,
            'content_title' => $content->original_title,
            'linked' => $linked,
            'created' => $created,
            'skipped_qualities' => $skipped,
        ];
    }

    /**
     * The title's existing offer for this plan row: one already on the plan's duration wins
     * (re-linking it can't collide with the "same duration and quality" rule), then any offer
     * of the same type and quality.
     */
    protected function matchingOffer(Collection $offers, PricePlan $plan, array $item): ?Offer
    {
        $candidates = $offers->filter(
            fn (Offer $offer): bool => $offer->offer_type === $item['offer_type'] && $offer->quality === $item['quality']
        );

        if ($candidates->isEmpty()) {
            return null;
        }

        $rentalDays = $item['offer_type'] === Offer::TYPE_RENTAL ? (int) $item['rental_days'] : null;

        return $candidates->first(fn (Offer $offer): bool => $offer->rental_days === $rentalDays)
            ?? $candidates->first(fn (Offer $offer): bool => $offer->price_plan_id === $plan->id)
            ?? $candidates->first();
    }

    protected function valuesFromItem(array $item, string $currency): array
    {
        return [
            'price_amount' => round((float) $item['price_amount'], 2),
            'rental_days' => $item['offer_type'] === Offer::TYPE_RENTAL ? (int) $item['rental_days'] : null,
            'currency' => $currency,
        ];
    }

    protected function differs(Offer $offer, array $next): bool
    {
        return round((float) $offer->price_amount, 2) !== $next['price_amount']
            || $offer->rental_days !== $next['rental_days']
            || $offer->currency !== $next['currency'];
    }

    /** Keeps an auto-generated name ("2 days HD") in step with a new duration; custom names stay. */
    protected function nameAfterChange(Offer $offer, ?int $rentalDays): string
    {
        $autoName = Offer::defaultName($offer->offer_type, $offer->quality, $offer->rental_days);

        return $offer->name === $autoName
            ? Offer::defaultName($offer->offer_type, $offer->quality, $rentalDays)
            : $offer->name;
    }

    protected function offerSummary(Offer $offer): array
    {
        return [
            'offer_id' => $offer->id,
            'content_id' => $offer->content_id,
            'content_title' => $offer->content?->original_title,
            'offer_type' => $offer->offer_type,
            'quality' => $offer->quality,
            'price_amount' => round((float) $offer->price_amount, 2),
            'rental_days' => $offer->rental_days,
            'currency' => $offer->currency,
        ];
    }

    /** @param  array<int, int>  $contentIds */
    protected function afterOffersChanged(array $contentIds): void
    {
        if ($contentIds === []) {
            return;
        }

        Content::query()->whereIn('id', $contentIds)->get()
            ->each(fn (Content $content) => $this->offerContentSync->sync($content));
        $this->contentSearch->syncContentIds($contentIds);
        $this->storefrontCache->clear();
    }
}
