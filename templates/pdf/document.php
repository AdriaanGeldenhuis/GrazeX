<?php
/**
 * PDF template for every document type (rendered by Dompdf).
 * Vars: $doc (documents row), $lines (document_lines), $snap (snapshot array), $copy (bool)
 */
defined('GX_ROOT') || exit;

$s = $snap['seller'] ?? [];
$b = $snap['buyer'] ?? [];
$o = $snap['order'] ?? [];
$type = $doc['doc_type'];
$isRanch = ($s['code'] ?? '') === 'ranch';
$accent = $isRanch ? '#5e9a12' : '#ff7a00';
$logo = GX_ROOT . '/assets/img/brand/pdf-' . ($isRanch ? 'ranch' : 'feeds') . '.png';
$isMoneyDoc = in_array($type, ['order_confirmation', 'tax_invoice', 'invoice', 'receipt', 'credit_note', 'debit_note'], true);
$vatReg = !empty($s['vat_registered']);
$title = $snap['title'] ?? strtoupper($type);
$r = static fn(int $c) => money($c);
?><!doctype html>
<html><head><meta charset="utf-8">
<style>
  @page { margin: 0; }
  body { font-family: 'DejaVu Sans', sans-serif; font-size: 9.5pt; color: #1b1f1c; margin: 0; }
  .head { background: #0b0e0d; color: #f4f4ef; padding: 22px 36px 18px; }
  .head table { width: 100%; }
  .head .title { font-size: 20pt; font-weight: bold; color: #ffffff; letter-spacing: 1px; }
  .head .meta { color: #b9c0ba; font-size: 9pt; line-height: 1.5; }
  .bar { height: 5px; background: <?= $accent ?>; }
  .wrap { padding: 22px 36px 120px; }
  .cols { width: 100%; margin-bottom: 18px; }
  .cols td { vertical-align: top; width: 50%; }
  h4 { margin: 0 0 6px; font-size: 8pt; letter-spacing: 1.5px; color: <?= $accent ?>; text-transform: uppercase; }
  .box { line-height: 1.5; }
  .muted { color: #6b736d; }
  table.lines { width: 100%; border-collapse: collapse; margin-top: 6px; }
  table.lines th { background: #f1f3f1; text-align: left; font-size: 7.5pt; letter-spacing: 1px; text-transform: uppercase; color: #4b524d; padding: 7px 8px; border-bottom: 2px solid <?= $accent ?>; }
  table.lines td { padding: 8px; border-bottom: 1px solid #e4e7e4; vertical-align: top; }
  .num { text-align: right; white-space: nowrap; }
  table.totals { width: 46%; margin-left: 54%; margin-top: 10px; border-collapse: collapse; }
  table.totals td { padding: 5px 8px; }
  table.totals .grand td { font-size: 12pt; font-weight: bold; border-top: 2px solid #1b1f1c; padding-top: 8px; }
  .note { margin-top: 18px; padding: 12px 14px; background: #f6f7f6; border-left: 4px solid <?= $accent ?>; line-height: 1.55; }
  .foot { position: fixed; bottom: 0; left: 0; right: 0; padding: 12px 36px; background: #0b0e0d; color: #9ea59f; font-size: 7.5pt; line-height: 1.5; }
  .copy { position: fixed; top: 38%; left: 0; right: 0; text-align: center; font-size: 90pt; color: rgba(200, 30, 30, .12); font-weight: bold; letter-spacing: 12px; }
  .sig { width: 100%; margin-top: 36px; }
  .sig td { width: 50%; padding-right: 30px; vertical-align: bottom; }
  .sig .line { border-top: 1px solid #1b1f1c; padding-top: 4px; margin-top: 40px; font-size: 8pt; color: #4b524d; }
  .badge { display: inline-block; padding: 2px 8px; border-radius: 8px; background: <?= $accent ?>; color: #111; font-size: 8pt; font-weight: bold; }
  .check { width: 14px; height: 14px; border: 1px solid #1b1f1c; display: inline-block; }
</style></head>
<body>
<?php if ($copy): ?><div class="copy">COPY</div><?php endif; ?>

<div class="head">
  <table><tr>
    <td style="width:55%">
      <?php if (is_file($logo)): ?><img src="<?= e($logo) ?>" style="height:66px"><?php endif; ?>
    </td>
    <td style="text-align:right">
      <div class="title"><?= e($title) ?></div>
      <div class="meta">
        <?= $type === 'order_confirmation' ? 'Order' : 'No.' ?> <strong style="color:#fff"><?= e($doc['doc_no']) ?></strong><br>
        Date: <?= e(fmt_date($doc['issued_at'])) ?><br>
        <?php if ($type !== 'order_confirmation' && !empty($o['order_no'])): ?>Order: <?= e($o['order_no']) ?><br><?php endif; ?>
        <?php if ($type === 'credit_note' && !empty($snap['invoice_no'])): ?>Against invoice: <?= e($snap['invoice_no']) ?><br><?php endif; ?>
      </div>
    </td>
  </tr></table>
</div>
<div class="bar"></div>

<div class="wrap">
  <table class="cols"><tr>
    <td>
      <h4>From</h4>
      <div class="box">
        <strong><?= e($s['name'] ?? '') ?></strong><?= !empty($s['trading_name']) && $s['trading_name'] !== $s['name'] ? '<br><span class="muted">t/a ' . e($s['trading_name']) . '</span>' : '' ?><br>
        <?= nl2br(e($s['address'] ?? '')) ?><?= !empty($s['address']) ? '<br>' : '' ?>
        <?php if (!empty($s['registration_no'])): ?>Reg no: <?= e($s['registration_no']) ?><br><?php endif; ?>
        <?php if ($vatReg && !empty($s['vat_no'])): ?>VAT no: <?= e($s['vat_no']) ?><br><?php endif; ?>
        <?= e(implode(' · ', array_filter([$s['phone'] ?? '', $s['email'] ?? '']))) ?>
      </div>
    </td>
    <td>
      <h4><?= $type === 'doc_identification' ? 'Buyer (receiver of stock)' : 'Bill to' ?></h4>
      <div class="box">
        <strong><?= e($b['company'] ?: $b['name']) ?></strong><?= $b['company'] ? '<br>Attn: ' . e($b['name']) : '' ?><br>
        <?= nl2br(e($b['address'] ?? '')) ?><?= !empty($b['address']) ? '<br>' : '' ?>
        <?php if (!empty($b['vat_no'])): ?>VAT no: <?= e($b['vat_no']) ?><br><?php endif; ?>
        <?php if (!empty($b['id_number']) && $isRanch): ?>ID / Reg no: <?= e($b['id_number']) ?><br><?php endif; ?>
        <?= e(implode(' · ', array_filter([$b['phone'] ?? '', $b['email'] ?? '']))) ?>
      </div>
      <?php if (!empty($b['ship']['line1'])): ?>
        <h4 style="margin-top:10px">Deliver to</h4>
        <div class="box"><?= e(implode(', ', array_filter([$b['ship']['name'] ?? '', $b['ship']['farm'] ?? '', $b['ship']['line1'] ?? '', $b['ship']['line2'] ?? '', $b['ship']['town'] ?? '']))) ?></div>
      <?php endif; ?>
    </td>
  </tr></table>

<?php if ($isMoneyDoc): ?>
  <table class="lines">
    <thead><tr>
      <th style="width:46%">Description</th><th class="num">Qty</th><th class="num">Unit price<?= $vatReg ? ' (incl)' : '' ?></th>
      <?php if ($vatReg): ?><th class="num">Excl VAT</th><th class="num">VAT</th><?php endif; ?>
      <th class="num">Amount</th>
    </tr></thead>
    <tbody>
    <?php foreach ($lines as $l): ?>
      <tr>
        <td><?= e($l['description']) ?><?= $l['zero_rated'] && $vatReg ? ' <span class="muted">(zero-rated)</span>' : '' ?></td>
        <td class="num"><?= e(rtrim(rtrim(number_format((float) $l['qty'], 2, '.', ' '), '0'), '.')) ?><?= $l['unit'] && $l['unit'] !== 'unit' ? ' ' . e($l['unit']) : '' ?></td>
        <td class="num"><?= e($r((int) $l['unit_price_incl_cents'])) ?></td>
        <?php if ($vatReg): ?><td class="num"><?= e($r((int) $l['line_excl_cents'])) ?></td><td class="num"><?= e($r((int) $l['line_vat_cents'])) ?></td><?php endif; ?>
        <td class="num"><?= e($r((int) $l['line_incl_cents'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <table class="totals">
    <?php if ($vatReg): ?>
      <tr><td>Total excl. VAT</td><td class="num"><?= e($r((int) $doc['subtotal_excl_cents'])) ?></td></tr>
      <tr><td>VAT (<?= e(number_format(((int) ($lines[0]['vat_rate_bp'] ?? 1500) ?: 1500) / 100, 0)) ?>%)</td><td class="num"><?= e($r((int) $doc['vat_cents'])) ?></td></tr>
    <?php endif; ?>
    <tr class="grand"><td><?= $type === 'receipt' ? 'Amount received' : ($type === 'credit_note' ? 'Total credit' : 'Total' . ($vatReg ? ' incl. VAT' : '')) ?></td><td class="num"><?= e($r((int) $doc['total_cents'])) ?></td></tr>
    <?php if (in_array($type, ['tax_invoice', 'invoice'], true)): ?>
      <tr><td class="muted">Paid to date</td><td class="num muted"><?= e($r((int) ($o['amount_paid_cents'] ?? 0))) ?></td></tr>
      <tr><td><strong>Balance due</strong></td><td class="num"><strong><?= e($r(max(0, (int) $doc['total_cents'] - (int) ($o['amount_paid_cents'] ?? 0)))) ?></strong></td></tr>
    <?php endif; ?>
  </table>

  <?php if ($type === 'credit_note'): ?>
    <div class="note"><strong>Reason:</strong> <?= e(ucfirst(str_replace('_', ' ', (string) $doc['reason_code']))) ?> — <?= e($doc['explanation']) ?></div>
  <?php endif; ?>

  <?php if ($type === 'order_confirmation'): ?>
    <div class="note">
      <?php if (($o['payment_method'] ?? '') === 'eft'): ?>
        <?php if (!empty($s['bank']['account_no'])): ?>
          <strong>Pay by EFT</strong> — <?= e($s['bank']['bank']) ?> · Account name: <?= e($s['bank']['account_name']) ?> · Account no: <?= e($s['bank']['account_no']) ?>
          · Branch: <?= e($s['bank']['branch_code']) ?><?= $s['bank']['account_type'] ? ' · ' . e($s['bank']['account_type']) : '' ?><br>
        <?php else: ?>
          <strong>Pay by EFT</strong> — our banking details will be sent to you by email.<br>
        <?php endif; ?>
        <strong>Reference: <?= e($o['order_no'] ?? $doc['doc_no']) ?></strong><?= !empty($o['hold_expires_at']) ? ' · Please pay before ' . e(fmt_datetime($o['hold_expires_at'])) . ' to keep your stock reserved.' : '' ?><br>
      <?php elseif (($o['payment_method'] ?? '') === 'yoco'): ?>
        <strong>Paying by card.</strong> Your tax invoice will be emailed as soon as your payment is confirmed.<br>
      <?php else: ?>
        <strong>Pay on collection</strong> by cash or card.<br>
      <?php endif; ?>
      <?php if (!empty($o['deposit_due_cents'])): ?>Deposit to reserve: <strong><?= e($r((int) $o['deposit_due_cents'])) ?></strong> · Balance payable at collection.<br><?php endif; ?>
      <?= ($o['fulfilment'] ?? '') === 'delivery' ? 'Delivery date: ' . e(fmt_date($o['delivery_date'] ?? null)) : 'Collection: ' . e(fmt_date($o['collection_date'] ?? null)) ?>
      <br><span class="muted">This is an order confirmation (pro-forma), not a tax invoice.</span>
    </div>
  <?php endif; ?>

<?php elseif ($type === 'doc_identification'): ?>
  <h4>Livestock delivered</h4>
  <table class="lines">
    <thead><tr><th>Number</th><th>Kind</th><th>Breed</th><th>Sex / class</th><th>Description, tags &amp; marks</th></tr></thead>
    <tbody>
    <?php foreach ($snap['lots'] ?? [] as $lot): ?>
      <tr>
        <td><strong><?= (int) $lot['qty'] ?></strong></td><td>Sheep</td><td><?= e($lot['breed']) ?></td>
        <td><?= e(LivestockService::sexLabel((string) $lot['sex'])) ?> · <?= e($lot['category']) ?></td>
        <td><?= e($lot['title']) ?> (lot <?= e($lot['lot_no']) ?>)<?= $lot['tag_no'] ? '<br>Tag: ' . e($lot['tag_no']) : '' ?><?= !empty($s['brand_mark']) ? '<br>Seller\'s mark: ' . e($s['brand_mark']) : '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="note">
    Date of delivery: <strong><?= e(fmt_date($doc['issued_at'])) ?></strong> · Place: <?= e(trim((string) ($s['address'] ?? 'farm'))) ?: 'GrazeX Ranch' ?><br>
    Buyer's brand / mark: <?= e($b['brand_mark'] ?: '—') ?> · Invoice / order: <?= e($o['order_no'] ?? '') ?><br>
    <span class="muted">Document of identification issued by the seller in respect of the stock described above. Keep this document with the animals during transport and retain it for your records.</span>
  </div>
  <table class="sig"><tr>
    <td><div class="line">Seller / authorised person — name &amp; signature</div></td>
    <td><div class="line">Buyer / receiver — name, ID &amp; signature</div></td>
  </tr></table>

<?php else: /* pick list / collection slip / delivery note */ ?>
  <table class="lines">
    <thead><tr><th style="width:8%">✓</th><th>Item</th><th>Code</th><th class="num">Qty</th></tr></thead>
    <tbody>
    <?php foreach ($snap['items'] ?? [] as $it): ?>
      <tr><td><span class="check"></span></td><td><?= e($it['name']) ?><?= $it['variant'] ? ' — ' . e($it['variant']) : '' ?></td><td><?= e($it['sku']) ?></td><td class="num"><strong><?= (int) $it['qty'] ?></strong></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="note">
    <?= ($o['fulfilment'] ?? '') === 'delivery' ? 'Delivery on ' . e(fmt_date($o['delivery_date'] ?? null)) : 'Collection on ' . e(fmt_date($o['collection_date'] ?? null)) ?>
    · Payment: <?= e(strtoupper(str_replace('_', ' ', (string) ($o['payment_method'] ?? '')))) ?>
    <?php if (!empty($o['customer_note'])): ?><br>Customer note: <?= e($o['customer_note']) ?><?php endif; ?>
    <?php if (!empty($b['ship']['directions'])): ?><br>Directions: <?= e($b['ship']['directions']) ?><?php endif; ?>
  </div>
  <table class="sig"><tr>
    <td><div class="line">Packed / released by</div></td>
    <td><div class="line">Received by (name &amp; signature)</div></td>
  </tr></table>
<?php endif; ?>
</div>

<div class="foot">
  <?= e($s['name'] ?? 'GrazeX') ?><?= $vatReg && !empty($s['vat_no']) ? ' · VAT ' . e($s['vat_no']) : '' ?><?= !empty($s['registration_no']) ? ' · Reg ' . e($s['registration_no']) : '' ?>
  <?= !empty($s['footer']) ? '<br>' . e($s['footer']) : '' ?>
</div>
</body></html>
