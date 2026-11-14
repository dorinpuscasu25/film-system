<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Where the buyer uses the service (art. 111 Cod fiscal: place of supply of digital
        // services), asked when money is added on the web. Copied onto the wallet credit.
        Schema::table('payment_top_ups', function (Blueprint $table): void {
            $table->string('access_location', 32)->nullable()->after('currency');
            $table->index(['access_location', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('payment_top_ups', function (Blueprint $table): void {
            $table->dropIndex(['access_location', 'created_at']);
            $table->dropColumn('access_location');
        });
    }
};
