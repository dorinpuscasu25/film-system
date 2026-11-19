<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Top-ups no longer all go through pay.filmoteca.md: Victoriabank MIA (instant QR)
        // and, later, CyberSource cards each settle the same payment_top_ups row.
        Schema::table('payment_top_ups', function (Blueprint $table): void {
            $table->string('provider', 32)->default('pay_filmoteca')->after('wallet_id');
            $table->string('payment_method', 16)->default('card')->after('provider');
            // Our own reference sent to the provider (MIA creditorRef). Unique per attempt.
            $table->string('provider_reference', 64)->nullable()->unique()->after('payment_method');
            $table->string('qr_header_uuid', 64)->nullable()->after('provider_payment_url');
            $table->string('qr_extension_uuid', 64)->nullable()->unique()->after('qr_header_uuid');
            $table->text('qr_as_text')->nullable()->after('qr_extension_uuid');
            $table->timestamp('qr_expires_at')->nullable()->after('qr_as_text');
            $table->timestamp('last_checked_at')->nullable()->after('qr_expires_at');
            $table->index(['provider', 'status', 'created_at']);
        });

        Schema::table('payment_refunds', function (Blueprint $table): void {
            $table->string('provider', 32)->default('pay_filmoteca')->after('wallet_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::table('payment_refunds', function (Blueprint $table): void {
            $table->dropColumn('provider');
        });

        Schema::table('payment_top_ups', function (Blueprint $table): void {
            $table->dropIndex(['provider', 'status', 'created_at']);
            $table->dropUnique(['provider_reference']);
            $table->dropUnique(['qr_extension_uuid']);
            $table->dropColumn([
                'provider',
                'payment_method',
                'provider_reference',
                'qr_header_uuid',
                'qr_extension_uuid',
                'qr_as_text',
                'qr_expires_at',
                'last_checked_at',
            ]);
        });
    }
};
