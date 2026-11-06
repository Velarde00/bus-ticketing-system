<?php
/**
 * index.php  Homepage with the trip search form.
 */
require_once __DIR__ . '/includes/auth.php';

// Cities for the dropdowns (only routes that are ACTIVE)
$origins = $pdo->query("SELECT DISTINCT origin FROM routes WHERE status = 'ACTIVE' ORDER BY origin")->fetchAll(PDO::FETCH_COLUMN);
$destinations = $pdo->query("SELECT DISTINCT destination FROM routes WHERE status = 'ACTIVE' ORDER BY destination")->fetchAll(PDO::FETCH_COLUMN);

// Routes with their upcoming trips and lowest available fare
$routes = $pdo->query(
    "SELECT r.origin, r.destination, r.distance_km,
            COUNT(CASE WHEN b.bus_id IS NOT NULL THEN t.trip_id END) AS upcoming_trips
     FROM routes r
     LEFT JOIN trips t ON t.route_id = r.route_id AND t.status = 'SCHEDULED' AND t.departure_time > NOW()
     LEFT JOIN buses b ON b.bus_id = t.bus_id AND b.status = 'ACTIVE'
     WHERE r.status = 'ACTIVE'
     GROUP BY r.route_id, r.origin, r.destination, r.distance_km
     ORDER BY r.origin, r.destination"
)->fetchAll();

$scheduleRows = $pdo->query(
    "SELECT r.origin, r.destination, t.trip_id, t.departure_time, t.arrival_time, t.fare,
            b.bus_number, b.bus_type,
            b.total_seats - (
                SELECT COUNT(*) FROM booking_seats bs
                WHERE bs.trip_id = t.trip_id AND bs.seat_status = 'BOOKED'
            ) AS available_seats
     FROM trips t
     JOIN routes r ON r.route_id = t.route_id
     JOIN buses b ON b.bus_id = t.bus_id
     WHERE r.status = 'ACTIVE' AND b.status = 'ACTIVE'
       AND t.status = 'SCHEDULED' AND t.departure_time > NOW()
     ORDER BY r.origin, r.destination, t.departure_time"
)->fetchAll();

$tripsByRoute = [];
foreach ($scheduleRows as $trip) {
    $routeKey = $trip['origin'] . "\0" . $trip['destination'];
    $tripsByRoute[$routeKey][] = $trip;
}

