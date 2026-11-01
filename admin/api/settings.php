<?php
/** Admin settings API: {action: settings, s{key: value}} · {action: division, code, ...business fields} */
declare(strict_types=1);

define('GX_IS_API', true);
define('GX_SKIP_MAINTENANCE', true);
require __DIR__ . '/../../includes/bootstrap.php';
require_once GX_ROOT . '/includes/admin_form.php';

api_post();
require_admin_api('settings.manage');

if (input('action') === 'yoco_webhook') {
    try {
        $r = YocoService::registerWebhook();
    } catch (Throwable $e) {
        json_error('Could not connect to Yoco: ' . $e->getMessage(), 409);
    }
    audit('yoco.webhook_registered', null, null, $r);
    json_ok([], $r['already'] ? 'Yoco webhook was already connected (' . $r['mode'] . ' mode).' : 'Yoco webhook connected (' . $r['mode'] . ' mode) — card payments now confirm automatically.');
}

if (input('action') === 'division') {
    $code = (string) input('code');
    if (!isset(GX_DIVISIONS[$code])) {
        json_error('Unknown division.', 400);
    }
    $d = validate_or_fail(input_all(), [
        'legal_name' => 'required|max:191', 'registration_no' => 'nullable|max:50', 'vat_no' => 'nullable|regex:/^4\d{9}$/', 'address' => 'nullable|max:500',
        'phone' => 'nullable|max:30', 'email' => 'nullable|email|max:191', 'tagline' => 'nullable|max:255',
        'bank_name' => 'nullable|max:100', 'bank_account_name' => 'nullable|max:150', 'bank_account_no' => 'nullable|regex:/^[0-9 ]{6,20}$/',
        'bank_branch_code' => 'nullable|regex:/^[0-9 ]{4,10}$/', 'bank_account_type' => 'nullable|max:40',
        'order_prefix' => 'required|regex:/^[A-Z0-9]{2,10}$/', 'invoice_prefix' => 'required|regex:/^[A-Z0-9-]{2,15}$/',
        'credit_prefix' => 'required|regex:/^[A-Z0-9-]{2,15}$/', 'receipt_prefix' => 'required|regex:/^[A-Z0-9-]{2,15}$/', 'document_footer' => 'nullable|max:1000',
    ]);
    $vatReg = af_bool(input('vat_registered', 0));
    if ($vatReg && empty($d['vat_no'])) {
        json_error(t('error.validation'), 422, ['vat_no' => 'A VAT number is required when VAT registered.']);
    }
    db_exec('UPDATE divisions SET legal_name=?, registration_no=?, vat_registered=?, vat_no=?, address=?, phone=?, email=?, tagline=?, bank_name=?, bank_account_name=?,
             bank_account_no=?, bank_branch_code=?, bank_account_type=?, order_prefix=?, invoice_prefix=?, credit_prefix=?, receipt_prefix=?, document_footer=? WHERE code = ?', [
        $d['legal_name'], $d['registration_no'], $vatReg, $d['vat_no'], $d['address'], $d['phone'], $d['email'], $d['tagline'], $d['bank_name'], $d['bank_account_name'],
        $d['bank_account_no'] ? str_replace(' ', '', $d['bank_account_no']) : null, $d['bank_branch_code'] ? str_replace(' ', '', $d['bank_branch_code']) : null,
        $d['bank_account_type'], $d['order_prefix'], $d['invoice_prefix'], $d['credit_prefix'], $d['receipt_prefix'], $d['document_footer'], $code,
    ]);
    audit('settings.division', 'division', division_id($code));
    json_ok([], 'Business details saved.');
}

// General settings: only keys that already exist can be updated
$known = array_flip(array_keys(settings_all(true)));
$n = 0;
foreach ((array) input('s', []) as $key => $value) {
    if (!isset($known[$key])) {
        continue;
    }
    $value = mb_substr(trim((string) $value), 0, 5000);
    if (str_starts_with((string) $key, 'social_') || $key === 'map_url') {
        if ($value !== '' && !preg_match('#^https://#i', $value)) {
            json_error(t('error.validation'), 422, ["s[{$key}]" => 'Links must start with https://']);
        }
    }
    setting_set((string) $key, $value);
    $n++;
}
audit('settings.saved', null, null, ['count' => $n]);
json_ok([], 'Settings saved.');
