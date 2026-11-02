<?php
/**
 * Feeds catalogue queries (products, variants, categories, species).
 */
declare(strict_types=1);

defined('GX_ROOT') || exit;

final class CatalogService
{
    public const SORTS = [
        'featured'   => 'Featured',
        'price_asc'  => 'Price: low to high',
        'price_desc' => 'Price: high to low',
        'name'       => 'Name A–Z',
        'newest'     => 'Newest',
    ];

    /** SQL for a variant's effective (special-aware) price. */
    private const EFFECTIVE_PRICE = "CASE WHEN v.special_price_cents IS NOT NULL AND v.special_price_cents < v.price_cents
        AND (v.special_from IS NULL OR v.special_from <= NOW()) AND (v.special_to IS NULL OR v.special_to >= NOW())
        THEN v.special_price_cents ELSE v.price_cents END";

    /**
     * Product list with filters. Returns ['items' => [...], 'total' => int, 'page' => int, 'pages' => int].
     * Filters: q, category (slug), species (slug), brand (slug), specials (bool), in_stock (bool), sort, page, per_page.
     */
    public static function products(array $f = []): array
    {
        $where = ['p.is_active = 1'];
        $params = [];
        if (!empty($f['category'])) {
            $where[] = '(c.slug = ? OR c.parent_id = (SELECT id FROM categories WHERE slug = ?))';
            array_push($params, $f['category'], $f['category']);
        }
        if (!empty($f['species'])) {
            $where[] = 'EXISTS (SELECT 1 FROM product_species ps JOIN species s ON s.id = ps.species_id WHERE ps.product_id = p.id AND s.slug = ?)';
            $params[] = $f['species'];
        }
        if (!empty($f['brand'])) {
            $where[] = 'b.slug = ?';
            $params[] = $f['brand'];
        }
        if (!empty($f['q'])) {
            $q = '%' . str_replace(['%', '_'], ['\%', '\_'], mb_substr((string) $f['q'], 0, 80)) . '%';
            $where[] = '(p.name LIKE ? OR p.short_description LIKE ? OR c.name LIKE ? OR b.name LIKE ?
                         OR EXISTS (SELECT 1 FROM product_variants vx WHERE vx.product_id = p.id AND vx.sku LIKE ?))';
            array_push($params, $q, $q, $q, $q, $q);
        }
        $having = ['active_variants > 0'];
        if (!empty($f['specials'])) {
            $having[] = 'on_special = 1';
        }
        if (!empty($f['in_stock'])) {
            $having[] = 'stock_total > 0';
        }

        $order = match ($f['sort'] ?? 'featured') {
            'price_asc'  => 'min_price ASC',
            'price_desc' => 'min_price DESC',
            'name'       => 'p.name ASC',
            'newest'     => 'p.created_at DESC',
            default      => 'p.is_featured DESC, (COALESCE(SUM(GREATEST(v.stock_qty,0)),0) > 0) DESC, p.sort_order ASC, p.name ASC',
        };

        $perPage = max(1, min(48, (int) ($f['per_page'] ?? 12)));
        $page = max(1, min(10000, (int) ($f['page'] ?? 1)));
        $eff = self::EFFECTIVE_PRICE;

        $base = "FROM products p
            JOIN categories c ON c.id = p.category_id
            LEFT JOIN brands b ON b.id = p.brand_id
            LEFT JOIN product_variants v ON v.product_id = p.id AND v.is_active = 1
            WHERE " . implode(' AND ', $where) . "
            GROUP BY p.id
            HAVING " . implode(' AND ', $having);

        $total = (int) db_val("SELECT COUNT(*) FROM (SELECT p.id, COUNT(v.id) AS active_variants, COALESCE(SUM(GREATEST(v.stock_qty,0)),0) AS stock_total,
            MAX(CASE WHEN ({$eff}) < v.price_cents THEN 1 ELSE 0 END) AS on_special {$base}) t", $params);

