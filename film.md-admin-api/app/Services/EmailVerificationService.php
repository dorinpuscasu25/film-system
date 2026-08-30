<?php

namespace App\Services;

use App\Mail\PasswordResetCodeMail;
use App\Mail\RegistrationVerificationCodeMail;
use App\Models\EmailVerificationCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class EmailVerificationService
{
    public function issueRegistrationCode(User $user): EmailVerificationCode
    {
        return DB::transaction(function () use ($user): EmailVerificationCode {
            EmailVerificationCode::query()
                ->where('user_id', $user->id)
                ->where('purpose', EmailVerificationCode::PURPOSE_REGISTRATION)
                ->whereNull('consumed_at')
                ->delete();

            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $token = bin2hex(random_bytes(32));
            $expiresInMinutes = (int) config('auth.registration_code_ttl_minutes', 15);
            $expiresAt = now()->addMinutes($expiresInMinutes);

            $verification = EmailVerificationCode::query()->create([
                'user_id' => $user->id,
                'email' => $user->email,
                'purpose' => EmailVerificationCode::PURPOSE_REGISTRATION,
                'code_hash' => hash('sha256', $code),
                'token_hash' => hash('sha256', $token),
                'expires_at' => $expiresAt,
                'meta' => [
                    'delivery' => 'email',
                ],
            ]);

            Mail::to($user->email)->locale($this->localeFor($user))->send(new RegistrationVerificationCodeMail(
                user: $user,
                code: $code,
                verificationUrl: route('auth.register.confirm', ['token' => $token]),
                expiresInMinutes: $expiresInMinutes,
            ));

            return $verification;
        });
    }

    public function consumeRegistrationCode(string $email, string $code): User
    {
        return DB::transaction(function () use ($email, $code): User {
            $normalizedEmail = strtolower(trim($email));
            $user = User::query()
                ->where('email', $normalizedEmail)
                ->where('status', 'pending_verification')
                ->lockForUpdate()
                ->first();

            if ($user === null) {
                throw ValidationException::withMessages([
                    'email' => ['No pending registration was found for this email.'],
                ]);
            }

            $verification = EmailVerificationCode::query()
                ->where('user_id', $user->id)
                ->where('purpose', EmailVerificationCode::PURPOSE_REGISTRATION)
                ->active()
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($verification === null || ! hash_equals($verification->code_hash, hash('sha256', trim($code)))) {
                throw ValidationException::withMessages([
                    'code' => ['The confirmation code is invalid or expired.'],
                ]);
            }

            $verification->forceFill(['consumed_at' => now()])->save();

            return $user;
        });
    }

    public function consumeRegistrationToken(string $token): User
    {
        return DB::transaction(function () use ($token): User {
            $tokenHash = hash('sha256', $token);
            $candidate = EmailVerificationCode::query()
                ->where('purpose', EmailVerificationCode::PURPOSE_REGISTRATION)
                ->where('token_hash', $tokenHash)
                ->active()
                ->first(['id', 'user_id']);

            $user = $candidate === null ? null : User::query()
                ->whereKey($candidate->user_id)
                ->where('status', 'pending_verification')
                ->lockForUpdate()
                ->first();

            $verification = $candidate === null ? null : EmailVerificationCode::query()
                ->whereKey($candidate->id)
                ->where('token_hash', $tokenHash)
                ->active()
                ->lockForUpdate()
                ->first();

            if ($verification === null || $user === null) {
                throw ValidationException::withMessages([
                    'token' => ['The confirmation link is invalid, expired, or has already been used.'],
                ]);
            }

            $verification->forceFill(['consumed_at' => now()])->save();

            return $user;
        });
    }

    /**
     * Issues a 6-digit password reset code.
     *
     * Storefront customers reset by code rather than by emailed link: the same
     * flow then works identically in the browser and in the mobile apps, where
     * a web reset page would need deep linking to come back.
     */
    public function issuePasswordResetCode(User $user): EmailVerificationCode
    {
        return DB::transaction(function () use ($user): EmailVerificationCode {
            EmailVerificationCode::query()
                ->where('user_id', $user->id)
                ->where('purpose', EmailVerificationCode::PURPOSE_PASSWORD_RESET)
                ->whereNull('consumed_at')
                ->delete();

            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expiresInMinutes = (int) config('auth.password_reset_code_ttl_minutes', 15);
            $expiresAt = now()->addMinutes($expiresInMinutes);

            $verification = EmailVerificationCode::query()->create([
                'user_id' => $user->id,
                'email' => $user->email,
                'purpose' => EmailVerificationCode::PURPOSE_PASSWORD_RESET,
                'code_hash' => hash('sha256', $code),
                'expires_at' => $expiresAt,
                'meta' => [
                    'delivery' => 'email',
                ],
            ]);

            Mail::to($user->email)->locale($this->localeFor($user))->send(new PasswordResetCodeMail(
                user: $user,
                code: $code,
                expiresInMinutes: $expiresInMinutes,
            ));

            return $verification;
        });
    }

    /**
     * Validates the reset code and sets the new password.
     *
     * Every existing API token is revoked so a session that was open on a
     * stolen device cannot survive the reset.
     */
    public function consumePasswordResetCode(string $email, string $code, string $password): User
    {
        return DB::transaction(function () use ($email, $code, $password): User {
            $normalizedEmail = strtolower(trim($email));
            $user = User::query()
                ->where('email', $normalizedEmail)
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            $verification = $user === null ? null : EmailVerificationCode::query()
                ->where('user_id', $user->id)
                ->where('purpose', EmailVerificationCode::PURPOSE_PASSWORD_RESET)
                ->active()
                ->latest('id')
                ->lockForUpdate()
                ->first();

            if ($user === null || $verification === null || ! hash_equals($verification->code_hash, hash('sha256', trim($code)))) {
                throw ValidationException::withMessages([
                    'code' => ['The reset code is invalid or expired.'],
                ]);
            }

            $verification->forceFill(['consumed_at' => now()])->save();
            $user->forceFill(['password' => $password])->save();
            $user->apiTokens()->delete();

            return $user;
        });
    }

    private function localeFor(User $user): string
    {
        return in_array($user->preferred_locale, ['ro', 'ru', 'en'], true)
            ? $user->preferred_locale
            : 'ro';
    }
}
