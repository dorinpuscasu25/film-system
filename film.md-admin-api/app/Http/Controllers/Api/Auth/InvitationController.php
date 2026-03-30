<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\ApiController;
use App\Models\Invitation;
use App\Models\PersonalAccessToken;
use App\Models\Role;
use App\Models\User;
use App\Services\AccountProfileService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class InvitationController extends ApiController
{
    public function __construct(
        protected AccountProfileService $profiles,
        protected WalletService $wallets,
    ) {
    }

    public function show(string $token): JsonResponse
    {
        $invitation = Invitation::findValidToken($token);

        if ($invitation === null || $invitation->status !== 'pending' || $invitation->isExpired()) {
            return response()->json([
                'message' => 'This invitation is no longer valid.',
            ], Response::HTTP_NOT_FOUND);
        }

        $roles = Role::query()
            ->whereIn('id', $invitation->role_ids ?? [])
            ->get();

        return response()->json([
            'invitation' => $this->invitationData($invitation, $roles),
        ]);
    }

    public function accept(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'preferred_locale' => ['nullable', Rule::in(['en', 'ro', 'ru'])],
        ]);

        $invitation = Invitation::findValidToken($token);

        if ($invitation === null || $invitation->status !== 'pending' || $invitation->isExpired()) {
            return response()->json([
                'message' => 'This invitation is no longer valid.',
            ], Response::HTTP_NOT_FOUND);
        }

        $user = DB::transaction(function () use ($invitation, $validated): User {
            $user = User::query()->firstOrNew([
                'email' => strtolower($invitation->email),
            ]);

            $user->fill([
                'name' => $validated['name'],
                'password' => $validated['password'],
                'preferred_locale' => $validated['preferred_locale'] ?? $user->preferred_locale ?? 'ro',
                'status' => 'active',
                'email_verified_at' => $user->email_verified_at ?? now(),
                'last_seen_at' => now(),
            ]);
            $user->save();

            $roleIds = collect($invitation->role_ids)
                ->filter()
                ->map(fn (mixed $roleId): int => (int) $roleId)
                ->values()
                ->all();

            if ($roleIds === []) {
                $roleIds = Role::query()
                    ->where('is_default', true)
                    ->pluck('id')
                    ->all();
            }

            $user->roles()->syncWithoutDetaching($roleIds);

            $invitation->forceFill([
                'status' => 'accepted',
                'accepted_at' => now(),
            ])->save();

            return $user->fresh();
        });

        $this->wallets->ensureWallet($user);
        $this->profiles->ensureDefaultProfile($user);
        $user->loadMissing('roles.permissions', 'wallet', 'profiles.favorites');

        [, $plainTextToken] = PersonalAccessToken::issue($user, 'invite-accept');

        return response()->json([
            'token' => $plainTextToken,
            'user' => $this->userData($user),
            'redirect_app' => $user->hasAdminPanelAccess() ? 'admin' : 'client',
        ]);
    }
}
