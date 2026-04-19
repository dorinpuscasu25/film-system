<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class MediaUploadService
{
    private const DISK = 's3';

    private const ALLOWED_IMAGE_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/avif',
        'image/gif',
        'image/svg+xml',
    ];

    private const MAX_FILE_SIZE_BYTES = 10 * 1024 * 1024; // 10 MB

    /**
     * Upload a file to Cloudflare R2 and return its public CDN URL.
     *
     * @param  string  $directory  Subdirectory inside the bucket (e.g. "content/posters")
     */
    public function upload(UploadedFile $file, string $directory = 'uploads'): string
    {
        $this->validateFile($file);

        $filename = $this->generateFilename($file);
        $path = rtrim($directory, '/') . '/' . $filename;

        Storage::disk(self::DISK)->put($path, $file->getContent(), 'public');

        return $this->publicUrl($path);
    }

    /**
     * Delete a previously uploaded file by its public URL.
     */
    public function delete(string $publicUrl): bool
    {
        $path = $this->pathFromUrl($publicUrl);

        if ($path === null) {
            return false;
        }

        return Storage::disk(self::DISK)->delete($path);
    }

    /**
     * Check whether a URL belongs to our CDN.
     */
    public function isCdnUrl(string $url): bool
    {
        $cdnBase = rtrim((string) config('filesystems.disks.s3.url'), '/');

        if ($cdnBase === '') {
            return false;
        }

        return str_starts_with($url, $cdnBase);
    }

    private function validateFile(UploadedFile $file): void
    {
        if (! $file->isValid()) {
            throw new \InvalidArgumentException('The uploaded file is invalid or corrupted.');
        }

        $mime = $file->getMimeType() ?? '';

        if (! in_array($mime, self::ALLOWED_IMAGE_MIMES, true)) {
            throw new \InvalidArgumentException(
                sprintf('File type "%s" is not allowed. Accepted: %s', $mime, implode(', ', self::ALLOWED_IMAGE_MIMES)),
            );
        }

        if ($file->getSize() > self::MAX_FILE_SIZE_BYTES) {
            throw new \InvalidArgumentException(
                sprintf('File size exceeds the maximum allowed size of %d MB.', self::MAX_FILE_SIZE_BYTES / 1024 / 1024),
            );
        }
    }

    private function generateFilename(UploadedFile $file): string
    {
        $extension = $file->getClientOriginalExtension() ?: ($file->guessExtension() ?? 'bin');

        return Str::ulid()->toBase32() . '.' . strtolower($extension);
    }

    private function publicUrl(string $path): string
    {
        return Storage::disk(self::DISK)->url($path);
    }

    private function pathFromUrl(string $url): ?string
    {
        $cdnBase = rtrim((string) config('filesystems.disks.s3.url'), '/');

        if ($cdnBase === '' || ! str_starts_with($url, $cdnBase)) {
            return null;
        }

        return ltrim(substr($url, strlen($cdnBase)), '/');
    }
}
