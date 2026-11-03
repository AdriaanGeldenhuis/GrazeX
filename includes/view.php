<?php
/**
 * View helpers shared by all areas: images, partials, prices, badges.
 */
declare(strict_types=1);

defined('GX_ROOT') || exit;

/** Render a partial from includes/partials with local variables. */
function partial(string $name, array $vars = []): string
{
    $file = GX_ROOT . '/includes/partials/' . basename($name) . '.php';
    if (!is_file($file)) {
        throw new RuntimeException('Partial not found: ' . $name);
    }
    extract($vars, EXTR_SKIP);
    ob_start();
    require $file;
    return (string) ob_get_clean();
}

/**
 * Responsive <img> for a media item, or a branded placeholder.
 * $ratio: 'wide' (16:9 focal crop) or 'orig'.
 */
function media_img(?int $mediaId, string $alt = '', string $sizes = '(min-width: 1024px) 33vw, 100vw', string $ratio = 'wide', bool $lazy = true, string $placeholderIcon = 'leaf'): string
{
    if ($mediaId) {
        $src = media_url($mediaId, 960, $ratio);
        if ($src) {
            $srcset = media_srcset($mediaId, $ratio);
            return '<img src="' . e($src) . '"' . ($srcset ? ' srcset="' . e($srcset) . '" sizes="' . e($sizes) . '"' : '')
                . ' alt="' . e($alt) . '"' . ($lazy ? ' loading="lazy" decoding="async"' : ' fetchpriority="high"') . '>';
        }
    }
    // Branded placeholder until a photo is uploaded: division logo watermark + subtle label
    $brand = in_array($placeholderIcon, ['sheep'], true) ? 'ranch' : 'feeds';
    return '<div class="media-placeholder mp-' . $brand . '" role="img" aria-label="' . e($alt) . '">'
        . '<img class="mp-logo" src="/assets/img/brand/' . $brand . '-logo-480.webp" alt="" width="480" height="240" loading="lazy" decoding="async">'
        . '<span class="mp-label"><svg class="icon icon-sm"><use href="#i-' . e($placeholderIcon) . '"/></svg>Photo coming soon</span></div>';
}

/** Stock badge for feeds. */
function stock_badge(int $stock, int $threshold = 5): string
{
    if ($stock <= 0) {
        return '<span class="badge badge-danger">' . e(t('common.out_of_stock')) . '</span>';
    }
    if ($stock <= $threshold) {
        return '<span class="badge badge-warning">' . e(t('common.low_stock', ['qty' => $stock])) . '</span>';
    }
    return '<span class="badge badge-success"><span class="dot"></span>' . e(t('common.in_stock')) . '</span>';
}

/** "incl. VAT" note when the division is VAT registered. */
function vat_note(string $div): string
{
    $d = division($div);
    return ($d && (int) $d['vat_registered']) ? '<span class="price-note">' . e(t('common.incl_vat')) . '</span>' : '';
}

/** Very small, safe Markdown → HTML (headings, bold, italics, lists, links, paragraphs, blockquotes). */
function markdown(?string $md): string
{
    $md = str_replace(["\r\n", "\r"], "\n", trim((string) $md));
    if ($md === '') {
        return '';
    }
    $inline = static function (string $s): string {
        $s = e($s);
        $s = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $s) ?? $s;
        $s = preg_replace('/(?<![\w*])_(.+?)_(?![\w*])/s', '<em>$1</em>', $s) ?? $s;
        $s = preg_replace('/(?<![\w*])\*(?!\s)(.+?)(?<!\s)\*(?![\w*])/s', '<em>$1</em>', $s) ?? $s;
        $s = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', static function ($m) {
            $url = html_entity_decode($m[2], ENT_QUOTES);
            if (!preg_match('#^(https?://|/(?![/\\\\])|mailto:|tel:)#i', $url)) {
                return $m[1];
            }
            $ext = preg_match('#^https?://#i', $url) ? ' target="_blank" rel="noopener"' : '';
            return '<a href="' . e($url) . '"' . $ext . '>' . $m[1] . '</a>';
        }, $s) ?? $s;
        return $s;
    };
    $html = '';
    foreach (preg_split('/\n{2,}/', $md) ?: [] as $block) {
        $lines = explode("\n", $block);
        if (preg_match('/^(#{1,4})\s+(.*)$/', $lines[0], $m) && count($lines) === 1) {
            $lvl = min(4, strlen($m[1]) + 1);
            $html .= "<h{$lvl}>" . $inline($m[2]) . "</h{$lvl}>";
        } elseif (preg_match('/^\s*[-*]\s+/', $lines[0])) {
            $html .= '<ul>' . implode('', array_map(static fn($l) => '<li>' . $inline(preg_replace('/^\s*[-*]\s+/', '', $l) ?? '') . '</li>', $lines)) . '</ul>';
        } elseif (preg_match('/^\s*\d+[.)]\s+/', $lines[0])) {
            $html .= '<ol>' . implode('', array_map(static fn($l) => '<li>' . $inline(preg_replace('/^\s*\d+[.)]\s+/', '', $l) ?? '') . '</li>', $lines)) . '</ol>';
        } elseif (str_starts_with($lines[0], '>')) {
            $html .= '<blockquote>' . $inline(implode(' ', array_map(static fn($l) => ltrim(substr($l, 1)), $lines))) . '</blockquote>';
        } else {
            $html .= '<p>' . implode('<br>', array_map($inline, $lines)) . '</p>';
        }
    }
    return $html;
}

