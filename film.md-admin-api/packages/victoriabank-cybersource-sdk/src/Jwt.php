<?php
declare(strict_types=1);

namespace Victoriabank\CyberSource;

/**
 * RS256 verification of the JWTs issued by CyberSource: capture context (Microform / Unified Checkout), transient token,
 * Unified Checkout result. The public key is taken by `kid` from GET {base}/flex/v2/public-keys/{kid} (no authentication)
 * or given as the JWK of the capture context. Any algorithm other than RS256 is rejected (no "none", no HS256 confusion).
 */
final class Jwt
{
    /** @var array<string,string> kid => PEM */
    private static array $cache = [];

    /**
     * Extra trusted CA certificate (PEM file) for downloading public keys - only for a test endpoint with its own
     * certificate (e.g. the VB simulator started with --tls). TLS verification is never switched off.
     */
    public static ?string $caBundle = null;

    /** Initializes $caBundle from the environment variable VB_CA_BUNDLE (PEM file), when set. */
    public static function caBundleFromEnv(): void
    {
        $f = getenv('VB_CA_BUNDLE');
        if (is_string($f) && $f !== '' && self::$caBundle === null) {
            self::$caBundle = $f;
        }
    }

    public static function b64urlDecode(string $s): string
    {
        $d = base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
        if ($d === false) {
            throw new \InvalidArgumentException('Invalid Base64url');
        }
        return $d;
    }

    /** @return array{header:array,payload:array,parts:string[]} */
    public static function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new \InvalidArgumentException('Not a JWS (expected 3 parts)');
        }
        return ['header' => json_decode(self::b64urlDecode($parts[0]), true, 64, JSON_THROW_ON_ERROR),
            'payload' => json_decode(self::b64urlDecode($parts[1]), true, 512, JSON_THROW_ON_ERROR), 'parts' => $parts];
    }

    /** DER/PEM public key from an RSA JWK (n, e). */
    public static function jwkToPem(array $jwk): string
    {
        $int = static function (string $b): string {
            if (ord($b[0]) > 0x7f) {
                $b = "\x00" . $b;
            }
            return "\x02" . self::len(strlen($b)) . $b;
        };
        $rsa = $int(self::b64urlDecode($jwk['n'])) . $int(self::b64urlDecode($jwk['e']));
        $rsa = "\x30" . self::len(strlen($rsa)) . $rsa;
        $bit = "\x03" . self::len(strlen($rsa) + 1) . "\x00" . $rsa;
        $alg = "\x30\x0d\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01\x05\x00";   // rsaEncryption, NULL
        $spki = "\x30" . self::len(strlen($alg . $bit)) . $alg . $bit;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($spki), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function len(int $n): string
    {
        if ($n < 0x80) {
            return chr($n);
        }
        $b = ltrim(pack('N', $n), "\x00");
        return chr(0x80 | strlen($b)) . $b;
    }

    /** Pins a CyberSource public key (JWK) for a kid - e.g. from your own key cache - so it is not fetched again. */
    public static function trustKey(string $baseUrl, string $kid, array $jwk): void
    {
        self::$cache[$baseUrl . '|' . $kid] = self::jwkToPem($jwk);
    }

    private static function fetchPem(string $baseUrl, string $kid): string
    {
        // same kid format as the Node.js SDK: letters, digits, '_', '-', '.', not starting with '.'
        if (!preg_match('/^[A-Za-z0-9_-][A-Za-z0-9._-]{0,127}$/D', $kid)) {
            throw new \InvalidArgumentException('Invalid kid');
        }
        $key = $baseUrl . '|' . $kid;
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        self::caBundleFromEnv();
        $ch = curl_init(rtrim($baseUrl, '/') . '/flex/v2/public-keys/' . rawurlencode($kid));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
        if (self::$caBundle !== null) {
            curl_setopt($ch, CURLOPT_CAINFO, self::$caBundle);
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $jwk = json_decode((string) $raw, true);
        if ($status !== 200 || !is_array($jwk) || empty($jwk['n']) || empty($jwk['e'])) {
            $hint = $status === 0 && str_starts_with($baseUrl, 'https://') && self::$caBundle === null
                ? ' - for a test endpoint with its own certificate (VB simulator --tls) set Jwt::$caBundle = the PEM file, or the environment variable VB_CA_BUNDLE' : '';
            throw new \RuntimeException("Public key $kid not available (HTTP $status)$hint");
        }
        return self::$cache[$key] = self::jwkToPem($jwk);
    }

    /**
     * @param array{jwk?:array,baseUrl?:string,requireExp?:bool} $opts
     * @return array the verified payload
     */
    public static function verify(string $token, array $opts): array
    {
        $j = self::decode($token);
        if (($j['header']['alg'] ?? '') !== 'RS256') {
            throw new \RuntimeException('Disallowed JWT alg: ' . ($j['header']['alg'] ?? 'none'));
        }
        if (isset($opts['jwk'])) {
            $pem = self::jwkToPem($opts['jwk']);
        } else {
            if (empty($j['header']['kid'])) {
                throw new \RuntimeException('JWT header has no kid');
            }
            $pem = self::fetchPem((string) ($opts['baseUrl'] ?? ''), (string) $j['header']['kid']);
        }
        $ok = openssl_verify($j['parts'][0] . '.' . $j['parts'][1], self::b64urlDecode($j['parts'][2]), $pem, OPENSSL_ALGO_SHA256);
        if ($ok !== 1) {
            throw new \RuntimeException('JWT signature verification failed');
        }
        $exp = $j['payload']['exp'] ?? null;
        if (!is_int($exp)) {
            if (($opts['requireExp'] ?? true) !== false) {
                throw new \RuntimeException('JWT has no exp');
            }
        } elseif ($exp < time() - 30) {
            throw new \RuntimeException('JWT expired');
        }
        return $j['payload'];
    }

    /** clientLibrary + clientLibraryIntegrity of a capture context (load the library from here, never hard-code it). */
    public static function clientLibraryOf(array $payload): array
    {
        $d = $payload['ctx'][0]['data'] ?? [];
        if (empty($d['clientLibrary']) || empty($d['clientLibraryIntegrity'])) {
            throw new \RuntimeException('Capture context has no clientLibrary');
        }
        return ['clientLibrary' => $d['clientLibrary'], 'clientLibraryIntegrity' => $d['clientLibraryIntegrity']];
    }
}
