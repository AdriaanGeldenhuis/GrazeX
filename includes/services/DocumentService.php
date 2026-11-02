<?php
/**
 * Business documents: order confirmations (pro-forma), tax invoices, receipts, credit notes,
 * pick lists, collection slips and livestock documents of identification.
 *
 * Rules:
 *  - Numbers come from document_sequences, locked FOR UPDATE → sequential, no gaps, no duplicates.
 *  - Issued documents are immutable. Corrections are made with a credit note.
 *  - A JSON snapshot of seller + buyer details is stored, so a PDF can be re-rendered identically later.
 *  - Any re-download after the first is watermarked "COPY".
 */
declare(strict_types=1);

defined('GX_ROOT') || exit;

final class DocumentService
{
    public const TITLES = [
        'order_confirmation' => 'Order confirmation',
        'tax_invoice' => 'Tax invoice',
        'invoice' => 'Invoice',
        'receipt' => 'Payment receipt',
        'credit_note' => 'Credit note',
        'debit_note' => 'Debit note',
        'pick_list' => 'Pick list',
        'delivery_note' => 'Delivery note',
        'collection_slip' => 'Collection slip',
        'doc_identification' => 'Document of identification',
        'statement' => 'Statement',
    ];

    /** Next sequential number for a division/doc type in the current year. Must run inside a transaction. */
    public static function nextNumber(int $divisionId, string $seqType): int
    {
        $year = (int) date('Y');
        // Lock the existing row first; create it only for the first number of the year
        $n = db_val('SELECT next_no FROM document_sequences WHERE division_id = ? AND doc_type = ? AND seq_year = ? FOR UPDATE', [$divisionId, $seqType, $year]);
        if ($n === null) {
            db_exec('INSERT IGNORE INTO document_sequences (division_id, doc_type, seq_year, next_no) VALUES (?, ?, ?, 1)', [$divisionId, $seqType, $year]);
            $n = db_val('SELECT next_no FROM document_sequences WHERE division_id = ? AND doc_type = ? AND seq_year = ? FOR UPDATE', [$divisionId, $seqType, $year]);
        }
        $n = (int) $n;
        db_exec('UPDATE document_sequences SET next_no = next_no + 1 WHERE division_id = ? AND doc_type = ? AND seq_year = ?', [$divisionId, $seqType, $year]);
        return $n;
    }

    public static function orderNumber(int $divisionId): string
    {
        $d = division($divisionId);
        return sprintf('%s-%s-%05d', $d['order_prefix'], date('y'), self::nextNumber($divisionId, 'order'));
    }

    private static function docNumber(array $division, string $type, array $order): string
    {
        $year = date('Y');
        return match ($type) {
            'tax_invoice', 'invoice' => sprintf('%s-%s-%05d', $division['invoice_prefix'], $year, self::nextNumber((int) $division['id'], 'tax_invoice')),
            'credit_note' => sprintf('%s-%s-%05d', $division['credit_prefix'], $year, self::nextNumber((int) $division['id'], 'credit_note')),
            'debit_note' => sprintf('%s-DN-%s-%05d', $division['order_prefix'], $year, self::nextNumber((int) $division['id'], 'debit_note')),
            'receipt' => sprintf('%s-%s-%05d', $division['receipt_prefix'], $year, self::nextNumber((int) $division['id'], 'receipt')),
            'doc_identification' => sprintf('%s-DOI-%s-%05d', $division['order_prefix'], $year, self::nextNumber((int) $division['id'], 'doc_identification')),
            'pick_list' => $order['order_no'] . '-PL',
            'delivery_note' => $order['order_no'] . '-DN',
            'collection_slip' => $order['order_no'] . '-CS',
            'statement' => sprintf('ST-%d-%s', (int) $order['customer_id'], date('YmdHis')),
            default => $order['order_no'],
        };
    }

    /** Seller snapshot for a division. */
    public static function sellerSnapshot(array $d): array
    {
        return [
            'name' => $d['legal_name'] ?: $d['name'], 'trading_name' => $d['name'], 'registration_no' => $d['registration_no'],
            'vat_registered' => (bool) $d['vat_registered'], 'vat_no' => $d['vat_no'], 'address' => $d['address'],
            'phone' => $d['phone'] ?: setting('contact_phone'), 'email' => $d['email'] ?: setting('contact_email'),
            'bank' => ['bank' => $d['bank_name'], 'account_name' => $d['bank_account_name'], 'account_no' => $d['bank_account_no'],
                       'branch_code' => $d['bank_branch_code'], 'account_type' => $d['bank_account_type']],
            'footer' => $d['document_footer'], 'code' => $d['code'],
            'brand_mark' => $d['code'] === 'ranch' ? setting('ranch_brand_mark') : null,
        ];
    }

