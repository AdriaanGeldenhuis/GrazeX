<?php
defined('GX_ROOT') || exit;
http_response_code(404);
$page = ['title' => 'Not found', 'division' => $page['division'] ?? 'gateway', 'noindex' => true];
require GX_ROOT . '/includes/layout/head.php';
require GX_ROOT . '/includes/layout/header.php';
?>
<main id="main" class="container page-top center" style="min-height:60vh;display:grid;place-content:center;gap:18px">
  <p class="kicker">404</p>
  <h1 class="display-2">Wrong <span class="text-fire">camp.</span></h1>
  <p class="lead" style="margin:0 auto"><?= e(t('error.not_found')) ?></p>
  <div class="row" style="justify-content:center">
    <a class="btn btn-orange" href="/feeds/">Shop Feeds</a>
    <a class="btn" href="/ranch/">Enter the Ranch</a>
  </div>
</main>
<?php require GX_ROOT . '/includes/layout/footer.php'; ?>
