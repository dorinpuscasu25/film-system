<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table): void {
            $table->string('access_location', 32)->nullable()->after('currency');
            $table->index(['type', 'access_location']);
        });

        Schema::table('content_entitlements', function (Blueprint $table): void {
            $table->string('access_location', 32)->nullable()->after('currency');
            $table->index(['access_location', 'granted_at']);
        });
    }

    public function down(): void
    {
        Schema::table('wallet_transactions', function (Blueprint $table): void {
            $table->dropIndex(['type', 'access_location']);
            $table->dropColumn('access_location');
        });

        Schema::table('content_entitlements', function (Blueprint $table): void {
            $table->dropIndex(['access_location', 'granted_at']);
            $table->dropColumn('access_location');
        });
    }
};