    /** Buyer snapshot from an order. */
    public static function buyerSnapshot(array $o): array
    {
        return [
            'name' => $o['billing_name'], 'company' => $o['billing_company'], 'email' => $o['billing_email'], 'phone' => $o['billing_phone'],
            'vat_no' => $o['billing_vat_no'], 'address' => $o['billing_address'], 'id_number' => $o['buyer_id_number'],
            'brand_mark' => $o['buyer_brand_mark'],
            'ship' => array_filter(['name' => $o['ship_name'], 'farm' => $o['ship_farm_name'], 'line1' => $o['ship_line1'], 'line2' => $o['ship_line2'],
                'town' => $o['ship_town'], 'province' => $o['ship_province'], 'postal_code' => $o['ship_postal_code'], 'phone' => $o['ship_phone'],
                'directions' => $o['ship_directions']]),
        ];
    }

    /**
     * Build document lines from an order's items (VAT-inclusive prices; VAT extracted per line).
     * Adds delivery and discount lines. Discount is allocated proportionally across VAT rates.
     */
    public static function linesFromOrder(array $order, array $items): array
    {
        $lines = [];
        $rateStd = (int) $order['vat_rate_bp'];
        $byRate = [];
        $vatByRate = [];
        foreach ($items as $it) {
            $rate = (int) $it['zero_rated'] ? 0 : (int) $it['vat_rate_bp'];
            $incl = (int) $it['line_total_cents'];
            $isKg = $it['pricing_mode'] === 'per_kg';
            $kgVal = $it['actual_weight_kg'] ?? $it['est_weight_kg'];
            $qty = $isKg ? (float) $kgVal : (float) $it['qty'];
            $desc = $it['name'] . ($it['variant_name'] ? ' — ' . $it['variant_name'] : '');
            if ($isKg) {
                $desc .= ' (' . (int) $it['qty'] . ' head, ' . ($it['actual_weight_kg'] !== null ? 'weighed' : 'estimated') . ' live weight)';
            } elseif ($it['pricing_mode'] === 'per_head') {
                $desc .= ' (per head)';
            }
            $line = self::line($desc, $qty, $isKg ? 'kg' : ($it['pricing_mode'] === 'per_head' ? 'head' : 'unit'),
                (int) $it['unit_price_cents'], $incl, $rate, (bool) $it['zero_rated'], (int) $it['id']);
            $lines[] = $line;
            $byRate[$rate] = ($byRate[$rate] ?? 0) + $incl;
            $vatByRate[$rate] = ($vatByRate[$rate] ?? 0) + $line['line_vat_cents'];
        }
        $delivery = (int) $order['delivery_cents'];
        [$goodsDiscount, $deliveryDiscount] = self::splitDiscount((int) $order['discount_cents'], $delivery, $order['coupon_type'] ?? null);
        $label = 'Discount' . (($order['coupon_code'] ?? '') !== '' ? ' (' . $order['coupon_code'] . ')' : '');
        if ($goodsDiscount > 0) {
            $rates = array_keys($byRate);
            foreach (self::allocateDiscount($byRate, $goodsDiscount) as $rate => $share) {
                if ($share > 0) {
                    // VAT so that this rate's VAT is exactly the VAT on its net amount (matches orderVat())
                    $vat = vat_from_incl($byRate[$rate] - $share, $rate) - $vatByRate[$rate];
                    $lines[] = self::line($label . (count($rates) > 1 ? ($rate ? ' — standard-rated' : ' — zero-rated') : ''),
                        1, null, -$share, -$share, $rate, $rate === 0 && $rateStd > 0, null, $vat);
                }
            }
        }
        if ($delivery > 0) {
            $lines[] = self::line('Delivery', 1, null, $delivery, $delivery, $rateStd, false, null);
            if ($deliveryDiscount > 0) {
                $vat = vat_from_incl($delivery - $deliveryDiscount, $rateStd) - vat_from_incl($delivery, $rateStd);
                $lines[] = self::line('Free delivery' . (($order['coupon_code'] ?? '') !== '' ? ' (' . $order['coupon_code'] . ')' : ''),
                    1, null, -$deliveryDiscount, -$deliveryDiscount, $rateStd, false, null, $vat);
            }
        }
        return $lines;
    }

