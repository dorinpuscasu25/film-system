<?php

namespace App\Http\Requests\Admin;

use App\Models\Content;
use App\Models\Offer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOfferRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'content_id' => ['required', 'integer', Rule::exists('contents', 'id')],
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

            if ($offerType === Offer::TYPE_RENTAL && ! $this->filled('rental_days')) {
                $validator->errors()->add('rental_days', 'Rental offers require a number of days.');
            }

            if ($offerType === Offer::TYPE_FREE && (float) $this->input('price_amount', 0) > 0) {
                $validator->errors()->add('price_amount', 'Free offers must have a 0 price.');
            }

            if ($content !== null && ! in_array((string) $this->input('quality'), $content->available_qualities ?? [], true)) {
                $validator->errors()->add('quality', 'The selected quality is not enabled for this title.');
            }

            if ($content !== null) {
                $selectedQuality = (string) $this->input('quality');
                $hasMatchingFormat = $content->formats()
                    ->where('format_type', 'main')
                    ->where('quality', $selectedQuality)
                    ->where('is_active', true)
                    ->exists();

                if (! $hasMatchingFormat && ! $this->filled('playback_url')) {
                    $validator->errors()->add(
                        'quality',
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
            'name' => $this->filled('name')
                ? trim((string) $this->input('name'))
                : $this->defaultOfferName($offerType, $quality, $rentalDays),
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

    protected function defaultOfferName(string $offerType, string $quality, ?int $rentalDays): string
    {
        if ($offerType === Offer::TYPE_FREE) {
            return "Free {$quality}";
        }

        if ($offerType === Offer::TYPE_LIFETIME) {
            return "Forever {$quality}";
        }

        return "{$rentalDays} days {$quality}";
    }
}
