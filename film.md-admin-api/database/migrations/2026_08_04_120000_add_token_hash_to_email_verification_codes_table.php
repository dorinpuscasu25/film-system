<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_verification_codes', function (Blueprint $table): void {
            $table->string('token_hash', 64)->nullable()->unique()->after('code_hash');
        });
    }

    public function down(): void
    {
        Schema::table('email_verification_codes', function (Blueprint $table): void {
            $table->dropUnique(['token_hash']);
            $table->dropColumn('token_hash');
        });
    }
};
