<?php
/** Admin — my profile: password and two-step verification. */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require GX_ROOT . '/includes/layout/admin-shell.php';

$admin = admin_start('My profile', 'profile', ['js' => ['/admin/js/admin-profile.js']]);
?>
<div class="adm-grid-2">
  <section class="card card-pad">
    <h2 class="h4 mb-2">Change password</h2>
    <form data-admin-form data-api="/admin/api/auth.php" data-action="password" novalidate>
      <?= af_text('current_password', 'Current password', '', ['type' => 'password', 'required' => true]) ?>
      <?= af_text('password', 'New password', '', ['type' => 'password', 'required' => true]) ?>
      <?= af_text('password_confirm', 'Confirm new password', '', ['type' => 'password', 'required' => true]) ?>
      <button class="btn btn-primary" type="submit">Change password</button>
    </form>
  </section>
  <section class="card card-pad">
    <h2 class="h4 mb-1">Two-step verification</h2>
    <p class="muted small">Protects the admin with a 6-digit code from Google Authenticator, Microsoft Authenticator or Authy on your phone.</p>
    <?php if ((int) $admin['totp_enabled']): ?>
      <div class="alert alert-success mb-2">✓ On</div>
      <form data-admin-form data-api="/admin/api/auth.php" data-action="2fa_disable" data-reload novalidate>
        <?= af_text('password', 'Your password to switch it off', '', ['type' => 'password', 'required' => true]) ?>
        <button class="btn btn-danger btn-sm" type="submit">Switch off</button>
      </form>
    <?php else: ?>
      <button class="btn btn-primary" type="button" data-2fa-start>Set up two-step verification</button>
      <div data-2fa-box hidden class="mt-2">
        <ol class="how-list small">
          <li>Open your authenticator app and add an account.</li>
          <li>Choose "enter a setup key" and type this key: <strong data-2fa-secret style="letter-spacing:.1em"></strong> <a class="link" data-2fa-uri href="#">(or tap here on your phone)</a></li>
          <li>Type the 6-digit code it shows:</li>
        </ol>
        <form data-admin-form data-api="/admin/api/auth.php" data-action="2fa_enable" data-reload novalidate>
          <?= af_text('code', '6-digit code', '', ['inputmode' => 'numeric', 'maxlength' => 6, 'required' => true]) ?>
          <button class="btn btn-primary" type="submit">Switch on</button>
        </form>
      </div>
    <?php endif; ?>
    <hr class="divider">
    <button class="btn" type="button" data-admin-act="/admin/api/auth.php" data-payload='{"action":"logout"}'>Log out</button>
  </section>
</div>
<?php admin_end(); ?>
