<?php
/**
 * Orders: placement (transactional stock deduction), payments, status transitions, expiry,
 * livestock finalisation, cancellation with restock + credit notes.
 */
declare(strict_types=1);

defined('GX_ROOT') || exit;

final class OrderService
{
    public const STATUS_LABELS = [
        'pending_payment' => ['Awaiting payment', 'badge-warning'],
        'awaiting_collection' => ['Pay on collection', 'badge-info'],
        'reserved' => ['Reserved', 'badge-warning'],
        'paid' => ['Paid', 'badge-success'],
        'deposit_paid' => ['Deposit paid', 'badge-success'],
        'processing' => ['Being prepared', 'badge-info'],
        'finalised' => ['Weighed & invoiced', 'badge-info'],
        'ready_for_collection' => ['Ready for collection', 'badge-accent'],
        'out_for_delivery' => ['Out for delivery', 'badge-accent'],
        'collected' => ['Collected', 'badge-success'],
        'completed' => ['Completed', 'badge-success'],
        'cancelled' => ['Cancelled', 'badge-danger'],
        'expired' => ['Expired', 'badge-danger'],
        'refunded' => ['Refunded', 'badge-danger'],
    ];

    /**
     * Allowed manual transitions (admin). "paid" / "deposit_paid" are never set by hand — only
     * recordPayment() sets them, so money and status always agree. "refunded" goes through refund().
     */
    public const TRANSITIONS = [
        'pending_payment' => ['cancelled'],
        'awaiting_collection' => ['processing', 'ready_for_collection', 'completed', 'cancelled'],
        'reserved' => ['finalised', 'cancelled'],
        'paid' => ['processing', 'ready_for_collection', 'out_for_delivery', 'completed', 'cancelled'],
        'deposit_paid' => ['finalised', 'cancelled'],
        'processing' => ['ready_for_collection', 'out_for_delivery', 'completed', 'cancelled'],
        'finalised' => ['ready_for_collection', 'collected', 'cancelled'],
        'ready_for_collection' => ['collected', 'completed', 'cancelled'],
        'out_for_delivery' => ['completed'],
        'collected' => ['completed'],
        'completed' => ['refunded'],
        'cancelled' => [],
        'expired' => [],
        'refunded' => [],
    ];

    public static function label(string $status): array
    {
        return self::STATUS_LABELS[$status] ?? [ucfirst(str_replace('_', ' ', $status)), ''];
    }

    /**
     * Statuses an admin may move this order to. Livestock must be handed over as "collected"
     * (that issues the Document of Identification) before it can be completed.
     */
    public static function nextStatuses(array $o): array
    {
        $next = self::TRANSITIONS[$o['status']] ?? [];
        if ((int) $o['division_id'] === division_id('ranch') && $o['status'] !== 'collected') {
            $next = array_values(array_diff($next, ['completed']));
        }
        return $next;
    }

    // -----------------------------------------------------------------
    // Quote (live totals for checkout)
    // -----------------------------------------------------------------

    /**
     * Compute totals for a checkout. $in: fulfilment, town, payment_method.
     * Returns lines, subtotal, discount, delivery, total, vat, deposit, due_now, zone, errors[], zero_rated, savings.
     */
    public static function quote(string $div, array $in, ?array $customer): array
    {
        $summary = CartService::summary($div);
        $divisionId = division_id($div);
        $vatBp = PricingService::divisionVatBp($divisionId);
        $errors = [];
        $zone = null;
        $delivery = 0;

        // Zero-rating for verified VAT-registered farmers (Feeds only, setting must be ON)
        $zeroOk = $div === 'feeds' && setting_bool('zero_rating_enabled') && $vatBp > 0
            && ($customer['vat_farmer_status'] ?? '') === 'verified';
        $lines = [];
        $savings = 0;
        foreach ($summary['lines'] as $l) {
            $zr = false;
            if ($zeroOk && $l['type'] === 'variant') {
                $zr = (bool) db_val('SELECT p.zero_rate_eligible FROM product_variants v JOIN products p ON p.id = v.product_id WHERE v.id = ?', [$l['target_id']]);
            }
            if ($zr) {
                $unitEx = (int) round($l['unit'] * 10000 / (10000 + $vatBp));
                $savings += ($l['unit'] - $unitEx) * $l['qty'];
                $l['unit'] = $unitEx;
                $l['total'] = $unitEx * $l['qty'];
            }
            $l['zero_rated'] = $zr;
            $lines[] = $l;
        }
        $subtotal = array_sum(array_column($lines, 'total'));
        $discount = 0;
        if ($summary['coupon']) {
            $coupon = db_one('SELECT * FROM coupons WHERE id = ?', [$summary['coupon']['id']]);
            $discount = $coupon && $coupon['type'] !== 'free_delivery' ? PricingService::couponDiscount($coupon, $subtotal, 0) : 0;
        }

        $fulfilment = $div === 'ranch' ? 'collection' : (($in['fulfilment'] ?? 'collection') === 'delivery' ? 'delivery' : 'collection');
        if ($fulfilment === 'delivery') {
            $zone = DeliveryService::zoneForTown((string) ($in['town'] ?? ''));
            if (!$zone) {
                $errors['town'] = 'We do not deliver to that town yet — choose collection or another town.';
            } else {
                $delivery = DeliveryService::fee($zone, $subtotal - $discount);
                if (($subtotal - $discount) < (int) $zone['min_order_cents']) {
                    $errors['fulfilment'] = 'The minimum order for delivery to ' . $zone['name'] . ' is ' . money((int) $zone['min_order_cents']) . '.';
                }
                if ($summary['coupon'] && $summary['coupon']['type'] === 'free_delivery') {
                    $discount += $delivery;
                }
            }
        }

        $total = $subtotal - $discount + $delivery;
        // VAT exactly as the invoice will show it: per line, delivery at the standard rate, and the discount
        // split across the VAT rates in the same way (DocumentService::allocateDiscount)
        $vat = 0;
        $byRate = [];
        foreach ($lines as $l) {
            $rate = $l['zero_rated'] ? 0 : $vatBp;
            $vat += vat_from_incl($l['total'], $rate);
            $byRate[$rate] = ($byRate[$rate] ?? 0) + $l['total'];
        }
        foreach (DocumentService::allocateDiscount($byRate, $discount) as $rate => $share) {
            $vat -= vat_from_incl($share, $rate);
        }
        $vat += vat_from_incl($delivery, $vatBp);
        // The deposit can never be more than the order (e.g. a coupon on a high-deposit lot)
        $deposit = $div === 'ranch' ? min((int) $summary['deposit'], max(0, $total)) : 0;
        $dueNow = $div === 'ranch' ? $deposit : (($in['payment_method'] ?? '') === 'cash_on_collection' ? 0 : $total);

        return [
            'division' => $div, 'lines' => $lines, 'notices' => $summary['notices'], 'coupon' => $summary['coupon'],
            'subtotal' => $subtotal, 'discount' => $discount, 'delivery' => $delivery, 'total' => max(0, $total),
            'vat' => max(0, $vat), 'vat_bp' => $vatBp, 'deposit' => $deposit, 'due_now' => $dueNow,
            'balance' => max(0, $total - $dueNow), 'zone' => $zone ? DeliveryService::describe($zone) : null,
            'fulfilment' => $fulfilment, 'errors' => $errors, 'zero_rated' => $savings > 0, 'savings' => $savings,
            'est_weight_kg' => $summary['est_weight_kg'],
        ];
    }

