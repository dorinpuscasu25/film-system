<?php

namespace App\Http\Controllers\Api;

use App\Models\AccountProfile;
use App\Models\Content;
use App\Models\ContentReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class ContentReviewController extends ApiController
{
    public function index(Request $request, string $identifier): JsonResponse
    {
        $content = $this->resolvePublishedContent($identifier);

        if ($content === null) {
            return response()->json(['message' => 'The requested content was not found.'], Response::HTTP_NOT_FOUND);
        }

        $reviews = ContentReview::query()
            ->with(['user:id,name,email', 'profile:id,name,avatar_label'])
            ->where('content_id', $content->id)
            ->where('status', ContentReview::STATUS_PUBLISHED)
            ->latest('id')
            ->get();

        return response()->json([
            'items' => $reviews->map(fn (ContentReview $review) => $this->reviewData($review))->values(),
            'summary' => $this->summary($content->id),
        ]);
    }

    public function store(Request $request, string $identifier): JsonResponse
    {
        $content = $this->resolvePublishedContent($identifier);

        if ($content === null) {
            return response()->json(['message' => 'The requested content was not found.'], Response::HTTP_NOT_FOUND);
        }

        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['required', 'string', 'min:3', 'max:3000'],
            'locale' => ['nullable', 'string', Rule::in(Content::supportedLocales())],
            'account_profile_id' => ['nullable', 'integer', 'exists:account_profiles,id'],
        ]);

        $user = $request->user();
        $profileId = $data['account_profile_id'] ?? null;

        if ($profileId !== null) {
            $belongsToUser = AccountProfile::query()
                ->where('id', $profileId)
                ->where('user_id', $user->id)
                ->exists();

            if (! $belongsToUser) {
                return response()->json(['message' => 'Invalid profile.'], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        $review = ContentReview::query()->updateOrCreate(
            [
                'content_id' => $content->id,
                'user_id' => $user->id,
            ],
            [
                'account_profile_id' => $profileId,
                'rating' => (int) $data['rating'],
                'comment' => trim((string) $data['comment']),
                'locale' => $data['locale'] ?? null,
                'status' => ContentReview::STATUS_PUBLISHED,
            ],
        );

        $review->load(['user:id,name,email', 'profile:id,name,avatar_label']);
        $this->updatePlatformRating($content);

        return response()->json([
            'review' => $this->reviewData($review),
            'summary' => $this->summary($content->id),
        ]);
    }

    protected function resolvePublishedContent(string $identifier): ?Content
    {
        return Content::query()
            ->published()
            ->where(function ($builder) use ($identifier): void {
                $builder->where('slug', $identifier);

                if (ctype_digit($identifier)) {
                    $builder->orWhere('id', (int) $identifier);
                }
            })
            ->first();
    }

    protected function reviewData(ContentReview $review): array
    {
        $displayName = $review->profile?->name ?: $review->user?->name ?: 'Utilizator';
        $avatarLabel = $review->profile?->avatar_label ?: mb_strtoupper(mb_substr($displayName, 0, 1));

        return [
            'id' => $review->id,
            'user_id' => $review->user_id,
            'user_name' => $displayName,
            'user_avatar' => $avatarLabel,
            'rating' => $review->rating,
            'comment' => $review->comment,
            'status' => $review->status,
            'created_at' => $review->created_at?->toIso8601String(),
            'updated_at' => $review->updated_at?->toIso8601String(),
        ];
    }

    protected function summary(int $contentId): array
    {
        $query = ContentReview::query()
            ->where('content_id', $contentId)
            ->where('status', ContentReview::STATUS_PUBLISHED);

        return [
            'count' => (clone $query)->count(),
            'average_rating' => round((float) ((clone $query)->avg('rating') ?? 0), 1),
        ];
    }

    protected function updatePlatformRating(Content $content): void
    {
        $average = ContentReview::query()
            ->where('content_id', $content->id)
            ->where('status', ContentReview::STATUS_PUBLISHED)
            ->avg('rating');

        $content->forceFill([
            'platform_rating' => $average !== null ? round((float) $average, 1) : null,
        ])->save();
    }
}
