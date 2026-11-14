<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Funding ledger for rights reporting (contract cap. 6): every credit that enters a wallet is a
 * "lot" (one MAIB top-up, one Apple pack, one bonus) carrying what it is worth in real money;
 * every debit records which lots it consumed. A film sale can then say exactly how much of its
 * price was paid by card (MAIB), by an Apple pack (net of Apple's commission, settled later) or
 * by promotional credit (no revenue), and stage 2 can tie each settlement back to the sales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_funding_lots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wallet_id')->constrained('wallets')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('source', 16);                       // own (MAIB card) | apple | platform (promo)
            $table->foreignId('credit_transaction_id')->nullable()->unique()->constrained('wallet_transactions')->nullOnDelete();
            $table->string('source_type')->nullable();          // e.g. PaymentTopUp / AppleIapTransaction
            $table->unsignedBigInteger('source_id')->nullable();
            $table->decimal('amount', 14, 2);                   // credits (MDL) granted
            $table->decimal('remaining_amount', 14, 2);
            $table->string('currency', 3)->default('MDL');
            $table->decimal('paid_amount', 14, 4)->nullable();  // what the customer paid, in paid_currency
            $table->string('paid_currency', 3)->nullable();
            // Real-money value of the whole lot in MDL (contract 6.5): gross paid, the store's
            // commission, the conversion cost, and what remains for the distribution base.
            $table->decimal('gross_value', 14, 4)->default(0);
            $table->decimal('commission_value', 14, 4)->default(0);
            $table->decimal('fx_cost_value', 14, 4)->default(0);
            $table->decimal('net_value', 14, 4)->default(0);
            $table->string('valuation_status', 16)->default('final'); // final | estimated
            $table->json('valuation_meta')->nullable();
            $table->timestamp('funded_at')->nullable();
            $table->timestamps();

            $table->index(['wallet_id', 'source', 'id'], 'wallet_funding_lots_fifo_idx');
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('wallet_funding_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('debit_transaction_id')->constrained('wallet_transactions')->cascadeOnDelete();
            $table->foreignId('lot_id')->constrained('wallet_funding_lots')->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->timestamps();

            $table->index('debit_transaction_id');
            $table->index('lot_id');
        });

        Schema::create('reporting_sale_fundings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reporting_sale_id')->constrained('reporting_sales')->cascadeOnDelete();
            $table->foreignId('lot_id')->nullable()->constrained('wallet_funding_lots')->nullOnDelete();
            $table->string('source', 16);
            $table->decimal('credits', 14, 2);
            $table->decimal('gross_value', 14, 4)->default(0);
            $table->decimal('commission_value', 14, 4)->default(0);
            $table->decimal('fx_cost_value', 14, 4)->default(0);
            $table->decimal('net_value', 14, 4)->default(0);
            $table->string('valuation_status', 16)->default('final');
            $table->timestamps();

            $table->index(['reporting_sale_id', 'source']);
        });

        Schema::table('reporting_sales', function (Blueprint $table): void {
            $table->decimal('promo_credits', 14, 2)->default(0)->after('gross_amount');
            $table->decimal('own_credits', 14, 2)->default(0)->after('promo_credits');
            $table->decimal('apple_credits', 14, 2)->default(0)->after('own_credits');
            $table->decimal('revenue_gross_amount', 14, 4)->default(0)->after('apple_credits');   // Venit Brut
            $table->decimal('channel_commission_amount', 14, 4)->default(0)->after('revenue_gross_amount');
            $table->decimal('fx_cost_amount', 14, 4)->default(0)->after('channel_commission_amount');
            $table->decimal('deductions_amount', 14, 4)->default(0)->after('fx_cost_amount');     // Deduceri Comerciale Admise
            $table->decimal('distribution_base_amount', 14, 4)->default(0)->after('deductions_amount'); // Bază de Repartizare
            $table->string('valuation_status', 16)->default('final')->after('distribution_base_amount');
            $table->index(['sales_channel', 'purchased_at']);
        });

        Schema::table('reporting_sale_allocations', function (Blueprint $table): void {
            // 6.29: available now (card money in hand) vs waiting for an intermediary's settlement.
            $table->decimal('available_amount', 14, 4)->default(0)->after('net_payable_amount');
            $table->decimal('pending_settlement_amount', 14, 4)->default(0)->after('available_amount');
        });
    }

    public function down(): void
    {
        Schema::table('reporting_sale_allocations', function (Blueprint $table): void {
            $table->dropColumn(['available_amount', 'pending_settlement_amount']);
        });
        Schema::table('reporting_sales', function (Blueprint $table): void {
            $table->dropIndex(['sales_channel', 'purchased_at']);
            $table->dropColumn([
                'promo_credits', 'own_credits', 'apple_credits', 'revenue_gross_amount', 'channel_commission_amount',
                'fx_cost_amount', 'deductions_amount', 'distribution_base_amount', 'valuation_status',
            ]);
        });
        Schema::dropIfExists('reporting_sale_fundings');
        Schema::dropIfExists('wallet_funding_allocations');
        Schema::dropIfExists('wallet_funding_lots');
    }
};
