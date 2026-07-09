<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_addresses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('full_name');
            $table->string('country_code', 2);
            $table->string('administrative_area')->nullable();
            $table->string('city');
            $table->string('postal_code', 32);
            $table->string('address_line1');
            $table->string('address_line2')->nullable();
            $table->boolean('is_default')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'is_default']);
            $table->index(['country_code', 'postal_code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_addresses');
    }
};
