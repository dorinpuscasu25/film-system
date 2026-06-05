<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable([
    'user_code',
    'device_code_hash',
    'status',
    'user_id',
    'device_name',
    'device_ip',
    'last_polled_at',
    'approved_at',
    'expires_at',
])]
class DeviceAuthCode extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_CLAIMED = 'claimed';
    public const STATUS_DENIED = 'denied';
    public const STATUS_EXPIRED = 'expired';

    /**
     * Characters used for the human-facing user code. We intentionally drop
     * easily-confused glyphs (0/O, 1/I, etc.) so the code is easy to read off a TV.
     */
    private const USER_CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * Create a fresh pending pairing request. Returns the model plus the plain
     * device_code secret (only available here — we persist only its hash).
     *
     * @return array{0: self, 1: string}
     */
    public static function issue(int $ttlSeconds = 600, ?string $deviceName = null, ?string $deviceIp = null): array
    {
        $deviceCode = Str::random(64);

        $code = self::query()->create([
            'user_code' => self::generateUniqueUserCode(),
            'device_code_hash' => hash('sha256', $deviceCode),
            'status' => self::STATUS_PENDING,
            'device_name' => $deviceName,
            'device_ip' => $deviceIp,
            'expires_at' => now()->addSeconds($ttlSeconds),
        ]);

        return [$code, $deviceCode];
    }

    public static function findByDeviceCode(string $deviceCode): ?self
    {
        return self::query()
            ->where('device_code_hash', hash('sha256', $deviceCode))
            ->first();
    }

    public static function findPendingByUserCode(string $userCode): ?self
    {
        return self::query()
            ->where('user_code', self::normalizeUserCode($userCode))
            ->first();
    }

    /**
     * Normalize whatever the user typed (lowercase, dashes, spaces, ambiguous
     * glyphs) into the canonical stored form "XXXX-XXXX".
     */
    public static function normalizeUserCode(string $input): string
    {
        $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $input) ?? '');
        $clean = strtr($clean, ['0' => 'O', '1' => 'I']);
        // We store O/I-free codes, so fold the common mistypes back out again.
        $clean = strtr($clean, ['O' => '', 'I' => '']);

        if (strlen($clean) === 8) {
            return substr($clean, 0, 4).'-'.substr($clean, 4, 4);
        }

        return $clean;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isApproved(): bool
    {
        return $this->status === self::STATUS_APPROVED;
    }

    protected function casts(): array
    {
        return [
            'last_polled_at' => 'datetime',
            'approved_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    private static function generateUniqueUserCode(): string
    {
        do {
            $raw = '';
            for ($i = 0; $i < 8; $i++) {
                $raw .= self::USER_CODE_ALPHABET[random_int(0, strlen(self::USER_CODE_ALPHABET) - 1)];
            }
            $code = substr($raw, 0, 4).'-'.substr($raw, 4, 4);
        } while (self::query()->where('user_code', $code)->exists());

        return $code;
    }
}
