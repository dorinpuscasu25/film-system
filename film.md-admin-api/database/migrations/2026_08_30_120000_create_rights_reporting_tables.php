<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reporting_settings_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('domestic_country_code', 2)->default('MD');
            $table->decimal('domestic_vat_rate', 6, 3)->default(20);
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['is_active', 'effective_from', 'effective_until'], 'reporting_settings_effective_idx');
        });

        Schema::create('creator_contract_versions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_creator_id')->constrained('content_creators')->cascadeOnDelete();
            $table->foreignId('content_id')->constrained('contents')->cascadeOnDelete();
            $table->decimal('share_percent', 7, 4);
            $table->json('territories')->nullable();
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->string('contract_reference')->nullable();
            $table->string('status', 24)->default('active');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['content_id', 'effective_from', 'effective_until'], 'creator_contract_content_effective_idx');
            $table->index(['content_creator_id', 'effective_from', 'effective_until'], 'creator_contract_holder_effective_idx');
        });

        Schema::create('creator_fiscal_profiles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('content_creator_id')->constrained('content_creators')->cascadeOnDelete();
            $table->string('person_type', 8);
            $table->string('tax_residency', 2)->default('MD');
            $table->boolean('is_vat_registered')->default(false);
            $table->decimal('vat_rate', 6, 3)->default(0);
            $table->boolean('withholding_enabled')->default(false);
            $table->decimal('withholding_rate', 6, 3)->default(0);
            $table->string('tax_identifier', 32)->nullable();
            $table->string('iban', 64)->nullable();
            $table->string('payment_currency', 3)->default('MDL');
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->string('status', 24)->default('active');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['content_creator_id', 'effective_from', 'effective_until'], 'creator_fiscal_effective_idx');
        });

        Schema::create('reporting_sales', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('content_entitlement_id')->nullable()->unique()->constrained('content_entitlements')->nullOnDelete();
            $table->foreignId('wallet_transaction_id')->nullable()->constrained('wallet_transactions')->nullOnDelete();
            $table->foreignId('buyer_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('content_id')->nullable()->constrained('contents')->nullOnDelete();
            $table->foreignId('offer_id')->nullable()->constrained('offers')->nullOnDelete();
            $table->foreignId('settings_version_id')->nullable()->constrained('reporting_settings_versions')->nullOnDelete();
            $table->timestamp('purchased_at');
            $table->string('status', 24)->default('completed');
            $table->string('calculation_status', 32)->default('calculated');
            $table->string('calculation_version', 16)->default('v1');
            $table->string('country_code', 2)->nullable();
            $table->string('market', 12)->default('export');
            $table->string('sales_channel', 24)->default('web');
            $table->string('payment_method', 32)->default('wallet');
            $table->string('payment_processor', 64)->nullable();
            $table->string('currency', 3)->default('MDL');
            $table->string('content_title');
            $table->string('offer_name')->nullable();
            $table->string('quality', 32)->nullable();
            $table->unsignedInteger('rental_days')->nullable();
            $table->decimal('gross_amount', 14, 4)->default(0);
            $table->decimal('vat_rate', 6, 3)->default(0);
            $table->decimal('vat_amount', 14, 4)->default(0);
            $table->decimal('net_ex_vat_amount', 14, 4)->default(0);
            $table->decimal('holders_gross_amount', 14, 4)->default(0);
            $table->decimal('holders_vat_amount', 14, 4)->default(0);
            $table->decimal('withholding_amount', 14, 4)->default(0);
            $table->decimal('holders_net_amount', 14, 4)->default(0);
            $table->decimal('platform_share_amount', 14, 4)->default(0);
            $table->decimal('platform_vat_amount', 14, 4)->default(0);
            $table->decimal('refund_amount', 14, 4)->default(0);
            $table->timestamp('refunded_at')->nullable();
            $table->json('source_snapshot')->nullable();
            $table->json('calculation_snapshot')->nullable();
            $table->timestamps();

            $table->index(['purchased_at', 'status']);
            $table->index(['content_id', 'purchased_at']);
            $table->index(['country_code', 'purchased_at']);
        });

        Schema::create('reporting_sale_allocations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reporting_sale_id')->constrained('reporting_sales')->cascadeOnDelete();
            $table->foreignId('content_creator_id')->nullable()->constrained('content_creators')->nullOnDelete();
            $table->foreignId('contract_version_id')->nullable()->constrained('creator_contract_versions')->nullOnDelete();
            $table->foreignId('fiscal_profile_id')->nullable()->constrained('creator_fiscal_profiles')->nullOnDelete();
            $table->string('holder_name');
            $table->decimal('share_percent', 7, 4)->default(0);
            $table->decimal('base_share_amount', 14, 4)->default(0);
            $table->decimal('vat_amount', 14, 4)->default(0);
            $table->decimal('gross_share_amount', 14, 4)->default(0);
            $table->decimal('withholding_rate', 6, 3)->default(0);
            $table->decimal('withholding_amount', 14, 4)->default(0);
            $table->decimal('net_payable_amount', 14, 4)->default(0);
            $table->string('person_type', 8)->nullable();
            $table->boolean('is_vat_registered')->default(false);
            $table->json('contract_snapshot')->nullable();
            $table->json('fiscal_snapshot')->nullable();
            $table->timestamps();

            $table->index(['content_creator_id', 'reporting_sale_id'], 'reporting_allocation_holder_sale_idx');
        });

        DB::table('reporting_settings_versions')->insert([
            'domestic_country_code' => 'MD',
            'domestic_vat_rate' => 20,
            'effective_from' => '2000-01-01 00:00:00',
            'is_active' => true,
            'meta' => json_encode(['source' => 'initial_reporting_configuration']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $managePermissionId = DB::table('permissions')->insertGetId([
            'code' => 'reporting.manage_profiles',
            'name' => 'Manage rights reporting profiles',
            'group' => 'reporting',
            'description' => 'Manage contractual, fiscal and tax-rule versions used by immutable sales reporting.',
            'is_system' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $financialPermissionId = DB::table('permissions')->where('code', 'content.view_financials')->value('id');
        $adminRoleId = DB::table('roles')->where('name', 'Admin')->value('id');
        if ($adminRoleId !== null) {
            foreach (array_filter([$managePermissionId, $financialPermissionId]) as $permissionId) {
                DB::table('permission_role')->updateOrInsert(
                    ['permission_id' => $permissionId, 'role_id' => $adminRoleId],
                    ['created_at' => now(), 'updated_at' => now()],
                );
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('reporting_sale_allocations');
        Schema::dropIfExists('reporting_sales');
        Schema::dropIfExists('creator_fiscal_profiles');
        Schema::dropIfExists('creator_contract_versions');
        Schema::dropIfExists('reporting_settings_versions');
    }
};
