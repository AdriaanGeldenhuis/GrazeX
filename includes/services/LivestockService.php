<?php
/**
 * Ranch (livestock lot) queries.
 */
declare(strict_types=1);

defined('GX_ROOT') || exit;

final class LivestockService
{
    public const SORTS = [
        'featured'   => 'Featured',
        'newest'     => 'Newest',
        'price_asc'  => 'Price: low to high',
        'price_desc' => 'Price: high to low',
        'weight'     => 'Heaviest first',
    ];

    public const STATUS_LABELS = [
        'available'   => ['Available', 'badge-success'],
        'reserved'    => ['Few left', 'badge-warning'],
        'coming_soon' => ['Coming soon', 'badge-info'],
        'sold_out'    => ['Sold', 'badge-danger'],
    ];

    /**
     * Filters: breed, category (slugs), sex, mode (per_head|per_kg), status ('now' | 'soon' | 'all'), sort, page, per_page.
     */
    public static function lots(array $f = []): array
    {
        $status = $f['status'] ?? 'now';
        $where = match ($status) {
            'soon' => ["l.status = 'coming_soon'"],
            'all'  => ["l.status IN ('available','reserved','coming_soon','sold_out')"],
            default => ["l.status IN ('available','reserved')", 'l.qty_available > 0'],
        };
        $params = [];
        if (!empty($f['breed'])) {
            $where[] = 'b.slug = ?';
            $params[] = $f['breed'];
        }
        if (!empty($f['category'])) {
            $where[] = 'c.slug = ?';
            $params[] = $f['category'];
        }
        if (!empty($f['sex']) && in_array($f['sex'], ['ewe', 'ram', 'wether', 'mixed'], true)) {
            $where[] = 'l.sex = ?';
            $params[] = $f['sex'];
        }
        if (!empty($f['mode']) && in_array($f['mode'], ['per_head', 'per_kg'], true)) {
            $where[] = 'l.pricing_mode = ?';
            $params[] = $f['mode'];
        }
        // Comparable per-head price for sorting (per kg × avg weight)
        $headPrice = "CASE WHEN l.pricing_mode = 'per_kg' THEN l.price_cents * COALESCE(l.avg_weight_kg, 0) ELSE l.price_cents END";
        $order = match ($f['sort'] ?? 'featured') {
            'newest'     => 'l.published_at DESC, l.id DESC',
            'price_asc'  => "{$headPrice} ASC",
            'price_desc' => "{$headPrice} DESC",
            'weight'     => 'l.avg_weight_kg DESC',
            default      => "l.is_featured DESC, FIELD(l.status, 'available', 'reserved', 'coming_soon', 'sold_out'), l.available_from IS NULL, l.available_from, l.id DESC",
        };
        $perPage = max(1, min(48, (int) ($f['per_page'] ?? 12)));
        $page = max(1, min(10000, (int) ($f['page'] ?? 1)));
        $sqlWhere = implode(' AND ', $where);

        $total = (int) db_val("SELECT COUNT(*) FROM lots l JOIN breeds b ON b.id = l.breed_id JOIN livestock_categories c ON c.id = l.category_id WHERE {$sqlWhere}", $params);
        $items = db_all("SELECT l.*, b.name AS breed_name, b.slug AS breed_slug, c.name AS category_name, c.slug AS category_slug,
                (SELECT lm.media_id FROM lot_media lm WHERE lm.lot_id = l.id ORDER BY lm.sort_order, lm.id LIMIT 1) AS media_id,
                (SELECT MAX(ws.weighed_on) FROM weigh_sessions ws WHERE ws.lot_id = l.id) AS last_weighed
            FROM lots l JOIN breeds b ON b.id = l.breed_id JOIN livestock_categories c ON c.id = l.category_id
            WHERE {$sqlWhere} ORDER BY {$order} LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage), $params);

