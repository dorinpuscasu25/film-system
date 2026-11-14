<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_plans', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('currency', 3)->default('MDL');
            // [{offer_type, quality, price_amount, rental_days}] — one row per (offer_type, quality).
            $table->json('items');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('offers', function (Blueprint $table): void {
            $table->foreignId('price_plan_id')
                ->nullable()
                ->after('content_id')
                ->constrained('price_plans')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('price_plan_id');
        });

        Schema::dropIfExists('price_plans');
    }
};
