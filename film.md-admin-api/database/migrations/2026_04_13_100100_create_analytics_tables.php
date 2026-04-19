<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'analytics';

    public function up(): void
    {
        Schema::connection($this->connection)->create('video_event_raw', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('content_id')->nullable();
            $table->unsignedBigInteger('content_format_id')->nullable();
            $table->unsignedBigInteger('playback_session_id')->nullable();
            $table->string('event_type', 64);
            $table->string('country_code', 5)->nullable();
            $table->unsignedInteger('position_seconds')->nullable();
            $table->unsignedInteger('watch_time_seconds')->nullable();
            $table->decimal('bandwidth_gb', 12, 4)->default(0);
            $table->unsignedInteger('requests_count')->default(0);
            $table->decimal('cache_hit_rate', 5, 2)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->index(['content_id', 'occurred_at']);
            $table->index(['event_type', 'occurred_at']);
        });

        Schema::connection($this->connection)->create('ad_event_raw', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('ad_campaign_id')->nullable();
            $table->unsignedBigInteger('ad_creative_id')->nullable();
            $table->unsignedBigInteger('content_id')->nullable();
            $table->unsignedBigInteger('playback_session_id')->nullable();
            $table->string('event_type', 64);
            $table->string('country_code', 5)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->index(['ad_campaign_id', 'occurred_at']);
            $table->index(['event_type', 'occurred_at']);
        });

        Schema::connection($this->connection)->create('video_daily_aggregates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('content_id');
            $table->unsignedBigInteger('content_format_id')->nullable();
            $table->date('date');
            $table->string('country_code', 5)->nullable();
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('watch_time_seconds')->default(0);
            $table->decimal('bandwidth_gb', 12, 4)->default(0);
            $table->unsignedInteger('requests_count')->default(0);
            $table->decimal('cache_hit_rate', 5, 2)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['content_id', 'content_format_id', 'date', 'country_code'], 'video_daily_aggregates_unique');
        });

        Schema::connection($this->connection)->create('ad_aggregate_daily', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('ad_campaign_id');
            $table->unsignedBigInteger('ad_creative_id')->nullable();
            $table->date('date');
            $table->string('country_code', 5)->nullable();
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('starts')->default(0);
            $table->unsignedInteger('first_quartile')->default(0);
            $table->unsignedInteger('midpoint')->default(0);
            $table->unsignedInteger('third_quartile')->default(0);
            $table->unsignedInteger('completes')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['ad_campaign_id', 'ad_creative_id', 'date', 'country_code'], 'ad_aggregate_daily_unique');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('ad_aggregate_daily');
        Schema::connection($this->connection)->dropIfExists('video_daily_aggregates');
        Schema::connection($this->connection)->dropIfExists('ad_event_raw');
        Schema::connection($this->connection)->dropIfExists('video_event_raw');
    }
};
