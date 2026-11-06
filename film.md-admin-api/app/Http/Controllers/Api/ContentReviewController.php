<?php

namespace App\Http\Controllers\Api;

use App\Models\AccountProfile;
use App\Models\Content;
use App\Models\ContentReview;
use App\Models\ContentReviewReport;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Models\UserBlock;
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

        // Public endpoint, but a signed-in viewer never sees reviews from people they blocked.
        $blockedIds = UserBlock::blockedIdsFor($this->optionalViewer($request));

        $reviews = ContentReview::query()
            ->with(['user:id,name,email', 'profile:id,name,avatar_label'])
            ->where('content_id', $content->id)
            ->where('status', ContentReview::STATUS_PUBLISHED)
            ->when($blockedIds !== [], fn ($query) => $query->whereNotIn('user_id', $blockedIds))
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

    public function destroy(Request $request, string $identifier, ContentReview $review): JsonResponse
    {
        $content = $this->resolvePublishedContent($identifier);

        if ($content === null || $review->content_id !== $content->id) {
            return response()->json(['message' => 'The requested review was not found.'], Response::HTTP_NOT_FOUND);
        }

        if ($review->user_id !== $request->user()->id) {
            return response()->json(['message' => 'You may only delete your own reviews.'], Response::HTTP_FORBIDDEN);
        }

        $review->delete();
        $this->updatePlatformRating($content);

        return response()->json([
            'message' => 'Review deleted successfully.',
            'summary' => $this->summary($content->id),
        ]);
    }

    /**
     * Flags a review for moderation. Enough distinct reports hide it straight away, so abusive
     * content doesn't stay up waiting for a moderator.
     */
    public function report(Request $request, string $identifier, ContentReview $review): JsonResponse
    {
        $content = $this->resolvePublishedContent($identifier);

        if ($content === null || $review->content_id !== $content->id) {
            return response()->json(['message' => 'The requested review was not found.'], Response::HTTP_NOT_FOUND);
        }

        $user = $request->user();
        if ($review->user_id === $user->id) {
            return response()->json(['message' => 'You cannot report your own review.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $data = $request->validate([
            'reason' => ['required', 'string', Rule::in(ContentReviewReport::REASONS)],
            'details' => ['nullable', 'string', 'max:500'],
        ]);

        ContentReviewReport::query()->updateOrCreate(
            ['content_review_id' => $review->id, 'reporter_id' => $user->id],
            ['reason' => $data['reason'], 'details' => $data['details'] ?? null, 'resolved_at' => null],
        );

        $openReports = $review->reports()->whereNull('resolved_at')->count();
        $hidden = false;
        if ($openReports >= ContentReviewReport::AUTO_HIDE_THRESHOLD && $review->status === ContentReview::STATUS_PUBLISHED) {
            $review->forceFill(['status' => ContentReview::STATUS_HIDDEN])->save();
            $this->updatePlatformRating($content);
            $hidden = true;
        }

        return response()->json([
            'message' => 'Thank you. Our team will review this report within 24 hours.',
            'hidden' => $hidden,
        ]);
    }

    /** Hides every review by that user for the viewer, now and in future. */
    public function blockUser(Request $request, User $user): JsonResponse
    {
        $viewer = $request->user();
        if ($viewer->id === $user->id) {
            return response()->json(['message' => 'You cannot block yourself.'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        UserBlock::query()->firstOrCreate(['user_id' => $viewer->id, 'blocked_user_id' => $user->id]);

        return response()->json(['message' => 'User blocked.', 'blocked_user_id' => $user->id]);
    }

    public function unblockUser(Request $request, User $user): JsonResponse
    {
        UserBlock::query()->where('user_id', $request->user()->id)->where('blocked_user_id', $user->id)->delete();

        return response()->json(['message' => 'User unblocked.', 'blocked_user_id' => $user->id]);
    }

    protected function optionalViewer(Request $request): ?User
    {
        $token = $request->bearerToken();
        if (! is_string($token) || trim($token) === '') {
            return null;
        }

        $accessToken = PersonalAccessToken::findValidToken($token);

        return $accessToken?->user;
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
            'content_id' => $review->content_id,
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
