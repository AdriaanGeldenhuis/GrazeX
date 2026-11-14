<?php
/**
 * Confirm an email address (the link in the "confirm your email" message).
 * Opening the link never changes anything: mail scanners open links too, and would otherwise confirm the address
 * for whoever registered with it. The button does, by POST (same as the livestock alert links).
 */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$token = preg_match('/^[a-f0-9]{64}$/', query_str('token')) ? query_str('token') : '';
$page = ['title' => 'Confirm your email', 'division' => 'account', 'css' => ['/account/css/account.css'], 'js' => ['/account/js/account.js'], 'noindex' => true];
require GX_ROOT . '/includes/layout/head.php';
require GX_ROOT . '/includes/layout/header.php';
?>
<main id="main" class="auth-screen">
  <div class="auth-card card card-pad reveal">
    <h1 class="h3">Confirm your email</h1>
    <?php if (!$token): ?>
      <div class="alert alert-warning mt-2">This link is not valid. <a class="link" href="/account/profile.php">Log in and request a new one from your profile</a>.</div>
    <?php else: ?>
      <p class="muted mt-1">Click the button to confirm that this email address is yours.</p>
      <form data-api="/account/api/auth.php" data-action="verify_email" class="mt-2" novalidate>
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <button class="btn btn-primary btn-lg btn-block" type="submit">Confirm my email</button>
      </form>
    <?php endif; ?>
  </div>
</main>
<?php require GX_ROOT . '/includes/layout/footer.php'; ?>
