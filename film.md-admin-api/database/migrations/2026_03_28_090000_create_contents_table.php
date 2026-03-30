<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contents', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 32);
            $table->string('slug')->unique();
            $table->string('default_locale', 5)->default('ro');
            $table->string('status', 32)->default('draft');
            $table->string('original_title');
            $table->json('title');
            $table->json('tagline')->nullable();
            $table->json('short_description');
            $table->json('description');
            $table->json('editor_notes')->nullable();
            $table->json('meta_title')->nullable();
            $table->json('meta_description')->nullable();
            $table->unsignedSmallInteger('release_year')->nullable();
            $table->string('country_code', 5)->nullable();
            $table->unsignedInteger('runtime_minutes')->nullable();
            $table->string('age_rating', 12)->nullable();
            $table->text('poster_url');
            $table->text('backdrop_url');
            $table->text('hero_desktop_url')->nullable();
            $table->text('hero_mobile_url')->nullable();
            $table->text('trailer_url')->nullable();
            $table->json('preview_images')->nullable();
            $table->json('subtitle_locales')->nullable();
            $table->json('available_qualities');
            $table->boolean('is_featured')->default(false);
            $table->boolean('is_trending')->default(false);
            $table->boolean('is_free')->default(false);
            $table->decimal('price_amount', 10, 2)->default(0);
            $table->string('currency', 3)->default('USD');
            $table->unsignedInteger('rental_days')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->text('canonical_url')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['status', 'type']);
            $table->index(['is_featured', 'status']);
            $table->index(['release_year', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contents');
    }
};
