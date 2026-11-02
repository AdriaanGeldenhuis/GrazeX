<?php
/** CMS pages: About, Terms, Privacy, Returns, Delivery… (edited in Admin > Lists & setup > Pages). */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$pg = db_one('SELECT * FROM pages WHERE slug = ? AND is_published = 1', [preg_replace('/[^a-z0-9-]/', '', query_str('slug'))]);
if (!$pg) {
    require GX_ROOT . '/includes/layout/not_found.php';
    exit;
}
redirect_to_pretty(page_url($pg['slug']));
$others = db_all('SELECT slug, title FROM pages WHERE is_published = 1 AND show_in_footer = 1 AND id <> ? ORDER BY sort_order', [$pg['id']]);
$page = ['title' => $pg['meta_title'] ?: $pg['title'], 'description' => $pg['meta_description'] ?? '', 'division' => 'pages', 'css' => ['/pages/css/pages.css'],
         'canonical' => page_url($pg['slug'])];
require GX_ROOT . '/includes/layout/head.php';
require GX_ROOT . '/includes/layout/header.php';
?>
<main id="main" class="container page-top pg">
  <article class="pg-body">
    <span class="kicker">GrazeX</span>
    <h1 class="display-2 mt-1 mb-3"><?= e($pg['title']) ?></h1>
    <div class="prose"><?= markdown($pg['body_md']) ?></div>
    <p class="tiny dim mt-4">Last updated <?= e(fmt_date($pg['updated_at'])) ?></p>
  </article>
  <?php if ($others): ?>
    <aside class="pg-side card card-pad">
      <h2 class="h4 mb-2">More information</h2>
      <ul class="trust-list"><?php foreach ($others as $o): ?><li><a class="link" href="<?= e(page_url($o['slug'])) ?>"><?= e($o['title']) ?></a></li><?php endforeach; ?>
        <li><a class="link" href="/pages/contact.php">Contact us</a></li></ul>
    </aside>
  <?php endif; ?>
</main>
<?php require GX_ROOT . '/includes/layout/footer.php'; ?>