        $items = db_all("SELECT p.id, p.slug, p.name, p.short_description, p.is_featured, p.created_at,
                c.name AS category_name, c.slug AS category_slug, b.name AS brand_name,
                COUNT(v.id) AS active_variants,
                MIN({$eff}) AS min_price,
                MIN(v.price_cents) AS min_regular,
                COALESCE(SUM(GREATEST(v.stock_qty,0)),0) AS stock_total,
                MAX(CASE WHEN ({$eff}) < v.price_cents THEN 1 ELSE 0 END) AS on_special,
                MIN(v.low_stock_threshold) AS low_threshold,
                (SELECT pm.media_id FROM product_media pm WHERE pm.product_id = p.id ORDER BY pm.sort_order, pm.id LIMIT 1) AS media_id,
                (SELECT MIN(pt.price_cents) FROM price_tiers pt JOIN product_variants v2 ON v2.id = pt.variant_id WHERE v2.product_id = p.id AND v2.is_active = 1) AS tier_from,
                (SELECT v3.id FROM product_variants v3 WHERE v3.product_id = p.id AND v3.is_active = 1 ORDER BY v3.stock_qty > 0 DESC, v3.sort_order, v3.id LIMIT 1) AS default_variant_id
            {$base}
            ORDER BY {$order}
            LIMIT {$perPage} OFFSET " . (($page - 1) * $perPage), $params);
        media_prime(array_column($items, 'media_id'));

        return ['items' => $items, 'total' => $total, 'page' => $page, 'pages' => max(1, (int) ceil($total / $perPage))];
    }

    /** One product by slug with variants, tiers, specs, media, species. */
    public static function product(string $slug): ?array
    {
        $p = db_one('SELECT p.*, c.name AS category_name, c.slug AS category_slug, b.name AS brand_name, b.slug AS brand_slug
                     FROM products p JOIN categories c ON c.id = p.category_id LEFT JOIN brands b ON b.id = p.brand_id
                     WHERE p.slug = ? AND p.is_active = 1', [$slug]);
        if (!$p) {
            return null;
        }
        $p['variants'] = db_all('SELECT * FROM product_variants WHERE product_id = ? AND is_active = 1 ORDER BY sort_order, id', [$p['id']]);
        foreach ($p['variants'] as &$v) {
            $v['tiers'] = PricingService::tiers((int) $v['id']);
            $v['effective'] = PricingService::basePrice($v);
            $v['on_special'] = PricingService::specialActive($v);
        }
        unset($v);
        $p['specs'] = db_all('SELECT label, value FROM product_specs WHERE product_id = ? ORDER BY sort_order, id', [$p['id']]);
        $p['media'] = db_col('SELECT media_id FROM product_media WHERE product_id = ? ORDER BY sort_order, id', [$p['id']]);
        $p['species'] = db_all('SELECT s.slug, s.name FROM product_species ps JOIN species s ON s.id = ps.species_id WHERE ps.product_id = ? ORDER BY s.sort_order', [$p['id']]);
        return $p;
    }

    public static function related(array $product, int $limit = 4): array
    {
        $r = self::products(['category' => $product['category_slug'], 'per_page' => $limit + 1, 'in_stock' => true]);
        return array_slice(array_values(array_filter($r['items'], static fn($i) => (int) $i['id'] !== (int) $product['id'])), 0, $limit);
    }

    /** Top-level categories with active product counts. */
    public static function categories(): array
    {
        return db_all('SELECT c.id, c.slug, c.name, c.description, c.icon, c.media_id,
                (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.is_active = 1) AS product_count
            FROM categories c WHERE c.is_active = 1 AND c.parent_id IS NULL ORDER BY c.sort_order, c.name');
    }

    public static function species(): array
    {
        return db_all('SELECT s.slug, s.name, (SELECT COUNT(*) FROM product_species ps JOIN products p ON p.id = ps.product_id
                        WHERE ps.species_id = s.id AND p.is_active = 1) AS product_count
                       FROM species s ORDER BY s.sort_order');
    }

    /** Lightweight search suggestions. */
    public static function suggest(string $q, int $limit = 6): array
    {
        $r = self::products(['q' => $q, 'per_page' => $limit]);
        return array_map(static fn($i) => [
            'name' => $i['name'], 'url' => '/feeds/product.php?slug=' . rawurlencode($i['slug']),
            'category' => $i['category_name'], 'price' => money((int) $i['min_price']),
            'image' => $i['media_id'] ? media_url((int) $i['media_id'], 480, 'orig') : null,
            'in_stock' => (int) $i['stock_total'] > 0,
        ], $r['items']);
    }
}