    /** A coupon's discount as [on goods, on delivery]: a free-delivery coupon only ever reduces the delivery fee. */
    public static function splitDiscount(int $discount, int $delivery, ?string $couponType): array
    {
        $onDelivery = $couponType === 'free_delivery' ? min($discount, $delivery) : 0;
        return [$discount - $onDelivery, $onDelivery];
    }

    /**
     * The order's VAT, computed the same way for the checkout quote, finalise() and the invoice:
     * VAT per line; where a discount applies, VAT on the NET amount per rate (goods at that rate minus their share);
     * delivery at the standard rate on the fee minus any free-delivery discount.
     * $lineRates: list of [incl cents, rate bp] per goods line.
     */
    public static function orderVat(array $lineRates, int $goodsDiscount, int $delivery, int $deliveryDiscount, int $stdRate): int
    {
        $byRate = [];
        $perLine = [];
        foreach ($lineRates as [$incl, $rate]) {
            $byRate[$rate] = ($byRate[$rate] ?? 0) + $incl;
            $perLine[$rate] = ($perLine[$rate] ?? 0) + vat_from_incl($incl, $rate);
        }
        $shares = self::allocateDiscount($byRate, $goodsDiscount);
        $vat = 0;
        foreach ($byRate as $rate => $incl) {
            $vat += !empty($shares[$rate]) ? vat_from_incl($incl - $shares[$rate], $rate) : $perLine[$rate];
        }
        return $vat + vat_from_incl(max(0, $delivery - $deliveryDiscount), $stdRate);
    }

    /**
     * Split a discount across VAT rates in proportion to the line totals per rate ([rate => incl total]).
     * Used for both the checkout quote and the invoice, so the order's VAT always equals the invoice's VAT.
     * Returns [rate => discount share] (shares add up to the discount exactly).
     */
    public static function allocateDiscount(array $byRate, int $discount): array
    {
        if ($discount <= 0 || !$byRate) {
            return [];
        }
        $sub = array_sum($byRate);
        $left = $discount;
        $rates = array_keys($byRate);
        $out = [];
        foreach ($rates as $i => $rate) {
            $share = $i === count($rates) - 1 ? $left : (int) round($discount * $byRate[$rate] / max(1, $sub));
            $left -= $share;
            $out[$rate] = $share;
        }
        return $out;
    }

    public static function line(string $desc, float $qty, ?string $unit, int $unitIncl, int $lineIncl, int $rateBp, bool $zero, ?int $itemId, ?int $vatOverride = null): array
    {
        $vat = $vatOverride ?? vat_from_incl($lineIncl, $rateBp);
        return ['description' => mb_substr($desc, 0, 255), 'qty' => $qty, 'unit' => $unit, 'unit_price_incl_cents' => $unitIncl,
                'line_excl_cents' => $lineIncl - $vat, 'line_vat_cents' => $vat, 'line_incl_cents' => $lineIncl,
                'vat_rate_bp' => $rateBp, 'zero_rated' => $zero ? 1 : 0, 'order_item_id' => $itemId];
    }

