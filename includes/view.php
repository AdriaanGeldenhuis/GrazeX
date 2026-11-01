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
            if (!preg_match('#^(https?://|/|mailto:|tel:)#i', $url)) {
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
function pagination(int $page, int $pages, string $baseUrl): string
{
    if ($pages <= 1) {
        return '';
    }
    $q = $_GET;
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
