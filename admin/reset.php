<?php
/** Admin — choose a new password from an emailed reset link (?token=…). */
declare(strict_types=1);

define('GX_SKIP_MAINTENANCE', true);
require __DIR__ . '/../includes/bootstrap.php';

$token = query_str('token');
$valid = (bool) preg_match('/^[a-f0-9]{64}$/', $token);
$page = ['title' => 'Choose a new admin password', 'division' => 'admin', 'css' => ['/admin/css/admin.css'], 'js' => ['/admin/js/admin.js', '/admin/js/admin-login.js'],
         'noindex' => true, 'hide_footer' => true, 'hide_fab' => true, 'body_class' => 'admin-body'];
// The token is in the address bar: never send it to other sites
header('Referrer-Policy: no-referrer');
require GX_ROOT . '/includes/layout/head.php';
?>
<main id="main" class="login-screen">
  <div class="card card-pad" style="width:min(420px,100%)">
    <div class="center mb-2"><img src="/assets/img/brand/ranch-logo-480.webp" alt="GrazeX" width="200" height="100" style="margin:0 auto"></div>
    <h1 class="h3 center mb-2">Choose a new password</h1>
    <?php if ($valid): ?>
      <form data-admin-public data-api="/admin/api/auth.php" data-action="reset" novalidate>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <div class="field"><label for="password">New password (at least <?= (int) GX_PASSWORD_MIN ?> characters)</label>
          <input class="input" id="password" name="password" type="password" autocomplete="new-password" minlength="<?= (int) GX_PASSWORD_MIN ?>" required autofocus></div>
        <div class="field"><label for="password_confirm">Type it again</label>
          <input class="input" id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" required></div>
        <p class="hint">You will be logged out everywhere. Two-step verification stays on.</p>
        <button class="btn btn-primary btn-lg btn-block" type="submit">Save new password</button>
      </form>
    <?php else: ?>
      <p class="muted">This link is not complete. Use the full link from the email, or ask for a new one.</p>
    <?php endif; ?>
    <p class="center small mt-2"><a class="link" href="/admin/forgot.php">Ask for a new link</a> · <a class="link" href="/admin/login.php">Log in</a></p>
  </div>
</main>
<?php require GX_ROOT . '/includes/layout/footer.php'; ?>
