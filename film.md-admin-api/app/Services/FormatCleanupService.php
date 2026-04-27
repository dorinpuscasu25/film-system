<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ContentFormat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Identifies content formats with zero views over the last N days and marks
 * them as inactive. Optionally calls Bunny Stream API to delete the underlying
 * video and clears `bunny_video_id` to free the asset slot.
 */
class FormatCleanupService
{
    public function __construct(
        protected AuditLogService $auditLog,
    ) {}

    /**
     * @return array{candidates: int, deactivated: int, deleted_remote: int}
     */
    public function run(int $unusedDays = 30, bool $deleteRemote = false): array
    {
        $cutoff = now()->subDays($unusedDays)->toDateString();

        // Sum views per format from internal aggregates AND Bunny snapshots.
        $viewsByFormat = collect(DB::connection('analytics')->table('video_daily_aggregates')
            ->where('date', '>=', $cutoff)
            ->selectRaw('content_format_id, SUM(views) as views')
            ->groupBy('content_format_id')
            ->get())
            ->pluck('views', 'content_format_id');

        $bunnyViewsByFormat = collect(DB::connection('analytics')->table('bunny_stream_stats_daily')
            ->where('date', '>=', $cutoff)
            ->selectRaw('content_format_id, SUM(views) as views')
            ->groupBy('content_format_id')
            ->get())
            ->pluck('views', 'content_format_id');

        $candidates = ContentFormat::query()
            ->where('is_active', true)
            ->whereNotNull('bunny_video_id')
            // protect newly added formats — give them at least N days
            ->where('created_at', '<=', now()->subDays($unusedDays))
            ->get();

        $deactivated = 0;
        $deletedRemote = 0;

        foreach ($candidates as $format) {
            $internalViews = (int) ($viewsByFormat[$format->id] ?? 0);
            $bunnyViews = (int) ($bunnyViewsByFormat[$format->id] ?? 0);

            if ($internalViews > 0 || $bunnyViews > 0) {
                continue;
            }

            $previousValues = [
                'is_active' => $format->is_active,
                'bunny_library_id' => $format->bunny_library_id,
                'bunny_video_id' => $format->bunny_video_id,
            ];

            if ($deleteRemote && $this->deleteFromBunny($format)) {
                $deletedRemote++;
                $format->bunny_video_id = '';
            }

            $format->is_active = false;
            $format->save();
            $deactivated++;

            $this->auditLog->record(
                'content_format.cleanup_deactivated',
                'content_format',
                $format->id,
                [
                    'reason' => sprintf('No views in last %d days', $unusedDays),
                    'previous' => $previousValues,
                    'deleted_remote' => $deleteRemote,
                ],
            );
        }

        return [
            'candidates' => $candidates->count(),
            'deactivated' => $deactivated,
            'deleted_remote' => $deletedRemote,
        ];
    }

    protected function deleteFromBunny(ContentFormat $format): bool
    {
        $apiKey = config('services.bunny.stream_api_key');
        if (empty($apiKey) || empty($format->bunny_library_id) || empty($format->bunny_video_id)) {
            return false;
        }

        try {
            $base = rtrim((string) config('services.bunny.stats_api_base'), '/');
            $response = Http::withHeaders([
                'AccessKey' => $apiKey,
                'Accept' => 'application/json',
            ])
                ->timeout(15)
                ->delete(sprintf('%s/library/%s/videos/%s', $base, $format->bunny_library_id, $format->bunny_video_id));

            return $response->successful();
        } catch (\Throwable $e) {
            Log::error('FormatCleanupService: bunny delete failed', [
                'format_id' => $format->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
