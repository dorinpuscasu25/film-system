<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\Content;
use App\Models\ContentReview;
use App\Services\ContentScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class ContentReviewController extends ApiController
{
    public function __construct(
        protected ContentScopeService $contentScope,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $contentId = $request->integer('content_id') ?: null;
        $status = $request->query('status');

        $query = ContentReview::query()
            ->with(['content:id,slug,original_title,title,default_locale,type', 'user:id,name,email', 'profile:id,name,avatar_label'])
            ->latest('id');

        if ($contentId !== null) {
            $query->where('content_id', $contentId);
        }

        if (in_array($status, [ContentReview::STATUS_PUBLISHED, ContentReview::STATUS_HIDDEN], true)) {
            $query->where('status', $status);
        }

        if ($this->contentScope->isScoped($user)) {
            $query->whereIn('content_id', $this->contentScope->assignedContentIds($user));
        }

        $reviews = $query->paginate(100);

        return response()->json([
            'items' => collect($reviews->items())->map(fn (ContentReview $review) => $this->reviewData($review))->values(),
            'stats' => [
                'total' => ContentReview::query()->when(
                    $this->contentScope->isScoped($user),
                    fn ($builder) => $builder->whereIn('content_id', $this->contentScope->assignedContentIds($user)),
                )->count(),
                'published' => ContentReview::query()->when(
                    $this->contentScope->isScoped($user),
                    fn ($builder) => $builder->whereIn('content_id', $this->contentScope->assignedContentIds($user)),
                )->where('status', ContentReview::STATUS_PUBLISHED)->count(),
                'hidden' => ContentReview::query()->when(
                    $this->contentScope->isScoped($user),
                    fn ($builder) => $builder->whereIn('content_id', $this->contentScope->assignedContentIds($user)),
                )->where('status', ContentReview::STATUS_HIDDEN)->count(),
            ],
            'pagination' => [
                'page' => $reviews->currentPage(),
                'per_page' => $reviews->perPage(),
                'total' => $reviews->total(),
            ],
        ]);
    }

    public function update(Request $request, ContentReview $review): JsonResponse
    {
        if ($this->contentScope->isScoped($request->user())
            && ! in_array($review->content_id, $this->contentScope->assignedContentIds($request->user()), true)) {
            return response()->json(['message' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        $data = $request->validate([
            'status' => ['required', Rule::in([ContentReview::STATUS_PUBLISHED, ContentReview::STATUS_HIDDEN])],
        ]);

        $review->update(['status' => $data['status']]);
        $this->updatePlatformRating($review->content);
        $review->load(['content:id,slug,original_title,title,default_locale,type', 'user:id,name,email', 'profile:id,name,avatar_label']);

        return response()->json(['review' => $this->reviewData($review)]);
    }

    public function destroy(Request $request, ContentReview $review): JsonResponse
    {
        if ($this->contentScope->isScoped($request->user())
            && ! in_array($review->content_id, $this->contentScope->assignedContentIds($request->user()), true)) {
            return response()->json(['message' => 'Forbidden'], Response::HTTP_FORBIDDEN);
        }

        $content = $review->content;
        $review->delete();
        $this->updatePlatformRating($content);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    protected function reviewData(ContentReview $review): array
    {
        $content = $review->content;
        $displayName = $review->profile?->name ?: $review->user?->name ?: 'Utilizator';
        $avatarLabel = $review->profile?->avatar_label ?: mb_strtoupper(mb_substr($displayName, 0, 1));

        return [
            'id' => $review->id,
            'content_id' => $review->content_id,
            'content_slug' => $content?->slug,
            'content_title' => $content?->getTranslation('title', $content->default_locale ?? 'ro', false) ?? $content?->original_title,
            'content_type' => $content?->type,
            'user_id' => $review->user_id,
            'user_name' => $displayName,
            'user_email' => $review->user?->email,
            'user_avatar' => $avatarLabel,
            'rating' => $review->rating,
            'comment' => $review->comment,
            'status' => $review->status,
            'locale' => $review->locale,
            'created_at' => $review->created_at?->toIso8601String(),
            'updated_at' => $review->updated_at?->toIso8601String(),
        ];
    }

    protected function updatePlatformRating(?Content $content): void
    {
        if ($content === null) {
            return;
        }

        $average = ContentReview::query()
            ->where('content_id', $content->id)
            ->where('status', ContentReview::STATUS_PUBLISHED)
            ->avg('rating');

        $content->forceFill([
            'platform_rating' => $average !== null ? round((float) $average, 1) : null,
        ])->save();
    }
}
