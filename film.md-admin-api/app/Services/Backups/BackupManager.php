<?php

namespace App\Services\Backups;

use App\Jobs\RunBackupJob;
use App\Models\BackupRun;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Throwable;

/**
 * Everything around backup runs except executing them: queueing, the
 * schedule, retention, deletion, file access and health information.
 */
class BackupManager
{
    public function __construct(
        protected BackupSettings $settings,
        protected BackupRunner $runner,
        protected Rclone $rclone,
        protected BackupNotifier $notifier,
    ) {}

    /**
     * @param  array<int, string>|null  $components  null = the components enabled in settings
     */
    public function queueBackup(string $trigger, ?User $user = null, ?array $components = null, bool $dispatch = true): BackupRun
    {
        $this->assertNothingActive();

        $components = collect($components ?? array_keys(array_filter($this->settings->all()['components'])))
            ->intersect(BackupSettings::COMPONENTS)
            ->values()
            ->all();

        if ($components === []) {
            throw new BackupStepException('Selectează cel puțin o componentă pentru backup.');
        }

        $run = BackupRun::query()->create([
            'name' => 'film-md-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(4)),
            'trigger' => $trigger,
            'type' => BackupRun::TYPE_BACKUP,
            'status' => BackupRun::STATUS_QUEUED,
            'requested_by' => $user?->id,
            'components' => $components,
        ]);

        if ($dispatch) {
            $this->dispatch($run);
        }

        return $run->fresh();
    }

    public function queueRestoreTest(BackupRun $source, string $component, ?User $user = null): BackupRun
    {
        $this->assertNothingActive();

        $artifact = collect($source->artifacts ?? [])->firstWhere('component', $component);
        if (! $source->hasFiles() || ! is_array($artifact) || ($artifact['status'] ?? null) !== 'completed' || $artifact['file'] === null) {
            throw new BackupStepException('Backup-ul nu are un fișier local pentru această componentă.');
        }

        if (! in_array($artifact['meta']['format'] ?? null, ['pg_custom', 'sqlite_gzip'], true)) {
            throw new BackupStepException('Testul de restaurare este disponibil doar pentru bazele de date.');
        }

        $run = BackupRun::query()->create([
            'name' => 'restore-test-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(4)),
            'trigger' => 'manual',
            'type' => BackupRun::TYPE_RESTORE_TEST,
            'source_run_id' => $source->id,
            'status' => BackupRun::STATUS_QUEUED,
            'requested_by' => $user?->id,
            'components' => [$component],
        ]);

        $this->dispatch($run);

        return $run->fresh();
    }

    /**
     * Called every minute by the scheduler.
     */
    public function tick(): ?BackupRun
    {
        if (! $this->settings->isDue()) {
            return null;
        }

        // Guard against a second scheduler instance firing the same minute.
        if (! Cache::add('backups:scheduled:'.now()->format('YmdHi'), true, 120)) {
            return null;
        }

        try {
            return $this->queueBackup('scheduled');
        } catch (BackupStepException) {
            return null;
        }
    }

