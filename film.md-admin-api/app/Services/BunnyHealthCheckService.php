<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ContentFormat;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Probes every Bunny integration point and reports green/red per check.
 * Used by the admin Settings → Bunny Health page and surfaced as a single
 * status badge on the Dashboard.
 *
 * @phpstan-type Probe array{
 *   id: string,
 *   label: string,
 *   required: bool,
 *   status: 'pass'|'fail'|'skipped',
 *   detail: ?string,
 *   latency_ms: ?int
 * }
 */
class BunnyHealthCheckService
{
    public function __construct(
        protected BunnyLibraryResolver $libraryResolver,
    ) {}

    /**
     * @return array{
     *   summary: array{total: int, passing: int, failing: int, skipped: int, status: 'healthy'|'degraded'|'down'},
     *   probes: list<Probe>
     * }
     */
    public function run(): array
    {
        $probes = [
            $this->checkConfigPresent('webhook_secret', 'Webhook secret', false),
            $this->checkConfigPresent('token_key', 'Stream playback token key', true),
            ...$this->checkLibraryConnection('movies'),
            ...$this->checkLibraryConnection('trailers'),
            $this->checkAccountKey(),
            $this->checkPullZone(),
        ];

        $required = array_filter($probes, fn ($p) => $p['required']);
        $passing = count(array_filter($probes, fn ($p) => $p['status'] === 'pass'));
        $failing = count(array_filter($probes, fn ($p) => $p['status'] === 'fail'));
        $skipped = count(array_filter($probes, fn ($p) => $p['status'] === 'skipped'));
        $requiredFailing = count(array_filter($required, fn ($p) => $p['status'] === 'fail'));

        $status = match (true) {
            $requiredFailing > 0 => 'down',
            $failing > 0 => 'degraded',
            default => 'healthy',
        };

        return [
            'summary' => [
                'total' => count($probes),
                'passing' => $passing,
                'failing' => $failing,
                'skipped' => $skipped,
                'status' => $status,
            ],
            'probes' => array_values($probes),
        ];
    }

    /**
     * @return Probe
     */
    private function checkConfigPresent(string $key, string $label, bool $required): array
    {
        $value = config("services.bunny.{$key}");
        $present = is_string($value) && $value !== '';

        return [
            'id' => "config.{$key}",
            'label' => $label,
            'required' => $required,
            'status' => $present ? 'pass' : ($required ? 'fail' : 'skipped'),
            'detail' => $present ? 'Configured' : 'Not set in .env',
            'latency_ms' => null,
        ];
    }

    /**
     * @return list<Probe>
     */
    private function checkLibraryConnection(string $kind): array
    {
        $cfg = $this->libraryResolver->forKind($kind);
        $apiKey = $cfg['api_key'];

        $configProbe = [
            'id' => "library.{$kind}.config",
            'label' => "Library [{$kind}] API key configured",
            'required' => $kind === BunnyLibraryResolver::LIBRARY_MOVIES,
            'status' => $apiKey ? 'pass' : ($kind === BunnyLibraryResolver::LIBRARY_MOVIES ? 'fail' : 'skipped'),
            'detail' => $apiKey
                ? 'API key present'
                : 'Missing '.strtoupper($kind).'_BUNNY_API_KEY in .env',
            'latency_ms' => null,
        ];

        if ($configProbe['status'] !== 'pass') {
            return [$configProbe];
        }

        // Pull a sample library_id from any content_format of the matching kind.
        // If no films registered yet, we can't probe — that's fine, mark skipped.
        $sampleLibraryId = $this->resolveSampleLibraryId($kind);
        if ($sampleLibraryId === null) {
            return [
                $configProbe,
                [
                    'id' => "library.{$kind}.api",
                    'label' => "Library [{$kind}] API reachable",
                    'required' => false,
                    'status' => 'skipped',
                    'detail' => 'No films registered yet for this kind — add a film with bunny_library_id to enable probe',
                    'latency_ms' => null,
                ],
            ];
        }

        return [$configProbe, $this->probeStreamLibrary($kind, $sampleLibraryId, (string) $apiKey)];
    }

    private function resolveSampleLibraryId(string $kind): ?string
    {
        $formatTypeFilter = $kind === BunnyLibraryResolver::LIBRARY_TRAILERS
            ? ['trailer', 'preview']
            : ['main', 'feature'];

        $row = ContentFormat::query()
            ->whereNotNull('bunny_library_id')
            ->where(function ($q) use ($kind, $formatTypeFilter): void {
                if ($kind === BunnyLibraryResolver::LIBRARY_TRAILERS) {
                    $q->whereIn('format_type', $formatTypeFilter);
                } else {
                    $q->whereNotIn('format_type', ['trailer', 'preview'])
                        ->orWhereNull('format_type');
                }
            })
            ->first(['bunny_library_id']);

        $libraryId = $row?->bunny_library_id;

        return $libraryId !== null && $libraryId !== '' ? (string) $libraryId : null;
    }

