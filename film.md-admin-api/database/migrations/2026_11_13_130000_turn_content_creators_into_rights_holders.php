<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Content creators become "titulari" (rights holders): every title has exactly one, and the
 * holder carries its legal and payment details. Those details are the single source for the
 * reporting fiscal profiles (see RightsHolderService::syncFiscalProfile).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('content_creators', function (Blueprint $table): void {
            $table->string('person_type', 2)->default('PJ')->after('company_name');   // PF / PJ
            $table->string('idno', 32)->nullable()->after('person_type');             // IDNO (PJ) or IDNP (PF)
            $table->string('legal_address', 500)->nullable()->after('idno');
            $table->string('iban', 64)->nullable()->after('legal_address');
            $table->string('bank_name')->nullable()->after('iban');
            $table->string('bank_swift', 16)->nullable()->after('bank_name');
            $table->boolean('is_vat_payer')->default(false)->after('bank_swift');
            $table->string('vat_code', 32)->nullable()->after('is_vat_payer');
            $table->string('contact_person')->nullable()->after('vat_code');
            $table->string('phone', 64)->nullable()->after('contact_person');
        });

        $this->backfillFromFiscalProfiles();
        $this->keepOneHolderPerTitle();

        Schema::table('content_creator_assignments', function (Blueprint $table): void {
            $table->unique('content_id', 'content_creator_assignments_one_holder');
        });
    }

    public function down(): void
    {
        Schema::table('content_creator_assignments', function (Blueprint $table): void {
            $table->dropUnique('content_creator_assignments_one_holder');
        });

        Schema::table('content_creators', function (Blueprint $table): void {
            $table->dropColumn([
                'person_type', 'idno', 'legal_address', 'iban', 'bank_name', 'bank_swift',
                'is_vat_payer', 'vat_code', 'contact_person', 'phone',
            ]);
        });
    }

    /** Holders that already have a fiscal profile start with its current values. */
    private function backfillFromFiscalProfiles(): void
    {
        $today = now()->toDateString();

        foreach (DB::table('content_creators')->pluck('id') as $creatorId) {
            $profile = DB::table('creator_fiscal_profiles')
                ->where('content_creator_id', $creatorId)
                ->where('status', 'active')
                ->whereDate('effective_from', '<=', $today)
                ->where(fn ($query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $today))
                ->orderByDesc('effective_from')
                ->first();

            if ($profile === null) {
                continue;
            }

            DB::table('content_creators')->where('id', $creatorId)->update([
                'person_type' => in_array($profile->person_type, ['PF', 'PJ'], true) ? $profile->person_type : 'PJ',
                'idno' => $profile->tax_identifier,
                'iban' => $profile->iban,
                'is_vat_payer' => (bool) $profile->is_vat_registered,
            ]);
        }
    }

    /**
     * A title linked to several creators keeps one: the primary, else the owner, else the
     * oldest link. Dropped links are logged so they can be restored by hand.
     */
    private function keepOneHolderPerTitle(): void
    {
        $duplicated = DB::table('content_creator_assignments')
            ->select('content_id')
            ->groupBy('content_id')
            ->havingRaw('COUNT(*) > 1')
            ->pluck('content_id');

        foreach ($duplicated as $contentId) {
            $rows = DB::table('content_creator_assignments')
                ->where('content_id', $contentId)
                ->orderByDesc('is_primary')
                ->orderByRaw("CASE WHEN role = 'owner' THEN 0 ELSE 1 END")
                ->orderBy('id')
                ->get();

            $dropped = $rows->slice(1);
            DB::table('content_creator_assignments')->whereIn('id', $dropped->pluck('id'))->delete();
            Log::warning('Rights holders: title had several creators, kept one', [
                'content_id' => $contentId,
                'kept_content_creator_id' => $rows->first()->content_creator_id,
                'dropped_content_creator_ids' => $dropped->pluck('content_creator_id')->values()->all(),
            ]);
        }

        DB::table('content_creator_assignments')->update(['role' => 'owner', 'is_primary' => true]);
    }
};
