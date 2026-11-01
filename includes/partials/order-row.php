<?php
/** @var array $o order row (+ div_code) */
defined('GX_ROOT') || exit;
[$label, $cls] = OrderService::label($o['status']);
$due = OrderService::amountDue($o);
$code = $o['div_code'] ?? division_code((int) $o['division_id']);
?>
<a class="order-row" href="/account/order.php?no=<?= e(rawurlencode($o['order_no'])) ?>">
  <div>
    <div class="or-no"><?= e($o['order_no']) ?></div>
    <div class="or-meta"><?= e(fmt_date($o['placed_at'])) ?> · <?= $code === 'ranch' ? 'GrazeX Ranch' : 'GrazeX Feeds' ?> · <?= $o['fulfilment'] === 'delivery' ? 'Delivery ' . e(fmt_date($o['delivery_date'], 'j M')) : 'Collect ' . e(fmt_date($o['collection_date'], 'j M')) ?></div>
  </div>
  <div class="or-total"><?= e(money((int) $o['total_cents'])) ?></div>
  <div class="or-badges">
    <span class="badge <?= e($cls) ?>"><?= e($label) ?></span>
    <?php if ($due > 0 && !in_array($o['status'], ['cancelled', 'expired'], true)): ?><span class="badge badge-warning"><?= e(money($due)) ?> due</span><?php endif; ?>
  </div>
</a>
