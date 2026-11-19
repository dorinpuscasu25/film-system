<?php
declare(strict_types=1);

namespace Victoriabank\CyberSource;

/**
 * CyberSource webhooks.
 *
 * Signature (Webhooks Implementation Guide, "Validating a Notification with the Digital Signature Key"):
 *   v-c-signature: t=<timestamp>;keyId=<key id>;sig=<Base64(HMAC-SHA256(Base64-decoded key, t + "." + raw body))>
 * No time window on t: the guide defines it as the creation time of the key. Deduplicate and confirm through the API.
 *
 * Message-Level Encryption: payment and Unified Checkout events require MLE. This SDK accepts a compact JWE
 * (alg RSA-OAEP-256, enc A256GCM) as the whole body or as the string value of `payload` (exact envelope: to be confirmed
 * in the CyberSource test environment). RSA-OAEP with SHA-256 needs phpseclib 3 (installed with the official SDK
 * cybersource/rest-client-php); without it decryptWebhook() throws a clear exception.
 * Order: verifySignature() on the RAW body -> decryptWebhook() -> confirm through GET /tss/v2/transactions/{id}.
 */
final class Webhook
{
    /** @return array<string,string> */
    public static function parseSignature(string $header): array
    {
        $out = [];
        foreach (array_filter(explode(';', preg_replace('/^v-c-signature:\s*/i', '', $header))) as $part) {
            $i = strpos($part, '=');
            if ($i !== false) {
                $out[trim(substr($part, 0, $i))] = rtrim(trim(substr($part, $i + 1)), '"');
            }
        }
        return $out;
    }

    /** The digital signature key, Base64-decoded. Throws on a key that is not strict Base64 or shorter than 16 bytes (S-06). */
    public static function keyBytes(string $keyB64): string
    {
        $s = trim($keyB64);
        $k = base64_decode($s, true);
        // canonical Base64 only (same check as the Node.js SDK): re-encoding gives back the key without padding differences
        if ($k === false || strlen($k) < 16 || rtrim(base64_encode($k), '=') !== rtrim($s, '=')) {
            throw new \InvalidArgumentException('Invalid webhook key: expected the Base64 digital signature key of the subscription (at least 16 bytes)');
        }
        return $k;
    }

    /**
     * $rawBody: the body exactly as received (php://input), before json_decode or decryption.
     * false for a missing/invalid signature or an empty key; throws for a key that is not valid Base64.
     */
    public static function verifySignature(?string $header, string $rawBody, string $keyB64, ?string $keyId = null): bool
    {
        if ($keyB64 === '') {
            return false;
        }
        $key = self::keyBytes($keyB64);
        if (!$header) {
            return false;
        }
        $p = self::parseSignature($header);
        if (empty($p['t']) || empty($p['sig']) || ($keyId !== null && $keyId !== '' && ($p['keyId'] ?? '') !== $keyId)) {
            return false;
        }
        $expected = hash_hmac('sha256', $p['t'] . '.' . $rawBody, $key, true);
        return hash_equals($expected, (string) base64_decode($p['sig']));
    }

    public static function isCompactJwe(mixed $s): bool
    {
        return is_string($s) && preg_match('/^[A-Za-z0-9_-]+(\.[A-Za-z0-9_-]+){4}$/D', trim($s)) === 1;
    }

