<?php

namespace Tests\Feature\Api;

use App\Models\Content;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicSharePreviewApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_share_preview_returns_server_side_open_graph_tags(): void
    {
        $content = Content::query()->create([
            'type' => Content::TYPE_ANIMATION,
            'slug' => 'of-animation',
            'default_locale' => 'ro',
            'status' => Content::STATUS_PUBLISHED,
            'original_title' => 'OF',
            'title' => ['ro' => 'OF'],
            'short_description' => ['ro' => 'Un scurtmetraj de animație cu o poveste memorabilă.'],
            'description' => ['ro' => 'Descriere completă pentru share preview.'],
            'meta_title' => ['ro' => 'OF - animație moldovenească'],
            'meta_description' => ['ro' => 'Descoperă OF, un scurtmetraj de animație disponibil online.'],
            'release_year' => 2026,
            'country_code' => 'MD',
            'country_codes' => ['MD'],
            'poster_url' => 'https://cdn.example.com/of-poster.jpg',
            'backdrop_url' => 'https://cdn.example.com/of-backdrop.jpg',
            'hero_desktop_url' => 'https://cdn.example.com/of-hero.jpg',
            'subtitle_locales' => ['ro'],
            'available_qualities' => ['HD'],
            'currency' => Content::DEFAULT_CURRENCY,
            'published_at' => now(),
        ]);
        $content->formats()->create([
            'quality' => 'HD',
            'format_type' => 'main',
            'bunny_library_id' => '123',
            'bunny_video_id' => 'of-animation-hd',
            'stream_url' => 'https://cdn.example.com/of.mp4',
            'is_active' => true,
            'is_default' => true,
        ]);

        $this->get('/api/v1/public/content/of-animation/share-preview?locale=ro')
            ->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->assertSee('<meta property="og:title" content="OF - animație moldovenească | filmoteca.md">', false)
            ->assertSee('<meta property="og:description" content="Descoperă OF, un scurtmetraj de animație disponibil online.', false)
            ->assertSee('<meta property="og:image" content="https://cdn.example.com/of-hero.jpg">', false)
            ->assertSee('<meta http-equiv="refresh"', false)
            ->assertSee('/movie/of-animation', false);
    }
}
