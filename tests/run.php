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

// Worker mode 2 (parallel checkout tests): JSON {cid, div, variant?, lot?, qty?, coupon?, date?, slot?, place_only?}
if ($worker === '--worker2') {
    $w = json_decode((string) ($argv[2] ?? '{}'), true);
    $_COOKIE = [];
    $_SESSION = ['customer_id' => (int) $w['cid'], 'customer_sv' => 1];
    try {
        $div = $w['div'] ?? 'feeds';
        if (empty($w['place_only'])) {
            CartService::add($div, (int) ($w['variant'] ?? $w['lot']), (int) ($w['qty'] ?? 1));
            if (!empty($w['coupon'])) {
                CartService::setCoupon($div, (int) $w['coupon']);
            }
        }
        $cal = DeliveryService::collectionCalendar(division_id($div))[0];
        usleep(random_int(0, 20000));
        $o = OrderService::place($div, ['billing_name' => 'W', 'billing_address' => 'Farm', 'buyer_id_number' => '8001015009087', 'payment_method' => $w['method'] ?? 'eft',
            'fulfilment' => 'collection', 'collection_date' => $w['date'] ?? $cal['date'], 'collection_slot_id' => $w['slot'] ?? $cal['slots'][0]['id']], current_customer());
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
// Fixture: animals may only be handed over with a document of identification, which needs the ranch's brand mark
$admin->exec("UPDATE settings SET setting_value = 'GX1' WHERE setting_key = 'ranch_brand_mark'");

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
    /** Headers sent with every request (e.g. the app's User-Agent). */
    public array $extra = [];
    /** Headers of the last response: lower-case name → list of values. */
    public array $lastHeaders = [];
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

    /** A cookie in this client's jar (null when it has none). */
    public function cookie(string $name): ?string
    {
        foreach (file($this->jar, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $f = explode("\t", preg_replace('/^#HttpOnly_/', '', $line));
            if (count($f) === 7 && !str_starts_with($line, '# ') && $f[5] === $name) {
                return $f[6];
            }
        }
        return null;
    }

    /** Forget a cookie, like a browser that was closed (session cookie) or cleared. */
    public function dropCookie(string $name): void
    {
        $keep = array_filter(file($this->jar, FILE_IGNORE_NEW_LINES) ?: [], static function (string $line) use ($name): bool {
            $f = explode("\t", preg_replace('/^#HttpOnly_/', '', $line));
            return !(count($f) === 7 && $f[5] === $name);
        });
        file_put_contents($this->jar, implode("\n", $keep) . "\n");
    }

    /** Put a cookie in the jar (e.g. a copied remember-me value on another device). */
    public function setCookie(string $name, string $value): void
    {
        $this->dropCookie($name);
        file_put_contents($this->jar, "127.0.0.1\tFALSE\t/\tFALSE\t0\t{$name}\t{$value}\n", FILE_APPEND);
    }

    /** GET and return [status, lower-cased response headers]. */
    public function headers(string $path, array $extra = []): array
    {
        $ch = curl_init($this->base . $path);
        $hdrs = [];
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEJAR => $this->jar, CURLOPT_COOKIEFILE => $this->jar, CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => array_merge($this->extra, $extra),
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
        $headers = array_merge(['Accept: ' . $accept, 'X-Requested-With: fetch'], $this->extra, $extra);
        $this->lastHeaders = [];
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
        $opts[CURLOPT_HEADERFUNCTION] = function ($c, string $h): int {
            if (preg_match('/^X-CSRF-Token:\s*([a-f0-9]+)/i', $h, $m)) {
                $this->csrf = $m[1];
            }
            if (str_contains($h, ':')) {
                [$k, $v] = explode(':', $h, 2);
                $this->lastHeaders[strtolower(trim($k))][] = trim($v);
            }
            return strlen($h);
        };
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

$gxTableCount = (int) db_val('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()');
// The setup lock file lives in the real storage/: remember whether it was there, restore at the end
$setupLockExisted = setup_locked();
register_shutdown_function(static function () use ($setupLockExisted): void {
    if (!$setupLockExisted) {
        @unlink(GX_ROOT . '/storage/private/setup.lock');
    }
});
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



/** Run several checkout workers at the same time; returns their outputs. */
function parallel_place(array $jobs): array
{
    $procs = [];
    $pipesAll = [];
    foreach ($jobs as $j) {
        $procs[] = proc_open([PHP_BINARY, __FILE__, '--worker2', json_encode($j)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $pipesAll[] = $pipes;
    }
    $out = [];
    foreach ($pipesAll as $i => $pipes) {
        $out[] = trim((string) stream_get_contents($pipes[1]));
        proc_close($procs[$i]);
    }
    return $out;
}
$oks = static fn(array $r) => count(array_filter($r, static fn($x) => str_starts_with($x, 'OK')));

section('Checkout: double submits, last slot, last coupon use');
$d1 = make_customer('double@test.local');
as_customer($d1);
CartService::add('feeds', 5, 2);
$before5 = stock(5);
$res = parallel_place([['cid' => $d1['id'], 'place_only' => true], ['cid' => $d1['id'], 'place_only' => true]]);
check('two submits of the same cart make one order', $oks($res) === 1, implode(' | ', $res));
check('… and take the stock once', stock(5) === $before5 - 2, (string) stock(5));
$cal1 = DeliveryService::collectionCalendar(1)[0];
$slotDate = $cal1['date'];
$slotId = (int) $cal1['slots'][0]['id'];
$capWas = (int) db_val('SELECT capacity FROM collection_slots WHERE id = ?', [$slotId]);
$bookedNow = (int) db_val("SELECT COUNT(*) FROM orders WHERE division_id = 1 AND collection_date = ? AND collection_slot_id = ? AND status NOT IN ('cancelled','expired','refunded')", [$slotDate, $slotId]);
db_exec('UPDATE collection_slots SET capacity = ? WHERE id = ?', [$bookedNow + 1, $slotId]);
$s1 = make_customer('slot1@test.local');
$s2 = make_customer('slot2@test.local');
$res = parallel_place([['cid' => $s1['id'], 'variant' => 6, 'date' => $slotDate, 'slot' => $slotId], ['cid' => $s2['id'], 'variant' => 6, 'date' => $slotDate, 'slot' => $slotId]]);
check('two buyers, one place left in a collection slot: one order', $oks($res) === 1, implode(' | ', $res));
db_exec('UPDATE collection_slots SET capacity = ? WHERE id = ?', [$capWas, $slotId]);
$cpn = db_insert("INSERT INTO coupons (code, type, value, division_id, is_active, max_uses) VALUES ('ONCEONLY', 'percent', 10, 1, 1, 1)");
$k1 = make_customer('cpn1@test.local');
$k2 = make_customer('cpn2@test.local');
$res = parallel_place([['cid' => $k1['id'], 'variant' => 6, 'coupon' => $cpn], ['cid' => $k2['id'], 'variant' => 6, 'coupon' => $cpn]]);
check('a coupon with one use left is used once', (int) db_val('SELECT used_count FROM coupons WHERE id = ?', [$cpn]) === 1
    && (int) db_val('SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id = ?', [$cpn]) === 1, implode(' | ', $res));

section('Checkout: coupons, deposits and VAT');
$fd = db_insert("INSERT INTO coupons (code, type, value, division_id, is_active) VALUES ('FREEDEL', 'free_delivery', 0, 1, 1)");
$z1 = make_customer('freedel@test.local');
as_customer($z1);
CartService::add('feeds', 6, 1);
CartService::setCoupon('feeds', $fd);
$zo = OrderService::place('feeds', ['billing_name' => 'Free Del', 'payment_method' => 'eft', 'fulfilment' => 'collection'] + slot(1), current_customer());
check('a coupon that gives nothing (free delivery on collection) is not used up', $zo['coupon_id'] === null && (int) db_val('SELECT used_count FROM coupons WHERE id = ?', [$fd]) === 0);
$today = db_insert('INSERT INTO coupons (code, type, value, division_id, is_active, ends_at) VALUES (?, ?, 5, 1, 1, ?)', ['TODAYONLY', 'percent', date('Y-m-d') . ' 00:00:00']);
check('a coupon ending today still works today', PricingService::checkCoupon('TODAYONLY', 1, 100000, null)['ok'] === true);
db_exec('UPDATE lots SET deposit_pct = 100 WHERE id = 5');
$big = db_insert("INSERT INTO coupons (code, type, value, division_id, is_active) VALUES ('RANCH500', 'fixed', 50000, 2, 1)");
$dep = make_customer('deposit@test.local');
as_customer($dep);
CartService::add('ranch', 5, 1);
CartService::setCoupon('ranch', $big);
$dq = OrderService::quote('ranch', ['fulfilment' => 'collection'], current_customer());
check('the deposit is never more than the order total', $dq['deposit'] <= $dq['total'] && $dq['discount'] > 0, $dq['deposit'] . ' vs ' . $dq['total']);
$ds = CartService::summary('ranch');
check('… and the cart shows the same deposit as checkout', $ds['deposit'] === $dq['deposit'], $ds['deposit'] . ' vs ' . $dq['deposit']);
CartService::clear('ranch');
db_exec('UPDATE lots SET deposit_pct = 20 WHERE id = 5');
setting_set('zero_rating_enabled', '1');
db_exec('UPDATE products p JOIN product_variants v ON v.product_id = p.id SET p.zero_rate_eligible = 1 WHERE v.id = 2');
$vf = make_customer('vatfarmer@test.local');
db_exec("UPDATE customers SET vat_farmer_status = 'verified', vat_no = '4111111111' WHERE id = ?", [$vf['id']]);
$ten = db_insert("INSERT INTO coupons (code, type, value, division_id, is_active) VALUES ('TENOFF', 'percent', 10, 1, 1)");
as_customer(db_one('SELECT * FROM customers WHERE id = ?', [$vf['id']]));
CartService::add('feeds', 2, 1);
CartService::add('feeds', 6, 2);
CartService::setCoupon('feeds', $ten);
$vo = OrderService::place('feeds', ['billing_name' => 'VAT Farmer', 'billing_address' => 'Farm', 'payment_method' => 'cash_on_collection', 'fulfilment' => 'collection'] + slot(1), current_customer());
OrderService::recordPayment((int) $vo['id'], (int) $vo['total_cents'], 'cash');
$vinv = db_one("SELECT * FROM documents WHERE order_id = ? AND doc_type = 'tax_invoice'", [$vo['id']]);
check('order VAT equals the tax invoice VAT (zero-rated + standard lines with a coupon)', $vinv && (int) $vinv['vat_cents'] === (int) db_val('SELECT vat_cents FROM orders WHERE id = ?', [$vo['id']]),
    ($vinv['vat_cents'] ?? 'no invoice') . ' vs ' . db_val('SELECT vat_cents FROM orders WHERE id = ?', [$vo['id']]));
setting_set('zero_rating_enabled', '0');
$hundred = db_insert("INSERT INTO coupons (code, type, value, division_id, is_active) VALUES ('ALLFREE', 'percent', 100, 1, 1)");
$fr = make_customer('free@test.local');
as_customer($fr);
CartService::add('feeds', 6, 1);
CartService::setCoupon('feeds', $hundred);
$fo = OrderService::place('feeds', ['billing_name' => 'Free', 'payment_method' => 'cash_on_collection', 'fulfilment' => 'collection'] + slot(1), current_customer());
check('an order that costs nothing is paid as placed (never expires)', $fo['status'] === 'paid' && $fo['payment_status'] === 'paid' && $fo['hold_expires_at'] === null, $fo['status'] . '/' . $fo['payment_status']);
check('… and gets its invoice', (bool) db_val("SELECT 1 FROM documents WHERE order_id = ? AND doc_type IN ('tax_invoice','invoice')", [$fo['id']]));
check('… with exactly R0.00 VAT on the order and on the invoice', (int) db_val('SELECT vat_cents FROM orders WHERE id = ?', [$fo['id']]) === 0
    && (int) db_val("SELECT vat_cents FROM documents WHERE order_id = ? AND doc_type IN ('tax_invoice','invoice')", [$fo['id']]) === 0);
check('… and its email does not ask for payment', str_contains((string) db_val("SELECT body_html FROM email_queue WHERE subject LIKE ? ORDER BY id DESC LIMIT 1", ['%' . $fo['order_no'] . ' received%']), 'Nothing to pay'));
// Free delivery on zero-rated feed: no VAT is charged at all (the delivery discount is not spread over the feed)
setting_set('zero_rating_enabled', '1');
as_customer(db_one('SELECT * FROM customers WHERE id = ?', [$vf['id']]));
CartService::add('feeds', 2, 2);
CartService::setCoupon('feeds', $fd);
$rz = DeliveryService::zoneForTown('Riverside');
$fdo = OrderService::place('feeds', ['billing_name' => 'VAT Farmer', 'billing_address' => 'Farm', 'payment_method' => 'eft', 'fulfilment' => 'delivery', 'town' => 'Riverside',
    'delivery_date' => DeliveryService::deliveryDates($rz, 10)[0], 'address' => ['recipient_name' => 'VF', 'phone' => '0821234567', 'line1' => '1 Farm']], current_customer());
OrderService::recordPayment((int) $fdo['id'], (int) $fdo['total_cents'], 'eft');
$fdinv = (int) db_val("SELECT vat_cents FROM documents WHERE order_id = ? AND doc_type = 'tax_invoice'", [$fdo['id']]);
check('free delivery on zero-rated feed: no VAT on the order or the invoice', (int) $fdo['delivery_cents'] > 0 && (int) db_val('SELECT vat_cents FROM orders WHERE id = ?', [$fdo['id']]) === 0 && $fdinv === 0,
    db_val('SELECT vat_cents FROM orders WHERE id = ?', [$fdo['id']]) . ' / ' . $fdinv);
setting_set('zero_rating_enabled', '0');
// Finalising a livestock order with a coupon keeps the order VAT equal to the invoice VAT
db_exec("UPDATE divisions SET vat_registered = 1, vat_no = '4222222222' WHERE id = 2");
$GLOBALS['GX_DIV_CACHE'] = [];
$fv = make_customer('finalvat@test.local');
as_customer($fv);
CartService::add('ranch', 1, 2);
CartService::setCoupon('ranch', $big);
$fvo = OrderService::place('ranch', ['billing_name' => 'Final VAT', 'billing_address' => 'Farm', 'buyer_id_number' => '8001015009087', 'payment_method' => 'eft', 'fulfilment' => 'collection'] + slot(2), current_customer());
$fvi = db_one('SELECT * FROM order_items WHERE order_id = ?', [$fvo['id']]);
OrderService::finalise((int) $fvo['id'], [$fvi['id'] => 71.3]);
check('after weighing, the order VAT still equals the tax invoice VAT (coupon included)', (int) db_val('SELECT vat_cents FROM orders WHERE id = ?', [$fvo['id']])
    === (int) db_val("SELECT vat_cents FROM documents WHERE order_id = ? AND doc_type = 'tax_invoice'", [$fvo['id']]),
    db_val('SELECT vat_cents FROM orders WHERE id = ?', [$fvo['id']]) . ' vs ' . db_val("SELECT vat_cents FROM documents WHERE order_id = ? AND doc_type = 'tax_invoice'", [$fvo['id']]));
db_exec("UPDATE divisions SET vat_registered = 0, vat_no = NULL WHERE id = 2");
$GLOBALS['GX_DIV_CACHE'] = [];

section('Checkout: holds, payment methods and the checkout API');
$uv = db_insert('INSERT INTO customers (email, password_hash, first_name, last_name, phone) VALUES (?,?,?,?,?)', ['unverified@test.local', password_hash('x', PASSWORD_BCRYPT), 'Un', 'Verified', '0821234567']);
as_customer(db_one('SELECT * FROM customers WHERE id = ?', [$uv]));
CartService::add('feeds', 6, 1);
$us = slot(1);
$uo = OrderService::place('feeds', ['billing_name' => 'Unverified', 'payment_method' => 'cash_on_collection', 'fulfilment' => 'collection'] + $us, current_customer());
check('an unconfirmed email holds pay-on-collection stock only until the end of the collection day', $uo['hold_expires_at'] === $us['collection_date'] . ' 23:59:59', (string) $uo['hold_expires_at']);
check('a livestock reservation without a deposit can only be paid at the farm', OrderService::allowedPaymentMethods('ranch', 'collection', 0) === ['cash_on_collection']);
setting_set('max_collection_days_ahead', '1');
$far = null;
foreach (DeliveryService::collectionCalendar(1, 30) as $dd) {
    if ($dd['date'] > date('Y-m-d', strtotime('+2 days'))) { $far = $dd; break; }
}
check('"how far ahead" is enforced on the server too (not only at least 14 days)', $far !== null && DeliveryService::validateSlot(1, $far['date'], (int) $far['slots'][0]['id']) === null);
setting_set('max_collection_days_ahead', '30');
$co = new TestHttp($base);
$co->page('/account/login.php');
$co->api('POST', '/account/api/auth.php', ['action' => 'login', 'email' => 'pay@test.local', 'password' => 'x']);
$co->page('/feeds/');
$co->api('POST', '/feeds/api/cart.php', ['action' => 'add', 'target_id' => 9, 'qty' => 3]);
$stock9 = stock(9);
db_exec('UPDATE product_variants SET stock_qty = 2 WHERE id = 9');
$cslot = slot(1);
$placeBody = ['action' => 'place', 'billing_name' => 'Pay Test', 'billing_phone' => '0821234567', 'fulfilment' => 'collection', 'payment_method' => 'eft',
    'accept_terms' => 1, 'collection_date' => $cslot['collection_date'], 'collection_slot_id' => $cslot['collection_slot_id']];
[$st, $res] = $co->api('POST', '/feeds/api/checkout.php', $placeBody);
check('when stock ran low the order is not placed with a silently smaller quantity', $st === 409 && str_contains((string) ($res['error'] ?? ''), 'Only 2'), $st . ' ' . json_encode($res));
[$st, $res] = $co->api('POST', '/feeds/api/checkout.php', $placeBody);
check('… the second attempt (customer has seen it) goes through with the 2 left', $st === 200, $st . ' ' . json_encode($res));
as_customer($c3);
CartService::add('feeds', 12, 3);
db_exec('UPDATE cart_items ci JOIN carts c ON c.id = ci.cart_id SET ci.qty = 999 WHERE c.customer_id = ? AND ci.variant_id = 12', [$c3['id']]);
$msg = throws(static fn() => OrderService::place('feeds', ['billing_name' => 'Direct', 'payment_method' => 'eft', 'fulfilment' => 'collection'] + slot(1), current_customer()));
check('place() itself refuses when its own look at the cart changed something', $msg !== null && str_contains($msg, 'quantity changed'), (string) $msg);
CartService::clear('feeds');
$co->api('POST', '/feeds/api/cart.php', ['action' => 'add', 'target_id' => 6, 'qty' => 1]);
[$st, $res] = $co->api('POST', '/feeds/api/checkout.php', $placeBody + ['expected_total' => 1]);
check('a total different from the one on screen is refused (409) with the new total', $st === 409 && str_contains((string) ($res['error'] ?? ''), 'Your total is now'), $st . ' ' . json_encode($res));
[$st, $qres] = $co->api('POST', '/feeds/api/checkout.php', ['action' => 'quote', 'fulfilment' => 'collection', 'payment_method' => 'eft']);
[$st, $res] = $co->api('POST', '/feeds/api/checkout.php', $placeBody + ['expected_total' => $qres['data']['total'] ?? 0]);
check('… and the order goes through once the page shows the right total', $st === 200, $st . ' ' . json_encode($res));
db_exec('UPDATE product_variants SET stock_qty = 1 WHERE id = 10');
$co->api('POST', '/feeds/api/cart.php', ['action' => 'add', 'target_id' => 10, 'qty' => 1]);
db_exec('UPDATE cart_items ci JOIN carts c ON c.id = ci.cart_id SET ci.qty = 3 WHERE c.customer_id = ? AND ci.variant_id = 10', [$c3['id']]);
check('the checkout page shows when the cart was changed (no silent cap on page load)', str_contains($co->page('/feeds/checkout.php'), 'Your cart was updated'));
$co->api('POST', '/feeds/api/cart.php', ['action' => 'remove', 'item_id' => (int) db_val('SELECT ci.id FROM cart_items ci JOIN carts c ON c.id = ci.cart_id WHERE c.customer_id = ? AND ci.variant_id = 10', [$c3['id']])]);
db_exec('UPDATE product_variants SET stock_qty = 19 WHERE id = 10');
db_exec('UPDATE product_variants SET stock_qty = ? WHERE id = 9', [max(0, $stock9 - 2)]);
$zoneRow = DeliveryService::zoneForTown('Riverside');
$dDate = DeliveryService::deliveryDates($zoneRow, 10)[0] ?? null;
$co->api('POST', '/feeds/api/cart.php', ['action' => 'add', 'target_id' => 6, 'qty' => 1]);
$addrCount = (int) db_val('SELECT COUNT(*) FROM customer_addresses WHERE customer_id = ?', [$c3['id']]);
$delBody = ['action' => 'place', 'billing_name' => 'Pay Test', 'billing_phone' => '0821234567', 'billing_company' => 'Pay Farms (Pty) Ltd', 'fulfilment' => 'delivery', 'payment_method' => 'eft', 'accept_terms' => 1,
    'delivery_date' => $dDate, 'town' => 'Riverside', 'address_id' => 'new', 'save_address' => 1,
    'address' => ['recipient_name' => 'Pay Test', 'phone' => '0821234567', 'line1' => '12 Farm Road']];
[$st, $res] = $co->api('POST', '/feeds/api/checkout.php', $delBody); // below the zone's minimum order → refused
check('a refused order does not save the delivery address', $st !== 200 && (int) db_val('SELECT COUNT(*) FROM customer_addresses WHERE customer_id = ?', [$c3['id']]) === $addrCount, $st . ' ' . json_encode($res));
$co->api('POST', '/feeds/api/cart.php', ['action' => 'add', 'target_id' => 2, 'qty' => 2]);
[$st, $res] = $co->api('POST', '/feeds/api/checkout.php', $delBody);
check('a placed delivery order saves the new address', $st === 200 && (int) db_val('SELECT COUNT(*) FROM customer_addresses WHERE customer_id = ?', [$c3['id']]) === $addrCount + 1, $st . ' ' . json_encode($res));
$co->api('POST', '/feeds/api/cart.php', ['action' => 'add', 'target_id' => 2, 'qty' => 2]);
$co->api('POST', '/feeds/api/cart.php', ['action' => 'add', 'target_id' => 6, 'qty' => 1]);
[$st] = $co->api('POST', '/feeds/api/checkout.php', $delBody);
check('… and the same address is not saved twice', $st === 200 && (int) db_val('SELECT COUNT(*) FROM customer_addresses WHERE customer_id = ?', [$c3['id']]) === $addrCount + 1, (string) $st);
$co->api('POST', '/feeds/api/cart.php', ['action' => 'add', 'target_id' => 2, 'qty' => 2]);
$delBody2 = $delBody;
$delBody2['address']['directions'] = 'Second gate after the silo';
[$st] = $co->api('POST', '/feeds/api/checkout.php', $delBody2);
check('… but new directions for the same address are kept', $st === 200 && db_val("SELECT directions FROM customer_addresses WHERE customer_id = ? AND line1 = '12 Farm Road'", [$c3['id']]) === 'Second gate after the silo', (string) $st);
$cc3 = db_one('SELECT company_name, farm_name FROM customers WHERE id = ?', [$c3['id']]);
check('the billing company is kept as the company name (not the farm name)', $cc3['company_name'] === 'Pay Farms (Pty) Ltd' && $cc3['farm_name'] !== 'Pay Farms (Pty) Ltd', json_encode($cc3));

section('Card payments and proof of payment: edge cases');
as_customer($c3);
CartService::add('feeds', 6, 1);
$lm = OrderService::place('feeds', ['billing_name' => 'Live Mode', 'payment_method' => 'yoco', 'fulfilment' => 'collection'] + slot(1), current_customer());
$lmPay = yoco_pending($lm, 'ch_testmode');
$GLOBALS['GX_ENV']['yoco']['mode'] = 'live';
YocoService::settle(db_one('SELECT * FROM payments WHERE id = ?', [$lmPay]), (int) $lm['total_cents'], 'p_tm', 'test');
YocoService::settle(db_one('SELECT * FROM payments WHERE id = ?', [$lmPay]), (int) $lm['total_cents'], 'p_tm', 'test');
$GLOBALS['GX_ENV']['yoco']['mode'] = 'test';
check('a test-mode card payment never counts on a live site', order_row((int) $lm['id'])['payment_status'] === 'unpaid');
check('… one alert, not one per retry', (int) db_val("SELECT COUNT(*) FROM admin_notifications WHERE title LIKE ?", ['%wrong Yoco mode%' . $lm['order_no'] . '%']) === 1);
as_customer($c3);
CartService::add('feeds', 6, 1);
$mm = OrderService::place('feeds', ['billing_name' => 'Mismatch', 'payment_method' => 'yoco', 'fulfilment' => 'collection'] + slot(1), current_customer());
$mmPay = yoco_pending($mm, 'ch_mismatch');
for ($i = 0; $i < 3; $i++) {
    YocoService::settle(db_one('SELECT * FROM payments WHERE id = ?', [$mmPay]), 123, 'p_mm', 'poll');
}
check('an amount mismatch raises one alert, not one per poll', (int) db_val("SELECT COUNT(*) FROM admin_notifications WHERE title LIKE ?", ['%amount mismatch%' . $mm['order_no'] . '%']) === 1);
as_customer($c3);
CartService::add('feeds', 6, 1);
$rj = OrderService::place('feeds', ['billing_name' => 'Reject', 'payment_method' => 'eft', 'fulfilment' => 'collection'] + slot(1), current_customer());
db_exec('UPDATE orders SET hold_expires_at = NOW() + INTERVAL 1 HOUR WHERE id = ?', [$rj['id']]);
$rjPop = db_insert("INSERT INTO payments (order_id, division_id, method, purpose, amount_cents, status, pop_path) VALUES (?, 1, 'eft', 'full', ?, 'awaiting_verification', 'x.pdf')", [$rj['id'], $rj['total_cents']]);
$adm->api('POST', '/admin/api/payments.php', ['action' => 'reject', 'id' => $rjPop, 'reason' => 'Not in our account']);
check('a rejected proof of payment gives the customer time to upload a new one', (int) db_val('SELECT hold_expires_at > NOW() + INTERVAL 23 HOUR FROM orders WHERE id = ?', [$rj['id']]) === 1);
$pd = make_customer('partial@test.local');
as_customer($pd);
CartService::add('ranch', 2, 1);
$po2 = OrderService::place('ranch', ['billing_name' => 'Partial', 'billing_address' => 'Farm', 'buyer_id_number' => '8001015009087', 'payment_method' => 'eft', 'fulfilment' => 'collection'] + slot(2), current_customer());
OrderService::recordPayment((int) $po2['id'], intdiv((int) $po2['deposit_due_cents'], 2), 'eft');
db_exec('UPDATE orders SET hold_expires_at = NOW() - INTERVAL 1 MINUTE WHERE id = ?', [$po2['id']]);
OrderService::expireDue();
OrderService::expireDue();
check('a half-paid deposit past its hold: one alert to the admin', (int) db_val("SELECT COUNT(*) FROM admin_notifications WHERE type = 'payment_overdue' AND title LIKE ?", ['%' . $po2['order_no']]) === 1);
check('… and the animals stay reserved (not silently held forever without anyone knowing)', order_row((int) $po2['id'])['status'] === 'reserved' && order_row((int) $po2['id'])['hold_expires_at'] === null);
$rv = make_customer('revive@test.local');
as_customer($rv);
CartService::add('feeds', 6, 1);
CartService::setCoupon('feeds', $ten);
$ro2 = OrderService::place('feeds', ['billing_name' => 'Revive', 'payment_method' => 'eft', 'fulfilment' => 'collection'] + slot(1), current_customer());
db_exec('UPDATE orders SET hold_expires_at = NOW() - INTERVAL 1 MINUTE WHERE id = ?', [$ro2['id']]);
OrderService::expireDue();
$usedAfterExpiry = (int) db_val('SELECT used_count FROM coupons WHERE id = ?', [$ten]);
OrderService::recordPayment((int) $ro2['id'], (int) $ro2['total_cents'], 'eft');
$ro2b = order_row((int) $ro2['id']);
check('a late payment reinstates the order cleanly (no "cancelled" stamp)', $ro2b['status'] === 'paid' && $ro2b['cancelled_at'] === null, $ro2b['status']);
check('… and its coupon use is restored', (bool) db_val('SELECT 1 FROM coupon_redemptions WHERE order_id = ?', [$ro2['id']]) && (int) db_val('SELECT used_count FROM coupons WHERE id = ?', [$ten]) === $usedAfterExpiry + 1);
as_customer($c3);
CartService::add('feeds', 6, 1);
$cx = OrderService::place('feeds', ['billing_name' => 'Cancel CN', 'payment_method' => 'cash_on_collection', 'fulfilment' => 'collection'] + slot(1), current_customer());
OrderService::recordPayment((int) $cx['id'], (int) $cx['total_cents'], 'cash');
OrderService::cancel((int) $cx['id'], 'Out of stock at supplier');
check('the cancellation email carries the credit note', str_contains((string) db_val("SELECT attachments FROM email_queue WHERE subject LIKE ? ORDER BY id DESC LIMIT 1", ['%' . $cx['order_no'] . ' cancelled%']), '.pdf'));

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
// Slow guessing from many addresses: an account-wide cap, without letting it lock the owner out
db_exec("UPDATE admin_users SET last_login_ip = '198.51.100.7' WHERE email = 'lock@test.local'");
for ($i = 0; $i < 32; $i++) {
    $ipx = '192.0.2.' . (10 + intdiv($i, 4));
    $attacker->api('POST', '/admin/api/auth.php', ['action' => 'login', 'email' => 'lock@test.local', 'password' => 'guess-' . $i], null, ['X-Test-Remote-Addr: ' . $ipx]);
}
$newPlace = new TestHttp($base);
$newPlace->page('/admin/login.php');
[$st] = $newPlace->api('POST', '/admin/api/auth.php', ['action' => 'login', 'email' => 'lock@test.local', 'password' => $lockPw], null, ['X-Test-Remote-Addr: 192.0.2.200']);
check('after 30+ failures from many addresses, new addresses are refused for that account', $st === 429, (string) $st);
$usual = new TestHttp($base);
$usual->page('/admin/login.php');
[$st] = $usual->api('POST', '/admin/api/auth.php', ['action' => 'login', 'email' => 'lock@test.local', 'password' => $lockPw], null, ['X-Test-Remote-Addr: 198.51.100.7']);
check('… but the owner still gets in from where they usually log in', $st === 200, (string) $st);
for ($i = 0; $i < 32; $i++) {
    $catk->api('POST', '/account/api/auth.php', ['action' => 'login', 'email' => 'lockme@test.local', 'password' => 'g-' . $i], null, ['X-Test-Remote-Addr: 192.0.2.' . (100 + intdiv($i, 5))]);
}
[$st] = $cown->api('POST', '/account/api/auth.php', ['action' => 'login', 'email' => 'lockme@test.local', 'password' => 'x'], null, ['X-Test-Remote-Addr: 198.51.100.9']);
check('a customer account under attack from many addresses is paused', $st === 429, (string) $st);
$lmId = (int) db_val("SELECT id FROM customers WHERE email = 'lockme@test.local'");
$rt = customer_token_create($lmId, 'reset_password', 60);
$cown->page('/account/reset.php?token=' . $rt);
[$st] = $cown->api('POST', '/account/api/auth.php', ['action' => 'reset', 'token' => $rt, 'password' => 'Brand-New-Pass-1!', 'password_confirm' => 'Brand-New-Pass-1!'], null, ['X-Test-Remote-Addr: 198.51.100.9']);
$back = new TestHttp($base);
$back->page('/account/login.php');
[$st2] = $back->api('POST', '/account/api/auth.php', ['action' => 'login', 'email' => 'lockme@test.local', 'password' => 'Brand-New-Pass-1!'], null, ['X-Test-Remote-Addr: 198.51.100.9']);
check('… and "Forgot password" lets them back in', $st === 200 && $st2 === 200, $st . '/' . $st2);
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

section('Security: review follow-ups');
[$st, $hd] = $cust->headers('/feeds/api/cart.php');
check('JSON answers carry the current CSRF token (pages stay valid after a re-login)', ($hd['x-csrf-token'] ?? '') !== '' && ($hd['x-csrf-token'] ?? '') === $cust->csrf, json_encode($hd['x-csrf-token'] ?? null));
@mkdir(GX_ROOT . '/storage/private/pop', 0755, true);
file_put_contents(GX_ROOT . '/storage/private/pop/test-proof.pdf', "%PDF-1.4\n%test\n");
$pdfPay = db_insert("INSERT INTO payments (order_id, division_id, method, purpose, amount_cents, status, pop_path, pop_original_name) VALUES (?, 1, 'eft', 'full', 100, 'rejected', 'storage/private/pop/test-proof.pdf', 'proof.pdf')", [$bo['id']]);
[$st, $hd] = $adm->headers('/admin/api/documents.php?pop=' . $pdfPay);
check('a PDF proof of payment downloads (a sandboxed PDF would not open)', $st === 200 && str_starts_with($hd['content-disposition'] ?? '', 'attachment') && !str_contains($hd['content-security-policy'] ?? '', 'sandbox'), json_encode($hd));
$roHtml = $ro->page('/admin/');
check('the bell only counts notifications this admin can see', !preg_match('/data-adm-bell[^>]*>\s*<svg[^>]*>.*?<\/svg>\s*<span class="cart-count/s', $roHtml));
[$st] = $mgr->headers('/admin/users.php');
check('the Admin users page is owner-only too', $st === 403, (string) $st);
$umId = (string) db_val("SELECT id FROM permissions WHERE slug = 'users.manage'");
$adm->page('/admin/users.php');
$adm->api('POST', '/admin/api/users.php', ['action' => 'perms', 'perm' => ['2' => [$umId], '3' => db_col('SELECT permission_id FROM role_permissions WHERE role_id = 3')]]);
check('"Manage admin users" cannot be given to another role', !db_val('SELECT 1 FROM role_permissions WHERE role_id = 2 AND permission_id = ?', [$umId]));
db_exec("INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT 2, id FROM permissions WHERE slug <> 'users.manage'");
db_exec("DELETE FROM role_permissions WHERE role_id = 3 AND permission_id = ?", [$staffPerm('customers.view')]);
$auditsBefore = (int) db_val("SELECT COUNT(*) FROM audit_log WHERE action = 'report.export'");
$staff->headers('/admin/reports.php?export=customers');
check('a refused export is not logged as an export', (int) db_val("SELECT COUNT(*) FROM audit_log WHERE action = 'report.export'") === $auditsBefore);
db_exec("INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT 3, id FROM permissions WHERE slug = 'customers.view'");

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
// Database re-imported (no owner any more): the old lock must not keep the owner from setting up again
$admin->exec('DROP DATABASE IF EXISTS `gx_noowner_test`');
$admin->exec('CREATE DATABASE `gx_noowner_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$admin->exec('USE `gx_noowner_test`');
$admin->exec((string) file_get_contents(GX_ROOT . '/database/install.sql'));
while ($admin->query('SELECT 1')) { break; }
// The import runs on in the background: wait (on a second connection) until its last statement has landed
$probe = new PDO(sprintf('mysql:host=%s;port=%d;dbname=gx_noowner_test;charset=utf8mb4', env('db.host'), (int) env('db.port', 3306)), (string) env('db.user'), (string) env('db.pass'));
for ($i = 0; $i < 100 && (int) $probe->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = 'gx_noowner_test'")->fetchColumn() < $gxTableCount; $i++) {
    usleep(100000);
}
for ($i = 0; $i < 100 && !(int) $probe->query('SELECT COUNT(*) FROM settings')->fetchColumn(); $i++) {
    usleep(100000);
}
usleep(300000);
$probe = null;
[$freshServer, $freshBase] = http_start('gx_noowner_test');
$fresh = new TestHttp($freshBase);
$freshHtml = $fresh->page('/setup/');
@proc_terminate($freshServer);
$admin->exec('DROP DATABASE IF EXISTS `gx_noowner_test`');
$admin->exec("USE `{$testDb}`");
check('a stale setup lock (database re-imported, no owner) is ignored', str_contains($freshHtml, 'Server check') && !setup_locked(), substr(strip_tags($freshHtml), 0, 160));
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
$noKeySecret = totp_secret_store('JBSWY3DPEHPK3PXP');
[$noKeyCodes, $noKeyHashes] = totp_recovery_generate();
$nkAdmin = db_insert('INSERT INTO admin_users (role_id, email, password_hash, name, division_scope, totp_recovery) VALUES (1, ?, ?, ?, ?, ?)',
    ['nokey@test.local', admin_password_hash('x'), 'No Key', 'all', $noKeyHashes]);
$nkCust = make_customer('nokey-cust@test.local');
$nkToken = customer_token_create((int) $nkCust['id'], 'reset_password', 60);
check('without APP_KEY a random key is kept in storage (stable, not derived from passwords)', gx_app_key() === gx_stored_key() && strlen((string) gx_stored_key()) === 64);
$GLOBALS['GX_ENV']['app_key'] = str_repeat('k', 64);
check('adding APP_KEY later: 2FA secrets saved before still work', totp_secret_load($noKeySecret) === 'JBSWY3DPEHPK3PXP');
check('… recovery codes saved before still work', totp_recovery_use($nkAdmin, $noKeyCodes[3]));
check('… and emailed links sent before still work', customer_token_consume($nkToken, 'reset_password') === (int) $nkCust['id']);
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

// =====================================================================
// Phase 4 — emails, documents, cron
// =====================================================================
function doc_html(int $docId): string
{
    $doc = db_one('SELECT * FROM documents WHERE id = ?', [$docId]);
    return PdfService::html('document', ['doc' => $doc, 'lines' => db_all('SELECT * FROM document_lines WHERE document_id = ? ORDER BY sort_order, id', [$docId]),
        'snap' => json_decode((string) $doc['snapshot'], true) ?: [], 'copy' => false,
        'live' => db_one('SELECT status, amount_paid_cents FROM orders WHERE id = ?', [$doc['order_id']])]); // same as DocumentService::pdf()
}
function last_mail(string $to, string $subjectLike): string
{
    return (string) db_val('SELECT body_html FROM email_queue WHERE to_email = ? AND subject LIKE ? ORDER BY id DESC LIMIT 1', [$to, $subjectLike]);
}

section('Phase 4: emails say which documents are attached');
$pdfOk = PdfService::available();
// The deposit email of the first Ranch order (found by the deposit amount, not by position)
$ro1 = db_one("SELECT * FROM orders WHERE customer_id = ? AND division_id = 2 ORDER BY id LIMIT 1", [$c2['id']]);
$depMail = (string) db_val("SELECT body_html FROM email_queue WHERE to_email = 'ranch@test.local' AND subject = ? AND body_html LIKE ? ORDER BY id LIMIT 1",
    ['Payment received for ' . $ro1['order_no'], '%' . str_replace(' ', '%', money((int) $ro1['deposit_due_cents'])) . '%']);
check('deposit: "Your receipt is attached" — no invoice promised',
    $pdfOk ? str_contains($depMail, 'Your receipt is attached') && !str_contains($depMail, 'invoice') : str_contains($depMail, 'My orders'), strip_tags($depMail));
$c5d = make_customer('docs@test.local');
as_customer($c5d);
CartService::add('feeds', 5, 1);
$fo = OrderService::place('feeds', ['billing_name' => 'Docs Buyer', 'payment_method' => 'eft', 'fulfilment' => 'collection'] + slot(1), current_customer());
OrderService::recordPayment((int) $fo['id'], (int) $fo['total_cents'], 'eft');
$fullMail = last_mail('docs@test.local', 'Payment received%');
check('full payment: "Your receipt and tax invoice are attached"',
    $pdfOk ? str_contains($fullMail, 'Your receipt and tax invoice are attached') : str_contains($fullMail, 'My orders'), strip_tags($fullMail));
// Low-stock digest: markdown list, no ∗∗garbled∗∗ stars
db_exec('UPDATE product_variants SET stock_qty = 1, low_stock_threshold = 5 WHERE id = 2');
setting_set('low_stock_email', 'stock@test.local');
step('low-stock digest runs', static fn() => CronService::lowStockDigest(true));
$lowMail = last_mail('stock@test.local', 'Low stock%');
check('low-stock digest is a clean list (no ∗∗ characters)', $lowMail !== '' && !str_contains($lowMail, '∗') && str_contains($lowMail, '<li') && str_contains($lowMail, ' left'), strip_tags($lowMail));

section('Phase 4: PDF documents');
$ro = db_one('SELECT * FROM orders WHERE customer_id = ? AND division_id = 2 ORDER BY id LIMIT 1', [$c2['id']]); // the first Ranch order (deposit, then balance)
$roInv = (int) db_val("SELECT id FROM documents WHERE order_id = ? AND doc_type IN ('tax_invoice','invoice')", [$ro['id']]);
$h = doc_html($roInv);
check('once the balance is paid, the invoice no longer asks for payment', $roInv > 0 && !str_contains($h, 'Pay the balance'));
$foInv = (int) db_val("SELECT id FROM documents WHERE order_id = ? AND doc_type = 'tax_invoice'", [$fo['id']]);
check('paid invoice has no "pay the balance" note', $foInv > 0 && !str_contains(doc_html($foInv), 'Pay the balance'));
check('tax invoice VAT label from its lines: "VAT (15%)"', $foInv > 0 && str_contains(doc_html($foInv), 'VAT (15%)'));
$foRc = (int) db_val("SELECT id FROM documents WHERE order_id = ? AND doc_type = 'receipt'", [$fo['id']]);
$h = doc_html($foRc);
check('receipt shows no VAT columns or VAT total', $foRc > 0 && !str_contains($h, 'Excl VAT') && !str_contains($h, 'Total excl. VAT') && str_contains($h, 'Amount received'));
$doi = (int) db_val("SELECT id FROM documents WHERE order_id = ? AND doc_type = 'doc_identification'", [$ro['id']]);
db_exec("UPDATE breeds SET kind = 'Goat' WHERE id = (SELECT breed_id FROM lots WHERE id = 1)");
db_exec('UPDATE lots SET age_months_min = 4, age_months_max = 6 WHERE id = 1');
check('DOI keeps its snapshot (later breed edits do not change it)', $doi > 0 && !str_contains(doc_html($doi), 'Goat'));
// A new DOI takes the kind and age from the breed and lot
as_customer($c2);
CartService::add('ranch', 1, 1);
$rd = OrderService::place('ranch', ['billing_name' => 'Ranch Buyer', 'billing_address' => 'Farm 1', 'buyer_id_number' => '8001015009087',
    'payment_method' => 'eft', 'fulfilment' => 'collection'] + slot(2), current_customer());
$rdItem = db_one('SELECT * FROM order_items WHERE order_id = ?', [$rd['id']]);
step('finalise (setup)', static fn() => OrderService::finalise((int) $rd['id'], [$rdItem['id'] => 40.0]));
step('ready (setup)', static fn() => OrderService::transition((int) $rd['id'], 'ready_for_collection'));
$rdInv = (int) db_val("SELECT id FROM documents WHERE order_id = ? AND doc_type IN ('tax_invoice','invoice')", [$rd['id']]);
$h = $rdInv ? doc_html($rdInv) : '';
$rdBank = (string) (json_decode((string) db_val('SELECT snapshot FROM documents WHERE id = ?', [$rdInv]), true)['seller']['bank']['account_no'] ?? 'missing');
check('invoice with a balance still owed shows the bank details and reference', str_contains($h, 'Pay the balance by EFT') && str_contains($h, 'Account no: ' . $rdBank) && str_contains($h, 'Reference: ' . $rd['order_no']));
step('pay (setup)', static fn() => OrderService::recordPayment((int) $rd['id'], OrderService::amountDue(order_row((int) $rd['id'])), 'cash'));
setting_set('ranch_brand_mark', '');
check('"Mark collected" is refused while the ranch brand mark is empty', str_contains((string) throws(static fn() => OrderService::transition((int) $rd['id'], 'collected')), 'brand')
    && order_row((int) $rd['id'])['status'] === 'ready_for_collection' && !db_val("SELECT 1 FROM documents WHERE order_id = ? AND doc_type = 'doc_identification'", [$rd['id']]));
setting_set('ranch_brand_mark', 'GX1');
step('collected with the mark set', static fn() => OrderService::transition((int) $rd['id'], 'collected'));
$doi2 = (int) db_val("SELECT id FROM documents WHERE order_id = ? AND doc_type = 'doc_identification'", [$rd['id']]);
$h = $doi2 ? doc_html($doi2) : '';
check('new DOI: kind of animal from the breed (Goat) and age (4–6 months)', str_contains($h, '>Goat<') && str_contains($h, '4–6 months'), $doi2 ? '' : 'no DOI');
check("new DOI: seller's mark printed", str_contains($h, "Seller's mark: GX1") || str_contains($h, 'Seller&#039;s mark: GX1'));
db_exec("UPDATE breeds SET kind = 'Sheep'");
db_exec('UPDATE lots SET age_months_min = NULL, age_months_max = NULL WHERE id = 1');
// Labels on documents made from scratch: mixed VAT rates, receipts, and old DOI snapshots without kind/age
$fakeDoc = ['doc_type' => 'tax_invoice', 'doc_no' => 'T-1', 'issued_at' => now(), 'subtotal_excl_cents' => 0, 'vat_cents' => 0, 'total_cents' => 0, 'reason_code' => null, 'explanation' => null];
$fakeLine = static fn(int $bp) => DocumentService::line('Item', 1, null, 11500, 11500, $bp, $bp === 0, null);
$fakeSnap = ['seller' => ['vat_registered' => true, 'name' => 'S', 'code' => 'feeds'], 'buyer' => ['name' => 'B', 'company' => null, 'brand_mark' => null], 'order' => []];
$hv = PdfService::html('document', ['doc' => $fakeDoc, 'lines' => [$fakeLine(1500), $fakeLine(0)], 'snap' => $fakeSnap, 'copy' => false]);
check('VAT label: standard + zero-rated lines → "VAT (15%)"', str_contains($hv, 'VAT (15%)') && str_contains($hv, '(zero-rated)'));
$hv = PdfService::html('document', ['doc' => $fakeDoc, 'lines' => [$fakeLine(1500), $fakeLine(1400)], 'snap' => $fakeSnap, 'copy' => false]);
check('VAT label: two different rates → plain "VAT" (no wrong percentage)', !str_contains($hv, 'VAT (15%)') && !str_contains($hv, 'VAT (14%)') && str_contains($hv, '>VAT<'));
$hv = PdfService::html('document', ['doc' => $fakeDoc, 'lines' => [$fakeLine(1500)], 'snap' => ['seller' => ['vat_registered' => false, 'name' => 'S', 'code' => 'feeds']] + $fakeSnap, 'copy' => false]);
check('seller not VAT registered: no VAT rows at all', !str_contains($hv, 'Excl VAT') && !str_contains($hv, 'Total excl. VAT'));
$oldDoi = ['lots' => [['qty' => 3, 'breed' => 'Dorper', 'sex' => 'ewe', 'category' => 'Weaner lambs', 'title' => 'Old lot', 'lot_no' => 'L1', 'tag_no' => null]]] + $fakeSnap;
$warn = null;
set_error_handler(static function (int $no, string $msg) use (&$warn): bool { $warn = $msg; return true; });
$hv = PdfService::html('document', ['doc' => ['doc_type' => 'doc_identification'] + $fakeDoc, 'lines' => [], 'snap' => $oldDoi, 'copy' => false]);
restore_error_handler();
check('a DOI stored before this change (no kind/age) still renders: "Sheep", age "—", no warnings', $warn === null && str_contains($hv, '>Sheep<') && str_contains($hv, '>—<'), (string) $warn);

section('Phase 4: scheduled jobs (cron)');
$admin->query("SELECT GET_LOCK(SHA1(CONCAT(DATABASE(), ':', 'cron')), 0)")->fetchColumn();
check('a second cron run is skipped while one is busy', step('cron while locked', static fn() => CronService::run()) === null && class_exists('CronService', false));
$admin->query("SELECT RELEASE_LOCK(SHA1(CONCAT(DATABASE(), ':', 'cron')))")->fetchColumn();
$line = step('cron run', static fn() => CronService::run());
check('cron runs once the lock is free (and records last_cron_run)', is_string($line) && str_contains($line, 'privacy retention') && setting('last_cron_run') !== null, (string) $line);
// Page-load expiry never overlaps a running expiry
as_customer($c5d);
CartService::add('feeds', 5, 1);
$xo = OrderService::place('feeds', ['billing_name' => 'Late Payer', 'payment_method' => 'eft', 'fulfilment' => 'collection'] + slot(1), current_customer());
db_exec('UPDATE orders SET hold_expires_at = NOW() - INTERVAL 1 MINUTE WHERE id = ?', [$xo['id']]);
setting_set('last_expiry_run', '0');
$admin->query("SELECT GET_LOCK(SHA1(CONCAT(DATABASE(), ':', 'expiry')), 0)")->fetchColumn();
OrderService::maybeExpire();
check('page-load expiry waits while another expiry run holds the lock', order_row((int) $xo['id'])['status'] === 'pending_payment');
$admin->query("SELECT RELEASE_LOCK(SHA1(CONCAT(DATABASE(), ':', 'expiry')))")->fetchColumn();
// Expiry is done by "the system", even when a customer's page load triggered it
setting_set('last_expiry_run', '0');
OrderService::maybeExpire();
check('lazy expiry ran', order_row((int) $xo['id'])['status'] === 'expired');
check('expiry is logged as the system, not the customer who loaded the page',
    db_val("SELECT actor_type FROM audit_log WHERE action = 'order.expired' AND entity_id = ? ORDER BY id DESC LIMIT 1", [$xo['id']]) === 'system');
// EFT reminder is sent exactly once
CartService::add('feeds', 5, 1);
$rm = OrderService::place('feeds', ['billing_name' => 'Reminder', 'payment_method' => 'eft', 'fulfilment' => 'collection'] + slot(1), current_customer());
db_exec('UPDATE orders SET placed_at = NOW() - INTERVAL 30 HOUR WHERE id = ?', [$rm['id']]);
OrderService::sendReminders();
OrderService::sendReminders();
check('EFT reminder sent exactly once', (int) db_val('SELECT COUNT(*) FROM email_queue WHERE subject LIKE ?', ['%' . $rm['order_no'] . ' is waiting for payment%']) === 1);
// An email stuck in "sending" (the sender died) goes back in the queue
$stuck = db_insert("INSERT INTO email_queue (to_email, subject, body_html, status, attempts, created_at) VALUES ('stuck@test.local', 'Stuck', '<p>x</p>', 'sending', 1, NOW() - INTERVAL 1 HOUR)");
$fresh = db_insert("INSERT INTO email_queue (to_email, subject, body_html, status, attempts) VALUES ('busy@test.local', 'Busy', '<p>x</p>', 'sending', 1)");
step('requeue stuck emails', static fn() => CronService::requeueStuckEmails());
check('email stuck in "sending" for an hour is queued again', db_val('SELECT status FROM email_queue WHERE id = ?', [$stuck]) === 'queued');
check('an email being sent right now is left alone', db_val('SELECT status FROM email_queue WHERE id = ?', [$fresh]) === 'sending');
// The age that counts is when sending started (claimed_at), not when the email was queued
$oldQueued = db_insert("INSERT INTO email_queue (to_email, subject, body_html, status, attempts, created_at, claimed_at) VALUES ('q@test.local', 'Q', '<p>x</p>', 'sending', 1, NOW() - INTERVAL 3 HOUR, NOW() - INTERVAL 1 MINUTE)");
$stuck2 = db_insert("INSERT INTO email_queue (to_email, subject, body_html, status, attempts, created_at, claimed_at) VALUES ('q@test.local', 'Q2', '<p>x</p>', 'sending', 5, NOW(), NOW() - INTERVAL 30 MINUTE)");
step('requeue stuck emails (claimed_at)', static fn() => CronService::requeueStuckEmails());
check('an old email that only just started sending is left alone', db_val('SELECT status FROM email_queue WHERE id = ?', [$oldQueued]) === 'sending');
check('a stuck email that already had 5 tries is marked failed', db_val('SELECT status FROM email_queue WHERE id = ?', [$stuck2]) === 'failed');
db_exec('DELETE FROM email_queue WHERE id IN (?, ?)', [$oldQueued, $stuck2]);
db_exec('DELETE FROM email_queue WHERE id IN (?, ?)', [$stuck, $fresh]);

section('Phase 4: POPIA');
$p4 = new TestHttp($base);
$p4->page('/admin/login.php');
$p4->api('POST', '/admin/api/auth.php', ['action' => 'login', 'email' => 'owner@test.local', 'password' => $ownerPw], null, ['X-Test-Remote-Addr: 10.44.0.1']);
$p4->page('/admin/');
// --- Anonymise: everything personal goes, the tax record stays ---
$pc = make_customer('popia@test.local');
db_exec("UPDATE customers SET farm_name = 'Popia Farm', vat_no = '4111111111', deletion_requested_at = NOW() WHERE id = ?", [$pc['id']]);
as_customer($pc);
CartService::add('feeds', 5, 1);
$po1 = OrderService::place('feeds', ['billing_name' => 'Popia Person', 'billing_phone' => '0829999999', 'billing_company' => 'Popia (Pty) Ltd', 'billing_vat_no' => '4222222222',
    'billing_address' => '1 Farm Road', 'customer_note' => 'Gate code 1234', 'payment_method' => 'eft', 'fulfilment' => 'collection'] + slot(1), current_customer());
[$st, $res] = $p4->api('POST', '/admin/api/customers.php', ['action' => 'anonymise', 'id' => $pc['id']]);
check('anonymise is refused while the customer has an open order', $st === 409 && db_val('SELECT email FROM customers WHERE id = ?', [$pc['id']]) === 'popia@test.local', $st . ' ' . json_encode($res));
db_exec("UPDATE orders SET status = 'completed' WHERE id = ?", [$po1['id']]);
@mkdir(GX_ROOT . '/storage/private/vat/test', 0755, true);
$vatFile = 'storage/private/vat/test/popia-' . $pc['id'] . '.pdf';
file_put_contents(GX_ROOT . '/' . $vatFile, '%PDF-1.4 test');
db_exec('UPDATE customers SET vat_farmer_doc_path = ? WHERE id = ?', [$vatFile, $pc['id']]);
LivestockService::subscribe('popia@test.local', null, null, null, true); // signed up as a guest earlier
db_exec("INSERT INTO enquiries (name, email, phone, message) VALUES ('Popia', 'popia@test.local', '0829999999', 'Do you deliver?')");
db_exec("INSERT INTO push_devices (customer_id, token_hash, token) VALUES (?, ?, 'fcm-token')", [$pc['id'], hash('sha256', 'fcm-token-' . $pc['id'])]);
$qOld = MailService::queue('popia@test.local', 'Popia', 'Old news', '<p>x</p>');
[$st, $res] = $p4->api('POST', '/admin/api/customers.php', ['action' => 'anonymise', 'id' => $pc['id']]);
check('anonymise works once orders are closed', $st === 200, $st . ' ' . json_encode($res));
$pcRow = db_one('SELECT * FROM customers WHERE id = ?', [$pc['id']]);
$po1Row = order_row((int) $po1['id']);
check('customer row: no name, email, farm or VAT number left', str_ends_with($pcRow['email'], '@deleted.invalid') && $pcRow['first_name'] === 'Deleted' && $pcRow['farm_name'] === null && $pcRow['vat_no'] === null);
check('order: phone, email, note and ID removed', $po1Row['billing_phone'] === null && $po1Row['customer_note'] === null && str_ends_with($po1Row['billing_email'], '@deleted.invalid'));
check('order: invoice details kept for the tax record (name, company, VAT no, address)', $po1Row['billing_name'] === 'Popia Person' && $po1Row['billing_company'] === 'Popia (Pty) Ltd'
    && $po1Row['billing_vat_no'] === '4222222222' && $po1Row['billing_address'] === '1 Farm Road');
check('alerts and enquiries under their (verified) email are deleted', !db_val("SELECT 1 FROM livestock_alerts WHERE email = 'popia@test.local'") && !db_val("SELECT 1 FROM enquiries WHERE email = 'popia@test.local'"));
check('uploaded VAT certificate file is deleted', !is_file(GX_ROOT . '/' . $vatFile));
check('push devices removed', !db_val('SELECT 1 FROM push_devices WHERE customer_id = ?', [$pc['id']]));
check('queued emails to the old address removed', !db_val('SELECT 1 FROM email_queue WHERE id = ?', [$qOld]));
$pcSnap = json_decode((string) db_val("SELECT snapshot FROM documents WHERE order_id = ? AND doc_type = 'order_confirmation'", [$po1['id']]), true) ?: [];
check("documents' copies of email, phone, notes and delivery address are removed (invoice details kept)",
    $pcSnap && !isset($pcSnap['buyer']['email']) && !isset($pcSnap['buyer']['phone']) && !isset($pcSnap['buyer']['ship']) && !isset($pcSnap['order']['customer_note'])
    && ($pcSnap['buyer']['name'] ?? '') === 'Popia Person' && ($pcSnap['buyer']['vat_no'] ?? '') === '4222222222', json_encode($pcSnap['buyer'] ?? null));
check('saved PDFs of their documents are removed (rebuilt from the cleaned copy)', !db_val('SELECT 1 FROM documents WHERE customer_id = ? AND pdf_path IS NOT NULL', [$pc['id']]));
check('confirmation ("account deleted") emailed to the old address', (bool) db_val("SELECT 1 FROM email_queue WHERE to_email = 'popia@test.local' AND subject LIKE '%account has been deleted%'"));
@rmdir(GX_ROOT . '/storage/private/vat/test');

// --- Guest data belongs to an account only once its email is verified ---
$vid = db_insert('INSERT INTO customers (email, password_hash, first_name, last_name, phone) VALUES (?,?,?,?,?)',
    ['victim@test.local', password_hash('x', PASSWORD_BCRYPT), 'Vic', 'Tim', '0821234567']);
LivestockService::subscribe('victim@test.local', null, null, null, true);
db_exec("INSERT INTO enquiries (name, email, message) VALUES ('Real Victim', 'victim@test.local', 'My private question')");
$vh = new TestHttp($base);
$vh->page('/account/login.php');
$vh->api('POST', '/account/api/auth.php', ['action' => 'login', 'email' => 'victim@test.local', 'password' => 'x']);
$vh->page('/account/');
[$st, $res] = $vh->api('POST', '/account/api/privacy.php', ['action' => 'export']);
check('unverified account: data export does not include guest alerts/enquiries for that email',
    $st === 200 && ($res['data']['download']['livestock_alerts'] ?? null) === [] && ($res['data']['download']['enquiries'] ?? null) === [], $st . ' ' . json_encode($res));
check('unverified account: alerts page does not list them', !str_contains($vh->page('/account/alerts.php'), 'Pause'));
$vtok = customer_token_create($vid, 'verify_email', 60);
$vh->page('/account/verify.php?token=' . $vtok);
check('verifying the email links the guest alert and enquiry to the account',
    (int) db_val("SELECT customer_id FROM livestock_alerts WHERE email = 'victim@test.local'") === $vid && (int) db_val("SELECT customer_id FROM enquiries WHERE email = 'victim@test.local'") === $vid);
[$st, $res] = $vh->api('POST', '/account/api/privacy.php', ['action' => 'export']);
check('… and the export now includes them', count($res['data']['download']['livestock_alerts'] ?? []) === 1 && count($res['data']['download']['enquiries'] ?? []) === 1, json_encode($res));

// --- A new VAT certificate replaces the old file ---
setting_set('zero_rating_enabled', '1');
$pdfA = (string) tempnam(sys_get_temp_dir(), 'gxvat');
file_put_contents($pdfA, "%PDF-1.4\n%test\n");
[$st1] = $vh->api('POST', '/account/api/profile.php', ['action' => 'vat_doc'], ['vat_doc' => $pdfA]);
$firstDoc = (string) db_val('SELECT vat_farmer_doc_path FROM customers WHERE id = ?', [$vid]);
[$st2] = $vh->api('POST', '/account/api/profile.php', ['action' => 'vat_doc'], ['vat_doc' => $pdfA]);
$secondDoc = (string) db_val('SELECT vat_farmer_doc_path FROM customers WHERE id = ?', [$vid]);
check('uploading a new VAT certificate deletes the previous file', $st1 === 200 && $st2 === 200 && $firstDoc !== $secondDoc && !is_file(GX_ROOT . '/' . $firstDoc) && is_file(GX_ROOT . '/' . $secondDoc),
    json_encode([$st1, $st2, $firstDoc, $secondDoc]));
@unlink(GX_ROOT . '/' . $secondDoc);
@unlink($pdfA);
setting_set('zero_rating_enabled', '0');

// --- Marketing consent date, terms acceptance ---
[$st] = $vh->api('POST', '/account/api/profile.php', ['action' => 'details', 'first_name' => 'Vic', 'last_name' => 'Tim', 'phone' => '0821234567', 'marketing_opt_in' => '1']);
check('opting in to marketing records the date', $st === 200 && db_val('SELECT marketing_opt_in_at FROM customers WHERE id = ?', [$vid]) !== null);
$rh = new TestHttp($base);
$rh->page('/account/register.php');
[$st] = $rh->api('POST', '/account/api/auth.php', ['action' => 'register', 'first_name' => 'New', 'last_name' => 'Person', 'email' => 'terms@test.local',
    'phone' => '0821234567', 'password' => 'Correct-Horse-9']);
$tc = db_one("SELECT terms_accepted_at, terms_version FROM customers WHERE email = 'terms@test.local'");
check('registration records the terms accepted and their version', $st === 200 && $tc && $tc['terms_accepted_at'] !== null
    && $tc['terms_version'] === (string) db_val("SELECT updated_at FROM pages WHERE slug = 'terms'"), json_encode($tc));
as_customer($c5d);
CartService::add('feeds', 5, 1);
$to = step('place with accepted terms', static fn() => OrderService::place('feeds', ['billing_name' => 'Terms', 'payment_method' => 'eft', 'fulfilment' => 'collection', 'accept_terms' => true] + slot(1), current_customer()));
check('an order records the terms accepted at checkout', $to && order_row((int) $to['id'])['terms_accepted_at'] !== null && order_row((int) $to['id'])['terms_version'] !== null);

// --- Livestock alerts: double opt-in, unsubscribe ---
db_exec('UPDATE livestock_alerts SET is_active = 0');
$gh = new TestHttp($base);
$gh->page('/ranch/');
[$st, $res] = $gh->api('POST', '/ranch/api/alerts.php', ['email' => 'guest-alert@test.local']);
$ga = db_one("SELECT * FROM livestock_alerts WHERE email = 'guest-alert@test.local'");
check('guest sign-up waits for email confirmation', $st === 200 && $ga && $ga['confirmed_at'] === null, $st . ' ' . json_encode($res));
$cm = last_mail('guest-alert@test.local', 'Confirm your%');
preg_match('#/ranch/alerts\.php\?confirm=([a-f0-9]{64})#', $cm, $m);
$ctok = $m[1] ?? '';
check('a confirmation email with a link is sent', $ctok !== '');
db_exec("UPDATE lots SET status = 'available' WHERE id = 1");
step('announce lot (unconfirmed)', static fn() => LivestockService::notifySubscribers(1));
check('an unconfirmed address gets no livestock alert', !db_val("SELECT 1 FROM email_queue WHERE to_email = 'guest-alert@test.local' AND subject LIKE 'New on GrazeX Ranch%'"));
$gh->page('/ranch/alerts.php?confirm=' . $ctok);
check('opening the confirmation link alone changes nothing (link scanners)', db_val("SELECT confirmed_at FROM livestock_alerts WHERE email = 'guest-alert@test.local'") === null);
[$st] = $gh->api('POST', '/ranch/api/alerts.php', ['action' => 'confirm', 'token' => $ctok]);
check('pressing "Yes, send me alerts" confirms it', $st === 200 && db_val("SELECT confirmed_at FROM livestock_alerts WHERE email = 'guest-alert@test.local'") !== null);
step('announce lot (confirmed)', static fn() => LivestockService::notifySubscribers(1));
$am = db_one("SELECT * FROM email_queue WHERE to_email = 'guest-alert@test.local' AND subject LIKE 'New on GrazeX Ranch%' ORDER BY id DESC LIMIT 1");
$utok = (string) db_val("SELECT unsubscribe_token FROM livestock_alerts WHERE email = 'guest-alert@test.local'");
check('after confirming, the alert is sent', (bool) $am);
check('the alert carries its own unsubscribe link and one-click header', $am && str_contains((string) $am['body_html'], '/ranch/alerts.php?unsubscribe=' . $utok)
    && str_contains((string) ($am['list_unsubscribe'] ?? ''), '/ranch/api/alerts.php?unsubscribe=' . $utok)
    && (MailService::extraHeaders($am)['List-Unsubscribe-Post'] ?? '') === 'List-Unsubscribe=One-Click');
[$st] = $gh->headers('/ranch/api/alerts.php?unsubscribe=' . $utok);
check('an unsubscribe GET (old links, scanners) only shows the page — nothing changes', (int) db_val("SELECT is_active FROM livestock_alerts WHERE email = 'guest-alert@test.local'") === 1);
$ch = curl_init($base . '/ranch/api/alerts.php?unsubscribe=' . $utok);
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => 'List-Unsubscribe=One-Click', CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']]);
curl_exec($ch);
$oneClick = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
check('one-click unsubscribe from the mail provider works (RFC 8058) — the guest sign-up is deleted', $oneClick === 200 && !db_val("SELECT 1 FROM livestock_alerts WHERE email = 'guest-alert@test.local'"), (string) $oneClick);
LivestockService::subscribe('second@test.local', null, null, null, true);
$utok2 = (string) db_val("SELECT unsubscribe_token FROM livestock_alerts WHERE email = 'second@test.local'");
[$st] = $gh->api('POST', '/ranch/api/alerts.php', ['action' => 'unsubscribe', 'token' => $utok2]);
check('the Unsubscribe button on the page works', $st === 200 && !db_val("SELECT 1 FROM livestock_alerts WHERE email = 'second@test.local'"));
// An account that has not verified its email can't take over (or learn about) someone's guest alert for that address
LivestockService::subscribe('taken@test.local', null, null, null, true); // the real owner of the address, signed up as a guest
db_insert('INSERT INTO customers (email, password_hash, first_name, last_name, phone) VALUES (?,?,?,?,?)', ['taken@test.local', password_hash('x', PASSWORD_BCRYPT), 'Fake', 'Owner', '0821234567']);
$tk = new TestHttp($base);
$tk->page('/account/login.php');
$tk->api('POST', '/account/api/auth.php', ['action' => 'login', 'email' => 'taken@test.local', 'password' => 'x'], null, ['X-Test-Remote-Addr: 10.44.0.9']);
$tk->page('/account/alerts.php');
[$st, $res] = $tk->api('POST', '/account/api/alerts.php', ['action' => 'create', 'breed' => '', 'category' => '']);
$tkId = (int) db_val("SELECT id FROM customers WHERE email = 'taken@test.local'");
check('unverified account: the guest\'s alert is not attached to it', (string) db_val("SELECT customer_id FROM livestock_alerts WHERE email = 'taken@test.local' AND confirmed_at IS NOT NULL") === ''
    && !db_val('SELECT 1 FROM livestock_alerts WHERE customer_id = ? AND confirmed_at IS NOT NULL', [$tkId]), json_encode($res));
check('… and the reply does not reveal that the address is subscribed', ($res['message'] ?? '') !== 'Alert added.', json_encode($res));
// The inbox owner confirms the account's alert (thinking it is theirs), then unsubscribes: the unverified account can't switch it back on
preg_match('#/ranch/alerts\.php\?confirm=([a-f0-9]{64})#', last_mail('taken@test.local', 'Confirm your%'), $m);
step('inbox owner confirms', static fn() => LivestockService::confirmAlert($m[1] ?? ''));
$tkAlert = (int) db_val('SELECT id FROM livestock_alerts WHERE customer_id = ?', [$tkId]);
step('inbox owner unsubscribes', static fn() => LivestockService::unsubscribe((string) db_val('SELECT unsubscribe_token FROM livestock_alerts WHERE id = ?', [$tkAlert])));
check("unsubscribing removes an unverified account's alert for that inbox", $tkAlert > 0 && !db_val('SELECT 1 FROM livestock_alerts WHERE id = ?', [$tkAlert]));
db_exec('INSERT INTO livestock_alerts (customer_id, email, is_active, confirmed_at, unsubscribe_token) VALUES (?, ?, 0, NOW(), ?)', [$tkId, 'taken@test.local', random_token(32)]);
[$st] = $tk->api('POST', '/account/api/alerts.php', ['action' => 'toggle', 'id' => (int) db_val('SELECT MAX(id) FROM livestock_alerts WHERE customer_id = ?', [$tkId])]);
check('an unverified account cannot switch a paused alert back on', $st === 409 && (int) db_val('SELECT is_active FROM livestock_alerts WHERE customer_id = ? ORDER BY id DESC LIMIT 1', [$tkId]) === 0, (string) $st);
// The site-wide hourly cap is checked first, so a busy hour doesn't use up anyone's own 3-a-day allowance
for ($i = 0; $i < 40; $i++) {
    rate_limit_hit('alert_confirm_site', 'all', 40, 3600);
}
LivestockService::subscribe('capped@test.local', null, null, null, false);
check('when the hourly cap is reached no confirmation is sent', !db_val("SELECT 1 FROM email_queue WHERE to_email = 'capped@test.local'"));
rate_limit_clear('alert_confirm_site', 'all');
for ($i = 0; $i < 3; $i++) {
    LivestockService::subscribe('capped@test.local', null, null, null, false);
}
check('… and the address still has its full daily allowance afterwards', (int) db_val("SELECT COUNT(*) FROM email_queue WHERE to_email = 'capped@test.local'") === 3);
// A pending confirmation link is not replaced by repeated sign-ups once the daily email limit is reached
for ($i = 0; $i < 3; $i++) {
    LivestockService::subscribe('pending@test.local', null, null, null, false);
}
$pendTok = (string) db_val("SELECT confirm_token FROM livestock_alerts WHERE email = 'pending@test.local'");
LivestockService::subscribe('pending@test.local', null, null, null, false);
check('after the daily limit no new email is sent and the last link keeps working', db_val("SELECT confirm_token FROM livestock_alerts WHERE email = 'pending@test.local'") === $pendTok
    && (int) db_val("SELECT COUNT(*) FROM email_queue WHERE to_email = 'pending@test.local'") === 3);

// --- Retention (POPIA: not kept longer than needed) ---
db_exec("INSERT INTO audit_log (actor_type, action, created_at) VALUES ('system', 'old.entry', NOW() - INTERVAL 800 DAY), ('system', 'new.entry', NOW())");
db_exec("INSERT INTO enquiries (name, email, message, created_at) VALUES ('Old', 'old@test.local', 'old', NOW() - INTERVAL 800 DAY)");
db_exec("INSERT INTO email_queue (to_email, subject, body_html, status, created_at) VALUES ('old@test.local', 'Failed', '<p>x</p>', 'failed', NOW() - INTERVAL 100 DAY)");
db_exec("INSERT INTO livestock_alerts (email, confirm_token, unsubscribe_token, created_at) VALUES ('never@test.local', ?, ?, NOW() - INTERVAL 8 DAY)", [random_token(32), random_token(32)]);
// An alert saved by the old code between pasting the migration and merging (no link was ever sent) keeps working
db_exec("INSERT INTO livestock_alerts (email, unsubscribe_token, created_at) VALUES ('window@test.local', ?, NOW() - INTERVAL 9 DAY)", [random_token(32)]);
// Unsubscribing an address that never confirmed removes the sign-up altogether (no consent)
LivestockService::subscribe('half@test.local', null, null, null, false);
$utok3 = (string) db_val("SELECT unsubscribe_token FROM livestock_alerts WHERE email = 'half@test.local'");
step('unsubscribe never-confirmed', static fn() => LivestockService::unsubscribe($utok3));
check('unsubscribing a never-confirmed sign-up deletes it', !db_val("SELECT 1 FROM livestock_alerts WHERE email = 'half@test.local'"));
step('retention clean-up', static fn() => CronService::retention());
step('alert consent job', static fn() => CronService::alertConsent());
check('an alert saved without proof of consent is not sent — it gets one confirmation email instead',
    db_val("SELECT confirmed_at FROM livestock_alerts WHERE email = 'window@test.local'") === null
    && (int) db_val("SELECT COUNT(*) FROM email_queue WHERE to_email = 'window@test.local' AND subject LIKE 'Confirm your%'") === 1);
step('alert consent job again', static fn() => CronService::alertConsent());
check('… only once', (int) db_val("SELECT COUNT(*) FROM email_queue WHERE to_email = 'window@test.local' AND subject LIKE 'Confirm your%'") === 1);
check('audit entries older than the retention period are deleted (recent ones kept)', !db_val("SELECT 1 FROM audit_log WHERE action = 'old.entry'") && (bool) db_val("SELECT 1 FROM audit_log WHERE action = 'new.entry'"));
check('old enquiries, old failed emails and never-confirmed alerts are deleted',
    !db_val("SELECT 1 FROM enquiries WHERE email = 'old@test.local'") && !db_val("SELECT 1 FROM email_queue WHERE to_email = 'old@test.local'") && !db_val("SELECT 1 FROM livestock_alerts WHERE email = 'never@test.local'"));

section('Phase 4: media, activity log, CSV exports, products');
// --- Media: a new focal point gives new file names (no stale cached crops) and removes the old crops ---
$gd = imagecreatetruecolor(1000, 700);
imagefill($gd, 0, 0, imagecolorallocate($gd, 90, 140, 40));
$mBase = 'uploads/media/test/' . random_token(6);
@mkdir(GX_ROOT . '/uploads/media/test', 0755, true);
$mVariants = media_generate_variants($gd, $mBase, .5, .5);
$mid = db_insert("INSERT INTO media (base_path, original_name, mime, width, height, size_bytes, focal_x, focal_y, variants) VALUES (?, 't.png', 'image/webp', 1000, 700, 1, .5, .5, ?)",
    [$mBase, json_encode($mVariants, JSON_UNESCAPED_SLASHES)]);
$oldWide = array_values($mVariants['wide']);
step('refocus', static fn() => media_refocus($mid, .2, .3));
$newWide = array_values(json_decode((string) db_val('SELECT variants FROM media WHERE id = ?', [$mid]), true)['wide'] ?? []);
check('refocused crops get new file names', $newWide && !array_intersect($oldWide, $newWide) && is_file(GX_ROOT . '/' . $newWide[0]));
check('the old crops are deleted (originals kept)', !array_filter($oldWide, static fn($p) => is_file(GX_ROOT . '/' . $p)) && is_file(GX_ROOT . '/' . array_values($mVariants['orig'])[0]));
media_forget($mid);
check('media URLs come from the cache after a refocus', media_url($mid, 480, 'wide') === '/' . $newWide[0]);
$catId = (int) db_val('SELECT id FROM categories ORDER BY id LIMIT 1');
db_exec('UPDATE categories SET media_id = ? WHERE id = ?', [$mid, $catId]);
[$st] = $p4->api('POST', '/admin/api/media.php', ['action' => 'delete', 'id' => $mid]);
check('an image used by a category cannot be deleted', $st === 409 && (bool) db_val('SELECT 1 FROM media WHERE id = ?', [$mid]), (string) $st);
$lib = $p4->page('/admin/media.php');
$at = strpos($lib, 'data-id="' . $mid . '"');
check('… and the media library shows it as "In use"', $at !== false && str_contains(substr($lib, $at, 700), 'In use'));
db_exec('UPDATE categories SET media_id = NULL WHERE id = ?', [$catId]);
[$st] = $p4->api('POST', '/admin/api/media.php', ['action' => 'delete', 'id' => $mid]);
check('once unused it can be deleted (files removed)', $st === 200 && !is_file(GX_ROOT . '/' . $newWide[0]));
$gd2 = imagecreatetruecolor(1700, 400);
$mBase2 = 'uploads/media/test/' . random_token(6);
$mid2 = db_insert("INSERT INTO media (base_path, original_name, mime, width, height, size_bytes, focal_x, focal_y, variants) VALUES (?, 'w.png', 'image/webp', 1700, 400, 1, .5, .5, ?)",
    [$mBase2, json_encode(media_generate_variants($gd2, $mBase2, .5, .5), JSON_UNESCAPED_SLASHES)]);
step('refocus a wide low image', static fn() => media_refocus($mid2, .3, .5));
$v2 = json_decode((string) db_val('SELECT variants FROM media WHERE id = ?', [$mid2]), true);
$onDisk = array_map(static fn($f) => 'uploads/media/test/' . basename($f), glob(GX_ROOT . '/' . $mBase2 . '-f*') ?: []);
check('refocus leaves no unused crop files behind', $onDisk !== [] && !array_diff($onDisk, array_values($v2['wide'])), json_encode([$onDisk, $v2['wide']]));
foreach (glob(GX_ROOT . '/' . $mBase2 . '*') ?: [] as $f) {
    @unlink($f);
}
db_exec('DELETE FROM media WHERE id = ?', [$mid2]);
@rmdir(GX_ROOT . '/uploads/media/test');

// --- Activity log: what changed, not just "saved" ---
$prod = db_one('SELECT * FROM products WHERE id = 1');
$pv = db_all('SELECT * FROM product_variants WHERE product_id = 1 ORDER BY sort_order, id');
$payload = ['action' => 'save', 'id' => 1, 'name' => $prod['name'], 'slug' => $prod['slug'], 'category_id' => $prod['category_id'], 'brand_id' => $prod['brand_id'],
    'is_active' => 1, 'variants' => array_map(static fn($v) => ['id' => $v['id'], 'name' => $v['name'], 'sku' => $v['sku'], 'is_active' => $v['is_active'],
        'price' => number_format(((int) $v['price_cents'] + ($v === $pv[0] ? 1000 : 0)) / 100, 2, '.', ''), 'weight_kg' => (string) ((int) $v['weight_grams'] / 1000),
        'low_stock_threshold' => $v['low_stock_threshold'], 'cost' => $v['cost_cents'] !== null ? number_format((int) $v['cost_cents'] / 100, 2, '.', '') : ''], $pv)];
[$st, $res] = $p4->api('POST', '/admin/api/products.php', $payload);
$ad = json_decode((string) db_val("SELECT details FROM audit_log WHERE action = 'product.saved' AND entity_id = 1 ORDER BY id DESC LIMIT 1"), true) ?: [];
check('price change is logged with the old and new price', $st === 200 && ($ad['sizes'][$pv[0]['id']]['price_cents'] ?? null) === [(int) $pv[0]['price_cents'], (int) $pv[0]['price_cents'] + 1000],
    $st . ' ' . json_encode($ad ?: $res));
$breed = db_one('SELECT * FROM breeds ORDER BY id LIMIT 1');
[$st] = $p4->api('POST', '/admin/api/manage.php', ['action' => 'save', 'e' => 'breeds', 'id' => $breed['id'], 'name' => $breed['name'], 'kind' => 'Goat', 'slug' => $breed['slug'], 'is_active' => 1, 'sort_order' => $breed['sort_order']]);
$ad = json_decode((string) db_val("SELECT details FROM audit_log WHERE action = 'manage.saved' AND entity_type = 'breeds' ORDER BY id DESC LIMIT 1"), true) ?: [];
check('list edits are logged with before/after (breed kind Sheep → Goat)', $st === 200 && ($ad['changes']['kind'] ?? null) === ['Sheep', 'Goat'], json_encode($ad));
db_exec("UPDATE breeds SET kind = 'Sheep'");
[$st] = $p4->api('POST', '/admin/api/settings.php', ['action' => 'settings', 's' => ['eft_hold_hours' => '72', 'social_facebook' => 'http://not-https.example']]);
check('settings: one invalid value saves nothing', $st === 422 && db_val("SELECT setting_value FROM settings WHERE setting_key = 'eft_hold_hours'") === '48');
[$st] = $p4->api('POST', '/admin/api/settings.php', ['action' => 'settings', 's' => ['eft_hold_hours' => '72']]);
$ad = json_decode((string) db_val("SELECT details FROM audit_log WHERE action = 'settings.saved' ORDER BY id DESC LIMIT 1"), true) ?: [];
check('settings changes are logged with before/after', $st === 200 && ($ad['changes']['eft_hold_hours'] ?? null) === ['48', '72'], json_encode($ad));
db_exec("UPDATE settings SET setting_value = '48' WHERE setting_key = 'eft_hold_hours'");

// --- CSV exports ---
db_exec("DELETE FROM role_permissions WHERE role_id = 3 AND permission_id = (SELECT id FROM permissions WHERE slug = 'reports.view')");
db_exec("DELETE FROM role_permissions WHERE role_id = 3 AND permission_id = (SELECT id FROM permissions WHERE slug = 'customers.view')");
$sx4 = new TestHttp($base);
$sx4->page('/admin/login.php');
// (from another address: the earlier tests used up 127.0.0.1's login allowance)
$sx4->api('POST', '/admin/api/auth.php', ['action' => 'login', 'email' => 'staff@test.local', 'password' => $ownerPw], null, ['X-Test-Remote-Addr: 10.44.0.4']);
$sx4->page('/admin/');
$csvOrders = $sx4->page('/admin/reports.php?export=orders&status=cancelled');
$csvLines = array_values(array_filter(explode("\n", trim($csvOrders))));
check('staff with "View orders" (no Reports) can export the orders CSV', str_contains($csvLines[0] ?? '', 'Order,Division'), substr($csvOrders, 0, 120));
check('the orders CSV uses the page filters (only cancelled orders)', count($csvLines) > 1 && !array_filter(array_slice($csvLines, 1), static fn($l) => !str_contains($l, ',cancelled,')));
check('staff without "View customers" cannot export customers', str_contains($sx4->page('/admin/reports.php?export=customers'), 'You do not have permission for this export.'));
check('… while the owner can (header present)', str_contains($p4->page('/admin/reports.php?export=customers'), '"First name","Last name",Email,Phone'));
db_exec("DELETE FROM role_permissions WHERE role_id = 3 AND permission_id IN (SELECT id FROM permissions WHERE slug IN ('orders.view','documents.view'))");
db_exec("INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT 3, id FROM permissions WHERE slug = 'reports.view'");
check('a "Reports"-only role (bookkeeper) can export invoices', str_contains($sx4->page('/admin/reports.php?export=documents'), 'Date,Type,Number'));
check('… but not the orders list with buyers\' email and phone', str_contains($sx4->page('/admin/reports.php?export=orders'), 'You do not have permission for this export.'));
check('… nor the customers list', str_contains($sx4->page('/admin/reports.php?export=customers'), 'You do not have permission for this export.'));
db_exec("DELETE FROM role_permissions WHERE role_id = 3 AND permission_id = (SELECT id FROM permissions WHERE slug = 'reports.view')");
db_exec("INSERT IGNORE INTO role_permissions (role_id, permission_id) SELECT 3, id FROM permissions WHERE slug IN ('orders.view','documents.view','customers.view')");
$cnDoc = db_one("SELECT * FROM documents WHERE doc_type = 'credit_note' AND is_void = 0 ORDER BY id LIMIT 1");
$csvDocs = $p4->page('/admin/reports.php?export=documents&q=' . rawurlencode($cnDoc['doc_no']));
check('negative amounts stay numbers in the CSV (no leading quote)', str_contains($csvDocs, ',-' . number_format(abs((int) $cnDoc['total_cents']) / 100, 2, '.', '')) && !str_contains($csvDocs, "'-"), $csvDocs);
db_exec('UPDATE documents SET is_void = 1 WHERE id = ?', [$cnDoc['id']]);
check('voided documents are left out of the CSV', !str_contains($p4->page('/admin/reports.php?export=documents&q=' . rawurlencode($cnDoc['doc_no'])), $cnDoc['doc_no']));
db_exec('UPDATE documents SET is_void = 0 WHERE id = ?', [$cnDoc['id']]);

// --- Deleting a product with stock history hides it instead of failing ---
$np = db_insert("INSERT INTO products (category_id, slug, name) VALUES (?, 'receipt-test', 'Receipt Test')", [$catId]);
$nv = db_insert("INSERT INTO product_variants (product_id, sku, name, price_cents, stock_qty) VALUES (?, 'RCPT-1', '25kg', 10000, 0)", [$np]);
$rcpt = db_insert("INSERT INTO stock_receipts (division_id, supplier_name, received_on) VALUES (1, 'Co-op', CURDATE())");
db_exec('INSERT INTO stock_receipt_items (receipt_id, variant_id, qty) VALUES (?, ?, 5)', [$rcpt, $nv]);
[$st, $res] = $p4->api('POST', '/admin/api/products.php', ['action' => 'delete', 'id' => $np]);
check('product with received stock is hidden, not deleted (no error)', $st === 200 && (string) db_val('SELECT is_active FROM products WHERE id = ?', [$np]) === '0', $st . ' ' . json_encode($res));
$np2 = db_insert("INSERT INTO products (category_id, slug, name) VALUES (?, 'no-history', 'No History')", [$catId]);
db_insert("INSERT INTO product_variants (product_id, sku, name, price_cents, stock_qty) VALUES (?, 'NOHIST-1', '5kg', 5000, 0)", [$np2]);
[$st] = $p4->api('POST', '/admin/api/products.php', ['action' => 'delete', 'id' => $np2]);
check('a product without any history is really deleted', $st === 200 && !db_val('SELECT 1 FROM products WHERE id = ?', [$np2]));

section('Phase 4: admin users, password reset, small fixes');
// --- Owner edits an admin user; the last owner can't be demoted ---
db_exec("UPDATE admin_users a JOIN roles r ON r.id = a.role_id SET a.status = 'disabled' WHERE r.slug = 'owner' AND a.email <> 'owner@test.local'");
$staffId = (int) db_val("SELECT id FROM admin_users WHERE email = 'staff@test.local'");
$ownerId = (int) db_val("SELECT id FROM admin_users WHERE email = 'owner@test.local'");
[$st, $res] = $p4->api('POST', '/admin/api/users.php', ['action' => 'update', 'id' => $staffId, 'name' => 'Staff Renamed', 'role_id' => 2, 'division_scope' => 'feeds']);
$su = db_one('SELECT name, role_id, division_scope FROM admin_users WHERE id = ?', [$staffId]);
check('owner can change an admin user\'s name, role and divisions', $st === 200 && $su['name'] === 'Staff Renamed' && (int) $su['role_id'] === 2 && $su['division_scope'] === 'feeds', $st . ' ' . json_encode($res));
db_exec("UPDATE admin_users SET name = 'Staff', role_id = 3, division_scope = 'all' WHERE id = ?", [$staffId]);
[$st] = $p4->api('POST', '/admin/api/users.php', ['action' => 'update', 'id' => $ownerId, 'name' => 'Owner', 'role_id' => 2, 'division_scope' => 'all']);
check('the last owner cannot be demoted', $st === 409 && (int) db_val('SELECT role_id FROM admin_users WHERE id = ?', [$ownerId]) === 1, (string) $st);
[$st] = $p4->api('POST', '/admin/api/users.php', ['action' => 'update', 'id' => $ownerId, 'name' => 'Owner', 'role_id' => 1, 'division_scope' => 'ranch']);
check('… nor limited to one division', $st === 409 && db_val('SELECT division_scope FROM admin_users WHERE id = ?', [$ownerId]) === 'all');
$feedsOwner = db_insert('INSERT INTO admin_users (role_id, email, password_hash, name, division_scope) VALUES (1, ?, ?, ?, ?)', ['feedsowner@test.local', admin_password_hash($ownerPw), 'Feeds Owner', 'feeds']);
$fo2 = new TestHttp($base);
$fo2->page('/admin/login.php');
$fo2->api('POST', '/admin/api/auth.php', ['action' => 'login', 'email' => 'feedsowner@test.local', 'password' => $ownerPw], null, ['X-Test-Remote-Addr: 10.44.0.11']);
$fo2->page('/admin/');
[$st] = $fo2->api('POST', '/admin/api/users.php', ['action' => 'toggle', 'id' => $ownerId]);
check('an owner limited to one division cannot disable the full owner', $st === 403 && db_val('SELECT status FROM admin_users WHERE id = ?', [$ownerId]) === 'active', (string) $st);
[$st] = $fo2->api('POST', '/admin/api/users.php', ['action' => 'update', 'id' => $feedsOwner, 'name' => 'Feeds Owner', 'role_id' => 1, 'division_scope' => 'all']);
check('… nor give themselves access to both divisions', $st === 403 && db_val('SELECT division_scope FROM admin_users WHERE id = ?', [$feedsOwner]) === 'feeds', (string) $st);
[$st] = $fo2->api('POST', '/admin/api/users.php', ['action' => 'create', 'name' => 'Sneaky', 'email' => 'sneaky@test.local', 'role_id' => 1, 'division_scope' => 'all', 'password' => 'Sneaky-Pass-99']);
check('… nor create a new full owner', $st === 403 && !db_val("SELECT 1 FROM admin_users WHERE email = 'sneaky@test.local'"), (string) $st);
db_exec("UPDATE admin_users a JOIN roles r ON r.id = a.role_id SET a.status = 'disabled' WHERE r.slug = 'owner' AND a.email NOT IN ('owner@test.local', 'feedsowner@test.local')");
$secondFull = db_insert('INSERT INTO admin_users (role_id, email, password_hash, name, division_scope) VALUES (1, ?, ?, ?, ?)', ['second@owner.local', admin_password_hash($ownerPw), 'Second Owner', 'all']);
[$st] = $p4->api('POST', '/admin/api/users.php', ['action' => 'toggle', 'id' => $secondFull]);
check('a full owner can disable another full owner', $st === 200 && db_val('SELECT status FROM admin_users WHERE id = ?', [$secondFull]) === 'disabled', (string) $st);
[$st] = $p4->api('POST', '/admin/api/users.php', ['action' => 'update', 'id' => $feedsOwner, 'name' => 'Feeds Owner', 'role_id' => 2, 'division_scope' => 'feeds']);
check('an owner limited to one division can be made a manager', $st === 200 && (int) db_val('SELECT role_id FROM admin_users WHERE id = ?', [$feedsOwner]) === 2, (string) $st);
db_exec("UPDATE admin_users a JOIN roles r ON r.id = a.role_id SET a.status = 'active' WHERE r.slug = 'owner'");

// --- Admin "forgot password" ---
$rpId = db_insert('INSERT INTO admin_users (role_id, email, password_hash, name, division_scope) VALUES (3, ?, ?, ?, ?)', ['resetme@test.local', admin_password_hash('Old-Password-1'), 'Reset Me', 'all']);
$fh = new TestHttp($base);
$fh->page('/admin/forgot.php');
[$st, $res] = $fh->api('POST', '/admin/api/auth.php', ['action' => 'forgot', 'email' => 'resetme@test.local'], null, ['X-Test-Remote-Addr: 10.44.0.7']);
[$st2, $res2] = $fh->api('POST', '/admin/api/auth.php', ['action' => 'forgot', 'email' => 'nobody-admin@test.local'], null, ['X-Test-Remote-Addr: 10.44.0.7']);
check('forgot password: same answer whether or not the admin exists', $st === 200 && $st2 === 200 && ($res['message'] ?? 'a') === ($res2['message'] ?? 'b'), json_encode([$res, $res2]));
$rm = last_mail('resetme@test.local', 'Reset your GrazeX admin password%');
preg_match('#/admin/reset\.php\?token=([a-f0-9]{64})#', $rm, $m);
$rtok = $m[1] ?? '';
check('a reset link is emailed', $rtok !== '' && !db_val("SELECT 1 FROM email_queue WHERE to_email = 'nobody-admin@test.local'"));
check('the reset page shows the new-password form', str_contains($fh->page('/admin/reset.php?token=' . $rtok), 'name="password_confirm"'));
$svBefore = (int) db_val('SELECT session_version FROM admin_users WHERE id = ?', [$rpId]);
[$st, $res] = $fh->api('POST', '/admin/api/auth.php', ['action' => 'reset', 'token' => $rtok, 'password' => 'New-Password-22', 'password_confirm' => 'New-Password-22'], null, ['X-Test-Remote-Addr: 10.44.0.7']);
check('the link sets a new password and logs out other sessions', $st === 200 && password_verify('New-Password-22', (string) db_val('SELECT password_hash FROM admin_users WHERE id = ?', [$rpId]))
    && (int) db_val('SELECT session_version FROM admin_users WHERE id = ?', [$rpId]) === $svBefore + 1, $st . ' ' . json_encode($res));
[$st] = $fh->api('POST', '/admin/api/auth.php', ['action' => 'reset', 'token' => $rtok, 'password' => 'Another-Pass-33', 'password_confirm' => 'Another-Pass-33'], null, ['X-Test-Remote-Addr: 10.44.0.7']);
check('a reset link works only once', $st === 422 && password_verify('New-Password-22', (string) db_val('SELECT password_hash FROM admin_users WHERE id = ?', [$rpId])));
$old = step('make a token', static fn() => admin_token_create($rpId));
db_exec('UPDATE admin_tokens SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE admin_user_id = ? AND used_at IS NULL', [$rpId]);
[$st] = $fh->api('POST', '/admin/api/auth.php', ['action' => 'reset', 'token' => (string) $old, 'password' => 'Another-Pass-33', 'password_confirm' => 'Another-Pass-33'], null, ['X-Test-Remote-Addr: 10.44.0.7']);
check('an expired reset link is refused', $st === 422);
[$st] = $fh->api('POST', '/admin/api/auth.php', ['action' => 'login', 'email' => 'resetme@test.local', 'password' => 'New-Password-22'], null, ['X-Test-Remote-Addr: 10.44.0.7']);
check('… and the admin can log in with the new password', $st === 200);

// --- Small fixes ---
db_exec("UPDATE lots SET lot_no = 'GXR-L050' WHERE id = (SELECT id FROM (SELECT MAX(id) id FROM lots) t)");
$maxNo = (int) db_val("SELECT MAX(CAST(SUBSTRING(lot_no, 6) AS UNSIGNED)) FROM lots WHERE lot_no REGEXP '^GXR-L[0-9]+$'");
check('new lot number continues after the highest one in use', str_contains($p4->page('/admin/lot.php'), 'value="GXR-L' . str_pad((string) ($maxNo + 1), 3, '0', STR_PAD_LEFT) . '"'));
[$st] = $p4->api('POST', '/admin/api/inventory.php', ['action' => 'stocktake_start']);
$take2 = db_one("SELECT * FROM stock_takes WHERE status = 'open' ORDER BY id DESC LIMIT 1");
$tl = db_one('SELECT * FROM stock_take_items WHERE stock_take_id = ? AND variant_id = 11', [$take2['id']]);
[$st, $res] = $p4->api('POST', '/admin/api/inventory.php', ['action' => 'stocktake_complete', 'id' => $take2['id'], 'counts' => [(string) $tl['id'] => '7']]);
check('"Complete stock take" also applies counts typed but not saved yet', $st === 200 && stock(11) === 7, $st . ' ' . json_encode($res) . ' stock ' . stock(11));
[$st] = $p4->api('POST', '/admin/api/inventory.php', ['action' => 'stocktake_save', 'id' => $take2['id'], 'counts' => [(string) $tl['id'] => '2']]);
check('counts can no longer be saved once the stock take is completed', $st === 409 && stock(11) === 7);
[$st, $res] = $p4->api('POST', '/admin/api/inventory.php', ['action' => 'receive', 'lines' => [['variant_id' => 999999, 'qty' => 3]]]);
check('receiving stock for an unknown product gives a clear message (no server error)', $st === 422, $st . ' ' . json_encode($res));
setting_set('last_cron_run', date('Y-m-d H:i:s', time() - 5 * 3600));
$dash = $p4->page('/admin/');
check('dashboard warns when scheduled jobs have not run for hours', str_contains($dash, 'data-cron-warning'));
check('dashboard greeting is in English', (bool) preg_match('/Good (morning|afternoon|evening),/', $dash));
setting_set('last_cron_run', now());
check('… and the warning goes once cron runs again', !str_contains($p4->page('/admin/'), 'data-cron-warning'));
db_exec("UPDATE pages SET show_in_footer = 0 WHERE slug = 'returns'");
$home = (new TestHttp($base))->page('/pages/contact.php');
check('a page switched off "Link in footer menu" leaves the footer', !str_contains($home, '/pages/returns') && str_contains($home, 'href="/pages/terms"'));
db_exec("UPDATE pages SET show_in_footer = 1 WHERE slug = 'returns'");
db_exec("UPDATE menu_items SET is_active = 0 WHERE url = '/pages/returns'");
check('a footer menu item switched off stays off', !str_contains((new TestHttp($base))->page('/pages/contact.php'), '/pages/returns'));
db_exec("UPDATE menu_items SET is_active = 1 WHERE url = '/pages/returns'");
db_exec("UPDATE pages SET show_in_footer = 1 WHERE slug = 'returns'");

section('Phase 5: one address per page, search engines');
$pv = new TestHttp($base);
$ldOf = static function (string $html): array {
    preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);
    return array_map(static fn($j) => json_decode($j, true), $m[1]);
};
$canonOf = static fn(string $html): string => preg_match('#<link rel="canonical" href="([^"]+)"#', $html, $m) ? $m[1] : '';
// Absolute URL ending in exactly this path (the server's APP_URL is not the test process's)
$isUrl = static fn(string $url, string $path): bool => (bool) preg_match('#^https?://[^/]+' . preg_quote($path, '#') . '$#', $url);
$prod = db_one("SELECT p.slug, c.slug AS cslug FROM products p JOIN categories c ON c.id = p.category_id WHERE p.is_active = 1
                AND EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = p.id AND v.is_active = 1) ORDER BY p.id LIMIT 1");
$h = $pv->page('/feeds/product/' . $prod['slug']);
check('product page: canonical is its own pretty address', $isUrl($canonOf($h), '/feeds/product/' . $prod['slug']), $canonOf($h));
$ld = $ldOf($h);
$types = array_column($ld, '@type');
check('product page: Product and BreadcrumbList structured data, no empty values', in_array('Product', $types, true) && in_array('BreadcrumbList', $types, true)
    && !preg_match('#"[^"]+":(null|"")#', implode('', array_map('json_encode', $ld))), json_encode($types));
check('product page: offer links use the pretty address', $isUrl((string) ($ld[0]['offers'][0]['url'] ?? ''), '/feeds/product/' . $prod['slug']));
[$st, $hd] = $pv->headers('/feeds/product.php?slug=' . $prod['slug']);
check('old product address moves permanently to the pretty one (301)', $st === 301 && ($hd['location'] ?? '') === '/feeds/product/' . $prod['slug'], $st . ' ' . ($hd['location'] ?? ''));
$lotSlug = (string) db_val("SELECT slug FROM lots WHERE status = 'available' ORDER BY id LIMIT 1");
$h = $pv->page('/ranch/lot/' . $lotSlug);
$ld = $ldOf($h);
check('lot page: canonical, Product with an offer and availability, breadcrumbs', $isUrl($canonOf($h), '/ranch/lot/' . $lotSlug)
    && ($ld[0]['@type'] ?? '') === 'Product' && str_starts_with((string) ($ld[0]['offers']['availability'] ?? ''), 'https://schema.org/')
    && in_array('BreadcrumbList', array_column($ld, '@type'), true), json_encode($ld[0] ?? null));
$lotRow = db_one('SELECT pricing_mode, price_cents, avg_weight_kg FROM lots WHERE slug = ?', [$lotSlug]);
if ($lotRow['pricing_mode'] === 'per_kg') {
    check('per-kg lot: the offer price is the estimate per animal; the per-kg rate is the unit price',
        ($ld[0]['offers']['price'] ?? '') === number_format(round((float) $lotRow['avg_weight_kg'] * (int) $lotRow['price_cents']) / 100, 2, '.', '')
        && ($ld[0]['offers']['priceSpecification']['referenceQuantity']['unitCode'] ?? '') === 'KGM', json_encode($ld[0]['offers'] ?? null));
}
[$st, $hd] = $pv->headers('/ranch/lot.php?slug=' . $lotSlug);
check('old lot address moves to the pretty one', $st === 301 && ($hd['location'] ?? '') === '/ranch/lot/' . $lotSlug);
[$st, $hd] = $pv->headers('/pages/page.php?slug=terms');
check('old information page address moves to /pages/terms', $st === 301 && ($hd['location'] ?? '') === '/pages/terms');
check('information page canonical is /pages/terms', $isUrl($canonOf($pv->page('/pages/terms')), '/pages/terms'));
check('the shop filtered by category (old address) points search engines to /feeds/category/…',
    $isUrl($canonOf($pv->page('/feeds/shop.php?category=' . $prod['cslug'] . '&sort=price_asc')), '/feeds/category/' . $prod['cslug']));
check('livestock page 2 is its own page for search engines', $isUrl($canonOf($pv->page('/ranch/livestock.php?page=2')), '/ranch/livestock.php?page=2'));
[$st, $hd] = $pv->headers('/feeds/product/' . $prod['slug'] . '/');
check('a trailing slash moves to the exact product address', $st === 301 && ($hd['location'] ?? '') === '/feeds/product/' . $prod['slug'], $st . ' ' . ($hd['location'] ?? ''));
[$st] = $pv->headers('/feeds/category/no-such-category');
check('an unknown category is "not found" (404), not an empty shop page', $st === 404, (string) $st);
db_exec('UPDATE categories SET is_active = 0 WHERE slug = ?', [$prod['cslug']]);
[$st] = $pv->headers('/feeds/category/' . $prod['cslug']);
$hp = $pv->page('/feeds/product/' . $prod['slug']);
check('a hidden category is "not found", and its products link to the shop instead', $st === 404 && !str_contains($hp, '/feeds/category/' . $prod['cslug']), (string) $st);
check('… and it leaves the sitemap', !str_contains($pv->page('/sitemap.xml'), '/feeds/category/' . $prod['cslug'] . '<'));
db_exec('UPDATE categories SET is_active = 1 WHERE slug = ?', [$prod['cslug']]);
$_GET = ['category' => $prod['cslug'], 'sort' => 'price_asc', 'page' => 1];
$pg = pagination(1, 3, category_url($prod['cslug']), ['category']);
$_GET = [];
check('category page links to its next page as /feeds/category/…?page=2 (no ?category=)', str_contains($pg, '/feeds/category/' . $prod['cslug'] . '?sort=price_asc&amp;page=2') && !str_contains($pg, 'category='), $pg);
check('search results are not indexed', str_contains($pv->page('/feeds/shop.php?q=lick'), 'name="robots" content="noindex'));
check('shop cards link to pretty product addresses', str_contains($pv->page('/feeds/shop.php'), 'href="/feeds/product/'));
db_exec("INSERT INTO products (category_id, slug, name, is_active) VALUES (?, 'no-sizes-yet', 'No sizes yet', 1)", [$catId]);
$sm = $pv->page('/sitemap.xml');
check('sitemap: pretty addresses only (pages, categories), no ?slug=', str_contains($sm, '/pages/terms</loc>') && str_contains($sm, '/feeds/category/' . $prod['cslug'] . '</loc>') && !str_contains($sm, '?slug='));
check('sitemap: a product without any live size is left out', !str_contains($sm, 'no-sizes-yet'));
db_exec("DELETE FROM products WHERE slug = 'no-sizes-yet'");
db_exec("UPDATE menu_items SET url = '/pages/page.php?slug=about' WHERE url = '/pages/about'");
$hf = $pv->page('/pages/contact.php');
check('footer shows old-style menu links in the pretty form', str_contains($hf, 'href="/pages/about"') && !str_contains($hf, 'slug=about'));
db_exec("UPDATE menu_items SET url = '/pages/about' WHERE url = '/pages/page.php?slug=about'");

section('Phase 5: accessibility, speed and small UX (server side)');
$fh5 = $pv->page('/feeds/');
check('closed cart drawer is inert and a real modal when open', (bool) preg_match('#<aside class="drawer" id="cart-drawer"[^>]*aria-modal="true"[^>]*inert>#', $fh5));
check('closed mobile menu is inert and labelled as a dialog', (bool) preg_match('#<div class="menu-sheet" id="menu-sheet" role="dialog" aria-modal="true" aria-label="[^"]+" aria-hidden="true" inert>#', $fh5));
check('cart button says how many items it holds', (bool) preg_match('#data-cart-label="Cart"\s+aria-label="Cart, \d+ items?"#', $fh5));
$heroAt = (int) strpos($fh5, 'class="store-hero"');
$hero = substr($fh5, $heroAt, (int) strpos($fh5, '</section>', $heroAt) - $heroAt);
check('Feeds hero is visible without waiting for JavaScript (no .reveal above the fold)', !preg_match('#class="[^"]*\breveal\b#', $hero) && str_contains($hero, 'hero-rise'));
$home = $pv->page('/');
preg_match_all('#class="hs-num" data-count="(\d+)">(\d+)</span>#', $home, $hs, PREG_SET_ORDER);
check('home stats show the real numbers in the page (not 0 until JavaScript runs)', count($hs) === 3 && !array_filter($hs, static fn($m) => $m[1] !== $m[2]), json_encode($hs));
check('both home portal images come in phone sizes (srcset)', preg_match_all('#class="portal portal-(ranch|feeds)[^>]*>\s*<img [^>]*srcset="[^"]*480w#', $home) === 2);

// Page backgrounds: each area has its scene; a photo from Admin > Site content > Page backgrounds replaces it
$bgGd = imagecreatetruecolor(1600, 900);
imagefill($bgGd, 0, 0, imagecolorallocate($bgGd, 40, 90, 140));
@mkdir(GX_ROOT . '/uploads/media/test', 0755, true);
$bgBase = 'uploads/media/test/' . random_token(6);
$bgVariants = media_generate_variants($bgGd, $bgBase, .3, .6);
$bgMid = db_insert("INSERT INTO media (base_path, original_name, mime, width, height, size_bytes, focal_x, focal_y, variants) VALUES (?, 'bg.png', 'image/webp', 1600, 900, 1, .3, .6, ?)",
    [$bgBase, json_encode($bgVariants, JSON_UNESCAPED_SLASHES)]);
$bgOf = static fn(string $path): string => preg_match('#<div class="page-bg"[^>]*>.*?</div>#s', $pv->page($path), $bgM) ? $bgM[0] : '';
check('home, Feeds and Ranch each show their own background scene',
    str_contains($bgOf('/'), 'data-scene="home"') && str_contains($bgOf('/feeds/'), 'data-scene="feeds"') && str_contains($bgOf('/ranch/'), 'data-scene="ranch"'));
db_exec("INSERT INTO content_blocks (scope, block_key, type, label, media_id) VALUES ('ranch', 'page_bg', 'image', 'Page bg', ?)", [$bgMid]);
check('a background photo saved on the old Ranch tab still shows behind the Ranch pages (and only there)',
    str_contains($bgOf('/ranch/'), $bgBase) && !str_contains($bgOf('/feeds/'), $bgBase));
[$st] = $p4->api('POST', '/admin/api/content.php', ['action' => 'save', 'scope' => 'global', 'images' => ['bg_ranch' => '']]);
check('… until Page backgrounds says otherwise: Remove brings the built-in scene back', $st === 200 && !str_contains($bgOf('/ranch/'), $bgBase) && str_contains($bgOf('/ranch/'), '/assets/img/bg/ranch-'));
[$st] = $p4->api('POST', '/admin/api/content.php', ['action' => 'save', 'scope' => 'global', 'images' => ['bg_ranch' => $bgMid]]);
$bgRanch = $bgOf('/ranch/');
check('a photo chosen on Page backgrounds replaces the Ranch scene, keeping its focus point in view on phones',
    $st === 200 && str_contains($bgRanch, $bgBase) && str_contains($bgRanch, 'data-photo') && str_contains($bgRanch, 'object-position:30% 60%'));
check('… behind every Ranch page (the livestock board too), not behind Feeds', str_contains($bgOf('/ranch/livestock.php'), $bgBase) && !str_contains($bgOf('/feeds/'), $bgBase));
$bgTab = $p4->page('/admin/content.php?s=backgrounds');
check('the Page backgrounds tab shows the live Ranch photo and saves to the global scope',
    str_contains($bgTab, 'name="images[bg_ranch]" value="' . $bgMid . '"') && str_contains($bgTab, 'name="scope" value="global"'));
check('… and the home page card photos say they are not the page backgrounds', str_contains($p4->page('/admin/content.php?s=gateway'), 'Home page — Ranch card photo'));
db_exec("DELETE FROM content_blocks WHERE (scope = 'global' AND block_key LIKE 'bg\\_%') OR (scope = 'ranch' AND block_key = 'page_bg')");
db_exec('DELETE FROM media WHERE id = ?', [$bgMid]);
foreach (array_merge(...array_values(array_map('array_values', $bgVariants))) as $bgFile) {
    @unlink(GX_ROOT . '/' . $bgFile);
}
$calc = $pv->page('/feeds/calculator.php');
check('calculator animal buttons use valid ARIA (pressed toggle buttons, not radio roles)', !str_contains($calc, 'role="radio"') && str_contains($calc, 'aria-pressed='));
preg_match('#data-rules="([^"]*)"#', $calc, $m);
$calcRules = json_decode(html_entity_decode($m[1] ?? '', ENT_QUOTES), true) ?: [];
$calcOk = $calcRules !== [];
foreach ($calcRules as $r) {
    $pid = (int) db_val('SELECT product_id FROM calculator_rules WHERE id = ?', [$r['id']]);
    $want = array_map('intval', db_col('SELECT id FROM product_variants WHERE product_id = ? AND is_active = 1 AND weight_grams > 0 ORDER BY weight_grams, id', [$pid]));
    $calcOk = $calcOk && array_column($r['variants'], 'id') === $want;
    foreach ($r['variants'] as $v) {
        $calcOk = $calcOk && array_column($v['tiers'], 'min') === array_map('intval', db_col('SELECT min_qty FROM price_tiers WHERE variant_id = ? ORDER BY min_qty', [$v['id']]));
    }
}
check('calculator (fewer queries) still shows every live size and its bulk prices', $calcOk, json_encode(array_slice($calcRules, 0, 1)));
$sw = $pv->page('/sw.js');
check('service worker precaches the exact versioned files the pages use', str_contains($sw, asset('/assets/css/core.css')) && str_contains($sw, '/offline.html'));
check('offline page exists', str_contains($pv->page('/offline.html'), "You're offline"));
$faqOn = db_col('SELECT id FROM faqs WHERE is_active = 1 AND division_id = 2');
db_exec('UPDATE faqs SET is_active = 0 WHERE division_id = 2');
check('Ranch "FAQ" link only appears when there are FAQs', !str_contains($pv->page('/ranch/'), '/ranch/#faq'));
if ($faqOn) {
    db_exec('UPDATE faqs SET is_active = 1 WHERE id IN (' . db_placeholders($faqOn) . ')', $faqOn);
}
check('… and comes back with them', str_contains($pv->page('/ranch/'), '/ranch/#faq'));
check('contact page marks "Contact" as the current page', (bool) preg_match('#href="/pages/contact\.php" aria-current="page"#', $pv->page('/pages/contact.php')));
// Address forms: area, province and postal code are saved; bad values are refused
$ah = new TestHttp($base);
$ah->page('/account/login.php');
$ah->api('POST', '/account/api/auth.php', ['action' => 'login', 'email' => 'docs@test.local', 'password' => 'x'], null, ['X-Test-Remote-Addr: 10.55.0.1']);
$ah->page('/account/addresses.php');
[$st] = $ah->api('POST', '/account/api/addresses.php', ['action' => 'create', 'recipient_name' => 'A', 'phone' => '0821234567', 'line1' => '1 Farm Rd',
    'line2' => 'Plot 7', 'town' => 'Riverside', 'province' => 'Western Cape', 'postal_code' => '7300']);
$addr = db_one("SELECT line2, province, postal_code FROM customer_addresses WHERE customer_id = ? ORDER BY id DESC LIMIT 1", [$c5d['id']]);
check('saved address keeps area, province and postal code', $st === 200 && $addr && $addr['line2'] === 'Plot 7' && $addr['province'] === 'Western Cape' && $addr['postal_code'] === '7300', json_encode($addr));
[$st] = $ah->api('POST', '/account/api/addresses.php', ['action' => 'create', 'recipient_name' => 'A', 'phone' => '0821234567', 'line1' => '1 Farm Rd', 'town' => 'X', 'province' => 'Atlantis']);
[$st2] = $ah->api('POST', '/account/api/addresses.php', ['action' => 'create', 'recipient_name' => 'A', 'phone' => '0821234567', 'line1' => '1 Farm Rd', 'town' => 'X', 'postal_code' => '12']);
check('unknown province or a bad postal code is refused', $st === 422 && $st2 === 422, $st . ' ' . $st2);
$ah->page('/feeds/');
[$st] = $ah->api('POST', '/feeds/api/cart.php', ['action' => 'add', 'target_id' => 5, 'qty' => 1]);
check('checkout address form asks for area, province and postal code (with autofill hints)',
    $st === 200 && (bool) preg_match('#name="address\[line2\]" autocomplete="shipping address-line2".*name="address\[postal_code\]".*name="address\[province\]"#s', $ah->page('/feeds/checkout.php')));
// A delivery order: area/plot, province and postal code reach the order; a bad province is refused; Plot 7 and Plot 9 stay two addresses
// (a size with enough stock to reach the delivery zone's minimum order)
$bulk = db_one('SELECT v.id, v.price_cents FROM product_variants v JOIN products p ON p.id = v.product_id
                WHERE p.is_active = 1 AND v.is_active = 1 AND v.stock_qty >= 40 ORDER BY v.price_cents DESC LIMIT 1');
$bulkQty = (int) max(1, ceil(60000 / max(1, (int) $bulk['price_cents'])));
$ah->api('POST', '/feeds/api/cart.php', ['action' => 'add', 'target_id' => $bulk['id'], 'qty' => $bulkQty]);
$pBody = ['action' => 'place', 'billing_name' => 'Plot Test', 'billing_phone' => '0821234567', 'fulfilment' => 'delivery', 'payment_method' => 'eft', 'accept_terms' => 1,
    'delivery_date' => DeliveryService::deliveryDates(DeliveryService::zoneForTown('Riverside'), 10)[0] ?? null, 'town' => 'Riverside', 'address_id' => 'new', 'save_address' => 1,
    'address' => ['recipient_name' => 'Plot Test', 'phone' => '0821234567', 'line1' => '5 River Rd', 'line2' => 'Plot 7', 'province' => 'Western Cape', 'postal_code' => '7300']];
$bad = $pBody;
$bad['address']['province'] = 'Atlantis';
[$st, $res] = $ah->api('POST', '/feeds/api/checkout.php', $bad);
check('checkout refuses an unknown province', $st === 422 && isset($res['errors']['address[province]']), $st . ' ' . json_encode($res));
[$st, $res] = $ah->api('POST', '/feeds/api/checkout.php', $pBody);
$po5 = db_one('SELECT ship_line2, ship_province, ship_postal_code FROM orders WHERE customer_id = ? ORDER BY id DESC LIMIT 1', [$c5d['id']]);
check('area, province and postal code reach the delivery order', $st === 200 && $po5['ship_line2'] === 'Plot 7' && $po5['ship_province'] === 'Western Cape'
    && $po5['ship_postal_code'] === '7300', $st . ' ' . json_encode([$po5, $res]));
$ah->api('POST', '/feeds/api/cart.php', ['action' => 'add', 'target_id' => $bulk['id'], 'qty' => $bulkQty]);
$pBody['address']['line2'] = 'Plot 9';
[$st] = $ah->api('POST', '/feeds/api/checkout.php', $pBody);
check('Plot 7 and Plot 9 on the same road are saved as two addresses (the first is not overwritten)', $st === 200
    && db_col("SELECT line2 FROM customer_addresses WHERE customer_id = ? AND line1 = '5 River Rd' ORDER BY line2", [$c5d['id']]) === ['Plot 7', 'Plot 9'], (string) $st);

section('Phase 6: keep me logged in');
$appUa = 'User-Agent: Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/131.0 Mobile Safari/537.36 GrazeXApp/1.0';
$rmPw = 'Remember-Me-123!';
$mkRemember = static function (string $email) use ($rmPw): int {
    return db_insert('INSERT INTO customers (email, password_hash, first_name, last_name, phone, email_verified_at) VALUES (?,?,?,?,?,NOW())',
        [$email, customer_password_hash($rmPw), 'Remy', 'Member', '0821234567']);
};
$ipN = 0;
$loginAs = static function (string $email, bool $remember, array $extra = []) use ($base, $rmPw, &$ipN): TestHttp {
    $h = new TestHttp($base);
    $h->extra = array_merge(['X-Test-Remote-Addr: 10.46.0.' . (++$ipN)], $extra);
    $h->page('/account/login.php');
    $h->api('POST', '/account/api/auth.php', ['action' => 'login', 'email' => $email, 'password' => $rmPw] + ($remember ? ['remember' => '1'] : []));
    return $h;
};
// The app was closed (session cookie gone): is the person still logged in on the next page?
$stillIn = static function (TestHttp $h): bool {
    $h->dropCookie(GX_SESSION_NAME);
    [$st] = $h->headers('/account/');
    return $st === 200;
};
$rmCid = $mkRemember('remember@test.local');
$rm = $loginAs('remember@test.local', true);
$rmSet = implode(' | ', array_filter($rm->lastHeaders['set-cookie'] ?? [], static fn($c) => str_starts_with($c, 'GXREMEMBER=')));
check('"Keep me logged in" sets a 60-day HttpOnly, SameSite=Lax cookie', preg_match('/^GXREMEMBER=[a-f0-9]{24}\.[a-f0-9]{64};/', $rmSet) === 1
    && stripos($rmSet, 'httponly') !== false && stripos($rmSet, 'samesite=lax') !== false
    && preg_match('/Max-Age=(\d+)/i', $rmSet, $mm) === 1 && (int) $mm[1] >= 59 * 86400, $rmSet);
$rmCookie = (string) $rm->cookie('GXREMEMBER');
[$rmSel, $rmSecret] = array_pad(explode('.', $rmCookie), 2, '');
$rmRow = db_one('SELECT * FROM customer_remember_tokens WHERE selector = ?', [$rmSel]);
check('… only a hash of its secret is stored, bound to the login version', $rmRow && remember_secret_matches($rmSecret, $rmRow['validator_hash'])
    && !str_contains(json_encode($rmRow), $rmSecret) && (int) $rmRow['customer_id'] === $rmCid && (int) $rmRow['session_version'] === 1);
check('the app was closed (no session cookie): the cookie logs them in again', $stillIn($rm));
$rmCookie2 = (string) $rm->cookie('GXREMEMBER');
check('… with a new secret on the same token', $rmCookie2 !== $rmCookie && str_starts_with($rmCookie2, $rmSel . '.')
    && (int) db_val('SELECT COUNT(*) FROM customer_remember_tokens WHERE customer_id = ?', [$rmCid]) === 1);
$late = new TestHttp($base);
$late->setCookie('GXREMEMBER', $rmCookie);
[$st] = $late->headers('/account/');
check('a request sent with the previous value a moment earlier still gets in (2-minute grace)', $st === 200);
db_exec('UPDATE customer_remember_tokens SET rotated_at = NOW() - INTERVAL 5 MINUTE WHERE selector = ?', [$rmSel]);
$thief = new TestHttp($base);
$thief->setCookie('GXREMEMBER', $rmCookie);
[$st] = $thief->headers('/account/');
check('the replaced value later on (a copied cookie) is refused and the token destroyed', $st === 302
    && !db_val('SELECT 1 FROM customer_remember_tokens WHERE selector = ?', [$rmSel]) && $thief->cookie('GXREMEMBER') === null);
check('… so the real phone has to log in again too (and its cookie is cleared)', !$stillIn($rm) && $rm->cookie('GXREMEMBER') === null);
check('… and every login of the account ends (also a session the copy already opened)', (int) db_val('SELECT session_version FROM customers WHERE id = ?', [$rmCid]) === 2);
db_exec('UPDATE customers SET session_version = 1 WHERE id = ?', [$rmCid]);

// The owner logs out while a copy of their cookie has already been used elsewhere (their secret is stale)
$own = $loginAs('remember@test.local', true);
$copy = new TestHttp($base);
$copy->setCookie('GXREMEMBER', (string) $own->cookie('GXREMEMBER'));
[$st] = $copy->headers('/account/'); // the copy logs in (new secret); the owner's value is now the old one
db_exec('UPDATE customer_remember_tokens SET rotated_at = NOW() - INTERVAL 5 MINUTE WHERE customer_id = ?', [$rmCid]);
[$st2] = $own->api('POST', '/account/api/auth.php', ['action' => 'logout']);
[$st3] = $copy->headers('/account/'); // the copy's session, still holding its session cookie
check('logging out with a stale cookie (a copy was used) deletes the token and ends the copy\'s open session', $st === 200 && $st2 === 200
    && $st3 === 302 && !db_val('SELECT 1 FROM customer_remember_tokens WHERE customer_id = ?', [$rmCid]), "$st $st2 $st3");
db_exec('UPDATE customers SET session_version = 1 WHERE id = ?', [$rmCid]);

// A page left open for a day: its CSRF token is stale, the remember cookie logs them back in — "Log out" still works
$idle = $loginAs('remember@test.local', true);
$idle->dropCookie(GX_SESSION_NAME);
$idle->csrf = 'stale-token-from-yesterday';
[$st, $res] = $idle->api('POST', '/account/api/auth.php', ['action' => 'logout']);
check('"Log out" on a page left open overnight logs out (no CSRF error, remembered login gone)', $st === 200 && $idle->cookie('GXREMEMBER') === null
    && !$stillIn($idle), $st . ' ' . json_encode($res));

$nr = $loginAs('remember@test.local', false);
check('without the tick box: no remember cookie, and closing the app logs out', $nr->cookie('GXREMEMBER') === null && !$stillIn($nr));
$webLogin = (new TestHttp($base))->page('/account/login.php');
$appH = new TestHttp($base);
$appH->extra = [$appUa];
$appLogin = $appH->page('/account/login.php');
check('the tick box is off on the website and on in the app', str_contains($webLogin, 'name="remember" value="1">') && str_contains($appLogin, 'name="remember" value="1" checked'));
check('… on the registration form too', str_contains($appH->page('/account/register.php'), 'name="remember" value="1" checked'));
check('the app gets html.in-app and the bridge script; the website does not', str_contains($appLogin, 'class="no-js in-app"')
    && str_contains($appLogin, '/assets/js/app-bridge.js') && !str_contains($webLogin, 'app-bridge.js') && !str_contains($webLogin, 'in-app'));

$lo = $loginAs('remember@test.local', true);
$loCopy = (string) $lo->cookie('GXREMEMBER');
[$st] = $lo->api('POST', '/account/api/auth.php', ['action' => 'logout']);
$loOther = new TestHttp($base);
$loOther->setCookie('GXREMEMBER', $loCopy);
[$st2] = $loOther->headers('/account/');
check('logging out ends the remembered login (cookie removed, token deleted)', $st === 200 && $lo->cookie('GXREMEMBER') === null && $st2 === 302
    && !db_val('SELECT 1 FROM customer_remember_tokens WHERE selector = ?', [explode('.', $loCopy)[0]]));

$pwA = $loginAs('remember@test.local', true);
$pwB = $loginAs('remember@test.local', true);
$pwA->page('/account/profile.php');
[$st, $res] = $pwA->api('POST', '/account/api/profile.php', ['action' => 'password', 'current_password' => $rmPw, 'password' => $rmPw . 'x', 'password_confirm' => $rmPw . 'x']);
check('changing the password: other phones\' remembered logins stop', $st === 200 && !$stillIn($pwB), json_encode($res));
check('… while this phone stays remembered', $stillIn($pwA));
db_exec('UPDATE customers SET password_hash = ? WHERE id = ?', [customer_password_hash($rmPw), $rmCid]);

$leA = $loginAs('remember@test.local', true);
$leB = $loginAs('remember@test.local', true);
$leA->page('/account/profile.php');
[$st] = $leA->api('POST', '/account/api/profile.php', ['action' => 'logout_others']);
check('"Log out everywhere else" ends the other phones\' remembered logins, not this one', $st === 200 && !$stillIn($leB) && $stillIn($leA));

$exp = $loginAs('remember@test.local', true);
db_exec('UPDATE customer_remember_tokens SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE selector = ?', [explode('.', (string) $exp->cookie('GXREMEMBER'))[0]]);
check('an expired token does not log in', !$stillIn($exp));

$adm6Pw = 'Push-Owner-123!';
$adm6Id = db_insert("INSERT INTO admin_users (role_id, email, password_hash, name, division_scope) VALUES ((SELECT id FROM roles WHERE slug = 'owner'), ?, ?, 'Push Owner', 'all')",
    ['pushowner@test.local', admin_password_hash($adm6Pw)]);
$adm6 = new TestHttp($base);
$adm6->extra = [$appUa, 'X-Test-Remote-Addr: 10.46.1.1'];
$adm6->page('/admin/login.php');
[$st] = $adm6->api('POST', '/admin/api/auth.php', ['action' => 'login', 'email' => 'pushowner@test.local', 'password' => $adm6Pw]);
check('(a staff member logs in to the admin in the app)', $st === 200);
$blk = $loginAs('remember@test.local', true);
[$st] = $adm6->api('POST', '/admin/api/customers.php', ['action' => 'block', 'id' => $rmCid]);
check('blocking the customer ends every remembered login', $st === 200 && !$stillIn($blk) && !db_val('SELECT 1 FROM customer_remember_tokens WHERE customer_id = ?', [$rmCid]));
$adm6->api('POST', '/admin/api/customers.php', ['action' => 'unblock', 'id' => $rmCid]);

section('Phase 6: push notifications');
$fbFile = GX_ROOT . '/storage/private/firebase-service-account.json';
$fbTokenFile = GX_ROOT . '/storage/private/fcm-access-token.json';
$fbBackup = is_file($fbFile) ? (string) file_get_contents($fbFile) : null;
// Put a real service-account file back even if the run stops half-way
register_shutdown_function(static function () use ($fbFile, $fbTokenFile, $fbBackup): void {
    @unlink($fbFile);
    @unlink($fbTokenFile);
    if ($fbBackup !== null) {
        file_put_contents($fbFile, $fbBackup);
        chmod($fbFile, 0600);
    }
});
@unlink($fbFile);
@unlink($fbTokenFile);
PushService::useCredentials(null);
$pushCid = $mkRemember('push6@test.local');
$offCid = $mkRemember('pushoff6@test.local');
$offDev = PushService::register('dOff6:APA91b' . str_repeat('Of7', 40), 'customer', $offCid, 1);
check('without the Firebase file push is off: a linked phone gets nothing queued', !PushService::configured() && $offDev > 0
    && PushService::toCustomer($offCid, 'payment_received', ['order_no' => 'X', 'amount' => 'R 1']) === 0
    && !db_val('SELECT 1 FROM push_queue WHERE device_id = ?', [$offDev]));
$fbKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($fbKey, $fbPem);
$fbPub = openssl_pkey_get_details($fbKey)['key'];
file_put_contents($fbFile, json_encode(['type' => 'service_account', 'project_id' => 'grazex-test', 'client_email' => 'push@grazex-test.iam.gserviceaccount.com',
    'private_key' => $fbPem, 'token_uri' => 'https://oauth2.googleapis.com/token']));
chmod($fbFile, 0600);
PushService::useCredentials(null);
check('with the service-account file in storage/private push is on', PushService::configured());
$fcmCalls = [];
$fcmGone = [];
$fcmReplies = [];
PushService::useTransport(static function (string $url, array $headers, string $body) use (&$fcmCalls, &$fcmGone, &$fcmReplies): array {
    $fcmCalls[] = ['url' => $url, 'headers' => $headers, 'body' => $body];
    if (str_contains($url, 'oauth2.googleapis.com')) {
        return [200, json_encode(['access_token' => 'ya29.test-' . count($fcmCalls), 'expires_in' => 3599, 'token_type' => 'Bearer'])];
    }
    if (in_array(json_decode($body, true)['message']['token'] ?? '', $fcmGone, true)) {
        return [404, '{"error":{"code":404,"message":"Requested entity was not found.","status":"NOT_FOUND","details":[{"@type":"type.googleapis.com/google.firebase.fcm.v1.FcmError","errorCode":"UNREGISTERED"}]}}'];
    }
    return array_shift($fcmReplies) ?? [200, '{"name":"projects/grazex-test/messages/0:1"}'];
});

$ph = $loginAs('push6@test.local', true, [$appUa]);
$phHome = $ph->page('/account/');
check('in the app, a logged-in page asks the bridge to link this phone', str_contains($phHome, 'data-push="customer" data-push-linked=""'));
$pTok = 'dTest6:APA91b' . str_repeat('Xy9_-', 30);
[$st, $res] = $ph->api('POST', '/account/api/push.php', ['action' => 'register', 'token' => $pTok, 'app_version' => '1.0']);
$pDev = db_one('SELECT * FROM push_devices WHERE token_hash = ?', [hash('sha256', $pTok)]);
check('the app links its push token to the customer and this login', $st === 200 && $pDev && (int) $pDev['customer_id'] === $pushCid
    && (int) $pDev['customer_sv'] === 1 && $pDev['app_version'] === '1.0' && $pDev['admin_user_id'] === null, json_encode($res));
check('… after which pages say this login is linked', str_contains($ph->page('/account/'), 'data-push="customer" data-push-linked="customer"'));
$noRem = $loginAs('push6@test.local', false, [$appUa]);
$noRem->page('/account/');
[$st, $res] = $noRem->api('POST', '/account/api/push.php', ['action' => 'register', 'token' => 'dNoRem6:APA91b' . str_repeat('Nr1', 40)]);
check('a login without "Keep me logged in" does not link the phone (no news on a borrowed phone later)', $st === 200 && ($res['data']['push'] ?? null) === false
    && !db_val('SELECT 1 FROM push_devices WHERE token_hash = ?', [hash('sha256', 'dNoRem6:APA91b' . str_repeat('Nr1', 40))])
    && str_contains($noRem->page('/account/'), 'data-push-linked="customer"'), json_encode($res));
[$st] = $ph->api('POST', '/account/api/push.php', ['action' => 'register', 'token' => 'not a token!']);
check('a junk token is refused', $st === 422);
$keep = $ph->csrf;
$ph->csrf = 'bad';
[$st] = $ph->api('POST', '/account/api/push.php', ['action' => 'register', 'token' => $pTok]);
$ph->csrf = $keep;
$nobody = new TestHttp($base);
$nobody->page('/');
[$st2] = $nobody->api('POST', '/account/api/push.php', ['action' => 'register', 'token' => $pTok]);
check('registering needs the CSRF token and a login', $st === 419 && $st2 === 401);
check('the website (not the app) never asks to link a phone, also when logged in', !str_contains($loginAs('push6@test.local', true)->page('/account/'), 'data-push='));
// The phone was forgotten (e.g. app uninstalled) while the session lives on: the next page links it again
$fgCid = $mkRemember('pushforget@test.local');
$fg = $loginAs('pushforget@test.local', true, [$appUa]);
$fg->page('/account/');
$fg->api('POST', '/account/api/push.php', ['action' => 'register', 'token' => 'dForget6:APA91b' . str_repeat('Fg5', 40)]);
db_exec('DELETE FROM push_devices WHERE customer_id = ?', [$fgCid]);
check('a phone that was forgotten is linked again on the next page (same session)', str_contains($fg->page('/account/'), 'data-push="customer" data-push-linked=""'));
// Shop pages in the app don't touch the admin session (its idle timeout keeps running)
$admShop = $adm6->page('/feeds/');
check('in the app, shop pages leave the admin session alone (no staff link there)', !str_contains($admShop, 'data-push="admin'));

$fcmCalls = [];
$n = PushService::toCustomer($pushCid, 'payment_received', ['order_no' => 'GXF-PUSH1', 'amount' => 'R 150.00'], '/account/order.php?no=GXF-PUSH1', 'order-GXF-PUSH1');
$pq = db_one('SELECT * FROM push_queue WHERE device_id = ? ORDER BY id DESC LIMIT 1', [$pDev['id']]);
check('order news is queued for the phone and sent straight away', $n === 1 && $pq['status'] === 'sent' && $pq['title'] === 'Payment received — GXF-PUSH1'
    && $pq['body'] === 'Thank you! We received R 150.00.', json_encode($pq));
parse_str((string) ($fcmCalls[0]['body'] ?? ''), $jwtForm);
[$jh, $jc, $js] = array_pad(explode('.', (string) ($jwtForm['assertion'] ?? '')), 3, '');
$b64d = static fn(string $s): string => (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
$jClaims = json_decode($b64d($jc), true) ?: [];
check('the access token comes from a JWT signed with the service-account key (RS256)', ($jwtForm['grant_type'] ?? '') === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
    && openssl_verify($jh . '.' . $jc, $b64d($js), $fbPub, OPENSSL_ALGO_SHA256) === 1 && (json_decode($b64d($jh), true)['alg'] ?? '') === 'RS256'
    && ($jClaims['iss'] ?? '') === 'push@grazex-test.iam.gserviceaccount.com' && ($jClaims['scope'] ?? '') === 'https://www.googleapis.com/auth/firebase.messaging'
    && ($jClaims['aud'] ?? '') === 'https://oauth2.googleapis.com/token' && ($jClaims['exp'] ?? 0) - ($jClaims['iat'] ?? 0) === 3600);
$fcmMsg = json_decode((string) ($fcmCalls[1]['body'] ?? '{}'), true)['message'] ?? [];
check('the FCM v1 message: data-only, to this token, opens the order', str_ends_with($fcmCalls[1]['url'] ?? '', '/v1/projects/grazex-test/messages:send')
    && in_array('Authorization: Bearer ya29.test-1', $fcmCalls[1]['headers'] ?? [], true) && ($fcmMsg['token'] ?? '') === $pTok && !isset($fcmMsg['notification'])
    && ($fcmMsg['data']['url'] ?? '') === '/account/order.php?no=GXF-PUSH1' && ($fcmMsg['data']['channel'] ?? '') === 'orders'
    && ($fcmMsg['android']['collapse_key'] ?? '') === 'order-GXF-PUSH1' && array_filter($fcmMsg['data'] ?? [], 'is_string') === ($fcmMsg['data'] ?? []), json_encode($fcmMsg));
$fcmCalls = [];
PushService::toCustomer($pushCid, 'order_ready', ['order_no' => 'GXF-PUSH1']);
check('the Google access token is cached (no second token request) in a private file', count($fcmCalls) === 1 && !str_contains($fcmCalls[0]['url'], 'oauth2')
    && (fileperms($fbTokenFile) & 0777) === 0600 && !str_contains((string) file_get_contents($fbTokenFile), 'PRIVATE KEY'));

$fcmReplies = [[503, '{"error":{"code":503,"message":"The service is currently unavailable.","status":"UNAVAILABLE"}}']];
PushService::toCustomer($pushCid, 'order_out_for_delivery', ['order_no' => 'GXF-PUSH1']);
$pq = db_one('SELECT * FROM push_queue WHERE device_id = ? ORDER BY id DESC LIMIT 1', [$pDev['id']]);
check('Google busy (503): the push waits and is tried again later', $pq['status'] === 'queued' && (int) $pq['attempts'] === 1 && strtotime($pq['send_after']) > time(), json_encode($pq));
db_exec('UPDATE push_queue SET attempts = 4, send_after = NOW() WHERE id = ?', [$pq['id']]);
$fcmReplies = [[503, '{"error":{"code":503,"status":"UNAVAILABLE"}}']];
PushService::sendQueued();
check('… and gives up after 5 tries', db_val('SELECT status FROM push_queue WHERE id = ?', [$pq['id']]) === 'failed');
$fcmCalls = [];
$fcmReplies = [[401, '{"error":{"code":401,"message":"Request had invalid authentication credentials.","status":"UNAUTHENTICATED"}}']];
PushService::toCustomer($pushCid, 'order_completed', ['order_no' => 'GXF-PUSH1']);
check('a refused access token (401) is renewed and the push sent once more', count($fcmCalls) === 3 && str_contains($fcmCalls[1]['url'], 'oauth2')
    && db_val('SELECT status FROM push_queue WHERE device_id = ? ORDER BY id DESC LIMIT 1', [$pDev['id']]) === 'sent');
// A push still waiting when the login ends (password change on another device) is not sent
$fcmReplies = [[503, '{"error":{"code":503,"status":"UNAVAILABLE"}}']];
PushService::toCustomer($pushCid, 'order_ready', ['order_no' => 'GXF-LATE']);
$lateQ = (int) db_val("SELECT id FROM push_queue WHERE title LIKE '%GXF-LATE%'");
db_exec('UPDATE customers SET session_version = session_version + 1 WHERE id = ?', [$pushCid]);
db_exec('UPDATE push_queue SET send_after = NOW() WHERE id = ?', [$lateQ]);
$fcmCalls = [];
PushService::sendQueued();
check('a push queued before the login ended is not sent afterwards', db_val('SELECT status FROM push_queue WHERE id = ?', [$lateQ]) === 'failed' && !$fcmCalls);
db_exec('UPDATE customers SET session_version = session_version - 1 WHERE id = ?', [$pushCid]);
// Google unreachable: one try, then the run stops (a page or cron never waits on timeout after timeout)
$busyCid = $mkRemember('pushbusy@test.local');
foreach ([1, 2, 3] as $bi) {
    PushService::register('dBusy' . $bi . ':APA91b' . str_repeat('Bu4', 40), 'customer', $busyCid, 1);
}
$fcmReplies = [[0, 'Connection timed out'], [0, 'Connection timed out'], [0, 'Connection timed out']];
$fcmCalls = [];
PushService::toCustomer($busyCid, 'order_ready', ['order_no' => 'GXF-BUSY']);
check('Google unreachable: the send run stops after the first failure; the rest waits in the queue', count(array_filter($fcmCalls, static fn($c) => str_contains($c['url'], 'messages:send'))) === 1
    && (int) db_val("SELECT COUNT(*) FROM push_queue WHERE title LIKE '%GXF-BUSY%' AND status = 'queued'") === 3);
$fcmReplies = [];
db_exec("DELETE FROM push_queue WHERE title LIKE '%GXF-BUSY%'");
// The server's Firebase project is not the app's: a set-up mistake — no phone is forgotten
$fcmReplies = [[403, '{"error":{"code":403,"message":"SenderId mismatch","status":"PERMISSION_DENIED","details":[{"@type":"type.googleapis.com/google.firebase.fcm.v1.FcmError","errorCode":"SENDER_ID_MISMATCH"}]}}']];
PushService::toCustomer($pushCid, 'order_ready', ['order_no' => 'GXF-SENDER']);
check('a Firebase project mix-up (SENDER_ID_MISMATCH) keeps the phones and marks the push failed', (bool) db_val('SELECT 1 FROM push_devices WHERE id = ?', [$pDev['id']])
    && db_val("SELECT status FROM push_queue WHERE title LIKE '%GXF-SENDER%'") === 'failed');
$fcmReplies = [];
$goneTok = 'dGone6:APA91b' . str_repeat('Zz8', 40);
$goneId = PushService::register($goneTok, 'customer', $pushCid, 1, '0.9');
$fcmGone = [$goneTok];
PushService::toCustomer($pushCid, 'order_ready', ['order_no' => 'GXF-PUSH2']);
check('an uninstalled app (UNREGISTERED) is forgotten; the other phone still gets it', !db_val('SELECT 1 FROM push_devices WHERE id = ?', [$goneId])
    && db_val('SELECT status FROM push_queue WHERE device_id = ? ORDER BY id DESC LIMIT 1', [$pDev['id']]) === 'sent');
check('an email template without push text sends no push', PushService::toCustomer($pushCid, 'verify_email', ['link' => 'x']) === 0);
// A shared phone changes hands: the previous person's notifications are not kept for the next one
$shareTok = 'dShare6:APA91b' . str_repeat('Sh9', 40);
$shareA = $mkRemember('sharea@test.local');
$shareB = $mkRemember('shareb@test.local');
$shareId = PushService::register($shareTok, 'customer', $shareA, 1);
PushService::toCustomer($shareA, 'payment_received', ['order_no' => 'GXF-SHARE', 'amount' => 'R 9 999.00']);
$shareId2 = PushService::register($shareTok, 'customer', $shareB, 1);
check('a phone that changes hands keeps nothing of the previous customer\'s notifications', $shareId === $shareId2
    && !db_val("SELECT 1 FROM push_queue WHERE device_id = ? AND title LIKE '%GXF-SHARE%'", [$shareId])
    && (int) db_val('SELECT customer_id FROM push_devices WHERE id = ?', [$shareId]) === $shareB);
$capTok = 'dCap6:APA91b' . str_repeat('Qq7', 40);
$capCid = $mkRemember('pushcap@test.local');
$capId = PushService::register($capTok, 'customer', $capCid, 1);
for ($i = 0; $i < PushService::MAX_PER_DEVICE_HOUR; $i++) {
    db_exec("INSERT INTO push_queue (device_id, title, body, status, sent_at) VALUES (?, 't', 'b', 'sent', NOW())", [$capId]);
}
check('at most 20 pushes per phone per hour', PushService::toCustomer($capCid, 'order_ready', ['order_no' => 'GXF-CAP']) === 0);
db_exec("UPDATE push_queue SET status = 'sending', claimed_at = NOW() - INTERVAL 20 MINUTE WHERE device_id = ? ORDER BY id LIMIT 1", [$capId]);
check('cron puts a push stuck in "sending" back in the queue', PushService::requeueStuck() === 1);

// Order emails carry the push: a real POP rejection
$popO = db_one("SELECT o.id, o.order_no, o.customer_id FROM orders o JOIN customers c ON c.id = o.customer_id WHERE c.status = 'active' ORDER BY o.id LIMIT 1");
if ($popO) {
    PushService::register('dPop6:APA91b' . str_repeat('Pp6', 40), 'customer', (int) $popO['customer_id'],
        (int) db_val('SELECT session_version FROM customers WHERE id = ?', [$popO['customer_id']]));
    NotificationService::email('pop_rejected', 'x@test.local', 'X', ['first_name' => 'X', 'order_no' => $popO['order_no'], 'reason' => 'r'], null, [], null, false, (int) $popO['customer_id']);
    check('an order email also goes to the customer\'s phone (POP rejected opens the order)', (bool) db_val("SELECT 1 FROM push_queue q JOIN push_devices d ON d.id = q.device_id
        WHERE d.customer_id = ? AND q.title = 'Proof of payment not accepted' AND q.url = ?", [$popO['customer_id'], '/account/order.php?no=' . rawurlencode($popO['order_no'])]));
} else {
    check('an order email also goes to the customer\'s phone', false, 'no order in the test data');
}

// Livestock alerts: a customer with a confirmed alert gets one push per announcement
$lotP = db_one("SELECT id, slug, title FROM lots WHERE status IN ('available','coming_soon') ORDER BY id LIMIT 1");
$alC = $mkRemember('pushalert@test.local');
PushService::register('dAlert6:APA91b' . str_repeat('Aa5', 40), 'customer', $alC, 1);
db_exec('INSERT INTO livestock_alerts (customer_id, email, is_active, confirmed_at, unsubscribe_token) VALUES (?, ?, 1, NOW(), ?)', [$alC, 'pushalert@test.local', random_token(32)]);
LivestockService::notifySubscribers((int) $lotP['id']);
$alQ = db_one('SELECT q.* FROM push_queue q JOIN push_devices d ON d.id = q.device_id WHERE d.customer_id = ? ORDER BY q.id DESC LIMIT 1', [$alC]);
check('new livestock: a push on the "livestock" channel that opens the lot', $alQ && $alQ['channel'] === 'livestock' && $alQ['url'] === lot_url($lotP['slug'])
    && str_contains($alQ['body'], $lotP['title']), json_encode($alQ));

// Staff: per permission and division, only active staff on their current login
db_exec("INSERT INTO roles (slug, name) VALUES ('pushtest', 'Push test')");
$ptRole = (int) db_val("SELECT id FROM roles WHERE slug = 'pushtest'");
db_exec("INSERT INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE slug = 'orders.view'", [$ptRole]);
$stF = db_insert("INSERT INTO admin_users (role_id, email, password_hash, name, division_scope) VALUES (?, 'pushfeeds@test.local', 'x', 'Feeds staff', 'feeds')", [$ptRole]);
$stR = db_insert("INSERT INTO admin_users (role_id, email, password_hash, name, division_scope) VALUES ((SELECT id FROM roles WHERE slug = 'owner'), 'pushranch@test.local', 'x', 'Ranch owner', 'ranch')");
$stX = db_insert("INSERT INTO admin_users (role_id, email, password_hash, name, division_scope, status) VALUES (?, 'pushoff@test.local', 'x', 'Disabled', 'all', 'disabled')", [$ptRole]);
$stDev = [];
foreach (['F' => $stF, 'R' => $stR, 'X' => $stX] as $k => $sid) {
    $stDev[$k] = PushService::register('dStaff' . $k . ':APA91b' . str_repeat('Ss4', 40), 'admin', $sid, 1);
}
$staffGot = static fn(int $devId, string $title): bool => (bool) db_val("SELECT 1 FROM push_queue WHERE device_id = ? AND channel = 'staff' AND title = ?", [$devId, $title]);
NotificationService::admin('new_order', 'New GrazeX Feeds order GXF-STAFF1', 'R 100', '/admin/order.php?id=1', division_id('feeds'));
NotificationService::admin('new_order', 'New GrazeX Ranch order GXR-STAFF2', 'R 100', '/admin/order.php?id=2', division_id('ranch'));
NotificationService::admin('refund_due', 'Refund due on GXF-STAFF3', 'R 5', '/admin/order.php?id=3', division_id('feeds'));
NotificationService::admin('low_stock', 'Low stock: Test', 'x', '/admin/inventory.php', division_id('feeds'));
check('staff pushes follow permission and division', $staffGot($stDev['F'], 'New GrazeX Feeds order GXF-STAFF1') && !$staffGot($stDev['R'], 'New GrazeX Feeds order GXF-STAFF1')
    && $staffGot($stDev['R'], 'New GrazeX Ranch order GXR-STAFF2') && !$staffGot($stDev['F'], 'New GrazeX Ranch order GXR-STAFF2')
    && !$staffGot($stDev['F'], 'Refund due on GXF-STAFF3'));
check('… never to a disabled staff member, and not for low-stock notices', !db_val('SELECT 1 FROM push_queue WHERE device_id = ?', [$stDev['X']])
    && !db_val("SELECT 1 FROM push_queue WHERE title = 'Low stock: Test'"));
db_exec("UPDATE role_permissions rp JOIN permissions p ON p.id = rp.permission_id SET rp.permission_id = (SELECT id FROM permissions WHERE slug = 'messages.manage') WHERE rp.role_id = ? AND p.slug = 'orders.view'", [$ptRole]);
$stMax = (int) db_val('SELECT COALESCE(MAX(id), 0) FROM push_queue');
NotificationService::admin('enquiry', 'New message from Sannie Secret', 'sannie@example.com: please call me on 082 555 0000', '/admin/messages.php?id=9', division_id('feeds'));
NotificationService::admin('new_order', 'New GrazeX Ranch order GXR-STAFF5', 'Piet Private, 3 items', '/admin/order.php?id=5', division_id('ranch'));
$stQ = db_all('SELECT device_id, title, body FROM push_queue WHERE id > ? ORDER BY id', [$stMax]);
check('staff pushes carry no names, emails or messages (only the alert title, generic for messages)', count($stQ) === 2
    && (int) $stQ[0]['device_id'] === $stDev['F'] && $stQ[0]['title'] === 'New customer message'
    && (int) $stQ[1]['device_id'] === $stDev['R'] && $stQ[1]['title'] === 'New GrazeX Ranch order GXR-STAFF5'
    && $stQ[0]['body'] === 'Tap to open it in the GrazeX admin.' && $stQ[1]['body'] === 'Tap to open it in the GrazeX admin.', json_encode($stQ));
db_exec("UPDATE role_permissions rp JOIN permissions p ON p.id = rp.permission_id SET rp.permission_id = (SELECT id FROM permissions WHERE slug = 'orders.view') WHERE rp.role_id = ? AND p.slug = 'messages.manage'", [$ptRole]);
db_exec('UPDATE admin_users SET session_version = session_version + 1 WHERE id = ?', [$stF]);
NotificationService::admin('new_order', 'New GrazeX Feeds order GXF-STAFF4', 'R 1', '/admin/order.php?id=4', division_id('feeds'));
check('… and stop after that staff member\'s password changes (until the app logs in again)', !$staffGot($stDev['F'], 'New GrazeX Feeds order GXF-STAFF4'));

$admHome = $adm6->page('/admin/');
check('in the app the admin also asks to link the phone for staff notifications', str_contains($admHome, 'data-push="admin" data-push-linked=""'));
$aTok = 'dAdmin6:APA91b' . str_repeat('Ad3', 40);
[$st] = $adm6->api('POST', '/admin/api/push.php', ['action' => 'register', 'token' => $aTok, 'app_version' => '1.0']);
$aDev = db_one('SELECT * FROM push_devices WHERE token_hash = ?', [hash('sha256', $aTok)]);
check('a staff member links the phone through the admin API', $st === 200 && $aDev && (int) $aDev['admin_user_id'] === $adm6Id && (int) $aDev['admin_sv'] === 1);
[$st, $res] = $adm6->api('POST', '/admin/api/push.php', ['action' => 'test']);
check('"Send a test notification" reaches the staff member\'s own phone', $st === 200 && (int) ($res['data']['queued'] ?? 0) === 1
    && db_val("SELECT channel FROM push_queue WHERE device_id = ? AND title = 'GrazeX test notification'", [$aDev['id']]) === 'staff', json_encode($res)); // (the test web server never sends)
check('Admin → Settings shows the push status', str_contains($adm6->page('/admin/settings.php?tab=system'), 'App push notifications:'));
[$st] = $adm6->api('POST', '/admin/api/auth.php', ['action' => 'logout']);
check('an admin logout unlinks the phone from staff notifications', $st === 200 && !db_val('SELECT 1 FROM push_devices WHERE id = ?', [$aDev['id']]));

[$st, $res] = $ph->api('POST', '/account/api/privacy.php', ['action' => 'export']);
$exp6 = $res['data']['download'] ?? [];
check('the data download lists the phones and remembered logins, without any token', $st === 200 && count($exp6['app_devices'] ?? []) === 1
    && count($exp6['remembered_logins'] ?? []) === (int) db_val('SELECT COUNT(*) FROM customer_remember_tokens WHERE customer_id = ?', [$pushCid])
    && count($exp6['remembered_logins'] ?? []) >= 1 && !empty($exp6['app_notifications']) && !str_contains(json_encode($res), $pTok)
    && !str_contains(json_encode($res), (string) $ph->cookie('GXREMEMBER')), json_encode(array_keys($exp6)));
db_exec('UPDATE customers SET session_version = session_version + 1 WHERE id = ?', [$pushCid]);
check('after "log out everywhere" the phone gets nothing until the app logs in again', PushService::toCustomer($pushCid, 'order_ready', ['order_no' => 'GXF-PUSH3']) === 0);
db_exec('UPDATE customers SET session_version = session_version - 1 WHERE id = ?', [$pushCid]);
db_exec("INSERT INTO push_queue (device_id, title, body) VALUES (?, 'waiting', 'x')", [$pDev['id']]);
[$st] = $ph->api('POST', '/account/api/auth.php', ['action' => 'logout']);
check('logging out unlinks the phone and drops pushes still waiting for it', $st === 200 && !db_val('SELECT 1 FROM push_devices WHERE id = ?', [$pDev['id']])
    && !db_val("SELECT 1 FROM push_queue WHERE title = 'waiting'"));
// A phone used by a customer and a staff member: the customer's logout drops only the customer's pushes
$shTok = 'dBoth6:APA91b' . str_repeat('Bo3', 40);
$bothC = $mkRemember('pushboth@test.local');
$bothId = PushService::register($shTok, 'customer', $bothC, 1);
PushService::register($shTok, 'admin', $adm6Id, 1);
db_exec("INSERT INTO push_queue (device_id, channel, title, body, status) VALUES (?, 'orders', 'cust-row', 'x', 'sent'), (?, 'staff', 'staff-row', 'x', 'sent')", [$bothId, $bothId]);
PushService::detach($bothId, 'customer');
check('… on a phone also used by staff, only the customer\'s notifications go; the staff link stays', (bool) db_val('SELECT 1 FROM push_devices WHERE id = ? AND admin_user_id = ? AND customer_id IS NULL', [$bothId, $adm6Id])
    && !db_val("SELECT 1 FROM push_queue WHERE title = 'cust-row'") && (bool) db_val("SELECT 1 FROM push_queue WHERE title = 'staff-row'"));
// A password change in the app keeps this phone linked (with the new login version)
$pwPCid = $mkRemember('pushpw@test.local');
$pwP = $loginAs('pushpw@test.local', true, [$appUa]);
$pwP->page('/account/');
$pwP->api('POST', '/account/api/push.php', ['action' => 'register', 'token' => 'dPw6:APA91b' . str_repeat('Pw2', 40)]);
$pwP->page('/account/profile.php');
[$st] = $pwP->api('POST', '/account/api/profile.php', ['action' => 'password', 'current_password' => $rmPw, 'password' => $rmPw . 'y', 'password_confirm' => $rmPw . 'y']);
check('after changing the password in the app, this phone keeps getting notifications', $st === 200
    && (int) db_val('SELECT customer_sv FROM push_devices WHERE customer_id = ?', [$pwPCid]) === (int) db_val('SELECT session_version FROM customers WHERE id = ?', [$pwPCid])
    && PushService::toCustomer($pwPCid, 'order_ready', ['order_no' => 'GXF-PW']) === 1 && str_contains($pwP->page('/account/'), 'data-push-linked="customer"'));

// Anonymise: remembered logins and phones go
$anC = $mkRemember('pushanon@test.local');
$an = $loginAs('pushanon@test.local', true, [$appUa]);
[$st0] = $an->api('POST', '/account/api/push.php', ['action' => 'register', 'token' => 'dAnon6:APA91b' . str_repeat('An2', 40)]);
check('(the customer to anonymise has a remembered login and a linked phone)', $st0 === 200 && (bool) db_val('SELECT 1 FROM push_devices WHERE customer_id = ?', [$anC])
    && (bool) db_val('SELECT 1 FROM customer_remember_tokens WHERE customer_id = ?', [$anC]));
$adm6->page('/admin/login.php');
$adm6->api('POST', '/admin/api/auth.php', ['action' => 'login', 'email' => 'pushowner@test.local', 'password' => $adm6Pw]);
[$st, $res] = $adm6->api('POST', '/admin/api/customers.php', ['action' => 'anonymise', 'id' => $anC]);
check('anonymising removes remembered logins and unlinks the phones', $st === 200 && !db_val('SELECT 1 FROM customer_remember_tokens WHERE customer_id = ?', [$anC])
    && !db_val('SELECT 1 FROM push_devices WHERE customer_id = ?', [$anC]) && !$stillIn($an), json_encode($res));

// Cron housekeeping
db_exec('UPDATE push_devices SET last_seen_at = NOW() - INTERVAL 61 DAY WHERE id = ?', [$capId]);
db_exec('UPDATE customer_remember_tokens SET expires_at = NOW() - INTERVAL 1 DAY WHERE customer_id = ?', [$rmCid]);
$cronOut = CronService::cleanup();
check('cron removes expired remembered logins and reports push housekeeping', !db_val('SELECT 1 FROM customer_remember_tokens WHERE customer_id = ? AND expires_at < NOW()', [$rmCid])
    && str_contains($cronOut, 'remembered logins') && str_contains($cronOut, 'push queue'), $cronOut);
check('… and forgets phones whose app was not opened for 60 days', !db_val('SELECT 1 FROM push_devices WHERE id = ?', [$capId]));

section('Phase 6: downloads in the app, pages in a dialog, App Links');
$dlDoc = db_one("SELECT d.id, c.email FROM documents d JOIN customers c ON c.id = d.customer_id
                 WHERE d.doc_type = 'receipt' AND d.is_void = 0 AND c.status = 'active' AND c.email LIKE '%@test.local' ORDER BY d.id LIMIT 1");
$dl = new TestHttp($base);
$dl->extra = ['X-Test-Remote-Addr: 10.46.2.1'];
$dl->page('/account/login.php');
db_exec('UPDATE customers SET password_hash = ? WHERE email = ?', [customer_password_hash($rmPw), $dlDoc['email']]);
$dl->api('POST', '/account/api/auth.php', ['action' => 'login', 'email' => $dlDoc['email'], 'password' => $rmPw]);
$pc0 = (int) db_val('SELECT print_count FROM documents WHERE id = ?', [$dlDoc['id']]);
[$st, $hd] = $dl->headers('/account/api/document.php?id=' . $dlDoc['id'] . '&dl=1');
[$st2, $hd2] = $dl->headers('/account/api/document.php?id=' . $dlDoc['id']);
check('?dl=1 sends the PDF as a download; without it, it opens in the browser', $st === 200 && str_starts_with($hd['content-disposition'] ?? '', 'attachment')
    && ($hd['content-type'] ?? '') === 'application/pdf' && $st2 === 200 && str_starts_with($hd2['content-disposition'] ?? '', 'inline'), json_encode($hd));
check('… and opening the same document twice within 2 minutes counts as one print', (int) db_val('SELECT print_count FROM documents WHERE id = ?', [$dlDoc['id']]) === $pc0 + 1);
check('document links are marked for the app to save them', str_contains($dl->page('/account/documents.php'), 'data-download'));

$pgH = new TestHttp($base);
[$st, $res] = $pgH->api('GET', '/pages/api/page.php?slug=terms');
check('the pages API returns a published page for the dialog', $st === 200 && ($res['data']['title'] ?? '') !== '' && str_contains($res['data']['html'] ?? '', '<p')
    && ($res['data']['url'] ?? '') === '/pages/terms', json_encode($res));
db_exec("UPDATE pages SET is_published = 0 WHERE slug = 'returns'");
[$st] = $pgH->api('GET', '/pages/api/page.php?slug=returns');
[$st2] = $pgH->api('GET', '/pages/api/page.php?slug=no-such-page');
[$st3] = $pgH->api('POST', '/pages/api/page.php?slug=terms', []);
db_exec("UPDATE pages SET is_published = 1 WHERE slug = 'returns'");
check('… but not an unpublished or unknown page, and only by GET', $st === 404 && $st2 === 404 && $st3 === 405);
check('registration opens the terms and privacy policy in a dialog', str_contains((new TestHttp($base))->page('/account/register.php'), 'data-page-dialog="terms"'));

[$st, $hd] = $pgH->headers('/.well-known/assetlinks.json');
[$st2, $hd2] = $pgH->headers('/.well-known/assetlinks.json', ['Host: www.127.0.0.1']);
[$st3] = $pgH->headers('/', ['Host: www.127.0.0.1']);
check('/.well-known/assetlinks.json is JSON, on both host names, never redirected', $st === 200 && str_starts_with($hd['content-type'] ?? '', 'application/json')
    && $st2 === 200 && $st3 === 301, $st . ' ' . $st2 . ' ' . $st3);
$GLOBALS['GX_ENV']['android'] = ['package' => 'za.co.grazex.app', 'sha256' => str_repeat('ab', 32) . ', ' . implode(':', str_split(str_repeat('0F', 32), 2)) . ', nonsense'];
$links = android_asset_links();
check('fingerprints with or without colons are accepted, junk ignored', ($links[0]['target']['package_name'] ?? '') === 'za.co.grazex.app'
    && ($links[0]['target']['sha256_cert_fingerprints'] ?? []) === [implode(':', str_split(str_repeat('AB', 32), 2)), implode(':', str_split(str_repeat('0F', 32), 2))]
    && $links[0]['relation'] === ['delegate_permission/common.handle_all_urls'], json_encode($links));
$GLOBALS['GX_ENV']['android'] = ['package' => 'not a package', 'sha256' => ''];
check('… and with no fingerprint set the file is empty', android_asset_links() === []);
unset($GLOBALS['GX_ENV']['android']);

// Push test set-up removed (a real service-account file, if there was one, is put back)
PushService::useTransport(null);
@unlink($fbFile);
@unlink($fbTokenFile);
if ($fbBackup !== null) {
    file_put_contents($fbFile, $fbBackup);
    chmod($fbFile, 0600);
}
PushService::useCredentials(null);

section('Migrations');
// Every migration must run on an up-to-date database without errors and without changing anything (safe to paste twice)
$schemaOf = static fn(): string => json_encode([
    db_all('SELECT table_name, column_name, column_type, is_nullable, column_default FROM information_schema.columns WHERE table_schema = DATABASE() ORDER BY 1, 2'),
    db_all('SELECT table_name, index_name, column_name, non_unique FROM information_schema.statistics WHERE table_schema = DATABASE() ORDER BY 1, 2, 3'),
    db_all('SELECT template_key, subject, body FROM email_templates ORDER BY 1'),
    db_all('SELECT setting_key FROM settings ORDER BY 1'),
]);
$beforeSchema = $schemaOf();
$installedVersions = db_col('SELECT version FROM schema_migrations ORDER BY version'); // as install.sql seeded them
$migErr = '';
foreach (glob(GX_ROOT . '/database/migrations/0*.sql') ?: [] as $mf) {
    try {
        $admin->exec((string) file_get_contents($mf));
        $admin->exec((string) file_get_contents($mf));
    } catch (Throwable $e) {
        $migErr .= basename($mf) . ': ' . $e->getMessage() . ' ';
    }
}
check('all migrations run twice on a current database without errors', $migErr === '', $migErr);
check('… and leave the schema, email templates and settings exactly as install.sql made them', $schemaOf() === $beforeSchema);
check('every migration is recorded in install.sql', $installedVersions === array_merge(['001_install'],
    array_map(static fn($f) => basename($f, '.sql'), glob(GX_ROOT . '/database/migrations/0*.sql') ?: [])));

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
