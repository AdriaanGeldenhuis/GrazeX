<?php
/**
 * Admin layout helpers:
 *   admin_start('Orders', 'orders', ['js' => ['/admin/js/admin-orders.js']]);
 *   ... page content ...
 *   admin_end();
 */
declare(strict_types=1);

defined('GX_ROOT') || exit;

require_once GX_ROOT . '/includes/admin_form.php';

const GX_ADMIN_NAV = [
    ['dashboard', '/admin/', 'Dashboard', 'grid', 'dashboard.view'],
    ['orders', '/admin/orders.php', 'Orders', 'bag', 'orders.view'],
    ['payments', '/admin/payments.php', 'Payments', 'card', 'payments.verify'],
    ['products', '/admin/products.php', 'Feeds products', 'leaf', 'products.manage'],
    ['lots', '/admin/lots.php', 'Livestock', 'sheep', 'lots.manage'],
    ['inventory', '/admin/inventory.php', 'Inventory', 'scale', 'inventory.manage'],
    ['customers', '/admin/customers.php', 'Customers', 'user', 'customers.view'],
    ['documents', '/admin/documents.php', 'Documents', 'file', 'documents.view'],
    ['messages', '/admin/messages.php', 'Messages', 'mail', 'messages.manage'],
    ['content', '/admin/content.php', 'Site content', 'spark', 'content.manage'],
    ['media', '/admin/media.php', 'Media', 'grid', 'media.manage'],
    ['setup', '/admin/manage.php', 'Lists & setup', 'tag', 'settings.manage'],
    ['reports', '/admin/reports.php', 'Reports', 'chart', 'reports.view'],
    ['settings', '/admin/settings.php', 'Settings', 'settings', 'settings.manage'],
    ['users', '/admin/users.php', 'Admin users', 'shield', 'users.manage'],
    ['audit', '/admin/audit.php', 'Audit log', 'clock', 'audit.view'],
];

/** Counters shown as badges on the nav (cheap queries). */
function admin_badges(): array
{
    static $b = null;
    if ($b === null) {
        $b = [
            'payments' => (int) db_val("SELECT COUNT(*) FROM payments WHERE status = 'awaiting_verification'"),
            'orders' => (int) db_val("SELECT COUNT(*) FROM orders WHERE status IN ('paid','awaiting_collection','deposit_paid','processing')"),
            'messages' => (int) db_val("SELECT COUNT(*) FROM enquiries WHERE status = 'new'"),
            'bell' => (int) db_val('SELECT COUNT(*) FROM admin_notifications WHERE is_read = 0 AND (division_id IS NULL OR division_id IN ('
                . db_placeholders(admin_division_ids()) . '))', admin_division_ids()),
        ];
    }
    return $b;
}

function admin_start(string $title, string $active, array $opts = []): array
{
    $admin = require_admin($opts['perm'] ?? null);
    global $page;
    $page = [
        'title' => $title . ' · Admin', 'division' => 'admin', 'noindex' => true, 'hide_fab' => true, 'hide_footer' => true,
        'css' => array_merge(['/admin/css/admin.css'], $opts['css'] ?? []),
        'js' => array_merge(['/admin/js/admin.js'], $opts['js'] ?? []),
        'body_class' => 'admin-body',
    ];
    require GX_ROOT . '/includes/layout/head.php';
    $badges = admin_badges();
    ?>
<div class="adm">
  <aside class="adm-side" aria-label="Admin navigation">
    <a class="adm-brand" href="/admin/"><span class="brand"><span>Graze<b>X</b></span></span><span class="badge">Admin</span></a>
    <nav class="adm-nav">
      <?php foreach (GX_ADMIN_NAV as [$key, $href, $label, $icon, $perm]): if (!admin_can($perm) || ($key === 'users' && $admin['role_slug'] !== 'owner')) continue; ?>
        <a href="<?= e($href) ?>"<?= $active === $key ? ' aria-current="page"' : '' ?>>
          <svg class="icon icon-sm"><use href="#i-<?= e($icon) ?>"/></svg><span><?= e($label) ?></span>
          <?php if (!empty($badges[$key])): ?><span class="adm-count"><?= (int) $badges[$key] ?></span><?php endif; ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="adm-side-foot">
      <a href="/" target="_blank" rel="noopener"><svg class="icon icon-sm"><use href="#i-arrow"/></svg>View website</a>
    </div>
  </aside>

  <div class="adm-main">
    <header class="adm-top">
      <button class="nav-icon-btn adm-menu-btn" type="button" data-adm-menu aria-label="Menu"><svg class="icon"><use href="#i-menu"/></svg></button>
      <h1 class="adm-title"><?= e($title) ?></h1>
      <div class="adm-top-actions">
        <form class="adm-search" action="/admin/search.php" method="get" role="search">
          <svg class="icon icon-sm"><use href="#i-search"/></svg>
          <input class="input" type="search" name="q" placeholder="Order no, customer, product… (/)" aria-label="Search admin" data-adm-search>
        </form>
        <button class="nav-icon-btn" type="button" data-adm-bell aria-label="Notifications">
          <svg class="icon"><use href="#i-bell"/></svg>
          <?php if ($badges['bell']): ?><span class="cart-count has-items"><?= min(99, $badges['bell']) ?></span><?php endif; ?>
        </button>
        <a class="adm-me" href="/admin/profile.php" title="My profile"><span><?= e(initials($admin['name'])) ?></span></a>
      </div>
    </header>
    <div class="adm-bell-panel card" data-adm-bell-panel hidden></div>
    <main id="main" class="adm-content">
    <?php if ($gxProblems = gx_settings_problems()): ?>
      <div class="alert alert-danger mb-3" role="alert"><strong>Settings file incomplete:</strong> add <?= e(implode(', ', $gxProblems)) ?> to <code>.env.php</code> / <code>.env</code> on the server.
        Password-reset links, saved secrets and links in emails depend on it.</div>
    <?php endif; ?>
<?php
    return $admin;
}

function admin_end(): void
{
    global $page;
    $badges = admin_badges();
    ?>
    </main>
  </div>

  <nav class="adm-bottom" aria-label="Quick navigation">
    <a href="/admin/"><svg class="icon"><use href="#i-grid"/></svg>Home</a>
    <a href="/admin/orders.php"><svg class="icon"><use href="#i-bag"/></svg>Orders<?php if ($badges['orders']): ?><span class="adm-count"><?= (int) $badges['orders'] ?></span><?php endif; ?></a>
    <a href="/admin/payments.php"><svg class="icon"><use href="#i-card"/></svg>Payments<?php if ($badges['payments']): ?><span class="adm-count"><?= (int) $badges['payments'] ?></span><?php endif; ?></a>
    <a href="/admin/inventory.php"><svg class="icon"><use href="#i-scale"/></svg>Stock</a>
    <button type="button" data-adm-menu><svg class="icon"><use href="#i-menu"/></svg>More</button>
  </nav>
  <div class="adm-scrim" data-adm-menu></div>
</div>
<?php
    require GX_ROOT . '/includes/layout/footer.php';
}

/** Small helper: status badge HTML for an order. */
function admin_status_badge(string $status): string
{
    [$label, $cls] = OrderService::label($status);
    return '<span class="badge ' . e($cls) . '">' . e($label) . '</span>';
}

/** Division chip (Feeds / Ranch). */
function admin_div_chip(int $divisionId): string
{
    return $divisionId === division_id('ranch')
        ? '<span class="badge badge-success">Ranch</span>'
        : '<span class="badge badge-accent">Feeds</span>';
}

// Every admin page includes this file: require a logged-in admin before any page queries run.
require_admin();
