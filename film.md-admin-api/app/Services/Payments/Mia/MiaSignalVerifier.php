<?php

namespace App\Services\Payments\Mia;

use Illuminate\Support\Facades\Log;

/**
 * Verifies the RS256 JWT Victoriabank posts to /api/signals against the bank's
 * certificate(s) (VBCA.crt). Several certificates may be configured at once so a
 * rotation never breaks verification.
 */
class MiaSignalVerifier
{
    /**
     * @return array<string, mixed>|null the signal payload, or null when the token is not
     *                                   a valid RS256 JWT signed by a configured certificate
     */
    public function verify(string $jwt): ?array
    {
        $parts = explode('.', trim($jwt));
        if (count($parts) !== 3) {
            return null;
        }

        [$encodedHeader, $encodedPayload, $encodedSignature] = $parts;
        $header = json_decode((string) $this->base64UrlDecode($encodedHeader), true);

        // Only RS256: reject "none", HS256 and anything else a forger might try.
        if (! is_array($header) || ($header['alg'] ?? null) !== 'RS256') {
            return null;
        }

        $signature = $this->base64UrlDecode($encodedSignature);
        if ($signature === null || $signature === '') {
            return null;
        }

        foreach ($this->publicKeys() as $key) {
            if (openssl_verify($encodedHeader.'.'.$encodedPayload, $signature, $key, OPENSSL_ALGO_SHA256) === 1) {
                $payload = json_decode((string) $this->base64UrlDecode($encodedPayload), true);

                return is_array($payload) ? $payload : null;
            }
        }

        return null;
    }

    /**
     * @return array<int, \OpenSSLAsymmetricKey>
     */
    protected function publicKeys(): array
    {
        $keys = [];

        foreach ((array) config('services.mia.signal_certificates', []) as $path) {
            $pem = is_string($path) && is_readable($path) ? file_get_contents($path) : false;
            $key = $pem === false ? false : openssl_pkey_get_public($pem);

            if ($key === false) {
                Log::channel('payments')->error('MIA signal certificate could not be loaded', ['path' => $path]);

                continue;
            }

            $keys[] = $key;
        }

        return $keys;
    }

    protected function base64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/').str_repeat('=', (4 - strlen($value) % 4) % 4), true);

        return $decoded === false ? null : $decoded;
    }
}
