<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ImageVariantService
{
    private const DISK = 's3';

    public function __construct(
        private readonly MediaUploadService $mediaUpload,
    ) {}

    public function variantUrl(string $publicUrl, int $width, ?int $height = null, string $fit = 'cover'): ?string
    {
        $path = $this->mediaUpload->pathFromUrl($publicUrl);
        if ($path === null || $this->isUnsupportedSource($path)) {
            return null;
        }

        $width = min(max($width, 32), 2400);
        $height = $height !== null ? min(max($height, 32), 2400) : null;
        $fit = in_array($fit, ['cover', 'contain'], true) ? $fit : 'cover';
        $variantPath = $this->variantPath($path, $width, $height, $fit);

        if (Storage::disk(self::DISK)->exists($variantPath)) {
            return $this->mediaUpload->publicUrl($variantPath);
        }

        $binary = Storage::disk(self::DISK)->get($path);
        if (! is_string($binary) || $binary === '') {
            return null;
        }

        $webp = $this->makeWebp($binary, $width, $height, $fit);
        Storage::disk(self::DISK)->put($variantPath, $webp, [
            'visibility' => 'public',
            'ContentType' => 'image/webp',
            'CacheControl' => 'public, max-age=31536000, immutable',
        ]);

        return $this->mediaUpload->publicUrl($variantPath);
    }

    private function variantPath(string $sourcePath, int $width, ?int $height, string $fit): string
    {
        $extension = pathinfo($sourcePath, PATHINFO_EXTENSION);
        $base = $extension !== '' ? substr($sourcePath, 0, -(strlen($extension) + 1)) : $sourcePath;
        $signature = substr(hash('sha256', "{$sourcePath}|{$width}|{$height}|{$fit}"), 0, 12);

        return sprintf('%s__%sx%s_%s_%s.webp', $base, $width, $height ?: 'auto', $fit, $signature);
    }

    private function isUnsupportedSource(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['svg', 'gif'], true);
    }

    private function makeWebp(string $binary, int $width, ?int $height, string $fit): string
    {
        $source = @imagecreatefromstring($binary);
        if ($source === false) {
            throw new RuntimeException('The source image could not be decoded.');
        }

        imagepalettetotruecolor($source);
        imagealphablending($source, true);
        imagesavealpha($source, true);

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $targetWidth = $width;
        $targetHeight = $height ?? max(1, (int) round($sourceHeight * ($width / max(1, $sourceWidth))));

        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
        imagefilledrectangle($target, 0, 0, $targetWidth, $targetHeight, $transparent);

        [$srcX, $srcY, $srcW, $srcH, $dstX, $dstY, $dstW, $dstH] = $this->dimensions(
            $sourceWidth,
            $sourceHeight,
            $targetWidth,
            $targetHeight,
            $fit,
        );

        imagecopyresampled($target, $source, $dstX, $dstY, $srcX, $srcY, $dstW, $dstH, $srcW, $srcH);

        ob_start();
        imagewebp($target, null, 78);
        $webp = ob_get_clean();

        imagedestroy($source);
        imagedestroy($target);

        if (! is_string($webp) || $webp === '') {
            throw new RuntimeException('The image variant could not be encoded.');
        }

        return $webp;
    }

    private function dimensions(int $sourceWidth, int $sourceHeight, int $targetWidth, int $targetHeight, string $fit): array
    {
        if ($fit === 'contain') {
            $scale = min($targetWidth / max(1, $sourceWidth), $targetHeight / max(1, $sourceHeight));
            $dstW = max(1, (int) round($sourceWidth * $scale));
            $dstH = max(1, (int) round($sourceHeight * $scale));

            return [0, 0, $sourceWidth, $sourceHeight, (int) floor(($targetWidth - $dstW) / 2), (int) floor(($targetHeight - $dstH) / 2), $dstW, $dstH];
        }

        $sourceRatio = $sourceWidth / max(1, $sourceHeight);
        $targetRatio = $targetWidth / max(1, $targetHeight);

        if ($sourceRatio > $targetRatio) {
            $srcH = $sourceHeight;
            $srcW = max(1, (int) round($sourceHeight * $targetRatio));
            $srcX = (int) floor(($sourceWidth - $srcW) / 2);

            return [$srcX, 0, $srcW, $srcH, 0, 0, $targetWidth, $targetHeight];
        }

        $srcW = $sourceWidth;
        $srcH = max(1, (int) round($sourceWidth / $targetRatio));
        $srcY = (int) floor(($sourceHeight - $srcH) / 2);

        return [0, $srcY, $srcW, $srcH, 0, 0, $targetWidth, $targetHeight];
    }
}
