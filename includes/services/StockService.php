<?php
/**
 * Stock ledger. Every stock change goes through here and writes a stock_movements row
 * with the balance after the change. Call inside a transaction; rows are locked FOR UPDATE.
 */
declare(strict_types=1);

defined('GX_ROOT') || exit;

final class StockService
{
    /**
     * Change a feed variant's stock. Returns the new balance.
     * @throws DomainException when stock would go below zero.
     */
    public static function adjustVariant(int $variantId, int $delta, string $type, array $opts = []): int
    {
        return db_tx(static function () use ($variantId, $delta, $type, $opts): int {
            $v = db_one('SELECT v.id, v.stock_qty, v.low_stock_threshold, v.name, p.name AS product_name
                         FROM product_variants v JOIN products p ON p.id = v.product_id WHERE v.id = ? FOR UPDATE', [$variantId]);
            if (!$v) {
                throw new DomainException('Product variant not found.');
            }
            $before = (int) $v['stock_qty'];
            $after = $before + $delta;
            if ($after < 0) {
                throw new DomainException('Not enough stock for ' . $v['product_name'] . ' (' . $v['name'] . '): only ' . $before . ' left.');
            }
            db_exec('UPDATE product_variants SET stock_qty = ? WHERE id = ?', [$after, $variantId]);
            if ($type === 'receive' && !empty($opts['unit_cost_cents'])) {
                db_exec('UPDATE product_variants SET cost_cents = ? WHERE id = ?', [(int) $opts['unit_cost_cents'], $variantId]);
            }
            self::log(division_id('feeds'), $variantId, null, $type, $delta, $after, $opts);
            $threshold = (int) $v['low_stock_threshold'];
            if ($delta < 0 && $before > $threshold && $after <= $threshold) {
                NotificationService::admin('low_stock', 'Low stock: ' . $v['product_name'] . ' (' . $v['name'] . ')',
                    $after . ' left (threshold ' . $threshold . ').', '/admin/inventory.php?variant=' . $variantId, division_id('feeds'), false);
            }
            return $after;
        });
    }

    /**
     * Change a livestock lot's available quantity. Keeps the lot status in sync (sold out ⇄ available).
     */
    public static function adjustLot(int $lotId, int $delta, string $type, array $opts = []): int
    {
        return db_tx(static function () use ($lotId, $delta, $type, $opts): int {
            $l = db_one('SELECT id, qty_available, status, title FROM lots WHERE id = ? FOR UPDATE', [$lotId]);
            if (!$l) {
                throw new DomainException('Lot not found.');
            }
            $after = (int) $l['qty_available'] + $delta;
            if ($after < 0) {
                throw new DomainException('Not enough animals left in ' . $l['title'] . ': only ' . (int) $l['qty_available'] . ' available.');
            }
            $status = $l['status'];
            if ($after === 0 && in_array($status, ['available', 'reserved'], true)) {
                $status = 'sold_out';
            } elseif ($after > 0 && $status === 'sold_out') {
                $status = 'available';
            }
            db_exec('UPDATE lots SET qty_available = ?, status = ? WHERE id = ?', [$after, $status, $lotId]);
            self::log(division_id('ranch'), null, $lotId, $type, $delta, $after, $opts);
            return $after;
        });
    }

    private static function log(int $divisionId, ?int $variantId, ?int $lotId, string $type, int $delta, int $after, array $opts): void
    {
        $admin = current_admin();
        db_exec('INSERT INTO stock_movements (division_id, variant_id, lot_id, type, qty_change, balance_after, unit_cost_cents, order_id, reference, note, admin_id)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)', [
            $divisionId, $variantId, $lotId, $type, $delta, $after,
            $opts['unit_cost_cents'] ?? null, $opts['order_id'] ?? null, $opts['reference'] ?? null,
            isset($opts['note']) ? mb_substr((string) $opts['note'], 0, 255) : null, $opts['admin_id'] ?? ($admin['id'] ?? null),
        ]);
    }
}
