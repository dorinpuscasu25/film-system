<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('apple_iap_transactions', function (Blueprint $table): void {
            $table->id();
            // Apple's own identifiers — transaction_id is unique per purchase and is the
            // idempotency key: Apple may deliver the same transaction/notification more than once.
            $table->string('transaction_id', 64)->unique();
            $table->string('original_transaction_id', 64)->nullable();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_id', 191);
            $table->string('app_account_token', 64)->nullable();
            $table->decimal('credits_granted', 14, 2)->default(0);
            $table->unsignedBigInteger('apple_price')->nullable();
            $table->string('apple_currency', 3)->nullable();
            // Production / Sandbox / Xcode / LocalTesting — see AppleIapService for the fraud gate
            // on anything other than Production.
            $table->string('environment', 24);
            $table->string('status', 24)->default('pending');
            $table->text('signed_transaction')->nullable();
            $table->json('decoded_payload')->nullable();
            $table->timestamp('credited_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->text('refund_reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('original_transaction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('apple_iap_transactions');
    }
};
