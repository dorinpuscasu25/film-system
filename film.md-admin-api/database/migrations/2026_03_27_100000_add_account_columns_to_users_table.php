<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('preferred_locale', 10)->default('ro')->after('password');
            $table->string('status', 20)->default('active')->after('preferred_locale');
            $table->string('avatar_url')->nullable()->after('status');
            $table->timestamp('last_seen_at')->nullable()->after('remember_token');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['preferred_locale', 'status', 'avatar_url', 'last_seen_at']);
        });
    }
};
