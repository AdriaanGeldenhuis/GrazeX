<?php
/**
 * Division-scoped carts (Feeds and Ranch each have their own cart).
 * Guests are identified by a cookie token; logged-in customers by customer_id.
 */
declare(strict_types=1);

defined('GX_ROOT') || exit;

final class CartService
{
    private static array $cartCache = [];

    private static function cookieName(string $div): string
    {
        return GX_CART_COOKIE_PREFIX . $div;
    }

    /** Find the active cart for a division (optionally create it). */
    public static function current(string $div, bool $create = false): ?array
    {
        if (array_key_exists($div, self::$cartCache) && (self::$cartCache[$div] || !$create)) {
            return self::$cartCache[$div];
        }
        $divisionId = division_id($div);
        $customerId = customer_id();
        $cart = null;
        if ($customerId) {
            $cart = db_one('SELECT * FROM carts WHERE customer_id = ? AND division_id = ?', [$customerId, $divisionId]);
        }
        if (!$cart) {
            $token = $_COOKIE[self::cookieName($div)] ?? '';
            if (is_string($token) && preg_match('/^[a-f0-9]{64}$/', $token)) {
                $cart = db_one('SELECT * FROM carts WHERE token = ? AND division_id = ? AND customer_id IS NULL', [$token, $divisionId]);
                if ($cart && $customerId) {
                    db_exec('UPDATE carts SET customer_id = ? WHERE id = ?', [$customerId, $cart['id']]);
                    $cart['customer_id'] = $customerId;
                }
            }
        }
        if (!$cart && $create) {
            $token = random_token(32);
            $id = db_insert('INSERT INTO carts (division_id, customer_id, token) VALUES (?, ?, ?)', [$divisionId, $customerId, $token]);
            $cart = db_one('SELECT * FROM carts WHERE id = ?', [$id]);
            if (!headers_sent()) {
                setcookie(self::cookieName($div), $token, [
                    'expires' => time() + GX_CART_COOKIE_DAYS * 86400, 'path' => '/', 'secure' => is_https(),
                    'httponly' => true, 'samesite' => 'Lax',
                ]);
            }
        }
        self::$cartCache[$div] = $cart;
        return $cart;
    }

