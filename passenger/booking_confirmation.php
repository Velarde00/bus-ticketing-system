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
        "SELECT bk.booking_id, bk.booking_reference, bk.booking_date, bk.status, bk.cancelled_by, bk.cancellation_choice,
                t.trip_id, t.status AS trip_status, t.departure_time, t.arrival_time,
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
    <!-- Breadcrumb back link -->
    <div class="mb-3">
        <a href="<?= url('passenger/my_bookings.php') ?>" class="text-muted text-decoration-none small d-inline-flex align-items-center gap-1">
            <i class="bi bi-arrow-left"></i> Back to My Bookings
        </a>
    </div>

    <!-- Booking Header Card -->
    <div class="bg-white p-4 rounded-4 border shadow-sm mb-4">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <span class="text-muted small d-block">Booking Reference Number</span>
                <h1 class="h3 fw-bold mb-1 font-monospace text-dark"><?= e($booking['booking_reference']) ?></h1>
                <span class="text-muted small"><i class="bi bi-clock me-1"></i> Reserved on <?= e(fmt_datetime($booking['booking_date'])) ?></span>
            </div>
            <div>
                <?= status_badge($booking['status']) ?>
            </div>
        </div>

        <!-- Status notice alert -->
        <div class="mt-4 pt-3 border-top">
            <?php if ($booking['status'] === 'PENDING'): ?>
                <div class="alert alert-warning border-0 shadow-sm d-flex align-items-center gap-3 mb-0" role="alert">
                    <i class="bi bi-hourglass-split fs-4 flex-shrink-0 text-warning"></i>
                    <div>
                        <strong>Awaiting Confirmation &amp; Payment.</strong> Your seats are held for you. Settle the fare at the bus terminal counter or with the administrator to issue your printable boarding pass.
                    </div>
                </div>
            <?php elseif ($booking['status'] === 'CONFIRMED'): ?>
                <div class="alert alert-success border-0 shadow-sm d-flex align-items-center gap-3 mb-0" role="alert">
                    <i class="bi bi-check-circle-fill fs-4 flex-shrink-0 text-success"></i>
                    <div>
                        <strong>Booking Confirmed!</strong> Your payment has been received and verified. Your digital tickets are ready to print.
                    </div>
                </div>
            <?php else: ?>
                <?php
                    $isCancelledByAdmin = ($booking['cancelled_by'] === 'ADMIN') || ($booking['trip_status'] === 'CANCELLED');
                ?>
                <div class="alert alert-secondary border-0 shadow-sm d-flex align-items-center gap-3 mb-0" role="alert">
                    <i class="bi bi-x-circle-fill fs-4 flex-shrink-0 text-secondary"></i>
                    <div class="flex-grow-1">
                        <strong>Reservation Cancelled.</strong>
                        <?php if ($isCancelledByAdmin): ?>
                            This scheduled trip was cancelled by the bus terminal management.
                        <?php else: ?>
                            This booking was cancelled and the assigned seats were released back to the schedule.
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($isCancelledByAdmin): ?>
                    <div class="mt-3 p-3 bg-light rounded-3 border">
                        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                            <div>
                                <h3 class="h6 fw-bold mb-1 text-dark"><i class="bi bi-shield-exclamation me-1 text-warning"></i> Trip Cancellation Options</h3>
                                <p class="small text-muted mb-0">
                                    Since this trip was cancelled by the bus operator, you are entitled to either rebook another available bus or request a full refund.
                                </p>
                                <?php if (($payment['payment_status'] ?? '') === 'REFUNDED'): ?>
                                    <div class="mt-2">
                                        <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2 rounded-pill small">
                                            <i class="bi bi-check-circle-fill me-1"></i> Full Refund Automatically Sent to E-Wallet
                                            <?php if (!empty($payment['refund_transfer_reference'])): ?>
                                                (Ref: <?= e($payment['refund_transfer_reference']) ?>)
                                            <?php endif; ?>
                                        </span>
                                    </div>
                                <?php elseif ($booking['cancellation_choice'] === 'REFUND_REQUESTED'): ?>
                                    <div class="mt-2">
                                        <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-3 py-2 rounded-pill small">
                                            <i class="bi bi-hourglass-split me-1"></i> Full Refund Requested — Processing by Terminal
                                        </span>
                                    </div>
                                <?php elseif ($booking['cancellation_choice'] === 'REBOOK_REQUESTED'): ?>
                                    <div class="mt-2">
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2 rounded-pill small">
                                            <i class="bi bi-arrow-repeat me-1"></i> Rebooking in progress
                                        </span>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="d-flex flex-wrap gap-2">
                                <?php if (($payment['payment_status'] ?? '') !== 'REFUNDED'): ?>
                                    <a href="<?= url('passenger/rebook_trip.php?booking_id=' . (int)$booking['booking_id']) ?>" class="btn btn-primary btn-sm rounded-pill px-3 shadow-sm">
                                        <i class="bi bi-arrow-repeat me-1"></i> Rebook Another Bus
                                    </a>
                                    <form method="post" action="<?= url('actions/handle_cancellation_choice.php') ?>" class="m-0" data-confirm="Instant Refund: Disburse full refund directly to your registered E-Wallet (<?= e($_SESSION['phone_number'] ?? 'on file') ?>)?">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="booking_id" value="<?= (int)$booking['booking_id'] ?>">
                                        <input type="hidden" name="choice" value="REFUND">
                                        <button type="submit" class="btn btn-outline-danger btn-sm rounded-pill px-3 shadow-sm">
                                            <i class="bi bi-lightning-charge-fill me-1"></i> Instant Refund to E-Wallet
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <a href="<?= url('passenger/rebook_trip.php?booking_id=' . (int)$booking['booking_id']) ?>" class="btn btn-outline-primary btn-sm rounded-pill px-3 shadow-sm">
                                        <i class="bi bi-search me-1"></i> Book Another Trip
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Trip Information Card -->
    <div class="panel bg-white rounded-4 border shadow-sm mb-4 overflow-hidden">
        <div class="panel-head p-3 px-4 border-bottom bg-light bg-opacity-50">
            <h2 class="h5 fw-bold mb-0"><i class="bi bi-signpost-split me-2 text-primary"></i>Trip Details</h2>
        </div>
        <div class="p-4">
            <div class="row g-4">
                <div class="col-sm-6">
                    <span class="text-muted small d-block mb-1">Departure Route</span>
                    <div class="h5 fw-bold text-dark mb-0"><?= e($booking['origin']) ?> &rarr; <?= e($booking['destination']) ?></div>
                </div>
                <div class="col-sm-6">
                    <span class="text-muted small d-block mb-1">Coach Number &amp; Class</span>
                    <div class="fw-semibold text-dark mb-0">Bus <?= e($booking['bus_number']) ?> (<?= e($booking['bus_type']) ?>)</div>
                </div>
                <div class="col-sm-6">
                    <span class="text-muted small d-block mb-1">Departure Time</span>
                    <div class="fw-bold text-primary mb-0"><i class="bi bi-calendar-check me-1"></i> <?= e(fmt_datetime($booking['departure_time'])) ?></div>
                </div>
                <div class="col-sm-6">
                    <span class="text-muted small d-block mb-1">Expected Arrival</span>
                    <div class="fw-semibold text-dark mb-0"><i class="bi bi-calendar-event me-1"></i> <?= e(fmt_datetime($booking['arrival_time'])) ?></div>
                </div>
                <div class="col-12 border-top pt-3">
                    <span class="text-muted small d-block mb-1">Assigned Driver</span>
                    <div class="fw-semibold text-dark mb-0"><i class="bi bi-person-badge me-1 text-primary"></i> <?= e($booking['driver_name']) ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Seats & Payment Breakdown Card -->
    <div class="panel bg-white rounded-4 border shadow-sm mb-4 overflow-hidden">
        <div class="panel-head p-3 px-4 border-bottom bg-light bg-opacity-50 d-flex justify-content-between align-items-center">
            <h2 class="h5 fw-bold mb-0"><i class="bi bi-cash-coin me-2 text-primary"></i>Reserved Seats &amp; Payment</h2>
            <span class="badge bg-light text-muted border"><?= count($seats) ?> seat<?= count($seats) === 1 ? '' : 's' ?></span>
        </div>
        <div class="table-responsive">
            <table class="table align-middle mb-0 custom-modern-table">
                <thead>
                    <tr>
                        <th class="ps-4">Seat #</th>
                        <th>Price</th>
                        <th>Seat Status</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($seats as $s): ?>
                    <tr>
                        <td class="ps-4 fw-bold">
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-1 rounded-pill">
                                Seat <?= (int)$s['seat_number'] ?>
                            </span>
                        </td>
                        <td class="fw-semibold"><?= money($s['price']) ?></td>
                        <td><?= status_badge($s['seat_status'] === 'BOOKED' ? 'ACTIVE' : 'CANCELLED') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="table-light">
                        <td class="ps-4 fw-bold fs-6">Total Amount:</td>
                        <td colspan="2" class="fw-bold fs-5 text-primary"><?= money($total) ?></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <div class="p-4 border-top bg-light bg-opacity-25">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <span class="text-muted small d-block">Recorded Payment Method:</span>
                    <?php if ($payment): ?>
                        <div class="fw-semibold text-dark mt-1">
                            <i class="bi bi-wallet2 text-primary me-1"></i>
                            <?= e(str_replace('_', ' ', ucfirst(strtolower($payment['payment_method'])))) ?>
                            &bull; <?= money($payment['amount']) ?>
                        </div>
                    <?php else: ?>
                        <span class="text-muted small">No payment record attached yet.</span>
                    <?php endif; ?>
                </div>
                <div>
                    <?php if ($payment): ?>
                        <?= status_badge($payment['payment_status']) ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center">
        <div>
            <?php if ($booking['status'] === 'CONFIRMED'): ?>
                <a class="btn btn-primary rounded-pill shadow-sm px-4" href="<?= url('passenger/ticket.php?booking_id=' . (int)$booking['booking_id']) ?>">
                    <i class="bi bi-printer-fill me-1"></i> View &amp; Print Tickets
                </a>
            <?php endif; ?>
        </div>
        <div>
            <?php if ($canCancel): ?>
                <form method="post" action="<?= url('actions/cancel_booking.php') ?>" data-confirm="Cancel this reservation? Your seats will be released immediately." class="m-0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="booking_id" value="<?= (int)$booking['booking_id'] ?>">
                    <button type="submit" class="btn btn-outline-danger btn-sm rounded-pill px-3">
                        <i class="bi bi-trash3 me-1"></i> Cancel Reservation
                    </button>
                </form>
            <?php elseif ($booking['status'] !== 'CANCELLED'): ?>
                <span class="text-muted small"><i class="bi bi-lock me-1"></i> Cannot be cancelled within 2 hours of departure.</span>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
