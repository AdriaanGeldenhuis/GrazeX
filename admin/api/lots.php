<?php
/**
 * Admin livestock API: {action: save, ...} · {action: notify, id}
 */
declare(strict_types=1);

define('GX_IS_API', true);
require __DIR__ . '/../../includes/bootstrap.php';
require_once GX_ROOT . '/includes/admin_form.php';

api_post();
$admin = require_admin_api('lots.manage');
$in = input_all();

if (($in['action'] ?? '') === 'notify') {
    rate_limit_or_fail('lot_notify', 'l' . (int) ($in['id'] ?? 0), 3, 3600);
    try {
        $n = LivestockService::notifySubscribers((int) ($in['id'] ?? 0));
    } catch (DomainException $e) {
        json_error($e->getMessage(), 409);
    }
    audit('lot.notified', 'lot', (int) $in['id'], ['count' => $n]);
    json_ok([], $n ? "Alert queued for {$n} subscriber" . ($n === 1 ? '' : 's') . '.' : 'No one to notify right now (or they were notified recently).');
}

$d = validate_or_fail($in, [
    'title' => 'required|max:191', 'lot_no' => 'required|max:30|regex:/^[A-Za-z0-9._-]+$/', 'slug' => 'nullable|max:150',
    'breed_id' => 'required|int|min:1', 'category_id' => 'required|int|min:1', 'sex' => 'required|in:mixed,ewe,ram,wether',
    'age_months_min' => 'nullable|int|min:0|max:240', 'age_months_max' => 'nullable|int|min:0|max:240', 'tag_no' => 'nullable|max:50',
    'qty_total' => 'required|int|min:1|max:100000', 'avg_weight_kg' => 'nullable|numeric|min:0|max:2000',
    'pricing_mode' => 'required|in:per_head,per_kg', 'price' => 'required|money', 'deposit_pct' => 'required|int|min:0|max:100',
    'status' => 'required|in:draft,coming_soon,available,reserved,sold_out,archived', 'available_from' => 'nullable|date', 'location' => 'nullable|max:150',
    'short_description' => 'nullable|max:500', 'description_md' => 'nullable|max:60000', 'health_summary' => 'nullable|max:2000',
    'video_url' => 'nullable|url|max:255', 'meta_title' => 'nullable|max:191', 'meta_description' => 'nullable|max:300',
]);
if ($d['pricing_mode'] === 'per_kg' && (float) ($d['avg_weight_kg'] ?? 0) <= 0) {
    json_error(t('error.validation'), 422, ['avg_weight_kg' => 'Enter the average live weight — per-kg lots are priced and the deposit is worked out from it.']);
}
$id = (int) ($in['id'] ?? 0);
$slug = slugify($d['slug'] ?: $d['title'] . ' ' . $d['lot_no']);
if (db_val('SELECT id FROM lots WHERE slug = ? AND id <> ?', [$slug, $id])) {
    $slug .= '-' . strtolower(substr(random_token(2), 0, 4));
}
if (db_val('SELECT id FROM lots WHERE lot_no = ? AND id <> ?', [strtoupper($d['lot_no']), $id])) {
    json_error(t('error.validation'), 422, ['lot_no' => 'That lot number is already used.']);
}
try {
    $cost = af_cents($in['cost'] ?? '');
} catch (DomainException $e) {
    json_error($e->getMessage(), 422);
}