/** Pagination links preserving the current query string. */
function pagination(int $page, int $pages, string $baseUrl, array $dropKeys = []): string
{
    if ($pages <= 1) {
        return '';
    }
    // Keys already expressed by the base address (e.g. the category in /feeds/category/x) are left out of the query
    $q = array_diff_key($_GET, array_flip($dropKeys));
    $link = static function (int $p) use ($q, $baseUrl): string {
        $q['page'] = $p;
        return $baseUrl . '?' . http_build_query($q);
    };
    $out = '<nav class="pagination" aria-label="Pages">';
    if ($page > 1) {
        $out .= '<a class="btn btn-sm" href="' . e($link($page - 1)) . '" data-page="' . ($page - 1) . '">← Prev</a>';
    }
    $out .= '<span class="muted small">Page ' . $page . ' of ' . $pages . '</span>';
    if ($page < $pages) {
        $out .= '<a class="btn btn-sm" href="' . e($link($page + 1)) . '" data-page="' . ($page + 1) . '">Next →</a>';
    }
    return $out . '</nav>';
}

// ---------------------------------------------------------------------
// Public addresses: ONE form everywhere (links, canonical tags, sitemap, structured data, emails)
// ---------------------------------------------------------------------
function product_url(string $slug): string
{
    return '/feeds/product/' . rawurlencode($slug);
}

function lot_url(string $slug): string
{
    return '/ranch/lot/' . rawurlencode($slug);
}

function page_url(string $slug): string
{
    return '/pages/' . rawurlencode($slug);
}

function category_url(string $slug): string
{
    return '/feeds/category/' . rawurlencode($slug);
}

/** An old-style link (/pages/page.php?slug=x, /feeds/product.php?slug=x …) in its pretty form; anything else unchanged. */
function pretty_url(string $url): string
{
    $map = ['#^/pages/page\.php\?slug=([a-z0-9-]+)$#' => 'page_url', '#^/feeds/product\.php\?slug=([a-z0-9-]+)$#' => 'product_url',
            '#^/ranch/lot\.php\?slug=([a-z0-9-]+)$#' => 'lot_url', '#^/feeds/shop\.php\?category=([a-z0-9-]+)$#' => 'category_url'];
    foreach ($map as $re => $fn) {
        if (preg_match($re, $url, $m)) {
            return $fn($m[1]);
        }
    }
    return $url;
}

/**
 * A detail page reached by any other address (…/product.php?slug=x, a trailing slash, …) moves permanently to the
 * pretty one, so search engines and shared links settle on one URL. Only for plain GET/HEAD page views.
 * Cannot loop: the pretty path is exactly what the rewrite rules serve.
 */
function redirect_to_pretty(string $prettyPath): void
{
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
    if ($path !== $prettyPath && in_array(request_method(), ['GET', 'HEAD'], true)) {
        $q = $_GET;
        unset($q['slug'], $q['category']);
        redirect($prettyPath . ($q ? '?' . http_build_query($q) : ''), 301);
    }
}

/** Structured data without empty values (Google flags null / "" fields). */
function jsonld_clean(array $data): array
{
    $out = [];
    foreach ($data as $k => $v) {
        if (is_array($v)) {
            $v = jsonld_clean($v);
            if ($v === []) {
                continue;
            }
        }
        if ($v === null || $v === '') {
            continue;
        }
        $out[$k] = $v;
    }
    return array_is_list($data) ? array_values($out) : $out;
}

/** schema.org BreadcrumbList from [[name, path], …]. */
function jsonld_breadcrumbs(array $crumbs): array
{
    $items = [];
    foreach (array_values($crumbs) as $i => [$name, $path]) {
        $items[] = ['@type' => 'ListItem', 'position' => $i + 1, 'name' => $name, 'item' => app_url($path)];
    }
    return ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
}