    /**
     * Marks runs whose worker died as failed and emails when no backup has
     * succeeded for too long. Called hourly.
     *
     * @return array{recovered: int, stale_alert: bool}
     */
    public function monitor(): array
    {
        $recovered = 0;
        $runningDeadline = now()->subSeconds(config('backups.timeout') + 600);

        BackupRun::query()
            ->where(function ($query) use ($runningDeadline): void {
                $query->where(fn ($q) => $q->where('status', BackupRun::STATUS_RUNNING)->where('started_at', '<', $runningDeadline))
                    ->orWhere(fn ($q) => $q->where('status', BackupRun::STATUS_QUEUED)->where('created_at', '<', now()->subHours(6)));
            })
            ->get()
            ->each(function (BackupRun $run) use (&$recovered): void {
                $message = $run->status === BackupRun::STATUS_QUEUED
                    ? 'Nu a fost preluat de niciun worker în 6 ore. Verifică containerul backup-worker.'
                    : 'Workerul s-a oprit în timpul backup-ului (timeout sau restart).';
                $run->forceFill([
                    'status' => BackupRun::STATUS_FAILED,
                    'error_message' => $message,
                    'finished_at' => now(),
                    'log' => ltrim(($run->log ?? '')."\n[".now()->format('H:i:s').'] '.$message),
                ])->save();
                $this->notifier->runFinished($run);
                $recovered++;
            });

        $settings = $this->settings->all();
        $hours = $settings['notifications']['stale_after_hours'];
        $lastSuccess = $this->lastSuccessfulBackup();
        $isStale = $settings['enabled'] && ($lastSuccess === null || $lastSuccess->finished_at->lt(now()->subHours($hours)));
        $alerted = false;

        if ($isStale) {
            $lastAlert = $this->settings->state()['last_stale_alert_at'] ?? null;
            if ($lastAlert === null || Carbon::parse($lastAlert)->lt(now()->subHours($hours))) {
                $this->notifier->stale($lastSuccess?->finished_at?->toIso8601String(), $hours);
                $this->settings->putState(['last_stale_alert_at' => now()->toIso8601String()]);
                $alerted = true;
            }
        }

        return ['recovered' => $recovered, 'stale_alert' => $alerted];
    }

    /**
     * Retention: keep the newest `keep_last` successful backups no matter
     * their age, delete the rest once older than `keep_days`. Locked backups
     * are never deleted.
     *
     * @return array<int, string> names of pruned runs
     */
    public function prune(): array
    {
        $retention = $this->settings->all()['retention'];
        $cutoff = now()->subDays($retention['keep_days']);

        $candidates = BackupRun::query()
            ->where('type', BackupRun::TYPE_BACKUP)
            ->whereIn('status', [BackupRun::STATUS_COMPLETED, BackupRun::STATUS_PARTIAL])
            ->whereNull('files_deleted_at')
            ->orderByDesc('created_at')
            ->get()
            ->slice($retention['keep_last'])
            ->filter(fn (BackupRun $run): bool => ! $run->is_locked && $run->created_at->lt($cutoff));

        $pruned = [];
        foreach ($candidates as $run) {
            $this->deleteFiles($run, $this->settings->all()['remote']['prune']);
            $pruned[] = $run->name;
        }

        return $pruned;
    }

    public function deleteFiles(BackupRun $run, bool $includeRemote): void
    {
        File::deleteDirectory($this->runner->runDirectory($run));

        $remoteError = null;
        if ($includeRemote && $run->remote_status === 'uploaded' && filled($run->remote_path)) {
            try {
                $result = $this->rclone->run(['purge', $run->remote_path], 1800);
                if (! $result->successful() && ! str_contains($result->errorOutput(), 'directory not found')) {
                    $remoteError = trim($result->errorOutput());
                }

                $destination = $this->settings->all()['remote']['destination'];
                if (filled($destination)) {
                    $this->rclone->run(['purge', $this->rclone->join($destination, 'media-deleted/'.$run->name)], 1800);
                }
            } catch (Throwable $exception) {
                $remoteError = $exception->getMessage();
            }
        }

        $run->forceFill([
            'files_deleted_at' => now(),
            'remote_status' => $includeRemote && $run->remote_status === 'uploaded' && $remoteError === null ? 'deleted' : $run->remote_status,
            'remote_error' => $remoteError ?? $run->remote_error,
        ])->save();
    }

    public function delete(BackupRun $run, bool $includeRemote): void
    {
        if ($run->is_locked) {
            throw new BackupStepException('Backup-ul este blocat. Deblochează-l înainte de ștergere.');
        }

        if (! $run->isFinished()) {
            throw new BackupStepException('Backup-ul încă rulează.');
        }

        if ($run->type === BackupRun::TYPE_BACKUP && $run->files_deleted_at === null) {
            $this->deleteFiles($run, $includeRemote);
        }

        $run->delete();
    }