    // -----------------------------------------------------------------
    // Place order
    // -----------------------------------------------------------------

    /**
     * Place an order from the current cart. $in is validated checkout input.
     * Returns the new order row. Throws DomainException with a customer-friendly message.
     */
    public static function place(string $div, array $in, array $customer): array
    {
        $divisionId = division_id($div);
        $quote = self::quote($div, $in, $customer);
        if (!$quote['lines']) {
            throw new DomainException('Your cart is empty.');
        }
        if ($quote['errors']) {
            throw new DomainException(reset($quote['errors']));
        }
        foreach ($quote['lines'] as $l) {
            // Safety net: never sell at R0 (e.g. a per-kg lot without an average weight)
            if ((int) $l['total'] <= 0) {
                throw new DomainException($l['name'] . ' cannot be ordered online right now — please contact us.');
            }
        }
        $method = (string) $in['payment_method'];
        $allowed = self::allowedPaymentMethods($div, $quote['fulfilment'], $quote['deposit']);
        if (!in_array($method, $allowed, true)) {
            throw new DomainException('That payment method is not available for this order.');
        }

        // Collection slot / delivery date validation
        $slot = null;
        $deliveryDate = null;
        if ($quote['fulfilment'] === 'collection') {
            $slot = DeliveryService::validateSlot($divisionId, (string) ($in['collection_date'] ?? ''), (int) ($in['collection_slot_id'] ?? 0));
            if (!$slot) {
                throw new DomainException('That collection time is no longer available. Please choose another.');
            }
        } else {
            $zoneRow = DeliveryService::zoneForTown((string) $in['town']);
            if (!$zoneRow) {
                throw new DomainException('We no longer deliver to that town — please choose collection or another town.');
            }
            if (!in_array((string) ($in['delivery_date'] ?? ''), DeliveryService::deliveryDates($zoneRow, 10), true)) {
                throw new DomainException('Please choose one of the available delivery dates.');
            }
            $deliveryDate = (string) $in['delivery_date'];
        }

        // Status + stock hold window. Nothing is paid up front for pay-on-collection and deposit-free reservations,
        // so an account whose email is not confirmed yet only holds stock until the end of the collection day.
        $cashDays = !empty($customer['email_verified_at']) ? setting_int('cash_hold_days', 3) : 0;
        $paymentStatus = 'unpaid';
        if ($div === 'ranch') {
            $status = 'reserved';
            $hold = $quote['deposit'] > 0
                ? date('Y-m-d H:i:s', time() + setting_int('ranch_deposit_hold_hours', 48) * 3600)
                : date('Y-m-d 23:59:59', strtotime($in['collection_date'] . ' +' . $cashDays . ' days'));
        } elseif ((int) $quote['total'] === 0) {
            // Nothing to pay (e.g. a 100% coupon): the order is paid as placed
            $status = 'paid';
            $paymentStatus = 'paid';
            $hold = null;
        } elseif ($method === 'cash_on_collection') {
            $status = 'awaiting_collection';
            $hold = date('Y-m-d 23:59:59', strtotime($in['collection_date'] . ' +' . $cashDays . ' days'));
        } else {
            $status = 'pending_payment';
            $hold = $method === 'yoco'
                ? date('Y-m-d H:i:s', time() + setting_int('yoco_hold_minutes', 60) * 60)
                : date('Y-m-d H:i:s', time() + setting_int('eft_hold_hours', 48) * 3600);
        }

        $cartId = (int) (CartService::current($div)['id'] ?? 0);
        $order = db_tx(static function () use ($div, $divisionId, $quote, $in, $customer, $method, $status, $paymentStatus, $hold, $slot, $deliveryDate, $cartId): array {
            // 0. One order per cart: lock the cart and check it still holds exactly what was quoted
            //    (a double click or a second tab waits here, then finds the cart already ordered).
            //    These locks come FIRST, before any plain read, so every later read in this transaction
            //    sees the data as it is after the lock was granted.
            if (!$cartId || !db_val('SELECT id FROM carts WHERE id = ? FOR UPDATE', [$cartId])) {
                throw new DomainException('Your cart is empty.');
            }
            if ($slot) {
                db_val('SELECT id FROM collection_slots WHERE id = ? FOR UPDATE', [$slot['id']]);
            }
            CartService::forget($div);
            $fresh = self::quote($div, $in, $customer);
            if (!$fresh['lines']) {
                throw new DomainException('Your cart is empty.');
            }
            if (self::quoteSignature($fresh) !== self::quoteSignature($quote)) {
                throw new DomainException('Your cart changed while you were checking out — please check the totals and try again.');
            }

            // 1. Lock stock rows in id order and re-check (prevents overselling under concurrency)
            $isFeeds = $div === 'feeds';
            $ids = array_map(static fn($l) => (int) $l['target_id'], $quote['lines']);
            sort($ids);
            $locked = [];
            foreach ($ids as $id) {
                $locked[$id] = $isFeeds
                    ? db_one('SELECT id, stock_qty AS avail, cost_cents FROM product_variants WHERE id = ? FOR UPDATE', [$id])
                    : db_one("SELECT id, qty_available AS avail, cost_cents, status FROM lots WHERE id = ? FOR UPDATE", [$id]);
            }
            foreach ($quote['lines'] as $l) {
                $row = $locked[(int) $l['target_id']] ?? null;
                if (!$row || (int) $row['avail'] < (int) $l['qty'] || (!$isFeeds && !in_array($row['status'], ['available', 'reserved'], true))) {
                    throw new DomainException('Sorry — ' . $l['name'] . ' just sold out or has fewer than ' . $l['qty'] . ' left. Please review your cart.');
                }
            }

            // 1b. Collection slot: count again under the slot lock taken in step 0 (two buyers cannot both take the last place)
            if ($slot) {
                $capacity = (int) db_val('SELECT capacity FROM collection_slots WHERE id = ?', [$slot['id']]);
                $taken = (int) db_val("SELECT COUNT(*) FROM orders WHERE division_id = ? AND collection_date = ? AND collection_slot_id = ?
                                       AND status NOT IN ('cancelled','expired','refunded') LOCK IN SHARE MODE", [$divisionId, $in['collection_date'], $slot['id']]);
                if ($taken >= $capacity) {
                    throw new DomainException('That collection time has just filled up. Please choose another.');
                }
            }

            // 1c. Coupon: claim a use atomically (limits hold under concurrency); a coupon that gives nothing is not used
            $couponId = $quote['coupon'] && (int) $quote['discount'] > 0 ? (int) $quote['coupon']['id'] : null;
            if ($couponId) {
                if (db_exec('UPDATE coupons SET used_count = used_count + 1 WHERE id = ? AND is_active = 1 AND (max_uses IS NULL OR used_count < max_uses)', [$couponId]) !== 1) {
                    throw new DomainException('That coupon has just been fully redeemed — please remove it from your cart and try again.');
                }
                $perCustomer = db_val('SELECT max_uses_per_customer FROM coupons WHERE id = ?', [$couponId]);
                if ($perCustomer !== null && (int) db_val('SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id = ? AND customer_id = ? LOCK IN SHARE MODE',
                        [$couponId, $customer['id']]) >= (int) $perCustomer) {
                    throw new DomainException('You have already used this coupon.');
                }
            }

            // 2. Order row
            $orderNo = DocumentService::orderNumber($divisionId);
            $addr = $in['address'] ?? [];
            $orderId = db_insert('INSERT INTO orders (division_id, order_no, customer_id, status, payment_status, payment_method, fulfilment,
                    collection_point_id, collection_slot_id, collection_date, delivery_zone_id, delivery_date,
                    ship_name, ship_phone, ship_farm_name, ship_line1, ship_line2, ship_town, ship_province, ship_postal_code, ship_directions, ship_gps_lat, ship_gps_lng,
                    billing_name, billing_email, billing_phone, billing_company, billing_vat_no, billing_address, buyer_id_number, buyer_brand_mark, transport_note,
                    vat_rate_bp, zero_rated, subtotal_cents, discount_cents, delivery_cents, vat_cents, total_cents, deposit_due_cents, coupon_id,
                    customer_note, hold_expires_at, pay_token)
                VALUES (?,?,?,?,?,?,?, ?,?,?,?,?, ?,?,?,?,?,?,?,?,?,?,?, ?,?,?,?,?,?,?,?,?, ?,?,?,?,?,?,?,?,?, ?,?,?)', [
                $divisionId, $orderNo, $customer['id'], $status, $paymentStatus, $method, $quote['fulfilment'],
                $slot ? $slot['collection_point_id'] : null, $slot['id'] ?? null, $slot ? $in['collection_date'] : null,
                $quote['zone']['id'] ?? null, $deliveryDate,
                $addr['recipient_name'] ?? null, $addr['phone'] ?? null, $addr['farm_name'] ?? null, $addr['line1'] ?? null, $addr['line2'] ?? null,
                $quote['fulfilment'] === 'delivery' ? $in['town'] : null, $addr['province'] ?? null, $addr['postal_code'] ?? null, $addr['directions'] ?? null,
                $addr['gps_lat'] ?? null, $addr['gps_lng'] ?? null,
                $in['billing_name'], $customer['email'], $in['billing_phone'] ?? null, $in['billing_company'] ?? null, $in['billing_vat_no'] ?? null,
                $in['billing_address'] ?? null, $in['buyer_id_number'] ?? null, $in['buyer_brand_mark'] ?? null, $in['transport_note'] ?? null,
                $quote['vat_bp'], $quote['zero_rated'] ? 1 : 0, $quote['subtotal'], $quote['discount'], $quote['delivery'],
                $quote['vat'], $quote['total'], $quote['deposit'], $couponId,
                $in['customer_note'] ?? null, $hold, random_token(32),
            ]);

            // 3. Items + stock deduction (ledger)
            foreach ($quote['lines'] as $l) {
                $row = $locked[(int) $l['target_id']];
                db_exec('INSERT INTO order_items (order_id, variant_id, lot_id, name, sku, variant_name, qty, pricing_mode, unit_price_cents,
                         est_weight_kg, line_total_cents, vat_rate_bp, zero_rated, unit_cost_cents) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
                    $orderId, $isFeeds ? $l['target_id'] : null, $isFeeds ? null : $l['target_id'], mb_substr((string) $l['name'], 0, 191), $l['sku'],
                    $l['variant'] !== null ? mb_substr((string) $l['variant'], 0, 200) : null,
                    $l['qty'], $isFeeds ? 'unit' : $l['pricing_mode'], $isFeeds ? $l['unit'] : ($l['pricing_mode'] === 'per_kg' ? $l['price_cents'] : $l['unit']),
                    $isFeeds ? null : $l['est_weight_kg'], $l['total'], $quote['vat_bp'], $l['zero_rated'] ? 1 : 0, $row['cost_cents'],
                ]);
                $opts = ['order_id' => $orderId, 'reference' => $orderNo, 'note' => 'Order ' . $orderNo];
                $isFeeds ? StockService::adjustVariant((int) $l['target_id'], -(int) $l['qty'], 'sale', $opts)
                         : StockService::adjustLot((int) $l['target_id'], -(int) $l['qty'], 'sale', $opts);
            }

