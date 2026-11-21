<?php
require_once __DIR__ . '/../../includes/auth.php';
require_role('ADMIN');

$id = filter_var($_GET['id'] ?? '', FILTER_VALIDATE_INT);
$stmt = $pdo->prepare(
    "SELECT bk.booking_id, bk.booking_reference, bk.booking_date, bk.status,
            CONCAT(u.first_name, ' ', u.last_name) AS passenger, u.email, u.phone_number,
            CONCAT(r.origin, ' to ', r.destination) AS route, t.departure_time, t.arrival_time,
            b.bus_number, CONCAT(d.first_name, ' ', d.last_name) AS driver_name
     FROM bookings bk JOIN users u ON u.user_id = bk.user_id JOIN trips t ON t.trip_id = bk.trip_id
     JOIN routes r ON r.route_id = t.route_id JOIN buses b ON b.bus_id = t.bus_id
     JOIN drivers d ON d.driver_id = t.driver_id WHERE bk.booking_id = :id"
);
$stmt->execute([':id' => $id ?: 0]);
$booking = $stmt->fetch();
if (!$booking) {
    set_flash('warning', 'Reservation not found.');
    redirect('admin/reservations/index.php');
}
$stmt = $pdo->prepare(
    "SELECT bs.seat_number, bs.price, bs.seat_status, tk.ticket_number
     FROM booking_seats bs LEFT JOIN tickets tk ON tk.booking_seat_id = bs.booking_seat_id
     WHERE bs.booking_id = :id ORDER BY bs.seat_number"
);
$stmt->execute([':id' => $id]);
$seats = $stmt->fetchAll();
$stmt = $pdo->prepare('SELECT amount, payment_method, payment_status, payment_date FROM payments WHERE booking_id = :id ORDER BY payment_date DESC, payment_id DESC');
$stmt->execute([':id' => $id]);
$payments = $stmt->fetchAll();

$pageTitle = 'Reservation ' . $booking['booking_reference'];
$layout = 'admin';
$activePage = 'reservations';
require __DIR__ . '/../../includes/header.php';
?>
<div class="mb-3">
    <a href="<?= url('admin/reservations/index.php') ?>" class="text-muted text-decoration-none small d-inline-flex align-items-center gap-1 mb-2">
        <i class="bi bi-arrow-left"></i> Back to all reservations
    </a>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <h1 class="h3 fw-bold mb-1 font-monospace text-dark"><?= e($booking['booking_reference']) ?></h1>
            <p class="text-muted small mb-0"><i class="bi bi-clock me-1"></i> Booked <?= e(fmt_datetime($booking['booking_date'])) ?></p>
        </div>
        <div>
            <?= status_badge($booking['status']) ?>
        </div>
    </div>
</div>

<div class="panel bg-white rounded-4 border shadow-sm mb-4 overflow-hidden">
    <div class="panel-head p-3 px-4 border-bottom bg-light bg-opacity-50">
        <h2 class="h5 fw-bold mb-0 text-dark"><i class="bi bi-person-badge me-2 text-primary"></i>Passenger &amp; Trip Details</h2>
    </div>
    <div class="p-4">
        <div class="row g-4">
            <div class="col-sm-6 col-md-3">
                <span class="text-muted small d-block mb-1">Passenger</span>
                <div class="fw-bold text-dark mb-0"><?= e($booking['passenger']) ?></div>
            </div>
            <div class="col-sm-6 col-md-3">
                <span class="text-muted small d-block mb-1">Email Address</span>
                <div class="fw-semibold text-dark mb-0"><?= e($booking['email']) ?></div>
            </div>
            <div class="col-sm-6 col-md-3">
                <span class="text-muted small d-block mb-1">Mobile Contact</span>
                <div class="fw-semibold text-dark mb-0"><?= e($booking['phone_number']) ?></div>
            </div>
            <div class="col-sm-6 col-md-3">
                <span class="text-muted small d-block mb-1">Route</span>
                <div class="fw-bold text-primary mb-0"><?= e($booking['route']) ?></div>
            </div>
            <div class="col-sm-6 col-md-3">
                <span class="text-muted small d-block mb-1">Departure</span>
                <div class="fw-semibold text-dark mb-0"><?= e(fmt_datetime($booking['departure_time'])) ?></div>
            </div>
            <div class="col-sm-6 col-md-3">
                <span class="text-muted small d-block mb-1">Expected Arrival</span>
                <div class="fw-semibold text-dark mb-0"><?= e(fmt_datetime($booking['arrival_time'])) ?></div>
            </div>
            <div class="col-sm-6 col-md-3">
                <span class="text-muted small d-block mb-1">Assigned Coach</span>
                <div class="fw-semibold text-dark mb-0">Bus <?= e($booking['bus_number']) ?></div>
            </div>
            <div class="col-sm-6 col-md-3">
                <span class="text-muted small d-block mb-1">Driver</span>
                <div class="fw-semibold text-dark mb-0"><?= e($booking['driver_name']) ?></div>
            </div>
        </div>
    </div>
</div>

<div class="panel bg-white rounded-4 border shadow-sm mb-4 overflow-hidden">
    <div class="panel-head p-3 px-4 border-bottom bg-light bg-opacity-50">
        <h2 class="h5 fw-bold mb-0 text-dark"><i class="bi bi-ticket-perforated me-2 text-primary"></i>Assigned Seats &amp; Tickets</h2>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 custom-modern-table">
            <thead><tr><th class="ps-4">Seat #</th><th>Fare</th><th>Seat Status</th><th class="pe-4">Issued Ticket Number</th></tr></thead>
            <tbody>
                <?php foreach ($seats as $seat): ?>
                    <tr>
                        <td class="ps-4">
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-1 rounded-pill fw-bold">
                                Seat <?= (int)$seat['seat_number'] ?>
                            </span>
                        </td>
                        <td class="fw-semibold"><?= money($seat['price']) ?></td>
                        <td><?= status_badge($seat['seat_status'] === 'BOOKED' ? 'ACTIVE' : 'CANCELLED') ?></td>
                        <td class="pe-4 font-monospace"><?= e($seat['ticket_number'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$seats): ?><tr><td colspan="4" class="text-muted text-center py-4">No seats found for this reservation.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="panel bg-white rounded-4 border shadow-sm mb-4 overflow-hidden">
    <div class="panel-head p-3 px-4 border-bottom bg-light bg-opacity-50">
        <h2 class="h5 fw-bold mb-0 text-dark"><i class="bi bi-cash-coin me-2 text-primary"></i>Payment Records</h2>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 custom-modern-table">
            <thead><tr><th class="ps-4">Amount</th><th>Method</th><th>Status</th><th class="pe-4">Payment Timestamp</th></tr></thead>
            <tbody>
                <?php foreach ($payments as $payment): ?>
                    <tr>
                        <td class="ps-4 fw-bold text-dark fs-6"><?= money($payment['amount']) ?></td>
                        <td><span class="badge bg-light text-dark border px-2 py-1 rounded-pill"><?= e(str_replace('_', ' ', $payment['payment_method'])) ?></span></td>
                        <td><?= status_badge($payment['payment_status']) ?></td>
                        <td class="pe-4 small text-muted"><?= e(fmt_datetime($payment['payment_date'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$payments): ?><tr><td colspan="4" class="text-muted text-center py-4">No payment records attached.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
