<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_profile_favorite_content', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('account_profile_id')->constrained('account_profiles')->cascadeOnDelete();
            $table->foreignId('content_id')->constrained('contents')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['account_profile_id', 'content_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_profile_favorite_content');
    }
};