    /** Badge number: bags for Feeds, lots for Ranch. */
    public static function count(string $div): int
    {
        try {
            $cart = self::current($div);
            if (!$cart) {
                return 0;
            }
            return $div === 'ranch'
                ? (int) db_val('SELECT COUNT(*) FROM cart_items WHERE cart_id = ?', [$cart['id']])
                : (int) db_val('SELECT COALESCE(SUM(qty), 0) FROM cart_items WHERE cart_id = ?', [$cart['id']]);
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** Add a product variant (feeds) or lot (ranch). Returns a user-facing message. */
    public static function add(string $div, int $targetId, int $qty): string
    {
        $qty = max(1, min(9999, $qty));
        $cart = self::current($div, true);
        if ($div === 'feeds') {
            $v = db_one('SELECT v.*, p.name AS product_name FROM product_variants v JOIN products p ON p.id = v.product_id
                         WHERE v.id = ? AND v.is_active = 1 AND p.is_active = 1', [$targetId]);
            if (!$v) {
                throw new DomainException('That product is no longer available.');
            }
            $existing = (int) db_val('SELECT qty FROM cart_items WHERE cart_id = ? AND variant_id = ?', [$cart['id'], $targetId]);
            $want = $existing + $qty;
            if ((int) $v['stock_qty'] <= 0) {
                throw new DomainException('Sorry, ' . $v['product_name'] . ' is out of stock.');
            }
            $final = min($want, (int) $v['stock_qty']);
            db_exec('INSERT INTO cart_items (cart_id, variant_id, qty) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE qty = VALUES(qty)',
                [$cart['id'], $targetId, $final]);
            self::touch((int) $cart['id']);
            return $final < $want
                ? 'Only ' . (int) $v['stock_qty'] . ' in stock — your cart has been updated.'
                : $v['product_name'] . ' (' . $v['name'] . ') added to your cart.';
        }

        $lot = db_one("SELECT * FROM lots WHERE id = ? AND status IN ('available','reserved')", [$targetId]);
        if (!$lot || (int) $lot['qty_available'] <= 0) {
            throw new DomainException('Sorry, that lot is no longer available.');
        }
        $final = min($qty, (int) $lot['qty_available']);
        db_exec('INSERT INTO cart_items (cart_id, lot_id, qty) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE qty = VALUES(qty)',
            [$cart['id'], $targetId, $final]);
        self::touch((int) $cart['id']);
        return $final < $qty
            ? 'Only ' . (int) $lot['qty_available'] . ' available — your selection has been updated.'
            : $lot['title'] . ' added to your selection.';
    }

    public static function updateQty(string $div, int $itemId, int $qty): void
    {
        $cart = self::current($div);
        if (!$cart) {
            return;
        }
        if ($qty <= 0) {
            self::remove($div, $itemId);
            return;
        }
        db_exec('UPDATE cart_items SET qty = ? WHERE id = ? AND cart_id = ?', [min(9999, $qty), $itemId, $cart['id']]);
        self::touch((int) $cart['id']);
    }

    public static function remove(string $div, int $itemId): void
    {
        $cart = self::current($div);
        if ($cart) {
            db_exec('DELETE FROM cart_items WHERE id = ? AND cart_id = ?', [$itemId, $cart['id']]);
            self::touch((int) $cart['id']);
        }
    }

    /**
     * What the cart changed on its own since the customer last looked (item removed, quantity capped, coupon dropped),
     * as sentences that name the product. Shown on checkout; placing the order waits until the customer has seen them.
     */
    public static function changes(array $summary): array
    {
        $out = $summary['notices'];
        foreach ($summary['lines'] as $l) {
            if (!empty($l['notice'])) {
                $out[] = $l['name'] . ($l['variant'] ? ' (' . $l['variant'] . ')' : '') . ': ' . rtrim($l['notice'], '.') . ' — quantity changed to ' . (int) $l['qty'] . '.';
            }
        }
        return array_values(array_unique($out));
    }

    /** Forget the cached cart row (it is read again, e.g. under a lock). */
    public static function forget(string $div): void
    {
        unset(self::$cartCache[$div]);
    }

    public static function clear(string $div): void
    {
        $cart = self::current($div);
        if ($cart) {
            db_exec('DELETE FROM cart_items WHERE cart_id = ?', [$cart['id']]);
            db_exec('UPDATE carts SET coupon_id = NULL WHERE id = ?', [$cart['id']]);
        }
    }

    public static function setCoupon(string $div, ?int $couponId): void
    {
        $cart = self::current($div, true);
        db_exec('UPDATE carts SET coupon_id = ? WHERE id = ?', [$couponId, $cart['id']]);
        self::$cartCache[$div]['coupon_id'] = $couponId;
    }

    private static function touch(int $cartId): void
    {
        db_exec('UPDATE carts SET updated_at = NOW() WHERE id = ?', [$cartId]);
    }

    /**
     * Full cart summary with live prices and stock checks. Quantities above available stock are capped.
     * Lines: id, type, target_id, name, variant, slug, url, media_id, qty, max, unit, total, regular, on_special, notice, ...
     */
    public static function summary(string $div): array
    {
        $cart = self::current($div);
        $out = ['division' => $div, 'lines' => [], 'count' => 0, 'subtotal' => 0, 'deposit' => 0, 'est_weight_kg' => 0.0,
                'coupon' => null, 'discount' => 0, 'notices' => [], 'vat_bp' => PricingService::divisionVatBp(division_id($div))];
        if (!$cart) {
            return $out;
        }

        if ($div === 'feeds') {
            $rows = db_all('SELECT ci.id AS item_id, ci.qty, v.*, p.name AS product_name, p.slug AS product_slug, p.is_active AS p_active,
                                   (SELECT pm.media_id FROM product_media pm WHERE pm.product_id = p.id ORDER BY pm.sort_order, pm.id LIMIT 1) AS media_id
                            FROM cart_items ci
                            JOIN product_variants v ON v.id = ci.variant_id
                            JOIN products p ON p.id = v.product_id
                            WHERE ci.cart_id = ? ORDER BY ci.id', [$cart['id']]);
            foreach ($rows as $r) {
                $stock = (int) $r['stock_qty'];
                $qty = (int) $r['qty'];
                $notice = null;
                if (!(int) $r['is_active'] || !(int) $r['p_active'] || $stock <= 0) {
                    db_exec('DELETE FROM cart_items WHERE id = ?', [$r['item_id']]);
                    $out['notices'][] = $r['product_name'] . ((int) $r['is_active'] && (int) $r['p_active']
                        ? ' is out of stock and was removed from your cart.' : ' is no longer available and was removed from your cart.');
                    continue;
                }
                if ($qty > $stock) {
                    $qty = $stock;
                    db_exec('UPDATE cart_items SET qty = ? WHERE id = ?', [$qty, $r['item_id']]);
                    $notice = 'Only ' . $stock . ' in stock.';
                }
                $price = PricingService::variantPrice($r, $qty);
                $out['lines'][] = [
                    'id' => (int) $r['item_id'], 'type' => 'variant', 'target_id' => (int) $r['id'],
                    'name' => $r['product_name'], 'variant' => $r['name'], 'sku' => $r['sku'],
                    'url' => product_url($r['product_slug']), 'media_id' => $r['media_id'] ? (int) $r['media_id'] : null,
                    'qty' => $qty, 'max' => $stock, 'unit' => $price['unit'], 'regular' => $price['regular'],
                    'on_special' => $price['on_special'], 'tier_min' => $price['tier_min'], 'next_tier' => $price['next_tier'],
                    'total' => $price['unit'] * $qty, 'weight_grams' => (int) $r['weight_grams'] * $qty, 'notice' => $notice,
                ];
                $out['count'] += $qty;
                $out['subtotal'] += $price['unit'] * $qty;
            }
        } else {
            $rows = db_all('SELECT ci.id AS item_id, ci.qty, l.*, b.name AS breed_name, c.name AS category_name,
                                   (SELECT lm.media_id FROM lot_media lm WHERE lm.lot_id = l.id ORDER BY lm.sort_order, lm.id LIMIT 1) AS media_id
                            FROM cart_items ci
                            JOIN lots l ON l.id = ci.lot_id
                            JOIN breeds b ON b.id = l.breed_id
                            JOIN livestock_categories c ON c.id = l.category_id
                            WHERE ci.cart_id = ? ORDER BY ci.id', [$cart['id']]);
            foreach ($rows as $r) {
                $avail = (int) $r['qty_available'];
                $qty = (int) $r['qty'];
                $notice = null;
                if (!in_array($r['status'], ['available', 'reserved'], true) || $avail <= 0) {
                    db_exec('DELETE FROM cart_items WHERE id = ?', [$r['item_id']]);
                    $out['notices'][] = $r['title'] . ' is no longer available and was removed.';
                    continue;
                }
                if ($qty > $avail) {
                    $qty = $avail;
                    db_exec('UPDATE cart_items SET qty = ? WHERE id = ?', [$qty, $r['item_id']]);
                    $notice = 'Only ' . $avail . ' available.';
                }
                $price = PricingService::lotPrice($r, $qty);
                $deposit = PricingService::lotDeposit($r, $price['total']);
                $out['lines'][] = [
                    'id' => (int) $r['item_id'], 'type' => 'lot', 'target_id' => (int) $r['id'],
                    'name' => $r['title'], 'variant' => $r['breed_name'] . ' · ' . $r['category_name'], 'sku' => $r['lot_no'],
                    'url' => lot_url($r['slug']), 'media_id' => $r['media_id'] ? (int) $r['media_id'] : null,
                    'qty' => $qty, 'max' => $avail, 'unit' => $price['unit'], 'regular' => $price['unit'], 'on_special' => false,
                    'tier_min' => null, 'next_tier' => null, 'total' => $price['total'], 'pricing_mode' => $r['pricing_mode'],
                    'price_cents' => (int) $r['price_cents'], 'avg_weight_kg' => $r['avg_weight_kg'] !== null ? (float) $r['avg_weight_kg'] : null,
                    'est_weight_kg' => $price['est_weight_kg'], 'deposit_pct' => (int) $r['deposit_pct'], 'deposit' => $deposit, 'notice' => $notice,
                ];
                $out['count'] += 1;
                $out['subtotal'] += $price['total'];
                $out['deposit'] += $deposit;
                $out['est_weight_kg'] += (float) ($price['est_weight_kg'] ?? 0);
            }
        }

        if ($cart['coupon_id'] ?? null) {
            $coupon = db_one('SELECT * FROM coupons WHERE id = ?', [$cart['coupon_id']]);
            $check = $coupon ? PricingService::checkCoupon($coupon['code'], division_id($div), $out['subtotal'], customer_id()) : ['ok' => false];
            if ($check['ok']) {
                $out['coupon'] = ['id' => (int) $coupon['id'], 'code' => $coupon['code'], 'type' => $coupon['type'], 'value' => (int) $coupon['value']];
                $out['discount'] = PricingService::couponDiscount($coupon, $out['subtotal'], 0);
            } else {
                db_exec('UPDATE carts SET coupon_id = NULL WHERE id = ?', [$cart['id']]);
                if ($out['lines']) {
                    $out['notices'][] = 'Your coupon no longer applies and was removed.';
                }
            }
        }
        // The deposit can never be more than what the cart costs (same rule as checkout)
        $out['deposit'] = min($out['deposit'], max(0, $out['subtotal'] - $out['discount']));
        return $out;
    }

    /** After login: attach guest carts to the customer, merging with existing ones. */
    public static function mergeGuestCarts(int $customerId): void
    {
        foreach (array_keys(GX_DIVISIONS) as $div) {
            $token = $_COOKIE[self::cookieName($div)] ?? '';
            if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/', $token)) {
                continue;
            }
            $divisionId = division_id($div);
            $guest = db_one('SELECT * FROM carts WHERE token = ? AND division_id = ? AND customer_id IS NULL', [$token, $divisionId]);
            if (!$guest) {
                continue;
            }
            $mine = db_one('SELECT * FROM carts WHERE customer_id = ? AND division_id = ?', [$customerId, $divisionId]);
            if (!$mine) {
                db_exec('UPDATE carts SET customer_id = ? WHERE id = ?', [$customerId, $guest['id']]);
                continue;
            }
            db_tx(static function () use ($guest, $mine): void {
                foreach (db_all('SELECT * FROM cart_items WHERE cart_id = ?', [$guest['id']]) as $it) {
                    if ($it['variant_id']) {
                        db_exec('INSERT INTO cart_items (cart_id, variant_id, qty) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty)',
                            [$mine['id'], $it['variant_id'], $it['qty']]);
                    } else {
                        db_exec('INSERT INTO cart_items (cart_id, lot_id, qty) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE qty = GREATEST(qty, VALUES(qty))',
                            [$mine['id'], $it['lot_id'], $it['qty']]);
                    }
                }
                db_exec('DELETE FROM carts WHERE id = ?', [$guest['id']]);
            });
        }
        self::$cartCache = [];
    }
}
