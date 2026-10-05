<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_campaigns', function (Blueprint $table): void {
            // A campaign may now run in several slots at once. The legacy single
            // `placement` column stays as the fallback for existing rows.
            $table->json('placements')->nullable()->after('placement');
            // Repeat mid-rolls every N minutes instead of a single fixed offset.
            $table->unsignedSmallInteger('mid_roll_every_minutes')->nullable()->after('mid_roll_offset_seconds');
        });
    }

    public function down(): void
    {
        Schema::table('ad_campaigns', function (Blueprint $table): void {
            $table->dropColumn(['placements', 'mid_roll_every_minutes']);
        });
    }
};
