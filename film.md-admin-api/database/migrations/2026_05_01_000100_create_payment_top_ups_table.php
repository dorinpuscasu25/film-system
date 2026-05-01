<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_top_ups', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('wallet_id')->constrained()->cascadeOnDelete();
            $table->string('subscriber_id');
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('MDL');
            $table->string('status', 32)->default('pending');
            $table->string('provider_order_id')->nullable()->unique();
            $table->text('provider_payment_url')->nullable();
            $table->string('provider_status', 64)->nullable();
            $table->string('description')->nullable();
            $table->json('raw_request')->nullable();
            $table->json('raw_response')->nullable();
            $table->json('raw_callback')->nullable();
            $table->json('raw_details')->nullable();
            $table->timestamp('credited_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['wallet_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_top_ups');
    }
};