    /**
     * Issue a document for an order. Returns the document id.
     * $opts: payment_id, related_document_id, reason_code, explanation, lines (override), admin_id, extra (array merged into snapshot)
     */
    public static function issue(int $orderId, string $type, array $opts = []): int
    {
        return db_tx(static function () use ($orderId, $type, $opts): int {
            $order = db_one('SELECT o.*, c.code AS coupon_code, c.type AS coupon_type FROM orders o LEFT JOIN coupons c ON c.id = o.coupon_id WHERE o.id = ? FOR UPDATE', [$orderId]);
            if (!$order) {
                throw new DomainException('Order not found.');
            }
            $division = division((int) $order['division_id']);
            if ($type === 'doc_identification') {
                self::assertCanIdentify();
            }
            if ($type === 'tax_invoice' && !(int) $division['vat_registered']) {
                $type = 'invoice';
            }
            // One invoice per order (re-issuing returns the existing one)
            if (in_array($type, ['tax_invoice', 'invoice', 'order_confirmation', 'doc_identification', 'pick_list', 'collection_slip', 'delivery_note'], true)) {
                $existing = db_val('SELECT id FROM documents WHERE order_id = ? AND doc_type = ? AND is_void = 0 ORDER BY id DESC LIMIT 1', [$orderId, $type]);
                if ($existing) {
                    return (int) $existing;
                }
            }
            $items = db_all('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [$orderId]);
            $lines = $opts['lines'] ?? self::linesFromOrder($order, $items);

            if ($type === 'receipt' && !isset($opts['lines'])) {
                $pay = db_one('SELECT * FROM payments WHERE id = ?', [(int) ($opts['payment_id'] ?? 0)]);
                $amount = (int) ($pay['amount_cents'] ?? 0);
                $label = ['deposit' => 'Deposit', 'balance' => 'Balance payment', 'full' => 'Payment'][$pay['purpose'] ?? 'full'] ?? 'Payment';
                $lines = [self::line($label . ' for order ' . $order['order_no'] . ' (' . strtoupper((string) ($pay['method'] ?? '')) . ')', 1, null, $amount, $amount, 0, false, null)];
                $lines[0]['line_vat_cents'] = 0;
                $lines[0]['line_excl_cents'] = $amount;
            }

            $excl = array_sum(array_column($lines, 'line_excl_cents'));
            $vat = array_sum(array_column($lines, 'line_vat_cents'));
            $total = array_sum(array_column($lines, 'line_incl_cents'));
            $docNo = self::docNumber($division, $type, $order);

            $snapshot = [
                'title' => $type === 'tax_invoice' ? 'TAX INVOICE' : strtoupper(self::TITLES[$type] ?? $type),
                'seller' => self::sellerSnapshot($division),
                'buyer' => self::buyerSnapshot($order),
                'order' => ['order_no' => $order['order_no'], 'placed_at' => $order['placed_at'], 'status' => $order['status'],
                            'payment_method' => $order['payment_method'], 'fulfilment' => $order['fulfilment'],
                            'collection_date' => $order['collection_date'], 'delivery_date' => $order['delivery_date'],
                            'total_cents' => (int) $order['total_cents'], 'deposit_due_cents' => (int) $order['deposit_due_cents'],
                            'amount_paid_cents' => (int) $order['amount_paid_cents'], 'hold_expires_at' => $order['hold_expires_at'],
                            'customer_note' => $order['customer_note'], 'transport_note' => $order['transport_note'], 'zero_rated' => (bool) $order['zero_rated']],
            ] + ($opts['extra'] ?? []);

            if ($type === 'doc_identification' || $type === 'pick_list' || $type === 'collection_slip') {
                $snapshot['items'] = array_map(static fn($it) => ['name' => $it['name'], 'variant' => $it['variant_name'], 'sku' => $it['sku'],
                    'qty' => (int) $it['qty'], 'est_weight_kg' => $it['est_weight_kg'], 'actual_weight_kg' => $it['actual_weight_kg'], 'lot_id' => $it['lot_id']], $items);
                if ($type === 'doc_identification') {
                    $snapshot['lots'] = [];
                    foreach ($items as $it) {
                        if ($it['lot_id']) {
                            $snapshot['lots'][] = db_one('SELECT l.lot_no, l.title, l.sex, l.tag_no, l.age_months_min, l.age_months_max,
                                       b.name AS breed, b.kind, c.name AS category
                                FROM lots l JOIN breeds b ON b.id = l.breed_id JOIN livestock_categories c ON c.id = l.category_id WHERE l.id = ?', [$it['lot_id']]) + ['qty' => (int) $it['qty']];
                        }
                    }
                }
            }

            $docId = db_insert('INSERT INTO documents (division_id, order_id, customer_id, payment_id, related_document_id, doc_type, doc_no,
                    subtotal_excl_cents, vat_cents, total_cents, reason_code, explanation, snapshot, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
                $order['division_id'], $orderId, $order['customer_id'], $opts['payment_id'] ?? null, $opts['related_document_id'] ?? null,
                $type, $docNo, $excl, $vat, $total, $opts['reason_code'] ?? null, $opts['explanation'] ?? null,
                json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $opts['admin_id'] ?? (gx_is_system() ? null : (current_admin()['id'] ?? null)),
            ]);
            foreach ($lines as $i => $l) {
                db_exec('INSERT INTO document_lines (document_id, order_item_id, description, qty, unit, unit_price_incl_cents, line_excl_cents, line_vat_cents, line_incl_cents, vat_rate_bp, zero_rated, sort_order)
                         VALUES (?,?,?,?,?,?,?,?,?,?,?,?)', [$docId, $l['order_item_id'] ?? null, $l['description'], $l['qty'], $l['unit'], $l['unit_price_incl_cents'],
                    $l['line_excl_cents'], $l['line_vat_cents'], $l['line_incl_cents'], $l['vat_rate_bp'], $l['zero_rated'], $i]);
            }
            return $docId;
        });
    }

