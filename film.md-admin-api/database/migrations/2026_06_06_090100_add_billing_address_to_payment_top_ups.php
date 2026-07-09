<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_top_ups', function (Blueprint $table): void {
            $table->foreignId('billing_address_id')
                ->nullable()
                ->after('wallet_id')
                ->constrained('billing_addresses')
                ->nullOnDelete();
            $table->json('billing_address')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('payment_top_ups', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('billing_address_id');
            $table->dropColumn('billing_address');
        });
    }
};
