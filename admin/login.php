<?php
/** Admin login (with optional TOTP 2FA step). */
declare(strict_types=1);

define('GX_SKIP_MAINTENANCE', true);
require __DIR__ . '/../includes/bootstrap.php';

$next = safe_next(query_str('next'), '/admin/');
if (current_admin()) {
    redirect($next);
}
$twoFa = !empty($_SESSION['admin_2fa_pending']);
$page = ['title' => 'Admin login', 'division' => 'admin', 'css' => ['/admin/css/admin.css'], 'js' => ['/admin/js/admin.js', '/admin/js/admin-login.js'],
         'noindex' => true, 'hide_footer' => true, 'hide_fab' => true, 'body_class' => 'admin-body'];
require GX_ROOT . '/includes/layout/head.php';
?>
<main id="main" class="login-screen">
  <div class="card card-pad" style="width:min(420px,100%)">
    <div class="center mb-2"><img src="/assets/img/brand/ranch-logo-480.webp" alt="GrazeX" width="200" height="100" style="margin:0 auto"></div>
    <h1 class="h3 center mb-2"><?= $twoFa ? 'Two-step verification' : 'GrazeX admin' ?></h1>
    <?php if ($twoFa): ?>
      <form id="admin-login" data-step="2fa" novalidate>
        <input type="hidden" name="next" value="<?= e($next) ?>">
        <div class="field"><label for="code">6-digit code from your authenticator app</label>
          <input class="input" id="code" name="code" autocomplete="one-time-code" maxlength="12" required autofocus style="font-size:24px;letter-spacing:.3em;text-align:center">
          <p class="hint">Lost your phone? Type one of your recovery codes instead.</p></div>
        <button class="btn btn-primary btn-lg btn-block" type="submit">Verify</button>
        <button class="btn btn-ghost btn-block mt-1" type="button" data-cancel-2fa>Use a different account</button>
      </form>
    <?php else: ?>
      <form id="admin-login" data-step="login" novalidate>
        <input type="hidden" name="next" value="<?= e($next) ?>">
        <div class="field"><label for="email">Email</label><input class="input" id="email" name="email" type="email" autocomplete="username" required autofocus></div>
        <div class="field"><label for="password">Password</label><input class="input" id="password" name="password" type="password" autocomplete="current-password" required></div>
        <button class="btn btn-primary btn-lg btn-block" type="submit">Log in</button>
        <p class="center small mt-2"><a class="link" href="/admin/forgot.php">Forgot your password?</a></p>
      </form>
    <?php endif; ?>
  </div>
</main>
<?php require GX_ROOT . '/includes/layout/footer.php'; ?>
