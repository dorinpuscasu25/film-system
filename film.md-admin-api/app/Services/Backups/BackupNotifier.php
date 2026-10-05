<?php

namespace App\Services\Backups;

use App\Mail\BackupStatusMail;
use App\Models\BackupRun;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Emails the configured recipients about backup outcomes. Delivery problems
 * are logged and never fail the backup itself.
 */
class BackupNotifier
{
    public function __construct(protected BackupSettings $settings) {}

    public function runFinished(BackupRun $run): void
    {
        $notifications = $this->settings->all()['notifications'];
        $success = $run->status === BackupRun::STATUS_COMPLETED;

        if (($success && ! $notifications['on_success']) || (! $success && ! $notifications['on_failure'])) {
            return;
        }

        $kind = $run->type === BackupRun::TYPE_RESTORE_TEST ? 'Test de restaurare' : 'Backup';
        $subject = match ($run->status) {
            BackupRun::STATUS_COMPLETED => "{$kind} reușit: {$run->name}",
            BackupRun::STATUS_PARTIAL => "{$kind} parțial: {$run->name}",
            default => "{$kind} EȘUAT: {$run->name}",
        };

        $this->send($subject, [
            'run' => $run,
            'headline' => $subject,
            'body' => $run->error_message,
        ]);
    }

    public function stale(?string $lastSuccessAt, int $hours): void
    {
        $this->send('Niciun backup reușit în ultimele '.$hours.' ore', [
            'run' => null,
            'headline' => 'Niciun backup reușit în ultimele '.$hours.' ore',
            'body' => $lastSuccessAt === null
                ? 'Nu există încă niciun backup reușit. Verifică pagina Backup-uri din admin și containerul backup-worker.'
                : 'Ultimul backup reușit: '.$lastSuccessAt.'. Verifică pagina Backup-uri din admin și containerul backup-worker.',
        ]);
    }

    public function sendTest(): int
    {
        return $this->send('Test notificări backup', [
            'run' => null,
            'headline' => 'Notificările pentru backup funcționează',
            'body' => 'Acest email confirmă că adresele configurate primesc alertele de backup.',
        ]);
    }

    protected function send(string $subject, array $data): int
    {
        $recipients = $this->settings->all()['notifications']['emails'];
        if ($recipients === []) {
            return 0;
        }

        try {
            Mail::to($recipients)->send(new BackupStatusMail(
                mailSubject: '['.config('app.name').'] '.$subject,
                headline: $data['headline'],
                body: $data['body'],
                run: $data['run'],
                adminUrl: rtrim((string) config('backups.admin_url'), '/').'/backups',
            ));
        } catch (Throwable $exception) {
            Log::warning('Backup notification could not be sent.', ['error' => $exception->getMessage()]);

            return 0;
        }

        return count($recipients);
    }
}
