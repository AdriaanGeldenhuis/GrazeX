<?php
/** Admin — site content: every text and image on the gateway and both storefronts, and the page background photos. */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require GX_ROOT . '/includes/layout/admin-shell.php';

$scopes = [
    'gateway' => ['Home page (gateway)', '/'],
    'feeds' => ['Feeds shop', '/feeds/'],
    'ranch' => ['Ranch', '/ranch/'],
    'backgrounds' => ['Page backgrounds', '/'],
];
$scope = array_key_exists(query_str('s'), $scopes) ? query_str('s') : 'gateway';
$isBg = $scope === 'backgrounds';
// The background photos are stored in the "global" content scope (see page_background())
$formScope = $isBg ? 'global' : $scope;

// Older keys from the first version of the backgrounds; they are only shown (and replaced) on the backgrounds tab
$legacyBg = ['page_bg', 'account_bg', 'pages_bg'];
$blocks = $isBg ? [] : array_values(array_filter(
    db_all('SELECT * FROM content_blocks WHERE scope = ? ORDER BY sort_order, id', [$scope]),
    static fn(array $b): bool => !in_array($b['block_key'], $legacyBg, true)
));
$extraImages = [
    'gateway' => ['ranch_card_image' => 'Home page — Ranch card photo', 'feeds_card_image' => 'Home page — Feeds card photo'],
    'feeds' => [], 'ranch' => [],
];
$imageHints = [
    'ranch_card_image' => 'The Ranch card in the middle of the home page. The big photo behind the Ranch pages is on the "Page backgrounds" tab.',
    'feeds_card_image' => 'The Feeds card in the middle of the home page. The big photo behind the Feeds pages is on the "Page backgrounds" tab.',
];
$have = array_column($blocks, 'block_key');

// Backgrounds tab: one photo per area, showing the photo that is live now
$bgSlots = [];
if ($isBg) {
    foreach (page_background_slots() as $scene => $slot) {
        $mid = page_background_media($scene);
        $sceneThumb = asset('/assets/img/bg/' . $scene . '-480.webp');
        $bgSlots[$scene] = $slot + [
            'media_id' => $mid,
            'scene_thumb' => $sceneThumb,
            'thumb' => ($mid ? media_url($mid, 480, 'wide') : null) ?: $sceneThumb,
            'dim' => (int) round(page_background_dim($scene) * 100),
        ];
    }
}

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
  <input type="hidden" name="scope" value="<?= e($formScope) ?>">
  <?php if ($isBg): ?>
    <div class="alert alert-info mb-2">
      <div>The big photo behind each part of the website. Use a wide landscape photo (at least 1920 × 1080).
        <strong>Darken</strong> lays a dark layer over it so text stays easy to read — the preview shows how it will look.
        <strong>Remove</strong> puts the built-in GrazeX scene back. To choose which part of the photo phones show, set its focus point in <a class="link" href="/admin/media.php">Media</a>.</div>
    </div>
    <div class="bg-slots">
      <?php foreach ($bgSlots as $scene => $slot): $dimId = 'bg-dim-' . $scene; ?>
        <section class="card card-pad bg-slot" style="--shade:<?= e((string) ($slot['dim'] / 100)) ?>">
          <div class="bg-preview" data-bg-preview data-scene-src="<?= e($slot['scene_thumb']) ?>">
            <img src="<?= e($slot['thumb']) ?>" alt="" width="480" height="270" loading="lazy">
            <span class="bg-preview-text"><small>Preview</small><?= e($slot['label']) ?></span>
          </div>
          <?= af_image('images[bg_' . $scene . ']', $slot['label'], $slot['media_id'], ['hint' => $slot['hint']]) ?>
          <div class="field">
            <label for="<?= e($dimId) ?>">Darken</label>
            <div class="bg-dim-row">
              <input type="range" id="<?= e($dimId) ?>" name="blocks[bg_<?= e($scene) ?>_dim]" min="0" max="<?= PAGE_BG_MAX_DIM ?>" step="5" value="<?= (int) $slot['dim'] ?>" data-bg-dim>
              <output for="<?= e($dimId) ?>" data-bg-dim-out><?= (int) $slot['dim'] ?>%</output>
            </div>
            <span class="hint">0% = the photo as it is · <?= PAGE_BG_MAX_DIM ?>% = very dark</span>
          </div>
        </section>
      <?php endforeach; ?>
    </div>
  <?php else: ?>
    <p class="muted small mb-2">Background photos behind the pages are on the <a class="link" href="/admin/content.php?s=backgrounds">Page backgrounds</a> tab.</p>
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
  <?php endif; ?>
  <div class="save-bar"><button class="btn btn-primary btn-lg" type="submit"><?= $isBg ? 'Save backgrounds' : 'Save content' ?></button></div>
</form>
<?php admin_end(); ?>
