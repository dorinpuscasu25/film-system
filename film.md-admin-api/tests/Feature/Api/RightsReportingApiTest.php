<?php

namespace Tests\Feature\Api;

use App\Models\Content;
use App\Models\ContentCreator;
use App\Models\ContentEntitlement;
use App\Models\CreatorContractVersion;
use App\Models\CreatorFiscalProfile;
use App\Models\Offer;
use App\Models\PersonalAccessToken;
use App\Models\Role;
use App\Models\User;
use App\Services\RightsReportingService;
use Database\Seeders\AccessControlSeeder;
use Database\Seeders\ContentSeeder;
use Database\Seeders\TaxonomySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RightsReportingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_pf_ntva_domestic_purchase_uses_versioned_waterfall_and_reconciles(): void
    {
        [$admin, $creator, $content, $offer] = $this->context();
        $this->contract($creator, $content, 50);
        $this->fiscal($creator, personType: 'PF', vatRegistered: false, withholdingRate: 12);

        $sale = app(RightsReportingService::class)->capturePurchase($this->entitlement($content, $offer, 120, 'moldova'));
        $allocation = $sale->allocations->firstOrFail();

        $this->assertSame(20.0, $sale->vat_amount);
        $this->assertSame(100.0, $sale->net_ex_vat_amount);
        $this->assertSame(50.0, $allocation->base_share_amount);
        $this->assertSame(0.0, $allocation->vat_amount);
        $this->assertSame(6.0, $allocation->withholding_amount);
        $this->assertSame(44.0, $allocation->net_payable_amount);
        $this->assertSame(50.0, $sale->platform_share_amount);
        $this->assertSame(20.0, $sale->platform_vat_amount);
        $this->assertEquals(120.0, $sale->calculation_snapshot['reconciled_amount']);
    }

    public function test_pj_tva_holder_receives_its_vat_component_without_withholding(): void
    {
        [, $creator, $content, $offer] = $this->context();
        $this->contract($creator, $content, 50);
        $this->fiscal($creator, personType: 'PJ', vatRegistered: true, withholdingRate: 0);

        $sale = app(RightsReportingService::class)->capturePurchase($this->entitlement($content, $offer, 120, 'moldova'));
        $allocation = $sale->allocations->firstOrFail();

        $this->assertSame(50.0, $allocation->base_share_amount);
        $this->assertSame(10.0, $allocation->vat_amount);
        $this->assertSame(60.0, $allocation->gross_share_amount);
        $this->assertSame(60.0, $allocation->net_payable_amount);
        $this->assertSame(10.0, $sale->platform_vat_amount);
    }

    public function test_profile_changes_do_not_recalculate_existing_sales_and_holder_api_hides_buyer(): void
    {
        [$admin, $creator, $content, $offer] = $this->context();
        $contract = $this->contract($creator, $content, 50);
        $this->fiscal($creator, personType: 'PF', vatRegistered: false, withholdingRate: 12);
        $entitlement = $this->entitlement($content, $offer, 120, 'outside_moldova');
        $sale = app(RightsReportingService::class)->capturePurchase($entitlement);

        $contract->forceFill(['share_percent' => 40])->save();
        $this->assertSame(60.0, $sale->fresh()->allocations()->firstOrFail()->base_share_amount);

        $producerRole = Role::query()->where('name', 'Producer')->firstOrFail();
        $producer = User::factory()->create(['email' => 'holder@example.com', 'status' => 'active']);
        $producer->roles()->sync([$producerRole->id]);
        $creator->forceFill(['user_id' => $producer->id])->save();
        $creator->contents()->sync([$content->id => ['role' => 'owner', 'is_primary' => true]]);
        [, $token] = PersonalAccessToken::issue($producer->fresh(), 'holder-reporting-test');

        $response = $this->getJson('/api/v1/admin/reporting', ['Authorization' => 'Bearer '.$token])
            ->assertOk()
            ->assertJsonPath('scope.is_holder', true)
            ->assertJsonPath('summary.holder_net_amount', 52.8)
            ->assertJsonMissingPath('transactions.0.buyer_user_id');

        $this->assertStringNotContainsString('buyer-private@example.com', $response->getContent());
    }

    public function test_new_profile_versions_close_previous_period_automatically(): void
    {
        [$admin, $creator, $content] = $this->context();
        $contract = $this->contract($creator, $content, 50);
        $fiscal = $this->fiscal($creator, personType: 'PF', vatRegistered: false, withholdingRate: 12);
        [, $token] = PersonalAccessToken::issue($admin, 'reporting-version-test');
        $headers = ['Authorization' => 'Bearer '.$token];

        $this->postJson('/api/v1/admin/reporting/contracts', [
            'content_creator_id' => $creator->id, 'content_id' => $content->id, 'share_percent' => 40,
            'effective_from' => '2026-01-01',
        ], $headers)->assertCreated()->assertJsonPath('contract.share_percent', 40);
        $this->postJson('/api/v1/admin/reporting/fiscal-profiles', [
            'content_creator_id' => $creator->id, 'person_type' => 'PJ', 'tax_residency' => 'MD',
            'is_vat_registered' => true, 'vat_rate' => 20, 'withholding_enabled' => false,
            'withholding_rate' => 0, 'payment_currency' => 'MDL', 'effective_from' => '2026-01-01',
        ], $headers)->assertCreated()->assertJsonPath('profile.person_type', 'PJ');

        $this->assertSame('2025-12-31', $contract->fresh()->effective_until?->format('Y-m-d'));
        $this->assertSame('2025-12-31', $fiscal->fresh()->effective_until?->format('Y-m-d'));
    }

    public function test_accounting_export_counts_sale_amount_once_when_there_are_multiple_holders(): void
    {
        [$admin, $creator, $content, $offer] = $this->context();
        $this->contract($creator, $content, 50);
        $this->fiscal($creator, personType: 'PF', vatRegistered: false, withholdingRate: 12);
        $second = ContentCreator::query()->create(['name' => 'Titular Doi', 'is_active' => true]);
        $this->contract($second, $content, 25);
        $this->fiscal($second, personType: 'PJ', vatRegistered: false, withholdingRate: 0);
        app(RightsReportingService::class)->capturePurchase($this->entitlement($content, $offer, 120, 'moldova'));

        $rows = app(RightsReportingService::class)->exportRows($admin, []);

        $this->assertCount(2, $rows);
        $this->assertSame(120.0, (float) $rows->sum('suma_achitata'));
        $this->assertSame(20.0, (float) $rows->sum('tva_vanzare'));
        $this->assertSame(25.0, (float) $rows->sum('cota_609_film'));
    }

    private function context(): array
    {
        $this->seed([AccessControlSeeder::class, TaxonomySeeder::class, ContentSeeder::class]);
        $admin = User::query()->where('email', 'admin@filmoteca.md')->firstOrFail();
        $content = Content::query()->where('status', Content::STATUS_PUBLISHED)->firstOrFail();
        $offer = Offer::query()->where('content_id', $content->id)->firstOrFail();
        $creator = ContentCreator::query()->create(['name' => 'Titular Test', 'email' => 'titular@example.com', 'is_active' => true]);

        return [$admin, $creator, $content, $offer];
    }

    private function contract(ContentCreator $creator, Content $content, float $share): CreatorContractVersion
    {
        return CreatorContractVersion::query()->create([
            'content_creator_id' => $creator->id, 'content_id' => $content->id, 'share_percent' => $share,
            'territories' => null, 'effective_from' => '2020-01-01', 'status' => 'active',
        ]);
    }

    private function fiscal(ContentCreator $creator, string $personType, bool $vatRegistered, float $withholdingRate): CreatorFiscalProfile
    {
        return CreatorFiscalProfile::query()->create([
            'content_creator_id' => $creator->id, 'person_type' => $personType, 'tax_residency' => 'MD',
            'is_vat_registered' => $vatRegistered, 'vat_rate' => $vatRegistered ? 20 : 0,
            'withholding_enabled' => $withholdingRate > 0, 'withholding_rate' => $withholdingRate,
            'payment_currency' => 'MDL', 'effective_from' => '2020-01-01', 'status' => 'active',
        ]);
    }

    private function entitlement(Content $content, Offer $offer, float $amount, string $accessLocation): ContentEntitlement
    {
        $buyer = User::factory()->create(['email' => 'buyer-private@example.com', 'status' => 'active']);

        return ContentEntitlement::query()->create([
            'user_id' => $buyer->id, 'content_id' => $content->id, 'offer_id' => $offer->id,
            'access_type' => $offer->offer_type, 'quality' => $offer->quality, 'status' => ContentEntitlement::STATUS_ACTIVE,
            'currency' => 'MDL', 'access_location' => $accessLocation, 'price_amount' => $amount,
            'granted_at' => now(), 'starts_at' => now(), 'expires_at' => now()->addDays(2),
            'meta' => ['offer_name' => $offer->name],
        ]);
    }
}
