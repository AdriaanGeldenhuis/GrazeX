<?php
/**
 * Admin — "Lists & setup": generic editor for simple tables (see includes/admin_entities.php).
 *   /admin/manage.php            → index of all lists
 *   /admin/manage.php?e=faqs     → list rows
 *   /admin/manage.php?e=faqs&id= → edit (id=0 → new)
 */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require GX_ROOT . '/includes/layout/admin-shell.php';

$entities = require GX_ROOT . '/includes/admin_entities.php';
$key = query_str('e');
$ent = $entities[$key] ?? null;

if (!$ent) {
    admin_start('Lists & setup', 'setup');
    $groups = [];
    foreach ($entities as $k => $en) {
        if (admin_can($en['perm'])) {
            $groups[$en['group']][$k] = $en;
        }
    }
    foreach ($groups as $g => $list): ?>
      <section class="adm-section"><h2><?= e($g) ?></h2><div class="todo">
        <?php foreach ($list as $k => $en): $n = (int) db_val('SELECT COUNT(*) FROM ' . $en['table']); ?>
          <a href="/admin/manage.php?e=<?= e($k) ?>"><span class="t-num"><?= $n ?></span><span><span class="t-label"><?= e($en['title']) ?></span></span></a>
        <?php endforeach; ?>
      </div></section>
    <?php endforeach;
    admin_end();
    exit;
}

$id = isset($_GET['id']) ? query_int('id') : null;
$fmt = static function (string $col, mixed $v, array $field) {
    $type = $field[0] ?? 'text';
    if ($v === null || $v === '') {
        return '<span class="dim">—</span>';
    }
    return match ($type) {
        'money' => e(money((int) $v)),
        'bool' => (int) $v ? '<span class="badge badge-success">Yes</span>' : '<span class="badge">No</span>',
        'select' => e($field['options'][(string) $v] ?? $v),
        'fk' => e((string) db_val('SELECT ' . $field['fk'][1] . ' FROM ' . $field['fk'][0] . ' WHERE id = ?', [(int) $v])),
        'weekdays' => e(implode(', ', array_map(static fn($d) => DeliveryService::WEEKDAYS[(int) $d] ?? '', explode(',', (string) $v)))),
        'image' => ($u = media_url((int) $v, 480, 'wide')) ? '<div class="thumb"><img src="' . e($u) . '" alt=""></div>' : '—',
        'time' => e(substr((string) $v, 0, 5)),
        default => e(str_limit((string) $v, 70)),
    };
};

admin_start($ent['title'], 'setup', ['perm' => $ent['perm']]);

if ($id === null):
    $rows = db_all('SELECT * FROM ' . $ent['table'] . ' ORDER BY ' . $ent['order'] . ' LIMIT 500'); ?>
  <div class="adm-bar">
    <a class="btn btn-sm btn-ghost" href="/admin/manage.php"><svg class="icon icon-sm"><use href="#i-arrow-left"/></svg>All lists</a>
    <?php if (empty($ent['no_create'])): ?><a class="btn btn-primary" href="/admin/manage.php?e=<?= e($key) ?>&amp;id=0"><svg class="icon"><use href="#i-plus"/></svg>Add</a><?php endif; ?>
  </div>
  <div class="table-wrap"><table class="table dt dt-cards">
    <thead><tr><?php foreach ($ent['list'] as $col): ?><th><?= e($ent['fields'][$col][1] ?? ucfirst(str_replace('_', ' ', $col))) ?></th><?php endforeach; ?><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <?php foreach ($ent['list'] as $i => $col): ?>
          <td data-label="<?= $i ? e($ent['fields'][$col][1] ?? $col) : '' ?>"><?= $i === 0 ? '<a class="row-link" href="/admin/manage.php?e=' . e($key) . '&amp;id=' . (int) $r['id'] . '">' . $fmt($col, $r[$col] ?? null, $ent['fields'][$col] ?? ['text']) . '</a>' : $fmt($col, $r[$col] ?? null, $ent['fields'][$col] ?? ['text']) ?></td>
        <?php endforeach; ?>
        <td class="actions"><a class="btn btn-sm" href="/admin/manage.php?e=<?= e($key) ?>&amp;id=<?= (int) $r['id'] ?>">Edit</a></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="<?= count($ent['list']) + 1 ?>" class="muted">Nothing here yet.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
