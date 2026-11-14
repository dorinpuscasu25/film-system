<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            // The IMDb title id (tt1234567). The rating itself is no longer typed in the admin:
            // it is fetched from OMDb for this id (see ImdbRatingService).
            $table->string('imdb_id', 16)->nullable()->after('imdb_rating')->index();
            $table->timestamp('imdb_rating_synced_at')->nullable()->after('imdb_id');
        });
    }

    public function down(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            $table->dropIndex(['imdb_id']);
            $table->dropColumn(['imdb_id', 'imdb_rating_synced_at']);
        });
    }
};
