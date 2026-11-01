<?php
/** Customer portal — invoices, receipts, credit notes and other documents. */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';

$customer = require_customer();
$docs = db_all("SELECT d.id, d.doc_type, d.doc_no, d.issued_at, d.total_cents, o.order_no, dv.name AS division_name
                FROM documents d JOIN orders o ON o.id = d.order_id JOIN divisions dv ON dv.id = d.division_id
                WHERE d.customer_id = ? AND d.is_void = 0 AND d.doc_type IN ('tax_invoice','invoice','receipt','credit_note','doc_identification','order_confirmation')
                ORDER BY d.issued_at DESC, d.id DESC LIMIT 200", [$customer['id']]);

$page = ['title' => 'My documents', 'division' => 'account', 'css' => ['/account/css/account.css'], 'js' => ['/account/js/account.js'], 'noindex' => true];
require GX_ROOT . '/includes/layout/head.php';
require GX_ROOT . '/includes/layout/header.php';
?>
<main id="main" class="container acc-wrap">
  <div class="acc-head"><div><span class="kicker">My account</span><h1 class="display-3 mt-1">Invoices &amp; documents</h1></div></div>
  <div class="acc-layout">
    <?php $accountTab = 'documents'; require GX_ROOT . '/includes/layout/account-nav.php'; ?>
    <div class="acc-main">
      <?php if ($docs): ?>
        <div class="table-wrap"><table class="table">
          <thead><tr><th>Date</th><th>Document</th><th>Number</th><th>Order</th><th class="num">Amount</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($docs as $d): ?>
            <tr>
              <td class="nowrap"><?= e(fmt_date($d['issued_at'])) ?></td>
              <td><?= e(DocumentService::TITLES[$d['doc_type']] ?? $d['doc_type']) ?><br><span class="tiny dim"><?= e($d['division_name']) ?></span></td>
              <td class="nowrap"><?= e($d['doc_no']) ?></td>
              <td><a class="link" href="/account/order.php?no=<?= e(rawurlencode($d['order_no'])) ?>"><?= e($d['order_no']) ?></a></td>
              <td class="num"><?= in_array($d['doc_type'], ['doc_identification'], true) ? '—' : e(money((int) $d['total_cents'])) ?></td>
              <td class="num"><a class="btn btn-sm" href="/account/api/document.php?id=<?= (int) $d['id'] ?>" target="_blank" rel="noopener"><svg class="icon icon-sm"><use href="#i-download"/></svg>PDF</a></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php else: ?>
        <div class="empty-state"><div class="es-icon"><svg class="icon icon-lg"><use href="#i-file"/></svg></div><h3>No documents yet</h3><p>Invoices and receipts appear here after your first order.</p></div>
      <?php endif; ?>
    </div>
  </div>
</main>
<?php require GX_ROOT . '/includes/layout/footer.php'; ?>
