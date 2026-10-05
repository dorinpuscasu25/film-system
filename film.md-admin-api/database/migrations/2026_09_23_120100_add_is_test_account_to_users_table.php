<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // Gates Apple Sandbox IAP redemptions: a Sandbox-signed transaction only credits
            // the wallet for accounts explicitly flagged for testing — otherwise anyone with a
            // sandbox Apple ID could mint unlimited free credits. See AppleIapService.
            $table->boolean('is_test_account')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_test_account');
        });
    }
};
