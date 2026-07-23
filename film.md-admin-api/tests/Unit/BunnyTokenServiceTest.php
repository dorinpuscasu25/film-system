<?php

namespace Tests\Unit;

use App\Models\ContentFormat;
use App\Services\BunnyLibraryResolver;
use App\Services\BunnyTokenService;
use Tests\TestCase;

class BunnyTokenServiceTest extends TestCase
{
    public function test_it_generates_the_mobile_embed_view_token_without_exposing_the_api_key(): void
    {
        $resolver = $this->createMock(BunnyLibraryResolver::class);
        $resolver->method('forFormat')->willReturn([
            'kind' => BunnyLibraryResolver::LIBRARY_MOVIES,
            'api_key' => 'server-only-library-key',
        ]);
        $format = new ContentFormat([
            'format_type' => ContentFormat::TYPE_MAIN,
            'bunny_video_id' => 'video-123',
        ]);

        $result = (new BunnyTokenService($resolver))->embedViewToken($format, ttlSeconds: 300);

        $this->assertNotNull($result);
        $decoded = base64_decode($result['token'], true);
        $this->assertIsString($decoded);
        [$signature, $expires] = explode(':', $decoded, 2);
        $this->assertSame((string) $result['expires'], $expires);
        $this->assertSame(
            hash_hmac('sha256', 'server-only-library-key'.'video-123'.$expires, 'server-only-library-key'),
            $signature,
        );
        $this->assertStringNotContainsString('server-only-library-key', $result['token']);
    }

    public function test_it_does_not_generate_a_token_without_the_server_library_key(): void
    {
        $resolver = $this->createMock(BunnyLibraryResolver::class);
        $resolver->method('forFormat')->willReturn([
            'kind' => BunnyLibraryResolver::LIBRARY_MOVIES,
            'api_key' => null,
        ]);

        $result = (new BunnyTokenService($resolver))->embedViewToken(
            new ContentFormat(['bunny_video_id' => 'video-123']),
        );

        $this->assertNull($result);
    }
}
