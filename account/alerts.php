<?php
/** Customer portal — livestock alerts. */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$customer = require_customer();
$alerts = db_all('SELECT a.*, b.name AS breed_name, c.name AS category_name FROM livestock_alerts a
                  LEFT JOIN breeds b ON b.id = a.breed_id LEFT JOIN livestock_categories c ON c.id = a.category_id
                  WHERE (a.customer_id = ? OR a.email = ?) ORDER BY a.is_active DESC, a.id DESC', [$customer['id'], customer_verified_email($customer)]);
$breeds = LivestockService::breeds();
$cats = LivestockService::categories();

$page = ['title' => 'Livestock alerts', 'division' => 'account', 'css' => ['/account/css/account.css'], 'js' => ['/account/js/account.js'], 'noindex' => true];
require GX_ROOT . '/includes/layout/head.php';
require GX_ROOT . '/includes/layout/header.php';
?>
<main id="main" class="container acc-wrap">
  <div class="acc-head"><div><span class="kicker">My account</span><h1 class="display-3 mt-1">Livestock alerts</h1></div></div>
  <div class="acc-layout">
    <?php $accountTab = 'alerts'; require GX_ROOT . '/includes/layout/account-nav.php'; ?>
    <div class="acc-main">
      <section class="card card-pad">
        <h2 class="h3 mb-1">Get notified first</h2>
        <p class="muted small">We email you the moment a matching lot goes on the board.</p>
        <form data-api="/account/api/alerts.php" data-action="create" data-reload class="grid-2" novalidate>
          <div class="field"><label for="breed">Breed</label><select class="select" id="breed" name="breed"><option value="">Any breed</option><?php foreach ($breeds as $b): ?><option value="<?= e($b['slug']) ?>"><?= e($b['name']) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label for="category">Type</label><select class="select" id="category" name="category"><option value="">Any type</option><?php foreach ($cats as $c): ?><option value="<?= e($c['slug']) ?>"><?= e($c['name']) ?></option><?php endforeach; ?></select></div>
          <div><button class="btn btn-primary" type="submit"><svg class="icon"><use href="#i-bell"/></svg>Add alert</button></div>
        </form>
      </section>
      <?php if ($alerts): ?>
        <div class="order-list">
          <?php foreach ($alerts as $a): ?>
            <div class="order-row" style="cursor:default">
              <div><div class="or-no"><?= e($a['breed_name'] ?? 'Any breed') ?> · <?= e($a['category_name'] ?? 'any type') ?></div><div class="or-meta"><?= !$a['confirmed_at'] ? 'Waiting for you to confirm — check your inbox' : ($a['last_notified_at'] ? 'Last alert ' . e(fmt_date($a['last_notified_at'])) : 'No alerts sent yet') ?></div></div>
              <div><button class="btn btn-sm<?= $a['is_active'] ? '' : ' btn-primary' ?>" type="button" data-act="/account/api/alerts.php" data-payload='<?= e(json_encode(['action' => 'toggle', 'id' => (int) $a['id']])) ?>'><?= $a['is_active'] ? 'Pause' : 'Resume' ?></button>
                <button class="btn btn-sm btn-ghost" type="button" data-act="/account/api/alerts.php" data-payload='<?= e(json_encode(['action' => 'delete', 'id' => (int) $a['id']])) ?>'>Delete</button></div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</main>
<?php require GX_ROOT . '/includes/layout/footer.php'; ?>
