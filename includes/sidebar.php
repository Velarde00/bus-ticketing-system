<?php
/** includes/sidebar.php  Admin menu. $activePage decides which link is highlighted. */
$items = [
    ['dashboard',    'Dashboard',           'admin/dashboard.php',          'bi-speedometer2'],
    ['buses',        'Manage Buses',        'admin/buses/index.php',        'bi-bus-front'],
    ['drivers',      'Manage Drivers',      'admin/drivers/index.php',      'bi-person-vcard'],
    ['routes',       'Manage Routes',       'admin/routes/index.php',       'bi-signpost-split'],
    ['trips',        'Manage Trips',        'admin/trips/index.php',        'bi-calendar-event'],
    ['passengers',   'Manage Passengers',   'admin/passengers/index.php',   'bi-people'],
    ['reservations', 'Manage Reservations', 'admin/reservations/index.php', 'bi-ticket-perforated'],
    ['payments',     'Manage Payments',     'admin/payments/index.php',     'bi-cash-coin'],
    ['reports',      'Reports',             'admin/reports/sales.php',      'bi-graph-up'],
    ['settings',     'Account settings',    'admin/settings.php',           'bi-gear'],
];
if (($_SESSION['role'] ?? '') === 'SUPER_ADMIN') {
    $items[] = ['administrators', 'Manage Administrators', 'admin/administrators.php', 'bi-shield-lock'];
}
?>
<aside class="offcanvas-lg offcanvas-start admin-sidebar" tabindex="-1" id="adminSidebar" aria-label="Admin menu">
    <div class="offcanvas-header d-lg-none">
        <span class="brand-mark text-white"><?= e(APP_NAME) ?></span>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#adminSidebar" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body d-flex flex-column p-0">
        <a class="sidebar-brand d-none d-lg-flex" href="<?= url('admin/dashboard.php') ?>">
            <i class="bi bi-bus-front-fill"></i> <?= e(APP_NAME) ?>
            <small>Admin</small>
        </a>
        <nav class="sidebar-nav flex-grow-1">
            <?php foreach ($items as [$key, $label, $href, $icon]): ?>
                <a href="<?= url($href) ?>" class="sidebar-link <?= $activePage === $key ? 'active' : '' ?>">
                    <i class="bi <?= e($icon) ?>"></i> <?= e($label) ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <form method="post" action="<?= url('auth/logout.php') ?>" class="sidebar-logout">
            <?= csrf_field() ?>
            <button type="submit" class="sidebar-link w-100 border-0 bg-transparent text-start">
                <i class="bi bi-box-arrow-left"></i> Logout
            </button>
        </form>
    </div>
</aside>
