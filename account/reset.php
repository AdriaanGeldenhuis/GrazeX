<?php
/** Choose a new password (from the emailed link). */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$token = preg_match('/^[a-f0-9]{64}$/', query_str('token')) ? query_str('token') : '';
$page = ['title' => 'New password', 'division' => 'account', 'css' => ['/account/css/account.css'], 'js' => ['/account/js/account.js'], 'noindex' => true];
require GX_ROOT . '/includes/layout/head.php';
require GX_ROOT . '/includes/layout/header.php';
?>
<main id="main" class="auth-screen">
  <div class="auth-card card card-pad reveal">
    <h1 class="h3">Choose a new password</h1>
    <?php if (!$token): ?>
      <div class="alert alert-warning mt-2">This link is not valid. <a class="link" href="/account/forgot.php">Request a new one</a>.</div>
    <?php else: ?>
      <form data-api="/account/api/auth.php" data-action="reset" novalidate>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <div class="field"><label for="password">New password <span class="opt">(min <?= GX_PASSWORD_MIN ?> characters)</span></label><input class="input" id="password" name="password" type="password" autocomplete="new-password" required autofocus></div>
        <div class="field"><label for="password_confirm">Confirm password</label><input class="input" id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" required></div>
        <button class="btn btn-primary btn-lg btn-block" type="submit">Save password</button>
      </form>
    <?php endif; ?>
  </div>
</main>
<?php require GX_ROOT . '/includes/layout/footer.php'; ?>
