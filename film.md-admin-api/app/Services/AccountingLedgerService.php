<?php

namespace App\Services;

use App\Models\PaymentRefund;
use App\Models\PaymentTopUp;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AccountingLedgerService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array{
     *     items: Collection<int, array<string, mixed>>,
     *     summary: array<string, mixed>,
     *     options: array<string, mixed>
     * }
     */
    public function build(array $filters = []): array
    {
        $direction = (string) Arr::get($filters, 'direction', 'all');
        $status = (string) Arr::get($filters, 'status', 'accounted');

        $items = collect();

        if ($direction !== 'outflow' && $status !== PaymentRefund::STATUS_SUCCEEDED) {
            $items = $items->merge(
                $this->topUpQuery($filters)
                    ->get()
                    ->map(fn (PaymentTopUp $topUp): array => $this->topUpRow($topUp)),
            );
        }

        if ($direction !== 'inflow' && ! in_array($status, [
            PaymentTopUp::STATUS_PAID,
            PaymentTopUp::STATUS_REFUNDED,
            PaymentTopUp::STATUS_PENDING,
            PaymentTopUp::STATUS_REDIRECT_CREATED,
            PaymentTopUp::STATUS_PROCESSING,
            PaymentTopUp::STATUS_CANCELED,
        ], true)) {
            $items = $items->merge(
                $this->refundQuery($filters)
                    ->get()
                    ->map(fn (PaymentRefund $refund): array => $this->refundRow($refund)),
            );
        }

        $items = $items
            ->sortByDesc(fn (array $row): string => (string) ($row['processed_at'] ?? ''))
            ->values();

        return [
            'items' => $items,
            'summary' => $this->summary($items),
            'options' => $this->options(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function topUpQuery(array $filters): Builder
    {
        $query = PaymentTopUp::query()
            ->with(['user', 'billingAddress'])
            ->latest('credited_at')
            ->latest('id');

        $this->applyDateFilters($query, $filters, 'COALESCE(credited_at, created_at)');
        $this->applyTopUpBillingFilters($query, $filters);
        $this->applyTopUpStatusFilter($query, (string) Arr::get($filters, 'status', 'accounted'));
        $this->applyTopUpSearch($query, trim((string) Arr::get($filters, 'search', '')));

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function refundQuery(array $filters): Builder
    {
        $query = PaymentRefund::query()
            ->with(['user', 'topUp.billingAddress'])
            ->latest('processed_at')
            ->latest('id');

        $this->applyDateFilters($query, $filters, 'COALESCE(processed_at, created_at)');
        $this->applyRefundBillingFilters($query, $filters);
        $this->applyRefundStatusFilter($query, (string) Arr::get($filters, 'status', 'accounted'));
        $this->applyRefundSearch($query, trim((string) Arr::get($filters, 'search', '')));

        return $query;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function applyDateFilters(Builder $query, array $filters, string $columnExpression): void
    {
        $from = $this->dateFilter($filters, 'from', true);
        $to = $this->dateFilter($filters, 'to', false);

        if ($from !== null) {
            $query->whereRaw("{$columnExpression} >= ?", [$from]);
        }

        if ($to !== null) {
            $query->whereRaw("{$columnExpression} <= ?", [$to]);
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function dateFilter(array $filters, string $key, bool $startOfDay): ?Carbon
    {
        $value = trim((string) Arr::get($filters, $key, ''));

        if ($value === '') {
            return null;
        }

        $date = Carbon::parse($value);

        return $startOfDay ? $date->startOfDay() : $date->endOfDay();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function applyTopUpBillingFilters(Builder $query, array $filters): void
    {
        $countryCode = strtoupper(trim((string) Arr::get($filters, 'country_code', '')));
        $region = trim((string) Arr::get($filters, 'administrative_area', ''));
        $market = (string) Arr::get($filters, 'market', 'all');

        if ($countryCode !== '') {
            $query->where('billing_address->country_code', $countryCode);
        }

        if ($region !== '') {
            $query->where('billing_address->administrative_area', $region);
        }

        if ($market === 'domestic') {
            $query->where('billing_address->country_code', 'MD');
        } elseif ($market === 'international') {
            $query->where('billing_address->country_code', '!=', 'MD');
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    protected function applyRefundBillingFilters(Builder $query, array $filters): void
    {
        $countryCode = strtoupper(trim((string) Arr::get($filters, 'country_code', '')));
        $region = trim((string) Arr::get($filters, 'administrative_area', ''));
        $market = (string) Arr::get($filters, 'market', 'all');

        if ($countryCode === '' && $region === '' && $market === 'all') {
            return;
        }

        $query->whereHas('topUp', function (Builder $topUpQuery) use ($countryCode, $region, $market): void {
            if ($countryCode !== '') {
                $topUpQuery->where('billing_address->country_code', $countryCode);
            }

            if ($region !== '') {
                $topUpQuery->where('billing_address->administrative_area', $region);
            }

            if ($market === 'domestic') {
                $topUpQuery->where('billing_address->country_code', 'MD');
            } elseif ($market === 'international') {
                $topUpQuery->where('billing_address->country_code', '!=', 'MD');
            }
        });
    }

    protected function applyTopUpStatusFilter(Builder $query, string $status): void
    {
        if ($status === 'all') {
            return;
        }

        if ($status === 'accounted') {
            $query
                ->whereNotNull('credited_at')
                ->whereIn('status', [PaymentTopUp::STATUS_PAID, PaymentTopUp::STATUS_REFUNDED]);

            return;
        }

        $query->where('status', $status);
    }

    protected function applyRefundStatusFilter(Builder $query, string $status): void
    {
        if ($status === 'all') {
            return;
        }

        if ($status === 'accounted') {
            $query->where('status', PaymentRefund::STATUS_SUCCEEDED);

            return;
        }

        $query->where('status', $status);
    }

    protected function applyTopUpSearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $query->where(function (Builder $nested) use ($search): void {
            $nested
                ->where('uuid', 'like', "%{$search}%")
                ->orWhere('provider_order_id', 'like', "%{$search}%")
                ->orWhere('provider_checkout_id', 'like', "%{$search}%")
                ->orWhere('provider_rrn', 'like', "%{$search}%")
                ->orWhereHas('user', function (Builder $userQuery) use ($search): void {
                    $userQuery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
        });
    }

    protected function applyRefundSearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $query->where(function (Builder $nested) use ($search): void {
            $nested
                ->where('uuid', 'like', "%{$search}%")
                ->orWhere('provider_order_id', 'like', "%{$search}%")
                ->orWhere('provider_checkout_id', 'like', "%{$search}%")
                ->orWhere('provider_rrn', 'like', "%{$search}%")
                ->orWhere('reason', 'like', "%{$search}%")
                ->orWhereHas('user', function (Builder $userQuery) use ($search): void {
                    $userQuery
                        ->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
        });
    }

    /**
     * @return array<string, mixed>
     */
    protected function topUpRow(PaymentTopUp $topUp): array
    {
        $billing = $this->billingSnapshot($topUp);
        $isAccounted = $topUp->credited_at !== null
            && in_array($topUp->status, [PaymentTopUp::STATUS_PAID, PaymentTopUp::STATUS_REFUNDED], true);

        return [
            'id' => 'topup-'.$topUp->uuid,
            'source_id' => $topUp->uuid,
            'source_type' => 'top_up',
            'direction' => 'inflow',
            'direction_label' => 'Intrare',
            'status' => $topUp->status,
            'is_accounted' => $isAccounted,
            'amount' => round((float) $topUp->amount, 2),
            'signed_amount' => $isAccounted ? round((float) $topUp->amount, 2) : 0.0,
            'currency' => $topUp->currency,
            'processed_at' => ($topUp->credited_at ?? $topUp->created_at)?->toIso8601String(),
            'description' => $topUp->description ?: 'Suplinire cont Filmoteca.md',
            'provider_order_id' => $topUp->provider_order_id,
            'provider_checkout_id' => $topUp->provider_checkout_id,
            'provider_rrn' => $topUp->provider_rrn,
            'user' => [
                'id' => $topUp->user?->id,
                'name' => $topUp->user?->name,
                'email' => $topUp->user?->email,
            ],
            'billing' => $billing,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function refundRow(PaymentRefund $refund): array
    {
        $billing = $refund->topUp ? $this->billingSnapshot($refund->topUp) : $this->emptyBilling();
        $isAccounted = $refund->status === PaymentRefund::STATUS_SUCCEEDED;

        return [
            'id' => 'refund-'.$refund->uuid,
            'source_id' => $refund->uuid,
            'source_type' => 'refund',
            'direction' => 'outflow',
            'direction_label' => 'Ieșire',
            'status' => $refund->status,
            'is_accounted' => $isAccounted,
            'amount' => round((float) $refund->amount, 2),
            'signed_amount' => $isAccounted ? -round((float) $refund->amount, 2) : 0.0,
            'currency' => $refund->currency,
            'processed_at' => ($refund->processed_at ?? $refund->created_at)?->toIso8601String(),
            'description' => $refund->reason,
            'provider_order_id' => $refund->provider_order_id,
            'provider_checkout_id' => $refund->provider_checkout_id,
            'provider_rrn' => $refund->provider_rrn,
            'user' => [
                'id' => $refund->user?->id,
                'name' => $refund->user?->name,
                'email' => $refund->user?->email,
            ],
            'billing' => $billing,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function billingSnapshot(PaymentTopUp $topUp): array
    {
        $snapshot = is_array($topUp->billing_address) ? $topUp->billing_address : [];
        $address = $topUp->billingAddress;
        $countryCode = strtoupper((string) (Arr::get($snapshot, 'country_code') ?? $address?->country_code ?? ''));

        return [
            'full_name' => Arr::get($snapshot, 'full_name') ?? $address?->full_name,
            'country_code' => $countryCode !== '' ? $countryCode : null,
            'administrative_area' => Arr::get($snapshot, 'administrative_area') ?? $address?->administrative_area,
            'city' => Arr::get($snapshot, 'city') ?? $address?->city,
            'postal_code' => Arr::get($snapshot, 'postal_code') ?? $address?->postal_code,
            'address_line1' => Arr::get($snapshot, 'address_line1') ?? $address?->address_line1,
            'address_line2' => Arr::get($snapshot, 'address_line2') ?? $address?->address_line2,
            'market' => $countryCode === 'MD' ? 'domestic' : ($countryCode !== '' ? 'international' : 'unknown'),
            'market_label' => $countryCode === 'MD' ? 'Moldova' : ($countryCode !== '' ? 'Extern' : 'Necunoscut'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function emptyBilling(): array
    {
        return [
            'full_name' => null,
            'country_code' => null,
            'administrative_area' => null,
            'city' => null,
            'postal_code' => null,
            'address_line1' => null,
            'address_line2' => null,
            'market' => 'unknown',
            'market_label' => 'Necunoscut',
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    protected function summary(Collection $items): array
    {
        $accounted = $items->where('is_accounted', true);
        $grossInflow = (float) $accounted->where('direction', 'inflow')->sum('amount');
        $refunds = (float) $accounted->where('direction', 'outflow')->sum('amount');

        return [
            'gross_inflow' => round($grossInflow, 2),
            'refunds' => round($refunds, 2),
            'net_inflow' => round($grossInflow - $refunds, 2),
            'accounted_transactions' => $accounted->count(),
            'domestic_transactions' => $accounted->where('billing.market', 'domestic')->count(),
            'international_transactions' => $accounted->where('billing.market', 'international')->count(),
            'currency' => (string) ($accounted->first()['currency'] ?? 'MDL'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function options(): array
    {
        $billingSnapshots = PaymentTopUp::query()
            ->whereNotNull('billing_address')
            ->get(['billing_address'])
            ->pluck('billing_address')
            ->filter(fn (mixed $snapshot): bool => is_array($snapshot));

        $countries = $billingSnapshots
            ->map(fn (array $snapshot): string => strtoupper((string) Arr::get($snapshot, 'country_code', '')))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->map(fn (string $code): array => ['value' => $code, 'label' => $code])
            ->all();

        $regions = $billingSnapshots
            ->map(fn (array $snapshot): string => trim((string) Arr::get($snapshot, 'administrative_area', '')))
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->map(fn (string $region): array => ['value' => $region, 'label' => $region])
            ->all();

        return [
            'countries' => $countries,
            'regions' => $regions,
            'statuses' => [
                ['value' => 'accounted', 'label' => 'Doar contabilizate'],
                ['value' => 'all', 'label' => 'Toate statusurile'],
                ['value' => PaymentTopUp::STATUS_PAID, 'label' => 'Plătite'],
                ['value' => PaymentTopUp::STATUS_REFUNDED, 'label' => 'Top-up refundat'],
                ['value' => PaymentRefund::STATUS_SUCCEEDED, 'label' => 'Refund reușit'],
                ['value' => PaymentTopUp::STATUS_PENDING, 'label' => 'În așteptare'],
                ['value' => PaymentTopUp::STATUS_PROCESSING, 'label' => 'În procesare'],
                ['value' => PaymentTopUp::STATUS_FAILED, 'label' => 'Eșuate'],
            ],
        ];
    }
}
