<?php

namespace App\Http\Requests\Admin;

use App\Models\Content;
use App\Models\Offer;
use App\Models\PricePlan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * An offer linked to a price plan takes its price, rental duration and currency from the
     * plan, whatever the client sent — so the duplicate check and the saved row below both see
     * the plan's values.
     */
    protected function prepareForValidation(): void
    {
        $plan = $this->linkedPricePlan();
        if ($plan === null) {
            return;
        }

        $item = $plan->itemFor((string) $this->input('offer_type'), (string) $this->input('quality'));
        if ($item === null) {
            return;
        }

        $this->merge([
            'price_amount' => $item['price_amount'],
            'rental_days' => $item['rental_days'] ?? null,
            'currency' => $plan->currency,
        ]);
    }

    /**
     * The plan this offer ends up linked to. A client that doesn't send `price_plan_id` at all
     * (an older screen) keeps the offer's current link instead of silently detaching it.
     */
    protected function linkedPricePlan(): ?PricePlan
    {
        if (! $this->has('price_plan_id')) {
            /** @var Offer|null $currentOffer */
            $currentOffer = $this->route('offer');

            return $currentOffer?->price_plan_id !== null
                ? PricePlan::query()->find($currentOffer->price_plan_id)
                : null;
        }

        if (! is_numeric($this->input('price_plan_id'))) {
            return null;
        }

        return PricePlan::query()->find((int) $this->input('price_plan_id'));
    }

    public function rules(): array
    {
        return [
            'content_id' => ['required', 'integer', Rule::exists('contents', 'id')],
            'price_plan_id' => ['nullable', 'integer', Rule::exists('price_plans', 'id')],
            'name' => ['nullable', 'string', 'max:255'],
            'offer_type' => ['required', Rule::in(Offer::availableTypes())],
            'quality' => ['required', Rule::in(Content::availableQualities())],
            'currency' => ['nullable', 'string', 'size:3'],
            'price_amount' => ['required', 'numeric', 'min:0', 'max:9999.99'],
            'playback_url' => ['nullable', 'url', 'max:2048'],
            'rental_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'is_active' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $offerType = (string) $this->input('offer_type');
            $content = Content::query()->find($this->integer('content_id'));

            $plan = $this->linkedPricePlan();
            if ($plan !== null) {
                if (! in_array($offerType, PricePlan::pricedOfferTypes(), true)) {
                    $validator->errors()->add('price_plan_id', 'Free offers cannot be linked to a price plan.');
                } elseif ($plan->itemFor($offerType, (string) $this->input('quality')) === null) {
                    $validator->errors()->add('price_plan_id', 'This price plan has no price for the selected type and quality.');
                }
            }

            if ($offerType === Offer::TYPE_RENTAL && ! $this->filled('rental_days')) {
                $validator->errors()->add('rental_days', 'Rental offers require a number of days.');
            }

            if ($offerType === Offer::TYPE_FREE && (float) $this->input('price_amount', 0) > 0) {
                $validator->errors()->add('price_amount', 'Free offers must have a 0 price.');
            }

            if ($content !== null) {
                $selectedQuality = (string) $this->input('quality');
                $hasMatchingFormat = $content->formats()
                    ->where('format_type', 'main')
                    ->where('quality', $selectedQuality)
                    ->where('is_active', true)
                    ->exists();
                $hasEnabledQuality = in_array($selectedQuality, $content->available_qualities ?? [], true);
                $hasPlaybackOverride = $this->filled('playback_url');
                $hasSeriesEpisodePlayback = $content->hasSeriesEpisodePlayback();

                if (! $hasEnabledQuality && ! $hasMatchingFormat && ! $hasPlaybackOverride && ! $hasSeriesEpisodePlayback) {
                    $validator->errors()->add('quality', 'The selected quality is not enabled for this title.');
                }

                if (! $hasMatchingFormat && ! $hasPlaybackOverride && ! $hasSeriesEpisodePlayback) {
                    $validator->errors()->add(
                        'playback_url',
                        'This offer needs either an active Bunny main format with the same quality or a playback URL override.'
                    );
                }
            }

            $duplicateQuery = Offer::query()
                ->where('content_id', $this->integer('content_id'))
                ->where('offer_type', $offerType)
                ->where('quality', (string) $this->input('quality'))
                ->where('currency', strtoupper((string) ($this->input('currency') ?: Content::DEFAULT_CURRENCY)))
                ->where('rental_days', $offerType === Offer::TYPE_RENTAL ? (int) $this->input('rental_days') : null);

            /** @var Offer|null $currentOffer */
            $currentOffer = $this->route('offer');
            if ($currentOffer !== null) {
                $duplicateQuery->where('id', '!=', $currentOffer->id);
            }

            if ($duplicateQuery->exists()) {
                $validator->errors()->add('quality', 'This film already has an offer with the same duration and quality.');
            }
        });
    }

    public function normalizedPayload(): array
    {
        $offerType = (string) $this->input('offer_type');
        $quality = (string) $this->input('quality');
        $rentalDays = $offerType === Offer::TYPE_RENTAL ? (int) $this->input('rental_days') : null;

        return [
            'content_id' => $this->integer('content_id'),
            'price_plan_id' => $this->linkedPricePlan()?->id,
            'name' => $this->filled('name')
                ? trim((string) $this->input('name'))
                : Offer::defaultName($offerType, $quality, $rentalDays),
            'offer_type' => $offerType,
            'quality' => $quality,
            'currency' => strtoupper((string) ($this->input('currency') ?: Content::DEFAULT_CURRENCY)),
            'price_amount' => $offerType === Offer::TYPE_FREE ? 0 : (float) $this->input('price_amount'),
            'playback_url' => $this->filled('playback_url') ? trim((string) $this->input('playback_url')) : null,
            'rental_days' => $rentalDays,
            'is_active' => $this->boolean('is_active', true),
            'starts_at' => $this->filled('starts_at') ? (string) $this->input('starts_at') : null,
            'ends_at' => $this->filled('ends_at') ? (string) $this->input('ends_at') : null,
            'sort_order' => (int) $this->input('sort_order', 0),
        ];
    }
}
