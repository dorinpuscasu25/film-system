<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_content_accesses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('content_id')->constrained('contents')->cascadeOnDelete();
            $table->boolean('can_view')->default(true);
            $table->boolean('can_view_stats')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'content_id'], 'user_content_accesses_unique');
            $table->index(['content_id', 'can_view']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_content_accesses');
    }
};
