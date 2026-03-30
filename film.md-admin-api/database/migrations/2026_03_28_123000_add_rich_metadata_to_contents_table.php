<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            $table->decimal('imdb_rating', 3, 1)->nullable()->after('country_code');
            $table->decimal('platform_rating', 3, 1)->nullable()->after('imdb_rating');
            $table->json('cast_members')->nullable()->after('preview_images');
            $table->json('crew_members')->nullable()->after('cast_members');
            $table->json('videos')->nullable()->after('crew_members');
            $table->json('seasons')->nullable()->after('videos');
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            $table->dropColumn([
                'imdb_rating',
                'platform_rating',
                'cast_members',
                'crew_members',
                'videos',
                'seasons',
            ]);
        });
    }
};
