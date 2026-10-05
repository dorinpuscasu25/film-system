<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_campaigns', function (Blueprint $table): void {
            // Which content maturity ratings the campaign may run against.
            $table->json('target_age_ratings')->nullable()->after('target_excluded_content_ids');
            // Minimum profile maturity required to be shown this ad. Lets an
            // advertiser restrict an 18+ creative to adult profiles only.
            $table->string('target_min_profile_rating', 16)->nullable()->after('target_age_ratings');
            // Never serve on kids profiles unless explicitly allowed.
            $table->boolean('exclude_kids_profiles')->default(true)->after('target_min_profile_rating');
            // web / ios / tvos / android — empty means every platform.
            $table->json('target_platforms')->nullable()->after('exclude_kids_profiles');
        });

        Schema::table('ad_events', function (Blueprint $table): void {
            $table->string('platform', 16)->nullable()->after('country_code');
            $table->index(['ad_campaign_id', 'platform']);
        });

        Schema::table('ad_event_aggregates', function (Blueprint $table): void {
            $table->string('platform', 16)->nullable()->after('country_code');
        });

        // The aggregate key must include platform, otherwise web and mobile rows
        // for the same day collapse into one bucket.
        Schema::table('ad_event_aggregates', function (Blueprint $table): void {
            $table->dropUnique('ad_event_agg_unique');
            $table->unique(
                ['ad_campaign_id', 'content_id', 'date', 'event_type', 'country_code', 'platform'],
                'ad_event_agg_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('ad_event_aggregates', function (Blueprint $table): void {
            $table->dropUnique('ad_event_agg_unique');
            $table->unique(
                ['ad_campaign_id', 'content_id', 'date', 'event_type', 'country_code'],
                'ad_event_agg_unique'
            );
            $table->dropColumn('platform');
        });

        Schema::table('ad_events', function (Blueprint $table): void {
            $table->dropIndex(['ad_campaign_id', 'platform']);
            $table->dropColumn('platform');
        });

        Schema::table('ad_campaigns', function (Blueprint $table): void {
            $table->dropColumn([
                'target_age_ratings',
                'target_min_profile_rating',
                'exclude_kids_profiles',
                'target_platforms',
            ]);
        });
    }
};
