<?php
/** includes/sidebar.php  Admin menu with categorized collapsible navigation and user status. */
$adminUser = current_user();
$adminRole = $_SESSION['role'] ?? 'ADMIN';
$adminInitials = 'A';
$adminUnreadNotifs = 0;

if ($adminUser && !empty($adminUser['name'])) {
    $parts = preg_split('/\s+/', trim($adminUser['name']));
    $adminInitials = strtoupper(substr($parts[0] ?? '', 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
    
    try {
        $aStmt = $pdo->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = :uid AND read_at IS NULL');
        $aStmt->execute([':uid' => (int)$adminUser['id']]);
        $adminUnreadNotifs = (int)$aStmt->fetchColumn();
    } catch (Throwable) {
        $adminUnreadNotifs = 0;
    }
}

$navSections = [
    'overview' => [
        'title' => 'Overview',
        'icon'  => 'bi-speedometer2',
        'items' => [
            ['dashboard',     'Dashboard',     'admin/dashboard.php',           'bi-speedometer2'],
            ['notifications', 'Notifications', 'admin/notifications/index.php', 'bi-bell'],
        ],
    ],
    'operations' => [
        'title' => 'Operations',
        'icon'  => 'bi-gear-wide-connected',
        'items' => [
            ['buses',   'Buses',   'admin/buses/index.php',   'bi-bus-front'],
            ['drivers', 'Drivers', 'admin/drivers/index.php', 'bi-person-vcard'],
            ['routes',  'Routes',  'admin/routes/index.php',  'bi-signpost-split'],
            ['trips',   'Trips',   'admin/trips/index.php',   'bi-calendar-event'],
        ],
    ],
    'reservations' => [
        'title' => 'Reservations',
        'icon'  => 'bi-ticket-perforated',
        'items' => [
            ['reservations', 'Reservations',    'admin/reservations/index.php',    'bi-ticket-perforated'],
            ['walk_in',      'Walk-in Booking', 'admin/reservations/walk_in.php',  'bi-person-walking'],
            ['verify',       'Verify Ticket',   'verify.php',                       'bi-patch-check'],
            ['payments',     'Payments',        'admin/payments/index.php',         'bi-cash-coin'],
            ['refunds',      'Refunds',         'admin/refunds/index.php',          'bi-arrow-counterclockwise'],
            ['passengers',   'Passengers',      'admin/passengers/index.php',       'bi-people'],
        ],
    ],
    'analytics' => [
        'title' => 'Analytics & System',
        'icon'  => 'bi-graph-up-arrow',
        'items' => [
            ['reports',  'Sales Reports',    'admin/reports/sales.php', 'bi-graph-up-arrow'],
            ['settings', 'Account Settings', 'admin/settings.php',      'bi-gear'],
        ],
    ],
];

if ($adminRole === 'SUPER_ADMIN') {
    $navSections['analytics']['items'][] = ['administrators', 'Admin Access', 'admin/administrators.php', 'bi-shield-lock'];
}

$curPage = $activePage ?? '';
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

        <nav class="sidebar-nav flex-grow-1 px-2 py-3 overflow-y-auto" id="sidebarAccordion">
            <?php foreach ($navSections as $secKey => $sec): ?>
                <?php
                    $isGroupActive = false;
                    foreach ($sec['items'] as [$key]) {
                        if ($curPage === $key) {
                            $isGroupActive = true;
                            break;
                        }
                    }
                    $collapseId = 'sidebar-sec-' . $secKey;
                ?>
                <div class="sidebar-section-group mb-2">
                    <button class="sidebar-section-toggle btn w-100 text-start d-flex align-items-center justify-content-between px-3 py-2 text-uppercase fw-bold text-white-50 <?= $isGroupActive ? 'active-section' : '' ?>"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#<?= $collapseId ?>"
                            aria-expanded="<?= $isGroupActive ? 'true' : 'false' ?>"
                            aria-controls="<?= $collapseId ?>"
                            style="font-size: 0.72rem; letter-spacing: 0.08em; border: none; background: transparent;">
                        <span>
                            <i class="bi <?= e($sec['icon']) ?> me-2 opacity-75"></i><?= e($sec['title']) ?>
                            <?php if ($secKey === 'overview' && $adminUnreadNotifs > 0): ?>
                                <span class="badge bg-danger rounded-pill ms-1" style="font-size: 0.65rem; padding: 0.2em 0.5em;"><?= $adminUnreadNotifs ?></span>
                            <?php endif; ?>
                        </span>
                        <i class="bi bi-chevron-down section-arrow" style="font-size: 0.7rem; transition: transform 0.2s ease;"></i>
                    </button>
                    <div class="collapse <?= $isGroupActive ? 'show' : '' ?>" id="<?= $collapseId ?>" data-bs-parent="#sidebarAccordion">
                        <div class="d-flex flex-column gap-1 pt-1 ps-1">
                            <?php foreach ($sec['items'] as [$key, $label, $href, $icon]): ?>
                                <a href="<?= url($href) ?>" class="sidebar-link d-flex align-items-center justify-content-between <?= $curPage === $key ? 'active' : '' ?>">
                                    <div class="d-flex align-items-center gap-2 text-truncate">
                                        <i class="bi <?= e($icon) ?> sidebar-icon"></i>
                                        <span class="text-truncate"><?= e($label) ?></span>
                                    </div>
                                    <?php if ($key === 'notifications' && $adminUnreadNotifs > 0): ?>
                                        <span class="badge bg-danger rounded-pill" style="font-size: 0.72rem;"><?= $adminUnreadNotifs > 99 ? '99+' : $adminUnreadNotifs ?></span>
                                    <?php endif; ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
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