    public function filePath(BackupRun $run, string $file): ?string
    {
        $allowed = collect($run->artifacts ?? [])->pluck('file')->filter()->push('manifest.json');
        if (! $allowed->contains($file) || $file !== basename($file) || ! $run->hasFiles()) {
            return null;
        }

        $path = $this->runner->runDirectory($run).'/'.$file;

        return is_file($path) ? $path : null;
    }

    public function preMigrationPath(string $file): ?string
    {
        if ($file !== basename($file) || ! preg_match('/^pre-migration-[0-9TZ]+\.dump$/', $file)) {
            return null;
        }

        $path = rtrim((string) config('backups.pre_migration_directory'), '/').'/'.$file;

        return is_file($path) ? $path : null;
    }

    /**
     * @return Collection<int, array{name: string, size: int, created_at: string}>
     */
    public function preMigrationDumps(): Collection
    {
        $directory = (string) config('backups.pre_migration_directory');
        if (! is_dir($directory)) {
            return collect();
        }

        return collect(glob(rtrim($directory, '/').'/pre-migration-*.dump') ?: [])
            ->map(fn (string $path): array => [
                'name' => basename($path),
                'size' => (int) filesize($path),
                'created_at' => Carbon::createFromTimestamp((int) filemtime($path))->toIso8601String(),
            ])
            ->sortByDesc('created_at')
            ->values();
    }

    public function lastSuccessfulBackup(): ?BackupRun
    {
        return BackupRun::query()
            ->where('type', BackupRun::TYPE_BACKUP)
            ->whereIn('status', [BackupRun::STATUS_COMPLETED, BackupRun::STATUS_PARTIAL])
            ->latest('finished_at')
            ->first();
    }

    public function activeRun(): ?BackupRun
    {
        return BackupRun::query()
            ->whereIn('status', [BackupRun::STATUS_QUEUED, BackupRun::STATUS_RUNNING])
            ->latest()
            ->first();
    }

    public function overview(): array
    {
        $settings = $this->settings->all();
        $lastSuccess = $this->lastSuccessfulBackup();
        $lastRun = BackupRun::query()->where('type', BackupRun::TYPE_BACKUP)->latest()->first();
        $directory = (string) config('backups.directory');
        $hours = $settings['notifications']['stale_after_hours'];

        $storedBytes = (int) BackupRun::query()
            ->where('type', BackupRun::TYPE_BACKUP)
            ->whereNull('files_deleted_at')
            ->sum('size_bytes');

        return [
            'last_success_at' => $lastSuccess?->finished_at?->toIso8601String(),
            'last_run_status' => $lastRun?->status,
            'is_stale' => $settings['enabled'] && ($lastSuccess === null || $lastSuccess->finished_at->lt(now()->subHours($hours))),
            'next_run_at' => $this->settings->nextRunAt()?->toIso8601String(),
            'stored_backups' => BackupRun::query()->where('type', BackupRun::TYPE_BACKUP)->whereNull('files_deleted_at')->whereIn('status', [BackupRun::STATUS_COMPLETED, BackupRun::STATUS_PARTIAL])->count(),
            'stored_bytes' => $storedBytes,
            'disk' => [
                'path' => $directory,
                'writable' => $this->directoryWritable($directory),
                'free_bytes' => $this->diskValue(fn () => disk_free_space($this->existingParent($directory))),
                'total_bytes' => $this->diskValue(fn () => disk_total_space($this->existingParent($directory))),
            ],
            'active_run_id' => $this->activeRun()?->id,
        ];
    }

