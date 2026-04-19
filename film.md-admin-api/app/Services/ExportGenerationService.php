<?php

namespace App\Services;

use App\Models\AdCampaign;
use App\Models\Content;
use App\Models\ContentCreator;
use App\Models\ContentEntitlement;
use App\Models\ContentFormat;
use App\Models\ContentRightsWindow;
use App\Models\CreatorMonthlyStatement;
use App\Models\ExportJob;
use App\Models\Offer;
use App\Models\PlaybackSession;
use App\Models\PremiereEvent;
use App\Models\Role;
use App\Models\SubtitleTrack;
use App\Models\User;
use App\Models\VideoMonthlyCost;
use App\Models\WalletTransaction;
use App\Models\WatchProgress;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ExportGenerationService
{
    protected const EXPORT_DISK = 'local';

    public function __construct(
        protected ContentScopeService $contentScope,
    ) {}

    public function generate(ExportJob $job, ?User $actor = null): ExportJob
    {
        $job->forceFill([
            'status' => 'processing',
            'meta' => array_merge($job->meta ?? [], [
                'processing_started_at' => now()->toIso8601String(),
            ]),
        ])->save();

        try {
            [$contents, $extension, $mimeType, $summary] = $this->buildExportPayload($job, $actor);
            $path = $this->storeExportContents($job, $contents, $extension);

            $job->forceFill([
                'status' => 'completed',
                'file_path' => $path,
                'meta' => array_merge($job->meta ?? [], $summary, [
                    'mime_type' => $mimeType,
                    'file_name' => basename($path),
                    'generated_at' => now()->toIso8601String(),
                ]),
            ])->save();
        } catch (Throwable $exception) {
            $job->forceFill([
                'status' => 'failed',
                'meta' => array_merge($job->meta ?? [], [
                    'error_message' => $exception->getMessage(),
                    'failed_at' => now()->toIso8601String(),
                ]),
            ])->save();
        }

        return $job->fresh();
    }

    /**
     * @return array{0:string,1:string,2:string,3:array<string,mixed>}
     */
    protected function buildExportPayload(ExportJob $job, ?User $actor): array
    {
        return match ($job->scope) {
            'billing' => $this->buildBillingExport($job, $actor),
            'creator-statements' => $this->buildCreatorStatementsExport($job, $actor),
            'full-platform' => $this->buildFullPlatformExport($job, $actor),
            default => throw new \RuntimeException('Unsupported export scope.'),
        };
    }

    /**
     * @return array{0:string,1:string,2:string,3:array<string,mixed>}
     */
    protected function buildBillingExport(ExportJob $job, ?User $actor): array
    {
        [$isScoped, $assignedContentIds] = $this->resolveScope($actor);
        $range = $this->resolveRangeFromFilters($job->filters ?? []);
        $from = Carbon::now()->startOfDay()->subDays($range['days'] - 1);
        $to = Carbon::now()->endOfDay();
        $month = now()->format('Y-m');

        $transactions = $this->scopeTransactions(
            WalletTransaction::query()
                ->with('user')
                ->whereBetween('processed_at', [$from, $to])
                ->latest('processed_at')
                ->latest('id'),
            $isScoped,
            $assignedContentIds,
        )->limit(200)->get();

        $monthlyCosts = $this->scopeContentQuery(
            VideoMonthlyCost::query()->with(['content', 'format'])->where('month', $month),
            $isScoped,
            $assignedContentIds,
        )->orderByDesc('profit_usd')->get();

        $creatorIds = $this->resolveCreatorIds($isScoped, $assignedContentIds);
        $statements = CreatorMonthlyStatement::query()
            ->with('creator')
            ->where('month', $month)
            ->when($isScoped, fn ($query) => $query->whereIn('content_creator_id', $creatorIds))
            ->orderByDesc('payout_usd')
            ->get();

        $rows = collect();

        foreach ($transactions as $transaction) {
            $rows->push([
                'section' => 'transactions',
                'processed_at' => optional($transaction->processed_at)->toIso8601String(),
                'type' => $transaction->type,
                'user_name' => $transaction->user?->name,
                'user_email' => $transaction->user?->email,
                'content_title' => Arr::get($transaction->meta ?? [], 'content_title'),
                'content_slug' => Arr::get($transaction->meta ?? [], 'content_slug'),
                'offer_type' => Arr::get($transaction->meta ?? [], 'offer_type'),
                'quality' => Arr::get($transaction->meta ?? [], 'quality'),
                'amount' => round((float) $transaction->amount, 2),
                'currency' => $transaction->currency,
                'description' => $transaction->description,
            ]);
        }

        foreach ($monthlyCosts as $row) {
            $rows->push([
                'section' => 'monthly_costs',
                'month' => $row->month,
                'content_title' => $row->content?->original_title,
                'content_slug' => $row->content?->slug,
                'quality' => $row->format?->quality,
                'storage_cost_usd' => $row->storage_cost_usd,
                'delivery_cost_usd' => $row->delivery_cost_usd,
                'drm_cost_usd' => $row->drm_cost_usd,
                'revenue_usd' => $row->revenue_usd,
                'profit_usd' => $row->profit_usd,
            ]);
        }

        foreach ($statements as $statement) {
            $rows->push([
                'section' => 'creator_statements',
                'month' => $statement->month,
                'creator_name' => $statement->creator?->name,
                'creator_company' => $statement->creator?->company_name,
                'revenue_usd' => $statement->revenue_usd,
                'costs_usd' => $statement->costs_usd,
                'payout_usd' => $statement->payout_usd,
                'profit_usd' => $statement->profit_usd,
            ]);
        }

        return [
            $this->toCsv($rows),
            'csv',
            'text/csv',
            [
                'row_count' => $rows->count(),
                'range' => $range['value'],
            ],
        ];
    }

    /**
     * @return array{0:string,1:string,2:string,3:array<string,mixed>}
     */
    protected function buildCreatorStatementsExport(ExportJob $job, ?User $actor): array
    {
        [$isScoped, $assignedContentIds] = $this->resolveScope($actor);
        $month = (string) Arr::get($job->filters ?? [], 'month', now()->format('Y-m'));
        $creatorIds = $this->resolveCreatorIds($isScoped, $assignedContentIds);

        $statements = CreatorMonthlyStatement::query()
            ->with('creator')
            ->where('month', $month)
            ->when($isScoped, fn ($query) => $query->whereIn('content_creator_id', $creatorIds))
            ->orderBy('content_creator_id')
            ->get();

        $lines = [
            'Film.md Creator Statements',
            sprintf('Month: %s', $month),
            sprintf('Generated at: %s', now()->toDateTimeString()),
            '',
        ];

        foreach ($statements as $statement) {
            $lines[] = sprintf('Creator: %s', $statement->creator?->name ?? 'Unknown creator');
            $lines[] = sprintf('Company: %s', $statement->creator?->company_name ?? 'N/A');
            $lines[] = sprintf('Revenue USD: %.2f', $statement->revenue_usd);
            $lines[] = sprintf('Costs USD: %.2f', $statement->costs_usd);
            $lines[] = sprintf('Payout USD: %.2f', $statement->payout_usd);
            $lines[] = sprintf('Profit USD: %.2f', $statement->profit_usd);
            $lines[] = sprintf(
                'Content IDs: %s',
                collect(Arr::get($statement->meta ?? [], 'content_ids', []))->filter()->implode(', ') ?: 'N/A',
            );
            $lines[] = '';
        }

        if ($statements->isEmpty()) {
            $lines[] = 'No creator statements were available for the selected month.';
        }

        return [
            $this->toSimplePdf($lines),
            'pdf',
            'application/pdf',
            [
                'row_count' => $statements->count(),
                'month' => $month,
            ],
        ];
    }

    /**
     * @return array{0:string,1:string,2:string,3:array<string,mixed>}
     */
    protected function buildFullPlatformExport(ExportJob $job, ?User $actor): array
    {
        [$isScoped, $assignedContentIds] = $this->resolveScope($actor);
        $creatorIds = $this->resolveCreatorIds($isScoped, $assignedContentIds);

        $payload = [
            'generated_at' => now()->toIso8601String(),
            'scope' => $job->scope,
            'filters' => $job->filters ?? [],
            'contents' => $this->scopeContentQuery(Content::query(), $isScoped, $assignedContentIds)
                ->with(['formats', 'rightsWindows', 'subtitleTracks', 'offers', 'creators', 'premiereEvents'])
                ->get()
                ->map(fn (Content $content) => [
                    'id' => $content->id,
                    'slug' => $content->slug,
                    'type' => $content->type,
                    'status' => $content->status,
                    'original_title' => $content->original_title,
                    'title' => $content->title,
                    'published_at' => optional($content->published_at)->toIso8601String(),
                    'formats' => $content->formats->map(fn (ContentFormat $format) => $format->toArray())->values()->all(),
                    'rights_windows' => $content->rightsWindows->map(fn (ContentRightsWindow $window) => $window->toArray())->values()->all(),
                    'subtitle_tracks' => $content->subtitleTracks->map(fn (SubtitleTrack $track) => $track->toArray())->values()->all(),
                    'offers' => $content->offers->map(fn (Offer $offer) => $offer->toArray())->values()->all(),
                    'creators' => $content->creators->map(fn (ContentCreator $creator) => $creator->toArray())->values()->all(),
                    'premiere_events' => $content->premiereEvents->map(fn (PremiereEvent $event) => $event->toArray())->values()->all(),
                ])
                ->values()
                ->all(),
            'video_monthly_costs' => $this->scopeContentQuery(VideoMonthlyCost::query(), $isScoped, $assignedContentIds)
                ->get()
                ->map(fn (VideoMonthlyCost $row) => $row->toArray())
                ->values()
                ->all(),
            'creator_monthly_statements' => CreatorMonthlyStatement::query()
                ->when($isScoped, fn ($query) => $query->whereIn('content_creator_id', $creatorIds))
                ->get()
                ->map(fn (CreatorMonthlyStatement $row) => $row->toArray())
                ->values()
                ->all(),
            'playback_sessions' => $this->scopeContentQuery(PlaybackSession::query(), $isScoped, $assignedContentIds)
                ->get()
                ->map(fn (PlaybackSession $row) => $row->toArray())
                ->values()
                ->all(),
            'watch_progress' => $this->scopeContentQuery(WatchProgress::query(), $isScoped, $assignedContentIds)
                ->get()
                ->map(fn (WatchProgress $row) => $row->toArray())
                ->values()
                ->all(),
            'transactions' => $this->scopeTransactions(WalletTransaction::query(), $isScoped, $assignedContentIds)
                ->get()
                ->map(fn (WalletTransaction $row) => $row->toArray())
                ->values()
                ->all(),
            'entitlements' => $this->scopeContentQuery(ContentEntitlement::query(), $isScoped, $assignedContentIds, 'content_entitlements.content_id')
                ->get()
                ->map(fn (ContentEntitlement $row) => $row->toArray())
                ->values()
                ->all(),
            'ad_campaigns' => AdCampaign::query()
                ->with(['creatives', 'targetingRules'])
                ->when($isScoped, function ($query) use ($assignedContentIds): void {
                    $query->where(function ($builder) use ($assignedContentIds): void {
                        $builder->whereDoesntHave('targetingRules', fn ($ruleQuery) => $ruleQuery->whereNotNull('content_id'))
                            ->orWhereHas('targetingRules', fn ($ruleQuery) => $ruleQuery->whereIn('content_id', $assignedContentIds));
                    });
                })
                ->get()
                ->map(fn (AdCampaign $campaign) => [
                    ...$campaign->toArray(),
                    'creatives' => $campaign->creatives->map->toArray()->values()->all(),
                    'targeting_rules' => $campaign->targetingRules->map->toArray()->values()->all(),
                ])
                ->values()
                ->all(),
            'roles' => ! $isScoped
                ? Role::query()->with('permissions')->get()->map(fn (Role $role) => [
                    ...$role->toArray(),
                    'permissions' => $role->permissions->map->only(['id', 'code', 'name', 'group'])->values()->all(),
                ])->values()->all()
                : [],
            'users' => ! $isScoped
                ? User::query()->with('roles')->get()->map(fn (User $user) => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'status' => $user->status,
                    'preferred_locale' => $user->preferred_locale,
                    'roles' => $user->roles->pluck('name')->values()->all(),
                ])->values()->all()
                : [],
            'analytics_daily' => DB::connection('analytics')
                ->table('video_daily_aggregates')
                ->when($isScoped, fn ($query) => $query->whereIn('content_id', $assignedContentIds))
                ->get(),
            'ad_analytics_daily' => DB::connection('analytics')
                ->table('ad_aggregate_daily')
                ->when($isScoped, fn ($query) => $query->whereIn('content_id', $assignedContentIds))
                ->get(),
        ];

        return [
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            'json',
            'application/json',
            [
                'row_count' => count($payload['contents']) + count($payload['video_monthly_costs']) + count($payload['creator_monthly_statements']),
            ],
        ];
    }

    protected function toCsv(Collection $rows): string
    {
        if ($rows->isEmpty()) {
            return "section,message\nempty,No rows available\n";
        }

        $headers = $rows->flatMap(fn (array $row) => array_keys($row))->unique()->values()->all();
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            fputcsv($handle, array_map(
                fn (string $header) => $this->csvValue($row[$header] ?? null),
                $headers,
            ));
        }

        rewind($handle);
        $contents = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $contents;
    }

    protected function csvValue(mixed $value): string|int|float
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        return is_scalar($value) ? $value : '';
    }

    protected function toSimplePdf(array $lines): string
    {
        $fontObjectId = 3;
        $pages = array_chunk($lines, 38) ?: [[]];
        $objects = [1 => "<< /Type /Catalog /Pages 2 0 R >>"];
        $kids = [];
        $nextObjectId = 4;

        foreach ($pages as $pageLines) {
            $pageObjectId = $nextObjectId++;
            $contentObjectId = $nextObjectId++;
            $kids[] = sprintf('%d 0 R', $pageObjectId);

            $stream = "BT\n/F1 11 Tf\n14 TL\n50 790 Td\n";
            foreach ($pageLines as $index => $line) {
                if ($index > 0) {
                    $stream .= "T*\n";
                }

                $stream .= sprintf("(%s) Tj\n", $this->escapePdfText((string) $line));
            }
            $stream .= "ET";

            $objects[$pageObjectId] = sprintf(
                "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 %d 0 R >> >> /Contents %d 0 R >>",
                $fontObjectId,
                $contentObjectId,
            );
            $objects[$contentObjectId] = sprintf("<< /Length %d >>\nstream\n%s\nendstream", strlen($stream), $stream);
        }

        $objects[2] = sprintf("<< /Type /Pages /Count %d /Kids [%s] >>", count($kids), implode(' ', $kids));
        $objects[$fontObjectId] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $objectId => $body) {
            $offsets[$objectId] = strlen($pdf);
            $pdf .= sprintf("%d 0 obj\n%s\nendobj\n", $objectId, $body);
        }

        $xrefOffset = strlen($pdf);
        $pdf .= sprintf("xref\n0 %d\n", count($objects) + 1);
        $pdf .= "0000000000 65535 f \n";

        for ($index = 1; $index <= count($objects); $index++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$index] ?? 0);
        }

        $pdf .= sprintf("trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF", count($objects) + 1, $xrefOffset);

        return $pdf;
    }

    protected function escapePdfText(string $text): string
    {
        $text = preg_replace('/[^\P{C}\t\n\r]/u', '', $text) ?: '';
        $text = mb_substr($text, 0, 110, 'UTF-8');

        return str_replace(['\\', '(', ')'], ['\\\\', '\(', '\)'], $text);
    }

    protected function storeExportContents(ExportJob $job, string $contents, string $extension): string
    {
        $directory = sprintf('exports/%s', now()->format('Y/m'));
        $filename = sprintf('%s-%d-%s.%s', $job->scope, $job->id, now()->format('YmdHis'), $extension);
        $path = sprintf('%s/%s', $directory, $filename);

        Storage::disk(self::EXPORT_DISK)->put($path, $contents);

        return $path;
    }

    /**
     * @return array{0:bool,1:array<int,int>}
     */
    protected function resolveScope(?User $actor): array
    {
        if ($actor === null) {
            return [false, []];
        }

        return [
            $this->contentScope->isScoped($actor),
            $this->contentScope->assignedContentIds($actor),
        ];
    }

    protected function resolveCreatorIds(bool $isScoped, array $assignedContentIds): array
    {
        if (! $isScoped || $assignedContentIds === []) {
            return [];
        }

        return DB::table('content_creator_assignments')
            ->whereIn('content_id', $assignedContentIds)
            ->pluck('content_creator_id')
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }

    protected function scopeContentQuery($query, bool $isScoped, array $assignedContentIds, string $column = 'content_id')
    {
        if (! $isScoped) {
            return $query;
        }

        if ($assignedContentIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($column, $assignedContentIds);
    }

    protected function scopeTransactions($query, bool $isScoped, array $assignedContentIds)
    {
        if (! $isScoped) {
            return $query;
        }

        if ($assignedContentIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function ($builder) use ($assignedContentIds): void {
            foreach ($assignedContentIds as $contentId) {
                $builder->orWhereJsonContains('meta->content_id', $contentId);
            }
        });
    }

    /**
     * @return array{value:string,days:int}
     */
    protected function resolveRangeFromFilters(array $filters): array
    {
        return match ((string) Arr::get($filters, 'range', '30days')) {
            '7days' => ['value' => '7days', 'days' => 7],
            '3months' => ['value' => '3months', 'days' => 90],
            default => ['value' => '30days', 'days' => 30],
        };
    }
}
