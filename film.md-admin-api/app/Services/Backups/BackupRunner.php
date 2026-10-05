<?php

namespace App\Services\Backups;

use App\Models\BackupRun;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use PDO;
use Throwable;

/**
 * Executes backup and restore-test runs. Every step is written to the run's
 * log as it happens, so the admin can follow a running backup live.
 */
class BackupRunner
{
    /**
     * Tables whose row counts are compared after a test restore. Missing
     * tables are ignored.
     */
    protected const KEY_TABLES = [
        'users',
        'contents',
        'wallet_transactions',
        'payment_top_ups',
        'content_entitlements',
        'reporting_sales',
    ];

    protected ?float $lastLogFlush = null;

    public function __construct(
        protected BackupSettings $settings,
        protected Rclone $rclone,
        protected BackupNotifier $notifier,
    ) {}

    public function run(BackupRun $run): BackupRun
    {
        $lock = Cache::lock('backups:runner', config('backups.timeout') + 600);
        if (! $lock->get()) {
            return $this->finish($run, BackupRun::STATUS_FAILED, 'Un alt backup rulează deja. Încearcă din nou după ce se termină.');
        }

        try {
            return $run->type === BackupRun::TYPE_RESTORE_TEST
                ? $this->runRestoreTest($run)
                : $this->runBackup($run);
        } finally {
            $lock->release();
        }
    }

    protected function runBackup(BackupRun $run): BackupRun
    {
        $settings = $this->settings->all();
        $components = $run->components ?: array_keys(array_filter($settings['components']));
        $encrypt = $this->settings->encryptionActive();
        $remoteEnabled = $this->settings->remoteEnabled();
        $directory = $this->runDirectory($run);

        $run->forceFill([
            'status' => BackupRun::STATUS_RUNNING,
            'started_at' => now(),
            'components' => $components,
            'encrypted' => $encrypt,
            'remote_status' => $remoteEnabled ? 'pending' : 'skipped',
        ])->save();

        $this->log($run, 'Backup pornit: '.implode(', ', $components), true);

        try {
            File::ensureDirectoryExists($directory, 0750);

            $artifacts = [];
            foreach (BackupSettings::COMPONENTS as $component) {
                if (! in_array($component, $components, true)) {
                    continue;
                }

                $artifacts[] = $this->backupComponent($run, $component, $directory, $remoteEnabled);
                $run->forceFill(['artifacts' => $artifacts])->save();
            }

            if ($encrypt) {
                $artifacts = $this->encryptArtifacts($run, $artifacts, $directory);
            }

            $this->writeManifest($run, $artifacts, $directory);

            $run->forceFill([
                'artifacts' => $artifacts,
                'size_bytes' => collect($artifacts)->sum(fn (array $artifact): int => (int) ($artifact['size'] ?? 0)),
            ])->save();

            $produced = collect($artifacts)->where('status', 'completed')->whereNotNull('file')->isNotEmpty();
            if ($remoteEnabled && $produced) {
                $this->uploadToRemote($run, $directory);
            } elseif ($remoteEnabled) {
                $run->forceFill(['remote_status' => 'skipped'])->save();
            }

            $status = $this->resolveStatus($run->fresh(), $artifacts);
            $error = $status === BackupRun::STATUS_COMPLETED ? null : $this->summarizeErrors($run->fresh(), $artifacts);
            $run = $this->finish($run, $status, $error);
        } catch (Throwable $exception) {
            report($exception);
            $run = $this->finish($run, BackupRun::STATUS_FAILED, $exception->getMessage());
        }

        if (! $run->isFinished() || $run->status === BackupRun::STATUS_FAILED) {
            File::deleteDirectory($directory);
            $run->forceFill(['files_deleted_at' => now()])->save();
        }

        if (in_array($run->status, [BackupRun::STATUS_COMPLETED, BackupRun::STATUS_PARTIAL], true)) {
            $this->settings->putState(['last_success_at' => now()->toIso8601String()]);
        }

        $this->notifier->runFinished($run);

        return $run;
    }

