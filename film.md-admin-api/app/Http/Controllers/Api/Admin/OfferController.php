<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Admin\StoreOfferRequest;
use App\Http\Requests\Admin\UpdateOfferRequest;
use App\Models\Content;
use App\Models\Offer;
use App\Services\ContentSearchService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class OfferController extends ApiController
{
    public function __construct(
        protected ContentSearchService $contentSearch,
    ) {}

    public function index(): JsonResponse
    {
        $locale = request()->user()?->preferred_locale ?? Content::supportedLocales()[0];
        $offers = Offer::query()
            ->with('content')
            ->orderByDesc('is_active')
            ->orderBy('sort_order')
            ->orderBy('offer_type')
            ->orderBy('price_amount')
            ->get();

        return response()->json([
            'items' => $offers->map(fn (Offer $offer) => $this->offerData($offer, $locale))->values(),
            'stats' => [
                'total_offers' => $offers->count(),
                'active_offers' => $offers->filter(fn (Offer $offer): bool => $offer->isCurrentlyAvailable())->count(),
                'rental_offers' => $offers->where('offer_type', Offer::TYPE_RENTAL)->count(),
                'lifetime_offers' => $offers->where('offer_type', Offer::TYPE_LIFETIME)->count(),
            ],
            'filters' => [
                'types' => collect(Offer::typeLabels())
                    ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])
                    ->values(),
                'qualities' => collect(Content::availableQualities())
                    ->map(fn (string $quality) => ['value' => $quality, 'label' => $quality])
                    ->values(),
                'contents' => Content::query()
                    ->orderBy('original_title')
                    ->get()
                    ->map(fn (Content $content) => [
                        'value' => (string) $content->id,
                        'label' => $content->getTranslation('title', $locale, false)
                            ?? $content->original_title,
                    ])
                    ->values(),
            ],
        ]);
    }

    public function store(StoreOfferRequest $request): JsonResponse
    {
        $offer = Offer::query()->create($request->normalizedPayload());
        $content = $offer->content()->first();
        $this->syncContentFreeFlag($content);
        $this->contentSearch->syncContent($content?->fresh()->load('taxonomies', 'offers'));

        return response()->json([
            'offer' => $this->offerData(
                $offer->fresh()->load('content'),
                $request->user()?->preferred_locale ?? Content::supportedLocales()[0],
            ),
        ], Response::HTTP_CREATED);
    }

    public function update(UpdateOfferRequest $request, Offer $offer): JsonResponse
    {
        $offer->fill($request->normalizedPayload())->save();
        $content = $offer->content()->first();
        $this->syncContentFreeFlag($content);
        $this->contentSearch->syncContent($content?->fresh()->load('taxonomies', 'offers'));

        return response()->json([
            'offer' => $this->offerData(
                $offer->fresh()->load('content'),
                $request->user()?->preferred_locale ?? Content::supportedLocales()[0],
            ),
        ]);
    }

    public function destroy(Offer $offer): JsonResponse
    {
        $content = $offer->content()->first();
        $offer->delete();
        $this->syncContentFreeFlag($content);
        $this->contentSearch->syncContent($content?->fresh()->load('taxonomies', 'offers'));

        return response()->json([], Response::HTTP_NO_CONTENT);
    }

    protected function syncContentFreeFlag(?Content $content): void
    {
        if ($content === null) {
            return;
        }

        $content->forceFill([
            'is_free' => $content->offers()->where('offer_type', Offer::TYPE_FREE)->exists(),
        ])->save();
    }
}
