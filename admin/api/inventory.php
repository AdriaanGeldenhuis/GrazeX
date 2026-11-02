<?php
/**
 * Admin inventory API.
 *   {action: adjust, variant_id, change: "+10 reason"} · {action: adjust_lot, lot_id, change}
 *   {action: receive, supplier_name, supplier_invoice_no, received_on, lines[{variant_id, qty, unit_cost}]}
 *   {action: stocktake_start} · {action: stocktake_save, id, counts{itemId: qty}} · {action: stocktake_complete, id} · {action: stocktake_cancel, id}
 */
declare(strict_types=1);

define('GX_IS_API', true);
require __DIR__ . '/../../includes/bootstrap.php';
require_once GX_ROOT . '/includes/admin_form.php';

api_post();
$admin = require_admin_api('inventory.manage');
$aid = (int) $admin['id'];
$in = input_all();

/** Parse "+10 torn bags" → [10, 'torn bags'] */
$parseChange = static function (string $s): array {
    if (!preg_match('/^\s*([+-]?\d+)\s*(.*)$/u', $s, $m) || (int) $m[1] === 0) {
        throw new DomainException('Type a change like +10 or -2, optionally followed by a reason.');
    }
    return [(int) $m[1], trim($m[2]) ?: 'Manual adjustment'];
};

