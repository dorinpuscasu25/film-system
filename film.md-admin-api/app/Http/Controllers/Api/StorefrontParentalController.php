<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Models\AccountProfile;
use App\Services\ParentalControlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StorefrontParentalController extends ApiController
{
    public function __construct(
        protected ParentalControlService $parental,
    ) {}

    public function setPin(Request $request, AccountProfile $profile): JsonResponse
    {
        $this->assertOwn($request, $profile);
        $data = $request->validate([
            'pin' => ['required', 'string', 'regex:/^\d{4,6}$/'],
            'max_age_rating' => ['nullable', 'string', 'in:G,PG,PG-13,R,NC-17,18+'],
        ]);
        $this->parental->setPin($profile, $data['pin']);
        if (array_key_exists('max_age_rating', $data)) {
            $profile->max_age_rating = $data['max_age_rating'];
            $profile->save();
        }

        return response()->json(['has_pin' => true, 'max_age_rating' => $profile->max_age_rating]);
    }

    public function clearPin(Request $request, AccountProfile $profile): JsonResponse
    {
        $this->assertOwn($request, $profile);
        // Require current PIN before clearing
        $data = $request->validate([
            'pin' => ['required', 'string', 'regex:/^\d{4,6}$/'],
        ]);
        if (! $this->parental->verifyAndUnlock($profile, $data['pin'], 1)) {
            return response()->json(['message' => 'PIN incorect.'], Response::HTTP_FORBIDDEN);
        }
        $this->parental->clearPin($profile);

        return response()->json(['has_pin' => false]);
    }

    public function unlock(Request $request, AccountProfile $profile): JsonResponse
    {
        $this->assertOwn($request, $profile);
        $data = $request->validate([
            'pin' => ['required', 'string', 'regex:/^\d{4,6}$/'],
        ]);
        if (! $this->parental->verifyAndUnlock($profile, $data['pin'])) {
            return response()->json(['message' => 'PIN incorect.'], Response::HTTP_FORBIDDEN);
        }

        return response()->json(['unlocked' => true, 'unlocked_for_seconds' => 1800]);
    }

    private function assertOwn(Request $request, AccountProfile $profile): void
    {
        if ($profile->user_id !== $request->user()?->id) {
            abort(Response::HTTP_FORBIDDEN, 'Profilul nu îți aparține.');
        }
    }
}
