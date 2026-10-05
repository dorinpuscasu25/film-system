<?php

namespace App\Services\Backups;

use App\Models\BackupRun;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Restores a backup over the live database. Only reachable from the CLI
 * (`php artisan backups:restore`): it replaces production data and must not
 * run inside the web process it would be replacing.
 */
class BackupRestorer
{
    public function __construct(
        protected BackupManager $manager,
        protected BackupRunner $runner,
    ) {}

    /**
     * @param  Closure(string): void  $output
     */
    public function restore(BackupRun $source, string $component, Closure $output, bool $safetyBackup = true): void
    {
        $artifact = collect($source->artifacts ?? [])->firstWhere('component', $component);
        if (! is_array($artifact) || ($artifact['meta']['format'] ?? null) !== 'pg_custom') {
            throw new BackupStepException('Restaurarea din CLI este disponibilă doar pentru dump-urile PostgreSQL.');
        }

        $path = $this->manager->filePath($source, (string) $artifact['file']);
        if ($path === null) {
            throw new BackupStepException('Fișierul '.$artifact['file'].' nu mai există local. Descarcă-l din copia off-site în '.$this->runner->runDirectory($source).'.');
        }

        if (hash_file('sha256', $path) !== $artifact['sha256']) {
            throw new BackupStepException('Checksum SHA-256 diferit: fișierul a fost modificat sau corupt.');
        }

        $config = DB::connection($this->runner->connectionFor($component))->getConfig();

        if ($safetyBackup) {
            $output('Backup de siguranță înainte de restaurare…');
            $safety = $this->manager->queueBackup('pre_restore', null, [$component], dispatch: false);
            $safety = $this->runner->run($safety);
            if ($safety->status !== BackupRun::STATUS_COMPLETED) {
                throw new BackupStepException('Backup-ul de siguranță a eșuat ('.$safety->error_message.'). Restaurarea a fost anulată.');
            }
            $safety->forceFill(['is_locked' => true, 'note' => 'Backup automat înainte de restaurarea din '.$source->name])->save();
            $output('Backup de siguranță: '.$safety->name.' (blocat, nu va fi șters automat)');
        }

        // A record for the restore itself, so it shows up in the admin history.
        $log = BackupRun::query()->create([
            'name' => 'restore-'.now()->format('Ymd-His'),
            'trigger' => 'cli',
            'type' => BackupRun::TYPE_RESTORE,
            'source_run_id' => $source->id,
            'status' => BackupRun::STATUS_RUNNING,
            'components' => [$component],
            'started_at' => now(),
            'note' => 'Restaurare completă peste '.$config['database'],
        ]);

        // The dump carries backup_runs and the backup settings as they were
        // when it was taken. Keep today's history and configuration instead.
        $preserved = $component === 'database' ? $this->captureBackupState() : null;

        try {
            $file = $path;
            if (str_ends_with($path, '.enc')) {
                $output('Decriptare…');
                $file = $this->runner->decrypt($log, $path);
            }

            try {
                $output('pg_restore peste '.$config['database'].'…');
                $this->runner->runProcess($log, [
                    config('backups.binaries.pg_restore'),
                    ...$this->runner->pgConnectionArgs($config),
                    '--clean',
                    '--if-exists',
                    '--no-owner',
                    '--no-privileges',
                    '--single-transaction',
                    '--exit-on-error',
                    '--dbname='.$config['database'],
                    $file,
                ], $this->runner->pgEnv($config));
            } finally {
                if ($file !== $path) {
                    @unlink($file);
                }
            }

            if ($preserved !== null) {
                $this->restoreBackupState($preserved);
            }

            $this->runner->log($log, 'Restaurare completă reușită.', true);
            $log->forceFill(['status' => BackupRun::STATUS_COMPLETED, 'finished_at' => now()])->save();
        } catch (Throwable $exception) {
            // pg_restore runs in a single transaction: on failure nothing changed.
            $this->runner->log($log, 'Eșuat: '.$exception->getMessage(), true);
            $log->forceFill(['status' => BackupRun::STATUS_FAILED, 'error_message' => $exception->getMessage(), 'finished_at' => now()])->save();

            throw $exception;
        }
    }

    /**
     * @return array{runs: array<int, array<string, mixed>>, settings: array<int, array<string, mixed>>}
     */
    protected function captureBackupState(): array
    {
        $connection = DB::connection($this->runner->mainConnection());

        return [
            'runs' => $connection->table('backup_runs')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'settings' => $connection->table('platform_settings')
                ->whereIn('key', [BackupSettings::SETTINGS_KEY, BackupSettings::STATE_KEY])
                ->get()
                ->map(fn (object $row): array => (array) $row)
                ->all(),
        ];
    }

    /**
     * @param  array{runs: array<int, array<string, mixed>>, settings: array<int, array<string, mixed>>}  $state
     */
    protected function restoreBackupState(array $state): void
    {
        $connection = DB::connection($this->runner->mainConnection());
        $connection->reconnect();

        $connection->transaction(function () use ($connection, $state): void {
            $userIds = $connection->table('users')->pluck('id')->flip();

            $connection->table('backup_runs')->delete();
            foreach (array_chunk($state['runs'], 200) as $chunk) {
                $connection->table('backup_runs')->insert(array_map(function (array $row) use ($userIds): array {
                    // The restored users table may not contain newer accounts.
                    if ($row['requested_by'] !== null && ! $userIds->has($row['requested_by'])) {
                        $row['requested_by'] = null;
                    }

                    return $row;
                }, $chunk));
            }

            foreach ($state['settings'] as $setting) {
                $connection->table('platform_settings')->updateOrInsert(['key' => $setting['key']], collect($setting)->except(['id', 'key'])->all());
            }

            if ($connection->getDriverName() === 'pgsql' && $state['runs'] !== []) {
                $connection->statement("SELECT setval(pg_get_serial_sequence('backup_runs', 'id'), (SELECT MAX(id) FROM backup_runs))");
            }
        });
    }
}
