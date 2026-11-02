<?php
/** Admin — media library. */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require GX_ROOT . '/includes/layout/admin-shell.php';

$items = db_all('SELECT m.*, ' . media_uses_sql('m.id') . ' AS uses
                 FROM media m ORDER BY m.id DESC LIMIT 300');
media_prime(array_column($items, 'id'));
admin_start('Media', 'media', ['perm' => 'media.manage']);
?>
<p class="muted small">Every photo is converted to .webp in 3 sizes plus 16:9 crops. Tap ◎ to change what stays in frame.</p>
<div data-media-manager data-name="library">
  <div class="media-grid">
    <label class="media-add"><input type="file" accept="image/jpeg,image/png,image/webp" multiple hidden><svg class="icon icon-lg"><use href="#i-upload"/></svg>Upload photos</label>
    <?php foreach ($items as $m): ?>
      <div class="media-item" data-id="<?= (int) $m['id'] ?>"><img src="<?= e(media_url((int) $m['id'], 480, 'wide') ?? '') ?>" alt="<?= e($m['alt_text']) ?>" loading="lazy">
        <span class="badge mi-first" style="position:absolute;top:6px;left:6px"><?= (int) $m['uses'] ? 'In use' : 'Unused' ?></span>
        <div class="mi-actions"><button type="button" data-focal="<?= (int) $m['id'] ?>">◎</button>
          <?php if (!(int) $m['uses']): ?><button type="button" data-admin-act="/admin/api/media.php" data-payload='<?= e(json_encode(['action' => 'delete', 'id' => (int) $m['id']])) ?>' data-confirm="Delete this photo?" data-danger>🗑</button><?php endif; ?></div></div>
    <?php endforeach; ?>
  </div>
</div>
<?php admin_end(); ?>
