<?php

namespace Tests\Unit;

use App\Services\MediaUploadService;
use Tests\TestCase;

class MediaUploadServiceTest extends TestCase
{
    public function test_it_accepts_current_and_legacy_cdn_urls_during_migration(): void
    {
        config([
            'filesystems.disks.s3.url' => 'https://cdn.filmoteca.md',
            'filesystems.disks.s3.legacy_url' => 'https://legacy-bucket.r2.dev',
        ]);

        $service = app(MediaUploadService::class);

        $this->assertTrue($service->isCdnUrl('https://cdn.filmoteca.md/content/poster.jpg'));
        $this->assertTrue($service->isCdnUrl('https://legacy-bucket.r2.dev/content/poster.jpg'));
        $this->assertSame(
            'content/poster.jpg',
            $service->pathFromUrl('https://legacy-bucket.r2.dev/content/poster.jpg'),
        );
        $this->assertFalse($service->isCdnUrl('https://example.com/content/poster.jpg'));
    }

    public function test_it_does_not_accept_a_hostname_prefix_collision(): void
    {
        config([
            'filesystems.disks.s3.url' => 'https://cdn.filmoteca.md',
            'filesystems.disks.s3.legacy_url' => null,
        ]);

        $service = app(MediaUploadService::class);

        $this->assertFalse($service->isCdnUrl('https://cdn.filmoteca.md.attacker.example/poster.jpg'));
    }
}
