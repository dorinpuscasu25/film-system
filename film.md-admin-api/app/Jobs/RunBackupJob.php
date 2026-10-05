<?php

namespace App\Jobs;

use App\Models\BackupRun;
use App\Services\Backups\BackupManager;
use App\Services\Backups\BackupRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class RunBackupJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public int $backupRunId)
    {
        $this->timeout = (int) config('backups.timeout');
    }

    public function handle(BackupRunner $runner, BackupManager $manager): void
    {
        $run = BackupRun::query()->find($this->backupRunId);
        if ($run === null || $run->status !== BackupRun::STATUS_QUEUED) {
            return;
        }

        $run = $runner->run($run);

        if ($run->type === BackupRun::TYPE_BACKUP && in_array($run->status, [BackupRun::STATUS_COMPLETED, BackupRun::STATUS_PARTIAL], true)) {
            $manager->prune();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $run = BackupRun::query()->find($this->backupRunId);
        if ($run === null || $run->isFinished()) {
            return;
        }

        $message = 'Job-ul de backup a fost oprit: '.($exception?->getMessage() ?? 'motiv necunoscut');
        $run->forceFill([
            'status' => BackupRun::STATUS_FAILED,
            'error_message' => $message,
            'finished_at' => now(),
            'log' => ltrim(($run->log ?? '')."\n[".now()->format('H:i:s').'] '.$message),
        ])->save();
    }
}
