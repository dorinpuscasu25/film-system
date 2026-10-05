<?php

namespace Tests\Feature\Api;

use App\Mail\BackupStatusMail;
use App\Models\BackupRun;
use App\Models\Permission;
use App\Models\PersonalAccessToken;
use App\Models\Role;
use App\Models\User;
use App\Services\Backups\BackupManager;
use App\Services\Backups\BackupSettings;
use Database\Seeders\AccessControlSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use PDO;
use Tests\TestCase;

class BackupApiTest extends TestCase
{
    use RefreshDatabase;

    protected string $token;

    protected string $directory;

    /** @var array<int, array<int, string>> */
    protected array $commands = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(AccessControlSeeder::class);
        $admin = User::query()->where('email', 'admin@filmoteca.md')->firstOrFail();
        [, $this->token] = PersonalAccessToken::issue($admin, 'test-admin');

        $this->directory = sys_get_temp_dir().'/filmmd-backup-tests-'.uniqid();
        config([
            'app.key' => 'base64:'.base64_encode(str_repeat('k', 32)),
            'backups.directory' => $this->directory.'/runs',
            'backups.pre_migration_directory' => $this->directory.'/pre-migration',
            'backups.encryption_passphrase' => null,
            // A Postgres connection that is never reached: every binary is faked.
            'database.connections.backup_pg' => [
                'driver' => 'pgsql',
                'host' => '127.0.0.1',
                'port' => '1',
                'database' => 'film_md',
                'username' => 'film',
                'password' => 'secret-password',
            ],
            'backups.connections.database' => 'backup_pg',
            'backups.connections.analytics' => 'backup_pg',
        ]);

        $this->fakeBinaries();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_admin_runs_a_verified_postgres_backup_and_downloads_it(): void
    {
        $response = $this->postJson('/api/v1/admin/backups', [], $this->auth())
            ->assertCreated()
            ->assertJsonPath('run.status', 'completed');

        $run = BackupRun::query()->findOrFail($response->json('run.id'));
        $database = collect($run->artifacts)->firstWhere('component', 'database');
        $analytics = collect($run->artifacts)->firstWhere('component', 'analytics');

        $this->assertSame('completed', $database['status']);
        $this->assertTrue($database['verified']);
        $this->assertSame('database-film_md.dump', $database['file']);
        $this->assertSame(3, $database['meta']['toc_entries']);
        $this->assertSame(hash_file('sha256', $this->directory.'/runs/'.$run->name.'/database-film_md.dump'), $database['sha256']);
        $this->assertSame('skipped', $analytics['status']);
        $this->assertFileExists($this->directory.'/runs/'.$run->name.'/manifest.json');

        // The password goes through the environment, never the command line.
        $pgDump = collect($this->commands)->first(fn (array $command): bool => $command[0] === 'pg_dump');
        $this->assertNotContains('secret-password', $pgDump);
        $this->assertStringNotContainsString('secret-password', $run->log);

        $url = $this->postJson("/api/v1/admin/backups/{$run->id}/download-link", ['file' => 'database-film_md.dump'], $this->auth())
            ->assertOk()
            ->json('url');

        $this->get($url)->assertOk()->assertDownload('database-film_md.dump');
        $this->get('/api/v1/backups/download?run='.$run->id.'&file=database-film_md.dump')->assertForbidden();
        $this->postJson("/api/v1/admin/backups/{$run->id}/download-link", ['file' => '../../.env'], $this->auth())->assertNotFound();
    }

    public function test_failed_component_makes_the_run_partial_and_emails_the_recipients(): void
    {
        Mail::fake();
        $this->updateSettings([
            'components' => ['database' => true, 'analytics' => false, 'redis' => true, 'media' => false],
            'notifications' => ['emails' => ['ops@filmoteca.md']],
        ]);
        $this->fakeBinaries(failing: ['redis-cli']);

        $response = $this->postJson('/api/v1/admin/backups', [], $this->auth())
            ->assertCreated()
            ->assertJsonPath('run.status', 'partial');

        $redis = collect($response->json('run.artifacts'))->firstWhere('component', 'redis');
        $this->assertSame('failed', $redis['status']);
        $this->assertStringContainsString('redis-cli a eșuat', $redis['error']);

        Mail::assertSent(BackupStatusMail::class, fn (BackupStatusMail $mail): bool => $mail->hasTo('ops@filmoteca.md') && str_contains($mail->mailSubject, 'parțial'));
    }

