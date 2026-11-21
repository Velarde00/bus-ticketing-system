<?php
/**
 * admin/notifications/index.php
 * Displays notifications and passenger decision alerts for the admin.
 */
require_once __DIR__ . '/../../includes/auth.php';
require_role('ADMIN');

$adminId = (int)$_SESSION['user_id'];

// Mark all as read if requested
if (isset($_GET['action']) && $_GET['action'] === 'read_all') {
    $pdo->prepare("UPDATE notifications SET read_at = NOW() WHERE user_id = :uid AND read_at IS NULL")
        ->execute([':uid' => $adminId]);
    redirect('admin/notifications/index.php');
}

// Fetch notifications
$stmt = $pdo->prepare(
    "SELECT notification_id, title, message, link, created_at, read_at
     FROM notifications
     WHERE user_id = :uid
     ORDER BY created_at DESC
     LIMIT 50"
);
$stmt->execute([':uid' => $adminId]);
$notifs = $stmt->fetchAll();

$pageTitle  = 'Notifications & Alerts';
$layout     = 'admin';
$activePage = 'notifications';
require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="h3 fw-bold mb-1 text-dark d-flex align-items-center gap-2">
            <i class="bi bi-bell-fill text-primary"></i> Notifications &amp; Alerts
        </h1>
        <p class="text-muted small mb-0">System alerts, passenger refund requests, and trip rebooking notifications.</p>
    </div>
    <?php if ($notifs): ?>
        <a href="<?= url('admin/notifications/index.php?action=read_all') ?>" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
            <i class="bi bi-check2-all me-1"></i> Mark All as Read
        </a>
    <?php endif; ?>
</div>

<?php if (!$notifs): ?>
    <div class="panel p-5 bg-white rounded-4 border shadow-sm text-center">
        <div class="empty-state py-4">
            <i class="bi bi-bell-slash fs-1 text-muted mb-3 d-block"></i>
            <h2 class="h5 fw-bold mb-2">No New Alerts</h2>
            <p class="text-muted small max-w-500 mx-auto mb-0">
                You're all caught up! When a passenger requests a refund or rebooks their cancelled trip, notifications will appear here.
            </p>
        </div>
    </div>
<?php else: ?>
    <div class="d-flex flex-column gap-3">
        <?php foreach ($notifs as $n): ?>
            <?php
                $isUnread = empty($n['read_at']);
                $isRefund = stripos($n['title'], 'Refund') !== false;
                $isRebook = stripos($n['title'], 'Rebook') !== false;

                if ($isRefund) {
                    $badgeClass = 'bg-danger-subtle text-danger border border-danger-subtle';
                    $iconClass  = 'bi-arrow-counterclockwise text-danger';
                } elseif ($isRebook) {
                    $badgeClass = 'bg-primary-subtle text-primary border border-primary-subtle';
                    $iconClass  = 'bi-arrow-repeat text-primary';
                } else {
                    $badgeClass = 'bg-info-subtle text-info border border-info-subtle';
                    $iconClass  = 'bi-info-circle text-info';
                }
            ?>
            <div class="card rounded-4 border shadow-sm p-3 p-md-4 <?= $isUnread ? 'bg-light border-primary' : 'bg-white' ?>">
                <div class="d-flex align-items-start gap-3">
                    <div class="p-2 rounded-circle bg-white shadow-sm flex-shrink-0 d-flex align-items-center justify-content-center" style="width: 44px; height: 44px;">
                        <i class="bi <?= $iconClass ?> fs-4"></i>
                    </div>
                    <div class="flex-grow-1">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-1">
                            <h2 class="h6 fw-bold mb-0 text-dark"><?= e($n['title']) ?></h2>
                            <div class="d-flex align-items-center gap-2">
                                <span class="badge <?= $badgeClass ?> px-2 py-1 rounded-pill small">
                                    <?= $isRefund ? 'Refund Action' : ($isRebook ? 'Rebook Notice' : 'Alert') ?>
                                </span>
                                <?php if ($isUnread): ?>
                                    <span class="badge bg-primary text-white rounded-pill px-2 py-1" style="font-size: 0.68rem;">NEW</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <p class="text-muted small mb-2"><?= e($n['message']) ?></p>
                        <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 pt-2 border-top">
                            <span class="text-muted small" style="font-size: 0.75rem;">
                                <i class="bi bi-clock me-1"></i> Received: <?= e(fmt_datetime($n['created_at'])) ?>
                            </span>
                            <?php if (!empty($n['link'])): ?>
                                <a href="<?= url($n['link']) ?>" class="btn btn-outline-primary btn-sm rounded-pill px-3 shadow-sm" style="font-size: 0.8rem;">
                                    Take Action <i class="bi bi-arrow-right ms-1"></i>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/footer.php'; ?>
