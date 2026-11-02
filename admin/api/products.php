<?php
/**
 * Admin products API: {action: save, ...form} · {action: delete, id}
 */
declare(strict_types=1);

define('GX_IS_API', true);
require __DIR__ . '/../../includes/bootstrap.php';
require_once GX_ROOT . '/includes/admin_form.php';

api_post();
$admin = require_admin_api('products.manage');
$in = input_all();

if (($in['action'] ?? '') === 'delete') {
    $id = (int) ($in['id'] ?? 0);
    $deleted = db_tx(static function () use ($id): bool {
        // Lock the sizes first (checkout locks them too): an order placed right now lands either before the check
        // (and the product is hidden) or after the delete (and finds it gone) — never lost in between
        db_col('SELECT id FROM product_variants WHERE product_id = ? FOR UPDATE', [$id]);
        // Sales, stock received or stock movements are part of the books: never delete those, hide the product
        $history = (bool) db_val('SELECT 1 FROM product_variants v WHERE v.product_id = ? AND (
                    EXISTS (SELECT 1 FROM order_items oi WHERE oi.variant_id = v.id)
                 OR EXISTS (SELECT 1 FROM stock_receipt_items ri WHERE ri.variant_id = v.id)
                 OR EXISTS (SELECT 1 FROM stock_movements sm WHERE sm.variant_id = v.id)) LIMIT 1', [$id]);
        if (!$history) {
            try {
                db_exec('DELETE FROM products WHERE id = ?', [$id]);
                return true;
            } catch (PDOException $e) {
                if ((int) ($e->errorInfo[1] ?? 0) !== 1451) {
                    throw $e;
                }
                // still referenced somewhere: hide instead
            }
        }
        db_exec('UPDATE products SET is_active = 0 WHERE id = ?', [$id]);
        return false;
    });
    if (!$deleted) {
        audit('product.hidden', 'product', $id);
        json_ok(['redirect' => '/admin/products.php'], 'This product has sales or stock history, so it was hidden instead of deleted.');
    }
    audit('product.deleted', 'product', $id);
    json_ok(['redirect' => '/admin/products.php'], 'Product deleted.');
}

