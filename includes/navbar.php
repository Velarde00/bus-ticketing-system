<?php
/** includes/navbar.php  Top menu for passenger and public pages. */
$user = current_user();
$currentScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
$initials = 'U';
if ($user && !empty($user['name'])) {
    $parts = preg_split('/\s+/', trim($user['name']));
    $initials = strtoupper(substr($parts[0] ?? '', 0, 1) . (isset($parts[1]) ? substr($parts[1], 0, 1) : ''));
}
?>
<nav class="navbar navbar-expand-lg site-nav sticky-top">
    <div class="container">
        <a class="navbar-brand brand-mark d-flex align-items-center gap-2" href="<?= url('index.php') ?>">
            <span class="brand-icon-wrapper">
                <i class="bi bi-bus-front-fill"></i>
            </span>
            <span class="brand-text">
                <?= e(APP_NAME) ?>
                <span class="brand-tag">Express</span>
            </span>
        </a>
        <button class="navbar-toggler border-0 p-2" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0 ms-lg-3 gap-lg-1">
                <?php if ($user && $user['role'] === 'PASSENGER'): ?>
                    <li class="nav-item">
                        <a class="nav-link <?= $currentScript === 'dashboard.php' ? 'active' : '' ?>" href="<?= url('passenger/dashboard.php') ?>">
                            <i class="bi bi-grid-1x2 me-1 text-muted"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= in_array($currentScript, ['my_bookings.php', 'booking_history.php'], true) ? 'active' : '' ?>" href="<?= url('passenger/my_bookings.php') ?>">
                            <i class="bi bi-ticket-perforated me-1 text-muted"></i> My Bookings
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link <?= $currentScript === 'profile.php' ? 'active' : '' ?>" href="<?= url('passenger/profile.php') ?>">
                            <i class="bi bi-person-gear me-1 text-muted"></i> Profile
                        </a>
                    </li>
                <?php elseif ($user && in_array($user['role'], ['ADMIN', 'SUPER_ADMIN'], true)): ?>
                    <li class="nav-item">
                        <a class="nav-link text-primary fw-semibold" href="<?= url('admin/dashboard.php') ?>">
                            <i class="bi bi-speedometer2 me-1"></i> Admin Portal
                        </a>
                    </li>
                <?php else: ?>
                    <li class="nav-item">
                        <a class="nav-link <?= $currentScript === 'index.php' ? 'active' : '' ?>" href="<?= url('index.php') ?>">
                            <i class="bi bi-search me-1 text-muted"></i> Search Trips
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= url('index.php#how-it-works') ?>">
                            <i class="bi bi-info-circle me-1 text-muted"></i> How It Works
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
            <div class="d-flex align-items-center gap-2 mt-3 mt-lg-0">
                <?php if ($user): ?>
                    <div class="dropdown">
                        <button class="user-pill-btn dropdown-toggle d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="user-avatar-circle"><?= e($initials) ?></span>
                            <span class="d-none d-sm-inline-block text-start">
                                <span class="d-block fw-semibold text-truncate user-name-label"><?= e($user['name']) ?></span>
                                <span class="d-block text-muted small user-role-label"><?= e(ucfirst(strtolower(str_replace('_', ' ', $user['role'])))) ?></span>
                            </span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm border-0 user-dropdown-menu">
                            <li class="px-3 py-2 border-bottom d-sm-none">
                                <span class="fw-semibold d-block"><?= e($user['name']) ?></span>
                                <span class="text-muted small"><?= e($user['role']) ?></span>
                            </li>
                            <?php if ($user['role'] === 'PASSENGER'): ?>
                                <li>
                                    <a class="dropdown-item py-2" href="<?= url('passenger/notifications.php') ?>">
                                        <i class="bi bi-bell me-2 text-primary"></i> Notifications
                                    </a>
                                </li>
                            <?php else: ?>
                                <li>
                                    <a class="dropdown-item py-2" href="<?= url('admin/notifications/index.php') ?>">
                                        <i class="bi bi-bell me-2 text-primary"></i> Notifications &amp; Alerts
                                    </a>
                                </li>
                            <?php endif; ?>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <form method="post" action="<?= url('auth/logout.php') ?>" class="m-0 px-2 py-1">
                                    <?= csrf_field() ?>
                                    <button type="submit" class="btn btn-sm btn-outline-danger w-100 d-flex align-items-center justify-content-center gap-1">
                                        <i class="bi bi-box-arrow-right"></i> Log out
                                    </button>
                                </form>
                            </li>
                        </ul>
                    </div>
                <?php else: ?>
                    <a class="btn btn-outline-secondary btn-sm px-3" href="<?= url('index.php#account-access') ?>" onclick="toggleLandingToLogin()">
                        <i class="bi bi-box-arrow-in-right me-1"></i> Sign In
                    </a>
                    <a class="btn btn-primary btn-sm px-3 shadow-sm" href="<?= url('index.php#account-access') ?>" onclick="toggleLandingToRegister()">
                        <i class="bi bi-person-plus me-1"></i> Register
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>
