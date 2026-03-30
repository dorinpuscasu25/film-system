<?php

namespace App\Http\Controllers\Api;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Services\AccountProfileService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingsController extends ApiController
{
    use PasswordValidationRules;
    use ProfileValidationRules;

    public function __construct(
        protected WalletService $wallets,
        protected AccountProfileService $profiles,
    ) {
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            ...$this->profileRules($user->id),
            'preferred_locale' => ['nullable', Rule::in(['en', 'ro', 'ru'])],
            'avatar_url' => ['nullable', 'url', 'max:2048'],
        ]);

        $user->fill($validated);
        $user->save();
        $this->wallets->ensureWallet($user);
        $this->profiles->ensureDefaultProfile($user);
        $user->loadMissing('roles.permissions', 'wallet', 'profiles.favorites');

        return response()->json([
            'user' => $this->userData($user),
        ]);
    }

    public function updatePassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'current_password' => $this->currentPasswordRules(),
            'password' => $this->passwordRules(),
        ]);

        $user = $request->user();
        $user->forceFill(['password' => $validated['password']])->save();

        return response()->json([
            'message' => 'Password updated successfully.',
        ]);
    }
}
