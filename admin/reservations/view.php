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
<div class="d-flex justify-content-between align-items-center mb-3">
    <div><h1 class="h3 mb-0"><?= e($booking['booking_reference']) ?></h1><p class="text-muted mb-0">Booked <?= e(fmt_datetime($booking['booking_date'])) ?></p></div>
    <?= status_badge($booking['status']) ?>
</div>
<a href="<?= url('admin/reservations/index.php') ?>" class="btn btn-booking-action btn-sm"><i class="bi bi-arrow-left"></i> All reservations</a>
<div class="panel mt-3 mb-3">
    <div class="panel-head"><h2 class="h5 mb-0">Passenger and trip</h2></div>
    <dl class="detail-grid p-3 mb-0">
        <div><dt>Passenger</dt><dd><?= e($booking['passenger']) ?></dd></div><div><dt>Email</dt><dd><?= e($booking['email']) ?></dd></div>
        <div><dt>Mobile</dt><dd><?= e($booking['phone_number']) ?></dd></div><div><dt>Route</dt><dd><?= e($booking['route']) ?></dd></div>
        <div><dt>Departure</dt><dd><?= e(fmt_datetime($booking['departure_time'])) ?></dd></div><div><dt>Arrival</dt><dd><?= e(fmt_datetime($booking['arrival_time'])) ?></dd></div>
        <div><dt>Bus</dt><dd><?= e($booking['bus_number']) ?></dd></div><div><dt>Driver</dt><dd><?= e($booking['driver_name']) ?></dd></div>
    </dl>
</div>
<div class="panel mb-3">
    <div class="panel-head"><h2 class="h5 mb-0">Seats and tickets</h2></div>
    <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Seat</th><th>Price</th><th>Status</th><th>Ticket</th></tr></thead><tbody>
        <?php foreach ($seats as $seat): ?><tr><td><?= (int)$seat['seat_number'] ?></td><td><?= money($seat['price']) ?></td><td><?= status_badge($seat['seat_status'] === 'BOOKED' ? 'ACTIVE' : 'CANCELLED') ?></td><td><?= e($seat['ticket_number'] ?: '—') ?></td></tr><?php endforeach; ?>
        <?php if (!$seats): ?><tr><td colspan="4" class="text-muted text-center py-3">No seats.</td></tr><?php endif; ?>
    </tbody></table></div>
</div>
<div class="panel">
    <div class="panel-head"><h2 class="h5 mb-0">Payments</h2></div>
    <div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Amount</th><th>Method</th><th>Status</th><th>Date</th></tr></thead><tbody>
        <?php foreach ($payments as $payment): ?><tr><td><?= money($payment['amount']) ?></td><td><?= e(payment_method_label($payment['payment_method'])) ?></td><td><?= status_badge($payment['payment_status']) ?></td><td><?= e(fmt_datetime($payment['payment_date'])) ?></td></tr><?php endforeach; ?>
        <?php if (!$payments): ?><tr><td colspan="4" class="text-muted text-center py-3">No payment records.</td></tr><?php endif; ?>
    </tbody></table></div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