/** The fields worth tracking in the activity log (product + each size). */
$snapshot = static function (int $id): array {
    $p = db_one('SELECT category_id, brand_id, slug, name, reg_no, zero_rate_eligible, is_featured, is_active, sort_order FROM products WHERE id = ?', [$id]);
    $v = [];
    foreach (db_all('SELECT id, name, sku, price_cents, special_price_cents, special_from, special_to, cost_cents, low_stock_threshold, is_active
                     FROM product_variants WHERE product_id = ?', [$id]) as $r) {
        $v[(int) $r['id']] = $r;
    }
    return [$p, $v];
};

$d = validate_or_fail($in, [
    'name' => 'required|max:191', 'slug' => 'nullable|max:150', 'category_id' => 'required|int|min:1', 'brand_id' => 'nullable|int',
    'short_description' => 'nullable|max:500', 'description_md' => 'nullable|max:60000', 'feeding_guide_md' => 'nullable|max:60000',
    'reg_no' => 'nullable|max:50', 'meta_title' => 'nullable|max:191', 'meta_description' => 'nullable|max:300', 'sort_order' => 'nullable|int',
]);
$id = (int) ($in['id'] ?? 0);
$slug = slugify($d['slug'] ?: $d['name']);
if (db_val('SELECT id FROM products WHERE slug = ? AND id <> ?', [$slug, $id])) {
    json_error(t('error.validation'), 422, ['slug' => 'Another product already uses this web address.']);
}
$variants = array_values(array_filter((array) ($in['variants'] ?? []), static fn($v) => is_array($v) && trim((string) ($v['name'] ?? '')) !== ''));
if (!$variants) {
    json_error('Add at least one size with a price.', 422);
}
[$beforeP, $beforeV] = $id ? $snapshot($id) : [null, []];

try {
    $id = db_tx(static function () use ($d, $in, $id, $slug, $variants, $admin): int {
        $row = [$d['category_id'], $d['brand_id'] ?: null, $slug, $d['name'], $d['short_description'], $d['description_md'], $d['feeding_guide_md'],
                $d['reg_no'], af_bool($in['zero_rate_eligible'] ?? 0), af_bool($in['is_featured'] ?? 0), af_bool($in['is_active'] ?? 0),
                $d['meta_title'], $d['meta_description'], (int) ($d['sort_order'] ?? 0)];
        if ($id) {
            db_exec('UPDATE products SET category_id=?, brand_id=?, slug=?, name=?, short_description=?, description_md=?, feeding_guide_md=?, reg_no=?,
                     zero_rate_eligible=?, is_featured=?, is_active=?, meta_title=?, meta_description=?, sort_order=? WHERE id = ?', array_merge($row, [$id]));
        } else {
            $id = db_insert('INSERT INTO products (category_id, brand_id, slug, name, short_description, description_md, feeding_guide_md, reg_no,
                     zero_rate_eligible, is_featured, is_active, meta_title, meta_description, sort_order) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', $row);
        }

        // Species
        db_exec('DELETE FROM product_species WHERE product_id = ?', [$id]);
        foreach (array_unique(array_map('intval', (array) ($in['species'] ?? []))) as $sid) {
            if ($sid > 0) {
                db_exec('INSERT IGNORE INTO product_species (product_id, species_id) VALUES (?, ?)', [$id, $sid]);
            }
        }

        // Variants (removed ones are hidden, never deleted — keeps sales history intact)
        $keep = [];
        foreach ($variants as $i => $v) {
            $price = af_cents($v['price'] ?? '');
            if ($price === null || $price <= 0) {
                throw new DomainException('Every size needs a price.');
            }
            $special = af_cents($v['special_price'] ?? '');
            $cost = af_cents($v['cost'] ?? '');
            $grams = (int) round(((float) str_replace(',', '.', (string) ($v['weight_kg'] ?? '0'))) * 1000);
            $sku = strtoupper(trim((string) ($v['sku'] ?? ''))) ?: 'GXF-' . $id . '-' . ($i + 1) . '-' . strtoupper(substr(random_token(2), 0, 3));
            if (!preg_match('/^[A-Z0-9._-]{2,60}$/', $sku)) {
                throw new DomainException('SKU "' . $sku . '" may only contain letters, numbers, dots, dashes and underscores.');
            }
            $vid = (int) ($v['id'] ?? 0);
            if (db_val('SELECT id FROM product_variants WHERE sku = ? AND id <> ?', [$sku, $vid])) {
                throw new DomainException('SKU ' . $sku . ' is already used by another product.');
            }
            $vals = [mb_substr(trim((string) $v['name']), 0, 100), $sku, $grams, $price, $special, af_date($v['special_from'] ?? ''), af_date($v['special_to'] ?? ''),
                     $cost, max(0, (int) ($v['low_stock_threshold'] ?? 5)), af_bool($v['is_active'] ?? 0), $i];
            if ($vid && db_val('SELECT 1 FROM product_variants WHERE id = ? AND product_id = ?', [$vid, $id])) {
                db_exec('UPDATE product_variants SET name=?, sku=?, weight_grams=?, price_cents=?, special_price_cents=?, special_from=?, special_to=?, cost_cents=?,
                         low_stock_threshold=?, is_active=?, sort_order=? WHERE id = ?', array_merge($vals, [$vid]));
            } else {
                $vid = db_insert('INSERT INTO product_variants (name, sku, weight_grams, price_cents, special_price_cents, special_from, special_to, cost_cents,
                         low_stock_threshold, is_active, sort_order, product_id, stock_qty) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,0)', array_merge($vals, [$id]));
                $opening = max(0, (int) ($v['opening_stock'] ?? 0));
                if ($opening > 0) {
                    StockService::adjustVariant($vid, $opening, 'initial', ['note' => 'Opening stock', 'admin_id' => (int) $admin['id'], 'unit_cost_cents' => $cost]);
                }
            }
            $keep[] = $vid;
            // Bulk tiers "10=469, 30=449"
            db_exec('DELETE FROM price_tiers WHERE variant_id = ?', [$vid]);
            foreach (array_filter(array_map('trim', explode(',', (string) ($v['tiers'] ?? '')))) as $pair) {
                if (!preg_match('/^(\d+)\s*=\s*R?\s*([\d\s.,]+)$/i', $pair, $m)) {
                    throw new DomainException('Bulk price "' . $pair . '" should look like 10=469.00');
                }
                $tp = to_cents($m[2]);
                if ((int) $m[1] < 2 || !$tp) {
                    throw new DomainException('Bulk price "' . $pair . '" needs a quantity of 2 or more and a price.');
                }
                db_exec('INSERT INTO price_tiers (variant_id, min_qty, price_cents) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE price_cents = VALUES(price_cents)', [$vid, (int) $m[1], $tp]);
            }
        }
        if ($keep) {
            db_exec('UPDATE product_variants SET is_active = 0 WHERE product_id = ? AND id NOT IN (' . db_placeholders($keep) . ')', array_merge([$id], $keep));
        }

        // Specs
        db_exec('DELETE FROM product_specs WHERE product_id = ?', [$id]);
        foreach (array_values((array) ($in['specs'] ?? [])) as $i => $s) {
            if (trim((string) ($s['label'] ?? '')) !== '') {
                db_exec('INSERT INTO product_specs (product_id, label, value, sort_order) VALUES (?,?,?,?)',
                    [$id, mb_substr(trim((string) $s['label']), 0, 100), mb_substr(trim((string) ($s['value'] ?? '')), 0, 100), $i]);
            }
        }
        // Photos (order = position)
        db_exec('DELETE FROM product_media WHERE product_id = ?', [$id]);
        foreach (array_values(array_unique(array_map('intval', (array) ($in['media_ids'] ?? [])))) as $i => $mid) {
            if ($mid > 0 && db_val('SELECT 1 FROM media WHERE id = ?', [$mid])) {
                db_exec('INSERT INTO product_media (product_id, media_id, sort_order) VALUES (?,?,?)', [$id, $mid, $i]);
            }
        }
        // Calculator rates
        db_exec('DELETE FROM calculator_rules WHERE product_id = ?', [$id]);
        foreach ((array) ($in['calc'] ?? []) as $c) {
            if ((int) ($c['species_id'] ?? 0) > 0 && trim((string) ($c['animal_class'] ?? '')) !== '' && (int) ($c['grams'] ?? 0) > 0) {
                db_exec('INSERT IGNORE INTO calculator_rules (product_id, species_id, animal_class, intake_grams_per_day, notes) VALUES (?,?,?,?,?)',
                    [$id, (int) $c['species_id'], mb_substr(trim((string) $c['animal_class']), 0, 100), (int) $c['grams'], mb_substr((string) ($c['notes'] ?? ''), 0, 255) ?: null]);
            }
        }
        return $id;
    });
} catch (DomainException $e) {
    json_error($e->getMessage(), 422);
}
// Activity log: what changed (prices especially), not just "saved"
[$afterP, $afterV] = $snapshot($id);
$sizes = [];
foreach (array_keys($beforeV + $afterV) as $vid) {
    if ($diff = audit_diff($beforeV[$vid] ?? null, $afterV[$vid] ?? null, ['id'])) {
        $sizes[$vid] = $diff;
    }
}
$changes = array_filter(['product' => audit_diff($beforeP, $afterP), 'sizes' => $sizes]);
audit($beforeP ? 'product.saved' : 'product.created', 'product', $id, ['name' => $d['name']] + $changes);
json_ok(['redirect' => '/admin/product.php?id=' . $id], 'Product saved.');