    public function test_encrypted_backup_is_uploaded_off_site(): void
    {
        config(['backups.encryption_passphrase' => 'correct horse battery staple']);
        $this->updateSettings([
            'components' => ['database' => true, 'analytics' => false, 'redis' => false, 'media' => false],
            'encryption' => ['enabled' => true],
            'remote' => ['enabled' => true, 'destination' => 'gdrive:film-md-backups', 'rclone_config' => "[gdrive]\ntype = drive\ntoken = {}"],
        ])->assertJsonPath('settings.remote.rclone_config_set', true)
            ->assertJsonMissingPath('settings.remote.rclone_config');

        $response = $this->postJson('/api/v1/admin/backups', [], $this->auth())
            ->assertCreated()
            ->assertJsonPath('run.status', 'completed')
            ->assertJsonPath('run.encrypted', true)
            ->assertJsonPath('run.remote_status', 'uploaded');

        $name = $response->json('run.name');
        $this->assertSame('database-film_md.dump.enc', $response->json('run.artifacts.0.file'));
        $this->assertFileExists($this->directory.'/runs/'.$name.'/database-film_md.dump.enc');
        $this->assertFileDoesNotExist($this->directory.'/runs/'.$name.'/database-film_md.dump');

        $copy = collect($this->commands)->first(fn (array $command): bool => $command[0] === 'rclone' && in_array('copy', $command, true));
        $this->assertContains('gdrive:film-md-backups/'.$name, $copy);
        $this->assertContains('--config', $copy);
    }

    public function test_remote_failure_marks_backup_partial(): void
    {
        $this->updateSettings([
            'components' => ['database' => true, 'analytics' => false, 'redis' => false, 'media' => false],
            'remote' => ['enabled' => true, 'destination' => 'gdrive:film-md-backups'],
        ]);
        $this->fakeBinaries(failing: ['rclone']);

        $this->postJson('/api/v1/admin/backups', [], $this->auth())
            ->assertCreated()
            ->assertJsonPath('run.status', 'partial')
            ->assertJsonPath('run.remote_status', 'failed');
    }

    public function test_encryption_cannot_be_enabled_without_passphrase(): void
    {
        $this->putJson('/api/v1/admin/backups/settings', ['encryption' => ['enabled' => true]], $this->auth())
            ->assertUnprocessable();

        $this->putJson('/api/v1/admin/backups/settings', ['schedule' => 'every day'], $this->auth())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('schedule');
    }

    public function test_only_one_backup_can_be_active(): void
    {
        BackupRun::query()->create(['name' => 'film-md-busy', 'status' => BackupRun::STATUS_RUNNING, 'started_at' => now()]);

        $this->postJson('/api/v1/admin/backups', [], $this->auth())->assertStatus(409);
    }

    public function test_restore_test_restores_sqlite_backup_into_a_temporary_copy(): void
    {
        $file = $this->directory.'/fixture.sqlite';
        File::ensureDirectoryExists($this->directory);
        $pdo = new PDO('sqlite:'.$file);
        $pdo->exec('CREATE TABLE films (id INTEGER PRIMARY KEY, title TEXT)');
        $pdo->exec("INSERT INTO films (title) VALUES ('Carbon')");
        $pdo = null;

        config([
            'database.connections.backup_sqlite' => ['driver' => 'sqlite', 'database' => $file, 'prefix' => ''],
            'backups.connections.database' => 'backup_sqlite',
        ]);

        $backup = $this->postJson('/api/v1/admin/backups', ['components' => ['database']], $this->auth())
            ->assertCreated()
            ->assertJsonPath('run.status', 'completed')
            ->json('run');

        $this->postJson("/api/v1/admin/backups/{$backup['id']}/restore-test", ['component' => 'database'], $this->auth())
            ->assertCreated()
            ->assertJsonPath('run.type', 'restore_test')
            ->assertJsonPath('run.status', 'completed')
            ->assertJsonPath('run.artifacts.0.tables', 1);
    }