    /**
     * @return array<string, mixed>
     */
    protected function backupComponent(BackupRun $run, string $component, string $directory, bool $remoteEnabled): array
    {
        $artifact = [
            'component' => $component,
            'label' => $this->componentLabel($component),
            'file' => null,
            'size' => 0,
            'sha256' => null,
            'status' => 'completed',
            'verified' => false,
            'error' => null,
            'meta' => [],
        ];

        $this->log($run, '— '.$artifact['label']);
        $startedAt = microtime(true);

        try {
            $artifact = match ($component) {
                'database' => $this->dumpDatabase($run, $artifact, $this->mainConnection(), $directory),
                'analytics' => $this->dumpAnalytics($run, $artifact, $directory),
                'redis' => $this->dumpRedis($run, $artifact, $directory),
                'media' => $this->mirrorMedia($run, $artifact, $remoteEnabled),
            };

            if ($artifact['file'] !== null && $artifact['status'] === 'completed') {
                $path = $directory.'/'.$artifact['file'];
                $artifact['size'] = (int) filesize($path);
                $artifact['sha256'] = hash_file('sha256', $path);
            }
        } catch (Throwable $exception) {
            $artifact['status'] = 'failed';
            $artifact['error'] = Str::limit($exception->getMessage(), 2000);
            if ($artifact['file'] !== null) {
                @unlink($directory.'/'.$artifact['file']);
            }
            $artifact['file'] = null;
            $this->log($run, 'EROARE: '.$artifact['error']);
        }

        $artifact['meta']['duration_seconds'] = round(microtime(true) - $startedAt, 1);

        if ($artifact['status'] === 'completed') {
            $this->log($run, sprintf('OK %s (%s)', $artifact['file'] ?? $artifact['label'], $this->humanBytes($artifact['size'] ?: (int) ($artifact['meta']['remote_bytes'] ?? 0))));
        } elseif ($artifact['status'] === 'skipped') {
            $this->log($run, 'Sărit: '.$artifact['error']);
        }

        return $artifact;
    }

    protected function dumpAnalytics(BackupRun $run, array $artifact, string $directory): array
    {
        $main = DB::connection($this->mainConnection())->getConfig();
        $analytics = DB::connection($this->analyticsConnection())->getConfig();

        $same = ($main['driver'] ?? null) === ($analytics['driver'] ?? null)
            && ($main['database'] ?? null) === ($analytics['database'] ?? null)
            && ($main['host'] ?? null) === ($analytics['host'] ?? null)
            && (string) ($main['port'] ?? '') === (string) ($analytics['port'] ?? '');

        if ($same) {
            return $this->skip($artifact, 'Analytics folosește aceeași bază ca aplicația; e inclusă în backup-ul principal.');
        }

        return $this->dumpDatabase($run, $artifact, $this->analyticsConnection(), $directory);
    }

    protected function dumpDatabase(BackupRun $run, array $artifact, string $connection, string $directory): array
    {
        $config = DB::connection($connection)->getConfig();

        return match ($config['driver'] ?? null) {
            'pgsql' => $this->dumpPostgres($run, $artifact, $config, $directory),
            'sqlite' => $this->dumpSqlite($run, $artifact, $config, $directory),
            default => $this->skip($artifact, 'Driverul '.($config['driver'] ?? '?').' nu este suportat pentru backup.'),
        };
    }

    protected function dumpPostgres(BackupRun $run, array $artifact, array $config, string $directory): array
    {
        $database = (string) $config['database'];
        $artifact['file'] = $artifact['component'].'-'.Str::slug($database, '_').'.dump';
        $path = $directory.'/'.$artifact['file'];

        $this->runProcess($run, [
            config('backups.binaries.pg_dump'),
            ...$this->pgConnectionArgs($config),
            '--format=custom',
            '--compress=6',
            '--no-owner',
            '--no-privileges',
            '--file='.$path,
            $database,
        ], $this->pgEnv($config));

        if (! is_file($path) || filesize($path) === 0) {
            throw new BackupStepException('pg_dump nu a produs fișierul de backup.');
        }

        $list = $this->runProcess($run, [config('backups.binaries.pg_restore'), '--list', $path], [], 600, logOutput: false);
        $entries = collect(preg_split('/\R/', $list->output()))
            ->filter(fn (string $line): bool => $line !== '' && ! str_starts_with($line, ';'))
            ->count();

        $artifact['verified'] = true;
        $artifact['meta'] = [
            'database' => $database,
            'format' => 'pg_custom',
            'toc_entries' => $entries,
            'server_version' => $this->postgresServerVersion($config),
        ];

        return $artifact;
    }

