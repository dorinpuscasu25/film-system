<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\BackupRun;
use App\Services\AuditLogService;
use App\Services\Backups\BackupManager;
use App\Services\Backups\BackupNotifier;
use App\Services\Backups\BackupSettings;
use App\Services\Backups\BackupStepException;
use App\Services\Backups\Rclone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class BackupController extends ApiController
{
    public function __construct(
        protected BackupManager $manager,
        protected BackupSettings $settings,
        protected BackupNotifier $notifier,
        protected Rclone $rclone,
        protected AuditLogService $auditLog,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'type' => ['nullable', Rule::in(['backup', 'restore_test', 'restore'])],
            'status' => ['nullable', Rule::in(['queued', 'running', 'completed', 'partial', 'failed'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $runs = BackupRun::query()
            ->with(['requester:id,name', 'sourceRun:id,name'])
            ->when($filters['type'] ?? null, fn ($query, string $type) => $query->where('type', $type))
            ->when($filters['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->latest('id')
            ->paginate(20);

        return response()->json([
            'overview' => $this->manager->overview(),
            'settings' => $this->settings->forDisplay(),
            'encryption_available' => $this->settings->encryptionAvailable(),
            'media_source' => $this->rclone->mediaSource(),
            'tools' => $this->manager->tools(),
            'pre_migration' => $this->manager->preMigrationDumps(),
            'runs' => collect($runs->items())->map(fn (BackupRun $run): array => $this->runData($run))->values(),
            'meta' => [
                'current_page' => $runs->currentPage(),
                'last_page' => $runs->lastPage(),
                'total' => $runs->total(),
            ],
        ]);
    }

    public function show(BackupRun $backupRun): JsonResponse
    {
        $backupRun->loadMissing(['requester:id,name', 'sourceRun:id,name']);

        return response()->json(['run' => $this->runData($backupRun, withLog: true)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'components' => ['nullable', 'array', 'min:1'],
            'components.*' => ['string', Rule::in(BackupSettings::COMPONENTS)],
        ]);

        try {
            $run = $this->manager->queueBackup('manual', $request->user(), $data['components'] ?? null);
        } catch (BackupStepException $exception) {
            return response()->json(['message' => $exception->getMessage()], Response::HTTP_CONFLICT);
        }

        $this->auditLog->record('backup.started', 'backup_run', $run->id, ['components' => $run->components], $request->user(), $request);

        return response()->json(['run' => $this->runData($run->fresh())], Response::HTTP_CREATED);
    }

    public function update(Request $request, BackupRun $backupRun): JsonResponse
    {
        $data = $request->validate([
            'is_locked' => ['sometimes', 'boolean'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        $backupRun->forceFill($data)->save();
        $this->auditLog->record('backup.updated', 'backup_run', $backupRun->id, $data, $request->user(), $request);

        return response()->json(['run' => $this->runData($backupRun->fresh())]);
    }

    public function destroy(Request $request, BackupRun $backupRun): JsonResponse
    {
        $withRemote = $request->boolean('with_remote');

        try {
            $this->manager->delete($backupRun, $withRemote);
        } catch (BackupStepException $exception) {
            return response()->json(['message' => $exception->getMessage()], Response::HTTP_CONFLICT);
        }

        $this->auditLog->record('backup.deleted', 'backup_run', $backupRun->id, ['name' => $backupRun->name, 'with_remote' => $withRemote], $request->user(), $request);

        return response()->json(['message' => 'Backup șters.']);
    }

    public function restoreTest(Request $request, BackupRun $backupRun): JsonResponse
    {
        $data = $request->validate([
            'component' => ['required', Rule::in(['database', 'analytics'])],
        ]);

        try {
            $run = $this->manager->queueRestoreTest($backupRun, $data['component'], $request->user());
        } catch (BackupStepException $exception) {
            return response()->json(['message' => $exception->getMessage()], Response::HTTP_CONFLICT);
        }

        $this->auditLog->record('backup.restore_test', 'backup_run', $backupRun->id, $data, $request->user(), $request);

        return response()->json(['run' => $this->runData($run->fresh())], Response::HTTP_CREATED);
    }

    public function downloadLink(Request $request, BackupRun $backupRun): JsonResponse
    {
        $data = $request->validate(['file' => ['required', 'string', 'max:255']]);

        abort_if($this->manager->filePath($backupRun, $data['file']) === null, Response::HTTP_NOT_FOUND, 'Fișierul nu mai există pe server.');

        $this->auditLog->record('backup.download', 'backup_run', $backupRun->id, ['file' => $data['file']], $request->user(), $request);

        return response()->json([
            'url' => URL::temporarySignedRoute('backups.download', now()->addMinutes(10), ['run' => $backupRun->id, 'file' => $data['file']]),
        ]);
    }

    public function preMigrationLink(Request $request): JsonResponse
    {
        $data = $request->validate(['file' => ['required', 'string', 'max:255']]);

        abort_if($this->manager->preMigrationPath($data['file']) === null, Response::HTTP_NOT_FOUND, 'Fișierul nu există.');

        $this->auditLog->record('backup.download', 'pre_migration_dump', null, ['file' => $data['file']], $request->user(), $request);

        return response()->json([
            'url' => URL::temporarySignedRoute('backups.download', now()->addMinutes(10), ['pre_migration' => $data['file']]),
        ]);
    }

    /**
     * Reached through a short-lived signed URL, so the browser downloads the
     * file directly instead of buffering gigabytes in JavaScript.
     */
    public function download(Request $request): BinaryFileResponse
    {
        if ($request->filled('pre_migration')) {
            $path = $this->manager->preMigrationPath((string) $request->query('pre_migration'));
        } else {
            $run = BackupRun::query()->findOrFail((int) $request->query('run'));
            $path = $this->manager->filePath($run, (string) $request->query('file'));
        }

        abort_if($path === null, Response::HTTP_NOT_FOUND);

        return response()->download($path, basename($path), ['Content-Type' => 'application/octet-stream']);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'schedule' => ['sometimes', 'string', 'max:64', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! BackupSettings::isValidCron((string) $value)) {
                    $fail('Expresia cron nu este validă.');
                }
            }],
            'timezone' => ['sometimes', 'string', 'timezone:all'],
            'components' => ['sometimes', 'array'],
            'components.*' => ['boolean'],
            'retention' => ['sometimes', 'array'],
            'retention.keep_last' => ['sometimes', 'integer', 'min:1', 'max:365'],
            'retention.keep_days' => ['sometimes', 'integer', 'min:1', 'max:3650'],
            'encryption' => ['sometimes', 'array'],
            'encryption.enabled' => ['sometimes', 'boolean'],
            'remote' => ['sometimes', 'array'],
            'remote.enabled' => ['sometimes', 'boolean'],
            'remote.destination' => ['sometimes', 'nullable', 'string', 'max:500', 'regex:/^[A-Za-z0-9_.\-]+:[^\s]*$/'],
            'remote.rclone_config' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'remote.clear_rclone_config' => ['sometimes', 'boolean'],
            'remote.prune' => ['sometimes', 'boolean'],
            'notifications' => ['sometimes', 'array'],
            'notifications.emails' => ['sometimes', 'array', 'max:10'],
            'notifications.emails.*' => ['email', 'max:255'],
            'notifications.on_failure' => ['sometimes', 'boolean'],
            'notifications.on_success' => ['sometimes', 'boolean'],
            'notifications.stale_after_hours' => ['sometimes', 'integer', 'min:1', 'max:720'],
        ], [
            'remote.destination.regex' => 'Destinația trebuie să fie de forma remote:cale (ex: gdrive:film-md-backups).',
        ]);

        if (($data['encryption']['enabled'] ?? false) && ! $this->settings->encryptionAvailable()) {
            return response()->json([
                'message' => 'Setează BACKUP_ENCRYPTION_PASSPHRASE în .env înainte de a activa criptarea.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $this->settings->update($data);
        Cache::forget('backups:tools');

        $this->auditLog->record('backup.settings_updated', 'platform_settings', BackupSettings::SETTINGS_KEY, [
            'keys' => array_keys($data),
            'rclone_config_changed' => array_key_exists('rclone_config', $data['remote'] ?? []) || ! empty($data['remote']['clear_rclone_config']),
        ], $request->user(), $request);

        return $this->index($request->merge(['page' => 1]));
    }

    public function testRemote(Request $request): JsonResponse
    {
        $data = $request->validate([
            'destination' => ['nullable', 'string', 'max:500'],
        ]);

        return response()->json($this->manager->testRemote($data['destination'] ?? null));
    }

    public function testNotification(): JsonResponse
    {
        $sent = $this->notifier->sendTest();

        return $sent > 0
            ? response()->json(['ok' => true, 'message' => "Email de test trimis către {$sent} destinatar(i)."])
            : response()->json(['ok' => false, 'message' => 'Nu s-a trimis nimic: adaugă cel puțin o adresă sau verifică setările MAIL_* din .env.']);
    }

    protected function runData(BackupRun $run, bool $withLog = false): array
    {
        return [
            'id' => $run->id,
            'name' => $run->name,
            'type' => $run->type,
            'trigger' => $run->trigger,
            'status' => $run->status,
            'components' => $run->components ?? [],
            'artifacts' => collect($run->artifacts ?? [])->map(fn (array $artifact): array => [
                ...$artifact,
                'downloadable' => $run->hasFiles() && ($artifact['status'] ?? null) === 'completed' && filled($artifact['file'] ?? null),
            ])->values(),
            'size_bytes' => $run->size_bytes,
            'encrypted' => $run->encrypted,
            'remote_status' => $run->remote_status,
            'remote_path' => $run->remote_path,
            'remote_error' => $run->remote_error,
            'is_locked' => $run->is_locked,
            'note' => $run->note,
            'error_message' => $run->error_message,
            'has_files' => $run->hasFiles(),
            'files_deleted_at' => $run->files_deleted_at?->toIso8601String(),
            'requested_by' => $run->requester?->name,
            'source_run' => $run->sourceRun ? ['id' => $run->sourceRun->id, 'name' => $run->sourceRun->name] : null,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'duration_seconds' => $run->durationSeconds(),
            'created_at' => $run->created_at?->toIso8601String(),
            'log' => $withLog ? ($run->log ?? '') : null,
        ];
    }
}
