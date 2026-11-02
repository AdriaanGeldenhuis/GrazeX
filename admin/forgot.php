<?php
/** Admin — "forgot password": emails a one-hour reset link. */
declare(strict_types=1);

define('GX_SKIP_MAINTENANCE', true);
require __DIR__ . '/../includes/bootstrap.php';

if (current_admin()) {
    redirect('/admin/');
}
$page = ['title' => 'Reset admin password', 'division' => 'admin', 'css' => ['/admin/css/admin.css'], 'js' => ['/admin/js/admin.js', '/admin/js/admin-login.js'],
         'noindex' => true, 'hide_footer' => true, 'hide_fab' => true, 'body_class' => 'admin-body'];
require GX_ROOT . '/includes/layout/head.php';
?>
<main id="main" class="login-screen">
  <div class="card card-pad" style="width:min(420px,100%)">
    <div class="center mb-2"><img src="/assets/img/brand/ranch-logo-480.webp" alt="GrazeX" width="200" height="100" style="margin:0 auto"></div>
    <h1 class="h3 center mb-2">Forgot your password?</h1>
    <form data-admin-public data-api="/admin/api/auth.php" data-action="forgot" novalidate>
      <p class="muted small">Enter the email you log in with. We'll send a link to choose a new password.</p>
      <div class="field"><label for="email">Email</label><input class="input" id="email" name="email" type="email" autocomplete="username" required autofocus></div>
      <button class="btn btn-primary btn-lg btn-block" type="submit">Send reset link</button>
    </form>
    <p class="alert alert-success" role="status" tabindex="-1" data-admin-public-done hidden></p>
    <p class="center small mt-2"><a class="link" href="/admin/login.php">Back to log in</a></p>
  </div>
</main>
<?php require GX_ROOT . '/includes/layout/footer.php'; ?>
