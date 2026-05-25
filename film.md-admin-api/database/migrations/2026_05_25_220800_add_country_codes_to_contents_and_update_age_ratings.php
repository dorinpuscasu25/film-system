<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contents', function (Blueprint $table): void {
            $table->json('country_codes')->nullable()->after('country_code');
        });

        DB::table('contents')
            ->whereNotNull('country_code')
            ->select(['id', 'country_code'])
            ->chunkById(100, function ($contents): void {
                foreach ($contents as $content) {
                    DB::table('contents')
                        ->where('id', $content->id)
                        ->update([
                            'country_codes' => json_encode([$content->country_code]),
                        ]);
                }
            });

        DB::table('contents')->where('age_rating', '0+')->update(['age_rating' => 'AG']);
        DB::table('contents')->where('age_rating', '6+')->update(['age_rating' => 'AG']);
        DB::table('contents')->where('age_rating', '12+')->update(['age_rating' => 'A.P.-12']);
        DB::table('contents')->where('age_rating', '16+')->update(['age_rating' => 'N-15']);
        DB::table('contents')->where('age_rating', '18+')->update(['age_rating' => 'I.M.-18']);
    }

    public function down(): void
    {
        DB::table('contents')->where('age_rating', 'AG')->update(['age_rating' => '0+']);
        DB::table('contents')->where('age_rating', 'A.P.-12')->update(['age_rating' => '12+']);
        DB::table('contents')->where('age_rating', 'N-15')->update(['age_rating' => '16+']);
        DB::table('contents')->where('age_rating', 'I.M.-18')->update(['age_rating' => '18+']);
        DB::table('contents')->where('age_rating', 'I.M.-18-XXX')->update(['age_rating' => '18+']);
        DB::table('contents')->where('age_rating', 'I.C.')->update(['age_rating' => null]);

        Schema::table('contents', function (Blueprint $table): void {
            $table->dropColumn('country_codes');
        });
    }
};
