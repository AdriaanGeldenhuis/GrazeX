<?php
/**
 * GrazeX engine tests — run from the project root:
 *   php tests/run.php
 * Uses a separate database "<db.name>_test" (created fresh from install.sql + demo.sql), so your data is safe.
 * Requires the MySQL user to be allowed to create that database (local development only).
 */
declare(strict_types=1);

define('GX_NO_SESSION', true);
define('GX_TESTING', true);
$_SERVER['REQUEST_URI'] = '/tests';
require __DIR__ . '/../includes/bootstrap.php';

$worker = $argv[1] ?? '';
$testDb = env('db.name') . '_test';
$GLOBALS['GX_ENV']['db']['name'] = $testDb;

// ---------------------------------------------------------------------
// Worker mode (used by the concurrency test): place an order for the last unit
// ---------------------------------------------------------------------
if ($worker === '--worker') {
    [$cid, $variantId] = [(int) $argv[2], (int) $argv[3]];
    $_COOKIE = [];
    $_SESSION = ['customer_id' => $cid, 'customer_sv' => 1];
    try {
        CartService::add('feeds', $variantId, 1);
        $slot = DeliveryService::collectionCalendar(1)[0];
        usleep(random_int(0, 20000));
        $o = OrderService::place('feeds', ['billing_name' => 'W', 'payment_method' => 'eft', 'fulfilment' => 'collection',
            'collection_date' => $slot['date'], 'collection_slot_id' => $slot['slots'][0]['id']], current_customer());
        echo 'OK ' . $o['order_no'];
    } catch (Throwable $e) {
        echo 'FAIL ' . $e->getMessage();
    }
    exit;
}

// ---------------------------------------------------------------------
// Fresh test database
// ---------------------------------------------------------------------
$admin = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', env('db.host'), (int) env('db.port', 3306)), (string) env('db.user'), (string) env('db.pass'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin->exec("DROP DATABASE IF EXISTS `{$testDb}`");
$admin->exec("CREATE DATABASE `{$testDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$admin->exec("USE `{$testDb}`");
foreach (['install.sql', 'demo.sql'] as $f) {
    $admin->exec((string) file_get_contents(GX_ROOT . '/database/' . $f));
    while ($admin->query('SELECT 1')) { break; }
}

$pass = 0;
$fail = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? "  \033[32m✓\033[0m " : "  \033[31m✗\033[0m ") . $name . ($ok || $detail === '' ? '' : " — {$detail}") . "\n";
}
function section(string $t): void { echo "\n\033[1m{$t}\033[0m\n"; }
function make_customer(string $email): array
{
    $id = db_insert('INSERT INTO customers (email, password_hash, first_name, last_name, phone, email_verified_at) VALUES (?,?,?,?,?,NOW())',
        [$email, password_hash('x', PASSWORD_BCRYPT), 'Test', 'Buyer', '0821234567']);
    return db_one('SELECT * FROM customers WHERE id = ?', [$id]);
}
function as_customer(array $c): void
{
    $_SESSION = ['customer_id' => (int) $c['id'], 'customer_sv' => 1];
    $_COOKIE = [];
    current_customer(true);
    $r = new ReflectionProperty(CartService::class, 'cartCache');
    $r->setValue(null, []);
}
function stock(int $variantId): int { return (int) db_val('SELECT stock_qty FROM product_variants WHERE id = ?', [$variantId]); }
function slot(int $div): array { $d = DeliveryService::collectionCalendar($div)[0]; return ['collection_date' => $d['date'], 'collection_slot_id' => $d['slots'][0]['id']]; }

// ---------------------------------------------------------------------
section('Money & VAT');
check('VAT from R115 incl at 15% = R15', vat_from_incl(11500, 1500) === 1500);
check('VAT at 0% is zero', vat_from_incl(11500, 0) === 0);
check('to_cents("1 234,50") = 123450', to_cents('1 234,50') === 123450);
check('to_cents("R1,234.50") = 123450', to_cents('R1,234.50') === 123450);
check('money() format', money(123450) === 'R 1 234.50');

