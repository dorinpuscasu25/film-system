<?php

namespace App\Services;

use App\Models\ContentCreator;
use App\Models\CreatorFiscalProfile;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * The titular's own fields (PF/PJ, VAT payer, IDNO, IBAN) are the single source for its
 * reporting fiscal profile. Profiles stay dated versions so past sales keep the terms they
 * were made under: editing the titular closes today's profile and opens a new one.
 */
class RightsHolderService
{
    public function __construct(protected RightsReportingService $reporting) {}

    /** @return CreatorFiscalProfile|null The profile now in force, or null if none was needed. */
    public function syncFiscalProfile(ContentCreator $holder, ?int $actorId): ?CreatorFiscalProfile
    {
        $today = Carbon::today();
        $current = CreatorFiscalProfile::query()
            ->where('content_creator_id', $holder->id)
            ->where('status', 'active')
            ->whereDate('effective_from', '<=', $today)
            ->where(fn (Builder $query) => $query->whereNull('effective_until')->orWhereDate('effective_until', '>=', $today))
            ->latest('effective_from')
            ->first();

        $wanted = [
            'person_type' => $holder->person_type ?: 'PJ',
            'is_vat_registered' => (bool) $holder->is_vat_payer,
            'tax_identifier' => $holder->idno ?: null,
            'iban' => $holder->iban ?: null,
        ];

        if ($current === null) {
            // Nothing fiscal entered yet: leave it to the reporting diagnostics to flag.
            if (! $wanted['is_vat_registered'] && $wanted['tax_identifier'] === null && $wanted['iban'] === null) {
                return null;
            }
        } elseif ($this->matches($current, $wanted)) {
            return $current;
        }

        $vatRate = $current !== null && (float) $current->vat_rate > 0
            ? (float) $current->vat_rate
            : (float) ($this->reporting->settingsFor(now())?->domestic_vat_rate ?? 20);

        // Corrected again on the day it was opened: fix that version instead of stacking another.
        if ($current !== null && Carbon::parse($current->effective_from)->isSameDay($today)) {
            $current->update([...$wanted, 'vat_rate' => $wanted['is_vat_registered'] ? $vatRate : 0]);

            return $current;
        }

        return $this->reporting->createFiscalProfile([
            'content_creator_id' => $holder->id,
            ...$wanted,
            'tax_residency' => $current?->tax_residency ?? 'MD',
            'vat_rate' => $vatRate,
            'withholding_enabled' => (bool) ($current?->withholding_enabled ?? false),
            'withholding_rate' => (float) ($current?->withholding_rate ?? 0),
            'payment_currency' => $current?->payment_currency ?? 'MDL',
            'effective_from' => $today->toDateString(),
        ], $actorId);
    }

    private function matches(CreatorFiscalProfile $profile, array $wanted): bool
    {
        return $profile->person_type === $wanted['person_type']
            && (bool) $profile->is_vat_registered === $wanted['is_vat_registered']
            && ($profile->tax_identifier ?: null) === $wanted['tax_identifier']
            && ($profile->iban ?: null) === $wanted['iban'];
    }
}
