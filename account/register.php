<?php
/** Customer registration. */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$next = safe_next(query_str('next'), '/account/');
if (current_customer()) {
    redirect($next);
}
$page = ['title' => 'Create account', 'division' => 'account', 'css' => ['/account/css/account.css'], 'js' => ['/account/js/account.js'], 'noindex' => true];
require GX_ROOT . '/includes/layout/head.php';
require GX_ROOT . '/includes/layout/header.php';
?>
<main id="main" class="auth-screen">
  <div class="auth-card card card-pad reveal">
    <div class="auth-logos"><img src="/assets/img/brand/feeds-logo-480.webp" alt="GrazeX Feeds" width="120" height="60"><img src="/assets/img/brand/ranch-logo-480.webp" alt="GrazeX Ranch" width="120" height="60"></div>
    <h1 class="h3 center">Create your account</h1>
    <p class="muted center small">Track orders, download invoices and reorder in one tap.</p>
    <form data-api="/account/api/auth.php" data-action="register" novalidate>
      <input type="hidden" name="next" value="<?= e($next) ?>">
      <div class="grid-2">
        <div class="field"><label for="first_name">First name</label><input class="input" id="first_name" name="first_name" autocomplete="given-name" required></div>
        <div class="field"><label for="last_name">Last name</label><input class="input" id="last_name" name="last_name" autocomplete="family-name" required></div>
      </div>
      <div class="field"><label for="email">Email</label><input class="input" id="email" name="email" type="email" autocomplete="email" required></div>
      <div class="field"><label for="phone">Cellphone</label><input class="input" id="phone" name="phone" type="tel" autocomplete="tel" placeholder="082 123 4567" required></div>
      <div class="field"><label for="password">Password <span class="opt">(min <?= GX_PASSWORD_MIN ?> characters)</span></label><input class="input" id="password" name="password" type="password" autocomplete="new-password" minlength="<?= GX_PASSWORD_MIN ?>" required></div>
      <label class="check mb-2"><input type="checkbox" name="marketing_opt_in" value="1"> Send me specials and new livestock alerts</label>
      <p class="hint">By creating an account you accept our <a class="link" href="/pages/page.php?slug=terms">terms</a> and <a class="link" href="/pages/page.php?slug=privacy">privacy policy</a>.</p>
      <button class="btn btn-primary btn-lg btn-block" type="submit">Create account</button>
    </form>
    <p class="center small mt-2">Already have an account? <a class="link" href="/account/login.php?next=<?= e(rawurlencode($next)) ?>">Log in</a></p>
  </div>
</main>
<?php require GX_ROOT . '/includes/layout/footer.php'; ?>
