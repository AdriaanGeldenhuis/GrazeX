<?php
/**
 * One-time setup wizard: checks the server, then creates the OWNER admin account.
 * Locks itself as soon as an owner exists. Requires the setup_token from config/env.php.
 */
declare(strict_types=1);

define('GX_SKIP_MAINTENANCE', true);
require __DIR__ . '/../includes/bootstrap.php';

$dbOk = false;
$ownerExists = false;
$dbError = '';
try {
    $dbOk = (int) db_val('SELECT COUNT(*) FROM divisions') >= 2;
    $ownerExists = (int) db_val("SELECT COUNT(*) FROM admin_users a JOIN roles r ON r.id = a.role_id WHERE r.slug = 'owner'") > 0;
} catch (Throwable $e) {
    $dbError = 'Database not ready — import database/install.sql in phpMyAdmin first.';
}

$checks = [
    ['PHP 8.2 or newer', version_compare(PHP_VERSION, '8.2.0', '>='), 'Running ' . PHP_VERSION],
    ['PDO MySQL extension', extension_loaded('pdo_mysql'), ''],
    ['GD image library with WebP', function_exists('imagewebp'), 'Needed to convert uploads to .webp'],
    ['Fileinfo (safe uploads)', extension_loaded('fileinfo'), ''],
    ['mbstring', extension_loaded('mbstring'), ''],
    ['cURL (Yoco payments)', extension_loaded('curl'), ''],
    ['OpenSSL', extension_loaded('openssl'), ''],
    ['storage/ is writable', is_writable(GX_ROOT . '/storage'), ''],
    ['uploads/ is writable', is_writable(GX_ROOT . '/uploads'), ''],
    ['Composer libraries installed', is_file(GX_ROOT . '/vendor/autoload.php'), 'Installed automatically by the deploy (PDFs + email)'],
    ['Database imported', $dbOk, $dbError],
    ['app_key changed in env.php', !str_starts_with((string) env('app_key', ''), 'CHANGE-ME') && strlen((string) env('app_key', '')) >= 32, ''],
    ['setup_token changed in env.php', !str_starts_with((string) env('setup_token', ''), 'CHANGE-ME') && strlen((string) env('setup_token', '')) >= 12, 'At least 12 characters'],
];
$allOk = !in_array(false, array_column($checks, 1), true);

$page = [
    'title' => t('setup.title'),
    'division' => 'gateway',
    'css' => ['/setup/css/setup.css'],
    'js' => ['/setup/js/setup.js'],
    'noindex' => true,
    'hide_fab' => true,
];
require GX_ROOT . '/includes/layout/head.php';
?>
<main id="main" class="setup container container-narrow">
  <div class="setup-head reveal">
    <img src="/assets/img/brand/ranch-logo-960.webp" alt="GrazeX" width="360" height="180">
    <span class="kicker">One-time setup</span>
    <h1 class="display-2">Set up <span class="text-fire italic">GrazeX.</span></h1>
  </div>

  <?php if ($ownerExists): ?>
    <section class="card card-pad reveal center">
      <div class="es-icon"><svg class="icon icon-lg"><use href="#i-lock"/></svg></div>
      <h2 class="h3 mb-1">Setup is complete</h2>
      <p class="muted">The owner account already exists, so this page is locked.</p>
      <a class="btn btn-primary" href="/admin/">Go to admin</a>
    </section>
  <?php else: ?>
    <section class="card card-pad reveal">
      <h2 class="h3 mb-2">1. Server check</h2>
      <ul class="checks">
        <?php foreach ($checks as [$label, $ok, $note]): ?>
          <li class="<?= $ok ? 'ok' : 'fail' ?>">
            <span class="ck"><?= $ok ? '✓' : '✕' ?></span>
            <span><?= e($label) ?><?php if ($note): ?> <small class="dim">— <?= e($note) ?></small><?php endif; ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
      <?php if (!$allOk): ?>
        <div class="alert alert-warning mt-2">Fix the items marked ✕, then refresh this page. (Composer libraries can be skipped while testing locally.)</div>
      <?php endif; ?>
    </section>

    <section class="card card-pad reveal mt-3">
      <h2 class="h3 mb-2">2. Create the owner account</h2>
      <form id="setup-form" data-api="/setup/api/setup.php" novalidate>
        <div class="field">
          <label for="setup_token">Setup token <span class="opt">(from config/env.php)</span></label>
          <input class="input" id="setup_token" name="setup_token" type="password" autocomplete="off" required>
        </div>
        <div class="grid-2">
          <div class="field">
            <label for="name">Your name</label>
            <input class="input" id="name" name="name" autocomplete="name" required>
          </div>
          <div class="field">
            <label for="email">Email</label>
            <input class="input" id="email" name="email" type="email" autocomplete="email" required>
          </div>
        </div>
        <div class="grid-2">
          <div class="field">
            <label for="password">Password <span class="opt">(min <?= GX_PASSWORD_MIN ?> characters)</span></label>
            <input class="input" id="password" name="password" type="password" autocomplete="new-password" minlength="<?= GX_PASSWORD_MIN ?>" required>
          </div>
          <div class="field">
            <label for="password_confirm">Confirm password</label>
            <input class="input" id="password_confirm" name="password_confirm" type="password" autocomplete="new-password" required>
          </div>
        </div>
        <button class="btn btn-primary btn-lg btn-block" type="submit"<?= $dbOk ? '' : ' disabled' ?>>Create owner account</button>
      </form>
    </section>
  <?php endif; ?>
</main>
<?php require GX_ROOT . '/includes/layout/footer.php'; ?>
