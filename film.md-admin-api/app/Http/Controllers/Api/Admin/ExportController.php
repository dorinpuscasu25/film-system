<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\ExportJob;
use App\Services\AuditLogService;
use App\Services\ExportGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ExportController extends ApiController
{
    public function __construct(
        protected ExportGenerationService $exports,
        protected AuditLogService $auditLog,
    ) {}

    public function index(): JsonResponse
    {
        $jobs = ExportJob::query()
            ->with('user')
            ->latest('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'items' => $jobs->map(fn (ExportJob $job) => [
                'id' => $job->id,
                'format' => $job->format,
                'scope' => $job->scope,
                'status' => $job->status,
                'file_path' => $job->file_path,
                'file_name' => data_get($job->meta ?? [], 'file_name'),
                'mime_type' => data_get($job->meta ?? [], 'mime_type'),
                'error_message' => data_get($job->meta ?? [], 'error_message'),
                'filters' => $job->filters ?? [],
                'requested_by' => $job->user?->name,
                'created_at' => $job->created_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'format' => ['required', 'string', 'in:pdf,xlsx,csv'],
            'scope' => ['required', 'string', 'max:32'],
            'filters' => ['nullable', 'array'],
        ]);

        // Excel/CSV restricted to admins (`exports.manage`).
        // PDF is broader — any user with `commerce.view_billing` (admin + creators).
        $user = $request->user();
        if (in_array($payload['format'], ['xlsx', 'csv'], true) && ! $user?->hasPermission('exports.manage')) {
            return response()->json([
                'message' => 'Doar administratorii pot exporta date editabile (Excel/CSV). Creators pot exporta în format PDF.',
            ], Response::HTTP_FORBIDDEN);
        }

        $job = ExportJob::query()->create([
            'user_id' => $request->user()?->id,
            'format' => $payload['format'],
            'scope' => $payload['scope'],
            'status' => 'queued',
            'filters' => $payload['filters'] ?? [],
            'meta' => [
                'requested_from' => 'admin',
            ],
        ]);

        $job = $this->exports->generate($job, $request->user());
        $this->auditLog->record(
            'export.created',
            'export_job',
            $job->id,
            ['format' => $job->format, 'scope' => $job->scope, 'status' => $job->status],
            $request->user(),
            $request,
        );

        return response()->json([
            'job' => $job,
        ], Response::HTTP_CREATED);
    }

    public function download(Request $request, ExportJob $exportJob): StreamedResponse
    {
        abort_unless(
            $exportJob->user_id === null
                || $exportJob->user_id === $request->user()?->id
                || $request->user()?->hasPermission('exports.manage'),
            Response::HTTP_FORBIDDEN,
        );

        abort_unless(
            $exportJob->status === 'completed' && $exportJob->file_path !== null,
            Response::HTTP_NOT_FOUND,
        );

        abort_unless(Storage::disk('local')->exists($exportJob->file_path), Response::HTTP_NOT_FOUND);

        $this->auditLog->record(
            'export.downloaded',
            'export_job',
            $exportJob->id,
            ['file_name' => data_get($exportJob->meta ?? [], 'file_name')],
            $request->user(),
            $request,
        );

        return Storage::disk('local')->download(
            $exportJob->file_path,
            data_get($exportJob->meta ?? [], 'file_name') ?: basename($exportJob->file_path),
            [
                'Content-Type' => data_get($exportJob->meta ?? [], 'mime_type', 'application/octet-stream'),
            ],
        );
    }
}
