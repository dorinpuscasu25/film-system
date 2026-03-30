<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('home_page_sections', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('section_type');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->json('title')->nullable();
            $table->json('subtitle')->nullable();
            $table->string('source_mode')->nullable();
            $table->unsignedInteger('limit')->nullable();
            $table->json('content_ids')->nullable();
            $table->json('rule_filters')->nullable();
            $table->json('hero_slides')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('home_page_sections');
    }
};
