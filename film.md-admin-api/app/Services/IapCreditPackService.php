<?php

namespace App\Services;

use App\Models\PlatformSetting;

/**
 * Configurable catalog mapping an Apple IAP consumable product_id to how many MDL credits it
 * grants. Apple is the source of truth for what the customer is actually charged (the App Store
 * storefront for Moldova has no local-currency pricing, so products are sold on fixed USD price
 * points — see docs/ios-in-app-purchase-audit.md) — this service only ever decides the credit
 * side, never derives it from whatever price Apple reports back on the transaction.
 */
class IapCreditPackService
{
    public const SETTINGS_KEY = 'iap_credit_packs';

    /**
     * Placeholder catalog for local `.storekit` development only — matches the product IDs a
     * developer would put in a local StoreKit Configuration file. Replace via the admin panel
     * (platform settings) once real products exist in App Store Connect.
     */
    public const DEFAULT_SETTINGS = [
        // Apple's cut (0.15 under the Small Business Program, 0.30 otherwise). Only used by the
        // admin pricing calculator to suggest how many credits a pack can safely grant — the
        // backend never charges or computes anything from it.
        'commission_rate' => 0.15,
        'packs' => [
            ['product_id' => 'md.filmoteca.ios.credits.100', 'credits_mdl' => 100, 'apple_price_usd' => 6.99, 'sort_order' => 1, 'is_placeholder' => true],
            ['product_id' => 'md.filmoteca.ios.credits.250', 'credits_mdl' => 250, 'apple_price_usd' => 16.99, 'sort_order' => 2, 'is_placeholder' => true],
            ['product_id' => 'md.filmoteca.ios.credits.500', 'credits_mdl' => 500, 'apple_price_usd' => 33.99, 'sort_order' => 3, 'is_placeholder' => true],
        ],
    ];

    /**
     * @return array<int, array{product_id: string, credits_mdl: float, apple_price_usd: ?float, sort_order: int, is_placeholder: bool}>
     */
    public function packs(): array
    {
        $settings = PlatformSetting::getValue(self::SETTINGS_KEY, []);

        return $this->normalizeSettings(is_array($settings) ? $settings : [])['packs'];
    }

    public function creditsForProduct(string $productId): ?float
    {
        foreach ($this->packs() as $pack) {
            if ($pack['product_id'] === $productId) {
                return $pack['credits_mdl'];
            }
        }

        return null;
    }

    public function normalizeSettings(array $settings): array
    {
        // The pack list is replaced wholesale, never merged index-by-index with the defaults —
        // otherwise saving a shorter list would silently keep redeemable placeholder packs alive.
        $packs = array_key_exists('packs', $settings) && is_array($settings['packs'])
            ? $settings['packs']
            : self::DEFAULT_SETTINGS['packs'];
        $commissionRate = (float) ($settings['commission_rate'] ?? self::DEFAULT_SETTINGS['commission_rate']);

        return [
            'commission_rate' => min(0.99, max(0, round($commissionRate, 4))),
            'packs' => collect($packs)
                ->map(function (mixed $pack): ?array {
                    $pack = is_array($pack) ? $pack : [];
                    $productId = trim((string) ($pack['product_id'] ?? ''));
                    if ($productId === '') {
                        return null;
                    }

                    $applePrice = $pack['apple_price_usd'] ?? null;

                    return [
                        'product_id' => $productId,
                        'credits_mdl' => max(0, round((float) ($pack['credits_mdl'] ?? 0), 2)),
                        'apple_price_usd' => is_numeric($applePrice) && (float) $applePrice > 0 ? round((float) $applePrice, 2) : null,
                        'sort_order' => (int) ($pack['sort_order'] ?? 0),
                        'is_placeholder' => filter_var($pack['is_placeholder'] ?? false, FILTER_VALIDATE_BOOL),
                    ];
                })
                ->filter(fn (?array $pack): bool => $pack !== null && $pack['credits_mdl'] > 0)
                ->unique('product_id')
                ->sortBy('sort_order')
                ->values()
                ->all(),
        ];
    }
}
