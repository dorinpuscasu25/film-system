<?php

use App\Services\AnalyticsBufferService;
use App\Services\ContentSearchService;
use Illuminate\Foundation\Inspiring;
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

Schedule::command('analytics:flush-buffers')->everyTenMinutes();
Schedule::command('analytics:recalculate-costs')->hourly();