/** South African provinces (address forms). */
function sa_provinces(): array
{
    return ['Eastern Cape', 'Free State', 'Gauteng', 'KwaZulu-Natal', 'Limpopo', 'Mpumalanga', 'North West', 'Northern Cape', 'Western Cape'];
}

/** <option> list of provinces with $selected chosen. */
function province_options(?string $selected = null): string
{
    $out = '<option value="">Choose…</option>';
    foreach (sa_provinces() as $p) {
        $out .= '<option value="' . e($p) . '"' . ($selected === $p ? ' selected' : '') . '>' . e($p) . '</option>';
    }
    return $out;
}

/** True when a division has FAQs to show (the nav links to the FAQ section only then). */
function division_has_faqs(string $division): bool
{
    try {
        return (bool) db_val('SELECT 1 FROM faqs WHERE is_active = 1 AND division_id = ? LIMIT 1', [division_id($division)]);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * The areas that have a background, the Admin label for each, and where a photo was stored before
 * the "Page backgrounds" tab existed (scope, key).
 */
function page_background_slots(): array
{
    return [
        'home' => ['label' => 'Home page', 'hint' => 'Behind the home page (/), the 404 page and other general pages', 'legacy' => ['gateway', 'page_bg']],
        'feeds' => ['label' => 'GrazeX Feeds', 'hint' => 'Behind every Feeds page: shop, products, specials, calculator, cart and checkout', 'legacy' => ['feeds', 'page_bg']],
        'ranch' => ['label' => 'GrazeX Ranch', 'hint' => 'Behind every Ranch page: livestock board, lots, our model, cart and checkout', 'legacy' => ['ranch', 'page_bg']],
        'account' => ['label' => 'My account', 'hint' => 'Behind log in, register and the customer account pages', 'legacy' => ['gateway', 'account_bg']],
        'pages' => ['label' => 'Info & contact pages', 'hint' => 'Behind About, Terms, Privacy, Returns, Delivery and Contact', 'legacy' => ['gateway', 'pages_bg']],
    ];
}

/**
 * The uploaded photo for an area's background, or null for the built-in scene.
 * Admin > Site content > Page backgrounds saves "bg_<area>" in the global scope; once that row exists it decides
 * (also when its photo was removed). Before that, an older per-tab photo still counts.
 */
function page_background_media(string $scene): ?int
{
    $slot = page_background_slots()[$scene] ?? null;
    if (!$slot) {
        return null;
    }
    $global = content_scope('global');
    if (array_key_exists('bg_' . $scene, $global)) {
        return (int) $global['bg_' . $scene]['media_id'] ?: null;
    }
    [$scope, $key] = $slot['legacy'];
    return (int) (content_scope($scope)[$key]['media_id'] ?? 0) ?: null;
}

/**
 * Full-screen background scene behind a page (the page content floats over it).
 * Each area has its own scene in /assets/img/bg/; Admin > Site content > Page backgrounds can swap in a real photo.
 * $page['bg'] picks a scene ('home', 'feeds', 'ranch', 'account', 'pages') or false for none.
 * Returns null when the page has no background (admin, setup).
 */
function page_background(array $page): ?array
{
    $scene = $page['bg'] ?? match ($page['division'] ?? 'gateway') {
        'gateway' => 'home',
        'feeds' => 'feeds',
        'ranch' => 'ranch',
        'account' => 'account',
        'pages' => 'pages',
        default => null,
    };
    if (!is_string($scene) || !array_key_exists($scene, page_background_slots())) {
        return null;
    }
    // Versioned URLs (?v=): static images are cached for a year, so a new scene must get a new address
    $img = static fn(string $size): string => asset('/assets/img/bg/' . $scene . '-' . $size . '.webp');
    $bg = [
        'scene' => $scene,
        'src' => $img('1600'),
        'srcset' => $img('960') . ' 960w, ' . $img('1600') . ' 1600w, ' . $img('2560') . ' 2560w',
        'portrait' => $img('p720') . ' 720w, ' . $img('p1080') . ' 1080w',
        'photo' => false,
        'position' => null,
    ];
    try {
        $mediaId = page_background_media($scene);
        if ($mediaId && ($src = media_url($mediaId, 1600))) {
            $m = media_row($mediaId);
            $bg['src'] = $src;
            $bg['srcset'] = media_srcset($mediaId, 'wide') ?: $src;
            $bg['portrait'] = null;
            $bg['photo'] = true;
            // Phones show a narrow slice of the wide photo: keep the focus point (Admin > Media) in view
            $bg['position'] = round((float) ($m['focal_x'] ?? .5) * 100, 1) . '% ' . round((float) ($m['focal_y'] ?? .5) * 100, 1) . '%';
        }
    } catch (Throwable $e) {
        gx_log('warning', 'Background photo unavailable: ' . $e->getMessage());
    }
    return $bg;
}
