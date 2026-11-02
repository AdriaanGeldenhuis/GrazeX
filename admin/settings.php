<?php
/** Admin — settings: general, divisions (business details per division), orders & payments, notifications. */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require GX_ROOT . '/includes/layout/admin-shell.php';

$tab = in_array(query_str('tab'), ['general', 'feeds', 'ranch', 'orders', 'system'], true) ? query_str('tab') : 'general';
$s = settings_all(true);
$v = static fn(string $k) => $s[$k] ?? '';

admin_start('Settings', 'settings', ['perm' => 'settings.manage']);
?>
<div class="tabs mb-3" role="tablist">
  <?php foreach (['general' => 'General', 'feeds' => 'GrazeX Feeds business', 'ranch' => 'GrazeX Ranch business', 'orders' => 'Orders & payments', 'system' => 'System'] as $k => $l): ?>
    <a class="tab" role="tab" aria-selected="<?= $tab === $k ? 'true' : 'false' ?>" href="/admin/settings.php?tab=<?= e($k) ?>"><?= e($l) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($tab === 'feeds' || $tab === 'ranch'): $d = division($tab); ?>
  <form data-admin-form data-api="/admin/api/settings.php" data-action="division" novalidate>
    <input type="hidden" name="code" value="<?= e($tab) ?>">
    <section class="card card-pad adm-section">
      <h2 class="h4 mb-1">Business details on invoices</h2>
      <p class="muted small">Shown on tax invoices, credit notes and in the website footer (ECT Act). Each division can be a separate legal entity.</p>
      <div class="form-grid">
        <?= af_text('legal_name', 'Registered business name', $d['legal_name'], ['required' => true]) ?>
        <?= af_text('registration_no', 'Company / CK registration number', $d['registration_no'], ['optional' => true]) ?>
        <?= af_check('vat_registered', 'VAT registered (issue TAX INVOICES with 15% VAT)', (bool) $d['vat_registered']) ?>
        <?= af_text('vat_no', 'VAT number', $d['vat_no'], ['optional' => true, 'placeholder' => '4xxxxxxxxx']) ?>
        <?= af_textarea('address', 'Physical address', $d['address'], ['span' => true, 'rows' => 3]) ?>
        <?= af_text('phone', 'Phone', $d['phone'], ['optional' => true]) ?>
        <?= af_text('email', 'Email', $d['email'], ['optional' => true, 'type' => 'email']) ?>
        <?= af_text('tagline', 'Tagline', $d['tagline'], ['optional' => true]) ?>
      </div>
    </section>
    <section class="card card-pad adm-section">
      <h2 class="h4 mb-2">Bank details for EFT</h2>
      <div class="form-grid">
        <?= af_text('bank_name', 'Bank', $d['bank_name'], ['placeholder' => 'e.g. FNB']) ?>
        <?= af_text('bank_account_name', 'Account name', $d['bank_account_name']) ?>
        <?= af_text('bank_account_no', 'Account number', $d['bank_account_no'], ['inputmode' => 'numeric']) ?>
        <?= af_text('bank_branch_code', 'Branch code', $d['bank_branch_code'], ['inputmode' => 'numeric']) ?>
        <?= af_text('bank_account_type', 'Account type', $d['bank_account_type'], ['placeholder' => 'Cheque / Current']) ?>
      </div>
    </section>
    <section class="card card-pad adm-section">
      <h2 class="h4 mb-2">Document numbering</h2>
      <div class="form-grid">
        <?= af_text('order_prefix', 'Order number prefix', $d['order_prefix'], ['required' => true, 'hint' => 'e.g. ' . $d['order_prefix'] . '-26-00001']) ?>
        <?= af_text('invoice_prefix', 'Invoice prefix', $d['invoice_prefix'], ['required' => true]) ?>
        <?= af_text('credit_prefix', 'Credit note prefix', $d['credit_prefix'], ['required' => true]) ?>
        <?= af_text('receipt_prefix', 'Receipt prefix', $d['receipt_prefix'], ['required' => true]) ?>
        <?= af_textarea('document_footer', 'Footer text on documents', $d['document_footer'], ['span' => true, 'rows' => 2]) ?>
      </div>
    </section>
    <div class="save-bar"><button class="btn btn-primary btn-lg" type="submit">Save <?= e($d['name']) ?></button></div>
  </form>