    public function test_retention_keeps_recent_and_locked_backups(): void
    {
        $this->updateSettings(['retention' => ['keep_last' => 2, 'keep_days' => 10]]);

        $make = function (string $name, int $daysAgo, bool $locked = false): BackupRun {
            File::ensureDirectoryExists($this->directory.'/runs/'.$name);
            file_put_contents($this->directory.'/runs/'.$name.'/database.dump', 'x');
            $run = BackupRun::query()->create([
                'name' => $name,
                'status' => BackupRun::STATUS_COMPLETED,
                'is_locked' => $locked,
                'artifacts' => [['component' => 'database', 'file' => 'database.dump', 'status' => 'completed']],
            ]);
            $run->forceFill(['created_at' => now()->subDays($daysAgo)])->save();

            return $run;
        };

        $newest = $make('newest', 1);
        $second = $make('second', 20);
        $oldLocked = $make('old-locked', 30, true);
        $old = $make('old', 40);
        $recentButBeyondKeepLast = $make('recent-extra', 5);

        $pruned = app(BackupManager::class)->prune();

        // The two newest are kept whatever their age; older than 10 days goes.
        $this->assertSame(['second', 'old'], $pruned);
        $this->assertNotNull($second->fresh()->files_deleted_at);
        $this->assertNotNull($old->fresh()->files_deleted_at);
        $this->assertDirectoryDoesNotExist($this->directory.'/runs/old');
        foreach ([$newest, $recentButBeyondKeepLast, $oldLocked] as $kept) {
            $this->assertNull($kept->fresh()->files_deleted_at, $kept->name.' should be kept');
        }

        $this->deleteJson("/api/v1/admin/backups/{$oldLocked->id}", [], $this->auth())->assertStatus(409);
        $this->patchJson("/api/v1/admin/backups/{$oldLocked->id}", ['is_locked' => false], $this->auth())->assertOk();
        $this->deleteJson("/api/v1/admin/backups/{$oldLocked->id}", [], $this->auth())->assertOk();
        $this->assertDatabaseMissing('backup_runs', ['id' => $oldLocked->id]);
    }

    public function test_monitor_recovers_dead_runs_and_alerts_when_backups_are_stale(): void
    {
        Mail::fake();
        $this->updateSettings(['notifications' => ['emails' => ['ops@filmoteca.md'], 'stale_after_hours' => 24]]);

        $stuck = BackupRun::query()->create([
            'name' => 'film-md-stuck',
            'status' => BackupRun::STATUS_RUNNING,
            'started_at' => now()->subHours(5),
        ]);

        $this->artisan('backups:monitor')->assertSuccessful();

        $this->assertSame(BackupRun::STATUS_FAILED, $stuck->fresh()->status);
        Mail::assertSent(BackupStatusMail::class, fn (BackupStatusMail $mail): bool => str_contains($mail->mailSubject, 'Niciun backup reușit'));

        // One alert per stale period.
        Mail::fake();
        $this->artisan('backups:monitor')->assertSuccessful();
        Mail::assertNotSent(BackupStatusMail::class);
    }

    public function test_scheduler_queues_a_backup_when_due(): void
    {
        $this->updateSettings(['schedule' => '* * * * *']);

        $this->artisan('backups:schedule')->assertSuccessful();
        $this->artisan('backups:schedule')->assertSuccessful();

        $this->assertSame(1, BackupRun::query()->where('trigger', 'scheduled')->count());

        $this->updateSettings(['enabled' => false]);
        $this->assertNull(app(BackupSettings::class)->nextRunAt());
    }

