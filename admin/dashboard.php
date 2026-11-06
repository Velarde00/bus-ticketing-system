<?php
/**
 * admin/dashboard.php
 * Administrator home: system statistics and the latest reservations.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('ADMIN');

$stats = $pdo->query(
    "SELECT
        (SELECT COUNT(*) FROM users   WHERE role = 'PASSENGER')     AS passengers,
        (SELECT COUNT(*) FROM buses)                                AS buses,
        (SELECT COUNT(*) FROM drivers)                              AS drivers,
        (SELECT COUNT(*) FROM routes)                               AS routes,
        (SELECT COUNT(*) FROM trips   WHERE status = 'SCHEDULED')   AS trips,
        (SELECT COUNT(*) FROM bookings)                             AS bookings,
        (SELECT COUNT(*) FROM bookings WHERE status = 'PENDING')    AS pending,
        (SELECT COUNT(*) FROM bookings WHERE status = 'CONFIRMED')  AS confirmed,
        (SELECT COUNT(*) FROM bookings WHERE status = 'CANCELLED')  AS cancelled,
        (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE payment_status = 'PAID') AS sales"
)->fetch();

$recent = $pdo->query(
    "SELECT bk.booking_id, bk.booking_reference, bk.booking_date, bk.status,
            CONCAT(u.first_name, ' ', u.last_name) AS passenger,
            CONCAT(r.origin, ' to ', r.destination) AS route
     FROM bookings bk
     JOIN users  u ON u.user_id  = bk.user_id
     JOIN trips  t ON t.trip_id  = bk.trip_id
     JOIN routes r ON r.route_id = t.route_id
     ORDER BY bk.booking_date DESC LIMIT 8"
)->fetchAll();

$pageTitle  = 'Dashboard';
$layout     = 'admin';
$activePage = 'dashboard';
require __DIR__ . '/../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="h3 mb-0">Dashboard</h1>
        <p class="text-muted mb-0">Today is <?= e(date('l, F j, Y')) ?>.</p>
    </div>
    <?php if (($_SESSION['role'] ?? '') === 'SUPER_ADMIN'): ?>
        <a href="<?= url('admin/administrators.php') ?>" class="btn btn-outline-dark btn-sm">
            <i class="bi bi-shield-lock"></i> Manage administrators
        </a>
    <?php endif; ?>
</div>

<h2 class="h6 text-muted mb-2">Operations</h2>
<div class="row row-cols-2 row-cols-md-3 row-cols-xl-5 g-3 mb-4">
    <div class="col"><div class="stat-card"><span class="stat-label"><i class="bi bi-people"></i> Passengers</span><span class="stat-value"><?= (int)$stats['passengers'] ?></span></div></div>
    <div class="col"><div class="stat-card"><span class="stat-label"><i class="bi bi-bus-front"></i> Buses</span><span class="stat-value"><?= (int)$stats['buses'] ?></span></div></div>
    <div class="col"><div class="stat-card"><span class="stat-label"><i class="bi bi-person-vcard"></i> Drivers</span><span class="stat-value"><?= (int)$stats['drivers'] ?></span></div></div>
    <div class="col"><div class="stat-card"><span class="stat-label"><i class="bi bi-signpost-split"></i> Routes</span><span class="stat-value"><?= (int)$stats['routes'] ?></span></div></div>
    <div class="col"><div class="stat-card"><span class="stat-label"><i class="bi bi-calendar-event"></i> Scheduled trips</span><span class="stat-value"><?= (int)$stats['trips'] ?></span></div></div>
</div>

<h2 class="h6 text-muted mb-2">Reservations and sales</h2>
<div class="row row-cols-2 row-cols-md-3 row-cols-xl-5 g-3 mb-4">
    <div class="col"><div class="stat-card"><span class="stat-label">Total bookings</span><span class="stat-value"><?= (int)$stats['bookings'] ?></span></div></div>
    <div class="col"><div class="stat-card stat-pending"><span class="stat-label">Pending</span><span class="stat-value"><?= (int)$stats['pending'] ?></span></div></div>
    <div class="col"><div class="stat-card stat-confirmed"><span class="stat-label">Confirmed</span><span class="stat-value"><?= (int)$stats['confirmed'] ?></span></div></div>
    <div class="col"><div class="stat-card"><span class="stat-label">Cancelled</span><span class="stat-value"><?= (int)$stats['cancelled'] ?></span></div></div>
    <div class="col"><div class="stat-card stat-sales"><span class="stat-label">Recorded sales</span><span class="stat-value"><?= money($stats['sales']) ?></span></div></div>
</div>

<div class="panel">
    <div class="panel-head">
        <h2 class="h5 mb-0">Latest reservations</h2>
        <a href="<?= url('admin/reservations/index.php') ?>" class="btn btn-booking-action btn-sm">View all <i class="bi bi-arrow-right"></i></a>
    </div>
    <?php if (!$recent): ?>
        <div class="empty-state"><i class="bi bi-ticket-perforated"></i><p class="mb-0">No reservations yet.</p></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th>Reference</th><th>Passenger</th><th>Route</th><th>Booked on</th><th>Status</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($recent as $b): ?>
                    <tr>
                        <td class="fw-semibold"><?= e($b['booking_reference']) ?></td>
                        <td><?= e($b['passenger']) ?></td>
                        <td><?= e($b['route']) ?></td>
                        <td><?= e(fmt_datetime($b['booking_date'])) ?></td>
                        <td><?= status_badge($b['status']) ?></td>
                        <td class="text-end"><a class="btn btn-outline-dark btn-sm" href="<?= url('admin/reservations/view.php?id=' . (int)$b['booking_id']) ?>">View</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