section('Pricing');
$v = db_one('SELECT * FROM product_variants WHERE id = 2');
$p1 = PricingService::variantPrice($v, 1);
$p12 = PricingService::variantPrice($v, 12);
$p30 = PricingService::variantPrice($v, 30);
check('special price active (359.00)', $p1['unit'] === 35900 && $p1['on_special']);
check('tier 10+ = 349.00', $p12['unit'] === 34900 && $p12['tier_min'] === 10);
check('tier 25+ = 339.00', $p30['unit'] === 33900);
$lot = db_one('SELECT * FROM lots WHERE id = 1');
$lp = PricingService::lotPrice($lot, 5);
check('per-kg lot estimate = 5 × 28.4kg × R62', $lp['total'] === (int) round(28.40 * 6200) * 5);
check('20% deposit', PricingService::lotDeposit($lot, 100000) === 20000);

section('Document numbering');
$nums = db_tx(static fn() => [DocumentService::nextNumber(1, 'test'), DocumentService::nextNumber(1, 'test'), DocumentService::nextNumber(1, 'test')]);
check('sequential, gap-free (1,2,3)', $nums === [1, 2, 3]);

section('Feeds order: place → POP → verify → invoice → cancel → credit note');
$c1 = make_customer('feeds@test.local');
as_customer($c1);
$before = stock(1);
CartService::add('feeds', 1, 4);
$o = OrderService::place('feeds', ['billing_name' => 'Test Buyer', 'payment_method' => 'eft', 'fulfilment' => 'collection'] + slot(1), current_customer());
check('order number format', (bool) preg_match('/^GXF-\d{2}-\d{5}$/', $o['order_no']), $o['order_no']);
check('status pending_payment + hold set', $o['status'] === 'pending_payment' && $o['hold_expires_at'] !== null);
check('stock deducted by 4', stock(1) === $before - 4, stock(1) . ' vs ' . ($before - 4));
check('ledger row written', (int) db_val("SELECT balance_after FROM stock_movements WHERE order_id = ? AND type = 'sale'", [$o['id']]) === $before - 4);
check('cart emptied', CartService::count('feeds') === 0);
check('order confirmation issued', (bool) db_val("SELECT 1 FROM documents WHERE order_id = ? AND doc_type = 'order_confirmation'", [$o['id']]));
$pid = db_insert("INSERT INTO payments (order_id, division_id, method, purpose, amount_cents, status) VALUES (?, 1, 'eft', 'full', ?, 'awaiting_verification')", [$o['id'], $o['total_cents']]);
$o2 = OrderService::recordPayment((int) $o['id'], (int) $o['total_cents'], 'eft', $pid, ['admin_id' => null]);
check('verified POP → paid', $o2['status'] === 'paid' && $o2['payment_status'] === 'paid');
$inv = db_one("SELECT * FROM documents WHERE order_id = ? AND doc_type IN ('tax_invoice','invoice')", [$o['id']]);
check('invoice issued on payment', (bool) $inv);
check('receipt issued', (bool) db_val("SELECT 1 FROM documents WHERE order_id = ? AND doc_type = 'receipt'", [$o['id']]));
check('invoice total = order total', $inv && (int) $inv['total_cents'] === (int) $o['total_cents']);
$again = OrderService::recordPayment((int) $o['id'], (int) $o['total_cents'], 'eft', $pid);
check('recording the same payment twice is idempotent', (int) $again['amount_paid_cents'] === (int) $o['total_cents']);
OrderService::cancel((int) $o['id'], 'Test cancel', null);
check('cancel restocks', stock(1) === $before);
$cn = db_one("SELECT * FROM documents WHERE order_id = ? AND doc_type = 'credit_note'", [$o['id']]);
check('credit note issued against invoice', $cn && (int) $cn['related_document_id'] === (int) $inv['id'] && (int) $cn['total_cents'] === -(int) $inv['total_cents']);
try { DocumentService::creditNote((int) $inv['id'], 'cancel', 'again'); $dup = true; } catch (DomainException $e) { $dup = false; }
check('second full credit note blocked', $dup === false);

