<?php
/** includes/navbar.php  Top menu for passenger and public pages. */
$user = current_user();
?>
<nav class="navbar navbar-expand-lg site-nav">
    <div class="container">
        <a class="navbar-brand brand-mark" href="<?= url('index.php') ?>">
            <i class="bi bi-bus-front-fill"></i> <?= e(APP_NAME) ?>
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                <?php if ($user && $user['role'] === 'PASSENGER'): ?>
                    <li class="nav-item"><a class="nav-link" href="<?= url('passenger/dashboard.php') ?>">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= url('passenger/my_bookings.php') ?>">My bookings</a></li>
                    <?php
                    $noticeStmt = $pdo->prepare(
                        "SELECT COUNT(*) FROM bookings bk JOIN trips t ON t.trip_id = bk.trip_id
                         WHERE bk.user_id = :uid AND bk.status = 'CANCELLED'
                           AND t.status = 'CANCELLED' AND bk.cancellation_choice IS NULL"
                    );
                    $noticeStmt->execute([(int)$user['id']]);
                    $noticeCount = (int)$noticeStmt->fetchColumn();
                    ?>
                    <li class="nav-item"><a class="nav-link" href="<?= url('passenger/notifications.php') ?>"><i class="bi bi-bell"></i> Notifications<?php if ($noticeCount): ?> <span class="badge text-bg-danger"><?= $noticeCount ?></span><?php endif; ?></a></li>
                    <li class="nav-item"><a class="nav-link" href="<?= url('passenger/profile.php') ?>">Profile</a></li>
                <?php elseif ($user && in_array($user['role'], ['ADMIN', 'SUPER_ADMIN'], true)): ?>
                    <li class="nav-item"><a class="nav-link" href="<?= url('admin/dashboard.php') ?>">Admin dashboard</a></li>
                <?php endif; ?>
            </ul>
            <div class="site-nav-actions d-flex align-items-center gap-2">
                <?php if ($user): ?>
                    <span class="site-nav-user"><i class="bi bi-person-circle"></i> <?= e($user['name']) ?></span>
                    <form method="post" action="<?= url('auth/logout.php') ?>" class="m-0">
                        <?= csrf_field() ?>
                        <button type="submit" class="btn btn-nav-outline btn-sm">Log out</button>
                    </form>
                <?php else: ?>
                    <a class="btn btn-nav-outline btn-sm" href="<?= url('auth/login.php') ?>">Log in</a>
                    <a class="btn btn-nav-primary btn-sm" href="<?= url('auth/register.php') ?>">Create account</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
</nav>