    protected function dumpSqlite(BackupRun $run, array $artifact, array $config, string $directory): array
    {
        $database = (string) ($config['database'] ?? '');
        if ($database === '' || $database === ':memory:' || ! is_file($database)) {
            return $this->skip($artifact, 'Baza SQLite nu este un fișier pe disc.');
        }

        $artifact['file'] = $artifact['component'].'-'.Str::slug(pathinfo($database, PATHINFO_FILENAME), '_').'.sqlite.gz';
        $this->gzipFile($database, $directory.'/'.$artifact['file']);
        $this->log($run, 'Copiat fișierul SQLite '.$database);
        $artifact['meta'] = ['database' => basename($database), 'format' => 'sqlite_gzip'];

        return $artifact;
    }

    protected function dumpRedis(BackupRun $run, array $artifact, string $directory): array
    {
        $config = config('database.redis.default', []);
        $rdb = $directory.'/redis.rdb';
        $artifact['file'] = 'redis.rdb.gz';

        $command = [config('backups.binaries.redis_cli'), '-h', (string) ($config['host'] ?? '127.0.0.1'), '-p', (string) ($config['port'] ?? 6379)];
        if (filled($config['username'] ?? null)) {
            array_push($command, '--user', (string) $config['username']);
        }
        if (($config['scheme'] ?? null) === 'tls') {
            $command[] = '--tls';
        }
        array_push($command, '--rdb', $rdb);

        $env = filled($config['password'] ?? null) ? ['REDISCLI_AUTH' => (string) $config['password']] : [];
        $this->runProcess($run, $command, $env, 1800);

        if (! is_file($rdb) || filesize($rdb) === 0) {
            throw new BackupStepException('redis-cli nu a produs fișierul RDB.');
        }

        $this->gzipFile($rdb, $directory.'/'.$artifact['file']);
        @unlink($rdb);
        $artifact['meta'] = ['format' => 'rdb_gzip'];

        return $artifact;
    }

    protected function mirrorMedia(BackupRun $run, array $artifact, bool $remoteEnabled): array
    {
        if (! $remoteEnabled) {
            return $this->skip($artifact, 'Oglinda media are nevoie de o destinație off-site (rclone) activă.');
        }

        $source = $this->rclone->mediaSource();
        if ($source === null) {
            return $this->skip($artifact, 'Bucket-ul S3/R2 nu este configurat (AWS_BUCKET / AWS_ACCESS_KEY_ID).');
        }

        $destination = $this->settings->all()['remote']['destination'];
        $mirror = $this->rclone->join($destination, 'media-mirror');
        // Files removed from the bucket since the last mirror are moved here
        // instead of being deleted, and pruned together with this run.
        $deleted = $this->rclone->join($destination, 'media-deleted/'.$run->name);

        $this->log($run, sprintf('$ rclone sync %s %s --backup-dir %s', $source, $mirror, $deleted));
        $result = $this->rclone->run(
            ['sync', $source, $mirror, '--backup-dir', $deleted, '--transfers', '8', '--checkers', '16', '--stats-one-line', '--stats', '60s', '--stats-log-level', 'NOTICE'],
            config('backups.timeout'),
            fn (string $output) => $this->log($run, $this->tail($output, 600)),
        );
        $this->assertSuccessful($result, 'rclone sync media');

        $size = $this->rclone->run(['size', '--json', $mirror], 1800);
        $stats = json_decode($size->output(), true);

        $artifact['meta'] = [
            'remote_path' => $mirror,
            'remote_bytes' => (int) ($stats['bytes'] ?? 0),
            'remote_objects' => (int) ($stats['count'] ?? 0),
        ];

        return $artifact;
    }

