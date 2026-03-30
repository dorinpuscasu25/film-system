<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    /**
     * Validate and create a newly registered user.
     *
     * @param  array<string, string>  $input
     */
    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        return DB::transaction(function () use ($input): User {
            $user = User::query()->create([
                'name' => $input['name'],
                'email' => strtolower($input['email']),
                'password' => $input['password'],
                'preferred_locale' => 'ro',
                'status' => 'active',
                'email_verified_at' => now(),
                'last_seen_at' => now(),
            ]);

            $viewerRole = Role::query()->where('is_default', true)->first();

            if ($viewerRole !== null) {
                $user->roles()->sync([$viewerRole->id]);
            }

            return $user;
        });
    }
}
