<?php
/** @var array $summary  @var string $div */
defined('GX_ROOT') || exit;
if (!$summary['lines']) {
    return;
}
$total = $summary['subtotal'] - $summary['discount'];
?>
<dl class="cart-totals">
  <div><dt>Subtotal</dt><dd><?= e(money($summary['subtotal'])) ?></dd></div>
  <?php if ($summary['coupon']): ?>
    <div class="text-accent"><dt>Coupon <?= e($summary['coupon']['code']) ?></dt><dd>−<?= e(money($summary['discount'])) ?></dd></div>
  <?php endif; ?>
  <?php if ($div === 'ranch' && $summary['deposit'] > 0): ?>
    <div><dt>Deposit to reserve</dt><dd><?= e(money($summary['deposit'])) ?></dd></div>
  <?php endif; ?>
  <div class="ct-total"><dt>Total<?= $div === 'feeds' ? ' <small class="dim">(delivery at checkout)</small>' : ' <small class="dim">(estimated)</small>' ?></dt><dd><?= e(money($total)) ?> <?= vat_note($div) ?></dd></div>
</dl>
<div class="grid" style="--grid-gap:10px">
  <a class="btn btn-primary btn-lg btn-block" href="/<?= e($div) ?>/checkout.php"><?= $div === 'ranch' ? 'Reserve now' : 'Checkout' ?> <svg class="icon arrow"><use href="#i-arrow"/></svg></a>
  <a class="btn btn-block" href="/<?= e($div) ?>/cart.php">View full <?= $div === 'ranch' ? 'selection' : 'cart' ?></a>
</div>
