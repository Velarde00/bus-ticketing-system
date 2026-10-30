<?php
/**
 * index.php  Homepage with the trip search form.
 */
require_once __DIR__ . '/includes/auth.php';

// Cities for the dropdowns (only routes that are ACTIVE)
$origins = $pdo->query("SELECT DISTINCT origin FROM routes WHERE status = 'ACTIVE' ORDER BY origin")->fetchAll(PDO::FETCH_COLUMN);
$destinations = $pdo->query("SELECT DISTINCT destination FROM routes WHERE status = 'ACTIVE' ORDER BY destination")->fetchAll(PDO::FETCH_COLUMN);

// Routes with their lowest upcoming fare
$routes = $pdo->query(
    "SELECT r.origin, r.destination, r.distance_km,
            MIN(t.fare) AS from_fare, COUNT(t.trip_id) AS upcoming_trips
     FROM routes r
     LEFT JOIN trips t ON t.route_id = r.route_id AND t.status = 'SCHEDULED' AND t.departure_time > NOW()
     WHERE r.status = 'ACTIVE'
     GROUP BY r.route_id, r.origin, r.destination, r.distance_km
     ORDER BY r.origin, r.destination"
)->fetchAll();

$selOrigin = $_GET['origin'] ?? '';
$selDest   = $_GET['destination'] ?? '';

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
    <p class="text-muted mb-4">Choose a route to fill in the search form.</p>
    <div class="row g-3">
        <?php foreach ($routes as $r): ?>
            <div class="col-md-6 col-xl-4">
                <a class="route-card" href="<?= url('index.php') ?>?origin=<?= urlencode($r['origin']) ?>&amp;destination=<?= urlencode($r['destination']) ?>#search">
                    <div class="route-line">
                        <span><?= e($r['origin']) ?></span>
                        <i class="bi bi-arrow-right"></i>
                        <span><?= e($r['destination']) ?></span>
                    </div>
                    <div class="route-meta">
                        <span><?= e(number_format((float)$r['distance_km'])) ?> km</span>
                        <?php if ($r['upcoming_trips'] > 0): ?>
                            <span><?= (int)$r['upcoming_trips'] ?> upcoming trip<?= $r['upcoming_trips'] > 1 ? 's' : '' ?>, from <strong><?= money($r['from_fare']) ?></strong></span>
                        <?php else: ?>
                            <span>No trips scheduled yet</span>
                        <?php endif; ?>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
        <?php if (!$routes): ?>
            <div class="col-12"><div class="alert alert-info mb-0">No routes are available yet.</div></div>
        <?php endif; ?>
    </div>
</section>
<?php require __DIR__ . '/includes/footer.php'; ?>
