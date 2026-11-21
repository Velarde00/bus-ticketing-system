<?php
/**
 * passenger/trip_results.php
 * Shows trips that match the origin, destination, and date from the search form.
 * If no trips exist on that specific date, surfaces other upcoming scheduled departure dates.
 */
require_once __DIR__ . '/../includes/auth.php';

$origin = trim($_GET['origin'] ?? '');
$dest   = trim($_GET['destination'] ?? '');
$date   = trim($_GET['date'] ?? '');

$errors     = [];
$trips      = [];
$otherTrips = [];

$d = DateTime::createFromFormat('Y-m-d', $date);
if ($origin === '' || $dest === '' || !$d || $d->format('Y-m-d') !== $date) {
    $errors[] = 'Choose an origin, a destination, and a departure date.';
} elseif ($origin === $dest) {
    $errors[] = 'Origin and destination must be different.';
} elseif ($date < date('Y-m-d')) {
    $errors[] = 'Departure date cannot be in the past.';
} else {
    $stmt = $pdo->prepare(
        "SELECT t.trip_id, b.bus_number, b.bus_type, b.total_seats,
                r.origin, r.destination, r.distance_km, t.departure_time, t.arrival_time, t.fare,
                b.total_seats - (SELECT COUNT(*) FROM booking_seats bs
                                 WHERE bs.trip_id = t.trip_id AND bs.seat_status = 'BOOKED') AS available_seats
         FROM trips t
         JOIN buses  b ON b.bus_id   = t.bus_id
         JOIN routes r ON r.route_id = t.route_id
         WHERE r.origin = :origin AND r.destination = :dest
           AND DATE(t.departure_time) = :date
           AND t.departure_time > NOW()
           AND t.status = 'SCHEDULED' AND b.status = 'ACTIVE' AND r.status = 'ACTIVE'
         ORDER BY t.departure_time"
    );
    $stmt->execute([':origin' => $origin, ':dest' => $dest, ':date' => $date]);
    $trips = $stmt->fetchAll();

    // If no buses on the chosen date, find other upcoming dates for this route
    if (empty($trips)) {
        $otherStmt = $pdo->prepare(
            "SELECT t.trip_id, b.bus_number, b.bus_type, b.total_seats,
                    r.origin, r.destination, r.distance_km, t.departure_time, t.arrival_time, t.fare,
                    b.total_seats - (SELECT COUNT(*) FROM booking_seats bs
                                     WHERE bs.trip_id = t.trip_id AND bs.seat_status = 'BOOKED') AS available_seats
             FROM trips t
             JOIN buses  b ON b.bus_id   = t.bus_id
             JOIN routes r ON r.route_id = t.route_id
             WHERE r.origin = :origin AND r.destination = :dest
               AND t.departure_time > NOW()
               AND t.status = 'SCHEDULED' AND b.status = 'ACTIVE' AND r.status = 'ACTIVE'
             ORDER BY t.departure_time ASC
             LIMIT 6"
        );
        $otherStmt->execute([':origin' => $origin, ':dest' => $dest]);
        $otherTrips = $otherStmt->fetchAll();
    }
}