    /** Compact JWE (RSA-OAEP-256 + A256GCM) -> plaintext, with the private key (PEM) of the MLE certificate of the subscription. */
    public static function decryptJwe(string $compact, string $privateKeyPem, ?string $passphrase = null): string
    {
        [$h, $ek, $iv, $ct, $tag] = explode('.', trim($compact));
        $header = json_decode(Jwt::b64urlDecode($h), true, 16, JSON_THROW_ON_ERROR);
        if (($header['alg'] ?? null) !== 'RSA-OAEP-256') {
            throw new \RuntimeException('Unsupported JWE alg ' . ($header['alg'] ?? 'none') . ' (expected RSA-OAEP-256)');
        }
        if (($header['enc'] ?? null) !== 'A256GCM') {
            throw new \RuntimeException('Unsupported JWE enc ' . ($header['enc'] ?? 'none') . ' (expected A256GCM)');
        }
        if (!class_exists(\phpseclib3\Crypt\PublicKeyLoader::class)) {
            throw new \RuntimeException('Webhook decryption (RSA-OAEP-256) needs phpseclib 3: install the official SDK (composer require cybersource/rest-client-php, which brings phpseclib/phpseclib ~3.0) or composer require phpseclib/phpseclib:~3.0');
        }
        $key = \phpseclib3\Crypt\PublicKeyLoader::load($privateKeyPem, $passphrase ?? false);
        if (!$key instanceof \phpseclib3\Crypt\RSA\PrivateKey) {
            throw new \InvalidArgumentException('The MLE key must be an RSA private key');
        }
        $cek = $key->withPadding(\phpseclib3\Crypt\RSA::ENCRYPTION_OAEP)->withHash('sha256')->withMGFHash('sha256')->decrypt(Jwt::b64urlDecode($ek));
        if (!is_string($cek) || strlen($cek) !== 32) {
            throw new \RuntimeException('JWE content key could not be decrypted (wrong key?)');
        }
        $ivB = Jwt::b64urlDecode($iv);
        $tagB = Jwt::b64urlDecode($tag);
        if (strlen($ivB) !== 12 || strlen($tagB) !== 16) {
            throw new \RuntimeException('Invalid JWE IV or tag length');
        }
        $plain = openssl_decrypt(Jwt::b64urlDecode($ct), 'aes-256-gcm', $cek, OPENSSL_RAW_DATA, $ivB, $tagB, $h);
        if ($plain === false) {
            throw new \RuntimeException('JWE authentication tag mismatch (tampered or wrong key)');
        }
        if (isset($header['zip'])) {
            if ($header['zip'] !== 'DEF' || ($plain = gzinflate($plain)) === false) {
                throw new \RuntimeException('Unsupported or invalid JWE compression');
            }
        }
        return $plain;
    }

    /**
     * Notification -> event array with a plaintext 'payload'. Accepts a compact JWE as the whole body, a JSON event whose
     * 'payload' is a compact JWE string, or a plaintext JSON event (returned as is unless $requireEncrypted).
     * $encrypted (by reference) tells whether the notification arrived encrypted.
     */
    public static function decryptWebhook(string $rawBody, string $privateKeyPem, ?string $passphrase = null, bool $requireEncrypted = false,
        ?bool &$encrypted = null): array
    {
        $raw = trim($rawBody);
        $encrypted = false;
        if (self::isCompactJwe($raw)) {
            $evt = json_decode(self::decryptJwe($raw, $privateKeyPem, $passphrase), true, 512, JSON_THROW_ON_ERROR);
            $encrypted = true;
        } else {
            $evt = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        }
        if (!is_array($evt)) {
            throw new \RuntimeException('Webhook notification is not a JSON object');
        }
        if (self::isCompactJwe($evt['payload'] ?? null)) {
            $evt['payload'] = json_decode(self::decryptJwe($evt['payload'], $privateKeyPem, $passphrase), true, 512, JSON_THROW_ON_ERROR);
            $encrypted = true;
        }
        if (!$encrypted && $requireEncrypted) {
            throw new \RuntimeException('Webhook notification is not encrypted (MLE expected for this subscription)');
        }
        return $evt;
    }

    /** Deduplication key of a notification (retries and duplicates are normal). */
    public static function eventKey(array $evt): string
    {
        return ($evt['webhookId'] ?? '') . ':' . ($evt['eventType'] ?? '') . ':' . ($evt['payload']['id'] ?? $evt['payload']['data']['id'] ?? '') . ':' . ($evt['eventDate'] ?? '');
    }
}
