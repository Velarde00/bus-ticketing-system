<?php
/** includes/sidebar.php  Admin menu with categorized navigation and user status. */
$adminUser = current_user();
$adminRole = $_SESSION['role'] ?? 'ADMIN';
$adminInitials = 'A';
if ($adminUser && !empty($adminUser['name'])) {
    $parts = preg_split('/\s+/', trim($adminUser['name']));
    $adminInitials = strtoupper(substr($parts[0] ?? '', 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
}

$navGroups = [
    'OVERVIEW' => [
        ['dashboard',     'Dashboard',     'admin/dashboard.php',           'bi-speedometer2'],
        ['notifications', 'Notifications', 'admin/notifications/index.php', 'bi-bell'],
    ],
    'OPERATIONS' => [
        ['buses',   'Buses',   'admin/buses/index.php',   'bi-bus-front'],
        ['drivers', 'Drivers', 'admin/drivers/index.php', 'bi-person-vcard'],
        ['routes',  'Routes',  'admin/routes/index.php',  'bi-signpost-split'],
        ['trips',   'Trips',   'admin/trips/index.php',   'bi-calendar-event'],
    ],
    'RESERVATIONS' => [
        ['reservations', 'Reservations',    'admin/reservations/index.php',    'bi-ticket-perforated'],
        ['walk_in',      'Walk-in Booking', 'admin/reservations/walk_in.php',  'bi-person-walking'],
        ['verify',       'Verify Ticket',   'verify.php',                       'bi-patch-check'],
        ['payments',     'Payments',        'admin/payments/index.php',         'bi-cash-coin'],
        ['passengers',   'Passengers',      'admin/passengers/index.php',       'bi-people'],
    ],
    'ANALYTICS & SYSTEM' => [
        ['reports',  'Sales Reports',    'admin/reports/sales.php', 'bi-graph-up-arrow'],
        ['settings', 'Account Settings', 'admin/settings.php',      'bi-gear'],
    ],
];

if ($adminRole === 'SUPER_ADMIN') {
    $navGroups['ANALYTICS & SYSTEM'][] = ['administrators', 'Admin Access', 'admin/administrators.php', 'bi-shield-lock'];
}
?>
<aside class="offcanvas-lg offcanvas-start admin-sidebar" tabindex="-1" id="adminSidebar" aria-label="Admin menu">
    <div class="offcanvas-header d-lg-none border-bottom border-secondary border-opacity-25 pb-3 mb-2">
        <div class="d-flex align-items-center gap-2">
            <span class="brand-icon-wrapper brand-icon-sm">
                <i class="bi bi-bus-front-fill"></i>
            </span>
            <span class="brand-text text-white">
                <?= e(APP_NAME) ?>
                <span class="badge bg-warning text-dark ms-1 small">Admin</span>
            </span>
        </div>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" data-bs-target="#adminSidebar" aria-label="Close"></button>
    </div>

    <div class="offcanvas-body d-flex flex-column p-0 h-100">
        <a class="sidebar-brand d-none d-lg-flex align-items-center gap-2" href="<?= url('admin/dashboard.php') ?>">
            <span class="brand-icon-wrapper">
                <i class="bi bi-bus-front-fill"></i>
            </span>
            <span class="brand-text">
                <?= e(APP_NAME) ?>
                <span class="brand-tag brand-tag-admin"><?= $adminRole === 'SUPER_ADMIN' ? 'Super Admin' : 'Admin Portal' ?></span>
            </span>
        </a>

        <div class="px-3 pt-3 pb-1">
            <a href="<?= url('index.php') ?>" class="btn btn-sm btn-outline-light w-100 d-flex align-items-center justify-content-center gap-2 public-preview-btn">
                <i class="bi bi-box-arrow-up-right"></i>
                <span class="preview-btn-text">View Public Site</span>
            </a>
        </div>

        <nav class="sidebar-nav flex-grow-1 px-2 py-3 overflow-y-auto">
            <?php foreach ($navGroups as $groupTitle => $items): ?>
                <div class="sidebar-heading"><?= e($groupTitle) ?></div>
                <?php foreach ($items as [$key, $label, $href, $icon]): ?>
                    <a href="<?= url($href) ?>" class="sidebar-link <?= ($activePage ?? '') === $key ? 'active' : '' ?>">
                        <i class="bi <?= e($icon) ?> sidebar-icon"></i>
                        <span><?= e($label) ?></span>
                    </a>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </nav>

        <div class="sidebar-footer p-3 border-top border-secondary border-opacity-25">
            <div class="d-flex align-items-center justify-content-between gap-2">
                <div class="d-flex align-items-center gap-2 overflow-hidden">
                    <span class="user-avatar-circle user-avatar-admin"><?= e($adminInitials) ?></span>
                    <div class="text-truncate">
                        <div class="text-white fw-semibold small text-truncate"><?= e($adminUser['name'] ?? 'Administrator') ?></div>
                        <div class="text-white-50 text-truncate" style="font-size: 0.75rem;"><?= e($adminRole === 'SUPER_ADMIN' ? 'Super Admin' : 'Admin') ?></div>
                    </div>
                </div>
                <form method="post" action="<?= url('auth/logout.php') ?>" class="m-0">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-outline-light btn-sm p-1 px-2" title="Sign out" aria-label="Sign out">
                        <i class="bi bi-box-arrow-right"></i>
                    </button>
                </form>
            </div>
        </div>
    </div>
</aside>