$id = db_tx(static function () use ($d, $in, $id, $slug, $cost, $admin): int {
    $existing = $id ? db_one('SELECT * FROM lots WHERE id = ? FOR UPDATE', [$id]) : null;
    $vals = [strtoupper($d['lot_no']), $slug, $d['title'], $d['breed_id'], $d['category_id'], $d['sex'], $d['age_months_min'], $d['age_months_max'],
             $d['tag_no'], $d['qty_total'], $d['avg_weight_kg'], $d['pricing_mode'], $d['price'], $d['deposit_pct'], $cost, $d['available_from'],
             $d['location'], $d['short_description'], $d['description_md'], $d['health_summary'], $d['video_url'], $d['status'],
             af_bool($in['is_featured'] ?? 0), $d['meta_title'], $d['meta_description']];
    if ($existing) {
        db_exec('UPDATE lots SET lot_no=?, slug=?, title=?, breed_id=?, category_id=?, sex=?, age_months_min=?, age_months_max=?, tag_no=?, qty_total=?,
                 avg_weight_kg=?, pricing_mode=?, price_cents=?, deposit_pct=?, cost_cents=?, available_from=?, location=?, short_description=?, description_md=?,
                 health_summary=?, video_url=?, status=?, is_featured=?, meta_title=?, meta_description=?,
                 published_at = IF(published_at IS NULL AND ? IN (\'available\',\'coming_soon\'), NOW(), published_at) WHERE id = ?',
            array_merge($vals, [$d['status'], $id]));
    } else {
        $id = db_insert('INSERT INTO lots (lot_no, slug, title, breed_id, category_id, sex, age_months_min, age_months_max, tag_no, qty_total, avg_weight_kg,
                 pricing_mode, price_cents, deposit_pct, cost_cents, available_from, location, short_description, description_md, health_summary, video_url,
                 status, is_featured, meta_title, meta_description, qty_available, published_at)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,0, IF(? IN (\'available\',\'coming_soon\'), NOW(), NULL))', array_merge($vals, [$d['status']]));
        StockService::adjustLot($id, (int) $d['qty_total'], 'initial', ['note' => 'New lot', 'admin_id' => (int) $admin['id']]);
        if (in_array($d['status'], ['available', 'reserved'], true)) {
            db_exec('UPDATE lots SET status = ? WHERE id = ?', [$d['status'], $id]);
        }
    }

    // Health records
    db_exec('DELETE FROM lot_health_records WHERE lot_id = ?', [$id]);
    foreach ((array) ($in['health'] ?? []) as $h) {
        $date = af_date($h['record_date'] ?? '');
        if (!$date) {
            continue;
        }
        db_exec('INSERT INTO lot_health_records (lot_id, record_date, type, product_used, notes, withdrawal_until) VALUES (?,?,?,?,?,?)', [
            $id, $date, in_array($h['type'] ?? '', ['vaccination', 'dosing', 'treatment', 'inspection', 'other'], true) ? $h['type'] : 'other',
            mb_substr((string) ($h['product_used'] ?? ''), 0, 150) ?: null, mb_substr((string) ($h['notes'] ?? ''), 0, 500) ?: null, af_date($h['withdrawal_until'] ?? ''),
        ]);
    }
    // Weighings (latest updates the lot's average weight)
    db_exec('DELETE FROM weigh_sessions WHERE lot_id = ?', [$id]);
    $latest = null;
    foreach ((array) ($in['weighs'] ?? []) as $w) {
        $date = af_date($w['weighed_on'] ?? '');
        $avg = (float) str_replace(',', '.', (string) ($w['avg_weight_kg'] ?? '0'));
        if (!$date || $avg <= 0) {
            continue;
        }
        $head = max(1, (int) ($w['head_count'] ?? 1));
        db_exec('INSERT INTO weigh_sessions (lot_id, weighed_on, head_count, avg_weight_kg, total_weight_kg, notes) VALUES (?,?,?,?,?,?)',
            [$id, $date, $head, $avg, round($avg * $head, 2), mb_substr((string) ($w['notes'] ?? ''), 0, 255) ?: null]);
        if ($latest === null || $date >= $latest[0]) {
            $latest = [$date, $avg];
        }
    }
    if ($latest) {
        db_exec('UPDATE lots SET avg_weight_kg = ? WHERE id = ?', [$latest[1], $id]);
    }
    // Photos
    db_exec('DELETE FROM lot_media WHERE lot_id = ?', [$id]);
    foreach (array_values(array_unique(array_map('intval', (array) ($in['media_ids'] ?? [])))) as $i => $mid) {
        if ($mid > 0 && db_val('SELECT 1 FROM media WHERE id = ?', [$mid])) {
            db_exec('INSERT INTO lot_media (lot_id, media_id, sort_order) VALUES (?,?,?)', [$id, $mid, $i]);
        }
    }
    return $id;
});
audit('lot.saved', 'lot', $id, ['title' => $d['title']]);
json_ok(['redirect' => '/admin/lot.php?id=' . $id], 'Lot saved.');
