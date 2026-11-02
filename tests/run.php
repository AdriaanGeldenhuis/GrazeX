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
if (!preg_match('/^[A-Za-z0-9_]+_test$/', $testDb)) {
    fwrite(STDERR, "The database name may only contain letters, digits and _ for the tests (got {$testDb}).\n");
    exit(1);
}
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

if ((string) env('yoco.test_webhook_secret', '') === '' || !str_starts_with((string) env('yoco.test_webhook_secret'), 'whsec_')) {
    fwrite(STDERR, "Set YOCO_TEST_WEBHOOK_SECRET=whsec_<any base64> in your settings file — the webhook tests sign with it.\n");
    exit(1);
}
// Card checkout only needs a key to be offered; no request ever reaches Yoco in these tests
$GLOBALS['GX_ENV']['yoco']['mode'] = 'test';
$GLOBALS['GX_ENV']['yoco']['test_secret_key'] = (string) env('yoco.test_secret_key', '') ?: 'sk_test_local';

// Fixture: EFT is only offered once a division's bank details are filled in
$admin->exec("UPDATE divisions SET bank_name = 'FNB', bank_account_name = 'GrazeX (Pty) Ltd', bank_account_no = '62000000001', bank_branch_code = '250655'");

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
function slot(int $div): array
{
    // First collection slot that still has space (tests place many orders)
    foreach (DeliveryService::collectionCalendar($div) as $d) {
        foreach ($d['slots'] as $sl) {
            if (($sl['left'] ?? 1) > 0) {
                return ['collection_date' => $d['date'], 'collection_slot_id' => $sl['id']];
            }
        }
    }
    throw new RuntimeException('No collection slot with space left.');
}

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


// ---------------------------------------------------------------------
// HTTP harness: the real endpoints on PHP's built-in server, pointed at this test database
// ---------------------------------------------------------------------
final class TestHttp
{
    public string $csrf = '';
    private string $jar;

    public function __construct(public string $base)
    {
        $this->jar = (string) tempnam(sys_get_temp_dir(), 'gxjar');
    }

    public function __destruct()
    {
        @unlink($this->jar);
    }

    /** Load an HTML page and remember its CSRF token. */
    public function page(string $path): string
    {
        [, $html] = $this->send('GET', $path, null, null, 'text/html');
        if (preg_match('/<meta name="csrf-token" content="([^"]+)"/', (string) $html, $m)) {
            $this->csrf = $m[1];
        }
        return (string) $html;
    }

    /** JSON request → [status, decoded body]. $files: ['field' => '/path/to/file'] (multipart). */
    public function api(string $method, string $path, ?array $body = null, ?array $files = null, array $headers = []): array
    {
        [$status, $raw] = $this->send($method, $path, $body, $files, 'application/json', $headers);
        return [$status, json_decode((string) $raw, true) ?? ['raw' => $raw]];
    }

    /** GET and return [status, lower-cased response headers]. */
    public function headers(string $path): array
    {
        $ch = curl_init($this->base . $path);
        $hdrs = [];
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 60,
            CURLOPT_HEADERFUNCTION => static function ($c, string $h) use (&$hdrs): int {
                if (str_contains($h, ':')) {
                    [$k, $v] = explode(':', $h, 2);
                    $hdrs[strtolower(trim($k))] = trim($v);
                }
                return strlen($h);
            }]);
        curl_exec($ch);
        return [(int) curl_getinfo($ch, CURLINFO_HTTP_CODE), $hdrs];
    }

    /** Raw POST (webhooks) → [status, decoded body]. */
    public function raw(string $path, string $body, array $headers): array
    {
        $ch = curl_init($this->base . $path);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers)]);
        $out = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return [$status, json_decode((string) $out, true) ?? ['raw' => $out]];
    }

    private function send(string $method, string $path, ?array $body, ?array $files, string $accept, array $extra = []): array
    {
        $ch = curl_init($this->base . $path);
        $headers = array_merge(['Accept: ' . $accept, 'X-Requested-With: fetch'], $extra);
        if ($method !== 'GET') {
            $headers[] = 'X-CSRF-Token: ' . $this->csrf;
        }
        $opts = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 60];
        if ($files !== null) {
            $fields = array_map('strval', $body ?? []);
            foreach ($files as $field => $file) {
                $fields[$field] = new CURLFile($file);
            }
            $opts[CURLOPT_POSTFIELDS] = $fields;
        } elseif ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = json_encode($body);
        }
        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);
        $out = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        return [$status, $out];
    }
}

/** Start `php -S` on a free port with the test database. Returns [process, base URL]. */
function http_start(string $testDb): array
{
    $sock = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr((string) stream_socket_get_name($sock, false), strrpos((string) stream_socket_get_name($sock, false), ':') + 1);
    fclose($sock);
    $env = getenv();
    $env['GX_TEST_DB'] = $testDb;
    $proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, GX_ROOT . '/tests/dev-router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, GX_ROOT, $env);
    for ($i = 0; $i < 100; $i++) {
        if ($fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2)) {
            fclose($fp);
            break;
        }
        usleep(100000);
    }
    return [$proc, 'http://127.0.0.1:' . $port];
}

function webhook_post(TestHttp $h, string $eventId, string $checkoutId, int $amount): array
{
    $body = json_encode(['id' => $eventId, 'type' => 'payment.succeeded', 'payload' => ['id' => 'p_' . $eventId, 'amount' => $amount, 'currency' => 'ZAR', 'metadata' => ['checkoutId' => $checkoutId]]]);
    $ts = (string) time();
    $secret = (string) env('yoco.test_webhook_secret');
    $sig = base64_encode(hash_hmac('sha256', "{$eventId}.{$ts}.{$body}", base64_decode(substr($secret, 6)), true));
    return $h->raw('/payments/api/yoco-webhook.php', $body, ['webhook-id: ' . $eventId, 'webhook-timestamp: ' . $ts, 'webhook-signature: v1,' . $sig]);
}

