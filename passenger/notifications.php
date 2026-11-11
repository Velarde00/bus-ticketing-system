<?php
/**
 * passenger/notifications.php
 * Displays trip alerts, status updates, and booking notifications for the logged-in passenger.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('PASSENGER');

$uid = (int)$_SESSION['user_id'];

// Fetch the passenger's recent bookings with trip details to generate live notification alerts
$stmt = $pdo->prepare(
    "SELECT bk.booking_id, bk.booking_reference, bk.booking_date, bk.status, bk.cancelled_by, bk.cancellation_choice,
            r.origin, r.destination, t.departure_time, t.status AS trip_status, b.bus_number, b.bus_type,
            (SELECT p.payment_status FROM payments p WHERE p.booking_id = bk.booking_id
             ORDER BY p.payment_date DESC, p.payment_id DESC LIMIT 1) AS payment_status
     FROM bookings bk
     JOIN trips  t ON t.trip_id  = bk.trip_id
     JOIN routes r ON r.route_id = t.route_id
     JOIN buses  b ON b.bus_id   = t.bus_id
     WHERE bk.user_id = :uid
     ORDER BY bk.booking_date DESC
     LIMIT 20"
);
$stmt->execute([':uid' => $uid]);
$bookings = $stmt->fetchAll();

$pageTitle = 'Notifications';
$layout    = 'public';
require __DIR__ . '/../includes/header.php';
?>
<div class="container py-4" style="max-width: 860px;">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1 d-flex align-items-center gap-2">
                <i class="bi bi-bell-fill text-primary"></i> Notifications
            </h1>
            <p class="text-muted small mb-0">Stay informed about your booking confirmations, departure reminders, and payment updates.</p>
        </div>
        <a href="<?= url('passenger/my_bookings.php') ?>" class="btn btn-outline-dark btn-sm rounded-pill px-3">
            <i class="bi bi-ticket-perforated me-1"></i> My Bookings
        </a>
    </div>

    <?php if (!$bookings): ?>
        <div class="panel p-5 bg-white rounded-4 border shadow-sm text-center">
            <div class="empty-state py-4">
                <div class="empty-state-icon mb-3">
                    <i class="bi bi-bell-slash fs-1 text-muted"></i>
                </div>
                <h2 class="h5 fw-bold mb-2">No Notifications Yet</h2>
                <p class="text-muted small max-w-500 mx-auto mb-4">
                    You don't have any notifications or booking alerts at this time. When you book a trip or when your payment is confirmed, updates will appear here.
                </p>
                <a href="<?= url('index.php') ?>#search" class="btn btn-primary rounded-pill px-4 shadow-sm">
                    <i class="bi bi-search me-1"></i> Search and Book Trips
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="d-flex flex-column gap-3">
            <?php foreach ($bookings as $b): ?>
                <?php
                    $depTs = strtotime($b['departure_time']);
                    $isUpcoming = $depTs > time() && $b['status'] !== 'CANCELLED';
                    $hoursUntil = ($depTs - time()) / 3600;

                    // Determine notification styling based on booking state
                    if ($b['status'] === 'CONFIRMED') {
                        $badgeClass = 'bg-success-subtle text-success border border-success-subtle';
                        $iconClass  = 'bi-check-circle-fill text-success';
                        $title      = 'Booking Confirmed & Ready for Boarding';
                        $msg        = "Your booking {$b['booking_reference']} from {$b['origin']} to {$b['destination']} is confirmed. Digital tickets are ready to print.";
                    } elseif ($b['status'] === 'PENDING') {
                        $badgeClass = 'bg-warning-subtle text-dark border border-warning-subtle';
                        $iconClass  = 'bi-hourglass-split text-warning';
                        $title      = 'Payment Awaiting Verification';
                        $msg        = "Reservation {$b['booking_reference']} ({$b['origin']} → {$b['destination']}) is recorded. Please settle or verify your e-wallet payment.";
                    } else {
                        $isCancelledByAdmin = ($b['cancelled_by'] === 'ADMIN') || ($b['trip_status'] === 'CANCELLED');
                        if ($isCancelledByAdmin) {
                            $badgeClass = 'bg-danger-subtle text-danger border border-danger-subtle';
                            $iconClass  = 'bi-exclamation-octagon-fill text-danger';
                            $title      = 'Trip Cancelled by Operator — Action Required';
                            $msg        = "Your scheduled bus for booking {$b['booking_reference']} ({$b['origin']} → {$b['destination']}) was cancelled by the bus company. You can rebook a similar trip or request a full refund.";
                        } else {
                            $badgeClass = 'bg-secondary-subtle text-muted border';
                            $iconClass  = 'bi-x-circle-fill text-secondary';
                            $title      = 'Booking Cancelled';
                            $msg        = "Reservation {$b['booking_reference']} was cancelled by you and assigned seats were released.";
                        }
                    }
                ?>
                <div class="card border rounded-4 shadow-sm p-3 p-md-4 bg-white">
                    <div class="d-flex align-items-start gap-3">
                        <div class="p-2 rounded-circle bg-light flex-shrink-0 d-flex align-items-center justify-content-center" style="width: 44px; height: 44px;">
                            <i class="bi <?= $iconClass ?> fs-4"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-1">
                                <h2 class="h6 fw-bold mb-0 text-dark"><?= e($title) ?></h2>
                                <span class="badge <?= $badgeClass ?> px-2 py-1 rounded-pill small">
                                    <?= e($b['status']) ?>
                                </span>
                            </div>
                            <p class="text-muted small mb-2"><?= e($msg) ?></p>

                            <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 pt-2 border-top">
                                <div class="d-flex flex-wrap align-items-center gap-2 text-muted small" style="font-size: 0.78rem;">
                                    <span><i class="bi bi-clock me-1 text-primary"></i> Departure: <?= e(fmt_date($b['departure_time'])) ?> &bull; <?= e(fmt_time($b['departure_time'])) ?></span>
                                    <span>&bull;</span>
                                    <span>Bus <?= e($b['bus_number']) ?> (<?= e($b['bus_type']) ?>)</span>
                                </div>
                                <div>
                                    <a href="<?= url('passenger/booking_confirmation.php?booking_id=' . (int)$b['booking_id']) ?>" class="btn btn-outline-primary btn-sm rounded-pill px-3" style="font-size: 0.78rem;">
                                        View Details <i class="bi bi-arrow-right ms-1"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