    /**
     * @param  array<int, array<string, mixed>>  $artifacts
     * @return array<int, array<string, mixed>>
     */
    protected function encryptArtifacts(BackupRun $run, array $artifacts, string $directory): array
    {
        $this->log($run, '— Criptare AES-256');

        foreach ($artifacts as $index => $artifact) {
            if ($artifact['status'] !== 'completed' || $artifact['file'] === null) {
                continue;
            }

            $plain = $directory.'/'.$artifact['file'];
            $encrypted = $plain.'.enc';

            try {
                $this->runProcess($run, [
                    config('backups.binaries.openssl'), 'enc', '-aes-256-cbc', '-pbkdf2', '-iter', '200000', '-salt',
                    '-in', $plain, '-out', $encrypted, '-pass', 'env:BACKUP_ENCRYPTION_PASSPHRASE',
                ], ['BACKUP_ENCRYPTION_PASSPHRASE' => (string) config('backups.encryption_passphrase')]);

                @unlink($plain);
                $artifacts[$index]['file'] .= '.enc';
                $artifacts[$index]['size'] = (int) filesize($encrypted);
                $artifacts[$index]['sha256'] = hash_file('sha256', $encrypted);
                $artifacts[$index]['meta']['encrypted'] = true;
            } catch (Throwable $exception) {
                @unlink($plain);
                @unlink($encrypted);
                $artifacts[$index]['status'] = 'failed';
                $artifacts[$index]['file'] = null;
                $artifacts[$index]['error'] = 'Criptarea a eșuat: '.$exception->getMessage();
                $this->log($run, 'EROARE: '.$artifacts[$index]['error']);
            }
        }

        return $artifacts;
    }

    protected function uploadToRemote(BackupRun $run, string $directory): void
    {
        $remotePath = $this->rclone->join($this->settings->all()['remote']['destination'], $run->name);
        $this->log($run, '— Upload off-site: '.$remotePath);
        $run->forceFill(['remote_status' => 'uploading', 'remote_path' => $remotePath])->save();

        try {
            $result = $this->rclone->run(
                ['copy', $directory, $remotePath, '--transfers', '4', '--stats-one-line', '--stats', '60s', '--stats-log-level', 'NOTICE'],
                config('backups.timeout'),
                fn (string $output) => $this->log($run, $this->tail($output, 600)),
            );
            $this->assertSuccessful($result, 'rclone copy');

            $run->forceFill(['remote_status' => 'uploaded', 'remote_error' => null])->save();
            $this->log($run, 'OK upload off-site');
        } catch (Throwable $exception) {
            $run->forceFill(['remote_status' => 'failed', 'remote_error' => Str::limit($exception->getMessage(), 2000)])->save();
            $this->log($run, 'EROARE upload off-site: '.$exception->getMessage());
        }
    }