        return ['items' => $items, 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $perPage))];
    }

    public static function lot(string $slug): ?array
    {
        $l = db_one("SELECT l.*, b.name AS breed_name, b.slug AS breed_slug, b.description AS breed_description,
                            c.name AS category_name, c.slug AS category_slug
                     FROM lots l JOIN breeds b ON b.id = l.breed_id JOIN livestock_categories c ON c.id = l.category_id
                     WHERE l.slug = ? AND l.status IN ('available','reserved','coming_soon','sold_out')", [$slug]);
        if (!$l) {
            return null;
        }
        $l['media'] = db_col('SELECT media_id FROM lot_media WHERE lot_id = ? ORDER BY sort_order, id', [$l['id']]);
        $l['health'] = db_all('SELECT * FROM lot_health_records WHERE lot_id = ? ORDER BY record_date DESC, id DESC', [$l['id']]);
        $l['weighs'] = db_all('SELECT weighed_on, head_count, avg_weight_kg FROM weigh_sessions WHERE lot_id = ? ORDER BY weighed_on, id', [$l['id']]);
        return $l;
    }

    public static function breeds(): array
    {
        return db_all("SELECT b.slug, b.name, (SELECT COUNT(*) FROM lots l WHERE l.breed_id = b.id AND l.status IN ('available','reserved') AND l.qty_available > 0) AS lot_count
                       FROM breeds b WHERE b.is_active = 1 ORDER BY b.sort_order, b.name");
    }

    public static function categories(): array
    {
        return db_all("SELECT c.id, c.slug, c.name, (SELECT COUNT(*) FROM lots l WHERE l.category_id = c.id AND l.status IN ('available','reserved') AND l.qty_available > 0) AS lot_count
                       FROM livestock_categories c ORDER BY c.sort_order, c.name");
    }

    /** Status badge [label, class] for a lot (low quantity shows "Few left"). */
    public static function badge(array $lot): array
    {
        if ($lot['status'] === 'available' && (int) $lot['qty_available'] > 0 && (int) $lot['qty_available'] <= max(2, (int) round((int) $lot['qty_total'] * 0.15))) {
            return ['Only ' . (int) $lot['qty_available'] . ' left', 'badge-warning'];
        }
        if (in_array($lot['status'], ['available', 'reserved'], true) && (int) $lot['qty_available'] <= 0) {
            return self::STATUS_LABELS['sold_out'];
        }
        return self::STATUS_LABELS[$lot['status']] ?? ['—', ''];
    }

    /** Human price line for a lot. */
    public static function priceLabel(array $lot): array
    {
        if ($lot['pricing_mode'] === 'per_kg') {
            $est = $lot['avg_weight_kg'] ? (int) round((float) $lot['avg_weight_kg'] * (int) $lot['price_cents']) : null;
            return ['main' => money((int) $lot['price_cents']), 'unit' => '/kg live', 'note' => $est ? '≈ ' . money($est, false) . ' per head' : null];
        }
        return ['main' => money((int) $lot['price_cents'], false), 'unit' => '/head', 'note' => null];
    }

    public static function sexLabel(string $sex): string
    {
        return ['ewe' => 'Ewes', 'ram' => 'Rams', 'wether' => 'Wethers', 'mixed' => 'Mixed'][$sex] ?? ucfirst($sex);
    }

    public static function ageLabel(array $lot): ?string
    {
        $min = $lot['age_months_min'];
        $max = $lot['age_months_max'];
        if ($min === null && $max === null) {
            return null;
        }
        if ($min !== null && $max !== null && (int) $min !== (int) $max) {
            return (int) $min . '–' . (int) $max . ' months';
        }
        return (int) ($min ?? $max) . ' months';
    }

    /** Email everyone whose alert matches a newly published lot. Returns the number queued. */
    public static function notifySubscribers(int $lotId): int
    {
        $l = db_one('SELECT l.*, b.name AS breed_name FROM lots l JOIN breeds b ON b.id = l.breed_id WHERE l.id = ?', [$lotId]);
        if (!$l || !in_array($l['status'], ['available', 'coming_soon'], true)) {
            throw new DomainException('Only available or coming-soon lots can be announced.');
        }
        $subs = db_all('SELECT * FROM livestock_alerts WHERE is_active = 1 AND (breed_id IS NULL OR breed_id = ?) AND (category_id IS NULL OR category_id = ?)
                        AND (last_notified_at IS NULL OR last_notified_at < NOW() - INTERVAL 6 HOUR)', [$l['breed_id'], $l['category_id']]);
        $sent = [];
        $url = app_url('/ranch/lot.php?slug=' . rawurlencode($l['slug']));
        foreach ($subs as $s) {
            if (isset($sent[$s['email']])) {
                continue;
            }
            $sent[$s['email']] = true;
            NotificationService::email('livestock_alert', $s['email'], null, ['lot_title' => $l['title'], 'link' => $url],
                division_id('ranch'), [], ['label' => 'View the lot', 'url' => $url], false);
            // Unsubscribe line (POPIA): appended to the queued email
            $qid = (int) db_val('SELECT MAX(id) FROM email_queue WHERE to_email = ?', [$s['email']]);
            $unsub = app_url('/ranch/api/alerts.php?unsubscribe=' . $s['unsubscribe_token']);
            db_exec('UPDATE email_queue SET body_html = REPLACE(body_html, \'</body>\', ?) WHERE id = ?',
                ['<p style="text-align:center;font-size:12px;color:#7f8781;font-family:Arial">Don\'t want these alerts? <a href="' . e($unsub) . '" style="color:#9ea59f">Unsubscribe</a></p></body>', $qid]);
            db_exec('UPDATE livestock_alerts SET last_notified_at = NOW() WHERE id = ?', [$s['id']]);
        }
        return count($sent);
    }
}
