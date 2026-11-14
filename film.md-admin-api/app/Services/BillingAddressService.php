<?php

namespace App\Services;

use App\Models\BillingAddress;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BillingAddressService
{
    /**
     * Validation rules for a billing address, shared by the top-up form and the profile editor.
     *
     * @return array<string, array<int, string>>
     */
    public static function rules(string $prefix = ''): array
    {
        return [
            $prefix.'id' => ['nullable', 'integer'],
            $prefix.'full_name' => ['required', 'string', 'min:2', 'max:255'],
            $prefix.'country_code' => ['required', 'string', 'size:2', 'regex:/^[A-Za-z]{2}$/'],
            $prefix.'administrative_area' => ['nullable', 'string', 'max:255'],
            $prefix.'city' => ['required', 'string', 'min:2', 'max:255'],
            $prefix.'postal_code' => ['required', 'string', 'min:2', 'max:32', 'regex:/^[A-Za-z0-9][A-Za-z0-9\\-\\s]{1,31}$/'],
            $prefix.'address_line1' => ['required', 'string', 'min:3', 'max:255'],
            $prefix.'address_line2' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function upsertDefault(User $user, array $payload): BillingAddress
    {
        $data = $this->normalize($payload);
        $addressId = isset($payload['id']) ? (int) $payload['id'] : null;

        return DB::transaction(function () use ($user, $data, $addressId): BillingAddress {
            $address = $addressId !== null && $addressId > 0
                ? $user->billingAddresses()->whereKey($addressId)->first()
                : null;

            $user->billingAddresses()
                ->where('is_default', true)
                ->when($address !== null, fn ($query) => $query->whereKeyNot($address->id))
                ->update(['is_default' => false]);

            if ($address !== null) {
                $address->forceFill([
                    ...$data,
                    'is_default' => true,
                ])->save();

                return $address->fresh();
            }

            return $user->billingAddresses()
                ->create([
                    ...$data,
                    'is_default' => true,
                ]);
        });
    }

    public function snapshot(BillingAddress $address): array
    {
        return [
            'full_name' => $address->full_name,
            'country_code' => $address->country_code,
            'administrative_area' => $address->administrative_area,
            'city' => $address->city,
            'postal_code' => $address->postal_code,
            'address_line1' => $address->address_line1,
            'address_line2' => $address->address_line2,
        ];
    }

    protected function normalize(array $payload): array
    {
        return [
            'full_name' => $this->cleanString($payload['full_name'] ?? ''),
            'country_code' => strtoupper($this->cleanString($payload['country_code'] ?? '')),
            'administrative_area' => $this->nullableString($payload['administrative_area'] ?? null),
            'city' => $this->cleanString($payload['city'] ?? ''),
            'postal_code' => strtoupper($this->cleanString($payload['postal_code'] ?? '')),
            'address_line1' => $this->cleanString($payload['address_line1'] ?? ''),
            'address_line2' => $this->nullableString($payload['address_line2'] ?? null),
        ];
    }

    protected function cleanString(mixed $value): string
    {
        return preg_replace('/\s+/u', ' ', trim((string) $value)) ?? '';
    }

    protected function nullableString(mixed $value): ?string
    {
        $cleaned = $this->cleanString($value);

        return $cleaned === '' ? null : $cleaned;
    }
}
