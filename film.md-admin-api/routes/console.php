<?php

use App\Models\AdEvent;
use App\Models\BackupRun;
use App\Services\Backups\BackupManager;
use App\Services\Backups\BackupRestorer;
use App\Services\Backups\BackupRunner;
use App\Services\Backups\BackupStepException;
use App\Services\AdEventTrackingService;
use App\Services\AnalyticsBufferService;
use App\Services\BunnyStatsService;
use App\Services\ContentSearchService;
use App\Services\FormatCleanupService;
use App\Services\MediaUrlMigrationService;
use App\Services\PayFilmotecaPaymentService;
use App\Services\RightsReportingService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('reporting:sync', function (RightsReportingService $reporting) {
    $result = $reporting->syncAll();
    $this->info("Captured {$result['captured']} new sales, repaired {$result['repaired']}, still pending {$result['still_pending']}.");

    return self::SUCCESS;
})->purpose('Capture new purchases into rights reporting and repair any sale left without a contract/fiscal profile');

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

Artisan::command(
    'media:migrate-cdn-urls {--from= : Legacy public CDN base URL} {--to= : New public CDN base URL} {--apply : Persist the replacements}',
    function (MediaUrlMigrationService $migration) {
        $from = (string) ($this->option('from') ?: config('filesystems.disks.s3.legacy_url'));
        $to = (string) ($this->option('to') ?: config('filesystems.disks.s3.url'));
        $apply = (bool) $this->option('apply');

        if ($from === '' || $to === '') {
            $this->error('Set AWS_LEGACY_URL and AWS_URL, or provide both --from and --to.');

            return self::FAILURE;
        }

        try {
            $result = $migration->migrate($from, $to, $apply);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $rows = collect($result['by_table'])
            ->map(fn (array $stats, string $table): array => [
                $table,
                $stats['records'],
                $stats['urls'],
            ])
            ->values()
            ->all();

        if ($rows !== []) {
            $this->table(['Table', 'Records', 'URLs'], $rows);
        }

        $mode = $apply ? 'Applied' : 'Dry-run';
        $this->info("{$mode}: {$result['urls']} URL(s) in {$result['records']} record(s).");

        if (! $apply && $result['urls'] > 0) {
            $this->warn('No data was changed. Run the same command with --apply after reviewing the totals.');
        }

        return self::SUCCESS;
    },
)->purpose('Safely migrate stored media URLs from a legacy R2 origin to the production CDN');

Artisan::command('analytics:flush-ad-aggregates', function (AdEventTrackingService $tracking) {
    $count = $tracking->flushAggregatesToDatabase();
    $this->info("Flushed {$count} ad aggregate keys from Redis to ad_event_aggregates.");

    return self::SUCCESS;
})->purpose('Flush buffered ad event aggregates from Redis into ad_event_aggregates');

Artisan::command('ad-events:prune {--days=7}', function () {
    $days = (int) $this->option('days');
    $deleted = AdEvent::query()
        ->where('occurred_at', '<', now()->subDays($days))
        ->delete();
    $this->info("Pruned {$deleted} ad_events older than {$days} days.");

    return self::SUCCESS;
})->purpose('Prune raw ad_events older than N days (default 7)');

Artisan::command('payments:poll-topups {--limit=50}', function (PayFilmotecaPaymentService $payments) {
    $stats = $payments->pollPendingTopUps((int) $this->option('limit'));

    $this->info(sprintf(
        'Payment top-ups checked: %d, paid: %d, failed: %d, canceled: %d, refunded: %d, still processing: %d.',
        $stats['checked'],
        $stats['paid'],
        $stats['failed'],
        $stats['canceled'],
        $stats['refunded'],
        $stats['processing'],
    ));

    return self::SUCCESS;
})->purpose('Poll pay.filmoteca.md payment details and settle pending wallet top-ups');

Artisan::command('backups:run {--component=* : database, analytics, redis, media (default: the ones enabled in admin)}', function (BackupManager $manager, BackupRunner $runner) {
    try {
        $components = $this->option('component') ?: null;
        $run = $manager->queueBackup('cli', null, $components, dispatch: false);
    } catch (BackupStepException $exception) {
        $this->error($exception->getMessage());

        return self::FAILURE;
    }

    $this->info("Backup {$run->name} pornit…");
    $run = $runner->run($run);
    if (in_array($run->status, [BackupRun::STATUS_COMPLETED, BackupRun::STATUS_PARTIAL], true)) {
        $manager->prune();
    }

    $this->line($run->log ?? '');

    return $run->status === BackupRun::STATUS_COMPLETED ? self::SUCCESS : self::FAILURE;
})->purpose('Run a backup now, in this process (same as "Backup acum" in the admin)');

Artisan::command('backups:schedule', function (BackupManager $manager) {
    $run = $manager->tick();
    if ($run !== null) {
        $this->info("Scheduled backup {$run->name} queued.");
    }

    return self::SUCCESS;
})->purpose('Queue a backup when the schedule configured in the admin is due');

Artisan::command('backups:monitor', function (BackupManager $manager) {
    $result = $manager->monitor();
    $this->info("Recovered {$result['recovered']} stuck run(s)".($result['stale_alert'] ? ', stale alert sent.' : '.'));

    return self::SUCCESS;
})->purpose('Fail backups whose worker died and alert when no backup succeeded recently');

Artisan::command('backups:prune', function (BackupManager $manager) {
    $pruned = $manager->prune();
    $this->info('Pruned '.count($pruned).' backup(s).');

    return self::SUCCESS;
})->purpose('Apply the backup retention policy configured in the admin');

Artisan::command(
    'backups:restore {run : Backup name or id} {--component=database : database or analytics} {--no-safety-backup : Skip the backup taken right before restoring} {--force : Do not ask for confirmation}',
    function (BackupRestorer $restorer, BackupRunner $runner) {
        $identifier = (string) $this->argument('run');
        $source = BackupRun::query()
            ->where('type', BackupRun::TYPE_BACKUP)
            ->where(fn ($query) => $query->where('name', $identifier)->orWhere('id', ctype_digit($identifier) ? (int) $identifier : 0))
            ->first();

        if ($source === null) {
            $this->error("Backup-ul {$identifier} nu există.");

            return self::FAILURE;
        }

        $component = (string) $this->option('component');
        $database = DB::connection($runner->connectionFor($component))->getDatabaseName();

        $this->warn("Restaurarea înlocuiește TOATE datele din baza {$database} cu cele din {$source->name} ({$source->created_at}).");
        $this->warn('Oprește înainte containerele app, queue, scheduler și backup-worker, ca nimeni să nu scrie în bază.');

        if (! $this->option('force') && $this->ask("Scrie numele bazei ({$database}) pentru confirmare") !== $database) {
            $this->error('Confirmare greșită. Nu s-a modificat nimic.');

            return self::FAILURE;
        }

        try {
            $restorer->restore($source, $component, fn (string $line) => $this->line($line), ! $this->option('no-safety-backup'));
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Restaurare reușită. Rulează apoi: php artisan optimize:clear && php artisan search:reindex-content');

        return self::SUCCESS;
    },
)->purpose('Restore a backup over the live PostgreSQL database (takes a safety backup first)');

Schedule::command('reporting:sync')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('analytics:flush-buffers')->everyTenMinutes();
Schedule::command('analytics:flush-ad-aggregates')->everyTenMinutes();
Schedule::command('ad-events:prune --days=7')->dailyAt('03:00');
Schedule::command('payments:poll-topups --limit=50')->everyMinute()->withoutOverlapping();
Schedule::command('analytics:recalculate-costs')->hourly();
Schedule::command('bunny:pull-stats')->dailyAt('01:30');
Schedule::command('content:cleanup-unused-formats --days=30')->weekly()->sundays()->at('02:00');
Schedule::command('backups:schedule')->everyMinute()->withoutOverlapping();
Schedule::command('backups:monitor')->hourly();
Schedule::command('backups:prune')->dailyAt('04:30');
