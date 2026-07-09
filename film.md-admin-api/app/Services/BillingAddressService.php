<?php

namespace App\Services;

use App\Models\BillingAddress;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class BillingAddressService
{
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
