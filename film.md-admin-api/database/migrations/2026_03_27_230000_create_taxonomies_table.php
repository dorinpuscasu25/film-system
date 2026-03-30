<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('taxonomies', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 32);
            $table->string('slug');
            $table->json('name');
            $table->json('description')->nullable();
            $table->boolean('active')->default(true);
            $table->string('color', 16)->nullable();
            $table->unsignedInteger('content_count')->default(0);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['type', 'slug']);
            $table->index(['type', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('taxonomies');
    }
};
