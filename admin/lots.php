<?php
/** Admin — livestock lots list. */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require GX_ROOT . '/includes/layout/admin-shell.php';

$status = query_str('status');
$params = [];
$where = '1=1';
if ($status !== '' && in_array($status, ['draft', 'coming_soon', 'available', 'reserved', 'sold_out', 'archived'], true)) {
    $where = 'l.status = ?';
    $params[] = $status;
}
$lots = db_all("SELECT l.*, b.name AS breed_name, c.name AS category_name,
        (SELECT lm.media_id FROM lot_media lm WHERE lm.lot_id = l.id ORDER BY lm.sort_order LIMIT 1) AS media_id,
        (SELECT COALESCE(SUM(oi.qty),0) FROM order_items oi JOIN orders o ON o.id = oi.order_id WHERE oi.lot_id = l.id AND o.status IN ('reserved','deposit_paid','finalised','ready_for_collection')) AS reserved_qty
    FROM lots l JOIN breeds b ON b.id = l.breed_id JOIN livestock_categories c ON c.id = l.category_id
    WHERE {$where} ORDER BY FIELD(l.status,'available','reserved','coming_soon','draft','sold_out','archived'), l.id DESC", $params);
media_prime(array_column($lots, 'media_id'));

admin_start('Livestock', 'lots', ['perm' => 'lots.manage']);
?>
<div class="adm-bar">
  <div class="chips">
    <?php foreach (['' => 'All', 'available' => 'Available', 'coming_soon' => 'Coming soon', 'draft' => 'Draft', 'sold_out' => 'Sold', 'archived' => 'Archived'] as $k => $l): ?>
      <a class="chip<?= $status === $k ? ' is-active' : '' ?>" href="/admin/lots.php<?= $k ? '?status=' . e($k) : '' ?>"><?= e($l) ?></a>
    <?php endforeach; ?>
  </div>
  <div class="row">
    <a class="btn btn-sm" href="/admin/manage.php?e=breeds">Breeds</a>
    <a class="btn btn-sm" href="/admin/manage.php?e=livestock_categories">Types</a>
    <a class="btn btn-primary" href="/admin/lot.php"><svg class="icon"><use href="#i-plus"/></svg>New lot</a>
  </div>
</div>
<div class="table-wrap"><table class="table dt dt-cards">
  <thead><tr><th></th><th>Lot</th><th>Available</th><th>Weight</th><th>Price</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($lots as $l): [$bl, $bc] = LivestockService::badge($l); $pl = LivestockService::priceLabel($l); ?>
    <tr>
      <td data-label=""><div class="thumb"><?= media_img($l['media_id'] ? (int) $l['media_id'] : null, '', '44px', 'wide', true, 'sheep') ?></div></td>
      <td data-label=""><a class="row-link" href="/admin/lot.php?id=<?= (int) $l['id'] ?>"><?= e($l['title']) ?></a><br><span class="tiny dim"><?= e($l['lot_no']) ?> · <?= e($l['breed_name']) ?> · <?= e($l['category_name']) ?></span></td>
      <td data-label="Head"><strong><?= (int) $l['qty_available'] ?></strong> / <?= (int) $l['qty_total'] ?><?= (int) $l['reserved_qty'] ? '<br><span class="tiny dim">' . (int) $l['reserved_qty'] . ' on open orders</span>' : '' ?></td>
      <td data-label="Avg"><?= $l['avg_weight_kg'] !== null ? e(kg((float) $l['avg_weight_kg'])) : '—' ?></td>
      <td data-label=""><?= e($pl['main']) ?> <span class="tiny dim"><?= e($pl['unit']) ?></span></td>
      <td data-label=""><span class="badge <?= e($bc) ?>"><?= e($bl) ?></span><?= $l['status'] === 'draft' ? ' <span class="badge">Draft</span>' : '' ?></td>
      <td class="actions"><a class="btn btn-sm" href="/admin/lot.php?id=<?= (int) $l['id'] ?>">Edit</a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$lots): ?><tr><td colspan="7" class="muted">No lots yet. <a class="link" href="/admin/lot.php">Add your first lot</a>.</td></tr><?php endif; ?>
  </tbody>
</table></div>
<?php admin_end(); ?>
