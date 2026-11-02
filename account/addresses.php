<?php
/** Customer portal — delivery addresses. */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$customer = require_customer();
$addresses = db_all('SELECT * FROM customer_addresses WHERE customer_id = ? ORDER BY is_default DESC, id DESC', [$customer['id']]);

$page = ['title' => 'My addresses', 'division' => 'account', 'css' => ['/account/css/account.css'], 'js' => ['/account/js/account.js'], 'noindex' => true];
require GX_ROOT . '/includes/layout/head.php';
require GX_ROOT . '/includes/layout/header.php';
?>
<main id="main" class="container acc-wrap">
  <div class="acc-head"><div><span class="kicker">My account</span><h1 class="display-3 mt-1">Addresses</h1></div></div>
  <div class="acc-layout">
    <?php $accountTab = 'addresses'; require GX_ROOT . '/includes/layout/account-nav.php'; ?>
    <div class="acc-main">
      <?php if ($addresses): ?>
        <div class="addr-grid">
          <?php foreach ($addresses as $a): ?>
            <div class="card card-pad">
              <div class="row-between mb-1"><strong><?= e($a['label'] ?: ($a['farm_name'] ?: 'Address')) ?></strong><?php if ($a['is_default']): ?><span class="badge badge-accent">Default</span><?php endif; ?></div>
              <p class="muted small mb-2"><?= e($a['recipient_name']) ?><?= $a['phone'] ? ' · ' . e($a['phone']) : '' ?><br><?= e(implode(', ', array_filter([$a['farm_name'], $a['line1'], $a['line2'], $a['town'], $a['province'], $a['postal_code']]))) ?>
                <?= $a['directions'] ? '<br><em>' . e($a['directions']) . '</em>' : '' ?><?= $a['gps_lat'] ? '<br>📍 ' . e($a['gps_lat']) . ', ' . e($a['gps_lng']) : '' ?></p>
              <div class="row">
                <?php if (!$a['is_default']): ?><button class="btn btn-sm" type="button" data-act="/account/api/addresses.php" data-payload='<?= e(json_encode(['action' => 'default', 'id' => (int) $a['id']])) ?>'>Make default</button><?php endif; ?>
                <button class="btn btn-sm btn-ghost" type="button" data-act="/account/api/addresses.php" data-payload='<?= e(json_encode(['action' => 'delete', 'id' => (int) $a['id']])) ?>' data-confirm="Delete this address?" data-ok="Delete" data-danger>Delete</button>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <section class="card card-pad">
        <h2 class="h3 mb-2">Add an address</h2>
        <form data-api="/account/api/addresses.php" data-action="create" data-reload novalidate>
          <div class="grid-2">
            <div class="field"><label for="label">Label <span class="opt">(e.g. Home farm)</span></label><input class="input" id="label" name="label"></div>
            <div class="field"><label for="farm_name">Farm name <span class="opt">(optional)</span></label><input class="input" id="farm_name" name="farm_name" autocomplete="organization"></div>
          </div>
          <div class="grid-2">
            <div class="field"><label for="recipient_name">Receiver's name</label><input class="input" id="recipient_name" name="recipient_name" autocomplete="name" value="<?= e($customer['first_name'] . ' ' . $customer['last_name']) ?>" required></div>
            <div class="field"><label for="phone">Receiver's phone</label><input class="input" id="phone" name="phone" type="tel" autocomplete="tel" value="<?= e($customer['phone'] ?? '') ?>" required></div>
          </div>
          <div class="grid-2">
            <div class="field"><label for="line1">Street / farm road</label><input class="input" id="line1" name="line1" autocomplete="address-line1" required></div>
            <div class="field"><label for="line2">Area, plot or suburb <span class="opt">(optional)</span></label><input class="input" id="line2" name="line2" autocomplete="address-line2"></div>
          </div>
          <div class="grid-2">
            <div class="field"><label for="town">Town</label><input class="input" id="town" name="town" autocomplete="address-level2" required></div>
            <div class="field"><label for="postal_code">Postal code <span class="opt">(optional)</span></label><input class="input" id="postal_code" name="postal_code" inputmode="numeric" autocomplete="postal-code"></div>
          </div>
          <div class="field"><label for="province">Province <span class="opt">(optional)</span></label><select class="select" id="province" name="province" autocomplete="address-level1"><?= province_options() ?></select></div>
          <div class="field"><label for="directions">Directions <span class="opt">(optional)</span></label><textarea class="textarea" id="directions" name="directions" rows="2"></textarea></div>
          <button class="btn btn-primary" type="submit">Save address</button>
        </form>
      </section>
    </div>
  </div>
</main>
<?php require GX_ROOT . '/includes/layout/footer.php'; ?>
