<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Which app a purchase was made from (reports: per film × channel). Apps may say so with an
 * X-Filmoteca-Client header; otherwise it is read from the User-Agent ("Filmoteca-iOS/1.0").
 */
final class SalesChannel
{
    public const WEB = 'web';

    public const IOS = 'ios';

    public const ANDROID = 'android';

    public const TV = 'tv';

    public const ALL = [self::WEB, self::IOS, self::ANDROID, self::TV];

    public static function fromRequest(Request $request): string
    {
        $declared = strtolower(trim((string) $request->header('X-Filmoteca-Client', '')));
        if (in_array($declared, self::ALL, true)) {
            return $declared;
        }

        $agent = strtolower((string) $request->userAgent());

        return match (true) {
            str_contains($agent, 'filmoteca-ios') => self::IOS,
            str_contains($agent, 'filmoteca-tv') || str_contains($agent, 'smarttv') || str_contains($agent, 'smart-tv')
                || str_contains($agent, 'tizen') || str_contains($agent, 'web0s') || str_contains($agent, 'android tv') => self::TV,
            str_contains($agent, 'filmoteca-android') => self::ANDROID,
            default => self::WEB, // browsers, including mobile browsers on Android/iPhone
        };
    }
}
