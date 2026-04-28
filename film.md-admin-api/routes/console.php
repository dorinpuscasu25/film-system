<?php

use App\Services\AdEventTrackingService;
use App\Services\AnalyticsBufferService;
use App\Services\BunnyStatsService;
use App\Services\ContentSearchService;
use App\Services\FormatCleanupService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('search:reindex-content', function (ContentSearchService $contentSearch) {
    $indexed = $contentSearch->reindex();
    $driver = config('search.driver');

    if ($driver !== 'meilisearch') {
        $this->warn("Search driver is set to [{$driver}]. Set SEARCH_DRIVER=meilisearch to build the index.");

        return self::SUCCESS;
    }

    $this->info("Reindexed {$indexed} published titles into [".config('search.indexes.content.uid').'].');

    return self::SUCCESS;
})->purpose('Rebuild the public content index used by storefront search');

Artisan::command('analytics:flush-buffers', function (AnalyticsBufferService $buffer) {
    $video = $buffer->flushVideoAggregatesToDatabase();
    $ads = $buffer->flushAdAggregatesToDatabase();

    $this->info("Flushed {$video} video aggregate keys and {$ads} ad aggregate keys from Redis to analytics DB.");

    return self::SUCCESS;
})->purpose('Flush buffered Redis analytics aggregates into analytics database tables');

Artisan::command('analytics:recalculate-costs {month?}', function (AnalyticsBufferService $buffer, ?string $month = null) {
    $result = $buffer->recalculateMonthlyCosts($month);

    $this->info("Recalculated costs for {$result['month']}: {$result['videos']} video rows, {$result['creators']} creator statements.");

    return self::SUCCESS;
})->purpose('Recalculate monthly content costs and creator statements from analytics aggregates');

Artisan::command('bunny:pull-stats {date? : YYYY-MM-DD, defaults to yesterday}', function (BunnyStatsService $stats, ?string $date = null) {
    $target = $date !== null ? Carbon::parse($date) : Carbon::yesterday();

    $stream = $stats->pullStreamStatsForDate($target);
    $cdn = $stats->pullCdnStatsForDate($target);
    $storage = $stats->pullStorageSnapshotForDate($target);

    $this->info(sprintf(
        'Bunny stats for %s — stream: %d videos synced, cdn: %s, storage: %s',
        $target->toDateString(),
        $stream,
        $cdn ? 'ok' : 'skipped/failed',
        $storage ? 'ok' : 'skipped/failed',
    ));

    return self::SUCCESS;
})->purpose('Pull daily Bunny stats (Stream + CDN + Storage) and store snapshots');

Artisan::command('content:cleanup-unused-formats {--days=30} {--delete-remote}', function (FormatCleanupService $cleanup) {
    $result = $cleanup->run((int) $this->option('days'), (bool) $this->option('delete-remote'));

    $this->info(sprintf(
        'Format cleanup: %d candidates, %d deactivated, %d remote videos deleted.',
        $result['candidates'],
        $result['deactivated'],
        $result['deleted_remote'],
    ));

    return self::SUCCESS;
})->purpose('Deactivate content formats with zero views in the lookback window');

Artisan::command('analytics:flush-ad-aggregates', function (AdEventTrackingService $tracking) {
    $count = $tracking->flushAggregatesToDatabase();
    $this->info("Flushed {$count} ad aggregate keys from Redis to ad_event_aggregates.");

    return self::SUCCESS;
})->purpose('Flush buffered ad event aggregates from Redis into ad_event_aggregates');

Artisan::command('ad-events:prune {--days=7}', function () {
    $days = (int) $this->option('days');
    $deleted = \App\Models\AdEvent::query()
        ->where('occurred_at', '<', now()->subDays($days))
        ->delete();
    $this->info("Pruned {$deleted} ad_events older than {$days} days.");

    return self::SUCCESS;
})->purpose('Prune raw ad_events older than N days (default 7)');

Schedule::command('analytics:flush-buffers')->everyTenMinutes();
Schedule::command('analytics:flush-ad-aggregates')->everyTenMinutes();
Schedule::command('ad-events:prune --days=7')->dailyAt('03:00');
Schedule::command('analytics:recalculate-costs')->hourly();
Schedule::command('bunny:pull-stats')->dailyAt('01:30');
Schedule::command('content:cleanup-unused-formats --days=30')->weekly()->sundays()->at('02:00');