section('VAT-registered division');
db_exec('UPDATE divisions SET vat_registered = 1, vat_no = ? WHERE id = 1', ['4123456789']);
$GLOBALS['GX_DIV_CACHE'] = [];
as_customer($c1);
CartService::add('feeds', 5, 2);
$o = OrderService::place('feeds', ['billing_name' => 'Test Buyer', 'payment_method' => 'cash_on_collection', 'fulfilment' => 'collection'] + slot(1), current_customer());
check('cash on collection → awaiting_collection', $o['status'] === 'awaiting_collection');
check('VAT on order = total × 15/115', (int) $o['vat_cents'] === vat_from_incl((int) $o['total_cents'], 1500));
OrderService::recordPayment((int) $o['id'], (int) $o['total_cents'], 'cash');
$inv = db_one("SELECT * FROM documents WHERE order_id = ? AND doc_type = 'tax_invoice'", [$o['id']]);
check('TAX INVOICE (not plain invoice) when VAT registered', (bool) $inv);
check('invoice VAT + excl = total', $inv && (int) $inv['vat_cents'] + (int) $inv['subtotal_excl_cents'] === (int) $inv['total_cents']);
OrderService::transition((int) $o['id'], 'completed');
check('cash order completes after payment', db_val('SELECT status FROM orders WHERE id = ?', [$o['id']]) === 'completed');

section('Delivery quote');
as_customer($c1);
CartService::add('feeds', 5, 1);
$q = OrderService::quote('feeds', ['fulfilment' => 'delivery', 'town' => 'riverside'], current_customer());
check('zone found (case-insensitive)', $q['zone'] !== null);
check('minimum order enforced', isset($q['errors']['fulfilment']));
$q2 = OrderService::quote('feeds', ['fulfilment' => 'delivery', 'town' => 'Atlantis'], current_customer());
check('unknown town rejected', isset($q2['errors']['town']));
CartService::clear('feeds');

section('Ranch order: reserve → deposit (Yoco webhook) → weigh → invoice → collect → document of identification');
$c2 = make_customer('ranch@test.local');
as_customer($c2);
$lotBefore = (int) db_val('SELECT qty_available FROM lots WHERE id = 1');
CartService::add('ranch', 1, 5);
$ro = OrderService::place('ranch', ['billing_name' => 'Ranch Buyer', 'billing_address' => 'Farm 1', 'buyer_id_number' => '8001015009087',
    'payment_method' => 'eft', 'fulfilment' => 'collection'] + slot(2), current_customer());
check('ranch order reserved', $ro['status'] === 'reserved' && (int) $ro['deposit_due_cents'] > 0);
check('lot quantity reduced', (int) db_val('SELECT qty_available FROM lots WHERE id = 1') === $lotBefore - 5);
check('amount due = deposit', OrderService::amountDue($ro) === (int) $ro['deposit_due_cents']);
// Simulate a Yoco checkout + signed webhook
$payId = db_insert("INSERT INTO payments (order_id, division_id, method, purpose, amount_cents, status, provider_checkout_id, provider_mode) VALUES (?, 2, 'yoco', 'deposit', ?, 'pending', 'ch_test_123', 'test')",
    [$ro['id'], $ro['deposit_due_cents']]);
