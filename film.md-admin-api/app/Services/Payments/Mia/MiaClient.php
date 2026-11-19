<?php

namespace App\Services\Payments\Mia;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Thin HTTP client for the Victoriabank Business IPS API. Owns the bearer token
 * (cached and refreshed — the bank throttles callers that authenticate on every call)
 * and retries a request once after a 401.
 */
class MiaClient
{
    protected const ACCESS_TOKEN_CACHE_KEY = 'payments:mia:access_token';

    protected const REFRESH_TOKEN_CACHE_KEY = 'payments:mia:refresh_token';

    public function isConfigured(): bool
    {
        $config = $this->config();

        return filled($config['base_url'] ?? null)
            && filled($config['username'] ?? null)
            && filled($config['password'] ?? null)
            && filled($config['iban'] ?? null);
    }

    /**
     * @return array{qrHeaderUUID: string, qrExtensionUUID: string, qrAsText: ?string, qrAsImage: ?string}
     */
    public function createQr(array $payload, int $width = 300, int $height = 300): array
    {
        $response = $this->send('post', 'api/v1/qr?'.http_build_query(['width' => $width, 'height' => $height]), $payload);

        if (! $response->successful()) {
            throw new MiaRequestException('MIA create QR rejected', $response);
        }

        $json = (array) $response->json();

        if (blank($json['qrHeaderUUID'] ?? null) || blank($json['qrExtensionUUID'] ?? null)) {
            throw new MiaRequestException('MIA create QR returned no identifiers', $response);
        }

        return $json;
    }

    public function cancelQr(string $qrHeaderUuid): bool
    {
        return $this->send('delete', 'api/v1/qr/'.rawurlencode($qrHeaderUuid))->successful();
    }

    public function extensionStatus(string $qrExtensionUuid, int $transactions = 1): array
    {
        $response = $this->send('get', 'api/v1/qr-extensions/'.rawurlencode($qrExtensionUuid).'/status?nbOfTxs='.max(0, min(10, $transactions)));

        if (! $response->successful()) {
            throw new MiaRequestException('MIA extension status failed', $response);
        }

        return (array) $response->json();
    }

    /** DELETE /api/v1/transaction/{id} — refunds the whole payment. */
    public function fullRefund(string $transactionId): Response
    {
        return $this->send('delete', 'api/v1/transaction/'.rawurlencode($transactionId));
    }

    /** DELETE /api/v1/transaction/{id}/partial-refund — one shot: the payment cannot be refunded again. */
    public function partialRefund(string $transactionId, float $amount): Response
    {
        return $this->send('delete', 'api/v1/transaction/'.rawurlencode($transactionId).'/partial-refund', [
            'amount' => round($amount, 2),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function reconciliation(string $dateFrom, string $dateTo): array
    {
        $response = $this->send('get', 'api/v1/reconciliation/transactions?'.http_build_query([
            'datefrom' => $dateFrom,
            'dateto' => $dateTo,
        ]));

        if (! $response->successful()) {
            throw new MiaRequestException('MIA reconciliation failed', $response);
        }

        return array_values((array) ($response->json('transactionsInfo') ?? []));
    }

    protected function send(string $method, string $path, ?array $body = null, bool $retried = false): Response
    {
        $request = $this->http()->withToken($this->accessToken());
        $url = rtrim((string) $this->config()['base_url'], '/').'/'.ltrim($path, '/');

        $response = match ($method) {
            'get' => $request->get($url),
            'post' => $request->post($url, $body ?? []),
            'delete' => $body === null ? $request->delete($url) : $request->delete($url, $body),
            default => throw new RuntimeException("Unsupported MIA method {$method}"),
        };

        Log::channel('payments')->info('MIA API call', [
            'method' => strtoupper($method),
            'path' => strtok($path, '?'),
            'http_status' => $response->status(),
            'trace_reference' => $response->json('traceReference'),
            'error_code' => $response->json('errorCode'),
        ]);

        if ($response->status() === 401 && ! $retried) {
            $this->forgetTokens();

            return $this->send($method, $path, $body, true);
        }

        return $response;
    }

    protected function accessToken(): string
    {
        $cached = Cache::get(self::ACCESS_TOKEN_CACHE_KEY);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $refreshToken = Cache::get(self::REFRESH_TOKEN_CACHE_KEY);
        $config = $this->config();

        $payload = is_string($refreshToken) && $refreshToken !== ''
            ? ['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]
            : ['grant_type' => 'password', 'username' => (string) $config['username'], 'password' => (string) $config['password']];

        $response = $this->http()
            ->asForm()
            ->post(rtrim((string) $config['base_url'], '/').'/identity/token', $payload);

        if (! $response->successful() && $payload['grant_type'] === 'refresh_token') {
            Cache::forget(self::REFRESH_TOKEN_CACHE_KEY);

            return $this->accessToken();
        }

        $accessToken = (string) $response->json('accessToken', '');
        if (! $response->successful() || $accessToken === '') {
            Log::channel('payments')->error('MIA authentication failed', [
                'http_status' => $response->status(),
                'grant_type' => $payload['grant_type'],
            ]);

            throw new MiaRequestException('MIA authentication failed', $response);
        }

        // Refresh a minute early so a token never expires mid-request.
        $expiresIn = max(60, (int) $response->json('expiresIn', 3600) - 60);
        Cache::put(self::ACCESS_TOKEN_CACHE_KEY, $accessToken, $expiresIn);

        $newRefreshToken = (string) $response->json('refreshToken', '');
        if ($newRefreshToken !== '') {
            Cache::put(self::REFRESH_TOKEN_CACHE_KEY, $newRefreshToken, max(60, (int) $response->json('refreshExpiresIn', 18000) - 60));
        }

        return $accessToken;
    }

    protected function forgetTokens(): void
    {
        Cache::forget(self::ACCESS_TOKEN_CACHE_KEY);
        Cache::forget(self::REFRESH_TOKEN_CACHE_KEY);
    }

    protected function http(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(max(5, (int) ($this->config()['timeout'] ?? 30)))
            ->connectTimeout(10);
    }

    protected function config(): array
    {
        return (array) config('services.mia', []);
    }
}
