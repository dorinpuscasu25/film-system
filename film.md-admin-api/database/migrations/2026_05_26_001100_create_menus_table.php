<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menus', function (Blueprint $table): void {
            $table->id();
            $table->json('name');
            $table->string('slug')->unique();
            $table->string('location', 64);
            $table->json('description')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['location', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menus');
    }
};
