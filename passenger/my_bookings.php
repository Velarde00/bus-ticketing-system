<?php
/**
 * passenger/my_bookings.php
 * The logged-in passenger's UPCOMING bookings (not cancelled, departure still in the future).
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('PASSENGER');

$stmt = $pdo->prepare(
    "SELECT bk.booking_id, bk.booking_reference, bk.status,
            r.origin, r.destination, t.departure_time, b.bus_number, b.bus_type,
            (SELECT GROUP_CONCAT(bs.seat_number ORDER BY bs.seat_number SEPARATOR ', ')
             FROM booking_seats bs WHERE bs.booking_id = bk.booking_id AND bs.seat_status = 'BOOKED') AS seat_list,
            (SELECT p.payment_status FROM payments p WHERE p.booking_id = bk.booking_id
             ORDER BY p.payment_date DESC, p.payment_id DESC LIMIT 1) AS payment_status
     FROM bookings bk
     JOIN trips  t ON t.trip_id  = bk.trip_id
     JOIN routes r ON r.route_id = t.route_id
     JOIN buses  b ON b.bus_id   = t.bus_id
     WHERE bk.user_id = :uid AND bk.status <> 'CANCELLED' AND t.departure_time > NOW()
     ORDER BY t.departure_time"
);
$stmt->execute([':uid' => $_SESSION['user_id']]);
$bookings = $stmt->fetchAll();

$pageTitle = 'My bookings';
$layout    = 'public';
require __DIR__ . '/../includes/header.php';
?>
<div class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1">My Upcoming Bookings</h1>
            <p class="text-muted small mb-0">Active and upcoming bus trips scheduled under your account.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?= url('index.php') ?>#search" class="btn btn-primary btn-sm rounded-pill shadow-sm px-3">
                <i class="bi bi-search me-1"></i> Book New Trip
            </a>
            <a href="<?= url('passenger/booking_history.php') ?>" class="btn btn-outline-dark btn-sm rounded-pill px-3">
                <i class="bi bi-clock-history me-1"></i> Booking History
            </a>
        </div>
    </div>

    <?php if (!$bookings): ?>
        <div class="panel p-5 bg-white rounded-4 border shadow-sm text-center">
            <div class="empty-state py-4">
                <div class="empty-state-icon mb-3">
                    <i class="bi bi-ticket-perforated fs-1 text-primary"></i>
                </div>
                <h2 class="h5 fw-bold mb-2">No Upcoming Bookings</h2>
                <p class="text-muted small max-w-500 mx-auto mb-4">
                    You do not currently have any scheduled or upcoming bus journeys. Find available trips and reserve your preferred seats now.
                </p>
                <a href="<?= url('index.php') ?>#search" class="btn btn-primary btn-sm rounded-pill px-4">
                    <i class="bi bi-search me-1"></i> Search Buses
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="panel bg-white rounded-4 border shadow-sm overflow-hidden">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 custom-modern-table">
                    <thead>
                        <tr>
                            <th class="ps-4">Reference</th>
                            <th>Route</th>
                            <th>Departure Time</th>
                            <th>Coach</th>
                            <th>Assigned Seats</th>
                            <th>Status</th>
                            <th>Payment</th>
                            <th class="text-end pe-4">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($bookings as $b): ?>
                        <tr>
                            <td class="ps-4">
                                <span class="font-monospace fw-bold text-dark"><?= e($b['booking_reference']) ?></span>
                            </td>
                            <td>
                                <div class="fw-semibold text-dark"><?= e($b['origin']) ?> &rarr; <?= e($b['destination']) ?></div>
                            </td>
                            <td>
                                <div class="small fw-medium text-dark"><?= e(fmt_datetime($b['departure_time'])) ?></div>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border px-2 py-1 rounded-pill small">
                                    <i class="bi bi-bus-front me-1 text-primary"></i><?= e($b['bus_number']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1 rounded-pill small">
                                    Seats: <?= e($b['seat_list'] ?: 'None') ?>
                                </span>
                            </td>
                            <td><?= status_badge($b['status']) ?></td>
                            <td><?= $b['payment_status'] ? status_badge($b['payment_status']) : '<span class="text-muted small">Pending</span>' ?></td>
                            <td class="text-end pe-4 text-nowrap">
                                <a class="btn btn-outline-secondary btn-sm rounded-pill px-3 me-1" href="<?= url('passenger/booking_confirmation.php?booking_id=' . (int)$b['booking_id']) ?>">
                                    Details
                                </a>
                                <?php if ($b['status'] === 'CONFIRMED'): ?>
                                    <a class="btn btn-primary btn-sm rounded-pill px-3 shadow-sm" href="<?= url('passenger/ticket.php?booking_id=' . (int)$b['booking_id']) ?>">
                                        <i class="bi bi-printer me-1"></i> Boarding Pass
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
