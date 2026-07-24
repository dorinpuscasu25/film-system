<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('payment_phone', 32)->nullable()->after('preferred_locale');
        });

        if (! Schema::hasTable('payment_top_ups')) {
            return;
        }

        $latestTopUps = DB::table('payment_top_ups')
            ->selectRaw('user_id, MAX(id) as latest_id')
            ->whereNotNull('raw_request')
            ->groupBy('user_id');

        DB::table('payment_top_ups as top_up')
            ->joinSub($latestTopUps, 'latest_top_up', function ($join): void {
                $join->on('top_up.id', '=', 'latest_top_up.latest_id');
            })
            ->select(['top_up.user_id', 'top_up.raw_request'])
            ->orderBy('top_up.id')
            ->chunk(500, function ($topUps): void {
                foreach ($topUps as $topUp) {
                    $payload = is_string($topUp->raw_request)
                        ? json_decode($topUp->raw_request, true)
                        : (array) $topUp->raw_request;
                    $phone = trim((string) ($payload['phone'] ?? ''));

                    if (preg_match('/^\+\d{7,15}$/', $phone) !== 1) {
                        continue;
                    }

                    DB::table('users')
                        ->where('id', $topUp->user_id)
                        ->whereNull('payment_phone')
                        ->update(['payment_phone' => $phone]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('payment_phone');
        });
    }
};
