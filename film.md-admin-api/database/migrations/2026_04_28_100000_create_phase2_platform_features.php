<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 platform features: parental control, coupons, watch parties,
 * enhanced ad targeting + per-event stats, creator-user linking, GA4 settings.
 */
return new class extends Migration
{
    public function up(): void
    {
        // --- Parental control on profiles ---
        Schema::table('account_profiles', function (Blueprint $table): void {
            $table->string('pin_hash', 255)->nullable()->after('is_kids');
            $table->string('max_age_rating', 8)->nullable()->after('pin_hash');
            $table->string('preferred_locale', 8)->default('ro')->after('max_age_rating');
        });

        // --- Promotional coupons ---
        Schema::create('coupons', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            // percent | fixed | free_access
            $table->string('discount_type', 16);
            $table->decimal('discount_value', 12, 4)->default(0);
            $table->string('currency', 8)->default('MDL');
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('redemptions_count')->default(0);
            $table->unsignedInteger('per_user_limit')->default(1);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->json('applicable_content_ids')->nullable();
            $table->json('applicable_offer_ids')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'starts_at', 'ends_at']);
        });

        Schema::create('coupon_redemptions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('offer_id')->nullable()->constrained('offers')->nullOnDelete();
            $table->foreignId('content_id')->nullable()->constrained('contents')->nullOnDelete();
            $table->decimal('discount_applied', 12, 4)->default(0);
            $table->string('currency', 8)->default('MDL');
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['coupon_id', 'user_id']);
        });

        // --- Watch parties (synchronized premieres) ---
        Schema::create('watch_parties', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_id')->constrained('contents')->cascadeOnDelete();
            $table->foreignId('host_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->string('room_code', 16)->unique();
            $table->timestamp('scheduled_start_at');
            $table->timestamp('actual_start_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('status', 32)->default('scheduled');
            $table->boolean('is_public')->default(true);
            $table->boolean('chat_enabled')->default(true);
            $table->unsignedInteger('max_participants')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['content_id', 'scheduled_start_at']);
            $table->index(['status', 'scheduled_start_at']);
        });

        Schema::create('watch_party_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('watch_party_id')->constrained('watch_parties')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('account_profile_id')->nullable()->constrained('account_profiles')->nullOnDelete();
            $table->string('display_name', 80);
            $table->timestamp('joined_at');
            $table->timestamp('left_at')->nullable();
            $table->boolean('is_host')->default(false);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['watch_party_id', 'user_id'], 'wpp_unique_user');
            $table->index('watch_party_id');
        });

        Schema::create('watch_party_chat_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('watch_party_id')->constrained('watch_parties')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('display_name', 80);
            $table->text('body');
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->index(['watch_party_id', 'sent_at']);
        });

        // --- Ad campaign enhancements ---
        Schema::table('ad_campaigns', function (Blueprint $table): void {
            $table->unsignedInteger('frequency_cap_per_session')->nullable()->after('skip_offset_seconds');
            $table->unsignedInteger('frequency_cap_per_day')->nullable()->after('frequency_cap_per_session');
            $table->json('target_countries')->nullable()->after('frequency_cap_per_day');
            $table->json('target_groups')->nullable()->after('target_countries');
            $table->json('target_content_ids')->nullable()->after('target_groups');
            $table->json('target_excluded_content_ids')->nullable()->after('target_content_ids');
            $table->unsignedInteger('mid_roll_offset_seconds')->nullable()->after('target_excluded_content_ids');
            $table->string('bunny_webhook_secret', 128)->nullable()->after('mid_roll_offset_seconds');
            $table->unsignedBigInteger('impressions_count')->default(0)->after('bunny_webhook_secret');
            $table->unsignedBigInteger('completes_count')->default(0)->after('impressions_count');
            $table->unsignedBigInteger('clicks_count')->default(0)->after('completes_count');
            $table->unsignedBigInteger('skips_count')->default(0)->after('clicks_count');
            $table->decimal('total_spend_usd', 12, 4)->default(0)->after('skips_count');
        });

        // Per-campaign per-event aggregates (daily, with country breakdown)
        Schema::create('ad_event_aggregates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ad_campaign_id')->constrained('ad_campaigns')->cascadeOnDelete();
            $table->foreignId('content_id')->nullable()->constrained('contents')->nullOnDelete();
            $table->date('date');
            $table->string('event_type', 32);
            $table->string('country_code', 5)->nullable();
            $table->unsignedBigInteger('count')->default(0);
            $table->timestamps();

            $table->unique(['ad_campaign_id', 'content_id', 'date', 'event_type', 'country_code'], 'ad_event_agg_unique');
            $table->index(['ad_campaign_id', 'date']);
        });

        // Raw per-event log (for last-N-days drilldown). Auto-pruned.
        Schema::create('ad_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ad_campaign_id')->constrained('ad_campaigns')->cascadeOnDelete();
            $table->foreignId('content_id')->nullable()->constrained('contents')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('playback_session_id', 80)->nullable();
            $table->string('event_type', 32);
            $table->string('country_code', 5)->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['ad_campaign_id', 'occurred_at']);
            $table->index(['ad_campaign_id', 'event_type']);
            $table->index('playback_session_id');
        });

        // --- Creator-user link (for creator portal access) ---
        Schema::table('content_creators', function (Blueprint $table): void {
            $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->nullOnDelete();
        });

        // --- GA / marketing settings ---
        Schema::create('platform_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 64)->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        // --- Marketing newsletter (lightweight) ---
        Schema::create('newsletter_subscribers', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->unique();
            $table->string('locale', 8)->default('ro');
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('unsubscribed_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_subscribers');
        Schema::dropIfExists('platform_settings');

        Schema::table('content_creators', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });

        Schema::dropIfExists('ad_events');
        Schema::dropIfExists('ad_event_aggregates');

        Schema::table('ad_campaigns', function (Blueprint $table): void {
            $table->dropColumn([
                'frequency_cap_per_session',
                'frequency_cap_per_day',
                'target_countries',
                'target_groups',
                'target_content_ids',
                'target_excluded_content_ids',
                'mid_roll_offset_seconds',
                'bunny_webhook_secret',
                'impressions_count',
                'completes_count',
                'clicks_count',
                'skips_count',
                'total_spend_usd',
            ]);
        });

        Schema::dropIfExists('watch_party_chat_messages');
        Schema::dropIfExists('watch_party_participants');
        Schema::dropIfExists('watch_parties');

        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('coupons');

        Schema::table('account_profiles', function (Blueprint $table): void {
            $table->dropColumn(['pin_hash', 'max_age_rating', 'preferred_locale']);
        });
    }
};
