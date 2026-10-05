<?php

namespace App\Services\Backups;

use App\Models\PlatformSetting;
use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Process;

/**
 * Thin wrapper around the rclone binary. The rclone config pasted in the
 * admin is written to a private temp file for the duration of a call; if
 * rclone refreshes an OAuth token (Google Drive) the new config is saved back.
 */
class Rclone
{
    public const MEDIA_REMOTE = 'filmmdmedia';

    public function __construct(protected BackupSettings $settings) {}

    /**
     * @param  array<int, string>  $args
     * @param  (Closure(string): void)|null  $onOutput
     */
    public function run(array $args, int $timeout = 600, ?Closure $onOutput = null): ProcessResult
    {
        $config = $this->settings->rcloneConfig();
        $configPath = null;
        $command = [config('backups.binaries.rclone')];

        if ($config !== null) {
            $configPath = tempnam(sys_get_temp_dir(), 'rclone-');
            chmod($configPath, 0600);
            file_put_contents($configPath, $config);
            $command[] = '--config';
            $command[] = $configPath;
        }

        try {
            return Process::timeout($timeout)
                ->env($this->mediaRemoteEnv())
                ->run([...$command, ...$args], $onOutput === null ? null : function (string $type, string $output) use ($onOutput): void {
                    $onOutput($output);
                });
        } finally {
            if ($configPath !== null) {
                $this->persistRefreshedConfig($configPath, $config);
                @unlink($configPath);
            }
        }
    }

    public function join(string $destination, string $path): string
    {
        $destination = rtrim($destination, '/');

        // "remote:" alone is the remote root; don't add a slash after the colon.
        return str_ends_with($destination, ':') ? $destination.ltrim($path, '/') : $destination.'/'.ltrim($path, '/');
    }

    public function mediaSource(): ?string
    {
        $bucket = config('filesystems.disks.s3.bucket');

        return filled($bucket) && filled(config('filesystems.disks.s3.key'))
            ? self::MEDIA_REMOTE.':'.$bucket
            : null;
    }

    /**
     * The media bucket is exposed to rclone as an env-defined remote, so its
     * credentials never have to be pasted into the admin.
     *
     * @return array<string, string>
     */
    protected function mediaRemoteEnv(): array
    {
        $disk = config('filesystems.disks.s3');
        if (blank($disk['key'] ?? null)) {
            return [];
        }

        $prefix = 'RCLONE_CONFIG_'.strtoupper(self::MEDIA_REMOTE).'_';

        return array_filter([
            $prefix.'TYPE' => 's3',
            $prefix.'PROVIDER' => 'Other',
            $prefix.'ACCESS_KEY_ID' => (string) $disk['key'],
            $prefix.'SECRET_ACCESS_KEY' => (string) ($disk['secret'] ?? ''),
            $prefix.'REGION' => (string) ($disk['region'] ?? ''),
            $prefix.'ENDPOINT' => (string) ($disk['endpoint'] ?? ''),
            $prefix.'FORCE_PATH_STYLE' => ($disk['use_path_style_endpoint'] ?? false) ? 'true' : 'false',
        ], fn (string $value): bool => $value !== '');
    }

    protected function persistRefreshedConfig(string $path, string $original): void
    {
        $current = @file_get_contents($path);
        if (! is_string($current) || trim($current) === '' || trim($current) === trim($original)) {
            return;
        }

        $settings = $this->settings->all();
        $settings['remote']['rclone_config'] = Crypt::encryptString(trim($current));
        PlatformSetting::setValue(BackupSettings::SETTINGS_KEY, $settings);
    }
}
