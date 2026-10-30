<?php
/**
 * passenger/dashboard.php
 * Passenger home: booking counts and the latest bookings. Only the logged-in passenger's data is read.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('PASSENGER');

$uid = (int)$_SESSION['user_id'];

$counts = $pdo->prepare(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(status = 'PENDING'), 0)   AS pending,
            COALESCE(SUM(status = 'CONFIRMED'), 0) AS confirmed,
            COALESCE(SUM(status = 'CANCELLED'), 0) AS cancelled
     FROM bookings WHERE user_id = :uid"
);
$counts->execute([':uid' => $uid]);
$stats = $counts->fetch();

$upcomingStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM bookings bk JOIN trips t ON t.trip_id = bk.trip_id
     WHERE bk.user_id = :uid AND bk.status <> 'CANCELLED' AND t.departure_time > NOW()"
);
$upcomingStmt->execute([':uid' => $uid]);
$upcoming = (int)$upcomingStmt->fetchColumn();

$recentStmt = $pdo->prepare(
    "SELECT bk.booking_id, bk.booking_reference, bk.status, r.origin, r.destination, t.departure_time,
            (SELECT COUNT(*) FROM booking_seats bs WHERE bs.booking_id = bk.booking_id AND bs.seat_status = 'BOOKED') AS seats,
            (SELECT p.payment_status FROM payments p WHERE p.booking_id = bk.booking_id
             ORDER BY p.payment_date DESC, p.payment_id DESC LIMIT 1) AS payment_status
     FROM bookings bk
     JOIN trips  t ON t.trip_id  = bk.trip_id
     JOIN routes r ON r.route_id = t.route_id
     WHERE bk.user_id = :uid
     ORDER BY bk.booking_date DESC LIMIT 5"
);
$recentStmt->execute([':uid' => $uid]);
$recent = $recentStmt->fetchAll();

$pageTitle = 'My dashboard';
$layout    = 'public';
require __DIR__ . '/../includes/header.php';
?>
<div class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h1 class="h3 mb-0">Hello, <?= e(explode(' ', $_SESSION['user_name'])[0]) ?></h1>
            <p class="text-muted mb-0">You have <?= $upcoming ?> upcoming booking<?= $upcoming === 1 ? '' : 's' ?>.</p>
        </div>
        <a href="<?= url('index.php') ?>#search" class="btn btn-primary"><i class="bi bi-search"></i> Search trips</a>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3"><div class="stat-card"><span class="stat-label">All bookings</span><span class="stat-value"><?= (int)$stats['total'] ?></span></div></div>
        <div class="col-6 col-lg-3"><div class="stat-card"><span class="stat-label">Pending</span><span class="stat-value"><?= (int)$stats['pending'] ?></span></div></div>
        <div class="col-6 col-lg-3"><div class="stat-card"><span class="stat-label">Confirmed</span><span class="stat-value"><?= (int)$stats['confirmed'] ?></span></div></div>
        <div class="col-6 col-lg-3"><div class="stat-card"><span class="stat-label">Cancelled</span><span class="stat-value"><?= (int)$stats['cancelled'] ?></span></div></div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <h2 class="h5 mb-0">Latest bookings</h2>
            <a href="<?= url('passenger/booking_history.php') ?>" class="small">Booking history</a>
        </div>
        <?php if (!$recent): ?>
            <div class="empty-state">
                <i class="bi bi-ticket-perforated"></i>
                <p class="mb-2">You have no bookings yet. Search a trip to book your first seat.</p>
                <a href="<?= url('index.php') ?>#search" class="btn btn-primary btn-sm">Search trips</a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table align-middle mb-0">
                    <thead><tr><th>Reference</th><th>Route</th><th>Departure</th><th>Seats</th><th>Status</th><th>Payment</th><th></th></tr></thead>
                    <tbody>
                    <?php foreach ($recent as $b): ?>
                        <tr>
                            <td class="fw-semibold"><?= e($b['booking_reference']) ?></td>
                            <td><?= e($b['origin']) ?> &rarr; <?= e($b['destination']) ?></td>
                            <td><?= e(fmt_datetime($b['departure_time'])) ?></td>
                            <td><?= (int)$b['seats'] ?></td>
                            <td><?= status_badge($b['status']) ?></td>
                            <td><?= $b['payment_status'] ? status_badge($b['payment_status']) : '<span class="text-muted">None</span>' ?></td>
                            <td class="text-end">
                                <?php if ($b['status'] === 'CONFIRMED'): ?>
                                    <a class="btn btn-outline-dark btn-sm" href="<?= url('passenger/ticket.php?booking_id=' . (int)$b['booking_id']) ?>">Tickets</a>
                                <?php else: ?>
                                    <a class="btn btn-outline-secondary btn-sm" href="<?= url('passenger/booking_confirmation.php?booking_id=' . (int)$b['booking_id']) ?>">Details</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
