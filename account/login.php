<?php
/** Customer login. */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$next = safe_next(query_str('next'), '/account/');
if (current_customer()) {
    redirect($next);
}
$page = ['title' => 'Log in', 'division' => 'account', 'css' => ['/account/css/account.css'], 'js' => ['/account/js/account.js'], 'noindex' => true];
require GX_ROOT . '/includes/layout/head.php';
require GX_ROOT . '/includes/layout/header.php';
?>
<main id="main" class="auth-screen">
  <div class="auth-card card card-pad reveal">
    <div class="auth-logos"><img src="/assets/img/brand/feeds-logo-480.webp" alt="GrazeX Feeds" width="120" height="60"><img src="/assets/img/brand/ranch-logo-480.webp" alt="GrazeX Ranch" width="120" height="60"></div>
    <h1 class="h3 center">Welcome back</h1>
    <p class="muted center small">One account for GrazeX Feeds and GrazeX Ranch.</p>
    <form data-api="/account/api/auth.php" data-action="login" novalidate>
      <input type="hidden" name="next" value="<?= e($next) ?>">
      <div class="field"><label for="email">Email</label><input class="input" id="email" name="email" type="email" autocomplete="email" required autofocus></div>
      <div class="field"><label for="password">Password</label><input class="input" id="password" name="password" type="password" autocomplete="current-password" required></div>
      <button class="btn btn-primary btn-lg btn-block" type="submit">Log in</button>
    </form>
    <div class="row-between mt-2 small">
      <a class="link" href="/account/forgot.php">Forgot password?</a>
      <a class="link" href="/account/register.php?next=<?= e(rawurlencode($next)) ?>">Create an account</a>
    </div>
  </div>
</main>
<?php require GX_ROOT . '/includes/layout/footer.php'; ?>
