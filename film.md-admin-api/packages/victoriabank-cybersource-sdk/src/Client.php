<?php
declare(strict_types=1);

namespace Victoriabank\CyberSource;

use Victoriabank\CyberSource\Exception\UnknownStatusException;

/**
 * Built-in CyberSource REST transport: JWT v2, HS256 shared secret. Same claim set as the official SDKs and as the
 * Node.js SDK of Victoriabank (byte-for-byte identical JWTs, verified by tests). No Message-Level Encryption: use it with
 * the VB simulator and for debugging; in CyberSource TEST and production use OfficialSdkTransport (JWT v2 + MLE).
 */
final class Client implements Transport
{
    public const HOSTS = ['test' => 'apitest.cybersource.com', 'production' => 'api.cybersource.com'];
    public readonly string $host;
    private readonly string $base;

    public function __construct(
        private readonly string $merchantId,   // transacting MID
        private readonly string $keyId,        // shared secret key ID
        private readonly string $secretB64,    // shared secret, Base64 as shown in Business Center
        string $environment = 'test',
        ?string $baseUrl = null,               // e.g. http://127.0.0.1:8770 for the VB simulator
        private readonly int $timeoutSec = 65, // longer than the gateway's own processing window
        bool $allowProductionWithoutMle = false,
    ) {
        // Victoriabank R8: production requires Message-Level Encryption, which this client does not implement
        if ($environment === 'production' && !$allowProductionWithoutMle) {   // also with a $baseUrl (S-08)
            throw new \InvalidArgumentException('The built-in client has no Message-Level Encryption: in production use OfficialSdkTransport (official CyberSource SDK with MLE)');
        }
        foreach (['merchantId' => $merchantId, 'keyId' => $keyId, 'sharedSecret' => $secretB64] as $k => $v) {
            if ($v === '') {
                throw new \InvalidArgumentException("Missing CyberSource configuration: $k");
            }
        }
        if ($baseUrl === null || $baseUrl === '') {
            if (!isset(self::HOSTS[$environment])) {
                throw new \InvalidArgumentException("Unknown environment $environment");
            }
            $baseUrl = 'https://' . self::HOSTS[$environment];
        }
        $this->base = self::checkBaseUrl($baseUrl);
        $p = parse_url($this->base);
        $this->host = $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    }

    public function baseUrl(): string { return $this->base; }

    /** https only; plain http only for a local endpoint (VB simulator on localhost / 127.0.0.1 / ::1). */
    public static function checkBaseUrl(string $baseUrl): string
    {
        $p = parse_url($baseUrl);
        if (!is_array($p) || empty($p['scheme']) || empty($p['host'])) {
            throw new \InvalidArgumentException("Invalid baseUrl $baseUrl");
        }
        $scheme = strtolower($p['scheme']);
        $local = in_array(strtolower($p['host']), ['localhost', '127.0.0.1', '[::1]', '::1'], true);
        if ($scheme !== 'https' && !($scheme === 'http' && $local)) {
            throw new \InvalidArgumentException("baseUrl must use https (http only for localhost / 127.0.0.1): $baseUrl");
        }
        return rtrim($baseUrl, '/');
    }

    public static function b64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    /** JWS for one request. $nowSec / $jti are injectable for tests. */
    public function buildJwt(string $method, string $path, ?string $body, ?int $nowSec = null, ?string $jti = null): string
    {
        $verb = strtolower($method);
        $iat = $nowSec ?? time();
        $claims = [];
        if (in_array($verb, ['post', 'put', 'patch'], true)) {
            $claims['digest'] = base64_encode(hash('sha256', $body ?? '', true));
            $claims['digest-algorithm'] = 'SHA-256';
        }
        $claims += [
            'iat' => $iat,
            'exp' => $iat + 120,
            'request-host' => $this->host,
            'request-resource-path' => $path,
            'request-method' => $verb,
            'iss' => $this->merchantId,
            'jti' => $jti ?? self::uuid4(),
            'v-c-jwt-version' => '2',
            'v-c-merchant-id' => $this->merchantId,
        ];
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        $input = self::b64url(json_encode(['typ' => 'JWT', 'alg' => 'HS256', 'kid' => $this->keyId], $flags))
            . '.' . self::b64url(json_encode($claims, $flags));
        $key = base64_decode($this->secretB64, true);
        if ($key === false || $key === '') {
            throw new \RuntimeException('The shared secret is empty or not Base64');
        }
        return $input . '.' . self::b64url(hash_hmac('sha256', $input, $key, true));
    }

    public function request(string $method, string $path, ?array $body = null): array
    {
        $json = $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSec,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json;charset=utf-8',
                'Accept: application/hal+json;charset=utf-8',
                'Authorization: Bearer ' . $this->buildJwt($method, $path, $json),
            ],
        ]);
        if ($json !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);   // the exact bytes that were digested
        }
        Jwt::caBundleFromEnv();
        if (Jwt::$caBundle !== null) {
            curl_setopt($ch, CURLOPT_CAINFO, Jwt::$caBundle);   // e.g. the simulator's sim-cert.pem in --tls mode
        }
        $raw = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_errno($ch);
        curl_close($ch);
        if ($err !== 0 || $status === 0) {
            throw new UnknownStatusException("CyberSource status unknown ($method $path, curl error $err)");
        }
        $data = json_decode((string) $raw, true);
        $decoded = is_array($data) ? $data : (string) $raw;    // session endpoints answer with the JWT as text
        if ($status >= 500) {                                  // every 5xx: the operation may have been processed
            throw new UnknownStatusException("CyberSource status unknown ($method $path, HTTP $status)", $status, is_array($decoded) ? $decoded : null);
        }
        return [$status, $decoded];
    }

    public static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
