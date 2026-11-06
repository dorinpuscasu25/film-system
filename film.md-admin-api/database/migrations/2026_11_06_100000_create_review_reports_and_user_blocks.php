<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * App Store guideline 1.2: user-generated content (reviews) must be reportable, and a viewer
 * must be able to block an abusive user so their content disappears for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('content_review_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_review_id')->constrained('content_reviews')->cascadeOnDelete();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->string('reason', 32);
            $table->string('details', 500)->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['content_review_id', 'reporter_id']);
        });

        Schema::create('user_blocks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('blocked_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'blocked_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_blocks');
        Schema::dropIfExists('content_review_reports');
    }
};
