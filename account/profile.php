<?php
/**
 * Customer portal — profile, password, security, VAT-registered farmer status, privacy (POPIA).
 */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$customer = require_customer();
$full = db_one('SELECT * FROM customers WHERE id = ?', [$customer['id']]);
$zeroOn = setting_bool('zero_rating_enabled');

$page = ['title' => 'My profile', 'division' => 'account', 'css' => ['/account/css/account.css'], 'js' => ['/account/js/account.js'], 'noindex' => true];
require GX_ROOT . '/includes/layout/head.php';
require GX_ROOT . '/includes/layout/header.php';
?>
<main id="main" class="container acc-wrap">
  <div class="acc-head"><div><span class="kicker">My account</span><h1 class="display-3 mt-1">Profile</h1></div></div>
  <div class="acc-layout">
    <?php $accountTab = 'profile'; require GX_ROOT . '/includes/layout/account-nav.php'; ?>
    <div class="acc-main">
      <section class="card card-pad">
        <h2 class="h3 mb-2">Your details</h2>
        <form data-api="/account/api/profile.php" data-action="details" novalidate>
          <div class="grid-2">
            <div class="field"><label for="first_name">First name</label><input class="input" id="first_name" name="first_name" value="<?= e($full['first_name']) ?>" required></div>
            <div class="field"><label for="last_name">Last name</label><input class="input" id="last_name" name="last_name" value="<?= e($full['last_name']) ?>" required></div>
          </div>
          <div class="grid-2">
            <div class="field"><label for="phone">Cellphone</label><input class="input" id="phone" name="phone" type="tel" value="<?= e($full['phone']) ?>" required></div>
            <div class="field"><label>Email</label><input class="input" value="<?= e($full['email']) ?>" disabled><span class="hint"><?= $full['email_verified_at'] ? '✓ Confirmed' : 'Not confirmed yet' ?></span></div>
          </div>
          <div class="grid-2">
            <div class="field"><label for="farm_name">Farm name</label><input class="input" id="farm_name" name="farm_name" value="<?= e($full['farm_name']) ?>"></div>
            <div class="field"><label for="company_name">Company name</label><input class="input" id="company_name" name="company_name" value="<?= e($full['company_name']) ?>"></div>
          </div>
          <div class="field"><label for="vat_no">VAT number <span class="opt">(optional)</span></label><input class="input" id="vat_no" name="vat_no" value="<?= e($full['vat_no']) ?>" inputmode="numeric"></div>
          <label class="check mb-2"><input type="checkbox" name="marketing_opt_in" value="1" <?= $full['marketing_opt_in'] ? 'checked' : '' ?>> Email me specials and livestock news</label>
          <button class="btn btn-primary" type="submit">Save details</button>
        </form>
      </section>

      <?php if ($zeroOn): ?>
      <section class="card card-pad">
        <h2 class="h3 mb-1">VAT-registered farmer</h2>
        <p class="muted small">If you are a VAT vendor with SARS farming authorisation, qualifying animal feed can be supplied at 0% VAT. Upload your Notice of Registration and we'll verify it.</p>
        <p>Status: <span class="badge <?= ['verified' => 'badge-success', 'pending' => 'badge-warning', 'rejected' => 'badge-danger'][$full['vat_farmer_status']] ?? '' ?>"><?= e(ucfirst($full['vat_farmer_status'] === 'none' ? 'not submitted' : $full['vat_farmer_status'])) ?></span></p>
        <?php if ($full['vat_farmer_status'] !== 'verified'): ?>
          <form id="vat-form" novalidate>
            <input type="hidden" name="action" value="vat_doc">
            <div class="field"><label for="vat_doc">SARS Notice of Registration (PDF or photo)</label><input class="input" id="vat_doc" type="file" name="vat_doc" accept="application/pdf,image/jpeg,image/png,image/webp" required></div>
            <button class="btn" type="submit">Upload for verification</button>
          </form>
        <?php endif; ?>
      </section>
      <?php endif; ?>

      <section class="card card-pad">
        <h2 class="h3 mb-2">Password &amp; security</h2>
        <form data-api="/account/api/profile.php" data-action="password" data-reset novalidate>
          <div class="field"><label for="current_password">Current password</label><input class="input" id="current_password" name="current_password" type="password" autocomplete="current-password" required></div>
          <div class="grid-2">
            <div class="field"><label for="password">New password</label><input class="input" id="password" name="password" type="password" autocomplete="new-password" required></div>
            <div class="field"><label for="password_confirm">Confirm new password</label><input class="input" id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" required></div>
          </div>
          <button class="btn btn-primary" type="submit">Change password</button>
        </form>
        <hr class="divider">
        <button class="btn" type="button" data-act="/account/api/profile.php" data-payload='{"action":"logout_others"}' data-confirm="Log out of GrazeX on all your other phones and computers?" data-ok="Log them out">Log out on other devices</button>
      </section>

      <section class="card card-pad">
        <h2 class="h3 mb-1">Your privacy</h2>
        <p class="muted small">Under POPIA you can see the personal information we hold and ask us to delete your account. Invoices must be kept for 5 years by law, so those records are retained without your login.</p>
        <div class="row">
          <button class="btn" type="button" data-act="/account/api/privacy.php" data-payload='{"action":"export"}'><svg class="icon"><use href="#i-download"/></svg>Download my data</button>
          <?php if ($full['deletion_requested_at']): ?>
            <span class="badge badge-warning">Deletion requested <?= e(fmt_date($full['deletion_requested_at'])) ?></span>
          <?php else: ?>
            <button class="btn btn-danger" type="button" data-act="/account/api/privacy.php" data-payload='{"action":"delete_request"}' data-confirm="Ask GrazeX to delete your account? We'll confirm by email." data-ok="Request deletion" data-danger>Delete my account</button>
          <?php endif; ?>
        </div>
      </section>
    </div>
  </div>
</main>
<?php require GX_ROOT . '/includes/layout/footer.php'; ?>
