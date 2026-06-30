<?php

namespace App\Http\Controllers\Api;

use App\Services\ImageVariantService;
use App\Services\MediaUploadService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class PublicImageController extends ApiController
{
    public function resize(Request $request, ImageVariantService $images, MediaUploadService $media): RedirectResponse
    {
        $data = $request->validate([
            'url' => ['required', 'url', 'max:4096'],
            'w' => ['required', 'integer', 'min:32', 'max:2400'],
            'h' => ['nullable', 'integer', 'min:32', 'max:2400'],
            'fit' => ['nullable', 'in:cover,contain'],
        ]);

        $sourceUrl = (string) $data['url'];
        abort_unless($media->isCdnUrl($sourceUrl), Response::HTTP_NOT_FOUND);

        try {
            $variantUrl = $images->variantUrl(
                $sourceUrl,
                (int) $data['w'],
                isset($data['h']) ? (int) $data['h'] : null,
                (string) ($data['fit'] ?? 'cover'),
            );
        } catch (Throwable $exception) {
            report($exception);
            $variantUrl = null;
        }

        return redirect()->away($variantUrl ?: $sourceUrl, Response::HTTP_FOUND)
            ->header('Cache-Control', 'public, max-age=86400, stale-while-revalidate=604800');
    }
}