<?php else: ?>
  <form data-admin-form data-api="/admin/api/settings.php" data-action="settings" novalidate>
    <section class="card card-pad">
      <div class="form-grid">
      <?php if ($tab === 'general'): ?>
        <?= af_text('s[site_name]', 'Site name', $v('site_name')) ?>
        <?= af_text('s[site_tagline]', 'Tagline', $v('site_tagline')) ?>
        <?= af_text('s[contact_email]', 'Public email', $v('contact_email'), ['type' => 'email']) ?>
        <?= af_text('s[contact_phone]', 'Public phone', $v('contact_phone')) ?>
        <?= af_text('s[whatsapp_number]', 'WhatsApp number', $v('whatsapp_number'), ['hint' => 'e.g. 0821234567 — shows WhatsApp buttons everywhere']) ?>
        <?= af_text('s[business_hours]', 'Business hours', $v('business_hours')) ?>
        <?= af_textarea('s[physical_address]', 'Address (footer & directions)', $v('physical_address'), ['span' => true, 'rows' => 2]) ?>
        <?= af_text('s[map_url]', 'Google Maps link', $v('map_url'), ['span' => true, 'placeholder' => 'https://maps.app.goo.gl/…']) ?>
        <?= af_text('s[social_facebook]', 'Facebook', $v('social_facebook')) ?>
        <?= af_text('s[social_instagram]', 'Instagram', $v('social_instagram')) ?>
        <?= af_text('s[social_tiktok]', 'TikTok', $v('social_tiktok')) ?>
        <?= af_text('s[ranch_brand_mark]', 'Ranch brand / tattoo mark (on documents of identification)', $v('ranch_brand_mark')) ?>
        <?= af_check('s[announcement_enabled]', 'Show announcement bar at the top of the site', $v('announcement_enabled') === '1') ?>
        <?= af_text('s[announcement_text]', 'Announcement text', $v('announcement_text')) ?>
        <?= af_text('s[announcement_link]', 'Announcement link', $v('announcement_link')) ?>
        <?= af_textarea('s[meta_description]', 'Default description for Google', $v('meta_description'), ['span' => true, 'rows' => 2]) ?>
      <?php elseif ($tab === 'orders'): ?>
        <?= af_check('s[payment_yoco_enabled]', 'Card payments (Yoco)', $v('payment_yoco_enabled') === '1', ['hint' => YocoService::configured() ? 'Yoco keys are set (' . YocoService::mode() . ' mode).' : 'Add Yoco keys in config/env.php to activate.']) ?>
        <?php if (YocoService::configured()): $wh = setting('yoco_' . YocoService::mode() . '_webhook_connected_at'); ?>
          <div class="field"><span class="label">Yoco payment confirmations (webhook)</span>
            <div class="row"><?= YocoService::webhookSecret(YocoService::mode()) !== '' ? '<span class="badge badge-success">Connected' . ($wh ? ' ' . e(fmt_date($wh)) : '') . '</span>' : '<span class="badge badge-warning">Not connected</span>' ?>
            <button class="btn btn-sm" type="button" data-admin-act="/admin/api/settings.php" data-payload='{"action":"yoco_webhook"}'>Connect Yoco webhook</button></div>
            <span class="hint">Do this once in test mode and again after switching to live mode.</span></div>
        <?php endif; ?>
        <?= af_check('s[payment_eft_enabled]', 'EFT with proof of payment', $v('payment_eft_enabled') === '1') ?>
        <?= af_check('s[payment_cash_enabled]', 'Pay on collection (cash / card)', $v('payment_cash_enabled') === '1') ?>
        <?= af_text('s[eft_hold_hours]', 'Hours an unpaid EFT order keeps its stock', $v('eft_hold_hours'), ['type' => 'number', 'min' => 1]) ?>
        <?= af_text('s[eft_reminder_hours]', 'Send EFT reminder after (hours)', $v('eft_reminder_hours'), ['type' => 'number', 'min' => 1]) ?>
        <?= af_text('s[yoco_hold_minutes]', 'Minutes an unpaid card order keeps its stock', $v('yoco_hold_minutes'), ['type' => 'number', 'min' => 15]) ?>
        <?= af_text('s[ranch_deposit_hold_hours]', 'Hours a livestock reservation waits for its deposit', $v('ranch_deposit_hold_hours'), ['type' => 'number', 'min' => 1]) ?>
        <?= af_text('s[cash_hold_days]', 'Days after the collection date before unpaid pay-on-collection orders expire', $v('cash_hold_days'), ['type' => 'number', 'min' => 0]) ?>
        <?= af_text('s[max_collection_days_ahead]', 'How many days ahead customers can book collection', $v('max_collection_days_ahead'), ['type' => 'number', 'min' => 1]) ?>
        <?= af_check('s[zero_rating_enabled]', 'Zero-rate eligible feed for verified VAT-registered farmers', $v('zero_rating_enabled') === '1', ['span' => true, 'hint' => 'Only switch on after your accountant confirms (VAT Act Sch 2 Part A).']) ?>
      <?php else: ?>
        <?= af_text('s[low_stock_email]', 'Email for admin alerts (new orders, POPs, low stock)', $v('low_stock_email'), ['type' => 'email']) ?>
        <?= af_text('s[email_hourly_cap]', 'Max emails per hour (Xneelo limit 500)', $v('email_hourly_cap'), ['type' => 'number', 'min' => 10, 'max' => 450]) ?>
        <?= af_text('s[retention_audit_days]', 'Keep the admin activity log for (days)', $v('retention_audit_days'), ['type' => 'number', 'min' => 90, 'hint' => 'POPIA: personal information is not kept longer than needed. Minimum 90.']) ?>
        <?= af_text('s[retention_enquiries_days]', 'Keep enquiries / contact messages for (days)', $v('retention_enquiries_days'), ['type' => 'number', 'min' => 30, 'hint' => 'Orders, invoices and payments are always kept 5 years (tax records).']) ?>
        <?= af_select('s[site_language]', 'Website language', ['en' => 'English', 'af' => 'Afrikaans (add config/lang/af.php first)'], $v('site_language') ?: 'en') ?>
        <?= af_check('s[maintenance_mode]', 'Maintenance mode — only logged-in admins can browse the site', $v('maintenance_mode') === '1', ['span' => true]) ?>
        <div class="span-2 alert alert-info small"><div>Emails waiting: <?= (int) db_val("SELECT COUNT(*) FROM email_queue WHERE status = 'queued'") ?> · failed: <?= (int) db_val("SELECT COUNT(*) FROM email_queue WHERE status = 'failed'") ?> ·
          SMTP <?= MailService::configured() ? 'configured' : '<strong>not configured</strong> (config/env.php)' ?> · PDF library <?= PdfService::available() ? 'installed' : '<strong>missing</strong>' ?> · PHP <?= e(PHP_VERSION) ?></div></div>
      <?php endif; ?>
      </div>
    </section>
    <div class="save-bar"><button class="btn btn-primary btn-lg" type="submit">Save settings</button></div>
  </form>
<?php endif; ?>
<?php admin_end(); ?>
