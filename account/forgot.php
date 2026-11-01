<?php
/** Forgot password. */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$page = ['title' => 'Forgot password', 'division' => 'account', 'css' => ['/account/css/account.css'], 'js' => ['/account/js/account.js'], 'noindex' => true];
require GX_ROOT . '/includes/layout/head.php';
require GX_ROOT . '/includes/layout/header.php';
?>
<main id="main" class="auth-screen">
  <div class="auth-card card card-pad reveal">
    <h1 class="h3">Reset your password</h1>
    <p class="muted small">Enter your email and we'll send you a link to choose a new password.</p>
    <form data-api="/account/api/auth.php" data-action="forgot" data-reset novalidate>
      <div class="field"><label for="email">Email</label><input class="input" id="email" name="email" type="email" autocomplete="email" required autofocus></div>
      <button class="btn btn-primary btn-lg btn-block" type="submit">Send reset link</button>
    </form>
    <p class="small mt-2"><a class="link" href="/account/login.php">Back to log in</a></p>
  </div>
</main>
<?php require GX_ROOT . '/includes/layout/footer.php'; ?>
