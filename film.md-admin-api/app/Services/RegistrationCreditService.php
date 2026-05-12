<?php

namespace App\Services;

use App\Models\PlatformSetting;
use App\Models\Wallet;
use Illuminate\Support\Carbon;
use Carbon\CarbonInterface;

class RegistrationCreditService
{
    public const SETTINGS_KEY = 'registration_credit';

    public const DEFAULT_SETTINGS = [
        'enabled' => true,
        'default_amount' => 20,
        'currency' => Wallet::DEFAULT_CURRENCY,
        'campaigns' => [],
    ];

    /**
     * @return array{enabled: bool, default_amount: float, currency: string, campaigns: array<int, array<string, mixed>>}
     */
    public function settings(): array
    {
        $settings = PlatformSetting::getValue(self::SETTINGS_KEY, []);

        return $this->normalizeSettings(is_array($settings) ? $settings : []);
    }

    /**
     * @return array{enabled: bool, amount: float, currency: string, campaign: array<string, mixed>|null}
     */
    public function resolveForRegistration(?CarbonInterface $registeredAt = null): array
    {
        $settings = $this->settings();
        $registeredAt ??= now();

        if (! $settings['enabled']) {
            return [
                'enabled' => false,
                'amount' => 0.0,
                'currency' => $settings['currency'],
                'campaign' => null,
            ];
        }

        $campaign = collect($settings['campaigns'])
            ->filter(fn (array $campaign): bool => (bool) ($campaign['enabled'] ?? true))
            ->first(function (array $campaign) use ($registeredAt): bool {
                $startsAt = $this->dateOrNull($campaign['starts_at'] ?? null)?->startOfDay();
                $endsAt = $this->dateOrNull($campaign['ends_at'] ?? null)?->endOfDay();

                if ($startsAt !== null && $registeredAt->lt($startsAt)) {
                    return false;
                }

                if ($endsAt !== null && $registeredAt->gt($endsAt)) {
                    return false;
                }

                return true;
            });

        return [
            'enabled' => true,
            'amount' => round((float) ($campaign['amount'] ?? $settings['default_amount']), 2),
            'currency' => $settings['currency'],
            'campaign' => $campaign,
        ];
    }

    public function normalizeSettings(array $settings): array
    {
        $merged = array_replace_recursive(self::DEFAULT_SETTINGS, $settings);

        return [
            'enabled' => filter_var($merged['enabled'] ?? true, FILTER_VALIDATE_BOOL),
            'default_amount' => max(0, round((float) ($merged['default_amount'] ?? 0), 2)),
            'currency' => strtoupper((string) ($merged['currency'] ?? Wallet::DEFAULT_CURRENCY)) ?: Wallet::DEFAULT_CURRENCY,
            'campaigns' => collect($merged['campaigns'] ?? [])
                ->map(function (mixed $campaign): array {
                    $campaign = is_array($campaign) ? $campaign : [];

                    return [
                        'label' => trim((string) ($campaign['label'] ?? '')),
                        'amount' => max(0, round((float) ($campaign['amount'] ?? 0), 2)),
                        'starts_at' => $this->dateStringOrNull($campaign['starts_at'] ?? null),
                        'ends_at' => $this->dateStringOrNull($campaign['ends_at'] ?? null),
                        'enabled' => filter_var($campaign['enabled'] ?? true, FILTER_VALIDATE_BOOL),
                    ];
                })
                ->filter(fn (array $campaign): bool => $campaign['amount'] > 0)
                ->sortBy(fn (array $campaign): string => (string) ($campaign['starts_at'] ?? '0000-00-00'))
                ->values()
                ->all(),
        ];
    }

    private function dateOrNull(mixed $value): ?Carbon
    {
        if (! is_scalar($value) || trim((string) $value) === '') {
            return null;
        }

        return Carbon::parse((string) $value);
    }

    private function dateStringOrNull(mixed $value): ?string
    {
        return $this->dateOrNull($value)?->toDateString();
    }
}