    /**
     * Which binaries exist in this container, and whether pg_dump can dump
     * the running PostgreSQL server (it refuses newer major versions).
     */
    public function tools(): array
    {
        return Cache::remember('backups:tools', 300, function (): array {
            $tools = [
                'pg_dump' => [config('backups.binaries.pg_dump'), '--version'],
                'pg_restore' => [config('backups.binaries.pg_restore'), '--version'],
                'psql' => [config('backups.binaries.psql'), '--version'],
                'redis_cli' => [config('backups.binaries.redis_cli'), '--version'],
                'rclone' => [config('backups.binaries.rclone'), 'version'],
                'openssl' => [config('backups.binaries.openssl'), 'version'],
            ];

            $result = [];
            foreach ($tools as $key => $command) {
                try {
                    $process = Process::timeout(10)->run($command);
                    $version = $process->successful() ? trim(strtok($process->output(), "\n") ?: '') : null;
                } catch (Throwable) {
                    $version = null;
                }
                $result[$key] = ['available' => $version !== null && $version !== '', 'version' => $version];
            }

            $serverVersion = null;
            try {
                if (DB::connection()->getDriverName() === 'pgsql') {
                    $serverVersion = (string) DB::connection()->selectOne('SHOW server_version')->server_version;
                }
            } catch (Throwable) {
            }

            $clientMajor = preg_match('/(\d+)(?:\.\d+)?/', (string) ($result['pg_dump']['version'] ?? ''), $m) ? (int) $m[1] : null;
            $serverMajor = $serverVersion !== null && preg_match('/^(\d+)/', $serverVersion, $s) ? (int) $s[1] : null;

            $result['postgres_server'] = [
                'version' => $serverVersion,
                'compatible' => $clientMajor === null || $serverMajor === null || $clientMajor >= $serverMajor,
            ];

            return $result;
        });
    }

    public function testRemote(?string $destination = null): array
    {
        $destination = trim($destination ?? $this->settings->all()['remote']['destination']);
        if ($destination === '') {
            return ['ok' => false, 'message' => 'Completează destinația (ex: gdrive:film-md-backups).'];
        }

        try {
            $marker = $this->rclone->join($destination, '.filmmd-backup-check');
            $write = $this->rclone->run(['touch', $marker], 120);
            if (! $write->successful()) {
                return ['ok' => false, 'message' => trim($write->errorOutput()) ?: 'rclone nu poate scrie în destinație.'];
            }

            $list = $this->rclone->run(['lsf', $destination, '--max-depth', '1'], 120);
            $this->rclone->run(['deletefile', $marker], 120);

            $entries = collect(preg_split('/\R/', trim($list->output())))->filter()->values();

            return [
                'ok' => true,
                'message' => sprintf('Conexiune reușită. Destinația conține %d elemente.', max(0, $entries->count() - 1)),
            ];
        } catch (Throwable $exception) {
            return ['ok' => false, 'message' => $exception->getMessage()];
        }
    }

    public function dispatch(BackupRun $run): void
    {
        RunBackupJob::dispatch($run->id)
            ->onConnection(config('backups.queue_connection'))
            ->onQueue(config('backups.queue'));
    }

    protected function assertNothingActive(): void
    {
        $active = $this->activeRun();
        if ($active !== null) {
            throw new BackupStepException(sprintf('Există deja o operațiune în curs (%s, %s).', $active->name, $active->status));
        }
    }

    protected function directoryWritable(string $directory): bool
    {
        try {
            File::ensureDirectoryExists($directory, 0750);

            return is_writable($directory);
        } catch (Throwable) {
            return false;
        }
    }

    protected function existingParent(string $directory): string
    {
        while ($directory !== '/' && $directory !== '' && ! is_dir($directory)) {
            $directory = dirname($directory);
        }

        return $directory === '' ? '/' : $directory;
    }

    protected function diskValue(callable $callback): ?int
    {
        try {
            $value = $callback();

            return $value === false ? null : (int) $value;
        } catch (Throwable) {
            return null;
        }
    }
}
