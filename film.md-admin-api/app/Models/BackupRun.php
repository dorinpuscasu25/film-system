<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'name',
    'trigger',
    'type',
    'source_run_id',
    'status',
    'requested_by',
    'components',
    'artifacts',
    'size_bytes',
    'encrypted',
    'remote_status',
    'remote_path',
    'remote_error',
    'is_locked',
    'note',
    'error_message',
    'log',
    'started_at',
    'finished_at',
    'files_deleted_at',
])]
class BackupRun extends Model
{
    public const TYPE_BACKUP = 'backup';

    // Restores a backup into a throwaway database to prove it is usable.
    public const TYPE_RESTORE_TEST = 'restore_test';

    // A full restore over the live database, run from the CLI.
    public const TYPE_RESTORE = 'restore';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    // Some components succeeded, at least one failed.
    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function sourceRun(): BelongsTo
    {
        return $this->belongsTo(self::class, 'source_run_id');
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_PARTIAL, self::STATUS_FAILED], true);
    }

    public function hasFiles(): bool
    {
        return $this->type === self::TYPE_BACKUP
            && $this->files_deleted_at === null
            && collect($this->artifacts ?? [])->contains(fn (array $artifact): bool => ($artifact['status'] ?? null) === 'completed');
    }

    public function durationSeconds(): ?int
    {
        if ($this->started_at === null) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->finished_at ?? now());
    }

    protected function casts(): array
    {
        return [
            'components' => 'array',
            'artifacts' => 'array',
            'size_bytes' => 'integer',
            'encrypted' => 'boolean',
            'is_locked' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'files_deleted_at' => 'datetime',
        ];
    }
}
