<?php
defined('GX_ROOT') || exit;
$page = ['title' => 'No access', 'division' => 'gateway', 'noindex' => true];
require GX_ROOT . '/includes/layout/head.php';
require GX_ROOT . '/includes/layout/header.php';
?>
<main id="main" class="container page-top center" style="min-height:60vh;display:grid;place-content:center;gap:18px">
  <h1 class="display-2">No <span class="text-fire">access.</span></h1>
  <p class="lead" style="margin:0 auto"><?= e(t('error.forbidden')) ?></p>
  <div><a class="btn btn-primary" href="/">Back to GrazeX</a></div>
</main>
<?php require GX_ROOT . '/includes/layout/footer.php'; ?>
