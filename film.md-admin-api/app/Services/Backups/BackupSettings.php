<?php

namespace App\Services\Backups;

use App\Models\PlatformSetting;
use Cron\CronExpression;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Backup configuration edited from the admin. Stored as one PlatformSetting
 * row; the rclone config is encrypted with APP_KEY and never sent back to
 * the browser.
 */
class BackupSettings
{
    public const SETTINGS_KEY = 'backups';

    public const STATE_KEY = 'backups_state';

    public const COMPONENTS = ['database', 'analytics', 'redis', 'media'];

    public function all(): array
    {
        return $this->normalize(PlatformSetting::getValue(self::SETTINGS_KEY, []) ?? []);
    }

    /**
     * Settings safe to send to the admin UI.
     */
    public function forDisplay(): array
    {
        $settings = $this->all();
        $settings['remote']['rclone_config_set'] = filled($settings['remote']['rclone_config']);
        unset($settings['remote']['rclone_config']);

        return $settings;
    }

    public function update(array $input): array
    {
        $current = $this->all();

        $remoteInput = $input['remote'] ?? [];
        if (! empty($remoteInput['clear_rclone_config'])) {
            $remoteInput['rclone_config'] = null;
        } elseif (filled($remoteInput['rclone_config'] ?? null)) {
            $remoteInput['rclone_config'] = Crypt::encryptString(trim((string) $remoteInput['rclone_config']));
        } else {
            $remoteInput['rclone_config'] = $current['remote']['rclone_config'];
        }
        unset($remoteInput['clear_rclone_config']);
        $input['remote'] = $remoteInput;

        $settings = $this->normalize(array_replace_recursive($current, $input));
        // Lists are replaced, not merged index by index.
        $settings['notifications']['emails'] = $this->normalizeEmails($input['notifications']['emails'] ?? $current['notifications']['emails']);

        PlatformSetting::setValue(self::SETTINGS_KEY, $settings);

        return $settings;
    }

    public function rcloneConfig(): ?string
    {
        $encrypted = $this->all()['remote']['rclone_config'];
        if (blank($encrypted)) {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (Throwable) {
            return null;
        }
    }

    public function remoteEnabled(): bool
    {
        $remote = $this->all()['remote'];

        return $remote['enabled'] && filled($remote['destination']);
    }

    public function encryptionAvailable(): bool
    {
        return filled(config('backups.encryption_passphrase'));
    }

    public function encryptionActive(): bool
    {
        return $this->all()['encryption']['enabled'] && $this->encryptionAvailable();
    }

    public function isDue(?Carbon $at = null): bool
    {
        $settings = $this->all();
        if (! $settings['enabled']) {
            return false;
        }

        try {
            return (new CronExpression($settings['schedule']))
                ->isDue(($at ?? now())->toDateTimeImmutable(), $settings['timezone']);
        } catch (Throwable) {
            return false;
        }
    }

    public function nextRunAt(): ?Carbon
    {
        $settings = $this->all();
        if (! $settings['enabled']) {
            return null;
        }

        try {
            $next = (new CronExpression($settings['schedule']))
                ->getNextRunDate(now()->toDateTimeImmutable(), 0, false, $settings['timezone']);

            return Carbon::instance($next)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    public function state(): array
    {
        $state = PlatformSetting::getValue(self::STATE_KEY, []);

        return is_array($state) ? $state : [];
    }

    public function putState(array $values): void
    {
        PlatformSetting::setValue(self::STATE_KEY, array_merge($this->state(), $values));
    }

    public static function isValidCron(string $expression): bool
    {
        return CronExpression::isValidExpression($expression);
    }

    public static function isValidTimezone(string $timezone): bool
    {
        return in_array($timezone, DateTimeZone::listIdentifiers(), true);
    }

    protected function normalize(array $value): array
    {
        $components = is_array($value['components'] ?? null) ? $value['components'] : [];
        $retention = is_array($value['retention'] ?? null) ? $value['retention'] : [];
        $remote = is_array($value['remote'] ?? null) ? $value['remote'] : [];
        $notifications = is_array($value['notifications'] ?? null) ? $value['notifications'] : [];

        $schedule = trim((string) ($value['schedule'] ?? ''));
        $timezone = (string) ($value['timezone'] ?? '');

        return [
            'enabled' => (bool) ($value['enabled'] ?? true),
            'schedule' => $schedule !== '' && self::isValidCron($schedule) ? $schedule : '0 3 * * *',
            'timezone' => self::isValidTimezone($timezone) ? $timezone : 'Europe/Chisinau',
            'components' => [
                'database' => (bool) ($components['database'] ?? true),
                'analytics' => (bool) ($components['analytics'] ?? true),
                'redis' => (bool) ($components['redis'] ?? false),
                'media' => (bool) ($components['media'] ?? false),
            ],
            'retention' => [
                'keep_last' => $this->clamp($retention['keep_last'] ?? 7, 1, 365),
                'keep_days' => $this->clamp($retention['keep_days'] ?? 30, 1, 3650),
            ],
            'encryption' => [
                'enabled' => (bool) ($value['encryption']['enabled'] ?? false),
            ],
            'remote' => [
                'enabled' => (bool) ($remote['enabled'] ?? false),
                'destination' => trim((string) ($remote['destination'] ?? '')),
                'rclone_config' => filled($remote['rclone_config'] ?? null) ? (string) $remote['rclone_config'] : null,
                'prune' => (bool) ($remote['prune'] ?? true),
            ],
            'notifications' => [
                'emails' => $this->normalizeEmails($notifications['emails'] ?? []),
                'on_failure' => (bool) ($notifications['on_failure'] ?? true),
                'on_success' => (bool) ($notifications['on_success'] ?? false),
                'stale_after_hours' => $this->clamp($notifications['stale_after_hours'] ?? 30, 1, 720),
            ],
        ];
    }

    protected function normalizeEmails(mixed $emails): array
    {
        return collect(is_array($emails) ? $emails : [])
            ->map(fn (mixed $email): string => strtolower(trim((string) $email)))
            ->filter(fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false)
            ->unique()
            ->values()
            ->all();
    }

    protected function clamp(mixed $value, int $min, int $max): int
    {
        return max($min, min($max, (int) $value));
    }
}
