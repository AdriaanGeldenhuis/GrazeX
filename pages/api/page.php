<?php
/**
 * GET /pages/api/page.php?slug=terms — a published page (terms, privacy, returns…) as JSON, so checkout and
 * registration can show it in a dialog without leaving the form that is being filled in.
 */
declare(strict_types=1);

define('GX_IS_API', true);
require __DIR__ . '/../../includes/bootstrap.php';

if (!in_array(request_method(), ['GET', 'HEAD'], true)) {
    json_error('Method not allowed.', 405);
}
$pg = db_one('SELECT slug, title, body_md, updated_at FROM pages WHERE slug = ? AND is_published = 1', [preg_replace('/[^a-z0-9-]/', '', query_str('slug'))]);
if (!$pg) {
    json_error('Page not found.', 404);
}
json_ok(['title' => $pg['title'], 'html' => markdown($pg['body_md']), 'updated' => fmt_date($pg['updated_at']), 'url' => page_url($pg['slug'])]);
