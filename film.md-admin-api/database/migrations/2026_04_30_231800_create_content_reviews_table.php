<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_reviews', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_id')->constrained('contents')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('account_profile_id')->nullable()->constrained('account_profiles')->nullOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment');
            $table->string('locale', 8)->nullable();
            $table->string('status', 24)->default('published');
            $table->timestamps();

            $table->unique(['content_id', 'user_id']);
            $table->index(['content_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_reviews');
    }
};