<?php else:
    $row = $id ? db_one('SELECT * FROM ' . $ent['table'] . ' WHERE id = ?', [$id]) : [];
    if ($id && !$row) {
        echo '<p>Not found.</p>';
        admin_end();
        exit;
    } ?>
  <form data-admin-form data-api="/admin/api/manage.php" data-action="save" novalidate>
    <input type="hidden" name="e" value="<?= e($key) ?>"><input type="hidden" name="id" value="<?= (int) $id ?>">
    <div class="adm-bar">
      <a class="btn btn-sm btn-ghost" href="/admin/manage.php?e=<?= e($key) ?>"><svg class="icon icon-sm"><use href="#i-arrow-left"/></svg><?= e($ent['title']) ?></a>
      <?php if ($id && empty($ent['no_delete'])): ?>
        <button class="btn btn-sm btn-danger" type="button" data-admin-act="/admin/api/manage.php" data-payload='<?= e(json_encode(['action' => 'delete', 'e' => $key, 'id' => $id])) ?>' data-confirm="Delete this item?" data-ok="Delete" data-danger>Delete</button>
      <?php endif; ?>
    </div>
    <section class="card card-pad">
      <div class="form-grid">
      <?php foreach ($ent['fields'] as $name => $f):
          [$type, $label] = $f;
          $val = $row[$name] ?? ($type === 'bool' ? 1 : '');
          $opts = ['readonly' => !empty($f['readonly']), 'hint' => $f['hint'] ?? null, 'required' => str_contains($f[2] ?? '', 'required')];
          $opts = array_filter($opts, static fn($v) => $v !== null && $v !== false);
          switch ($type) {
              case 'textarea': echo af_textarea($name, $label, (string) $val, $opts + ['span' => true]); break;
              case 'markdown': echo af_markdown($name, $label, (string) $val, $opts + ['span' => true, 'rows' => 10]); break;
              case 'number': echo af_text($name, $label, (string) $val, $opts + ['type' => 'number']); break;
              case 'money': echo af_money($name, $label, $val === '' || $val === null ? null : (int) $val, $opts); break;
              case 'bool': echo af_check($name, $label, (bool) $val); break;
              case 'select': echo af_select($name, $label, $f['options'], (string) $val, $opts); break;
              case 'fk': echo af_select($name, $label, array_column(db_all('SELECT id, ' . $f['fk'][1] . ' AS l FROM ' . $f['fk'][0] . ' ORDER BY l'), 'l', 'id'), (string) $val, $opts + ['empty' => 'Choose…']); break;
              case 'date': echo af_text($name, $label, $val ? substr((string) $val, 0, 10) : '', $opts + ['type' => 'date']); break;
              case 'time': echo af_text($name, $label, $val ? substr((string) $val, 0, 5) : '', $opts + ['type' => 'time']); break;
              case 'image': echo af_image($name, $label, $val ? (int) $val : null, $opts); break;
              case 'slug': echo af_text($name, $label, (string) $val, $opts + ['data' => ['slug-from' => af_id($f['from'])]]); break;
              case 'code': echo af_text($name, $label, (string) $val, $opts + ['data' => ['upper' => '1']]); break;
              case 'weekdays':
                  $sel = array_filter(explode(',', (string) $val));
                  echo '<div class="field span-2"><span class="label">' . e($label) . '</span><div class="chips">';
                  foreach (DeliveryService::WEEKDAYS as $n => $dname) {
                      echo '<label class="chip"><input type="checkbox" name="' . e($name) . '[]" value="' . $n . '"' . (in_array((string) $n, $sel, true) ? ' checked' : '') . '> ' . e($dname) . '</label>';
                  }
                  echo '</div></div>';
                  break;
              case 'lines':
                  $c = $f['child'];
                  $lines = $id ? implode("\n", db_col("SELECT {$c['col']} FROM {$c['table']} WHERE {$c['fk']} = ? ORDER BY {$c['col']}", [$id])) : '';
                  echo af_textarea($name, $label, $lines, ['span' => true, 'rows' => 6]);
                  break;
              default: echo af_text($name, $label, (string) $val, $opts);
          }
      endforeach; ?>
      </div>
    </section>
    <div class="save-bar"><a class="btn btn-ghost" href="/admin/manage.php?e=<?= e($key) ?>">Cancel</a><button class="btn btn-primary btn-lg" type="submit">Save</button></div>
  </form>
<?php endif;
admin_end();
