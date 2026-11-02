<?php
/**
 * Admin list filters shared by a list page and its CSV export, so the export holds exactly what the page shows.
 */
declare(strict_types=1);

defined('GX_ROOT') || exit;

/**
 * Orders filter from query values. Returns [$f (clean values for the form), $where (SQL parts on alias o), $params].
 * $ids: the divisions this admin may see.
 */
function admin_order_filters(array $q, array $ids): array
{
    $str = static fn(string $k): string => is_string($q[$k] ?? null) ? trim($q[$k]) : '';
    $date = static fn(string $k): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', $str($k)) ? $str($k) : '';
    $f = [
        'q' => mb_substr($str('q'), 0, 80),
        'division' => in_array($str('division'), ['feeds', 'ranch'], true) ? $str('division') : '',
        'status' => array_key_exists($str('status'), OrderService::STATUS_LABELS) ? $str('status') : '',
        'method' => in_array($str('method'), ['yoco', 'eft', 'cash_on_collection'], true) ? $str('method') : '',
        'queue' => in_array($str('queue'), ['pack', 'today', 'weigh', 'unpaid'], true) ? $str('queue') : '',
        'from' => $date('from'),
        'to' => $date('to'),
    ];
    $where = ['o.division_id IN (' . db_placeholders($ids) . ')'];
    $params = $ids;
    if ($f['division']) { $where[] = 'o.division_id = ?'; $params[] = division_id($f['division']); }
    if ($f['status']) { $where[] = 'o.status = ?'; $params[] = $f['status']; }
    if ($f['method']) { $where[] = 'o.payment_method = ?'; $params[] = $f['method']; }
    if ($f['from']) { $where[] = 'o.placed_at >= ?'; $params[] = $f['from'] . ' 00:00:00'; }
    if ($f['to']) { $where[] = 'o.placed_at <= ?'; $params[] = $f['to'] . ' 23:59:59'; }
    if ($f['q'] !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $f['q']) . '%';
        $where[] = '(o.order_no LIKE ? OR o.billing_name LIKE ? OR o.billing_email LIKE ? OR o.billing_phone LIKE ? OR o.billing_company LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like);
    }
    $queueSql = [
        'pack' => "o.status IN ('paid','awaiting_collection','processing')",
        'today' => "(o.collection_date = CURDATE() OR o.delivery_date = CURDATE()) AND o.status NOT IN ('cancelled','expired','refunded','completed','collected')",
        'weigh' => "o.status IN ('reserved','deposit_paid')",
        'unpaid' => "o.payment_status IN ('unpaid','partial') AND o.status NOT IN ('cancelled','expired','refunded')",
    ];
    if ($f['queue']) { $where[] = $queueSql[$f['queue']]; }
    return [$f, $where, $params];
}

/** Documents filter (type, search, dates) → [$f, $where (alias d, orders o), $params]. Void documents are never included. */
function admin_document_filters(array $q, array $ids, array $types): array
{
    $str = static fn(string $k): string => is_string($q[$k] ?? null) ? trim($q[$k]) : '';
    $date = static fn(string $k): string => preg_match('/^\d{4}-\d{2}-\d{2}$/', $str($k)) ? $str($k) : '';
    $f = ['type' => in_array($str('type'), $types, true) ? $str('type') : '', 'q' => mb_substr($str('q'), 0, 60), 'from' => $date('from'), 'to' => $date('to')];
    $where = ['d.division_id IN (' . db_placeholders($ids) . ')', 'd.is_void = 0', 'd.doc_type IN (' . db_placeholders($types) . ')'];
    $params = array_merge($ids, $types);
    if ($f['type']) { $where[] = 'd.doc_type = ?'; $params[] = $f['type']; }
    if ($f['from']) { $where[] = 'd.issued_at >= ?'; $params[] = $f['from'] . ' 00:00:00'; }
    if ($f['to']) { $where[] = 'd.issued_at <= ?'; $params[] = $f['to'] . ' 23:59:59'; }
    if ($f['q'] !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $f['q']) . '%';
        $where[] = '(d.doc_no LIKE ? OR o.order_no LIKE ? OR o.billing_name LIKE ?)';
        array_push($params, $like, $like, $like);
    }
    return [$f, $where, $params];
}
