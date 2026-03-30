<?php

namespace App\Services;

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
            $expiresAt = now()->addMinutes((int) config('auth.registration_code_ttl_minutes', 15));

            $verification = EmailVerificationCode::query()->create([
                'user_id' => $user->id,
                'email' => $user->email,
                'purpose' => EmailVerificationCode::PURPOSE_REGISTRATION,
                'code_hash' => hash('sha256', $code),
                'expires_at' => $expiresAt,
                'meta' => [
                    'delivery' => 'email',
                ],
            ]);

            Mail::to($user->email)->send(new RegistrationVerificationCodeMail(
                user: $user,
                code: $code,
                expiresAtLabel: $expiresAt->format('Y-m-d H:i'),
            ));

            return $verification;
        });
    }

    public function consumeRegistrationCode(string $email, string $code): User
    {
        $normalizedEmail = strtolower(trim($email));
        $user = User::query()
            ->where('email', $normalizedEmail)
            ->where('status', 'pending_verification')
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
            ->first();

        if ($verification === null || ! hash_equals($verification->code_hash, hash('sha256', trim($code)))) {
            throw ValidationException::withMessages([
                'code' => ['The confirmation code is invalid or expired.'],
            ]);
        }

        $verification->forceFill([
            'consumed_at' => now(),
        ])->save();

        return $user;
    }
}