$selOrigin = $_GET['origin'] ?? '';
$selDest   = $_GET['destination'] ?? '';
$canBook = !empty($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'PASSENGER';

$pageTitle = 'Book your bus seat';
$layout    = 'public';
require __DIR__ . '/includes/header.php';
?>
<section class="hero">
    <div class="container">
        <div class="row align-items-center g-5">
            <div class="col-lg-6">
                <h1 class="hero-title">Pick your trip.<br>Pick your seat.<br>Be on the bus.</h1>
                <p class="hero-sub">Search Philippine bus routes, choose seats on the seat map, and get a ticket you can print.</p>
            </div>
            <div class="col-lg-6" id="search">
                <form class="ticket-panel" method="get" action="<?= url('passenger/trip_results.php') ?>">
                    <h2 class="h5 mb-3">Find a trip</h2>
                    <div class="row g-3 align-items-end">
                        <div class="col-sm-5">
                            <label for="origin" class="form-label">From</label>
                            <select class="form-select" id="origin" name="origin" required>
                                <option value="">Origin</option>
                                <?php foreach ($origins as $city): ?>
                                    <option value="<?= e($city) ?>" <?= $city === $selOrigin ? 'selected' : '' ?>><?= e($city) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-sm-2 text-center">
                            <button type="button" class="btn btn-outline-dark btn-sm" id="swapCities" aria-label="Swap origin and destination">
                                <i class="bi bi-arrow-left-right"></i>
                            </button>
                        </div>
                        <div class="col-sm-5">
                            <label for="destination" class="form-label">To</label>
                            <select class="form-select" id="destination" name="destination" required>
                                <option value="">Destination</option>
                                <?php foreach ($destinations as $city): ?>
                                    <option value="<?= e($city) ?>" <?= $city === $selDest ? 'selected' : '' ?>><?= e($city) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12">
                            <label for="date" class="form-label">Departure date</label>
                            <input type="date" class="form-control" id="date" name="date" min="<?= date('Y-m-d') ?>" value="<?= e($_GET['date'] ?? '') ?>" required>
                        </div>
                    </div>
                    <div class="ticket-tear"></div>
                    <button type="submit" class="btn btn-primary w-100"><i class="bi bi-search"></i> Search buses</button>
                </form>
            </div>
        </div>
    </div>
</section>

<section class="container py-5">
    <h2 class="h4 mb-1">Routes we serve</h2>
    <p class="text-muted mb-4">See upcoming departure dates, times, fares, and available seats for each route.</p>
    <div class="row g-3">
        <?php foreach ($routes as $r): ?>
            <div class="col-md-6 col-xl-4">
                <div class="route-card">
                    <a class="route-line" href="<?= url('index.php') ?>?origin=<?= urlencode($r['origin']) ?>&amp;destination=<?= urlencode($r['destination']) ?>#search">
                        <span><?= e($r['origin']) ?></span>
                        <i class="bi bi-arrow-right"></i>
                        <span><?= e($r['destination']) ?></span>
                    </a>
                    <div class="route-meta">
                        <span><?= e(number_format((float)$r['distance_km'])) ?> km</span>
                    </div>
                    <?php
                    $routeKey = $r['origin'] . "\0" . $r['destination'];
                    $routeTrips = $tripsByRoute[$routeKey] ?? [];
                    ?>
                    <div class="route-schedules">
                        <div class="route-schedules-heading">
                            <strong>Upcoming departures</strong>
                            <?php if ((int)$r['upcoming_trips'] > 0): ?>
                                <span><?= (int)$r['upcoming_trips'] ?> trip<?= (int)$r['upcoming_trips'] === 1 ? '' : 's' ?></span>
                            <?php endif; ?>
                        </div>
                        <?php if ($routeTrips): ?>
                            <?php foreach ($routeTrips as $trip): ?>
                                <div class="route-schedule">
                                    <div>
                                        <span class="route-schedule-date"><?= e(fmt_date($trip['departure_time'])) ?></span>
                                        <span class="route-schedule-time"><?= e(fmt_time($trip['departure_time'])) ?></span>
                                        <span class="small text-muted"><?= e($trip['bus_number']) ?></span>
                                    </div>
                                    <div class="route-schedule-availability">
                                        <?php if ((int)$trip['available_seats'] > 0): ?>
                                            <span><?= (int)$trip['available_seats'] ?> seats left</span>
                                            <strong><?= money($trip['fare']) ?></strong>
                                        <?php else: ?>
                                            <span class="text-danger">Sold out</span>
                                            <strong><?= money($trip['fare']) ?></strong>
                                        <?php endif; ?>
                                    </div>
                                    <?php
                                    $tripUrl = $canBook
                                        ? url('passenger/seat_selection.php?trip_id=' . (int)$trip['trip_id'])
                                        : url('passenger/trip_results.php?origin=' . urlencode($trip['origin'])
                                            . '&destination=' . urlencode($trip['destination'])
                                            . '&date=' . urlencode(date('Y-m-d', strtotime($trip['departure_time']))));
                                    ?>
                                    <?php if ((int)$trip['available_seats'] > 0): ?>
                                        <a class="btn btn-primary btn-sm route-schedule-action" href="<?= e($tripUrl) ?>">
                                            <?= $canBook ? 'Select seats' : 'View trip' ?>
                                        </a>
                                    <?php else: ?>
                                        <span class="btn btn-secondary btn-sm route-schedule-action disabled" aria-disabled="true">Sold out</span>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <span class="small text-muted">No upcoming trips scheduled.</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if (!$routes): ?>
            <div class="col-12"><div class="alert alert-info mb-0">No routes are available yet.</div></div>
        <?php endif; ?>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
