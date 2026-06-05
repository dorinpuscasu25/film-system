<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_auth_codes', function (Blueprint $table): void {
            $table->id();
            // Short human-friendly code shown on the TV screen (e.g. "KXTP-9F2L").
            $table->string('user_code', 16)->unique();
            // SHA-256 of the secret device_code that the TV holds and polls with.
            $table->string('device_code_hash', 64)->unique();
            // pending → approved (user linked it) → claimed (TV exchanged it for a token)
            //         → denied / expired
            $table->string('status', 16)->default('pending');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('device_name')->nullable();
            $table->string('device_ip', 45)->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_auth_codes');
    }
};
