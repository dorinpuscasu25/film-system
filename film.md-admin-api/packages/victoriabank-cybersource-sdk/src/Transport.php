<?php
declare(strict_types=1);

namespace Victoriabank\CyberSource;

/**
 * Sends one request to CyberSource.
 * Built-in implementation: Client (JWT v2, no Message-Level Encryption) - simulator and test environment.
 * Production: OfficialSdkTransport, over the official SDK cybersource/rest-client-php (JWT v2 + MLE), see README.
 */
interface Transport
{
    /**
     * @return array{0:int,1:array|string} [HTTP status, decoded body (or the JWT text for session endpoints)]
     * @throws Exception\UnknownStatusException on timeout, transport error, HTTP 502/504
     */
    public function request(string $method, string $path, ?array $body = null): array;

    /** Base URL of the API (used to fetch CyberSource public keys for RS256 verification). */
    public function baseUrl(): string;
}
