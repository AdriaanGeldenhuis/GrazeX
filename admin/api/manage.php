<?php
/**
 * Generic CRUD API for "Lists & setup" entities. {action: save, e, id, ...fields} · {action: delete, e, id}
 * Only tables/columns declared in includes/admin_entities.php can be touched.
 */
declare(strict_types=1);

define('GX_IS_API', true);
require __DIR__ . '/../../includes/bootstrap.php';
require_once GX_ROOT . '/includes/admin_form.php';

api_post();
$entities = require GX_ROOT . '/includes/admin_entities.php';
$key = (string) input('e', '');
$ent = $entities[$key] ?? null;
if (!$ent) {
    json_error('Unknown list.', 404);
}
$admin = require_admin_api($ent['perm']);
$table = $ent['table'];
$id = (int) input('id', 0);
$in = input_all();

if (($in['action'] ?? '') === 'delete') {
    if (!empty($ent['no_delete'])) {
        json_error('These items cannot be deleted.', 409);
    }
    try {
        db_exec("DELETE FROM {$table} WHERE id = ?", [$id]);
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) === 1451) {
            json_error('This item is still in use (e.g. by products or orders) — hide it instead.', 409);
        }
        throw $e;
    }
    audit('manage.deleted', $table, $id);
    json_ok(['redirect' => '/admin/manage.php?e=' . $key], 'Deleted.');
}
if (!$id && !empty($ent['no_create'])) {
    json_error('New items cannot be added here.', 409);
}

// Build validation rules + values per field type
$rules = [];
foreach ($ent['fields'] as $name => $f) {
    if (in_array($f[0], ['money', 'bool', 'weekdays', 'lines', 'image', 'slug'], true) || !empty($f['readonly'])) {
        continue;
    }
    $rules[$name] = $f[2] ?? 'nullable';
}
$d = validate_or_fail($in, $rules);
$cols = [];
$vals = [];
$children = [];
try {
    foreach ($ent['fields'] as $name => $f) {
        if (!empty($f['readonly'])) {
            continue;
        }
        $type = $f[0];
        $v = match ($type) {
            'money' => af_cents($in[$name] ?? ''),
            'bool' => af_bool($in[$name] ?? 0),
            'weekdays' => implode(',', array_values(array_unique(array_filter(array_map('intval', (array) ($in[$name] ?? [])), static fn($n) => $n >= 1 && $n <= 7)))),
            'image' => ((int) ($in[$name] ?? 0)) ?: null,
            'slug' => slugify((string) (($in[$name] ?? '') ?: ($in[$f['from']] ?? 'item'))),
            'code' => strtoupper((string) ($d[$name] ?? '')),
            'date' => $d[$name] ?: null,
            'lines' => null,
            default => $d[$name] ?? null,
        };
        if ($type === 'money' && $v === null && str_contains($f[2] ?? '', 'required')) {
            throw new DomainException($f[1] . ' is required.');
        }
        if ($type === 'image' && $v === null && str_contains($f[2] ?? '', 'required')) {
            throw new DomainException('Please choose a photo.');
        }
        if ($type === 'weekdays' && $v === '' && str_contains($f[2] ?? '', 'required')) {
            throw new DomainException('Choose at least one day.');
        }
        if ($type === 'lines') {
            $children[] = [$f['child'], array_values(array_unique(array_filter(array_map('trim', preg_split('/\R/', (string) ($in[$name] ?? '')) ?: []))))];
            continue;
        }
        if ($type === 'select' && ($v === '' || $v === null) && !str_contains($f[2] ?? '', 'required')) {
            $v = null;
        }
        if ($type === 'number' && $v === null && in_array($name, ['sort_order', 'lead_days'], true)) {
            $v = 0;
        }
        if (in_array($name, ['min_spend_cents'], true) && $v === null) {
            $v = 0;
        }
        if ($name === 'min_order_cents' && $v === null) {
            $v = 0;
        }
        $cols[] = $name;
        $vals[] = $v;
    }
} catch (DomainException $e) {
    json_error($e->getMessage(), 422);
}

try {
    $id = db_tx(static function () use ($table, $cols, $vals, $id, $children): int {
        if ($id) {
            db_exec("UPDATE {$table} SET " . implode(', ', array_map(static fn($c) => "`{$c}` = ?", $cols)) . ' WHERE id = ?', array_merge($vals, [$id]));
        } else {
            $id = db_insert("INSERT INTO {$table} (`" . implode('`, `', $cols) . '`) VALUES (' . db_placeholders($cols) . ')', $vals);
        }
        foreach ($children as [$c, $lines]) {
            db_exec("DELETE FROM {$c['table']} WHERE {$c['fk']} = ?", [$id]);
            foreach ($lines as $line) {
                db_exec("INSERT INTO {$c['table']} ({$c['fk']}, {$c['col']}) VALUES (?, ?)", [$id, mb_substr($line, 0, 120)]);
            }
        }
        return $id;
    });
} catch (PDOException $e) {
    if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
        json_error('That value is already used by another item (e.g. same name, code, town or web address).', 422);
    }
    throw $e;
}
audit('manage.saved', $table, $id);
json_ok(['redirect' => '/admin/manage.php?e=' . $key], 'Saved.');
