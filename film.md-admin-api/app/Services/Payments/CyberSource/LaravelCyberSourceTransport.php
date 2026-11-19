<?php

namespace App\Services\Payments\CyberSource;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Victoriabank\CyberSource\Client;
use Victoriabank\CyberSource\Exception\UnknownStatusException;
use Victoriabank\CyberSource\Transport;

/**
 * CyberSource REST transport over Laravel's HTTP client. Signing stays the bank SDK's
 * (Client::buildJwt — JWT v2, HS256, digest of the exact body bytes); only the HTTP
 * call is ours, because:
 *  - the SDK's built-in client always sends Accept: application/hal+json, which
 *    POST /tss/v2/searches rejects with 406 on apitest.cybersource.com (found 2026-11-19);
 *  - Laravel's client gives us logging and Http::fake() in tests.
 *
 * Same contract as the SDK client: every transport error, timeout or HTTP 5xx throws
 * UnknownStatusException (the outcome must be reconciled by reference, R10).
 */
class LaravelCyberSourceTransport implements Transport
{
    /** Endpoints that only answer application/json. */
    protected const JSON_ONLY_PATHS = ['/tss/v2/searches'];

    public function __construct(
        protected Client $signer,
        protected int $timeoutSeconds = 65,
    ) {}

    public function baseUrl(): string
    {
        return $this->signer->baseUrl();
    }

    public function request(string $method, string $path, ?array $body = null): array
    {
        $json = $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $accept = in_array(strtok($path, '?'), self::JSON_ONLY_PATHS, true)
            ? 'application/json;charset=utf-8'
            : 'application/hal+json;charset=utf-8';

        $request = Http::withHeaders([
            'Accept' => $accept,
            'Authorization' => 'Bearer '.$this->signer->buildJwt($method, $path, $json),
        ])
            ->timeout($this->timeoutSeconds)
            ->connectTimeout(10);

        if ($json !== null) {
            // The exact bytes that were digested in the JWT.
            $request = $request->withBody($json, 'application/json;charset=utf-8');
        }

        try {
            $response = $request->send($method, $this->baseUrl().$path);
        } catch (ConnectionException $exception) {
            throw new UnknownStatusException("CyberSource status unknown ($method $path, {$exception->getMessage()})");
        }

        $status = $response->status();
        $data = $response->json();
        $decoded = is_array($data) ? $data : $response->body();   // session endpoints answer with the JWT as text

        Log::channel('payments')->info('CyberSource API call', [
            'method' => $method,
            'path' => preg_replace('~/\d{6,}~', '/{id}', $path),
            'http_status' => $status,
            'correlation_id' => $response->header('v-c-correlation-id'),
            'status' => is_array($decoded) ? ($decoded['status'] ?? null) : null,
            'reason' => is_array($decoded) ? ($decoded['reason'] ?? $decoded['errorInformation']['reason'] ?? null) : null,
        ]);

        if ($status >= 500) {
            throw new UnknownStatusException("CyberSource status unknown ($method $path, HTTP $status)", $status, is_array($decoded) ? $decoded : null);
        }

        return [$status, $decoded];
    }
}
