<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_top_ups', function (Blueprint $table): void {
            $table->string('provider_checkout_id')->nullable()->unique()->after('provider_order_id');
            $table->string('provider_rrn')->nullable()->after('provider_checkout_id');
            $table->index('provider_rrn');
        });
    }

    public function down(): void
    {
        Schema::table('payment_top_ups', function (Blueprint $table): void {
            $table->dropIndex(['provider_rrn']);
            $table->dropUnique(['provider_checkout_id']);
            $table->dropColumn(['provider_checkout_id', 'provider_rrn']);
        });
    }
};
