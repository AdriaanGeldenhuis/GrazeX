<?php
/**
 * Prices, specials, bulk tiers, VAT and coupons. All amounts are integer cents, VAT-inclusive.
 */
declare(strict_types=1);

defined('GX_ROOT') || exit;

final class PricingService
{
    /** Is the variant's special price active right now? */
    public static function specialActive(array $v): bool
    {
        if ($v['special_price_cents'] === null || $v['special_price_cents'] === '') {
            return false;
        }
        $now = time();
        if (!empty($v['special_from']) && strtotime((string) $v['special_from']) > $now) {
            return false;
        }
        if (!empty($v['special_to']) && strtotime((string) $v['special_to']) < $now) {
            return false;
        }
        return (int) $v['special_price_cents'] < (int) $v['price_cents'];
    }

    /** Base unit price (special if active). */
    public static function basePrice(array $v): int
    {
        return self::specialActive($v) ? (int) $v['special_price_cents'] : (int) $v['price_cents'];
    }

    /** Tiers for a variant, sorted by min_qty ascending (cached per request). */
    public static function tiers(int $variantId): array
    {
        static $cache = [];
        if (!isset($cache[$variantId])) {
            $cache[$variantId] = db_all('SELECT min_qty, price_cents FROM price_tiers WHERE variant_id = ? ORDER BY min_qty', [$variantId]);
        }
        return $cache[$variantId];
    }

    /**
     * Unit price for a quantity: the lowest of base price and any qualifying bulk tier.
     * Returns ['unit' => int, 'base' => int, 'regular' => int, 'on_special' => bool, 'tier_min' => ?int, 'next_tier' => ?array]
     */
    public static function variantPrice(array $v, int $qty = 1): array
    {
        $regular = (int) $v['price_cents'];
        $base = self::basePrice($v);
        $unit = $base;
        $tierMin = null;
        $next = null;
        foreach (self::tiers((int) $v['id']) as $t) {
            if ($qty >= (int) $t['min_qty']) {
                if ((int) $t['price_cents'] < $unit) {
                    $unit = (int) $t['price_cents'];
                    $tierMin = (int) $t['min_qty'];
                }
            } elseif ($next === null && (int) $t['price_cents'] < $unit) {
                $next = ['min_qty' => (int) $t['min_qty'], 'price_cents' => (int) $t['price_cents']];
            }
        }
        return [
            'unit' => $unit,
            'base' => $base,
            'regular' => $regular,
            'on_special' => self::specialActive($v),
            'tier_min' => $tierMin,
            'next_tier' => $next,
        ];
    }

    /**
     * Livestock line: per head = qty × price; per kg = qty × avg weight × price per kg (estimate).
     * Returns ['unit' => cents per head (estimated for per kg), 'total' => cents, 'est_weight_kg' => ?float]
     */
    public static function lotPrice(array $lot, int $qty): array
    {
        if ($lot['pricing_mode'] === 'per_kg') {
            $avg = (float) ($lot['avg_weight_kg'] ?? 0);
            $unit = (int) round($avg * (int) $lot['price_cents']);
            return ['unit' => $unit, 'total' => $unit * $qty, 'est_weight_kg' => round($avg * $qty, 2)];
        }
        $unit = (int) $lot['price_cents'];
        return ['unit' => $unit, 'total' => $unit * $qty, 'est_weight_kg' => $lot['avg_weight_kg'] !== null ? round((float) $lot['avg_weight_kg'] * $qty, 2) : null];
    }

    /** Deposit for a livestock line. */
    public static function lotDeposit(array $lot, int $lineTotal): int
    {
        return (int) round($lineTotal * min(100, max(0, (int) $lot['deposit_pct'])) / 100);
    }

    /** Current standard VAT rate in basis points (1500 = 15%). */
    public static function vatRateBp(?string $date = null): int
    {
        static $cache = [];
        $date ??= date('Y-m-d');
        if (!isset($cache[$date])) {
            $cache[$date] = (int) (db_val('SELECT rate_bp FROM vat_rates WHERE effective_from <= ? ORDER BY effective_from DESC LIMIT 1', [$date]) ?? 1500);
        }
        return $cache[$date];
    }

    /** VAT rate a division charges (0 if not VAT registered). */
    public static function divisionVatBp(int $divisionId): int
    {
        $d = division($divisionId);
        return ($d && (int) $d['vat_registered'] === 1) ? self::vatRateBp() : 0;
    }

    /**
     * Validate a coupon for a cart. Returns ['ok' => bool, 'error' => ?string, 'coupon' => ?array].
     */
    public static function checkCoupon(string $code, int $divisionId, int $subtotal, ?int $customerId): array
    {
        $c = db_one('SELECT * FROM coupons WHERE code = ?', [mb_strtoupper(trim($code))]);
        $fail = static fn(string $m) => ['ok' => false, 'error' => $m, 'coupon' => null];
        if (!$c || !(int) $c['is_active']) {
            return $fail('That coupon code is not valid.');
        }
        if ($c['division_id'] !== null && (int) $c['division_id'] !== $divisionId) {
            return $fail('That coupon is not valid for this shop.');
        }
        if ($c['starts_at'] && strtotime($c['starts_at']) > time()) {
            return $fail('That coupon is not active yet.');
        }
        // An end date without a time (00:00) means "valid through that whole day"
        $ends = $c['ends_at'] ? (str_ends_with((string) $c['ends_at'], '00:00:00') ? strtotime(substr((string) $c['ends_at'], 0, 10) . ' 23:59:59') : strtotime((string) $c['ends_at'])) : null;
        if ($ends !== null && $ends < time()) {
            return $fail('That coupon has expired.');
        }
        if ($c['max_uses'] !== null && (int) $c['used_count'] >= (int) $c['max_uses']) {
            return $fail('That coupon has been fully redeemed.');
        }
        if ($subtotal < (int) $c['min_spend_cents']) {
            return $fail('Spend at least ' . money((int) $c['min_spend_cents']) . ' to use this coupon.');
        }
        if ($customerId && $c['max_uses_per_customer'] !== null) {
            $used = (int) db_val('SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id = ? AND customer_id = ?', [$c['id'], $customerId]);
            if ($used >= (int) $c['max_uses_per_customer']) {
                return $fail('You have already used this coupon.');
            }
        }
        return ['ok' => true, 'error' => null, 'coupon' => $c];
    }

    /** Discount (cents) a valid coupon gives on a subtotal / delivery fee. */
    public static function couponDiscount(array $coupon, int $subtotal, int $delivery): int
    {
        return match ($coupon['type']) {
            'percent' => (int) round($subtotal * min(100, (int) $coupon['value']) / 100),
            'fixed' => min($subtotal, (int) $coupon['value']),
            'free_delivery' => $delivery,
            default => 0,
        };
    }
}
