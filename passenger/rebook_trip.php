<?php
/**
 * passenger/rebook_trip.php
 * Suggests similar trips for a passenger whose booking was cancelled by the administrator / schedule cancellation.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('PASSENGER');

$uid       = (int)$_SESSION['user_id'];
$bookingId = filter_var($_GET['booking_id'] ?? null, FILTER_VALIDATE_INT);

if (!$bookingId) {
    set_flash('warning', 'Choose a booking first.');
    redirect('passenger/my_bookings.php');
}

$stmt = $pdo->prepare(
    "SELECT bk.booking_id, bk.booking_reference, bk.status, bk.cancelled_by, bk.cancellation_choice,
            r.route_id, r.origin, r.destination, r.distance_km,
            t.trip_id, t.departure_time, t.arrival_time, t.fare,
            b.bus_number, b.bus_type
     FROM bookings bk
     JOIN trips  t ON t.trip_id  = bk.trip_id
     JOIN routes r ON r.route_id = t.route_id
     JOIN buses  b ON b.bus_id   = t.bus_id
     WHERE bk.booking_id = :id AND bk.user_id = :uid"
);
$stmt->execute([':id' => $bookingId, ':uid' => $uid]);
$booking = $stmt->fetch();

if (!$booking) {
    set_flash('warning', 'Booking not found.');
    redirect('passenger/my_bookings.php');
}

// Search for similar trips:
// 1) Same route (origin -> destination), scheduled in the future, active status, bus active
$simStmt = $pdo->prepare(
    "SELECT t.trip_id, t.departure_time, t.arrival_time, t.fare,
            r.origin, r.destination, r.distance_km,
            b.bus_number, b.bus_type, b.total_seats,
            b.total_seats - (SELECT COUNT(*) FROM booking_seats bs WHERE bs.trip_id = t.trip_id AND bs.seat_status = 'BOOKED') AS available_seats
     FROM trips t
     JOIN routes r ON r.route_id = t.route_id
     JOIN buses  b ON b.bus_id   = t.bus_id
     WHERE r.origin = :orig AND r.destination = :dest
       AND t.trip_id <> :old_trip_id
       AND t.status = 'SCHEDULED'
       AND t.departure_time > NOW()
       AND r.status = 'ACTIVE'
       AND b.status = 'ACTIVE'
     ORDER BY t.departure_time ASC
     LIMIT 8"
);
$simStmt->execute([
    ':orig'        => $booking['origin'],
    ':dest'        => $booking['destination'],
    ':old_trip_id' => $booking['trip_id'],
]);
$similarTrips = $simStmt->fetchAll();

$pageTitle = 'Rebook Your Trip - ' . $booking['booking_reference'];
$layout    = 'public';
require __DIR__ . '/../includes/header.php';
?>
<div class="container py-4">
    <!-- Breadcrumb back link -->
    <div class="mb-3">
        <a href="<?= url('passenger/booking_confirmation.php?booking_id=' . (int)$bookingId) ?>" class="text-muted text-decoration-none small d-inline-flex align-items-center gap-1">
            <i class="bi bi-arrow-left"></i> Back to Booking Details
        </a>
    </div>

    <!-- Banner explaining rebooking context -->
    <div class="alert alert-primary border-0 rounded-4 shadow-sm p-4 mb-4">
        <div class="d-flex align-items-start gap-3">
            <div class="p-2 bg-primary text-white rounded-circle flex-shrink-0 d-flex align-items-center justify-content-center" style="width: 44px; height: 44px;">
                <i class="bi bi-arrow-repeat fs-4"></i>
            </div>
            <div class="flex-grow-1">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-1">
                    <h1 class="h5 fw-bold mb-0 text-primary-emphasis">Rebooking Trip: <?= e($booking['booking_reference']) ?></h1>
                    <span class="badge bg-warning text-dark px-3 py-1 rounded-pill small">Trip Cancelled by Terminal</span>
                </div>
                <p class="small text-muted mb-2">
                    Your scheduled trip from <strong><?= e($booking['origin']) ?></strong> to <strong><?= e($booking['destination']) ?></strong> (was set for <?= e(fmt_datetime($booking['departure_time'])) ?>) was cancelled by the bus operator.
                </p>
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <span class="badge bg-white text-dark border px-2 py-1 small">
                        <i class="bi bi-bus-front me-1 text-primary"></i> Same Route: <?= e($booking['origin']) ?> &rarr; <?= e($booking['destination']) ?>
                    </span>
                    <form method="post" action="<?= url('actions/handle_cancellation_choice.php') ?>" class="m-0 d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="booking_id" value="<?= (int)$booking['booking_id'] ?>">
                        <input type="hidden" name="choice" value="REFUND">
                        <button type="submit" class="btn btn-outline-danger btn-sm rounded-pill px-3 shadow-sm">
                            <i class="bi bi-arrow-counterclockwise me-1"></i> Prefer a Refund instead?
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Suggested Similar Trips -->
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
        <div>
            <h2 class="h5 fw-bold mb-0">Suggested Available Trips</h2>
            <p class="text-muted small mb-0">Choose from upcoming scheduled buses along the same route to complete your journey.</p>
        </div>
        <a href="<?= url('index.php') ?>#search" class="btn btn-outline-dark btn-sm rounded-pill px-3">
            <i class="bi bi-search me-1"></i> Search Other Routes
        </a>
    </div>

    <?php if (!$similarTrips): ?>
        <div class="panel p-5 bg-white rounded-4 border shadow-sm text-center">
            <div class="empty-state py-3">
                <i class="bi bi-calendar-x fs-1 text-warning mb-3 d-block"></i>
                <h3 class="h5 fw-bold mb-2">No Other Scheduled Trips Found on This Route Right Now</h3>
                <p class="text-muted small max-w-500 mx-auto mb-4">
                    There are currently no other scheduled departures between <strong><?= e($booking['origin']) ?></strong> and <strong><?= e($booking['destination']) ?></strong>. You can request a full refund, or search for trips on nearby dates.
                </p>
                <div class="d-flex justify-content-center gap-2">
                    <form method="post" action="<?= url('actions/handle_cancellation_choice.php') ?>" class="m-0">
                        <?= csrf_field() ?>
                        <input type="hidden" name="booking_id" value="<?= (int)$booking['booking_id'] ?>">
                        <input type="hidden" name="choice" value="REFUND">
                        <button type="submit" class="btn btn-danger rounded-pill px-4 shadow-sm">
                            <i class="bi bi-arrow-counterclockwise me-1"></i> Request Full Refund
                        </button>
                    </form>
                    <a href="<?= url('index.php') ?>#search" class="btn btn-outline-dark rounded-pill px-4">
                        <i class="bi bi-search me-1"></i> Search Schedule
                    </a>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($similarTrips as $st): ?>
                <?php
                    $left = max(0, (int)$st['available_seats']);
                    $depTs = strtotime($st['departure_time']);
                    $arrTs = strtotime($st['arrival_time']);
                    $dur = $arrTs > $depTs ? round(($arrTs - $depTs) / 3600, 1) : null;
                ?>
                <div class="col-lg-6">
                    <div class="bg-white p-4 rounded-4 border shadow-sm h-100 d-flex flex-column justify-content-between">
                        <div>
                            <!-- Header pill: Departure Date -->
                            <div class="d-flex justify-content-between align-items-center mb-3 pb-2 border-bottom">
                                <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2 rounded-pill fw-semibold">
                                    <i class="bi bi-calendar-event me-1"></i> <?= e(date('l, F j, Y', $depTs)) ?>
                                </span>
                                <span class="badge bg-light text-dark border px-2 py-1 rounded-pill small">
                                    <i class="bi bi-bus-front me-1 text-primary"></i> Bus <?= e($st['bus_number']) ?> (<?= e($st['bus_type']) ?>)
                                </span>
                            </div>

                            <!-- Route Visual -->
                            <div class="d-flex align-items-center justify-content-between my-3">
                                <div>
                                    <div class="h4 fw-bold text-dark mb-0"><?= e(fmt_time($st['departure_time'])) ?></div>
                                    <span class="text-muted small fw-semibold"><?= e($st['origin']) ?></span>
                                </div>
                                <div class="flex-grow-1 px-3 text-center">
                                    <?php if ($dur): ?>
                                        <span class="small text-muted d-block mb-1"><?= $dur ?> hrs</span>
                                    <?php endif; ?>
                                    <div class="position-relative d-flex align-items-center justify-content-center">
                                        <div style="height: 2px; width: 100%; background: #CBD5E1;"></div>
                                        <i class="bi bi-arrow-right-circle-fill text-primary position-absolute bg-white px-1 fs-5"></i>
                                    </div>
                                    <span class="small text-muted d-block mt-1"><?= e(number_format((float)$st['distance_km'])) ?> km</span>
                                </div>
                                <div class="text-end">
                                    <div class="h4 fw-bold text-dark mb-0"><?= e(fmt_time($st['arrival_time'])) ?></div>
                                    <span class="text-muted small fw-semibold"><?= e($st['destination']) ?></span>
                                </div>
                            </div>
                        </div>

                        <!-- Card Footer -->
                        <div class="d-flex flex-wrap justify-content-between align-items-center pt-3 border-top mt-3 gap-2">
                            <div>
                                <?php if ($left <= 5): ?>
                                    <span class="badge bg-danger-subtle text-danger px-2 py-1 rounded-pill small">
                                        <i class="bi bi-fire me-1"></i> Only <?= $left ?> seats left
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-success-subtle text-success px-2 py-1 rounded-pill small">
                                        <i class="bi bi-check2-circle me-1"></i> <?= $left ?> seats available
                                    </span>
                                <?php endif; ?>
                                <div class="fw-bold fs-5 text-primary mt-1">
                                    <?= money($st['fare']) ?> <span class="small fw-normal text-muted">/ seat</span>
                                </div>
                            </div>
                            <div>
                                <?php if ($left > 0): ?>
                                    <a href="<?= url('passenger/seat_selection.php?trip_id=' . (int)$st['trip_id']) ?>" class="btn btn-primary rounded-pill px-4 shadow-sm">
                                        Rebook this Bus <i class="bi bi-arrow-right ms-1"></i>
                                    </a>
                                <?php else: ?>
                                    <button class="btn btn-secondary rounded-pill px-3" disabled>Sold out</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