            // 4. Coupon redemption (the use was claimed in step 1c)
            if ($couponId) {
                db_exec('INSERT INTO coupon_redemptions (coupon_id, order_id, customer_id, discount_cents) VALUES (?,?,?,?)',
                    [$couponId, $orderId, $customer['id'], $quote['discount']]);
            }
            if ($paymentStatus === 'paid') {
                db_exec('UPDATE orders SET paid_at = NOW() WHERE id = ?', [$orderId]);
            }

            self::history($orderId, null, $status, 'Order placed', 'customer', (int) $customer['id']);
            CartService::clear($div);
            return db_one('SELECT * FROM orders WHERE id = ?', [$orderId]);
        });

        // After commit, and after the customer already has their answer (a slow mail server never slows checkout):
        // documents + notifications, each on its own so one failure never skips the others
        $firstName = (string) $customer['first_name'];
        gx_after_response('Order confirmation for ' . $order['order_no'], static function () use ($order, $divisionId, $firstName): void {
            $docId = DocumentService::issue((int) $order['id'], 'order_confirmation');
            $attach = DocumentService::attachment($docId);
            if ($order['payment_status'] === 'paid' && (int) $order['division_id'] === division_id('feeds')) {
                $attach = array_merge($attach, DocumentService::attachment(DocumentService::issue((int) $order['id'], 'tax_invoice')));
            }
            $instructions = self::paymentInstructions($order);
            NotificationService::email('order_placed', $order['billing_email'], $order['billing_name'], [
                'first_name' => $firstName, 'order_no' => $order['order_no'], 'payment_instructions' => $instructions,
                'payment_instructions_md' => $instructions,
            ], $divisionId, $attach, ['label' => 'View your order', 'url' => app_url('/account/order.php?no=' . rawurlencode($order['order_no']))]);
        });
        gx_after_response('New-order alert for ' . $order['order_no'], static function () use ($order, $divisionId, $method): void {
            NotificationService::admin('new_order', 'New ' . division($divisionId)['name'] . ' order ' . $order['order_no'] . ' (' . money((int) $order['total_cents']) . ')',
                $order['billing_name'] . ' · ' . strtoupper(str_replace('_', ' ', $method)) . ' · ' . ($order['fulfilment'] === 'delivery' ? 'Delivery to ' . $order['ship_town'] : 'Collection ' . fmt_date($order['collection_date'])),
                '/admin/order.php?id=' . $order['id'], $divisionId);
        });
        audit('order.placed', 'order', (int) $order['id'], ['order_no' => $order['order_no'], 'total' => $order['total_cents']]);
        return $order;
    }

    /** What the customer agreed to: lines, quantities and money. Used to detect a cart that changed under the lock. */
    private static function quoteSignature(array $q): string
    {
        return json_encode([
            array_map(static fn($l) => [(int) $l['target_id'], (int) $l['qty'], (int) $l['total']], $q['lines']),
            (int) $q['subtotal'], (int) $q['discount'], (int) $q['delivery'], (int) $q['total'], (int) $q['deposit'], $q['coupon']['id'] ?? null,
        ]);
    }

    public static function allowedPaymentMethods(string $div, string $fulfilment, int $deposit): array
    {
        if ($div === 'ranch' && $deposit === 0) {
            // Nothing is due now: the reservation is paid at the farm (card/EFT here would charge the full estimate)
            return ['cash_on_collection'];
        }
        $m = [];
        if (setting_bool('payment_yoco_enabled', true) && YocoService::configured()) {
            $m[] = 'yoco';
        }
        // EFT only once the division's bank details are filled in (Admin → Settings) — otherwise the
        // customer would be asked to pay without knowing where to pay.
        if (setting_bool('payment_eft_enabled', true) && trim((string) (division($div)['bank_account_no'] ?? '')) !== '') {
            $m[] = 'eft';
        }
        $cashOk = $div === 'ranch' ? $deposit === 0 : $fulfilment === 'collection';
        if (setting_bool('payment_cash_enabled', true) && $cashOk) {
            $m[] = 'cash_on_collection';
        }
        return $m;
    }

    /** Amount the customer must pay now / next. */
    public static function amountDue(array $o): int
    {
        if (in_array($o['status'], ['cancelled', 'expired', 'refunded'], true)) {
            return 0;
        }
        if ((int) $o['division_id'] === division_id('ranch') && in_array($o['status'], ['reserved'], true) && (int) $o['deposit_due_cents'] > 0) {
            return max(0, (int) $o['deposit_due_cents'] - (int) $o['amount_paid_cents']);
        }
        return max(0, (int) $o['total_cents'] - (int) $o['amount_paid_cents']);
    }

    /** Markdown bank details for an EFT order (empty-safe). */
    public static function bankDetailsMd(array $o): string
    {
        $d = division((int) $o['division_id']);
        if (!$d || trim((string) $d['bank_account_no']) === '') {
            return "Please contact us for our banking details. Use **{$o['order_no']}** as your payment reference.";
        }
        return "- Bank: **{$d['bank_name']}**\n- Account name: **{$d['bank_account_name']}**\n- Account no: **{$d['bank_account_no']}**"
             . "\n- Branch code: **{$d['bank_branch_code']}**\n- Reference: **{$o['order_no']}**";
    }

    /** Markdown payment instructions for emails / pages. */
    public static function paymentInstructions(array $o): string
    {
        $due = self::amountDue($o);
        if ($o['payment_method'] === 'eft') {
            $bank = self::bankDetailsMd($o);
            return "**Please pay " . money($due) . " by EFT** before " . fmt_datetime($o['hold_expires_at']) . " to keep your stock reserved.\n\n{$bank}\n\nThen upload your proof of payment in your account.";
        }
        if ($o['payment_method'] === 'yoco') {
            return '**Pay ' . money($due) . ' securely by card** — if you did not finish paying, use the button below.';
        }
        if ((int) $o['division_id'] === division_id('ranch')) {
            return 'Your animals are reserved. The balance is payable at collection on **' . fmt_date($o['collection_date']) . '**.';
        }
        return 'Pay **' . money($due) . '** by cash or card when you collect on **' . fmt_date($o['collection_date']) . '**.';
    }

    // -----------------------------------------------------------------
    // Payments
    // -----------------------------------------------------------------

    /**
     * Record a successful payment (idempotent per payment row). Updates order totals/status,
     * issues receipt (+ invoice when fully paid for Feeds), emails the customer.
     * $paymentId: existing payments row to mark succeeded, or null to create one (cash/card/manual EFT).
     */
    public static function recordPayment(int $orderId, int $amount, string $method, ?int $paymentId = null, array $opts = []): array
    {
        $result = db_tx(static function () use ($orderId, $amount, $method, $paymentId, $opts): array {
            $o = db_one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
            if (!$o) {
                throw new DomainException('Order not found.');
            }
            if (in_array($o['status'], ['cancelled', 'refunded'], true)) {
                // Money for a closed order: never reopen it or invoice it — the caller records it and flags a refund
                throw new LatePaymentException('Payment received for ' . $o['status'] . ' order ' . $o['order_no'] . ' — refund needed.');
            }
            if ($paymentId) {
                $p = db_one('SELECT * FROM payments WHERE id = ? AND order_id = ? FOR UPDATE', [$paymentId, $orderId]);
                if (!$p) {
                    throw new DomainException('Payment not found.');
                }
                if ($p['status'] === 'succeeded') {
                    return ['order' => $o, 'payment_id' => $paymentId, 'new' => false];
                }
                db_exec("UPDATE payments SET status = 'succeeded', amount_cents = ?, provider_payment_id = COALESCE(?, provider_payment_id),
                         verified_by = ?, verified_at = NOW(), notes = COALESCE(?, notes) WHERE id = ?",
                    [$amount, $opts['provider_payment_id'] ?? null, $opts['admin_id'] ?? null, $opts['notes'] ?? null, $paymentId]);
            } else {
                $purpose = self::paymentPurpose($o, $amount);
                $paymentId = db_insert('INSERT INTO payments (order_id, division_id, method, purpose, amount_cents, status, reference, verified_by, verified_at, notes)
                                        VALUES (?,?,?,?,?,\'succeeded\',?,?,NOW(),?)',
                    [$orderId, $o['division_id'], $method, $purpose, $amount, $opts['reference'] ?? $o['order_no'], $opts['admin_id'] ?? null, $opts['notes'] ?? null]);
            }

            $paid = (int) $o['amount_paid_cents'] + $amount;
            $paymentStatus = $paid >= (int) $o['total_cents'] ? 'paid' : 'partial';
            $newStatus = $o['status'];
            $isRanch = (int) $o['division_id'] === division_id('ranch');

            // Late payment on an expired order: try to take the stock again
            if ($o['status'] === 'expired') {
                $ok = true;
                try {
                    foreach (db_all('SELECT * FROM order_items WHERE order_id = ?', [$orderId]) as $it) {
                        $it['variant_id']
                            ? StockService::adjustVariant((int) $it['variant_id'], -(int) $it['qty'], 'late_payment', ['order_id' => $orderId, 'reference' => $o['order_no']])
                            : StockService::adjustLot((int) $it['lot_id'], -(int) $it['qty'], 'late_payment', ['order_id' => $orderId, 'reference' => $o['order_no']]);
                    }
                } catch (DomainException $e) {
                    $ok = false;
                }
                if (!$ok) {
                    throw new LatePaymentException('Payment received for expired order ' . $o['order_no'] . ' but stock is no longer available — refund needed.');
                }
                $newStatus = $isRanch ? 'reserved' : 'pending_payment';
                // Reinstated: undo what the expiry did (cancel stamp, coupon use)
                db_exec('UPDATE orders SET cancelled_at = NULL, cancel_reason = NULL WHERE id = ?', [$orderId]);
                if ($o['coupon_id'] && !db_val('SELECT 1 FROM coupon_redemptions WHERE order_id = ?', [$orderId])) {
                    db_exec('INSERT INTO coupon_redemptions (coupon_id, order_id, customer_id, discount_cents) VALUES (?,?,?,?)',
                        [$o['coupon_id'], $orderId, $o['customer_id'], (int) $o['discount_cents']]);
                    db_exec('UPDATE coupons SET used_count = used_count + 1 WHERE id = ?', [$o['coupon_id']]);
                }
                self::history($orderId, 'expired', $newStatus, 'Order reinstated — payment arrived after the hold had expired', 'system');
            }

            if ($isRanch) {
                if (in_array($newStatus, ['reserved'], true) && $paid >= (int) $o['deposit_due_cents']) {
                    $newStatus = 'deposit_paid';
                }
            } elseif (in_array($newStatus, ['pending_payment'], true) && $paymentStatus === 'paid') {
                $newStatus = 'paid';
            }

            db_exec('UPDATE orders SET amount_paid_cents = ?, payment_status = ?, status = ?, paid_at = COALESCE(paid_at, IF(? = \'paid\', NOW(), NULL)),
                     hold_expires_at = IF(? IN (\'paid\',\'deposit_paid\'), NULL, hold_expires_at) WHERE id = ?',
                [$paid, $paymentStatus, $newStatus, $paymentStatus, $newStatus, $orderId]);
            if ($newStatus !== $o['status']) {
                self::history($orderId, $o['status'], $newStatus, 'Payment of ' . money($amount) . ' received (' . strtoupper($method) . ')',
                    isset($opts['admin_id']) ? 'admin' : 'system', $opts['admin_id'] ?? null);
            } else {
                self::history($orderId, $o['status'], $o['status'], 'Payment of ' . money($amount) . ' received (' . strtoupper($method) . ')',
                    isset($opts['admin_id']) ? 'admin' : 'system', $opts['admin_id'] ?? null);
            }
            return ['order' => db_one('SELECT * FROM orders WHERE id = ?', [$orderId]), 'payment_id' => $paymentId, 'new' => true];
        });

        if ($result['new']) {
            self::afterPayment($result['order'], (int) $result['payment_id']);
            $ro = $result['order'];
            if ((int) $ro['amount_paid_cents'] > (int) $ro['total_cents']) {
                try {
                    NotificationService::admin('refund_due', 'Overpayment on ' . $ro['order_no'],
                        money((int) $ro['amount_paid_cents']) . ' received against a total of ' . money((int) $ro['total_cents'])
                        . ' — refund ' . money((int) $ro['amount_paid_cents'] - (int) $ro['total_cents']) . '.',
                        '/admin/order.php?id=' . $ro['id'], (int) $ro['division_id']);
                } catch (Throwable $e) {
                    gx_log('error', 'Overpayment alert failed', ['order' => $ro['order_no'], 'error' => $e->getMessage()]);
                }
            }
        }
        return $result['order'];
    }

    private static function paymentPurpose(array $o, int $amount): string
    {
        if ((int) $o['division_id'] === division_id('ranch') && (int) $o['amount_paid_cents'] === 0 && (int) $o['deposit_due_cents'] > 0
            && $amount < (int) $o['total_cents']) {
            return 'deposit';
        }
        return (int) $o['amount_paid_cents'] > 0 ? 'balance' : 'full';
    }

    private static function afterPayment(array $o, int $paymentId): void
    {
        try {
            $receiptId = DocumentService::issue((int) $o['id'], 'receipt', ['payment_id' => $paymentId]);
            $attach = DocumentService::attachment($receiptId);
            // Feeds: invoice as soon as fully paid. Ranch: invoice at finalisation (weights known).
            if ((int) $o['division_id'] === division_id('feeds') && $o['payment_status'] === 'paid') {
                $invId = DocumentService::issue((int) $o['id'], 'tax_invoice');
                $attach = array_merge($attach, DocumentService::attachment($invId));
            }
            $pay = db_one('SELECT amount_cents FROM payments WHERE id = ?', [$paymentId]);
            $customer = db_one('SELECT first_name FROM customers WHERE id = ?', [$o['customer_id']]);
            NotificationService::email('payment_received', $o['billing_email'], $o['billing_name'], [
                'first_name' => $customer['first_name'] ?? '', 'order_no' => $o['order_no'], 'amount' => money((int) $pay['amount_cents']),
            ], (int) $o['division_id'], $attach, ['label' => 'View your order', 'url' => app_url('/account/order.php?no=' . rawurlencode($o['order_no']))]);
        } catch (Throwable $e) {
            gx_log('error', 'After-payment tasks failed', ['order' => $o['order_no'], 'error' => $e->getMessage()]);
        }
    }

    // -----------------------------------------------------------------
    // Status changes
    // -----------------------------------------------------------------

    public static function history(int $orderId, ?string $from, string $to, ?string $note, string $actorType = 'system', ?int $actorId = null): void
    {
        db_exec('INSERT INTO order_status_history (order_id, from_status, to_status, note, actor_type, actor_id) VALUES (?,?,?,?,?,?)',
            [$orderId, $from, $to, $note ? mb_substr($note, 0, 500) : null, $actorType, $actorId]);
    }

    /** Admin/customer status change with side effects. */
    public static function transition(int $orderId, string $to, ?string $note = null, ?int $adminId = null): array
    {
        $o = db_one('SELECT * FROM orders WHERE id = ?', [$orderId]);
        if (!$o) {
            throw new DomainException('Order not found.');
        }
        if (!in_array($to, self::nextStatuses($o), true)) {
            throw new DomainException('Cannot change an order from "' . self::label($o['status'])[0] . '" to "' . self::label($to)[0] . '".'
                . ((int) $o['division_id'] === division_id('ranch') && $to === 'completed' ? ' Mark the livestock as collected first.' : ''));
        }
        if ($to === 'refunded') {
            return self::refund($orderId, $note ?: 'Refunded', $adminId, false);
        }
        if ($to === 'cancelled') {
            return self::cancel($orderId, $note ?: 'Cancelled', $adminId);
        }
        if ($to === 'finalised') {
            return self::finalise($orderId, [], $adminId);
        }
        if (in_array($to, ['completed', 'collected'], true) && self::amountDue($o) > 0 && $o['status'] !== 'collected') {
            throw new DomainException('This order still has ' . money(self::amountDue($o)) . ' outstanding. Record the payment first.');
        }

        db_tx(static function () use ($o, $to, $note, $adminId): void {
            // Only move the order if nobody changed it in the meantime (e.g. a payment or expiry)
            $n = db_exec('UPDATE orders SET status = ?, completed_at = IF(? = \'completed\', NOW(), completed_at), hold_expires_at = NULL WHERE id = ? AND status = ?',
                [$to, $to, $o['id'], $o['status']]);
            if ($n !== 1) {
                throw new DomainException('This order was updated in the meantime — reload the page and try again.');
            }
            self::history((int) $o['id'], $o['status'], $to, $note, $adminId ? 'admin' : 'system', $adminId);
        });
        $o = db_one('SELECT * FROM orders WHERE id = ?', [$orderId]);

        // Side effects
        try {
            $first = (string) db_val('SELECT first_name FROM customers WHERE id = ?', [$o['customer_id']]);
            $btn = ['label' => 'View your order', 'url' => app_url('/account/order.php?no=' . rawurlencode($o['order_no']))];
            if ($to === 'processing') {
                DocumentService::issue($orderId, $o['fulfilment'] === 'delivery' ? 'delivery_note' : 'pick_list');
            } elseif ($to === 'ready_for_collection') {
                DocumentService::issue($orderId, 'collection_slip');
                NotificationService::email('order_ready', $o['billing_email'], $o['billing_name'], ['first_name' => $first, 'order_no' => $o['order_no'],
                    'collection_date' => fmt_date($o['collection_date'])], (int) $o['division_id'], [], $btn);
            } elseif ($to === 'out_for_delivery') {
                NotificationService::email('order_out_for_delivery', $o['billing_email'], $o['billing_name'], ['first_name' => $first, 'order_no' => $o['order_no']], (int) $o['division_id'], [], $btn);
            } elseif ($to === 'collected') {
                if ((int) $o['division_id'] === division_id('ranch')) {
                    $doiId = DocumentService::issue($orderId, 'doc_identification');
                    NotificationService::email('order_completed', $o['billing_email'], $o['billing_name'], ['first_name' => $first, 'order_no' => $o['order_no']],
                        (int) $o['division_id'], DocumentService::attachment($doiId), $btn);
                }
            } elseif ($to === 'completed' && $o['status'] === 'completed') {
                NotificationService::email('order_completed', $o['billing_email'], $o['billing_name'], ['first_name' => $first, 'order_no' => $o['order_no']], (int) $o['division_id'], [], $btn);
            }
        } catch (Throwable $e) {
            gx_log('error', 'Transition side effects failed', ['order' => $o['order_no'], 'to' => $to, 'error' => $e->getMessage()]);
        }
        audit('order.status', 'order', $orderId, ['to' => $to, 'note' => $note]);
        return $o;
    }

    /**
     * Record that money received on a cancelled / expired order was paid back (EFT or Yoco portal).
     * The order itself stays closed; payments become "refunded" so reports stop counting them.
     */
    public static function markMoneyRefunded(int $orderId, string $note, ?int $adminId = null): array
    {
        $o = db_tx(static function () use ($orderId, $note, $adminId): array {
            $o = db_one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
            if (!$o || !in_array($o['status'], ['cancelled', 'expired'], true)) {
                throw new DomainException('Only cancelled or expired orders use this — refund completed orders with Refund….');
            }
            if ((int) $o['amount_paid_cents'] <= 0 || $o['payment_status'] === 'refunded') {
                throw new DomainException('There is no payment on this order to mark as refunded.');
            }
            db_exec("UPDATE payments SET status = 'refunded', notes = CONCAT(COALESCE(notes, ''), ' Refunded: ', ?) WHERE order_id = ? AND status = 'succeeded'",
                [mb_substr($note, 0, 200), $orderId]);
            db_exec("UPDATE orders SET payment_status = 'refunded' WHERE id = ?", [$orderId]);
            self::history($orderId, $o['status'], $o['status'], 'Payment refunded: ' . $note, $adminId ? 'admin' : 'system', $adminId);
            return db_one('SELECT * FROM orders WHERE id = ?', [$orderId]);
        });
        audit('order.money_refunded', 'order', $orderId, ['note' => $note]);
        return $o;
    }

    /** True while a card checkout for this order may still be completed by the customer. */
    public static function cardCheckoutOpen(int $orderId): bool
    {
        return (bool) db_val("SELECT 1 FROM payments WHERE order_id = ? AND method = 'yoco' AND status = 'pending'
                              AND created_at > NOW() - INTERVAL ? MINUTE", [$orderId, setting_int('yoco_hold_minutes', 60)]);
    }

    /**
     * Refund a completed order: credit note against the invoice, payments marked refunded, optional restock
     * (only when the goods physically came back). The money itself is paid back outside the site (EFT / Yoco portal).
     */
    public static function refund(int $orderId, string $reason, ?int $adminId = null, bool $restock = false): array
    {
        return self::cancel($orderId, $reason, $adminId, 'refunded', $restock);
    }

    /** Cancel: restock, credit note if invoiced, email. */
    public static function cancel(int $orderId, string $reason, ?int $adminId = null, string $finalStatus = 'cancelled', bool $restock = true): array
    {
        $o = db_tx(static function () use ($orderId, $reason, $adminId, $finalStatus, $restock): array {
            $o = db_one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
            if (!$o || in_array($o['status'], ['cancelled', 'expired', 'refunded'], true)) {
                throw new DomainException('This order is already closed.');
            }
            if ($finalStatus === 'refunded' && $o['status'] !== 'completed') {
                throw new DomainException('Only completed orders can be refunded — cancel the order instead.');
            }
            if (in_array($o['status'], ['collected', 'completed', 'out_for_delivery'], true) && $finalStatus !== 'refunded') {
                throw new DomainException('This order has already been handed over and cannot be cancelled.');
            }
            if ($finalStatus === 'expired') {
                // Re-check under the lock: a payment / POP may have arrived since the order was picked for expiry
                $stillDue = in_array($o['status'], ['pending_payment', 'reserved', 'awaiting_collection'], true)
                    && $o['payment_status'] === 'unpaid'
                    && (int) db_val('SELECT hold_expires_at IS NOT NULL AND hold_expires_at < NOW() FROM orders WHERE id = ?', [$orderId]) === 1
                    && !db_val("SELECT 1 FROM payments WHERE order_id = ? AND status = 'awaiting_verification'", [$orderId]);
                if (!$stillDue) {
                    throw new DomainException('This order is no longer due to expire.');
                }
            }
            $type = $finalStatus === 'expired' ? 'expiry_return' : 'cancel_return';
            if ($restock) {
                foreach (db_all('SELECT * FROM order_items WHERE order_id = ?', [$orderId]) as $it) {
                    $opts = ['order_id' => $orderId, 'reference' => $o['order_no'], 'note' => ucfirst($finalStatus) . ': ' . $reason, 'admin_id' => $adminId];
                    $it['variant_id'] ? StockService::adjustVariant((int) $it['variant_id'], (int) $it['qty'], $type, $opts)
                                      : ($it['lot_id'] ? StockService::adjustLot((int) $it['lot_id'], (int) $it['qty'], $type, $opts) : null);
                }
            }
            db_exec('UPDATE orders SET status = ?, cancelled_at = NOW(), cancel_reason = ?, hold_expires_at = NULL WHERE id = ?', [$finalStatus, mb_substr($reason, 0, 255), $orderId]);
            db_exec("UPDATE payments SET status = 'cancelled' WHERE order_id = ? AND status IN ('pending','awaiting_verification')", [$orderId]);
            if ($finalStatus === 'refunded') {
                db_exec("UPDATE payments SET status = 'refunded', notes = CONCAT(COALESCE(notes, ''), ' Refunded: ', ?) WHERE order_id = ? AND status = 'succeeded'",
                    [mb_substr($reason, 0, 200), $orderId]);
                db_exec("UPDATE orders SET payment_status = 'refunded' WHERE id = ? AND amount_paid_cents > 0", [$orderId]);
            }
            if ($o['coupon_id']) {
                db_exec('DELETE FROM coupon_redemptions WHERE order_id = ?', [$orderId]);
                db_exec('UPDATE coupons SET used_count = GREATEST(used_count - 1, 0) WHERE id = ?', [$o['coupon_id']]);
            }
            self::history($orderId, $o['status'], $finalStatus, $reason, $adminId ? 'admin' : ($finalStatus === 'expired' ? 'system' : 'customer'), $adminId ?? ($finalStatus === 'cancelled' ? (int) $o['customer_id'] : null));
            return db_one('SELECT * FROM orders WHERE id = ?', [$orderId]);
        });

        try {
            $cnId = null;
            $inv = db_val("SELECT id FROM documents WHERE order_id = ? AND doc_type IN ('tax_invoice','invoice') AND is_void = 0 ORDER BY id DESC LIMIT 1", [$orderId]);
            if ($inv) {
                $cnId = DocumentService::creditNote((int) $inv, $finalStatus === 'refunded' ? 'refund' : 'cancel', $reason);
            }
            $first = (string) db_val('SELECT first_name FROM customers WHERE id = ?', [$o['customer_id']]);
            $template = match ($finalStatus) { 'expired' => 'order_expired', 'refunded' => 'order_refunded', default => 'order_cancelled' };
            NotificationService::email($template, $o['billing_email'], $o['billing_name'],
                ['first_name' => $first, 'order_no' => $o['order_no'], 'reason' => $reason], (int) $o['division_id'],
                $cnId ? DocumentService::attachment($cnId) : []);
            if ((int) $o['amount_paid_cents'] > 0 && $finalStatus !== 'refunded') {
                NotificationService::admin('refund_due', 'Refund due on ' . $o['order_no'], money((int) $o['amount_paid_cents']) . ' was paid before the order was ' . $finalStatus . '.',
                    '/admin/order.php?id=' . $orderId, (int) $o['division_id']);
            }
        } catch (Throwable $e) {
            gx_log('error', 'Cancel side effects failed', ['order' => $o['order_no'], 'error' => $e->getMessage()]);
        }
        audit('order.' . $finalStatus, 'order', $orderId, ['reason' => $reason]);
        return $o;
    }

    /**
     * Ranch: enter actual weights (per_kg lines), recalc totals, issue the invoice.
     * $weights: [order_item_id => total actual kg]
     */
    public static function finalise(int $orderId, array $weights, ?int $adminId = null): array
    {
        $o = db_tx(static function () use ($orderId, $weights, $adminId): array {
            $o = db_one('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
            if (!$o || !in_array($o['status'], ['reserved', 'deposit_paid'], true)) {
                throw new DomainException('Only reserved livestock orders can be finalised.');
            }
            $subtotal = 0;
            $vatBp = (int) $o['vat_rate_bp'];
            $vatOn = PricingService::divisionVatBp((int) $o['division_id']) > 0;
            $vat = 0;
            foreach (db_all('SELECT * FROM order_items WHERE order_id = ?', [$orderId]) as $it) {
                $line = (int) $it['line_total_cents'];
                if ($it['pricing_mode'] === 'per_kg') {
                    if (!isset($weights[$it['id']])) {
                        throw new DomainException('Enter the actual weight for ' . $it['name'] . ' — the invoice is based on it.');
                    }
                    $kg = (float) $weights[$it['id']];
                    if ($kg <= 0) {
                        throw new DomainException('Enter the actual weight for ' . $it['name'] . '.');
                    }
                    $line = (int) round($kg * (int) $it['unit_price_cents']);
                    db_exec('UPDATE order_items SET actual_weight_kg = ?, line_total_cents = ? WHERE id = ?', [$kg, $line, $it['id']]);
                }
                $subtotal += $line;
                $vat += $vatOn ? vat_from_incl($line, $vatBp) : 0;
            }
            $total = $subtotal - (int) $o['discount_cents'] + (int) $o['delivery_cents'];
            $paid = (int) $o['amount_paid_cents'];
            db_exec('UPDATE orders SET subtotal_cents = ?, vat_cents = ?, total_cents = ?, status = ?, finalised_at = NOW(),
                     payment_status = ?, hold_expires_at = NULL WHERE id = ?',
                [$subtotal, $vat, $total, 'finalised', $paid >= $total ? 'paid' : ($paid > 0 ? 'partial' : 'unpaid'), $orderId]);
            self::history($orderId, $o['status'], 'finalised', 'Weights confirmed — final total ' . money($total), $adminId ? 'admin' : 'system', $adminId);
            return db_one('SELECT * FROM orders WHERE id = ?', [$orderId]);
        });
        try {
            $invId = DocumentService::issue($orderId, 'tax_invoice', ['admin_id' => $adminId]);
            $first = (string) db_val('SELECT first_name FROM customers WHERE id = ?', [$o['customer_id']]);
            NotificationService::email('order_finalised', $o['billing_email'], $o['billing_name'], [
                'first_name' => $first, 'order_no' => $o['order_no'], 'balance_due' => money(self::amountDue($o)),
            ], (int) $o['division_id'], DocumentService::attachment($invId), ['label' => 'View your order', 'url' => app_url('/account/order.php?no=' . rawurlencode($o['order_no']))]);
            if ((int) $o['amount_paid_cents'] > (int) $o['total_cents']) {
                NotificationService::admin('refund_due', 'Overpayment on ' . $o['order_no'], 'Final total is lower than the amount paid — refund ' . money((int) $o['amount_paid_cents'] - (int) $o['total_cents']) . '.',
                    '/admin/order.php?id=' . $orderId, (int) $o['division_id']);
            }
        } catch (Throwable $e) {
            gx_log('error', 'Finalise side effects failed', ['order' => $o['order_no'], 'error' => $e->getMessage()]);
        }
        audit('order.finalised', 'order', $orderId);
        return $o;
    }

    // -----------------------------------------------------------------
    // Expiry (lazy — Xneelo cron only runs every 2 hours)
    // -----------------------------------------------------------------

    /** Run expireDue() at most every 5 minutes, triggered from normal page loads. */
    public static function maybeExpire(): void
    {
        $last = (int) setting('last_expiry_run', '0');
        if (time() - $last < 300) {
            return;
        }
        try {
            setting_set('last_expiry_run', (string) time());
            self::expireDue();
            self::sendReminders();
            MailService::processQueue(5);
        } catch (Throwable $e) {
            gx_log('error', 'Expiry run failed: ' . $e->getMessage());
        }
    }

    public static function expireDue(int $limit = 25): int
    {
        // Part-paid orders are never released automatically: tell the admin once, then stop the clock
        foreach (db_all("SELECT id, order_no, division_id, amount_paid_cents, total_cents, deposit_due_cents FROM orders
                         WHERE status IN ('pending_payment','reserved') AND payment_status = 'partial'
                         AND hold_expires_at IS NOT NULL AND hold_expires_at < NOW() LIMIT " . (int) $limit) as $p) {
            if (db_exec('UPDATE orders SET hold_expires_at = NULL WHERE id = ? AND hold_expires_at IS NOT NULL', [$p['id']]) === 1) {
                NotificationService::admin('payment_overdue', 'Part-paid order past its hold: ' . $p['order_no'],
                    money((int) $p['amount_paid_cents']) . ' of ' . money((int) ($p['deposit_due_cents'] ?: $p['total_cents'])) . ' received. Contact the customer, then record the rest or cancel (a refund is flagged).',
                    '/admin/order.php?id=' . $p['id'], (int) $p['division_id']);
            }
        }
        $due = db_col("SELECT id FROM orders WHERE status IN ('pending_payment','reserved','awaiting_collection') AND payment_status = 'unpaid'
                       AND hold_expires_at IS NOT NULL AND hold_expires_at < NOW() ORDER BY hold_expires_at LIMIT " . (int) $limit);
        $n = 0;
        foreach ($due as $id) {
            // Don't expire a card order whose payment is still being confirmed
            if (db_val("SELECT 1 FROM payments WHERE order_id = ? AND status = 'awaiting_verification'", [$id])) {
                continue;
            }
            try {
                self::cancel((int) $id, 'Payment not received in time — stock released.', null, 'expired');
                $n++;
            } catch (DomainException $e) {
                // already closed
            }
        }
        return $n;
    }

    /** EFT payment reminders. */
    public static function sendReminders(): void
    {
        $hours = setting_int('eft_reminder_hours', 24);
        $rows = db_all("SELECT o.*, c.first_name FROM orders o JOIN customers c ON c.id = o.customer_id
                        WHERE o.status IN ('pending_payment','reserved') AND o.payment_status = 'unpaid' AND o.payment_method = 'eft'
                        AND o.reminder_sent_at IS NULL AND o.placed_at < NOW() - INTERVAL ? HOUR AND o.hold_expires_at > NOW()
                        AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.order_id = o.id AND p.status = 'awaiting_verification') LIMIT 25", [$hours]);
        foreach ($rows as $o) {
            db_exec('UPDATE orders SET reminder_sent_at = NOW() WHERE id = ?', [$o['id']]);
            NotificationService::email('payment_reminder', $o['billing_email'], $o['billing_name'], [
                'first_name' => $o['first_name'], 'order_no' => $o['order_no'], 'expires_at' => fmt_datetime($o['hold_expires_at']),
                'amount_due' => money(self::amountDue($o)), 'bank_details_md' => self::bankDetailsMd($o),
            ], (int) $o['division_id'], [], ['label' => 'Upload proof of payment', 'url' => app_url('/account/order.php?no=' . rawurlencode($o['order_no']))], false);
        }
    }
}

/** Thrown when money arrives for an order whose stock is gone. */
final class LatePaymentException extends DomainException
{
}