try {
    switch ((string) ($in['action'] ?? '')) {
        case 'adjust':
            [$delta, $reason] = $parseChange((string) ($in['change'] ?? ''));
            $bal = StockService::adjustVariant((int) $in['variant_id'], $delta, $delta < 0 ? 'write_off' : 'adjustment', ['note' => $reason, 'admin_id' => $aid]);
            json_ok([], 'Stock updated — now ' . $bal . '.');
        case 'adjust_lot':
            [$delta, $reason] = $parseChange((string) ($in['change'] ?? ''));
            $bal = StockService::adjustLot((int) $in['lot_id'], $delta, 'adjustment', ['note' => $reason, 'admin_id' => $aid]);
            if ($delta > 0) {
                db_exec('UPDATE lots SET qty_total = GREATEST(qty_total, qty_available) WHERE id = ?', [(int) $in['lot_id']]);
            }
            json_ok([], 'Livestock updated — ' . $bal . ' available.');
        case 'receive':
            $lines = array_values(array_filter((array) ($in['lines'] ?? []), static fn($l) => (int) ($l['qty'] ?? 0) > 0));
            if (!$lines) {
                json_error('Add at least one line with a quantity.', 422);
            }
            $n = db_tx(static function () use ($in, $lines, $aid): int {
                $rid = db_insert('INSERT INTO stock_receipts (division_id, supplier_name, supplier_invoice_no, received_on, admin_id) VALUES (?,?,?,?,?)', [
                    division_id('feeds'), mb_substr((string) ($in['supplier_name'] ?? ''), 0, 150) ?: null, mb_substr((string) ($in['supplier_invoice_no'] ?? ''), 0, 60) ?: null,
                    af_date($in['received_on'] ?? '') ?? date('Y-m-d'), $aid]);
                foreach ($lines as $l) {
                    $cost = af_cents($l['unit_cost'] ?? '');
                    db_exec('INSERT INTO stock_receipt_items (receipt_id, variant_id, qty, unit_cost_cents) VALUES (?,?,?,?)', [$rid, (int) $l['variant_id'], (int) $l['qty'], $cost]);
                    StockService::adjustVariant((int) $l['variant_id'], (int) $l['qty'], 'receive', ['unit_cost_cents' => $cost, 'reference' => 'GRV-' . $rid,
                        'note' => trim('Received ' . ($in['supplier_name'] ?? '') . ' ' . ($in['supplier_invoice_no'] ?? '')), 'admin_id' => $aid]);
                }
                return count($lines);
            });
            json_ok([], "Stock received ({$n} line" . ($n === 1 ? '' : 's') . ').');
        case 'stocktake_start':
            if (db_val("SELECT 1 FROM stock_takes WHERE status = 'open'")) {
                json_error('A stock take is already open.', 409);
            }
            db_tx(static function () use ($aid): void {
                $tid = db_insert('INSERT INTO stock_takes (division_id, started_by) VALUES (?, ?)', [division_id('feeds'), $aid]);
                db_exec('INSERT INTO stock_take_items (stock_take_id, variant_id, system_qty) SELECT ?, v.id, v.stock_qty FROM product_variants v JOIN products p ON p.id = v.product_id WHERE v.is_active = 1 AND p.is_active = 1', [$tid]);
            });
            json_ok([], 'Stock take started.');
        case 'stocktake_save':
            $takeId = (int) ($in['id'] ?? 0);
            $counts = (array) ($in['counts'] ?? []);
            if (!$takeId || !db_val("SELECT 1 FROM stock_takes WHERE id = ? AND status = 'open'", [$takeId])) {
                json_error('This stock take is no longer open — reload the page.', 409);
            }
            $ids = array_map('intval', array_keys($counts));
            $known = $ids ? (int) db_val('SELECT COUNT(*) FROM stock_take_items WHERE stock_take_id = ? AND id IN (' . db_placeholders($ids) . ')', array_merge([$takeId], $ids)) : 0;
            if ($known !== count($ids)) {
                json_error('Those count lines do not belong to this stock take — nothing was saved. Reload the page and try again.', 409);
            }
            db_tx(static function () use ($counts, $takeId): void {
                // The ledger position at the moment of counting: movements after it are added on completion
                $mark = (int) db_val('SELECT COALESCE(MAX(id), 0) FROM stock_movements');
                foreach ($counts as $itemId => $qty) {
                    $qty = $qty === '' || $qty === null ? null : max(0, (int) $qty);
                    // counted_qty is assigned last so the IF()s compare against the previous count
                    db_exec('UPDATE stock_take_items SET counted_mark = IF(counted_qty <=> ?, counted_mark, ?), counted_at = IF(counted_qty <=> ?, counted_at, NOW()),
                             counted_qty = ? WHERE id = ? AND stock_take_id = ?', [$qty, $qty === null ? null : $mark, $qty, $qty, (int) $itemId, $takeId]);
                }
            });
            json_ok([], 'Counts saved.');
        case 'stocktake_complete':
            $changed = db_tx(static function () use ($in, $aid): int {
                $take = db_one("SELECT * FROM stock_takes WHERE id = ? AND status = 'open' FOR UPDATE", [(int) $in['id']]);
                if (!$take) {
                    throw new DomainException('Stock take not found.');
                }
                $n = 0;
                foreach (db_all('SELECT * FROM stock_take_items WHERE stock_take_id = ? AND counted_qty IS NOT NULL', [$take['id']]) as $it) {
                    $now = (int) db_val('SELECT stock_qty FROM product_variants WHERE id = ? FOR UPDATE', [$it['variant_id']]);
                    if ($it['counted_mark'] !== null) {
                        // Count + every movement after the moment it was counted (sales, receipts, returns)
                        $since = (int) db_val("SELECT COALESCE(SUM(qty_change), 0) FROM stock_movements WHERE variant_id = ? AND id > ? AND type <> 'stocktake'",
                            [$it['variant_id'], (int) $it['counted_mark']]);
                    } else {
                        // Counted before counted_mark existed: treat the count as taken when the stock take started
                        $since = $now - (int) $it['system_qty'];
                    }
                    $target = max(0, (int) $it['counted_qty'] + $since);
                    if ($target !== $now) {
                        StockService::adjustVariant((int) $it['variant_id'], $target - $now, 'stocktake', ['reference' => 'ST-' . $take['id'], 'note' => 'Stock take', 'admin_id' => $aid]);
                        $n++;
                    }
                }
                db_exec("UPDATE stock_takes SET status = 'completed', completed_at = NOW() WHERE id = ?", [$take['id']]);
                return $n;
            });
            json_ok([], "Stock take complete — {$changed} item" . ($changed === 1 ? '' : 's') . ' corrected.');
        case 'stocktake_cancel':
            db_exec("UPDATE stock_takes SET status = 'cancelled' WHERE id = ? AND status = 'open'", [(int) $in['id']]);
            json_ok([], 'Stock take cancelled.');
        default:
            json_error('Unknown action.', 400);
    }
} catch (DomainException $e) {
    json_error($e->getMessage(), 422);
}
