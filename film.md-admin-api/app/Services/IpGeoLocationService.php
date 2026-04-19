<?php

namespace App\Services;

use Illuminate\Http\Request;

class IpGeoLocationService
{
    public function resolveCountryCode(Request $request): ?string
    {
        $candidates = [
            $request->header('CF-IPCountry'),
            $request->header('X-Country-Code'),
            data_get($request->attributes->all(), 'country_code'),
        ];

        foreach ($candidates as $candidate) {
            $value = strtoupper(trim((string) $candidate));
            if ($value !== '' && strlen($value) <= 5) {
                return $value;
            }
        }

        return null;
    }
}
