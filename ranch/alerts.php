<?php
/**
 * GrazeX Ranch — confirm or stop livestock alerts (links from emails).
 * Opening the link never changes anything (email scanners open links too); the button does, by POST.
 *   ?confirm=TOKEN · ?unsubscribe=TOKEN · ?done=confirmed|unsubscribed
 */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$confirm = query_str('confirm');
$unsub = query_str('unsubscribe');
$done = query_str('done');
$mode = match (true) {
    $done === 'confirmed' => 'confirmed',
    $done === 'unsubscribed' => 'unsubscribed',
    preg_match('/^[a-f0-9]{64}$/', $confirm) === 1 => 'confirm',
    preg_match('/^[a-f0-9]{64}$/', $unsub) === 1 => 'unsubscribe',
    default => 'invalid',
};

$page = [
    'title' => $mode === 'confirm' || $mode === 'confirmed' ? 'Confirm livestock alert' : 'Livestock alerts',
    'division' => 'ranch',
    'css' => ['/assets/css/store.css', '/ranch/css/ranch.css'],
    'noindex' => true,
];
require GX_ROOT . '/includes/layout/head.php';
require GX_ROOT . '/includes/layout/header.php';
?>
<main id="main" class="container page-top">
  <section class="card card-pad" style="max-width:560px;margin-inline:auto">
    <span class="kicker">GrazeX Ranch</span>
    <?php if ($mode === 'confirm'): ?>
      <h1 class="display-3 mt-1">Confirm your alert</h1>
      <p class="muted mt-2">Click the button to start getting an email when new livestock matching your alert is listed. You can stop the emails at any time.</p>
      <form data-api="/ranch/api/alerts.php" data-auto class="mt-3" novalidate>
        <input type="hidden" name="action" value="confirm">
        <input type="hidden" name="token" value="<?= e($confirm) ?>">
        <button class="btn btn-primary btn-lg" type="submit"><svg class="icon"><use href="#i-bell"/></svg>Yes, send me alerts</button>
      </form>
    <?php elseif ($mode === 'unsubscribe'): ?>
      <h1 class="display-3 mt-1">Stop livestock alerts?</h1>
      <p class="muted mt-2">You will no longer get emails about new livestock at this address.</p>
      <form data-api="/ranch/api/alerts.php" data-auto class="mt-3" novalidate>
        <input type="hidden" name="action" value="unsubscribe">
        <input type="hidden" name="token" value="<?= e($unsub) ?>">
        <button class="btn btn-primary btn-lg" type="submit">Unsubscribe</button>
      </form>
    <?php elseif ($mode === 'confirmed'): ?>
      <h1 class="display-3 mt-1">You're signed up</h1>
      <p class="muted mt-2">We'll email you as soon as matching livestock is available.</p>
      <p class="mt-3"><a class="btn btn-primary" href="/ranch/livestock.php">See what's available now</a></p>
    <?php elseif ($mode === 'unsubscribed'): ?>
      <h1 class="display-3 mt-1">You're unsubscribed</h1>
      <p class="muted mt-2">You won't get livestock alerts any more. Changed your mind? Sign up again on any lot page.</p>
      <p class="mt-3"><a class="btn" href="/ranch/">Back to GrazeX Ranch</a></p>
    <?php else: ?>
      <h1 class="display-3 mt-1">This link is not valid</h1>
      <p class="muted mt-2">Please use the full link from the email. You can manage alerts from your account, or contact us.</p>
      <p class="mt-3"><a class="btn" href="/ranch/">Back to GrazeX Ranch</a></p>
    <?php endif; ?>
  </section>
</main>
<?php require GX_ROOT . '/includes/layout/footer.php'; ?>
