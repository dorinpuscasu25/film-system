<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_formats', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_id')->constrained('contents')->cascadeOnDelete();
            $table->string('quality', 32);
            $table->string('format_type', 32)->default('main');
            $table->string('bunny_library_id', 64);
            $table->string('bunny_video_id', 128);
            $table->string('stream_url')->nullable();
            $table->string('token_path')->nullable();
            $table->string('drm_policy', 32)->default('tokenized');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['content_id', 'quality', 'format_type'], 'content_formats_unique_variant');
            $table->index(['bunny_library_id', 'bunny_video_id']);
        });

        Schema::create('content_rights_windows', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_id')->constrained('contents')->cascadeOnDelete();
            $table->foreignId('content_format_id')->nullable()->constrained('content_formats')->nullOnDelete();
            $table->string('country_code', 5)->nullable();
            $table->boolean('is_allowed')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['content_id', 'country_code']);
            $table->index(['starts_at', 'ends_at']);
        });

        Schema::create('subtitle_tracks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_id')->constrained('contents')->cascadeOnDelete();
            $table->foreignId('content_format_id')->nullable()->constrained('content_formats')->nullOnDelete();
            $table->string('locale', 8);
            $table->string('label', 64);
            $table->string('file_url');
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['content_id', 'locale']);
        });

        Schema::create('content_creators', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('company_name')->nullable();
            $table->decimal('platform_fee_percent', 5, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('content_creator_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_id')->constrained('contents')->cascadeOnDelete();
            $table->foreignId('content_creator_id')->constrained('content_creators')->cascadeOnDelete();
            $table->string('role', 32)->default('owner');
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['content_id', 'content_creator_id', 'role'], 'content_creator_assignments_unique');
        });

        Schema::create('content_playlists', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('content_playlist_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_playlist_id')->constrained('content_playlists')->cascadeOnDelete();
            $table->foreignId('content_id')->constrained('contents')->cascadeOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['content_playlist_id', 'content_id'], 'content_playlist_items_unique');
        });

        Schema::create('premiere_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_id')->constrained('contents')->cascadeOnDelete();
            $table->string('title');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['content_id', 'starts_at']);
        });

        Schema::create('playback_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('content_id')->constrained('contents')->cascadeOnDelete();
            $table->foreignId('content_format_id')->nullable()->constrained('content_formats')->nullOnDelete();
            $table->foreignId('offer_id')->nullable()->constrained('offers')->nullOnDelete();
            $table->foreignId('account_profile_id')->nullable()->constrained('account_profiles')->nullOnDelete();
            $table->string('session_token', 80)->unique();
            $table->string('country_code', 5)->nullable();
            $table->string('device_type', 32)->nullable();
            $table->string('status', 32)->default('started');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('watch_time_seconds')->default(0);
            $table->unsignedInteger('max_position_seconds')->default(0);
            $table->boolean('counted_as_view')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['content_id', 'started_at']);
            $table->index(['user_id', 'content_id']);
        });

        Schema::create('watch_progress', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('account_profile_id')->nullable()->constrained('account_profiles')->nullOnDelete();
            $table->foreignId('content_id')->constrained('contents')->cascadeOnDelete();
            $table->string('episode_id', 128)->nullable();
            $table->foreignId('content_format_id')->nullable()->constrained('content_formats')->nullOnDelete();
            $table->unsignedInteger('position_seconds')->default(0);
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->unsignedInteger('watch_time_seconds')->default(0);
            $table->timestamp('last_watched_at')->nullable();
            $table->boolean('is_completed')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'account_profile_id', 'content_id', 'episode_id'], 'watch_progress_unique_entry');
        });

        Schema::create('watch_history', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('account_profile_id')->nullable()->constrained('account_profiles')->nullOnDelete();
            $table->foreignId('content_id')->constrained('contents')->cascadeOnDelete();
            $table->foreignId('playback_session_id')->nullable()->constrained('playback_sessions')->nullOnDelete();
            $table->timestamp('watched_at');
            $table->unsignedInteger('position_seconds')->default(0);
            $table->unsignedInteger('watch_time_seconds')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'watched_at']);
            $table->index(['content_id', 'watched_at']);
        });

        Schema::create('cost_settings_versions', function (Blueprint $table): void {
            $table->id();
            $table->decimal('storage_cost_per_gb_day', 10, 4)->default(0);
            $table->decimal('delivery_cost_per_gb', 10, 4)->default(0);
            $table->decimal('drm_cost_per_license', 10, 4)->default(0);
            $table->decimal('usd_to_mdl_rate', 10, 4)->default(1);
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['effective_from', 'effective_until']);
        });

        Schema::create('video_monthly_costs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_id')->constrained('contents')->cascadeOnDelete();
            $table->foreignId('content_format_id')->nullable()->constrained('content_formats')->nullOnDelete();
            $table->foreignId('content_creator_id')->nullable()->constrained('content_creators')->nullOnDelete();
            $table->foreignId('cost_settings_version_id')->nullable()->constrained('cost_settings_versions')->nullOnDelete();
            $table->string('month', 7);
            $table->decimal('storage_cost_usd', 12, 4)->default(0);
            $table->decimal('delivery_cost_usd', 12, 4)->default(0);
            $table->decimal('drm_cost_usd', 12, 4)->default(0);
            $table->decimal('revenue_usd', 12, 4)->default(0);
            $table->decimal('profit_usd', 12, 4)->default(0);
            $table->decimal('usd_to_mdl_rate', 10, 4)->default(1);
            $table->boolean('is_locked')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['content_id', 'content_format_id', 'month'], 'video_monthly_costs_unique');
        });

        Schema::create('creator_monthly_statements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_creator_id')->constrained('content_creators')->cascadeOnDelete();
            $table->string('month', 7);
            $table->decimal('revenue_usd', 12, 4)->default(0);
            $table->decimal('costs_usd', 12, 4)->default(0);
            $table->decimal('payout_usd', 12, 4)->default(0);
            $table->decimal('profit_usd', 12, 4)->default(0);
            $table->boolean('is_locked')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['content_creator_id', 'month'], 'creator_monthly_statements_unique');
        });

        Schema::create('ad_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('company_name')->nullable();
            $table->string('vast_tag_url')->nullable();
            $table->string('click_through_url')->nullable();
            $table->string('placement', 32)->default('pre-roll');
            $table->string('status', 32)->default('draft');
            $table->decimal('bid_amount', 10, 4)->default(0);
            $table->unsignedInteger('skip_offset_seconds')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('ad_creatives', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ad_campaign_id')->constrained('ad_campaigns')->cascadeOnDelete();
            $table->string('name');
            $table->string('media_url');
            $table->string('mime_type', 64)->default('video/mp4');
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('ad_targeting_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ad_campaign_id')->constrained('ad_campaigns')->cascadeOnDelete();
            $table->string('country_code', 5)->nullable();
            $table->string('allowed_group', 32)->nullable();
            $table->foreignId('content_id')->nullable()->constrained('contents')->nullOnDelete();
            $table->boolean('is_include_rule')->default(true);
            $table->timestamps();

            $table->index(['country_code', 'allowed_group']);
        });

        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 128);
            $table->string('entity_type', 64);
            $table->string('entity_id', 64)->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['entity_type', 'entity_id']);
        });

        Schema::create('export_jobs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('format', 16);
            $table->string('scope', 32);
            $table->string('status', 32)->default('queued');
            $table->string('file_path')->nullable();
            $table->json('filters')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });

        Schema::create('webhook_deliveries', function (Blueprint $table): void {
            $table->id();
            $table->string('source', 64);
            $table->string('event_type', 64)->nullable();
            $table->string('status', 32)->default('received');
            $table->string('signature', 255)->nullable();
            $table->json('headers')->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['source', 'event_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhook_deliveries');
        Schema::dropIfExists('export_jobs');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('ad_targeting_rules');
        Schema::dropIfExists('ad_creatives');
        Schema::dropIfExists('ad_campaigns');
        Schema::dropIfExists('creator_monthly_statements');
        Schema::dropIfExists('video_monthly_costs');
        Schema::dropIfExists('cost_settings_versions');
        Schema::dropIfExists('watch_history');
        Schema::dropIfExists('watch_progress');
        Schema::dropIfExists('playback_sessions');
        Schema::dropIfExists('premiere_events');
        Schema::dropIfExists('content_playlist_items');
        Schema::dropIfExists('content_playlists');
        Schema::dropIfExists('content_creator_assignments');
        Schema::dropIfExists('content_creators');
        Schema::dropIfExists('subtitle_tracks');
        Schema::dropIfExists('content_rights_windows');
        Schema::dropIfExists('content_formats');
    }
};
