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
                t.departure_time, t.arrival_time, r.origin, r.destination, b.bus_number, b.bus_type,
                CONCAT(d.first_name, ' ', d.last_name) AS driver_name
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
    <!-- Top actions bar -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4 no-print">
        <a href="<?= url('passenger/booking_confirmation.php?booking_id=' . (int)$bookingId) ?>" class="btn btn-outline-secondary btn-sm rounded-pill">
            <i class="bi bi-arrow-left me-1"></i> Booking Details
        </a>
        <div class="d-flex gap-2">
            <a href="<?= url('passenger/my_bookings.php') ?>" class="btn btn-outline-dark btn-sm rounded-pill">
                <i class="bi bi-ticket-perforated me-1"></i> All Bookings
            </a>
            <?php if ($tickets): ?>
                <button type="button" class="btn btn-primary btn-sm rounded-pill shadow-sm" onclick="window.print()">
                    <i class="bi bi-printer-fill me-1"></i> Print Boarding Passes
                </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$tickets): ?>
        <div class="alert alert-info shadow-sm border-0 d-flex align-items-center gap-2">
            <i class="bi bi-info-circle-fill fs-5"></i>
            <div>Your tickets are being prepared by the administrator. Please check back shortly.</div>
        </div>
    <?php endif; ?>

    <!-- Render Each Boarding Pass -->
    <?php foreach ($tickets as $t): ?>
        <div class="ticket-boarding-pass shadow-sm rounded-4 overflow-hidden mb-4 border bg-white">
            <div class="row g-0">
                <!-- Main Boarding Pass Section -->
                <div class="col-md-8 col-lg-9 p-4 ticket-main-body position-relative">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="d-flex align-items-center gap-2">
                            <span class="brand-icon-wrapper brand-icon-sm">
                                <i class="bi bi-bus-front-fill"></i>
                            </span>
                            <span class="fw-bold fs-5 text-dark"><?= e(APP_NAME) ?> <span class="brand-tag">Express</span></span>
                        </div>
                        <div class="text-end">
                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 rounded-pill small">
                                <i class="bi bi-check-circle-fill me-1"></i> Confirmed
                            </span>
                            <div class="ticket-num-code text-muted small mt-1 font-monospace"><?= e($t['ticket_number']) ?></div>
                        </div>
                    </div>

                    <!-- Journey Route Header -->
                    <div class="ticket-route-headline my-3 p-3 bg-light rounded-3 d-flex justify-content-between align-items-center">
                        <div>
                            <span class="text-muted small d-block">Origin</span>
                            <span class="h5 fw-bold text-dark mb-0"><?= e($booking['origin']) ?></span>
                        </div>
                        <div class="px-3 text-center">
                            <i class="bi bi-bus-front text-primary fs-4"></i>
                            <div class="ticket-route-line"></div>
                        </div>
                        <div class="text-end">
                            <span class="text-muted small d-block">Destination</span>
                            <span class="h5 fw-bold text-dark mb-0"><?= e($booking['destination']) ?></span>
                        </div>
                    </div>

                    <!-- Passenger & Travel Details Grid -->
                    <div class="row g-3 ticket-meta-grid text-start">
                        <div class="col-6 col-sm-4">
                            <span class="ticket-meta-label">Passenger</span>
                            <span class="ticket-meta-val text-truncate d-block fw-semibold"><?= e($booking['passenger_name']) ?></span>
                        </div>
                        <div class="col-6 col-sm-4">
                            <span class="ticket-meta-label">Date</span>
                            <span class="ticket-meta-val fw-semibold"><?= e(fmt_date($booking['departure_time'])) ?></span>
                        </div>
                        <div class="col-6 col-sm-4">
                            <span class="ticket-meta-label">Departure</span>
                            <span class="ticket-meta-val text-primary fw-bold"><?= e(fmt_time($booking['departure_time'])) ?></span>
                        </div>
                        <div class="col-6 col-sm-4">
                            <span class="ticket-meta-label">Coach</span>
                            <span class="ticket-meta-val fw-semibold"><?= e($booking['bus_number']) ?></span>
                        </div>
                        <div class="col-6 col-sm-4">
                            <span class="ticket-meta-label">Class</span>
                            <span class="ticket-meta-val fw-semibold"><?= e($booking['bus_type']) ?></span>
                        </div>
                        <div class="col-6 col-sm-4">
                            <span class="ticket-meta-label">Booking Ref</span>
                            <span class="ticket-meta-val font-monospace fw-semibold"><?= e($booking['booking_reference']) ?></span>
                        </div>
                    </div>

                    <!-- Simulated Barcode Banner -->
                    <div class="ticket-barcode-strip mt-3 pt-3 border-top d-flex justify-content-between align-items-center">
                        <div class="barcode-visual">
                            <div class="barcode-lines"></div>
                            <span class="barcode-digits font-monospace small text-muted"><?= e($t['ticket_number']) ?></span>
                        </div>
                        <span class="text-muted small" style="font-size: 0.75rem;">Official e-Ticket &bull; Present upon boarding</span>
                    </div>
                </div>

                <!-- Stub Section (Seat & Tear Notch) -->
                <div class="col-md-4 col-lg-3 ticket-stub-modern p-4 d-flex flex-column justify-content-between text-center position-relative">
                    <!-- Perforated circles -->
                    <div class="stub-notch notch-top"></div>
                    <div class="stub-notch notch-bottom"></div>

                    <div>
                        <span class="badge bg-warning text-dark px-2 py-1 rounded-pill small fw-semibold mb-2">BOARDING PASS</span>
                        <div class="text-white-50 small mt-2">ASSIGNED SEAT</div>
                        <div class="stub-seat-number display-4 fw-bold text-signal my-1"><?= (int)$t['seat_number'] ?></div>
                        <div class="text-white-50 small">Coach <?= e($booking['bus_number']) ?></div>
                    </div>

                    <div class="pt-3 border-top border-secondary border-opacity-50">
                        <div class="text-white-50 small">Fare Paid</div>
                        <div class="fw-bold fs-5 text-white"><?= money($t['price']) ?></div>
                        <div class="text-white-50 font-monospace mt-1" style="font-size: 0.7rem;"><?= e($booking['booking_reference']) ?></div>
                    </div>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
