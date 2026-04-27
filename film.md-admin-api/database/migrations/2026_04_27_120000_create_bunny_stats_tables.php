<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected string $connection = 'analytics';

    public function up(): void
    {
        // Stats per video, per day (from Bunny Stream API)
        Schema::connection($this->connection)->create('bunny_stream_stats_daily', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('content_id')->nullable();
            $table->unsignedBigInteger('content_format_id')->nullable();
            $table->string('bunny_library_id', 64);
            $table->string('bunny_video_id', 128);
            $table->date('date');
            $table->unsignedInteger('views')->default(0);
            $table->unsignedBigInteger('watch_time_seconds')->default(0);
            $table->unsignedInteger('plays')->default(0);
            $table->unsignedInteger('finishes')->default(0);
            $table->unsignedBigInteger('bandwidth_bytes')->default(0);
            $table->json('country_breakdown')->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['bunny_library_id', 'bunny_video_id', 'date'], 'bunny_stream_stats_unique');
            $table->index(['content_id', 'date']);
        });

        // Stats per pull zone, per day (from Bunny CDN Statistics API)
        Schema::connection($this->connection)->create('bunny_cdn_stats_daily', function (Blueprint $table): void {
            $table->id();
            $table->string('pull_zone_id', 64);
            $table->date('date');
            $table->unsignedBigInteger('bandwidth_bytes')->default(0);
            $table->unsignedBigInteger('origin_bandwidth_bytes')->default(0);
            $table->unsignedBigInteger('requests_served')->default(0);
            $table->decimal('cache_hit_rate', 5, 2)->nullable();
            $table->unsignedInteger('avg_response_time_ms')->nullable();
            $table->json('geo_breakdown')->nullable(); // { "MD": bytes, "RO": bytes, ... }
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['pull_zone_id', 'date'], 'bunny_cdn_stats_unique');
        });

        // Daily storage snapshot per zone
        Schema::connection($this->connection)->create('bunny_storage_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('storage_zone_name', 128);
            $table->date('date');
            $table->unsignedBigInteger('used_bytes')->default(0);
            $table->unsignedInteger('files_count')->default(0);
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->unique(['storage_zone_name', 'date'], 'bunny_storage_snapshots_unique');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('bunny_storage_snapshots');
        Schema::connection($this->connection)->dropIfExists('bunny_cdn_stats_daily');
        Schema::connection($this->connection)->dropIfExists('bunny_stream_stats_daily');
    }
};