    /**
     * @return Probe
     */
    private function probeStreamLibrary(string $kind, string $libraryId, string $apiKey): array
    {
        $base = rtrim((string) config('services.bunny.stream_base_url'), '/');
        $url = sprintf('%s/library/%s', $base, $libraryId);
        $start = microtime(true);

        try {
            $response = Http::withHeaders([
                'AccessKey' => $apiKey,
                'Accept' => 'application/json',
            ])->timeout(10)->get($url);

            $latency = (int) ((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                $name = (string) ($response->json('Name') ?? 'unknown');

                return [
                    'id' => "library.{$kind}.api",
                    'label' => "Library [{$kind}] API reachable",
                    'required' => $kind === BunnyLibraryResolver::LIBRARY_MOVIES,
                    'status' => 'pass',
                    'detail' => "OK — library: {$name}",
                    'latency_ms' => $latency,
                ];
            }

            return [
                'id' => "library.{$kind}.api",
                'label' => "Library [{$kind}] API reachable",
                'required' => $kind === BunnyLibraryResolver::LIBRARY_MOVIES,
                'status' => 'fail',
                'detail' => "HTTP {$response->status()} — ".substr((string) $response->body(), 0, 120),
                'latency_ms' => $latency,
            ];
        } catch (Throwable $e) {
            return [
                'id' => "library.{$kind}.api",
                'label' => "Library [{$kind}] API reachable",
                'required' => $kind === BunnyLibraryResolver::LIBRARY_MOVIES,
                'status' => 'fail',
                'detail' => 'Network error: '.$e->getMessage(),
                'latency_ms' => null,
            ];
        }
    }

    /**
     * @return Probe
     */
    private function checkAccountKey(): array
    {
        $apiKey = (string) config('services.bunny.account_api_key');
        if ($apiKey === '') {
            return [
                'id' => 'account.api_key',
                'label' => 'Account API key (CDN stats)',
                'required' => false,
                'status' => 'skipped',
                'detail' => 'Optional — set BUNNY_ACCOUNT_API_KEY only if you want global CDN bandwidth dashboard',
                'latency_ms' => null,
            ];
        }

        $start = microtime(true);
        try {
            $response = Http::withHeaders([
                'AccessKey' => $apiKey,
                'Accept' => 'application/json',
            ])->timeout(10)->get('https://api.bunny.net/pullzone');

            $latency = (int) ((microtime(true) - $start) * 1000);

            return [
                'id' => 'account.api_key',
                'label' => 'Account API key',
                'required' => false,
                'status' => $response->successful() ? 'pass' : 'fail',
                'detail' => $response->successful()
                    ? sprintf('OK — %d pull zones visible', count((array) $response->json()))
                    : "HTTP {$response->status()}",
                'latency_ms' => $latency,
            ];
        } catch (Throwable $e) {
            return [
                'id' => 'account.api_key',
                'label' => 'Account API key',
                'required' => false,
                'status' => 'fail',
                'detail' => 'Network error: '.$e->getMessage(),
                'latency_ms' => null,
            ];
        }
    }

    /**
     * @return Probe
     */
    private function checkPullZone(): array
    {
        $pullZoneId = (string) config('services.bunny.cdn_pull_zone_id');
        $apiKey = (string) config('services.bunny.account_api_key');

        if ($pullZoneId === '' || $apiKey === '') {
            return [
                'id' => 'cdn.pull_zone',
                'label' => 'CDN Pull Zone',
                'required' => false,
                'status' => 'skipped',
                'detail' => 'Optional — set BUNNY_CDN_PULL_ZONE_ID + BUNNY_ACCOUNT_API_KEY for CDN stats',
                'latency_ms' => null,
            ];
        }

        $start = microtime(true);
        try {
            $response = Http::withHeaders([
                'AccessKey' => $apiKey,
                'Accept' => 'application/json',
            ])->timeout(10)->get("https://api.bunny.net/pullzone/{$pullZoneId}");

            $latency = (int) ((microtime(true) - $start) * 1000);

            if ($response->successful()) {
                $name = (string) ($response->json('Name') ?? '');

                return [
                    'id' => 'cdn.pull_zone',
                    'label' => 'CDN Pull Zone',
                    'required' => false,
                    'status' => 'pass',
                    'detail' => "OK — pull zone: {$name}",
                    'latency_ms' => $latency,
                ];
            }

            return [
                'id' => 'cdn.pull_zone',
                'label' => 'CDN Pull Zone',
                'required' => false,
                'status' => 'fail',
                'detail' => "HTTP {$response->status()}",
                'latency_ms' => $latency,
            ];
        } catch (Throwable $e) {
            return [
                'id' => 'cdn.pull_zone',
                'label' => 'CDN Pull Zone',
                'required' => false,
                'status' => 'fail',
                'detail' => 'Network error: '.$e->getMessage(),
                'latency_ms' => null,
            ];
        }
    }
}