$body = json_encode(['id' => 'evt_1', 'type' => 'payment.succeeded', 'payload' => ['id' => 'p_1', 'amount' => (int) $ro['deposit_due_cents'], 'currency' => 'ZAR', 'metadata' => ['checkoutId' => 'ch_test_123']]]);
$ts = (string) time();
$secret = (string) env('yoco.test_webhook_secret');
$sig = base64_encode(hash_hmac('sha256', "evt_1.{$ts}.{$body}", base64_decode(substr($secret, 6)), true));
check('webhook signature verifies', YocoService::verifySignature($body, 'evt_1', $ts, 'v1,bad v1,' . $sig) === 'test');
check('tampered body rejected', YocoService::verifySignature($body . ' ', 'evt_1', $ts, 'v1,' . $sig) === null);
check('old timestamp rejected', YocoService::verifySignature($body, 'evt_1', (string) (time() - 900), 'v1,' . $sig) === null);
YocoService::settle(db_one('SELECT * FROM payments WHERE id = ?', [$payId]), (int) $ro['deposit_due_cents'], 'p_1', 'test');
$ro = db_one('SELECT * FROM orders WHERE id = ?', [$ro['id']]);
check('deposit paid', $ro['status'] === 'deposit_paid' && $ro['payment_status'] === 'partial');
YocoService::settle(db_one('SELECT * FROM payments WHERE id = ?', [$payId]), (int) $ro['deposit_due_cents'], 'p_1', 'test');
check('duplicate webhook does not double-count', (int) db_val('SELECT amount_paid_cents FROM orders WHERE id = ?', [$ro['id']]) === (int) $ro['deposit_due_cents']);
$item = db_one('SELECT * FROM order_items WHERE order_id = ?', [$ro['id']]);
$ro = OrderService::finalise((int) $ro['id'], [$item['id'] => 150.0]);
check('finalised with actual weight (150kg × R62)', (int) $ro['total_cents'] === 150 * 6200 && $ro['status'] === 'finalised');
check('ranch invoice issued at finalisation', (bool) db_val("SELECT 1 FROM documents WHERE order_id = ? AND doc_type IN ('tax_invoice','invoice')", [$ro['id']]));
OrderService::transition((int) $ro['id'], 'ready_for_collection');
try { OrderService::transition((int) $ro['id'], 'collected'); $blocked = false; } catch (DomainException $e) { $blocked = true; }
check('cannot hand over livestock with balance outstanding', $blocked);
OrderService::recordPayment((int) $ro['id'], OrderService::amountDue(db_one('SELECT * FROM orders WHERE id = ?', [$ro['id']])), 'card');
OrderService::transition((int) $ro['id'], 'collected');
check('document of identification issued on collection', (bool) db_val("SELECT 1 FROM documents WHERE order_id = ? AND doc_type = 'doc_identification'", [$ro['id']]));

section('Expiry (lazy cron)');
as_customer($c1);
$before = stock(3);
CartService::add('feeds', 3, 2);
$eo = OrderService::place('feeds', ['billing_name' => 'Late Payer', 'payment_method' => 'eft', 'fulfilment' => 'collection'] + slot(1), current_customer());
db_exec('UPDATE orders SET hold_expires_at = NOW() - INTERVAL 1 MINUTE WHERE id = ?', [$eo['id']]);
OrderService::expireDue();
check('unpaid order expired', db_val('SELECT status FROM orders WHERE id = ?', [$eo['id']]) === 'expired');
check('expired stock released', stock(3) === $before);

section('Concurrency: two buyers, one bag left');
db_exec('UPDATE product_variants SET stock_qty = 1 WHERE id = 7');
$cA = make_customer('a@test.local');
$cB = make_customer('b@test.local');
$procs = [];
foreach ([$cA, $cB] as $cx) {
    $procs[] = proc_open([PHP_BINARY, __FILE__, '--worker', (string) $cx['id'], '7'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out[] = $pipes;
}
$results = [];
foreach ($out as $i => $pipes) {
    $results[] = trim((string) stream_get_contents($pipes[1]));
    proc_close($procs[$i]);
}
$oks = count(array_filter($results, static fn($r) => str_starts_with($r, 'OK')));
check('exactly one order succeeds', $oks === 1, implode(' | ', $results));
check('stock never negative', stock(7) === 0);

section('PDF rendering');
if (PdfService::available()) {
    foreach (db_all('SELECT id, doc_type FROM documents GROUP BY doc_type') as $d) {
        try {
            $pdf = DocumentService::pdf((int) $d['id'], true);
            check('PDF ' . $d['doc_type'], str_starts_with($pdf['bytes'], '%PDF'));
        } catch (Throwable $e) {
            check('PDF ' . $d['doc_type'], false, $e->getMessage());
        }
    }
} else {
    echo "  (skipped — run composer install)\n";
}

section('PHP compatibility');
check('no PHP deprecation notices from GrazeX code', empty($GLOBALS['GX_DEPRECATIONS']), implode('; ', array_slice($GLOBALS['GX_DEPRECATIONS'] ?? [], 0, 3)));

echo "\n" . ($fail ? "\033[31m{$fail} failed\033[0m, " : '') . "\033[32m{$pass} passed\033[0m\n";
$admin->exec("DROP DATABASE IF EXISTS `{$testDb}`");
exit($fail ? 1 : 0);
