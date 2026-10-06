<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\MediaUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class UploadController extends Controller
{
    public function __construct(
        private readonly MediaUploadService $uploadService,
    ) {}

    /**
     * Upload one or more files to Cloudflare R2 CDN.
     *
     * Accepts multipart/form-data with:
     *   - file   (single file)   → returns { url: string }
     *   - files[] (multiple files) → returns { urls: string[] }
     *   - directory (optional)    → R2 subdirectory, defaults to "uploads"
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['nullable', 'file', 'max:262144'],
            'files' => ['nullable', 'array', 'max:20'],
            'files.*' => ['file', 'max:262144'],
            'directory' => ['nullable', 'string', 'max:255', 'regex:/^[a-zA-Z0-9_\-\/]+$/'],
            // Images are the default so existing callers keep working; "video"
            // is used for ad creatives, which VAST needs as progressive files.
            'kind' => ['nullable', 'string', 'in:image,video'],
        ]);

        $isVideo = $request->input('kind') === 'video';
        $directory = $request->input('directory', $isVideo ? 'media/spots' : 'uploads');

        // Single file upload
        if ($request->hasFile('file')) {
            try {
                $url = $isVideo
                    ? $this->uploadService->uploadVideo($request->file('file'), $directory)
                    : $this->uploadService->upload($request->file('file'), $directory);

                return response()->json(['url' => $url], Response::HTTP_CREATED);
            } catch (\InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
            }
        }

        // Multiple files upload
        if ($request->hasFile('files')) {
            $urls = [];
            $errors = [];

            foreach ($request->file('files') as $index => $file) {
                try {
                    $urls[] = $isVideo
                        ? $this->uploadService->uploadVideo($file, $directory)
                        : $this->uploadService->upload($file, $directory);
                } catch (\InvalidArgumentException $e) {
                    $errors[] = [
                        'index' => $index,
                        'name' => $file->getClientOriginalName(),
                        'error' => $e->getMessage(),
                    ];
                }
            }

            if (! empty($errors) && empty($urls)) {
                return response()->json([
                    'message' => 'All file uploads failed.',
                    'errors' => $errors,
                ], Response::HTTP_UNPROCESSABLE_ENTITY);
            }

            return response()->json([
                'urls' => $urls,
                'errors' => $errors,
            ], Response::HTTP_CREATED);
        }

        return response()->json(
            ['message' => 'No file provided. Send a "file" or "files[]" field.'],
            Response::HTTP_UNPROCESSABLE_ENTITY,
        );
    }

    /**
     * Delete a file from Cloudflare R2 CDN by its public URL.
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->validate([
            'url' => ['required', 'url', 'max:2048'],
        ]);

        $deleted = $this->uploadService->delete($request->input('url'));

        if (! $deleted) {
            return response()->json(
                ['message' => 'File not found or URL does not belong to our CDN.'],
                Response::HTTP_NOT_FOUND,
            );
        }

        return response()->json([], Response::HTTP_NO_CONTENT);
    }
}