$pageTitle = 'Available trips';
$layout    = 'public';
require __DIR__ . '/../includes/header.php';
?>
<div class="container py-4">
    <!-- Breadcrumb / Search summary banner -->
    <div class="search-summary-bar p-3 p-md-4 rounded-4 mb-4 shadow-sm bg-white border">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <a href="<?= url('index.php') ?>#search" class="text-decoration-none small text-muted d-inline-flex align-items-center gap-1 mb-2">
                    <i class="bi bi-arrow-left"></i> Change search parameters
                </a>
                <h1 class="h4 mb-1 fw-bold d-flex align-items-center gap-2">
                    <span><?= e($origin ?: 'Origin') ?></span>
                    <i class="bi bi-arrow-right text-primary opacity-75"></i>
                    <span><?= e($dest ?: 'Destination') ?></span>
                </h1>
                <div class="d-flex flex-wrap align-items-center gap-3 text-muted small">
                    <span><i class="bi bi-calendar-event me-1 text-primary"></i> <?= $d ? e(fmt_date($date)) : 'Selected Date' ?></span>
                    <span>&bull;</span>
                    <span><i class="bi bi-bus-front me-1 text-primary"></i> <?= count($trips) ?> scheduled bus<?= count($trips) === 1 ? '' : 'es' ?> found</span>
                </div>
            </div>
            <a href="<?= url('index.php') ?>#search" class="btn btn-outline-dark btn-sm rounded-pill px-3">
                <i class="bi bi-pencil-square me-1"></i> Modify Search
            </a>
        </div>
    </div>

    <?php if ($errors): ?>
        <div class="alert alert-warning shadow-sm border-0 d-flex align-items-center gap-2" role="alert">
            <i class="bi bi-exclamation-triangle-fill fs-5"></i>
            <div><?= e($errors[0]) ?></div>
        </div>
        <div class="text-center py-4">
            <a href="<?= url('index.php') ?>#search" class="btn btn-primary rounded-pill px-4"><i class="bi bi-search me-1"></i> Back to search</a>
        </div>
    <?php else: ?>
        <?php if (!$trips): ?>
            <!-- No trips on searched date -->
            <div class="panel p-4 p-md-5 bg-white rounded-4 border shadow-sm mb-4">
                <div class="empty-state py-2 text-center">
                    <div class="empty-state-icon mb-3">
                        <i class="bi bi-calendar-x fs-1 text-warning"></i>
                    </div>
                    <h2 class="h5 fw-bold mb-2">No Buses Scheduled on <?= e(fmt_date($date)) ?></h2>
                    <p class="text-muted small max-w-500 mx-auto mb-4">
                        We could not find any buses leaving from <strong><?= e($origin) ?></strong> to <strong><?= e($dest) ?></strong> on <strong><?= e(fmt_date($date)) ?></strong>.
                    </p>
                    <div class="d-flex justify-content-center gap-2 mb-2">
                        <a href="<?= url('index.php') ?>#search" class="btn btn-outline-dark btn-sm rounded-pill px-3">
                            <i class="bi bi-search me-1"></i> Search another route
                        </a>
                    </div>
                </div>

                <!-- Show upcoming available dates for this route -->
                <?php if ($otherTrips): ?>
                    <div class="mt-4 pt-4 border-top">
                        <div class="d-flex align-items-center gap-2 mb-3">
                            <span class="badge bg-primary-subtle text-primary rounded-pill px-3 py-1 fw-semibold small">
                                <i class="bi bi-calendar-check-fill me-1"></i> Alternative Available Dates
                            </span>
                            <span class="text-muted small">Buses are available for this route on the following scheduled departures:</span>
                        </div>

                        <div class="row g-3">
                            <?php foreach ($otherTrips as $ot): ?>
                                <?php
                                    $otLeft = max(0, (int)$ot['available_seats']);
                                    $otDep = strtotime($ot['departure_time']);
                                    $otBusImg = url(bus_type_image($ot['bus_type'] ?? ''));
                                ?>
                                <div class="col-md-6">
                                    <div class="trip-card-subtle-bg p-3 rounded-4 border h-100 d-flex flex-column justify-content-between position-relative overflow-hidden shadow-sm"
                                         style="background: linear-gradient(135deg, rgba(255,255,255,0.92) 0%, rgba(248,250,252,0.85) 100%), url('<?= e($otBusImg) ?>') center center / cover no-repeat;">
                                        <div class="position-relative z-1">
                                            <div class="d-flex justify-content-between align-items-center mb-2">
                                                <span class="badge bg-primary text-white px-2 py-1 rounded-pill small">
                                                    <i class="bi bi-calendar-event me-1"></i> <?= e(date('l, M j, Y', $otDep)) ?>
                                                </span>
                                                <span class="badge bg-white text-dark border small font-monospace shadow-xs">Bus #<?= e($ot['bus_number']) ?></span>
                                            </div>
                                            <div class="h5 fw-bold text-dark mb-1">
                                                <i class="bi bi-clock-fill text-warning me-1"></i> <?= e(fmt_time($ot['departure_time'])) ?>
                                                <span class="text-muted small fw-normal">&rarr; <?= e(fmt_time($ot['arrival_time'])) ?></span>
                                            </div>
                                            <div class="d-flex align-items-center gap-2 mb-2">
                                                <span class="badge bg-white text-dark border shadow-xs small">
                                                    <i class="bi bi-bus-front text-primary me-1"></i> <?= e($ot['bus_type']) ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="d-flex justify-content-between align-items-center pt-2 border-top position-relative z-1">
                                            <div>
                                                <strong class="text-primary fs-5"><?= money($ot['fare']) ?></strong>
                                                <span class="badge bg-success-subtle text-success ms-1 small"><?= $otLeft ?> seats left</span>
                                                <div class="small text-success mt-1" style="font-size: 0.75rem;">
                                                    <i class="bi bi-tag-fill me-1"></i><?= money($ot['fare'] * 0.80) ?> with 20% discount
                                                </div>
                                            </div>
                                            <a href="<?= url('passenger/seat_selection.php?trip_id=' . (int)$ot['trip_id']) ?>" class="btn btn-primary btn-sm rounded-pill px-3 shadow-sm">
                                                Select Seats <i class="bi bi-arrow-right ms-1"></i>
                                            </a>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="d-flex justify-content-between align-items-center mb-3">
                <span class="text-muted small">
                    <i class="bi bi-calendar-check me-1 text-primary"></i> Scheduled departures for <strong><?= e(date('l, F j, Y', strtotime($date))) ?></strong>
                </span>
                <span class="badge bg-light text-muted border">Prices in PHP (₱)</span>
            </div>

            <div class="trip-list d-flex flex-column gap-3">
                <?php foreach ($trips as $t): ?>
                    <?php
                        $left = max(0, (int)$t['available_seats']);
                        $depTime = strtotime($t['departure_time']);
                        $arrTime = strtotime($t['arrival_time']);
                        $durationHours = $arrTime > $depTime ? round(($arrTime - $depTime) / 3600, 1) : null;
                        $busImg = url(bus_type_image($t['bus_type'] ?? ''));
                    ?>
                    <article class="trip-card-modern trip-card-with-bg shadow-sm rounded-4 border overflow-hidden p-3 p-md-4 position-relative"
                             style="background-image: linear-gradient(100deg, rgba(255,255,255,0.95) 0%, rgba(255,255,255,0.92) 55%, rgba(255,255,255,0.85) 100%), url('<?= e($busImg) ?>');">
                        <div class="trip-card-bus-watermark" style="background-image: url('<?= e($busImg) ?>');"></div>
                        <div class="row align-items-center g-3 position-relative z-1">
                            <!-- Bus & Operator Info -->
                            <div class="col-lg-3">
                                <div class="d-flex align-items-center gap-2 mb-2">
                                    <span class="badge bg-primary text-white fw-semibold px-2 py-1 rounded-pill shadow-xs">
                                        <i class="bi bi-bus-front me-1"></i> <?= e($t['bus_type']) ?>
                                    </span>
                                </div>
                                <div class="fw-bold fs-6 text-dark"><?= e(APP_NAME) ?> Express</div>
                                <div class="text-muted small">Bus #<?= e($t['bus_number']) ?></div>

                                <!-- Prominent Departure Date Stamp -->
                                <div class="mt-2">
                                    <span class="badge bg-white text-primary border border-primary-subtle px-2 py-1 rounded-pill small shadow-xs">
                                        <i class="bi bi-calendar-event me-1"></i> <?= e(date('D, M j, Y', $depTime)) ?>
                                    </span>
                                </div>
                            </div>

                            <!-- Timeline & Travel Times -->
                            <div class="col-lg-5">
                                <div class="trip-timeline d-flex align-items-center justify-content-between">
                                    <div class="text-start">
                                        <div class="small text-muted fw-semibold mb-1">
                                            <i class="bi bi-clock text-primary me-1"></i> Departure
                                        </div>
                                        <div class="trip-time-digit fw-bold fs-3 text-dark"><?= e(fmt_time($t['departure_time'])) ?></div>
                                        <div class="text-muted small fw-medium text-truncate" style="max-width: 120px;"><?= e($t['origin']) ?></div>
                                    </div>

                                    <div class="trip-journey-route flex-grow-1 px-3 text-center">
                                        <?php if ($durationHours): ?>
                                            <span class="trip-duration-badge text-muted small d-block mb-1"><?= $durationHours ?> hrs</span>
                                        <?php endif; ?>
                                        <div class="journey-line-wrapper position-relative">
                                            <div class="journey-line"></div>
                                            <i class="bi bi-bus-front-fill journey-bus-icon text-primary"></i>
                                        </div>
                                        <span class="text-muted small d-block mt-1"><?= e(number_format((float)$t['distance_km'])) ?> km</span>
                                    </div>

                                    <div class="text-end">
                                        <div class="small text-muted fw-semibold mb-1">
                                            <i class="bi bi-clock text-muted me-1"></i> Arrival
                                        </div>
                                        <div class="trip-time-digit fw-bold fs-3 text-dark"><?= e(fmt_time($t['arrival_time'])) ?></div>
                                        <div class="text-muted small fw-medium text-truncate" style="max-width: 120px;"><?= e($t['destination']) ?></div>
                                    </div>
                                </div>
                            </div>

                            <!-- Seat Availability -->
                            <div class="col-sm-6 col-lg-2 text-lg-center">
                                <?php if ($left <= 0): ?>
                                    <span class="badge bg-danger-subtle text-danger px-2 py-1 rounded-pill">
                                        <i class="bi bi-x-circle me-1"></i> Sold out
                                    </span>
                                <?php elseif ($left <= 5): ?>
                                    <span class="badge bg-danger-subtle text-danger px-2 py-1 rounded-pill mb-1 d-inline-block">
                                        <i class="bi bi-fire me-1"></i> Only <?= $left ?> seats left!
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-success-subtle text-success px-2 py-1 rounded-pill mb-1 d-inline-block">
                                        <i class="bi bi-check2-circle me-1"></i> <?= $left ?> seats available
                                    </span>
                                <?php endif; ?>
                                <div class="text-muted small">Total: <?= (int)$t['total_seats'] ?> seats</div>
                            </div>

                            <!-- Fare & Action Button -->
                            <div class="col-sm-6 col-lg-2 text-end">
                                <div class="mb-2">
                                    <span class="text-muted small d-block">Price per seat</span>
                                    <strong class="trip-price-val fs-4 text-primary"><?= money($t['fare']) ?></strong>
                                    <div class="mt-1">
                                        <span class="badge bg-success-subtle text-success border border-success-subtle rounded-pill small" style="font-size: 0.72rem;">
                                            <i class="bi bi-tag-fill me-1"></i> <?= money($t['fare'] * 0.80) ?> (20% off for Student/Senior/PWD)
                                        </span>
                                    </div>
                                </div>
                                <?php if ($left > 0): ?>
                                    <a class="btn btn-primary btn-sm w-100 rounded-pill shadow-sm d-inline-flex align-items-center justify-content-center gap-1"
                                       href="<?= url('passenger/seat_selection.php?trip_id=' . (int)$t['trip_id']) ?>">
                                        <span>Select Seats</span>
                                        <i class="bi bi-arrow-right"></i>
                                    </a>
                                <?php else: ?>
                                    <button class="btn btn-secondary btn-sm w-100 rounded-pill" disabled>Sold out</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
