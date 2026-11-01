<?php
/** Admin — admin users and role permissions (owner only). */
declare(strict_types=1);

require __DIR__ . '/../includes/bootstrap.php';
require GX_ROOT . '/includes/layout/admin-shell.php';

$users = db_all('SELECT a.*, r.name AS role_name FROM admin_users a JOIN roles r ON r.id = a.role_id ORDER BY a.id');
$roles = db_all('SELECT * FROM roles ORDER BY id');
$perms = db_all('SELECT * FROM permissions ORDER BY group_name, id');
$has = [];
foreach (db_all('SELECT * FROM role_permissions') as $rp) {
    $has[$rp['role_id']][$rp['permission_id']] = true;
}
admin_start('Admin users', 'users', ['perm' => 'users.manage']);
?>
<div class="adm-grid-2">
  <section class="card card-pad">
    <div class="row-between mb-2"><h2 class="h4">Team</h2><button class="btn btn-primary btn-sm" type="button" data-modal-open="user-modal">＋ Add person</button></div>
    <div class="table-wrap"><table class="table dt dt-cards">
      <thead><tr><th>Name</th><th>Role</th><th>Divisions</th><th>2FA</th><th>Last login</th><th></th></tr></thead>
      <tbody><?php foreach ($users as $u): ?>
        <tr><td data-label=""><strong><?= e($u['name']) ?></strong><br><span class="tiny dim"><?= e($u['email']) ?></span></td><td data-label=""><?= e($u['role_name']) ?></td>
          <td data-label=""><?= e(ucfirst($u['division_scope'])) ?></td><td data-label="2FA"><?= (int) $u['totp_enabled'] ? '✓' : '—' ?></td><td data-label=""><?= e(fmt_datetime($u['last_login_at'])) ?></td>
          <td class="actions"><?php if ($u['role_name'] !== 'Owner' || count(array_filter($users, static fn($x) => $x['role_name'] === 'Owner')) > 1): ?>
            <button class="btn btn-sm<?= $u['status'] === 'active' ? ' btn-danger' : '' ?>" type="button" data-admin-act="/admin/api/users.php" data-payload='<?= e(json_encode(['action' => 'toggle', 'id' => (int) $u['id']])) ?>' data-confirm="<?= $u['status'] === 'active' ? 'Disable' : 'Enable' ?> <?= e($u['name']) ?>?"><?= $u['status'] === 'active' ? 'Disable' : 'Enable' ?></button><?php endif; ?>
            <?php if ((int) $u['totp_enabled']): ?><button class="btn btn-sm btn-ghost" type="button" data-admin-act="/admin/api/users.php" data-payload='<?= e(json_encode(['action' => 'reset_2fa', 'id' => (int) $u['id']])) ?>' data-confirm="Reset 2FA for <?= e($u['name']) ?>?">Reset 2FA</button><?php endif; ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
  </section>
  <section class="card card-pad">
    <h2 class="h4 mb-1">What each role may do</h2>
    <form data-admin-form data-api="/admin/api/users.php" data-action="perms">
      <div class="table-wrap"><table class="table perm-matrix">
        <thead><tr><th>Permission</th><?php foreach ($roles as $r): ?><th><?= e($r['name']) ?></th><?php endforeach; ?></tr></thead>
        <tbody><?php foreach ($perms as $p): ?>
          <tr><td class="small"><?= e($p['name']) ?></td><?php foreach ($roles as $r): ?>
            <td><?php if ($r['slug'] === 'owner'): ?>✓<?php else: ?><input type="checkbox" name="perm[<?= (int) $r['id'] ?>][]" value="<?= (int) $p['id'] ?>" <?= isset($has[$r['id']][$p['id']]) ? 'checked' : '' ?> aria-label="<?= e($r['name'] . ': ' . $p['name']) ?>"><?php endif; ?></td>
          <?php endforeach; ?></tr>
        <?php endforeach; ?></tbody>
      </table></div>
      <button class="btn btn-primary btn-sm mt-2" type="submit">Save permissions</button>
    </form>
  </section>
</div>
<dialog class="modal" id="user-modal">
  <form data-modal-form data-api="/admin/api/users.php" data-action="create" novalidate>
    <div class="modal-head"><h3>Add admin user</h3><button class="nav-icon-btn" type="button" data-modal-close>✕</button></div>
    <div class="modal-body">
      <?= af_text('name', 'Name', '', ['required' => true]) ?>
      <?= af_text('email', 'Email', '', ['type' => 'email', 'required' => true]) ?>
      <?= af_select('role_id', 'Role', array_column($roles, 'name', 'id'), 3) ?>
      <?= af_select('division_scope', 'Can see', ['all' => 'Feeds and Ranch', 'feeds' => 'Feeds only', 'ranch' => 'Ranch only'], 'all') ?>
      <?= af_text('password', 'Temporary password (min ' . GX_PASSWORD_MIN . ')', '', ['type' => 'password', 'required' => true, 'hint' => 'Share it privately; they should change it on first login.']) ?>
    </div>
    <div class="modal-foot"><button class="btn btn-ghost" type="button" data-modal-close>Cancel</button><button class="btn btn-primary" type="submit">Add</button></div>
  </form>
</dialog>
<?php admin_end(); ?>