    public function test_backup_page_requires_backup_permission(): void
    {
        $role = Role::query()->create(['name' => 'Editor', 'admin_panel_access' => true]);
        $role->permissions()->sync(Permission::query()->whereIn('code', ['admin.access', 'content.view'])->pluck('id'));
        $editor = User::factory()->create(['status' => 'active']);
        $editor->roles()->sync([$role->id]);
        [, $token] = PersonalAccessToken::issue($editor, 'test-editor');

        $this->getJson('/api/v1/admin/backups', ['Authorization' => 'Bearer '.$token])->assertForbidden();

        $this->getJson('/api/v1/admin/backups', $this->auth())
            ->assertOk()
            ->assertJsonPath('settings.schedule', '0 3 * * *')
            ->assertJsonPath('settings.timezone', 'Europe/Chisinau')
            ->assertJsonStructure(['overview' => ['last_success_at', 'next_run_at', 'disk'], 'tools' => ['pg_dump', 'postgres_server'], 'runs', 'pre_migration']);
    }

    public function test_pre_migration_dumps_are_listed_and_downloadable(): void
    {
        File::ensureDirectoryExists($this->directory.'/pre-migration');
        file_put_contents($this->directory.'/pre-migration/pre-migration-20260926T010000Z.dump', 'dump');

        $this->getJson('/api/v1/admin/backups', $this->auth())
            ->assertOk()
            ->assertJsonPath('pre_migration.0.name', 'pre-migration-20260926T010000Z.dump');

        $url = $this->postJson('/api/v1/admin/backups/pre-migration/download-link', ['file' => 'pre-migration-20260926T010000Z.dump'], $this->auth())
            ->assertOk()
            ->json('url');

        $this->get($url)->assertOk()->assertDownload('pre-migration-20260926T010000Z.dump');
    }

    /**
     * Fakes pg_dump, pg_restore, redis-cli, openssl and rclone. The fakes write
     * the files the real binaries would produce.
     *
     * @param  array<int, string>  $failing
     */
    protected function fakeBinaries(array $failing = []): void
    {
        $this->commands = [];

        Process::fake(function (PendingProcess $process) use ($failing) {
            $command = is_array($process->command) ? $process->command : explode(' ', $process->command);
            $binary = basename($command[0]);
            $this->commands[] = [$binary, ...array_slice($command, 1)];

            if (in_array($binary, $failing, true)) {
                return Process::result(errorOutput: 'simulated failure', exitCode: 1);
            }

            $option = function (string $prefix) use ($command): ?string {
                foreach ($command as $index => $part) {
                    if (str_starts_with($part, $prefix.'=')) {
                        return substr($part, strlen($prefix) + 1);
                    }
                    if ($part === $prefix) {
                        return $command[$index + 1] ?? null;
                    }
                }

                return null;
            };

            return match ($binary) {
                'pg_dump' => (function () use ($option) {
                    file_put_contents($option('--file'), 'PGDMP fake dump');

                    return Process::result();
                })(),
                'pg_restore' => Process::result(output: ";\n; Archive created\n1; TABLE users\n2; TABLE contents\n3; DATA users\n"),
                'redis-cli' => in_array('--rdb', $command, true)
                    ? (function () use ($option) {
                        file_put_contents($option('--rdb'), 'REDIS0011');

                        return Process::result();
                    })()
                    : Process::result(output: 'redis-cli 7.0.0'),
                'openssl' => in_array('-out', $command, true)
                    ? (function () use ($option) {
                        file_put_contents($option('-out'), 'Salted__'.file_get_contents($option('-in')));

                        return Process::result();
                    })()
                    : Process::result(output: 'OpenSSL 3.0.0'),
                default => Process::result(output: $binary.' 16.4'),
            };
        });
    }

    protected function updateSettings(array $settings)
    {
        return $this->putJson('/api/v1/admin/backups/settings', $settings, $this->auth())->assertOk();
    }

    /**
     * @return array<string, string>
     */
    protected function auth(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }
}