    /**
     * A document of identification (Stock Theft Act) must show the seller's registered brand / tattoo mark.
     * @throws DomainException when the ranch mark has not been set up.
     */
    public static function assertCanIdentify(): void
    {
        if (trim((string) setting('ranch_brand_mark', '')) === '') {
            throw new DomainException('Add the ranch brand / tattoo mark in Settings → General first — it is printed on the document of identification that goes with the animals.');
        }
    }

    /** Credit note against an invoice (full or partial lines). */
    public static function creditNote(int $invoiceId, string $reasonCode, string $explanation, ?array $lines = null): int
    {
        $inv = db_one("SELECT * FROM documents WHERE id = ? AND doc_type IN ('tax_invoice','invoice') AND is_void = 0", [$invoiceId]);
        if (!$inv) {
            throw new DomainException('Invoice not found.');
        }
        if ($lines === null) {
            if (db_val("SELECT id FROM documents WHERE related_document_id = ? AND doc_type = 'credit_note' AND is_void = 0", [$invoiceId])) {
                throw new DomainException('A full credit note already exists for this invoice.');
            }
            $lines = array_map(static fn($l) => [
                'description' => 'Credit: ' . $l['description'], 'qty' => (float) $l['qty'], 'unit' => $l['unit'],
                'unit_price_incl_cents' => -(int) $l['unit_price_incl_cents'], 'line_excl_cents' => -(int) $l['line_excl_cents'],
                'line_vat_cents' => -(int) $l['line_vat_cents'], 'line_incl_cents' => -(int) $l['line_incl_cents'],
                'vat_rate_bp' => (int) $l['vat_rate_bp'], 'zero_rated' => (int) $l['zero_rated'], 'order_item_id' => $l['order_item_id'],
            ], db_all('SELECT * FROM document_lines WHERE document_id = ? ORDER BY sort_order', [$invoiceId]));
        }
        return self::issue((int) $inv['order_id'], 'credit_note', [
            'related_document_id' => $invoiceId, 'reason_code' => $reasonCode, 'explanation' => $explanation, 'lines' => $lines,
            'extra' => ['invoice_no' => $inv['doc_no'], 'invoice_date' => $inv['issued_at']],
        ]);
    }

    /** Render a stored document to PDF bytes (COPY watermark when $copy). */
    public static function pdf(int $docId, bool $copy = false): array
    {
        $doc = db_one('SELECT * FROM documents WHERE id = ?', [$docId]);
        if (!$doc) {
            throw new DomainException('Document not found.');
        }
        $lines = db_all('SELECT * FROM document_lines WHERE document_id = ? ORDER BY sort_order, id', [$docId]);
        $snap = json_decode((string) $doc['snapshot'], true) ?: [];
        $bytes = PdfService::render('document', ['doc' => $doc, 'lines' => $lines, 'snap' => $snap, 'copy' => $copy]);
        return ['bytes' => $bytes, 'filename' => $doc['doc_no'] . '.pdf', 'doc' => $doc];
    }

    /** Save a PDF to private storage (for email attachments). Returns a storage-relative path or null. */
    public static function savePdf(int $docId): ?string
    {
        if (!PdfService::available()) {
            return null;
        }
        try {
            $pdf = self::pdf($docId);
            $dir = 'storage/private/documents/' . date('Y/m');
            if (!is_dir(GX_ROOT . '/' . $dir)) {
                mkdir(GX_ROOT . '/' . $dir, 0755, true);
            }
            $rel = $dir . '/' . preg_replace('/[^A-Za-z0-9._-]/', '_', $pdf['filename']);
            file_put_contents(GX_ROOT . '/' . $rel, $pdf['bytes']);
            db_exec('UPDATE documents SET pdf_path = ? WHERE id = ?', [$rel, $docId]);
            return $rel;
        } catch (Throwable $e) {
            gx_log('error', 'PDF save failed', ['doc' => $docId, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /** Attachment array for MailService. */
    public static function attachment(int $docId): array
    {
        $doc = db_one('SELECT doc_no, pdf_path FROM documents WHERE id = ?', [$docId]);
        $path = $doc['pdf_path'] ?? null ?: self::savePdf($docId);
        return $path ? [['path' => $path, 'name' => $doc['doc_no'] . '.pdf']] : [];
    }
}
