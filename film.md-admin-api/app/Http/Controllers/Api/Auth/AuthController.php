<?php

namespace App\Http\Controllers\Api\Auth;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Http\Controllers\Api\ApiController;
use App\Models\EmailVerificationCode;
use App\Models\PersonalAccessToken;
use App\Models\Role;
use App\Models\User;
use App\Services\AccountProfileService;
use App\Services\EmailVerificationService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

class AuthController extends ApiController
{
    use PasswordValidationRules;
    use ProfileValidationRules;

    public function __construct(
        protected EmailVerificationService $emailVerification,
        protected AccountProfileService $profiles,
        protected WalletService $wallets,
    ) {
    }

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => $this->nameRules(),
            'email' => ['required', 'email', 'max:255'],
            'password' => $this->passwordRules(),
            'preferred_locale' => ['nullable', 'in:en,ro,ru'],
        ]);

        $email = strtolower($validated['email']);
        $existingUser = User::query()->where('email', $email)->first();

        if ($existingUser !== null && $existingUser->status !== 'pending_verification') {
            return response()->json([
                'message' => 'An account with this email already exists.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $user = DB::transaction(function () use ($validated, $existingUser, $email): User {
            $user = $existingUser ?? new User();
            $user->fill([
                'name' => $validated['name'],
                'email' => $email,
                'password' => $validated['password'],
                'preferred_locale' => $validated['preferred_locale'] ?? 'ro',
                'status' => 'pending_verification',
                'email_verified_at' => null,
                'last_seen_at' => null,
            ]);
            $user->save();

            return $user->fresh();
        });

        $verification = $this->emailVerification->issueRegistrationCode($user);

        return response()->json([
            'message' => 'We sent a confirmation code to your email address.',
            'email' => $user->email,
            'expires_at' => $verification->expires_at?->toIso8601String(),
        ], Response::HTTP_ACCEPTED);
    }

    public function verifyRegistration(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'code' => ['required', 'digits:6'],
        ]);

        $viewerRole = Role::query()
            ->where('is_default', true)
            ->firstOrFail();

        $user = $this->emailVerification->consumeRegistrationCode($validated['email'], $validated['code']);

        $user = DB::transaction(function () use ($user, $viewerRole): User {
            $user->forceFill([
                'status' => 'active',
                'email_verified_at' => now(),
                'last_seen_at' => now(),
            ])->save();

            $user->roles()->syncWithoutDetaching([$viewerRole->id]);

            return $user->fresh();
        });

        $this->wallets->ensureWallet($user);
        $this->profiles->ensureDefaultProfile($user);
        $user->loadMissing('roles.permissions', 'wallet', 'profiles.favorites');

        [, $plainTextToken] = PersonalAccessToken::issue($user, 'client-register');

        return response()->json([
            'token' => $plainTextToken,
            'user' => $this->userData($user),
        ], Response::HTTP_CREATED);
    }

    public function resendRegistrationCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
        ]);

        $user = User::query()
            ->where('email', strtolower($validated['email']))
            ->where('status', 'pending_verification')
            ->first();

        if ($user === null) {
            return response()->json([
                'message' => 'No pending registration was found for this email.',
            ], Response::HTTP_NOT_FOUND);
        }

        $verification = $this->emailVerification->issueRegistrationCode($user);

        return response()->json([
            'message' => 'A new confirmation code was sent.',
            'email' => $user->email,
            'expires_at' => $verification->expires_at?->toIso8601String(),
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'app' => ['nullable', 'in:admin,client'],
        ]);

        $user = User::query()
            ->where('email', strtolower($validated['email']))
            ->first();

        if ($user === null || ! Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid email or password.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($user->status === 'pending_verification') {
            return response()->json([
                'message' => 'Confirm your email with the verification code we sent before logging in.',
            ], Response::HTTP_FORBIDDEN);
        }

        if ($user->status === 'suspended') {
            return response()->json([
                'message' => 'This account is suspended. Contact an administrator.',
            ], Response::HTTP_FORBIDDEN);
        }

        $app = $validated['app'] ?? 'client';

        if ($app === 'admin' && ! $user->hasAdminPanelAccess()) {
            return response()->json([
                'message' => 'This account does not have access to the admin dashboard.',
            ], Response::HTTP_FORBIDDEN);
        }

        $user->forceFill(['last_seen_at' => now()])->saveQuietly();
        $this->wallets->ensureWallet($user);
        $this->profiles->ensureDefaultProfile($user);
        $user->loadMissing('roles.permissions', 'wallet', 'profiles.favorites');

        [, $plainTextToken] = PersonalAccessToken::issue($user, "{$app}-login");

        return response()->json([
            'token' => $plainTextToken,
            'user' => $this->userData($user),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $this->wallets->ensureWallet($user);
        $this->profiles->ensureDefaultProfile($user);
        $user->loadMissing('roles.permissions', 'wallet', 'profiles.favorites');

        return response()->json([
            'user' => $this->userData($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var \App\Models\PersonalAccessToken|null $token */
        $token = $request->attributes->get('currentAccessToken');

        if ($token !== null) {
            $token->delete();
        }

        return response()->json(status: Response::HTTP_NO_CONTENT);
    }
}
