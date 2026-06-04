<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_refunds', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('payment_top_up_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('requested_by_admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('provider_order_id')->nullable();
            $table->string('provider_checkout_id');
            $table->string('provider_rrn');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('MDL');
            $table->text('reason');
            $table->string('status', 32)->default('requested');
            $table->string('provider_status', 64)->nullable();
            $table->json('raw_request')->nullable();
            $table->json('raw_response')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->index(['payment_top_up_id', 'status']);
            $table->index(['user_id', 'status']);
            $table->index(['provider_checkout_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_refunds');
    }
};
