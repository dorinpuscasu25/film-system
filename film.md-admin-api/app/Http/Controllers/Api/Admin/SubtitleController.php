<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\Content;
use App\Models\SubtitleTrack;
use App\Services\AuditLogService;
use App\Services\ContentScopeService;
use App\Services\MediaUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CRUD for subtitle_tracks per content. Stores the WebVTT/SRT URL on R2.
 * The storefront player consumes /api/v1/public/content/{slug} which returns
 * `subtitles[]` with locale + label + url for HLS sidecar tracks.
 */
class SubtitleController extends ApiController
{
    public function __construct(
        protected ContentScopeService $scope,
        protected MediaUploadService $media,
        protected AuditLogService $auditLog,
    ) {}

    public function index(Request $request, Content $content): JsonResponse
    {
        $this->scope->assertCanAccessContent($request->user(), $content);

        $tracks = SubtitleTrack::query()
            ->where('content_id', $content->id)
            ->orderBy('sort_order')
            ->get();

        return response()->json([
            'content_id' => $content->id,
            'items' => $tracks->map(fn (SubtitleTrack $t) => [
                'id' => $t->id,
                'locale' => $t->locale,
                'label' => $t->label,
                'file_url' => $t->file_url,
                'is_default' => $t->is_default,
                'sort_order' => $t->sort_order,
                'content_format_id' => $t->content_format_id,
            ])->values(),
        ]);
    }

    public function store(Request $request, Content $content): JsonResponse
    {
        $this->scope->assertCanAccessContent($request->user(), $content);

        $payload = $request->validate([
            'locale' => ['required', 'string', 'max:8'],
            'label' => ['required', 'string', 'max:64'],
            'file_url' => ['required', 'string', 'max:2048'],
            'content_format_id' => ['nullable', 'integer'],
            'is_default' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer'],
        ]);

        // If file_url is a base64 data URI, push to R2 first
        $payload['file_url'] = $this->media->resolveImageUrl($payload['file_url'], 'subtitles')
            ?? $payload['file_url'];

        $track = SubtitleTrack::query()->create([
            'content_id' => $content->id,
            'locale' => $payload['locale'],
            'label' => $payload['label'],
            'file_url' => $payload['file_url'],
            'content_format_id' => $payload['content_format_id'] ?? null,
            'is_default' => $payload['is_default'] ?? false,
            'sort_order' => $payload['sort_order'] ?? 0,
        ]);

        $this->auditLog->record('subtitle.created', 'subtitle_track', $track->id, [
            'content_id' => $content->id,
            'locale' => $track->locale,
        ], $request->user(), $request);

        return response()->json(['track' => $track], Response::HTTP_CREATED);
    }

    public function update(Request $request, Content $content, SubtitleTrack $track): JsonResponse
    {
        $this->scope->assertCanAccessContent($request->user(), $content);
        if ($track->content_id !== $content->id) {
            return response()->json(['message' => 'Subtitrarea nu aparține acestui film.'], Response::HTTP_NOT_FOUND);
        }

        $payload = $request->validate([
            'locale' => ['sometimes', 'string', 'max:8'],
            'label' => ['sometimes', 'string', 'max:64'],
            'file_url' => ['sometimes', 'string', 'max:2048'],
            'is_default' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer'],
        ]);

        if (isset($payload['file_url'])) {
            $payload['file_url'] = $this->media->resolveImageUrl($payload['file_url'], 'subtitles')
                ?? $payload['file_url'];
        }

        $track->fill($payload)->save();
        $this->auditLog->record('subtitle.updated', 'subtitle_track', $track->id, [], $request->user(), $request);

        return response()->json(['track' => $track->fresh()]);
    }

    public function destroy(Request $request, Content $content, SubtitleTrack $track): JsonResponse
    {
        $this->scope->assertCanAccessContent($request->user(), $content);
        if ($track->content_id !== $content->id) {
            return response()->json(['message' => 'Subtitrarea nu aparține acestui film.'], Response::HTTP_NOT_FOUND);
        }
        $id = $track->id;
        $track->delete();
        $this->auditLog->record('subtitle.deleted', 'subtitle_track', $id, [], $request->user(), $request);

        return response()->json([], Response::HTTP_NO_CONTENT);
    }
}
