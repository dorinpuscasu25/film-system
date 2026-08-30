<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('deleted_at')->nullable()->after('last_seen_at');
            $table->string('deletion_reason', 500)->nullable()->after('deleted_at');

            $table->index('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['deleted_at']);
            $table->dropColumn(['deleted_at', 'deletion_reason']);
        });
    }
};
