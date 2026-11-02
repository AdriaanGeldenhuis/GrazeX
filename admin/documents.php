<?php
/** Admin — all issued documents. */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require GX_ROOT . '/includes/layout/admin-shell.php';

$ids = admin_division_ids();
$type = array_key_exists(query_str('type'), DocumentService::TITLES) ? query_str('type') : '';
$q = mb_substr(query_str('q'), 0, 60);
$where = ['d.division_id IN (' . db_placeholders($ids) . ')'];
$params = $ids;
if ($type) { $where[] = 'd.doc_type = ?'; $params[] = $type; }
if ($q !== '') { $where[] = '(d.doc_no LIKE ? OR o.order_no LIKE ? OR o.billing_name LIKE ?)'; $like = '%' . $q . '%'; array_push($params, $like, $like, $like); }
$perPage = 40;
$pageNo = query_page();
$sql = 'FROM documents d JOIN orders o ON o.id = d.order_id WHERE ' . implode(' AND ', $where);
$total = (int) db_val("SELECT COUNT(*) {$sql}", $params);
$docs = db_all("SELECT d.*, o.order_no, o.billing_name {$sql} ORDER BY d.id DESC LIMIT {$perPage} OFFSET " . (($pageNo - 1) * $perPage), $params);

admin_start('Documents', 'documents', ['perm' => 'documents.view']);
?>
<form class="adm-filters" method="get" data-autosubmit>
  <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Doc no, order no, customer…">
  <select class="select" name="type"><option value="">All types</option>
    <?php foreach (DocumentService::TITLES as $k => $l): ?><option value="<?= e($k) ?>"<?= $type === $k ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
  <a class="btn btn-sm" href="/admin/reports.php?<?= e(http_build_query(array_filter(['export' => 'documents', 'type' => $type, 'q' => $q]))) ?>"><svg class="icon icon-sm"><use href="#i-download"/></svg>CSV</a>
</form>
<div class="table-wrap"><table class="table dt dt-cards">
  <thead><tr><th>Date</th><th>Type</th><th>Number</th><th>Order / customer</th><th class="num">Total</th><th class="num">VAT</th><th></th></tr></thead>
  <tbody>
  <?php foreach ($docs as $d): ?>
    <tr>
      <td data-label=""><?= e(fmt_date($d['issued_at'])) ?></td>
      <td data-label=""><?= e(DocumentService::TITLES[$d['doc_type']] ?? $d['doc_type']) ?> <?= admin_div_chip((int) $d['division_id']) ?></td>
      <td data-label=""><strong><?= e($d['doc_no']) ?></strong><?= $d['is_void'] ? ' <span class="badge badge-danger">Void</span>' : '' ?></td>
      <td data-label=""><a class="link" href="/admin/order.php?id=<?= (int) $d['order_id'] ?>"><?= e($d['order_no']) ?></a> · <?= e($d['billing_name']) ?></td>
      <td class="num" data-label=""><?= e(money((int) $d['total_cents'])) ?></td>
      <td class="num" data-label=""><?= e(money((int) $d['vat_cents'])) ?></td>
      <td class="actions"><a class="btn btn-sm" href="/admin/api/documents.php?id=<?= (int) $d['id'] ?>" target="_blank" rel="noopener">PDF</a></td>
    </tr>
  <?php endforeach; ?>
  <?php if (!$docs): ?><tr><td colspan="7" class="muted">No documents yet.</td></tr><?php endif; ?>
  </tbody>
</table></div>
<?= pagination($pageNo, max(1, (int) ceil($total / $perPage)), '/admin/documents.php') ?>
<?php admin_end(); ?>
