<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\Content;
use App\Models\ContentRightsWindow;
use App\Services\AuditLogService;
use App\Services\ContentScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Manages content availability windows (geo + time-window rights).
 * Caiet de sarcini §4: configurare drepturi pe teritorii + perioade de
 * disponibilitate per titlu.
 */
class AvailabilityWindowController extends ApiController
{
    public function __construct(
        protected ContentScopeService $scope,
        protected AuditLogService $auditLog,
    ) {}

    public function index(Request $request, Content $content): JsonResponse
    {
        $this->scope->assertCanAccessContent($request->user(), $content);

        $windows = ContentRightsWindow::query()
            ->where('content_id', $content->id)
            ->orderBy('starts_at')
            ->get();

        return response()->json([
            'content_id' => $content->id,
            'items' => $windows->map(fn (ContentRightsWindow $w) => [
                'id' => $w->id,
                'content_format_id' => $w->content_format_id,
                'country_code' => $w->country_code,
                'is_allowed' => $w->is_allowed,
                'starts_at' => $w->starts_at?->toIso8601String(),
                'ends_at' => $w->ends_at?->toIso8601String(),
            ])->values(),
        ]);
    }

    public function store(Request $request, Content $content): JsonResponse
    {
        $this->scope->assertCanAccessContent($request->user(), $content);

        $payload = $request->validate([
            'content_format_id' => ['nullable', 'integer'],
            'country_code' => ['nullable', 'string', 'max:5'],
            'is_allowed' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ]);

        $window = ContentRightsWindow::query()->create([
            'content_id' => $content->id,
            'content_format_id' => $payload['content_format_id'] ?? null,
            'country_code' => isset($payload['country_code']) ? strtoupper($payload['country_code']) : null,
            'is_allowed' => $payload['is_allowed'] ?? true,
            'starts_at' => $payload['starts_at'] ?? null,
            'ends_at' => $payload['ends_at'] ?? null,
        ]);

        $this->auditLog->record('availability_window.created', 'content_rights_window', $window->id, [
            'content_id' => $content->id,
            'country_code' => $window->country_code,
        ], $request->user(), $request);

        return response()->json(['window' => $window], Response::HTTP_CREATED);
    }

    public function update(Request $request, Content $content, ContentRightsWindow $window): JsonResponse
    {
        $this->scope->assertCanAccessContent($request->user(), $content);
        if ($window->content_id !== $content->id) {
            return response()->json(['message' => 'Fereastra nu aparține acestui film.'], Response::HTTP_NOT_FOUND);
        }

        $payload = $request->validate([
            'content_format_id' => ['nullable', 'integer'],
            'country_code' => ['nullable', 'string', 'max:5'],
            'is_allowed' => ['sometimes', 'boolean'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
        ]);

        if (isset($payload['country_code'])) {
            $payload['country_code'] = strtoupper($payload['country_code']);
        }
        $window->fill($payload)->save();
        $this->auditLog->record('availability_window.updated', 'content_rights_window', $window->id, [], $request->user(), $request);

        return response()->json(['window' => $window->fresh()]);
    }

    public function destroy(Request $request, Content $content, ContentRightsWindow $window): JsonResponse
    {
        $this->scope->assertCanAccessContent($request->user(), $content);
        if ($window->content_id !== $content->id) {
            return response()->json(['message' => 'Fereastra nu aparține acestui film.'], Response::HTTP_NOT_FOUND);
        }
        $id = $window->id;
        $window->delete();
        $this->auditLog->record('availability_window.deleted', 'content_rights_window', $id, [], $request->user(), $request);

        return response()->json([], Response::HTTP_NO_CONTENT);
    }
}
