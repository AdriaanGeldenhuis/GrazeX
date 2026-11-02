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
        media_prime(array_column($items, 'media_id'));

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

    /** Email everyone whose (confirmed) alert matches a newly published lot. Returns the number of emails queued. */
    public static function notifySubscribers(int $lotId): int
    {
        $l = db_one('SELECT l.*, b.name AS breed_name FROM lots l JOIN breeds b ON b.id = l.breed_id WHERE l.id = ?', [$lotId]);
        if (!$l || !in_array($l['status'], ['available', 'coming_soon'], true)) {
            throw new DomainException('Only available or coming-soon lots can be announced.');
        }
        // Only confirmed sign-ups (double opt-in) — an unconfirmed address never gets alerts
        $subs = db_all('SELECT * FROM livestock_alerts WHERE is_active = 1 AND confirmed_at IS NOT NULL
                        AND (breed_id IS NULL OR breed_id = ?) AND (category_id IS NULL OR category_id = ?)
                        AND (last_notified_at IS NULL OR last_notified_at < NOW() - INTERVAL 6 HOUR) ORDER BY id', [$l['breed_id'], $l['category_id']]);
        $byEmail = [];
        foreach ($subs as $s) {
            $byEmail[mb_strtolower($s['email'])][] = $s;
        }
        $url = app_url('/ranch/lot.php?slug=' . rawurlencode($l['slug']));
        $n = 0;
        foreach ($byEmail as $rows) {
            $s = $rows[0];
            $qid = NotificationService::email('livestock_alert', $s['email'], null, ['lot_title' => $l['title'], 'link' => $url],
                division_id('ranch'), [], ['label' => 'View the lot', 'url' => $url], false);
            if (!$qid) {
                continue;
            }
            // Unsubscribe (POPIA): a link in the email, plus one-click headers for the mail provider (RFC 8058)
            $page = app_url('/ranch/alerts.php?unsubscribe=' . $s['unsubscribe_token']);
            $oneClick = app_url('/ranch/api/alerts.php?unsubscribe=' . $s['unsubscribe_token']);
            db_exec('UPDATE email_queue SET body_html = REPLACE(body_html, \'</body>\', ?), list_unsubscribe = ? WHERE id = ?',
                ['<p style="text-align:center;font-size:12px;color:#7f8781;font-family:Arial">Don\'t want these alerts? <a href="' . e($page) . '" style="color:#9ea59f">Unsubscribe</a></p></body>',
                 $oneClick, $qid]);
            // Every matching alert of this address counts as notified (one email covers them all)
            $ids = array_map(static fn($r) => (int) $r['id'], $rows);
            db_exec('UPDATE livestock_alerts SET last_notified_at = NOW() WHERE id IN (' . db_placeholders($ids) . ')', $ids);
            $n++;
        }
        return $n;
    }

    /**
     * Sign an email address up for alerts. $confirmed: the address is proven (a logged-in customer's verified email),
     * so the alert is live at once; otherwise a confirmation link is emailed first (double opt-in, POPIA).
     * An unproven request only ever touches the requester's own rows (a guest's, or this account's) — never
     * someone else's alert for the same address. Returns true when the alert is live now.
     */
    public static function subscribe(string $email, ?int $customerId, ?int $breedId, ?int $categoryId, bool $confirmed): bool
    {
        if ($confirmed) {
            $row = db_one('SELECT * FROM livestock_alerts WHERE email = ? AND breed_id <=> ? AND category_id <=> ?
                           ORDER BY confirmed_at IS NULL, id DESC LIMIT 1', [$email, $breedId, $categoryId]);
            if ($row && $row['confirmed_at'] && (int) $row['is_active'] === 1) {
                return true; // already signed up
            }
            if ($row) {
                db_exec('UPDATE livestock_alerts SET is_active = 1, confirmed_at = COALESCE(confirmed_at, NOW()), confirm_token = NULL,
                         customer_id = COALESCE(customer_id, ?) WHERE id = ?', [$customerId, $row['id']]);
            } else {
                db_exec('INSERT INTO livestock_alerts (customer_id, email, breed_id, category_id, confirmed_at, unsubscribe_token) VALUES (?, ?, ?, ?, NOW(), ?)',
                    [$customerId, $email, $breedId, $categoryId, random_token(32)]);
            }
            return true;
        }
        $row = db_one('SELECT * FROM livestock_alerts WHERE email = ? AND breed_id <=> ? AND category_id <=> ? AND customer_id <=> ? ORDER BY id DESC LIMIT 1',
            [$email, $breedId, $categoryId, $customerId]);
        if ($row && $row['confirmed_at'] && (int) $row['is_active'] === 1) {
            return true; // this requester's own alert is already on
        }
        if (!$row) {
            // Stored with a placeholder token until a confirmation email actually goes out (never-confirmed rows expire)
            $id = db_insert('INSERT INTO livestock_alerts (customer_id, email, breed_id, category_id, confirm_token, unsubscribe_token) VALUES (?, ?, ?, ?, ?, ?)',
                [$customerId, $email, $breedId, $categoryId, token_hash(random_token(32)), random_token(32)]);
            $row = db_one('SELECT * FROM livestock_alerts WHERE id = ?', [$id]);
        }
        self::requestConfirmation($row);
        return false;
    }

    /**
     * Email the "confirm your alert" link for an alert row. Limited to 3 a day per address and 40 an hour for the
     * whole site (the form can't flood an inbox or use up the shop's email allowance). The link is only replaced
     * when an email really goes out, so a pending link keeps working. Returns true when an email was queued.
     */
    public static function requestConfirmation(array $row, string $siteBucket = 'alert_confirm_site', int $siteMax = 40): bool
    {
        // The site-wide cap first: a refused site hit uses up nobody's own daily allowance
        if (!rate_limit_hit($siteBucket, 'all', $siteMax, 3600)) {
            gx_log('warning', 'Livestock alert confirmations paused: hourly cap reached', ['bucket' => $siteBucket]);
            return false;
        }
        if (!rate_limit_hit('alert_confirm', (string) $row['email'], 3, 86400)) {
            return false;
        }
        $token = random_token(32);
        db_exec('UPDATE livestock_alerts SET confirm_token = ?, confirm_sent_at = NOW() WHERE id = ?', [token_hash($token), $row['id']]);
        $what = implode(' · ', [
            $row['breed_id'] ? (string) db_val('SELECT name FROM breeds WHERE id = ?', [$row['breed_id']]) : 'Any breed',
            $row['category_id'] ? (string) db_val('SELECT name FROM livestock_categories WHERE id = ?', [$row['category_id']]) : 'any type',
        ]);
        $link = app_url('/ranch/alerts.php?confirm=' . $token);
        $qid = NotificationService::email('livestock_alert_confirm', (string) $row['email'], null, ['alert' => $what, 'link' => $link], division_id('ranch'), [],
            ['label' => 'Confirm my alert', 'url' => $link], false);
        if ($qid) {
            // Sent after the answer: the reply takes the same time whether or not an email goes out
            gx_after_response('Alert confirmation email', static fn() => MailService::sendNow($qid));
        }
        return true;
    }

    /** Confirm an alert from the emailed link. Returns the alert's email, or null when the link is not valid. */
    public static function confirmAlert(string $token): ?string
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return null;
        }
        // Links sent before the site key changed keep working (same as account links)
        $hashes = array_map(static fn(string $k) => hash_hmac('sha256', $token, $k), gx_key_ring());
        $row = db_one('SELECT id, email FROM livestock_alerts WHERE confirm_token IN (' . db_placeholders($hashes) . ') LIMIT 1', $hashes);
        if (!$row) {
            return null;
        }
        db_exec('UPDATE livestock_alerts SET confirmed_at = NOW(), is_active = 1, confirm_token = NULL WHERE id = ?', [$row['id']]);
        return (string) $row['email'];
    }

    /** Stop every alert for the address behind an unsubscribe token. Returns how many were removed or switched off. */
    public static function unsubscribe(string $token): int
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return 0;
        }
        $email = db_val('SELECT email FROM livestock_alerts WHERE unsubscribe_token = ?', [$token]);
        if ($email === null) {
            return 0;
        }
        // Deleted (POPIA: nothing kept once consent is withdrawn): guest sign-ups, never-confirmed ones, and alerts of
        // accounts that never proved they own this address. Only a verified account's own alerts are switched off,
        // so that customer can switch them on again in their account.
        return db_exec('DELETE a FROM livestock_alerts a LEFT JOIN customers c ON c.id = a.customer_id
                        WHERE a.email = ? AND (a.customer_id IS NULL OR a.confirmed_at IS NULL OR c.email_verified_at IS NULL OR c.email <> a.email)', [$email])
             + db_exec('UPDATE livestock_alerts SET is_active = 0, confirm_token = NULL WHERE email = ? AND is_active = 1', [$email]);
    }
}
