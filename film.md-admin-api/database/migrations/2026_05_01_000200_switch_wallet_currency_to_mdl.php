<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('wallets')
            ->where('currency', 'USD')
            ->update(['currency' => 'MDL']);

        DB::table('contents')
            ->where('currency', 'USD')
            ->update(['currency' => 'MDL']);

        DB::table('offers')
            ->where('currency', 'USD')
            ->update(['currency' => 'MDL']);

        DB::table('content_entitlements')
            ->where('currency', 'USD')
            ->update(['currency' => 'MDL']);

        DB::statement("ALTER TABLE contents ALTER COLUMN currency SET DEFAULT 'MDL'");
        DB::statement("ALTER TABLE offers ALTER COLUMN currency SET DEFAULT 'MDL'");
        DB::statement("ALTER TABLE wallets ALTER COLUMN currency SET DEFAULT 'MDL'");
        DB::statement("ALTER TABLE wallet_transactions ALTER COLUMN currency SET DEFAULT 'MDL'");
        DB::statement("ALTER TABLE content_entitlements ALTER COLUMN currency SET DEFAULT 'MDL'");
        DB::statement("ALTER TABLE payment_top_ups ALTER COLUMN currency SET DEFAULT 'MDL'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE contents ALTER COLUMN currency SET DEFAULT 'USD'");
        DB::statement("ALTER TABLE offers ALTER COLUMN currency SET DEFAULT 'USD'");
        DB::statement("ALTER TABLE wallets ALTER COLUMN currency SET DEFAULT 'USD'");
        DB::statement("ALTER TABLE wallet_transactions ALTER COLUMN currency SET DEFAULT 'USD'");
        DB::statement("ALTER TABLE content_entitlements ALTER COLUMN currency SET DEFAULT 'USD'");
        DB::statement("ALTER TABLE payment_top_ups ALTER COLUMN currency SET DEFAULT 'USD'");
    }
};
