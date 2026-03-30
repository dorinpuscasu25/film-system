<?php

namespace App\Http\Controllers\Api;

use App\Models\AccountProfile;
use App\Models\Content;
use App\Services\AccountProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class StorefrontProfileController extends ApiController
{
    public function __construct(
        protected AccountProfileService $profiles,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'avatar_color' => ['nullable', 'string', 'max:120'],
            'avatar_label' => ['nullable', 'string', 'max:16'],
            'is_kids' => ['nullable', 'boolean'],
        ]);

        $profile = $this->profiles->create($request->user(), $validated);
        $profile->loadMissing('favorites');

        return response()->json([
            'profile' => $this->accountProfileData($profile),
            'profiles' => $request->user()->profiles()->with('favorites')->orderByDesc('is_default')->orderBy('sort_order')->get()
                ->map(fn (AccountProfile $item) => $this->accountProfileData($item))
                ->values(),
        ], Response::HTTP_CREATED);
    }

    public function update(Request $request, AccountProfile $profile): JsonResponse
    {
        $this->abortIfProfileDoesNotBelongToUser($request, $profile);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'avatar_color' => ['nullable', 'string', 'max:120'],
            'avatar_label' => ['nullable', 'string', 'max:16'],
            'is_kids' => ['nullable', 'boolean'],
        ]);

        $profile = $this->profiles->update($profile, $validated);
        $profile->loadMissing('favorites');

        return response()->json([
            'profile' => $this->accountProfileData($profile),
            'profiles' => $request->user()->profiles()->with('favorites')->orderByDesc('is_default')->orderBy('sort_order')->get()
                ->map(fn (AccountProfile $item) => $this->accountProfileData($item))
                ->values(),
        ]);
    }

    public function destroy(Request $request, AccountProfile $profile): JsonResponse
    {
        $this->abortIfProfileDoesNotBelongToUser($request, $profile);
        $this->profiles->delete($profile);

        return response()->json([
            'profiles' => $request->user()->profiles()->with('favorites')->orderByDesc('is_default')->orderBy('sort_order')->get()
                ->map(fn (AccountProfile $item) => $this->accountProfileData($item))
                ->values(),
        ]);
    }

    public function favorite(Request $request, AccountProfile $profile, string $identifier): JsonResponse
    {
        $this->abortIfProfileDoesNotBelongToUser($request, $profile);
        $content = $this->resolvePublishedContent($identifier);

        if ($content === null) {
            return response()->json([
                'message' => 'The requested content was not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $this->profiles->toggleFavorite($profile, $content, true);
        $profile->load('favorites');

        return response()->json([
            'favorites_by_profile' => $this->favoriteMapData($request->user()->profiles()->with('favorites')->get()),
        ]);
    }

    public function unfavorite(Request $request, AccountProfile $profile, string $identifier): JsonResponse
    {
        $this->abortIfProfileDoesNotBelongToUser($request, $profile);
        $content = $this->resolvePublishedContent($identifier);

        if ($content === null) {
            return response()->json([
                'message' => 'The requested content was not found.',
            ], Response::HTTP_NOT_FOUND);
        }

        $this->profiles->toggleFavorite($profile, $content, false);
        $profile->load('favorites');

        return response()->json([
            'favorites_by_profile' => $this->favoriteMapData($request->user()->profiles()->with('favorites')->get()),
        ]);
    }

    protected function abortIfProfileDoesNotBelongToUser(Request $request, AccountProfile $profile): void
    {
        abort_unless($profile->user_id === $request->user()->id, Response::HTTP_NOT_FOUND);
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
}