function order_row(int $id): array { return db_one('SELECT * FROM orders WHERE id = ?', [$id]); }
function notified(string $type, string $like): bool { return (bool) db_val('SELECT 1 FROM admin_notifications WHERE type = ? AND title LIKE ?', [$type, '%' . $like . '%']); }
function throws(callable $fn): ?string { try { $fn(); return null; } catch (DomainException $e) { return $e->getMessage(); } }
/** Run a setup step; an exception becomes a failed check instead of stopping the whole run. */
function step(string $name, callable $fn): mixed { try { return $fn(); } catch (Throwable $e) { check($name, false, $e->getMessage()); return null; } }
function yoco_pending(array $o, string $checkoutId): int
{
    return db_insert("INSERT INTO payments (order_id, division_id, method, purpose, amount_cents, status, provider_checkout_id, provider_mode, reference)
                      VALUES (?, ?, 'yoco', 'full', ?, 'pending', ?, 'test', ?)", [$o['id'], $o['division_id'], OrderService::amountDue($o), $checkoutId, $o['order_no']]);
}

// The setup lock file lives in the real storage/: remember whether it was there, restore at the end
$setupLockExisted = setup_locked();
// No lazy expiry from page loads during these tests (they control expiry explicitly)
setting_set('last_expiry_run', (string) (time() + 86400));
[$server, $base] = http_start($testDb);
register_shutdown_function(static function () use ($server): void { @proc_terminate($server); });

section('Card payments: webhook retry, payment on a closed order, overpayment');
$c3 = make_customer('pay@test.local');
as_customer($c3);
CartService::add('feeds', 2, 1);
$yo = OrderService::place('feeds', ['billing_name' => 'Card Buyer', 'payment_method' => 'yoco', 'fulfilment' => 'collection'] + slot(1), current_customer());
yoco_pending($yo, 'ch_retry');
// The first delivery crashed after the event row was stored (processed_at stays NULL)
db_exec("INSERT INTO payment_webhook_events (provider, event_id, event_type, checkout_id, payload) VALUES ('yoco', 'evt_retry', 'payment.succeeded', 'ch_retry', '{}')");
$hook = new TestHttp($base);
[$st, $res] = webhook_post($hook, 'evt_retry', 'ch_retry', (int) $yo['total_cents']);
check('webhook retry after a failed first run is processed (not "duplicate")', $st === 200 && ($res['data']['result'] ?? '') === 'settled', json_encode($res));
check('order paid after the retried webhook', order_row((int) $yo['id'])['payment_status'] === 'paid');
[$st, $res] = webhook_post($hook, 'evt_retry', 'ch_retry', (int) $yo['total_cents']);
check('a processed event is answered as duplicate', !empty($res['data']['duplicate']));

as_customer($c3);
CartService::add('feeds', 2, 1);
$co = OrderService::place('feeds', ['billing_name' => 'Card Buyer', 'payment_method' => 'yoco', 'fulfilment' => 'collection'] + slot(1), current_customer());
yoco_pending($co, 'ch_after_cancel');
OrderService::cancel((int) $co['id'], 'Changed my mind');
[$st, $res] = webhook_post($hook, 'evt_after_cancel', 'ch_after_cancel', (int) $co['total_cents']);
$co2 = order_row((int) $co['id']);
check('card payment after cancel: order stays cancelled', $co2['status'] === 'cancelled', $co2['status']);
check('card payment after cancel: money recorded', (int) $co2['amount_paid_cents'] === (int) $co['total_cents']);
check('card payment after cancel: no invoice or receipt issued', !db_val("SELECT 1 FROM documents WHERE order_id = ? AND doc_type IN ('tax_invoice','invoice','receipt')", [$co['id']]));
check('card payment after cancel: refund alert raised', notified('refund_due', $co['order_no']));
[$st, $res] = $hook->api('GET', '/payments/api/status.php?t=' . $co['pay_token']);
check('return page is told the payment was late (refund message)', !empty($res['data']['late']), json_encode($res));
webhook_post($hook, 'evt_after_cancel_2', 'ch_after_cancel', (int) $co['total_cents']);
check('a second late event does not double-count', (int) order_row((int) $co['id'])['amount_paid_cents'] === (int) $co['total_cents']);

as_customer($c3);
CartService::add('feeds', 6, 1);
$ov = OrderService::place('feeds', ['billing_name' => 'Over Payer', 'payment_method' => 'cash_on_collection', 'fulfilment' => 'collection'] + slot(1), current_customer());
OrderService::recordPayment((int) $ov['id'], (int) $ov['total_cents'], 'cash');
OrderService::recordPayment((int) $ov['id'], 5000, 'cash');
check('overpayment raises a refund alert', notified('refund_due', 'Overpayment on ' . $ov['order_no']));

section('Expiry never closes an order that was paid in the meantime');
as_customer($c3);
CartService::add('feeds', 6, 1);
$ex = OrderService::place('feeds', ['billing_name' => 'Race', 'payment_method' => 'eft', 'fulfilment' => 'collection'] + slot(1), current_customer());
db_exec('UPDATE orders SET hold_expires_at = NOW() - INTERVAL 1 MINUTE WHERE id = ?', [$ex['id']]);
OrderService::recordPayment((int) $ex['id'], (int) $ex['total_cents'], 'eft');
check('expire on a just-paid order is refused', throws(static fn() => OrderService::cancel((int) $ex['id'], 'late', null, 'expired')) !== null);
check('paid order is still paid', order_row((int) $ex['id'])['status'] === 'paid');
as_customer($c3);
CartService::add('feeds', 6, 1);
$ex2 = OrderService::place('feeds', ['billing_name' => 'Not yet', 'payment_method' => 'eft', 'fulfilment' => 'collection'] + slot(1), current_customer());
check('expire before the hold ends is refused', throws(static fn() => OrderService::cancel((int) $ex2['id'], 'early', null, 'expired')) !== null);

section('Status changes: no paid-without-money, livestock handed over before completion, refunds');
as_customer($c3);
CartService::add('feeds', 6, 1);
$pp = OrderService::place('feeds', ['billing_name' => 'Hand Paid', 'payment_method' => 'eft', 'fulfilment' => 'collection'] + slot(1), current_customer());
check('pending_payment → paid by hand is refused', throws(static fn() => OrderService::transition((int) $pp['id'], 'paid')) !== null);
check('… and the order is still waiting for payment', order_row((int) $pp['id'])['status'] === 'pending_payment');
$c4 = make_customer('ranch2@test.local');
as_customer($c4);
CartService::add('ranch', 1, 2);
$r2 = OrderService::place('ranch', ['billing_name' => 'Ranch Two', 'billing_address' => 'Farm 2', 'buyer_id_number' => '8001015009087',
    'payment_method' => 'eft', 'fulfilment' => 'collection'] + slot(2), current_customer());
check('reserved → deposit_paid by hand is refused', throws(static fn() => OrderService::transition((int) $r2['id'], 'deposit_paid')) !== null);
check('finalise without a weight for a per-kg line is refused', throws(static fn() => OrderService::finalise((int) $r2['id'], [])) !== null);
check('transition to finalised (no weights) is refused for per-kg lots', throws(static fn() => OrderService::transition((int) $r2['id'], 'finalised')) !== null);
$r2item = db_one('SELECT * FROM order_items WHERE order_id = ?', [$r2['id']]);
step('finalise ranch order (setup)', static fn() => OrderService::finalise((int) $r2['id'], [$r2item['id'] => 61.5]));
step('ready for collection (setup)', static fn() => OrderService::transition((int) $r2['id'], 'ready_for_collection'));
step('record balance (setup)', static fn() => OrderService::recordPayment((int) $r2['id'], OrderService::amountDue(order_row((int) $r2['id'])), 'cash'));
$msg = throws(static fn() => OrderService::transition((int) $r2['id'], 'completed'));
check('livestock cannot be completed before "collected" (Document of Identification)', $msg !== null, (string) $msg);
step('collected (setup)', static fn() => OrderService::transition((int) $r2['id'], 'collected'));
step('completed (setup)', static fn() => order_row((int) $r2['id'])['status'] === 'completed' ?: OrderService::transition((int) $r2['id'], 'completed'));
check('livestock completes after collection', order_row((int) $r2['id'])['status'] === 'completed');

as_customer($c3);
$rfBefore = stock(12);
CartService::add('feeds', 12, 2);
$rf = OrderService::place('feeds', ['billing_name' => 'Refund Me', 'payment_method' => 'cash_on_collection', 'fulfilment' => 'collection'] + slot(1), current_customer());
OrderService::recordPayment((int) $rf['id'], (int) $rf['total_cents'], 'cash');
OrderService::transition((int) $rf['id'], 'completed');
step('refund via status change', static fn() => OrderService::transition((int) $rf['id'], 'refunded', 'Bags were damp'));
$rf2 = order_row((int) $rf['id']);
check('refund: status refunded + payment_status refunded', $rf2['status'] === 'refunded' && $rf2['payment_status'] === 'refunded', $rf2['status'] . '/' . $rf2['payment_status']);
check('refund: credit note issued against the invoice', (bool) db_val("SELECT 1 FROM documents WHERE order_id = ? AND doc_type = 'credit_note'", [$rf['id']]));
check('refund: payments marked refunded', !db_val("SELECT 1 FROM payments WHERE order_id = ? AND status = 'succeeded'", [$rf['id']]));
check('refund without returned goods does not restock', stock(12) === $rfBefore - 2);
as_customer($c3);
CartService::add('feeds', 12, 1);
$rf3 = OrderService::place('feeds', ['billing_name' => 'Refund Restock', 'payment_method' => 'cash_on_collection', 'fulfilment' => 'collection'] + slot(1), current_customer());
OrderService::recordPayment((int) $rf3['id'], (int) $rf3['total_cents'], 'cash');
OrderService::transition((int) $rf3['id'], 'completed');
step('refund with restock', static fn() => OrderService::refund((int) $rf3['id'], 'Returned unopened', null, true));
check('refund with returned goods restocks', stock(12) === $rfBefore - 2);
check('only completed orders can be refunded', step('refund guard', static fn() => throws(static fn() => OrderService::refund((int) $ex2['id'], 'x'))) !== null);
check('the customer gets a refund email (not "cancelled")', (bool) db_val("SELECT 1 FROM email_queue WHERE subject LIKE ? ", ['%' . $rf['order_no'] . ' refunded%']));
step('cancel a paid order (setup)', static fn() => OrderService::cancel((int) $ex['id'], 'Customer changed mind', null));
check('cancelled paid order still shows the money as received', order_row((int) $ex['id'])['payment_status'] === 'paid');
step('mark money refunded', static fn() => OrderService::markMoneyRefunded((int) $ex['id'], 'EFT back on 3 Nov'));
check('money on a cancelled order can be marked refunded', order_row((int) $ex['id'])['payment_status'] === 'refunded'
    && !db_val("SELECT 1 FROM payments WHERE order_id = ? AND status = 'succeeded'", [$ex['id']]));
check('… only once', throws(static fn() => OrderService::markMoneyRefunded((int) $ex['id'], 'again')) !== null);

section('VAT rate, payment methods and R0 lines');
check('non-VAT-registered division stores a 0% rate on the order', (int) db_val('SELECT vat_rate_bp FROM orders WHERE id = ?', [$r2['id']]) === 0);
check('… and on its order lines', (int) db_val('SELECT MAX(vat_rate_bp) FROM order_items WHERE order_id = ?', [$r2['id']]) === 0);
check('VAT-registered division keeps 15%', (int) db_val('SELECT vat_rate_bp FROM orders WHERE id = ?', [$rf['id']]) === 1500);
db_exec("UPDATE divisions SET bank_account_no = NULL WHERE id = 2");
$GLOBALS['GX_DIV_CACHE'] = [];
check('EFT is hidden while a division has no bank details', !in_array('eft', OrderService::allowedPaymentMethods('ranch', 'collection', 1000), true));
check('EFT is offered once bank details exist', in_array('eft', OrderService::allowedPaymentMethods('feeds', 'collection', 0), true));
$keyWas = $GLOBALS['GX_ENV']['yoco']['test_secret_key'];
$GLOBALS['GX_ENV']['yoco']['test_secret_key'] = '';
check('no bank details + no card key: a deposit reservation has no payment method (checkout explains instead)', OrderService::allowedPaymentMethods('ranch', 'collection', 1000) === []);
$GLOBALS['GX_ENV']['yoco']['test_secret_key'] = $keyWas;
db_exec("UPDATE divisions SET bank_account_no = '62000000002' WHERE id = 2");
$GLOBALS['GX_DIV_CACHE'] = [];
db_exec('UPDATE lots SET avg_weight_kg = NULL WHERE id = 1');
as_customer($c4);
CartService::add('ranch', 1, 1);
check('a line priced at R0 cannot be ordered', throws(static fn() => OrderService::place('ranch', ['billing_name' => 'Zero', 'billing_address' => 'Farm', 'buyer_id_number' => '8001015009087',
    'payment_method' => 'eft', 'fulfilment' => 'collection'] + slot(2), current_customer())) !== null);
CartService::clear('ranch');
db_exec('UPDATE lots SET avg_weight_kg = 28.40 WHERE id = 1');

section('Admin APIs over HTTP (real requests, CSRF, permissions)');
$ownerPw = 'Owner-Pass-123!';
db_insert('INSERT INTO admin_users (role_id, email, password_hash, name, division_scope) VALUES (1, ?, ?, ?, ?)', ['owner@test.local', admin_password_hash($ownerPw), 'Owner', 'all']);
db_insert('INSERT INTO admin_users (role_id, email, password_hash, name, division_scope) VALUES (3, ?, ?, ?, ?)', ['staff@test.local', admin_password_hash($ownerPw), 'Staff', 'all']);
$adm = new TestHttp($base);
$adm->page('/admin/login.php');
[$st, $res] = $adm->api('POST', '/admin/api/auth.php', ['action' => 'login', 'email' => 'owner@test.local', 'password' => $ownerPw]);
check('owner logs in', $st === 200 && !empty($res['ok']), json_encode($res));
$adm->page('/admin/');

as_customer($c4);
CartService::add('ranch', 1, 3);
$r3 = OrderService::place('ranch', ['billing_name' => 'Ranch Three', 'billing_address' => 'Farm 3', 'buyer_id_number' => '8001015009087',
    'payment_method' => 'eft', 'fulfilment' => 'collection'] + slot(2), current_customer());
$r3item = db_one('SELECT * FROM order_items WHERE order_id = ?', [$r3['id']]);
[$st, $res] = $adm->api('POST', '/admin/api/orders.php', ['action' => 'finalise', 'id' => $r3['id'], 'weights' => [812.5]]);
check('finalise with weights keyed by position (old bug) is refused', $st === 422, $st . ' ' . json_encode($res));
check('… and nothing was invoiced', order_row((int) $r3['id'])['status'] === 'reserved');
[$st, $res] = $adm->api('POST', '/admin/api/orders.php', ['action' => 'finalise', 'id' => $r3['id'], 'weights' => [(string) $r3item['id'] => '86.5']]);
$r3b = order_row((int) $r3['id']);
check('finalise bills the weight the admin typed', $st === 200 && (int) $r3b['total_cents'] === (int) round(86.5 * (int) $r3item['unit_price_cents']), $st . ' ' . json_encode($res) . ' total ' . $r3b['total_cents']);
check('… and the invoice shows the typed weight', (float) db_val('SELECT actual_weight_kg FROM order_items WHERE id = ?', [$r3item['id']]) === 86.5);

$before2 = db_col('SELECT permission_id FROM role_permissions WHERE role_id = 2 ORDER BY permission_id');
$before3 = db_col('SELECT permission_id FROM role_permissions WHERE role_id = 3 ORDER BY permission_id');
[$st] = $adm->api('POST', '/admin/api/users.php', ['action' => 'perms', 'perm' => [$before2, $before3]]);
check('role permissions sent as a plain list are refused', $st === 400);
check('… and Manager/Staff keep their permissions', db_col('SELECT permission_id FROM role_permissions WHERE role_id = 2 ORDER BY permission_id') === $before2
    && db_col('SELECT permission_id FROM role_permissions WHERE role_id = 3 ORDER BY permission_id') === $before3);
$staffPerms = array_values(array_unique(array_merge($before3, [(string) db_val("SELECT id FROM permissions WHERE slug = 'reports.view'")])));
[$st] = $adm->api('POST', '/admin/api/users.php', ['action' => 'perms', 'perm' => ['2' => $before2, '3' => $staffPerms]]);
check('role permissions keyed by role id are saved', $st === 200 && count(db_col('SELECT permission_id FROM role_permissions WHERE role_id = 3')) === count($staffPerms)
    && db_col('SELECT permission_id FROM role_permissions WHERE role_id = 2 ORDER BY permission_id') === $before2);

[$st, $res] = $adm->api('POST', '/admin/api/inventory.php', ['action' => 'stocktake_start']);
$take = db_one("SELECT * FROM stock_takes WHERE status = 'open' ORDER BY id DESC LIMIT 1");
$line = db_one('SELECT * FROM stock_take_items WHERE stock_take_id = ? AND variant_id = 10', [$take['id']]);
[$st] = $adm->api('POST', '/admin/api/inventory.php', ['action' => 'stocktake_save', 'id' => $take['id'], 'counts' => ['999999' => '5']]);
check('stock take: counts for lines of another take are refused', $st === 409);
[$st] = $adm->api('POST', '/admin/api/inventory.php', ['action' => 'stocktake_save', 'id' => $take['id'], 'counts' => [(string) $line['id'] => '9']]);
check('stock take: count saved on the right line', $st === 200 && (int) db_val('SELECT counted_qty FROM stock_take_items WHERE id = ?', [$line['id']]) === 9
    && (int) db_val('SELECT COUNT(*) FROM stock_take_items WHERE stock_take_id = ? AND counted_qty IS NOT NULL', [$take['id']]) === 1);
[$st] = $adm->api('POST', '/admin/api/inventory.php', ['action' => 'receive', 'supplier_name' => 'Co-op', 'lines' => [['variant_id' => 10, 'qty' => 10, 'unit_cost' => '']]]);
[$st, $res] = $adm->api('POST', '/admin/api/inventory.php', ['action' => 'stocktake_complete', 'id' => $take['id']]);
check('stock take: stock received after counting is kept (9 counted + 10 received)', stock(10) === 19, 'stock ' . stock(10) . ' ' . json_encode($res));

as_customer($c3);
CartService::add('feeds', 6, 1);
$pe = OrderService::place('feeds', ['billing_name' => 'POP Payer', 'payment_method' => 'eft', 'fulfilment' => 'collection'] + slot(1), current_customer());
$popId = db_insert("INSERT INTO payments (order_id, division_id, method, purpose, amount_cents, status, pop_path) VALUES (?, 1, 'eft', 'full', ?, 'awaiting_verification', 'x.pdf')", [$pe['id'], $pe['total_cents']]);
[$st, $res] = $adm->api('POST', '/admin/api/payments.php', ['action' => 'approve', 'id' => $popId, 'amount' => number_format(((int) $pe['total_cents'] + 50000) / 100, 2, '.', '')]);
check('approving more than is due asks for confirmation', $st === 409 && !empty($res['errors']['override']), $st . ' ' . json_encode($res));
check('… and records nothing yet', order_row((int) $pe['id'])['payment_status'] === 'unpaid');
[$st] = $adm->api('POST', '/admin/api/payments.php', ['action' => 'approve', 'id' => $popId, 'amount' => number_format((int) $pe['total_cents'] / 100, 2, '.', '')]);
check('approving the amount due works', $st === 200 && order_row((int) $pe['id'])['payment_status'] === 'paid');

[$st, $res] = $adm->api('POST', '/admin/api/settings.php', ['action' => 'division', 'code' => 'ranch', 'legal_name' => 'GrazeX Ranch',
    'order_prefix' => 'GXF', 'invoice_prefix' => 'GXR-INV', 'credit_prefix' => 'GXR-CN', 'receipt_prefix' => 'GXR-RC']);
check('a prefix already used by the other division is refused', $st === 422 && isset($res['errors']['order_prefix']), json_encode($res));
[$st, $res] = $adm->api('POST', '/admin/api/settings.php', ['action' => 'division', 'code' => 'ranch', 'legal_name' => 'GrazeX Ranch',
    'order_prefix' => 'GXR', 'invoice_prefix' => 'GXR-X', 'credit_prefix' => 'GXR-X', 'receipt_prefix' => 'GXR-RC']);
check('two document types with the same prefix are refused', $st === 422 && isset($res['errors']['credit_prefix']), json_encode($res));

[$st, $res] = $adm->api('POST', '/admin/api/lots.php', ['title' => 'Test lot', 'lot_no' => 'T-1', 'breed_id' => 1, 'category_id' => 1, 'sex' => 'mixed',
    'qty_total' => 5, 'pricing_mode' => 'per_kg', 'price' => '62.00', 'deposit_pct' => 20, 'status' => 'draft', 'avg_weight_kg' => '']);
check('a per-kg lot without an average weight is refused', $st === 422 && isset($res['errors']['avg_weight_kg']), json_encode($res));
[$st, $res] = $adm->api('POST', '/admin/api/lots.php', ['title' => 'Test lot', 'lot_no' => 'T-2', 'breed_id' => 1, 'category_id' => 1, 'sex' => 'mixed',
    'qty_total' => 5, 'pricing_mode' => 'per_kg', 'price' => '62.00', 'deposit_pct' => 20, 'status' => 'draft', 'avg_weight_kg' => '',
    'weighs' => ['1001' => ['weighed_on' => date('Y-m-d'), 'head_count' => 5, 'avg_weight_kg' => '31.5']]]);
check('… but a weighing in the same save supplies it', $st === 200 && (float) db_val("SELECT avg_weight_kg FROM lots WHERE lot_no = 'T-2'") === 31.5, json_encode($res));

$staff = new TestHttp($base);
$staff->page('/admin/login.php');
$staff->api('POST', '/admin/api/auth.php', ['action' => 'login', 'email' => 'staff@test.local', 'password' => $ownerPw]);
$staff->page('/admin/');
[$st] = $staff->api('POST', '/admin/api/orders.php', ['action' => 'refund', 'id' => $r2['id'], 'reason' => 'x']);
check('staff without payments.refund cannot refund', $st === 403);
[$st] = $staff->api('POST', '/admin/api/orders.php', ['action' => 'transition', 'id' => $r2['id'], 'to' => 'refunded']);
check('… not even through a status change', $st === 403);
[$st, $res] = $adm->api('POST', '/admin/api/orders.php', ['action' => 'refund', 'id' => $r2['id'], 'reason' => 'Animal unwell', 'restock' => 0]);
check('owner can refund a completed livestock order', $st === 200 && order_row((int) $r2['id'])['status'] === 'refunded', json_encode($res));

section('Customer APIs over HTTP');
$cust = new TestHttp($base);
$cust->page('/account/login.php');
[$st, $res] = $cust->api('POST', '/account/api/auth.php', ['action' => 'login', 'email' => 'pay@test.local', 'password' => 'x']);
check('customer logs in', $st === 200, json_encode($res));
$cust->page('/account/');
as_customer($c3);
CartService::add('feeds', 6, 1);
$po = OrderService::place('feeds', ['billing_name' => 'Uploader', 'payment_method' => 'eft', 'fulfilment' => 'collection'] + slot(1), current_customer());
$png = (string) tempnam(sys_get_temp_dir(), 'gxpop');
imagepng(imagecreatetruecolor(40, 40), $png);
[$st, $res] = $cust->api('POST', '/account/api/pop-upload.php', ['order_no' => $po['order_no']], ['pop' => $png]);
check('proof of payment upload works', $st === 200, $st . ' ' . json_encode($res));
[$st] = $cust->api('POST', '/account/api/pop-upload.php', ['order_no' => $po['order_no']], ['pop' => $png]);
check('a second proof of payment while one is being checked is refused', $st === 409);
@unlink($png);
as_customer($c3);
CartService::add('feeds', 6, 1);
$cc = OrderService::place('feeds', ['billing_name' => 'Card Open', 'payment_method' => 'yoco', 'fulfilment' => 'collection'] + slot(1), current_customer());
yoco_pending($cc, 'ch_open');
[$st] = $cust->api('POST', '/account/api/orders.php', ['action' => 'cancel', 'order_no' => $cc['order_no']]);
check('customer cannot cancel while a card payment is in progress', $st === 409);
$html = $cust->page('/account/order.php?no=' . rawurlencode($cc['order_no']));
check('… and the order page explains instead of showing a Cancel button', !str_contains($html, 'Cancel this order') && str_contains($html, 'card payment for this order is in progress'));
db_exec("UPDATE payments SET created_at = NOW() - INTERVAL 2 HOUR WHERE provider_checkout_id = 'ch_open'");
[$st] = $cust->api('POST', '/account/api/orders.php', ['action' => 'cancel', 'order_no' => $cc['order_no']]);
check('… but can once that checkout has lapsed', $st === 200);
// Yoco refund confirmed by webhook → the order shows refunded once nothing paid remains
as_customer($c3);
CartService::add('feeds', 6, 1);
$yr = OrderService::place('feeds', ['billing_name' => 'Yoco Refund', 'payment_method' => 'yoco', 'fulfilment' => 'collection'] + slot(1), current_customer());
yoco_pending($yr, 'ch_refund');
webhook_post($hook, 'evt_paid_r', 'ch_refund', (int) $yr['total_cents']);
$rbody = json_encode(['id' => 'evt_refund_r', 'type' => 'refund.succeeded', 'payload' => ['amount' => (int) $yr['total_cents'], 'metadata' => ['checkoutId' => 'ch_refund']]]);
$rts = (string) time();
$rsig = base64_encode(hash_hmac('sha256', "evt_refund_r.{$rts}.{$rbody}", base64_decode(substr((string) env('yoco.test_webhook_secret'), 6)), true));
$hook->raw('/payments/api/yoco-webhook.php', $rbody, ['webhook-id: evt_refund_r', 'webhook-timestamp: ' . $rts, 'webhook-signature: v1,' . $rsig]);
check('Yoco refund webhook marks the order refunded', order_row((int) $yr['id'])['payment_status'] === 'refunded');


section('Security: logins cannot be used to lock someone else out');
$lockPw = 'Lock-Pass-123!';
db_insert('INSERT INTO admin_users (role_id, email, password_hash, name, division_scope) VALUES (1, ?, ?, ?, ?)', ['lock@test.local', admin_password_hash($lockPw), 'Lock Owner', 'all']);
$attacker = new TestHttp($base);
$attacker->page('/admin/login.php');
for ($i = 0; $i < 7; $i++) {
    [$st] = $attacker->api('POST', '/admin/api/auth.php', ['action' => 'login', 'email' => 'lock@test.local', 'password' => 'wrong-' . $i], null, ['X-Test-Remote-Addr: 203.0.113.9']);
}
check('the attacker\'s own address gets blocked', $st === 429, (string) $st);
$owner = new TestHttp($base);
$owner->page('/admin/login.php');
[$st] = $owner->api('POST', '/admin/api/auth.php', ['action' => 'login', 'email' => 'lock@test.local', 'password' => $lockPw], null, ['X-Test-Remote-Addr: 198.51.100.7']);
check('the owner still logs in from their own address', $st === 200, (string) $st);
make_customer('lockme@test.local');
$catk = new TestHttp($base);
$catk->page('/account/login.php');
for ($i = 0; $i < 8; $i++) {
    $catk->api('POST', '/account/api/auth.php', ['action' => 'login', 'email' => 'lockme@test.local', 'password' => 'nope-' . $i], null, ['X-Test-Remote-Addr: 203.0.113.10']);
}
$cown = new TestHttp($base);
$cown->page('/account/login.php');
[$st] = $cown->api('POST', '/account/api/auth.php', ['action' => 'login', 'email' => 'lockme@test.local', 'password' => 'x'], null, ['X-Test-Remote-Addr: 198.51.100.8']);
check('a customer still logs in after someone else hammered their account', $st === 200, (string) $st);
check('unknown-email logins verify against a hash of the same kind as real ones',
    password_get_info(gx_dummy_hash())['algoName'] === password_get_info(customer_password_hash('x'))['algoName']);
$fg = new TestHttp($base);
$fg->page('/account/forgot.php');
$codes = [];
for ($i = 0; $i < 4; $i++) {
    [$codes[]] = $fg->api('POST', '/account/api/auth.php', ['action' => 'forgot', 'email' => 'nobody-' . 'x@test.local']);
}
check('forgot-password limit applies to unknown emails too (no account probing)', $codes === [200, 200, 200, 429], implode(',', $codes));

section('Security: redirects, re-authentication and two-step verification');
check('next=/<tab>/evil.com is not followed', safe_next("/\t/evil.com") === '/' && safe_next("/\n/evil.com") === '/' && safe_next('/account/orders.php') === '/account/orders.php');
$pw = new TestHttp($base);
$pw->page('/admin/login.php');
$pw->api('POST', '/admin/api/auth.php', ['action' => 'login', 'email' => 'lock@test.local', 'password' => $lockPw], null, ['X-Test-Remote-Addr: 198.51.100.7']);
$pw->page('/admin/profile.php');
$sts = [];
for ($i = 0; $i < 6; $i++) {
    [$sts[]] = $pw->api('POST', '/admin/api/auth.php', ['action' => 'password', 'current_password' => 'bad-' . $i, 'password' => 'New-Pass-12345!', 'password_confirm' => 'New-Pass-12345!']);
}
check('guessing the current password is rate limited', end($sts) === 429, implode(',', $sts));
check('… and failed re-authentication is audited', (bool) db_val("SELECT 1 FROM audit_log WHERE action = 'admin.reauth_failed'"));

$tfaPw = 'Tfa-Pass-12345!';
$tfaId = db_insert('INSERT INTO admin_users (role_id, email, password_hash, name, division_scope) VALUES (1, ?, ?, ?, ?)', ['tfa@test.local', admin_password_hash($tfaPw), 'Two Step', 'all']);
$t = new TestHttp($base);
$t->page('/admin/login.php');
$t->api('POST', '/admin/api/auth.php', ['action' => 'login', 'email' => 'tfa@test.local', 'password' => $tfaPw]);
$t->page('/admin/profile.php');
[$st] = $t->api('POST', '/admin/api/auth.php', ['action' => '2fa_setup']);
check('2FA setup needs the password', $st === 422, (string) $st);
[$st, $res] = $t->api('POST', '/admin/api/auth.php', ['action' => '2fa_setup', 'password' => $tfaPw]);
$secret = (string) ($res['data']['secret'] ?? '');
[$st] = $t->api('POST', '/admin/api/auth.php', ['action' => '2fa_enable', 'code' => totp_code($secret)]);
check('2FA switched on', $st === 200 && (int) db_val('SELECT totp_enabled FROM admin_users WHERE id = ?', [$tfaId]) === 1);
check('… secret stored encrypted', str_starts_with((string) db_val('SELECT totp_secret FROM admin_users WHERE id = ?', [$tfaId]), 'enc:'));
$profile = $t->page('/admin/profile.php');
preg_match_all('/<li>([A-Z0-9]{4}-[A-Z0-9]{4})<\/li>/', $profile, $rc);
check('8 recovery codes shown once on the profile page', count($rc[1]) === 8);
check('… and not shown again', !preg_match('/<li>[A-Z0-9]{4}-[A-Z0-9]{4}<\/li>/', $t->page('/admin/profile.php')));
$login2 = static function (string $code) use ($base, $tfaPw): int {
    $h = new TestHttp($base);
    $h->page('/admin/login.php');
    $h->api('POST', '/admin/api/auth.php', ['action' => 'login', 'email' => 'tfa@test.local', 'password' => $tfaPw]);
    $h->page('/admin/login.php');
    [$st] = $h->api('POST', '/admin/api/auth.php', ['action' => '2fa', 'code' => $code]);
    return $st;
};
$step = intdiv(time(), 30);
check('login with the authenticator code', $login2(totp_code($secret, $step * 30)) === 200);
check('the same code cannot be used twice', $login2(totp_code($secret, $step * 30)) === 422);
check('a recovery code works instead of the phone', $login2(strtolower($rc[1][0] ?? 'none')) === 200);
check('… only once', $login2($rc[1][0] ?? 'none') === 422);

section('Security: sessions');
$sx = new TestHttp($base);
$sx->page('/account/login.php');
$sx->api('POST', '/account/api/auth.php', ['action' => 'login', 'email' => 'pay@test.local', 'password' => 'x']);
$sx->page('/account/');
$oldCsrf = $sx->csrf;
[$st] = $sx->api('POST', '/account/api/auth.php', ['action' => 'logout']);
$sx->csrf = $oldCsrf;
[$st] = $sx->api('POST', '/account/api/auth.php', ['action' => 'login', 'email' => 'pay@test.local', 'password' => 'x']);
check('after logout the old CSRF token no longer works', $st === 419, (string) $st);

section('Security: admin permissions');
db_exec("INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT 2, id FROM permissions WHERE slug = 'users.manage'");
db_insert('INSERT INTO admin_users (role_id, email, password_hash, name, division_scope) VALUES (2, ?, ?, ?, ?)', ['manager@test.local', admin_password_hash($ownerPw), 'Manager', 'all']);
$mgr = new TestHttp($base);
$mgr->page('/admin/login.php');
$mgr->api('POST', '/admin/api/auth.php', ['action' => 'login', 'email' => 'manager@test.local', 'password' => $ownerPw]);
$mgr->page('/admin/');
[$st] = $mgr->api('POST', '/admin/api/users.php', ['action' => 'create', 'name' => 'Sneaky', 'email' => 'sneaky@test.local', 'role_id' => 1, 'division_scope' => 'all', 'password' => 'Sneaky-Pass-123!']);
check('a manager cannot create an owner (users API is owner-only)', $st === 403 && !db_val("SELECT 1 FROM admin_users WHERE email = 'sneaky@test.local'"), (string) $st);
[$st] = $adm->api('POST', '/admin/api/users.php', ['action' => 'create', 'name' => 'Bad Role', 'email' => 'badrole@test.local', 'role_id' => 999, 'division_scope' => 'all', 'password' => 'Badrole-Pass-123!']);
check('a role that does not exist is refused (no server error)', $st === 422, (string) $st);
$staffPerm = static fn(string $slug) => db_val('SELECT id FROM permissions WHERE slug = ?', [$slug]);
foreach (['customers.view', 'messages.manage', 'orders.manage'] as $slug) {
    db_exec('DELETE FROM role_permissions WHERE role_id = 3 AND permission_id = ?', [$staffPerm($slug)]);
}
$searchHtml = $staff->page('/admin/search.php?q=pay%40test');
check('search does not show customers to a role without customers.view', !str_contains($searchHtml, 'pay@test.local'));
$enq = db_insert("INSERT INTO enquiries (division_id, name, email, message, status) VALUES (1, 'Q', 'q@test.local', 'Hello', 'new')");
[$st] = $staff->headers('/admin/messages.php?id=' . $enq);
check('messages are not opened (or marked read) without messages.manage', $st === 403 && db_val('SELECT status FROM enquiries WHERE id = ?', [$enq]) === 'new', (string) $st);
$doc = (int) db_val("SELECT id FROM documents WHERE division_id = 1 ORDER BY id LIMIT 1");
[$st] = $staff->api('POST', '/admin/api/documents.php', ['action' => 'resend', 'id' => $doc]);
check('resending a document to a customer needs orders.manage', $st === 403, (string) $st);
[$st] = $staff->headers('/admin/reports.php?export=customers');
check('exporting all customers needs customers.view', $st === 403, (string) $st);
db_exec("INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT 3, id FROM permissions WHERE slug IN ('customers.view','messages.manage','orders.manage')");
db_insert('INSERT INTO admin_users (role_id, email, password_hash, name, division_scope) VALUES (2, ?, ?, ?, ?)', ['ranchonly@test.local', admin_password_hash($ownerPw), 'Ranch Only', 'ranch']);
$feedsNote = db_insert("INSERT INTO admin_notifications (division_id, type, title) VALUES (1, 'test', 'Feeds only note')");
$ro = new TestHttp($base);
$ro->page('/admin/login.php');
$ro->api('POST', '/admin/api/auth.php', ['action' => 'login', 'email' => 'ranchonly@test.local', 'password' => $ownerPw]);
$ro->page('/admin/');
$ro->api('POST', '/admin/api/notifications.php', ['action' => 'read_all']);
check('"mark all read" leaves other divisions\' notifications alone', (int) db_val('SELECT is_read FROM admin_notifications WHERE id = ?', [$feedsNote]) === 0);

section('Security: input, uploads and links');
$pub = new TestHttp($base);
$pub->page('/pages/contact.php');
[$st] = $pub->api('POST', '/pages/api/contact.php', ['name' => ['x'], 'email' => 'a@test.local', 'phone' => '0821234567', 'message' => 'Hello there, a question.']);
check('a list where text is expected is a validation error, not a crash', $st === 422, (string) $st);
[$st] = $pub->api('GET', '/feeds/api/products.php?page=99999999999999999999');
check('a huge page number does not break the catalogue', $st === 200, (string) $st);
[$st] = $pub->api('GET', '/ranch/api/lots.php?page=9223372036854775807');
check('… nor the livestock board', $st === 200, (string) $st);
// A tiny PNG whose header claims 30000 × 30000 pixels (would need >3 GB to decode)
$bomb = (string) tempnam(sys_get_temp_dir(), 'gxbomb');
$ihdr = 'IHDR' . pack('NN', 30000, 30000) . "\x08\x02\x00\x00\x00";
file_put_contents($bomb, "\x89PNG\r\n\x1a\n" . pack('N', 13) . $ihdr . pack('N', crc32($ihdr)) . str_repeat("\0", 64));
as_customer($c3);
CartService::add('feeds', 6, 1);
$bo = OrderService::place('feeds', ['billing_name' => 'Bomb', 'payment_method' => 'eft', 'fulfilment' => 'collection'] + slot(1), current_customer());
[$st, $res] = $cust->api('POST', '/account/api/pop-upload.php', ['order_no' => $bo['order_no']], ['pop' => $bomb]);
check('an image bomb is refused before it is decoded', $st === 422 && str_contains((string) ($res['error'] ?? ''), 'too large'), $st . ' ' . json_encode($res));
@unlink($bomb);
$popPay = (int) db_val("SELECT id FROM payments WHERE pop_path LIKE 'storage/private/%' ORDER BY id DESC LIMIT 1");
[$st, $hd] = $adm->headers('/admin/api/documents.php?pop=' . $popPay);
check('uploaded files are served sandboxed (no script can run)', $st === 200 && str_contains($hd['content-security-policy'] ?? '', 'sandbox'), $st . ' ' . json_encode($hd));
check('markdown never links to //other-site', !str_contains(markdown('[x](//evil.com)'), 'href') && str_contains(markdown('[x](/feeds/)'), 'href="/feeds/"'));
[$st] = $adm->api('POST', '/admin/api/settings.php', ['s' => ['announcement_link' => 'javascript:alert(1)']]);
check('announcement link must be https:// or a page on this site', $st === 422, (string) $st);
[$st] = $adm->api('POST', '/admin/api/settings.php', ['s' => ['announcement_link' => '/feeds/specials.php']]);
check('… a page on this site is fine', $st === 200, (string) $st);
$_SERVER['REMOTE_ADDR'] = '2001:db8:abcd:12:1111:2222:3333:4444';
check('IPv6 visitors are rate limited per /64', rate_limit_ip() === '2001:db8:abcd:12::/64');
$_SERVER['REMOTE_ADDR'] = '203.0.113.5';
check('IPv4 visitors per address', rate_limit_ip() === '203.0.113.5');
unset($_SERVER['REMOTE_ADDR']);

section('Security: setup lock, secret key, canonical host');
setup_lock_write();
// A second server whose database does not exist (an outage): the setup page must still not show diagnostics
[$downServer, $downBase] = http_start('gx_unreachable_test');
$down = new TestHttp($downBase);
$setupHtml = $down->page('/setup/');
@proc_terminate($downServer);
check('a locked setup page shows no server/database diagnostics, even while the database is down',
    str_contains($setupHtml, 'Setup is complete') && !str_contains($setupHtml, 'Server check') && !str_contains($setupHtml, 'Tried'), substr(strip_tags($setupHtml), 0, 200));
[$st] = $pub->api('POST', '/setup/api/setup.php', ['setup_token' => 'x', 'name' => 'X', 'email' => 'x@test.local', 'password' => 'Xxxxxxxx-1234', 'password_confirm' => 'Xxxxxxxx-1234']);
check('… and refuses to create another owner', $st === 409, (string) $st);
$keyWas = $GLOBALS['GX_ENV']['app_key'];
$legacy = (static function (string $plain): string {
    $key = hash('sha256', '', true);
    $iv = random_bytes(12);
    $tag = '';
    $c = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $c);
})('whsec_old');
$GLOBALS['GX_ENV']['app_key'] = '';
check('without APP_KEY tokens are never signed with a publicly known key', token_hash('t') !== hash_hmac('sha256', 't', 'grazex') && token_hash('t') !== hash_hmac('sha256', 't', ''));
check('… and the admin is told what is missing', in_array('APP_KEY', gx_settings_problems(), true));
check('… and secrets saved by the old version can still be read', secret_decrypt($legacy) === 'whsec_old');
$GLOBALS['GX_ENV']['app_key'] = $keyWas;
check('secrets round-trip with the real key', secret_decrypt(secret_encrypt('abc')) === 'abc');
$GLOBALS['GX_ENV']['app_url'] = 'https://grazex.example';
$_SERVER['HTTP_HOST'] = 'www.grazex.example';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = '/feeds/shop.php?x=1';
check('www → the APP_URL host (one site, one session)', canonical_host_redirect() === 'https://grazex.example/feeds/shop.php?x=1');
$_SERVER['REQUEST_METHOD'] = 'POST';
check('… never for POSTs (webhooks, forms)', canonical_host_redirect() === null);
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'evil.example';
check('… and never to/for unrelated hosts', canonical_host_redirect() === null);
$_SERVER['HTTP_HOST'] = 'grazex.example';
check('… and not when already on the right host', canonical_host_redirect() === null);
unset($_SERVER['HTTP_HOST'], $_SERVER['REQUEST_METHOD']);
$_SERVER['REQUEST_URI'] = '/tests';

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
if (!$setupLockExisted) {
    @unlink(GX_ROOT . '/storage/private/setup.lock');
}
foreach (db_col("SELECT pop_path FROM payments WHERE pop_path LIKE 'storage/private/%'") as $f) {
    @unlink(GX_ROOT . '/' . $f);
}
$admin->exec("DROP DATABASE IF EXISTS `{$testDb}`");
exit($fail ? 1 : 0);
