<?php
/**
 * Delivery zones (Feeds) and collection slots (Feeds + Ranch).
 */
declare(strict_types=1);

defined('GX_ROOT') || exit;

final class DeliveryService
{
    public const WEEKDAYS = [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'];

    /** Active zone for a town (case-insensitive exact match), or null. */
    public static function zoneForTown(string $town): ?array
    {
        $town = trim($town);
        if ($town === '') {
            return null;
        }
        $z = db_one('SELECT z.* FROM delivery_zone_towns t JOIN delivery_zones z ON z.id = t.zone_id
                     WHERE z.is_active = 1 AND LOWER(t.town) = LOWER(?) LIMIT 1', [$town]);
        return $z ?: null;
    }

    /** Town suggestions for autocomplete. */
    public static function suggestTowns(string $q, int $limit = 8): array
    {
        $q = trim($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        return db_col('SELECT t.town FROM delivery_zone_towns t JOIN delivery_zones z ON z.id = t.zone_id
                       WHERE z.is_active = 1 AND t.town LIKE ? ORDER BY t.town LIMIT ' . (int) $limit,
            [str_replace(['%', '_'], ['\%', '\_'], $q) . '%']);
    }

    public static function allTowns(): array
    {
        return db_col('SELECT t.town FROM delivery_zone_towns t JOIN delivery_zones z ON z.id = t.zone_id WHERE z.is_active = 1 ORDER BY t.town');
    }

    /** Delivery fee for a zone and order subtotal (after discount). */
    public static function fee(array $zone, int $subtotal): int
    {
        if ($zone['free_over_cents'] !== null && $subtotal >= (int) $zone['free_over_cents']) {
            return 0;
        }
        return (int) $zone['fee_cents'];
    }

    /** Public summary of a zone for the UI. */
    public static function describe(array $zone): array
    {
        $days = array_map(static fn($d) => self::WEEKDAYS[(int) $d] ?? '', array_filter(explode(',', (string) $zone['delivery_days'])));
        return [
            'id' => (int) $zone['id'],
            'name' => $zone['name'],
            'fee' => (int) $zone['fee_cents'],
            'fee_label' => money((int) $zone['fee_cents']),
            'free_over' => $zone['free_over_cents'] !== null ? (int) $zone['free_over_cents'] : null,
            'free_over_label' => $zone['free_over_cents'] !== null ? money((int) $zone['free_over_cents']) : null,
            'min_order' => (int) $zone['min_order_cents'],
            'min_order_label' => money((int) $zone['min_order_cents']),
            'days' => array_values($days),
            'next_dates' => self::deliveryDates($zone, 4),
        ];
    }

    /** Is a date blacked out for a division (or globally)? */
    public static function isBlackout(string $date, int $divisionId): bool
    {
        return (bool) db_val('SELECT 1 FROM blackout_dates WHERE blackout_on = ? AND (division_id IS NULL OR division_id = ?) LIMIT 1', [$date, $divisionId]);
    }

    /** Next N delivery dates for a zone (Y-m-d). */
    public static function deliveryDates(array $zone, int $count = 6): array
    {
        $days = array_map('intval', array_filter(explode(',', (string) $zone['delivery_days'])));
        if (!$days) {
            return [];
        }
        $out = [];
        $ts = strtotime('today +' . max(1, (int) $zone['lead_days']) . ' days');
        for ($i = 0; $i < 60 && count($out) < $count; $i++, $ts += 86400) {
            $d = date('Y-m-d', $ts);
            if (in_array((int) date('N', $ts), $days, true) && !self::isBlackout($d, division_id('feeds'))) {
                $out[] = $d;
            }
        }
        return $out;
    }

    /** Active collection point for a division (first one). */
    public static function collectionPoint(int $divisionId): ?array
    {
        return db_one('SELECT * FROM collection_points WHERE division_id = ? AND is_active = 1 ORDER BY sort_order, id LIMIT 1', [$divisionId]);
    }

    /**
     * Collection dates with slots and remaining capacity for the next N days.
     * Returns [ ['date' => 'Y-m-d', 'label' => 'Mon 3 Nov', 'slots' => [ ['id', 'label', 'left'] ]] ]
     */
    public static function collectionCalendar(int $divisionId, int $days = 14): array
    {
        $point = self::collectionPoint($divisionId);
        if (!$point) {
            return [];
        }
        $slots = db_all('SELECT * FROM collection_slots WHERE collection_point_id = ? AND is_active = 1 ORDER BY weekday, start_time', [$point['id']]);
        if (!$slots) {
            return [];
        }
        $byDay = [];
        foreach ($slots as $s) {
            $byDay[(int) $s['weekday']][] = $s;
        }
        $from = date('Y-m-d');
        $to = date('Y-m-d', strtotime("+{$days} days"));
        $booked = [];
        foreach (db_all("SELECT collection_date, collection_slot_id, COUNT(*) n FROM orders
                         WHERE division_id = ? AND collection_date BETWEEN ? AND ? AND status NOT IN ('cancelled','expired','refunded')
                         GROUP BY collection_date, collection_slot_id", [$divisionId, $from, $to]) as $b) {
            $booked[$b['collection_date'] . '|' . $b['collection_slot_id']] = (int) $b['n'];
        }
        $nowTs = time();
        $out = [];
        for ($i = 0; $i <= $days; $i++) {
            $ts = strtotime("today +{$i} days");
            $date = date('Y-m-d', $ts);
            $wd = (int) date('N', $ts);
            if (empty($byDay[$wd]) || self::isBlackout($date, $divisionId)) {
                continue;
            }
            $daySlots = [];
            foreach ($byDay[$wd] as $s) {
                // Same-day slots need at least 2 hours' notice before the slot ends
                if ($i === 0 && strtotime($date . ' ' . $s['end_time']) - 7200 < $nowTs) {
                    continue;
                }
                $left = (int) $s['capacity'] - ($booked[$date . '|' . $s['id']] ?? 0);
                $daySlots[] = ['id' => (int) $s['id'], 'label' => substr($s['start_time'], 0, 5) . '–' . substr($s['end_time'], 0, 5), 'left' => max(0, $left)];
            }
            if ($daySlots) {
                $out[] = ['date' => $date, 'label' => date('D j M', $ts), 'slots' => $daySlots];
            }
        }
        return $out;
    }

    /** Validate a chosen collection slot; returns the slot row or null. */
    public static function validateSlot(int $divisionId, string $date, int $slotId): ?array
    {
        foreach (self::collectionCalendar($divisionId, max(1, setting_int('max_collection_days_ahead', 30))) as $day) {
            if ($day['date'] !== $date) {
                continue;
            }
            foreach ($day['slots'] as $s) {
                if ($s['id'] === $slotId && $s['left'] > 0) {
                    return db_one('SELECT * FROM collection_slots WHERE id = ?', [$slotId]);
                }
            }
        }
        return null;
    }
}
