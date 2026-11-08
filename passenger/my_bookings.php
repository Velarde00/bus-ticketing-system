<?php
/**
 * passenger/my_bookings.php
 * The logged-in passenger's UPCOMING bookings (not cancelled, departure still in the future).
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('PASSENGER');

$stmt = $pdo->prepare(
    "SELECT bk.booking_id, bk.booking_reference, bk.status,
            r.origin, r.destination, t.departure_time, b.bus_number,
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
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h3 mb-0">My bookings</h1>
        <a href="<?= url('passenger/booking_history.php') ?>" class="btn btn-booking-history btn-sm"><i class="bi bi-clock-history"></i> Booking history</a>
    </div>

    <?php if (!$bookings): ?>
        <div class="panel"><div class="empty-state">
            <i class="bi bi-ticket-perforated"></i>
            <p class="mb-2">You have no upcoming bookings.</p>
            <a href="<?= url('passenger/dashboard.php') ?>" class="btn btn-primary btn-sm">Browse available trips</a>
        </div></div>
    <?php else: ?>
        <div class="panel"><div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th>Reference</th><th>Route</th><th>Departure</th><th>Bus</th><th>Seats</th><th>Status</th><th>Payment</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($bookings as $b): ?>
                    <tr>
                        <td class="fw-semibold"><?= e($b['booking_reference']) ?></td>
                        <td><?= e($b['origin']) ?> &rarr; <?= e($b['destination']) ?></td>
                        <td><?= e(fmt_datetime($b['departure_time'])) ?></td>
                        <td><?= e($b['bus_number']) ?></td>
                        <td><?= e($b['seat_list']) ?></td>
                        <td><?= status_badge($b['status']) ?></td>
                        <td><?= $b['payment_status'] ? status_badge($b['payment_status']) : '<span class="text-muted">None</span>' ?></td>
                        <td class="text-end text-nowrap">
                            <a class="btn btn-booking-action btn-sm" href="<?= url('passenger/booking_confirmation.php?booking_id=' . (int)$b['booking_id']) ?>">Booking details</a>
                            <?php if ($b['status'] === 'CONFIRMED'): ?>
                                <a class="btn btn-primary btn-sm" href="<?= url('passenger/ticket.php?booking_id=' . (int)$b['booking_id']) ?>">Tickets</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div></div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
