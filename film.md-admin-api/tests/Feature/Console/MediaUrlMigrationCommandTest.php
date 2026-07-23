<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MediaUrlMigrationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_previews_and_then_applies_legacy_cdn_url_replacements(): void
    {
        $old = 'https://legacy-bucket.r2.dev';
        $new = 'https://cdn.filmoteca.md';

        DB::table('contents')->insert([
            'type' => 'movie',
            'slug' => 'cdn-migration-test',
            'default_locale' => 'ro',
            'status' => 'draft',
            'original_title' => 'CDN migration test',
            'title' => json_encode(['ro' => 'CDN migration test']),
            'short_description' => json_encode(['ro' => 'Test']),
            'description' => json_encode(['ro' => 'Test']),
            'poster_url' => "{$old}/content/posters/poster.jpg",
            'backdrop_url' => 'https://images.example.com/backdrop.jpg',
            'preview_images' => json_encode([
                "{$old}/content/previews/one.jpg",
                "{$old}/content/previews/two.jpg",
            ]),
            'cast_members' => json_encode([
                ['name' => 'Actor', 'avatar_url' => "{$old}/people/actor.jpg"],
            ]),
            'available_qualities' => json_encode(['HD']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $exitCode = Artisan::call('media:migrate-cdn-urls', [
            '--from' => $old,
            '--to' => $new,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(
            'Dry-run: 4 URL(s) in 1 record(s).',
            Artisan::output(),
        );

        $dryRunRecord = DB::table('contents')->where('slug', 'cdn-migration-test')->first();
        $this->assertStringContainsString($old, $dryRunRecord->poster_url);
        $this->assertSame(
            [
                "{$old}/content/previews/one.jpg",
                "{$old}/content/previews/two.jpg",
            ],
            json_decode($dryRunRecord->preview_images, true),
        );

        $exitCode = Artisan::call('media:migrate-cdn-urls', [
            '--from' => $old,
            '--to' => $new,
            '--apply' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString(
            'Applied: 4 URL(s) in 1 record(s).',
            Artisan::output(),
        );

        $migratedRecord = DB::table('contents')->where('slug', 'cdn-migration-test')->first();
        $this->assertStringNotContainsString($old, $migratedRecord->poster_url);
        $this->assertStringContainsString($new, $migratedRecord->poster_url);
        $this->assertSame(
            [
                "{$new}/content/previews/one.jpg",
                "{$new}/content/previews/two.jpg",
            ],
            json_decode($migratedRecord->preview_images, true),
        );
        $this->assertSame(
            "{$new}/people/actor.jpg",
            json_decode($migratedRecord->cast_members, true)[0]['avatar_url'],
        );
        $this->assertSame('https://images.example.com/backdrop.jpg', $migratedRecord->backdrop_url);
    }
}
