<?php
/** Admin — site content: every text and image on the gateway and both storefronts. */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require GX_ROOT . '/includes/layout/admin-shell.php';

$scopes = ['gateway' => ['Home page (gateway)', '/'], 'feeds' => ['Feeds shop', '/feeds/'], 'ranch' => ['Ranch', '/ranch/']];
$scope = array_key_exists(query_str('s'), $scopes) ? query_str('s') : 'gateway';
$blocks = db_all('SELECT * FROM content_blocks WHERE scope = ? ORDER BY sort_order, id', [$scope]);
$extraImages = [
    'gateway' => ['ranch_card_image' => 'Ranch card photo', 'feeds_card_image' => 'Feeds card photo',
                  'page_bg' => 'Home page background photo', 'account_bg' => 'My account background photo', 'pages_bg' => 'Info & contact pages background photo'],
    'feeds' => ['page_bg' => 'Feeds background photo'],
    'ranch' => ['page_bg' => 'Ranch background photo'],
];
$imageHints = [
    'ranch_card_image' => 'Optional — replaces the scene on the card', 'feeds_card_image' => 'Optional — replaces the scene on the card',
    'page_bg' => 'Optional — a wide landscape photo (at least 1600 px) behind every page here; the page darkens it so text stays readable',
    'account_bg' => 'Optional — a wide landscape photo behind the customer account pages',
    'pages_bg' => 'Optional — a wide landscape photo behind About, Terms, Contact and the other info pages',
];
$have = array_column($blocks, 'block_key');

admin_start('Site content', 'content', ['perm' => 'content.manage']);
?>
<div class="adm-bar">
  <div class="tabs" role="tablist">
    <?php foreach ($scopes as $k => [$l]): ?><a class="tab" role="tab" aria-selected="<?= $scope === $k ? 'true' : 'false' ?>" href="/admin/content.php?s=<?= e($k) ?>"><?= e($l) ?></a><?php endforeach; ?>
  </div>
  <div class="row">
    <a class="btn btn-sm" href="/admin/manage.php?e=pages">Pages</a><a class="btn btn-sm" href="/admin/manage.php?e=faqs">FAQs</a><a class="btn btn-sm" href="/admin/manage.php?e=testimonials">Testimonials</a>
    <a class="btn btn-sm" href="<?= e($scopes[$scope][1]) ?>" target="_blank" rel="noopener">View page ↗</a>
  </div>
</div>
<form data-admin-form data-api="/admin/api/content.php" data-action="save" novalidate>
  <input type="hidden" name="scope" value="<?= e($scope) ?>">
  <section class="card card-pad">
    <div class="form-grid">
      <?php foreach ($blocks as $b):
          $n = 'blocks[' . $b['block_key'] . ']';
          echo match ($b['type']) {
              'textarea' => af_textarea($n, $b['label'], $b['value'], ['span' => true, 'rows' => 3]),
              'markdown' => af_markdown($n, $b['label'], $b['value'], ['span' => true]),
              'image' => af_image('images[' . $b['block_key'] . ']', $extraImages[$scope][$b['block_key']] ?? $b['label'], $b['media_id'] ? (int) $b['media_id'] : null,
                  ['hint' => $imageHints[$b['block_key']] ?? '']),
              default => af_text($n, $b['label'], $b['value']),
          };
      endforeach; ?>
      <?php foreach ($extraImages[$scope] as $k => $label): if (in_array($k, $have, true)) continue; ?>
        <?= af_image('images[' . $k . ']', $label, null, ['hint' => $imageHints[$k] ?? '']) ?>
      <?php endforeach; ?>
    </div>
  </section>
  <div class="save-bar"><button class="btn btn-primary btn-lg" type="submit">Save content</button></div>
</form>
<?php admin_end(); ?>