    protected function writeManifest(BackupRun $run, array $artifacts, string $directory): void
    {
        file_put_contents($directory.'/manifest.json', json_encode([
            'name' => $run->name,
            'app' => config('app.name'),
            'environment' => app()->environment(),
            'created_at' => $run->started_at?->toIso8601String(),
            'encrypted' => $run->encrypted,
            'encryption' => $run->encrypted ? 'openssl enc -aes-256-cbc -pbkdf2 -iter 200000' : null,
            'artifacts' => collect($artifacts)->map(fn (array $artifact): array => collect($artifact)->only(['component', 'file', 'size', 'sha256', 'status', 'meta'])->all())->values(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    protected function runRestoreTest(BackupRun $run): BackupRun
    {
        $source = $run->sourceRun;
        $component = $run->components[0] ?? 'database';
        $run->forceFill(['status' => BackupRun::STATUS_RUNNING, 'started_at' => now()])->save();
        $this->log($run, sprintf('Test de restaurare pentru %s (%s)', $source?->name ?? '?', $component), true);

        $artifact = collect($source?->artifacts ?? [])->firstWhere('component', $component);
        $temporary = [];

        try {
            if ($source === null || ! $source->hasFiles() || ! is_array($artifact) || $artifact['file'] === null) {
                throw new BackupStepException('Fișierul de backup nu mai există local.');
            }

            $path = $this->runDirectory($source).'/'.$artifact['file'];
            if (! is_file($path)) {
                throw new BackupStepException('Fișierul '.$artifact['file'].' lipsește de pe disc.');
            }

            if (hash_file('sha256', $path) !== $artifact['sha256']) {
                throw new BackupStepException('Checksum SHA-256 diferit: fișierul a fost modificat sau corupt.');
            }
            $this->log($run, 'OK checksum SHA-256');

            if (str_ends_with($path, '.enc')) {
                $path = $this->decrypt($run, $path);
                $temporary[] = $path;
            }

            $result = match ($artifact['meta']['format'] ?? null) {
                'pg_custom' => $this->restoreTestPostgres($run, $path, $component),
                'sqlite_gzip' => $this->restoreTestSqlite($run, $path, $temporary),
                default => throw new BackupStepException('Testul de restaurare este disponibil doar pentru bazele de date.'),
            };

            $run->forceFill(['artifacts' => [array_merge(['component' => $component, 'status' => 'completed'], $result)]])->save();
            $run = $this->finish($run, BackupRun::STATUS_COMPLETED);
        } catch (Throwable $exception) {
            report($exception);
            $run = $this->finish($run, BackupRun::STATUS_FAILED, $exception->getMessage());
        } finally {
            foreach ($temporary as $file) {
                @unlink($file);
            }
        }

        $this->notifier->runFinished($run);

        return $run;
    }

    protected function restoreTestPostgres(BackupRun $run, string $path, string $component): array
    {
        $config = DB::connection($this->connectionFor($component))->getConfig();
        $database = 'filmmd_restore_check_'.$run->id;
        $admin = [...$config, 'database' => 'postgres'];

        $this->psql($run, $admin, 'DROP DATABASE IF EXISTS "'.$database.'"');
        $this->psql($run, $admin, 'CREATE DATABASE "'.$database.'"');

        try {
            $this->runProcess($run, [
                config('backups.binaries.pg_restore'),
                ...$this->pgConnectionArgs($config),
                '--no-owner',
                '--no-privileges',
                '--exit-on-error',
                '--dbname='.$database,
                $path,
            ], $this->pgEnv($config));
            $this->log($run, 'OK pg_restore în baza temporară '.$database);

            $restoredConfig = [...$config, 'database' => $database];
            $tables = (int) trim($this->psql($run, $restoredConfig, "SELECT count(*) FROM information_schema.tables WHERE table_schema = 'public'"));
            $counts = [];
            foreach (self::KEY_TABLES as $table) {
                $exists = trim($this->psql($run, $restoredConfig, "SELECT to_regclass('public.{$table}') IS NOT NULL"));
                if ($exists !== 't') {
                    continue;
                }
                $counts[$table] = [
                    'restored' => (int) trim($this->psql($run, $restoredConfig, 'SELECT count(*) FROM "'.$table.'"')),
                    'live' => $this->liveCount($component, $table),
                ];
            }

            $this->log($run, sprintf('Tabele restaurate: %d', $tables));
            foreach ($counts as $table => $count) {
                $this->log($run, sprintf('  %s: %d rânduri în backup, %s acum', $table, $count['restored'], $count['live'] ?? '?'));
            }

            return ['tables' => $tables, 'row_counts' => $counts];
        } finally {
            try {
                $this->psql($run, $admin, 'DROP DATABASE IF EXISTS "'.$database.'" WITH (FORCE)');
                $this->log($run, 'Baza temporară a fost ștearsă.');
            } catch (Throwable $exception) {
                $this->log($run, 'ATENȚIE: baza temporară '.$database.' nu a putut fi ștearsă: '.$exception->getMessage());
            }
        }
    }

    protected function restoreTestSqlite(BackupRun $run, string $path, array &$temporary): array
    {
        $plain = $path.'.check.sqlite';
        $temporary[] = $plain;
        $this->gunzipFile($path, $plain);

        $pdo = new PDO('sqlite:'.$plain);
        $integrity = (string) $pdo->query('PRAGMA integrity_check')->fetchColumn();
        if ($integrity !== 'ok') {
            throw new BackupStepException('SQLite integrity_check: '.$integrity);
        }

        $tables = (int) $pdo->query("SELECT count(*) FROM sqlite_master WHERE type = 'table'")->fetchColumn();
        $this->log($run, sprintf('OK integrity_check, %d tabele', $tables));

        return ['tables' => $tables, 'row_counts' => []];
    }

    protected function liveCount(string $component, string $table): ?int
    {
        try {
            return DB::connection($this->connectionFor($component))->table($table)->count();
        } catch (Throwable) {
            return null;
        }
    }

    public function decrypt(BackupRun $run, string $path, ?string $target = null): string
    {
        if (! filled(config('backups.encryption_passphrase'))) {
            throw new BackupStepException('Backup-ul este criptat, dar BACKUP_ENCRYPTION_PASSPHRASE nu este setat.');
        }

        $target ??= substr($path, 0, -4).'.dec-'.Str::lower(Str::random(6));
        $this->runProcess($run, [
            config('backups.binaries.openssl'), 'enc', '-d', '-aes-256-cbc', '-pbkdf2', '-iter', '200000',
            '-in', $path, '-out', $target, '-pass', 'env:BACKUP_ENCRYPTION_PASSPHRASE',
        ], ['BACKUP_ENCRYPTION_PASSPHRASE' => (string) config('backups.encryption_passphrase')]);
        $this->log($run, 'OK decriptare');

        return $target;
    }

    public function psql(BackupRun $run, array $config, string $sql): string
    {
        return $this->runProcess($run, [
            config('backups.binaries.psql'),
            ...$this->pgConnectionArgs($config),
            '--dbname='.$config['database'],
            '--no-psqlrc',
            '--tuples-only',
            '--no-align',
            '--set=ON_ERROR_STOP=1',
            '--command='.$sql,
        ], $this->pgEnv($config), 300, logOutput: false, quiet: true)->output();
    }

    /**
     * @return array<int, string>
     */
    public function pgConnectionArgs(array $config): array
    {
        return [
            '--host='.($config['host'] ?? '127.0.0.1'),
            '--port='.($config['port'] ?? 5432),
            '--username='.($config['username'] ?? 'postgres'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function pgEnv(array $config): array
    {
        return array_filter([
            'PGPASSWORD' => (string) ($config['password'] ?? ''),
            'PGSSLMODE' => (string) ($config['sslmode'] ?? ''),
            'PGCONNECT_TIMEOUT' => '15',
        ], fn (string $value): bool => $value !== '');
    }

    public function mainConnection(): string
    {
        return (string) (config('backups.connections.database') ?: config('database.default'));
    }

    public function analyticsConnection(): string
    {
        return (string) config('backups.connections.analytics', 'analytics');
    }

    public function connectionFor(string $component): string
    {
        return $component === 'analytics' ? $this->analyticsConnection() : $this->mainConnection();
    }

    public function runDirectory(BackupRun $run): string
    {
        return rtrim((string) config('backups.directory'), '/').'/'.$run->name;
    }

    protected function postgresServerVersion(array $config): ?string
    {
        try {
            $connection = DB::connection($config['name'] ?? $this->mainConnection());

            return $connection->getDriverName() === 'pgsql'
                ? (string) $connection->selectOne('SHOW server_version')->server_version
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<int, string>  $command
     * @param  array<string, string>  $env
     */
    public function runProcess(BackupRun $run, array $command, array $env = [], ?int $timeout = null, bool $logOutput = true, bool $quiet = false): ProcessResult
    {
        if (! $quiet) {
            $this->log($run, '$ '.implode(' ', array_map(fn (string $part): string => str_contains($part, ' ') ? "'{$part}'" : $part, $command)));
        }

        $result = Process::timeout($timeout ?? config('backups.timeout'))->env($env)->run($command);

        $output = trim($result->errorOutput().($logOutput ? "\n".$result->output() : ''));
        if ($output !== '' && ! $quiet) {
            $this->log($run, $this->tail($output, 4000));
        }

        $this->assertSuccessful($result, basename($command[0]));

        return $result;
    }

    protected function assertSuccessful(ProcessResult $result, string $label): void
    {
        if ($result->successful()) {
            return;
        }

        $detail = trim($result->errorOutput()) ?: trim($result->output());
        if ($result->exitCode() === 127) {
            $detail = 'comanda nu este instalată pe server. '.$detail;
        }

        throw new BackupStepException(sprintf('%s a eșuat (cod %s): %s', $label, $result->exitCode() ?? '?', $this->tail($detail, 1500)));
    }

    public function log(BackupRun $run, string $message, bool $force = false): void
    {
        $line = '['.now()->format('H:i:s').'] '.rtrim($message);
        $run->log = ltrim(($run->log ?? '')."\n".$line);

        // Keep the log readable in the UI and bounded in the database.
        if (strlen($run->log) > 200_000) {
            $run->log = "[…]\n".substr($run->log, -180_000);
        }

        $now = microtime(true);
        if ($force || $this->lastLogFlush === null || $now - $this->lastLogFlush >= 1.0) {
            $run->saveQuietly();
            $this->lastLogFlush = $now;
        }
    }

    protected function finish(BackupRun $run, string $status, ?string $error = null): BackupRun
    {
        $run->forceFill([
            'status' => $status,
            'error_message' => $error !== null ? Str::limit($error, 5000) : null,
            'started_at' => $run->started_at ?? now(),
            'finished_at' => now(),
        ]);

        $duration = $run->durationSeconds();
        $this->log($run, match ($status) {
            BackupRun::STATUS_COMPLETED => "Finalizat cu succes în {$duration}s.",
            BackupRun::STATUS_PARTIAL => "Finalizat parțial în {$duration}s: {$error}",
            default => 'Eșuat: '.$error,
        }, true);

        return $run->fresh();
    }

    protected function resolveStatus(BackupRun $run, array $artifacts): string
    {
        $attempted = collect($artifacts)->where('status', '!=', 'skipped');
        $failed = $attempted->where('status', 'failed')->count();
        $succeeded = $attempted->where('status', 'completed')->count();

        if ($succeeded === 0) {
            return BackupRun::STATUS_FAILED;
        }

        return $failed > 0 || $run->remote_status === 'failed'
            ? BackupRun::STATUS_PARTIAL
            : BackupRun::STATUS_COMPLETED;
    }

    protected function summarizeErrors(BackupRun $run, array $artifacts): string
    {
        $errors = collect($artifacts)
            ->where('status', 'failed')
            ->map(fn (array $artifact): string => $artifact['label'].': '.$artifact['error'])
            ->values();

        if ($run->remote_status === 'failed') {
            $errors->push('Upload off-site: '.$run->remote_error);
        }

        return $errors->isEmpty() ? 'Nicio componentă nu a produs un backup.' : $errors->implode("\n");
    }

    protected function skip(array $artifact, string $reason): array
    {
        $artifact['status'] = 'skipped';
        $artifact['file'] = null;
        $artifact['error'] = $reason;

        return $artifact;
    }

    public function componentLabel(string $component): string
    {
        return match ($component) {
            'database' => 'Baza de date principală',
            'analytics' => 'Baza de date analytics',
            'redis' => 'Redis (cozi, sesiuni, buffer analytics)',
            'media' => 'Oglindă media (bucket S3/R2)',
            default => $component,
        };
    }

    public function gzipFile(string $source, string $target): void
    {
        $in = fopen($source, 'rb');
        $out = gzopen($target, 'wb6');
        if ($in === false || $out === false) {
            throw new BackupStepException('Nu pot comprima '.basename($source));
        }

        while (! feof($in)) {
            gzwrite($out, (string) fread($in, 1024 * 1024));
        }

        fclose($in);
        gzclose($out);
    }

    public function gunzipFile(string $source, string $target): void
    {
        $in = gzopen($source, 'rb');
        $out = fopen($target, 'wb');
        if ($in === false || $out === false) {
            throw new BackupStepException('Nu pot decomprima '.basename($source));
        }

        while (! gzeof($in)) {
            fwrite($out, (string) gzread($in, 1024 * 1024));
        }

        gzclose($in);
        fclose($out);
    }

    public function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? min((int) floor(log($bytes, 1024)), count($units) - 1) : 0;

        return round($bytes / (1024 ** $power), 1).' '.$units[$power];
    }

    protected function tail(string $text, int $length): string
    {
        $text = trim($text);

        return strlen($text) > $length ? '…'.substr($text, -$length) : $text;
    }
}
