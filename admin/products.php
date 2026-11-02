<?php
/** Admin — Feeds products list. */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require GX_ROOT . '/includes/layout/admin-shell.php';

$q = mb_substr(query_str('q'), 0, 80);
$cat = query_int('category');
$where = ['1=1'];
$params = [];
if ($q !== '') { $where[] = '(p.name LIKE ? OR EXISTS (SELECT 1 FROM product_variants x WHERE x.product_id = p.id AND x.sku LIKE ?))'; $params[] = "%{$q}%"; $params[] = "%{$q}%"; }
if ($cat) { $where[] = 'p.category_id = ?'; $params[] = $cat; }
$products = db_all('SELECT p.*, c.name AS category_name,
        (SELECT pm.media_id FROM product_media pm WHERE pm.product_id = p.id ORDER BY pm.sort_order LIMIT 1) AS media_id
    FROM products p JOIN categories c ON c.id = p.category_id WHERE ' . implode(' AND ', $where) . ' ORDER BY p.is_active DESC, p.sort_order, p.name', $params);
media_prime(array_column($products, 'media_id'));
$variants = [];
foreach (db_all('SELECT * FROM product_variants ORDER BY sort_order, id') as $v) {
    $variants[$v['product_id']][] = $v;
}
$cats = db_all('SELECT id, name FROM categories ORDER BY sort_order, name');

admin_start('Feeds products', 'products', ['perm' => 'products.manage']);
?>
<div class="adm-bar">
  <form class="adm-filters mb-0" method="get" data-autosubmit>
    <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Search name or SKU">
    <select class="select" name="category"><option value="">All categories</option><?php foreach ($cats as $c): ?><option value="<?= (int) $c['id'] ?>"<?= $cat === (int) $c['id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option><?php endforeach; ?></select>
  </form>
  <div class="row">
    <a class="btn btn-sm" href="/admin/manage.php?e=categories">Categories</a>
    <a class="btn btn-sm" href="/admin/manage.php?e=brands">Brands</a>
    <a class="btn btn-primary" href="/admin/product.php"><svg class="icon"><use href="#i-plus"/></svg>New product</a>
  </div>
</div>
<div class="table-wrap"><table class="table dt dt-cards">
  <thead><tr><th></th><th>Product</th><th>Sizes · price · stock</th><th>Status</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($products as $p): ?>
    <tr>
      <td data-label=""><div class="thumb"><?= media_img($p['media_id'] ? (int) $p['media_id'] : null, '', '44px', 'orig', true, 'bag') ?></div></td>
      <td data-label=""><a class="row-link" href="/admin/product.php?id=<?= (int) $p['id'] ?>"><?= e($p['name']) ?></a><br><span class="tiny dim"><?= e($p['category_name']) ?></span></td>
      <td data-label="">
        <?php foreach ($variants[$p['id']] ?? [] as $v): $s = (int) $v['stock_qty']; ?>
          <div class="small"><?= e($v['name']) ?> · <?= e(money(PricingService::basePrice($v))) ?><?= PricingService::specialActive($v) ? ' <span class="badge badge-solid">special</span>' : '' ?> ·
            <span class="<?= $s <= 0 ? 'stock-out' : ($s <= (int) $v['low_stock_threshold'] ? 'stock-low' : 'stock-ok') ?>"><?= $s ?> in stock</span><?= !(int) $v['is_active'] ? ' <span class="badge">hidden</span>' : '' ?></div>
        <?php endforeach; ?>
      </td>
      <td data-label=""><?= (int) $p['is_active'] ? '<span class="badge badge-success">Live</span>' : '<span class="badge">Hidden</span>' ?><?= (int) $p['is_featured'] ? ' <span class="badge badge-accent">Featured</span>' : '' ?></td>
      <td class="actions"><a class="btn btn-sm" href="/admin/product.php?id=<?= (int) $p['id'] ?>">Edit</a> <a class="btn btn-sm btn-ghost" href="/feeds/product.php?slug=<?= e($p['slug']) ?>" target="_blank" rel="noopener">View</a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$products): ?><tr><td colspan="5" class="muted">No products yet. <a class="link" href="/admin/product.php">Add your first product</a>.</td></tr><?php endif; ?>
  </tbody>
</table></div>
<?php admin_end(); ?>
