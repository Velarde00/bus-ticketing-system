<?php
/**
 * passenger/booking_confirmation.php
 * Shows one booking's details. A passenger can only open their own booking.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('PASSENGER');

$uid       = (int)$_SESSION['user_id'];
$bookingId = filter_var($_GET['booking_id'] ?? null, FILTER_VALIDATE_INT);

$booking = false;
if ($bookingId) {
    $stmt = $pdo->prepare(
        "SELECT bk.booking_id, bk.booking_reference, bk.booking_date, bk.status,
                t.departure_time, t.arrival_time,
                r.origin, r.destination, b.bus_number, b.bus_type,
                CONCAT(d.first_name, ' ', d.last_name) AS driver_name
         FROM bookings bk
         JOIN trips   t ON t.trip_id   = bk.trip_id
         JOIN routes  r ON r.route_id  = t.route_id
         JOIN buses   b ON b.bus_id    = t.bus_id
         JOIN drivers d ON d.driver_id = t.driver_id
         WHERE bk.booking_id = :id AND bk.user_id = :uid"
    );
    $stmt->execute([':id' => $bookingId, ':uid' => $uid]);
    $booking = $stmt->fetch();
}

if (!$booking) {
    set_flash('warning', 'Booking not found.');
    redirect('passenger/my_bookings.php');
}

$seatStmt = $pdo->prepare('SELECT seat_number, price, seat_status FROM booking_seats WHERE booking_id = :id ORDER BY seat_number');
$seatStmt->execute([':id' => $bookingId]);
$seats = $seatStmt->fetchAll();
$total = array_sum(array_column($seats, 'price'));

$payStmt = $pdo->prepare('SELECT amount, payment_method, payment_status FROM payments WHERE booking_id = :id ORDER BY payment_date DESC, payment_id DESC LIMIT 1');
$payStmt->execute([':id' => $bookingId]);
$payment = $payStmt->fetch();

$canCancel = $booking['status'] !== 'CANCELLED' && (strtotime($booking['departure_time']) - time()) >= 2 * 3600;

$pageTitle = 'Booking ' . $booking['booking_reference'];
$layout    = 'public';
require __DIR__ . '/../includes/header.php';
?>
<div class="container py-4" style="max-width: 860px;">
    <a href="<?= url('passenger/my_bookings.php') ?>" class="small"><i class="bi bi-arrow-left"></i> My bookings</a>

    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-2 mb-3">
        <div>
            <h1 class="h3 mb-0"><?= e($booking['booking_reference']) ?></h1>
            <span class="text-muted small">Booked on <?= e(fmt_datetime($booking['booking_date'])) ?></span>
        </div>
        <?= status_badge($booking['status']) ?>
    </div>

    <?php if ($booking['status'] === 'PENDING'): ?>
        <div class="alert alert-warning">Waiting for confirmation. Your ticket is issued once the administrator confirms your booking.</div>
    <?php elseif ($booking['status'] === 'CONFIRMED'): ?>
        <div class="alert alert-success">Your booking is confirmed.</div>
    <?php else: ?>
        <div class="alert alert-secondary">This booking was cancelled.</div>
    <?php endif; ?>

    <div class="panel mb-3">
        <div class="panel-head"><h2 class="h5 mb-0">Trip</h2></div>
        <dl class="detail-grid p-3 mb-0">
            <div><dt>Route</dt><dd><?= e($booking['origin']) ?> &rarr; <?= e($booking['destination']) ?></dd></div>
            <div><dt>Departure</dt><dd><?= e(fmt_datetime($booking['departure_time'])) ?></dd></div>
            <div><dt>Arrival</dt><dd><?= e(fmt_datetime($booking['arrival_time'])) ?></dd></div>
            <div><dt>Bus</dt><dd><?= e($booking['bus_number']) ?> (<?= e($booking['bus_type']) ?>)</dd></div>
            <div><dt>Driver</dt><dd><?= e($booking['driver_name']) ?></dd></div>
        </dl>
    </div>

    <div class="panel mb-3">
        <div class="panel-head"><h2 class="h5 mb-0">Seats and payment</h2></div>
        <div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th>Seat</th><th>Price</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($seats as $s): ?>
                    <tr>
                        <td class="fw-semibold">Seat <?= (int)$s['seat_number'] ?></td>
                        <td><?= money($s['price']) ?></td>
                        <td><?= status_badge($s['seat_status'] === 'BOOKED' ? 'ACTIVE' : 'CANCELLED') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr><th>Total fare</th><th colspan="2"><?= money($total) ?></th></tr>
                </tfoot>
            </table>
        </div>
        <div class="p-3 border-top">
            <?php if ($payment): ?>
                Payment: <strong><?= e(str_replace('_', '-', ucfirst(strtolower($payment['payment_method'])))) ?></strong>
                &middot; <?= money($payment['amount']) ?> &middot; <?= status_badge($payment['payment_status']) ?>
            <?php else: ?>
                <span class="text-muted">No payment record yet.</span>
            <?php endif; ?>
        </div>
    </div>

    <div class="d-flex flex-wrap gap-2">
        <?php if ($booking['status'] === 'CONFIRMED'): ?>
            <a class="btn btn-primary" href="<?= url('passenger/ticket.php?booking_id=' . (int)$booking['booking_id']) ?>"><i class="bi bi-ticket-perforated"></i> View tickets</a>
        <?php endif; ?>
        <?php if ($canCancel): ?>
            <form method="post" action="<?= url('actions/cancel_booking.php') ?>" data-confirm="Cancel this booking? Your seats will be released.">
                <?= csrf_field() ?>
                <input type="hidden" name="booking_id" value="<?= (int)$booking['booking_id'] ?>">
                <button type="submit" class="btn btn-outline-danger">Cancel booking</button>
            </form>
        <?php elseif ($booking['status'] !== 'CANCELLED'): ?>
            <span class="text-muted small align-self-center">Bookings cannot be cancelled within 2 hours of departure.</span>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
