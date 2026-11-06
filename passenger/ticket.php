<?php
/**
 * passenger/ticket.php
 * Printable tickets, one per booked seat. Only for the passenger's own CONFIRMED booking.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('PASSENGER');

$bookingId = filter_var($_GET['booking_id'] ?? null, FILTER_VALIDATE_INT);

$booking = false;
if ($bookingId) {
    $stmt = $pdo->prepare(
        "SELECT bk.booking_id, bk.booking_reference, bk.status,
                CONCAT(u.first_name, ' ', u.last_name) AS passenger_name,
                t.departure_time, r.origin, r.destination, b.bus_number, b.bus_type,
                CONCAT(d.first_name, ' ', d.last_name) AS driver_name,
                (SELECT p.payment_method FROM payments p
                 WHERE p.booking_id = bk.booking_id
                 ORDER BY p.payment_date DESC, p.payment_id DESC LIMIT 1) AS payment_method
         FROM bookings bk
         JOIN users   u ON u.user_id   = bk.user_id
         JOIN trips   t ON t.trip_id   = bk.trip_id
         JOIN routes  r ON r.route_id  = t.route_id
         JOIN buses   b ON b.bus_id    = t.bus_id
         JOIN drivers d ON d.driver_id = t.driver_id
         WHERE bk.booking_id = :id AND bk.user_id = :uid"
    );
    $stmt->execute([':id' => $bookingId, ':uid' => $_SESSION['user_id']]);
    $booking = $stmt->fetch();
}

if (!$booking) {
    set_flash('warning', 'Booking not found.');
    redirect('passenger/my_bookings.php');
}
if ($booking['status'] !== 'CONFIRMED') {
    set_flash('warning', 'Tickets are issued only after your booking is confirmed.');
    redirect('passenger/booking_confirmation.php?booking_id=' . $bookingId);
}

$tk = $pdo->prepare(
    "SELECT tk.ticket_number, bs.seat_number, bs.price
     FROM tickets tk JOIN booking_seats bs ON bs.booking_seat_id = tk.booking_seat_id
     WHERE bs.booking_id = :id AND bs.seat_status = 'BOOKED'
     ORDER BY bs.seat_number"
);
$tk->execute([':id' => $bookingId]);
$tickets = $tk->fetchAll();

$pageTitle = 'Tickets ' . $booking['booking_reference'];
$layout    = 'public';
require __DIR__ . '/../includes/header.php';
?>
<div class="container py-4" style="max-width: 860px;">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 no-print">
        <a href="<?= url('passenger/booking_confirmation.php?booking_id=' . (int)$bookingId) ?>" class="btn btn-booking-action btn-sm"><i class="bi bi-arrow-left"></i> Booking details</a>
        <?php if ($tickets): ?>
            <button type="button" class="btn btn-primary btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> Print tickets</button>
        <?php endif; ?>
    </div>

    <?php if (!$tickets): ?>
        <div class="alert alert-info">Your tickets are being prepared. Check back soon.</div>
    <?php endif; ?>

    <?php foreach ($tickets as $t): ?>
        <?php
        $paymentMethod = $booking['payment_method'] ?: 'UNKNOWN';
        $qrPayload = 'BusTicket|Reference:' . $booking['booking_reference']
            . '|Ticket:' . $t['ticket_number']
            . '|Payment method:' . payment_method_label($paymentMethod);
        ?>
        <div class="ticket">
            <div class="ticket-main">
                <div class="ticket-top">
                    <span class="brand-mark"><i class="bi bi-bus-front-fill"></i> <?= e(APP_NAME) ?></span>
                    <span class="ticket-no"><?= e($t['ticket_number']) ?></span>
                </div>
                <div class="ticket-route"><?= e($booking['origin']) ?> <i class="bi bi-arrow-right"></i> <?= e($booking['destination']) ?></div>
                <dl class="ticket-fields">
                    <div><dt>Passenger</dt><dd><?= e($booking['passenger_name']) ?></dd></div>
                    <div><dt>Date</dt><dd><?= e(fmt_date($booking['departure_time'])) ?></dd></div>
                    <div><dt>Departure</dt><dd><?= e(fmt_time($booking['departure_time'])) ?></dd></div>
                    <div><dt>Bus</dt><dd><?= e($booking['bus_number']) ?> (<?= e($booking['bus_type']) ?>)</dd></div>
                    <div><dt>Driver</dt><dd><?= e($booking['driver_name']) ?></dd></div>
                    <div><dt>Fare</dt><dd><?= money($t['price']) ?></dd></div>
                    <div><dt>Booking reference</dt><dd><?= e($booking['booking_reference']) ?></dd></div>
                    <div><dt>Booking status</dt><dd><?= e(ucfirst(strtolower($booking['status']))) ?></dd></div>
                </dl>
                <div class="ticket-qr" data-qr-value="<?= e($qrPayload) ?>" role="img" aria-label="Scan for ticket reference and payment method">
                    <span class="ticket-qr-fallback">QR code unavailable</span>
                </div>
            </div>
            <div class="ticket-stub">
                <span>Seat</span>
                <strong><?= (int)$t['seat_number'] ?></strong>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
<script>
document.querySelectorAll('.ticket-qr').forEach(function (container) {
    if (typeof QRCode === 'undefined') {
        container.setAttribute('aria-label', 'QR code unavailable. ' + container.textContent);
        return;
    }

    container.textContent = '';
    new QRCode(container, {
        text: container.dataset.qrValue,
        width: 96,
        height: 96,
        colorDark: '#12263A',
        colorLight: '#FFFFFF',
        correctLevel: QRCode.CorrectLevel.M
    });
});
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
