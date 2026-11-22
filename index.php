<?php
/**
 * index.php  Homepage with the trip search form, scheduled available bus timetable,
 * and regional route showcase.
 */
require_once __DIR__ . '/includes/auth.php';

// Cities for the dropdowns (only routes that are ACTIVE)
$origins = $pdo->query("SELECT DISTINCT origin FROM routes WHERE status = 'ACTIVE' ORDER BY origin")->fetchAll(PDO::FETCH_COLUMN);
$destinations = $pdo->query("SELECT DISTINCT destination FROM routes WHERE status = 'ACTIVE' ORDER BY destination")->fetchAll(PDO::FETCH_COLUMN);

// All upcoming scheduled trips with date, time, bus info, and available seats
$upcomingTrips = $pdo->query(
    "SELECT t.trip_id, t.departure_time, t.arrival_time, t.fare,
            r.origin, r.destination, r.distance_km,
            b.bus_number, b.bus_type, b.total_seats,
            b.total_seats - (SELECT COUNT(*) FROM booking_seats bs WHERE bs.trip_id = t.trip_id AND bs.seat_status = 'BOOKED') AS available_seats
     FROM trips t
     JOIN routes r ON r.route_id = t.route_id
     JOIN buses b ON b.bus_id = t.bus_id
     WHERE t.status = 'SCHEDULED'
       AND t.departure_time > NOW()
       AND r.status = 'ACTIVE'
       AND b.status = 'ACTIVE'
     ORDER BY t.departure_time ASC
     LIMIT 8"
)->fetchAll();

// Routes with their lowest upcoming fare and next departure date/time, plus bus type of next departure
$routes = $pdo->query(
    "SELECT r.route_id, r.origin, r.destination, r.distance_km,
            MIN(t.fare) AS from_fare,
            COUNT(t.trip_id) AS upcoming_trips,
            MIN(t.departure_time) AS next_departure,
            (SELECT b.bus_type FROM trips t2 JOIN buses b ON b.bus_id = t2.bus_id
             WHERE t2.route_id = r.route_id AND t2.status = 'SCHEDULED' AND t2.departure_time > NOW()
             ORDER BY t2.departure_time ASC LIMIT 1) AS next_bus_type
     FROM routes r
     LEFT JOIN trips t ON t.route_id = r.route_id AND t.status = 'SCHEDULED' AND t.departure_time > NOW()
     WHERE r.status = 'ACTIVE'
     GROUP BY r.route_id, r.origin, r.destination, r.distance_km
     ORDER BY r.origin, r.destination"
)->fetchAll();

$selOrigin = $_GET['origin'] ?? '';
$selDest   = $_GET['destination'] ?? '';
$earliestTripDate = !empty($upcomingTrips) ? date('Y-m-d', strtotime($upcomingTrips[0]['departure_time'])) : date('Y-m-d');
$selDate   = $_GET['date'] ?? $earliestTripDate;

$pageTitle = 'Book your bus seat';
$layout    = 'public';
require __DIR__ . '/includes/header.php';
?>
<!-- Hero Section -->
<section class="hero hero-elevated position-relative overflow-hidden">
    <div class="hero-ambient-glow"></div>
    <div class="container position-relative z-1">
        <div class="row align-items-center g-5 py-2">
            <div class="col-lg-6 text-white">
                <div class="hero-badge mb-3 d-inline-flex align-items-center gap-2">
                    <span class="badge-dot"></span>
                    <span>Official Zamboanga &amp; Region 9 Bus Booking Portal</span>
                </div>
                <h1 class="hero-title mb-3">
                    Pick your trip.<br>
                    Pick your seat.<br>
                    <span class="text-signal">Be on the bus.</span>
                </h1>
                <p class="hero-sub mb-4">
                    Fast, secure intercity travel across Zamboanga City and Western Mindanao. View real-time seating layouts, book in minutes, and print your digital boarding pass.
                </p>

                <!-- Popular Route Shortcuts with Next Departure Time & Date -->
                <?php if ($routes): ?>
                    <div class="hero-quick-routes pt-2">
                        <span class="small text-white-50 d-block mb-2">
                            <i class="bi bi-clock-history text-warning me-1"></i> Scheduled upcoming routes (click to select):
                        </span>
                        <div class="d-flex flex-wrap gap-2">
                            <?php foreach (array_slice($routes, 0, 4) as $qr): ?>
                                <?php
                                    $qrDate = !empty($qr['next_departure']) ? date('Y-m-d', strtotime($qr['next_departure'])) : '';
                                    $qrTime = !empty($qr['next_departure']) ? fmt_time($qr['next_departure']) : '';
                                    $qrDateFmt = !empty($qr['next_departure']) ? fmt_date($qr['next_departure']) : '';
                                ?>
                                <button type="button" class="btn btn-outline-light btn-sm hero-chip"
                                        onclick="fillSearch('<?= e(addslashes($qr['origin'])) ?>', '<?= e(addslashes($qr['destination'])) ?>', '<?= e($qrDate) ?>')">
                                    <?= e($qr['origin']) ?> <i class="bi bi-arrow-right mx-1 opacity-75"></i> <?= e($qr['destination']) ?>
                                    <?php if ($qrDateFmt): ?>
                                        <span class="badge bg-warning text-dark ms-1 small"><?= e($qrDateFmt) ?> &bull; <?= e($qrTime) ?></span>
                                    <?php endif; ?>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="col-lg-6" id="search">
                <form class="ticket-panel shadow-lg" method="get" action="<?= url('passenger/trip_results.php') ?>">
                    <div class="ticket-panel-header d-flex justify-content-between align-items-center mb-4">
                        <div class="d-flex align-items-center gap-2">
                            <span class="ticket-panel-icon"><i class="bi bi-search"></i></span>
                            <div>
                                <h2 class="h5 mb-0 fw-bold">Find a Trip</h2>
                                <small class="text-muted">Search scheduled departures</small>
                            </div>
                        </div>
                        <div class="d-flex flex-column align-items-end gap-1">
                            <span class="badge bg-light text-primary border px-2 py-1"><i class="bi bi-shield-check"></i> Instant Booking</span>
                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1" style="font-size: 0.72rem;">
                                <i class="bi bi-tag-fill me-1"></i> 20% Off for Student / Senior / PWD
                            </span>
                        </div>
                    </div>

                    <div class="row g-3 align-items-center">
                        <div class="col-md-5 position-relative">
                            <label for="originInput" class="form-label d-flex align-items-center gap-1">
                                <i class="bi bi-geo-alt-fill text-primary"></i> Departure City
                            </label>
                            <div class="input-group">
                                <input type="text" class="form-control autocomplete-city" id="originInput" name="origin" placeholder="Type city (e.g. Zamboanga City)..." value="<?= e($selOrigin) ?>" autocomplete="off" required>
                                <button class="btn btn-outline-secondary dropdown-toggle dropdown-toggle-split" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    <span class="visually-hidden">Toggle Dropdown</span>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end shadow w-100" id="originDropdown">
                                    <?php foreach ($origins as $city): ?>
                                        <li><button class="dropdown-item city-opt" type="button" data-target="originInput"><?= e($city) ?></button></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <div class="autocomplete-suggestions list-group shadow position-absolute w-100 d-none" id="originSuggestions" style="z-index: 1050; max-height: 220px; overflow-y: auto;"></div>
                        </div>

                        <div class="col-md-2 text-center py-1 py-md-0">
                            <button type="button" class="btn btn-outline-dark btn-swap rounded-circle" id="swapCities" title="Swap origin and destination" aria-label="Swap origin and destination">
                                <i class="bi bi-arrow-left-right"></i>
                            </button>
                        </div>

                        <div class="col-md-5 position-relative">
                            <label for="destinationInput" class="form-label d-flex align-items-center gap-1">
                                <i class="bi bi-geo-fill text-danger"></i> Destination City
                            </label>
                            <div class="input-group">
                                <input type="text" class="form-control autocomplete-city" id="destinationInput" name="destination" placeholder="Type destination (e.g. Pagadian City)..." value="<?= e($selDest) ?>" autocomplete="off" required>
                                <button class="btn btn-outline-secondary dropdown-toggle dropdown-toggle-split" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                    <span class="visually-hidden">Toggle Dropdown</span>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end shadow w-100" id="destinationDropdown">
                                    <?php foreach ($destinations as $city): ?>
                                        <li><button class="dropdown-item city-opt" type="button" data-target="destinationInput"><?= e($city) ?></button></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                            <div class="autocomplete-suggestions list-group shadow position-absolute w-100 d-none" id="destinationSuggestions" style="z-index: 1050; max-height: 220px; overflow-y: auto;"></div>
                        </div>

                        <div class="col-12">
                            <label for="date" class="form-label d-flex align-items-center gap-1">
                                <i class="bi bi-calendar3 text-warning"></i> Departure Date
                            </label>
                            <input type="date" class="form-control" id="date" name="date" min="<?= date('Y-m-d') ?>" value="<?= e($selDate) ?>" required>
                            <?php if (!empty($upcomingTrips)): ?>
                                <div class="form-text text-muted small mt-1">
                                    <i class="bi bi-info-circle-fill text-primary me-1"></i> Earliest available scheduled bus departs on:
                                    <strong><?= e(fmt_date($upcomingTrips[0]['departure_time'])) ?></strong> (<?= e(fmt_time($upcomingTrips[0]['departure_time'])) ?>)
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ticket-tear">
                        <span class="ticket-tear-circle left"></span>
                        <span class="ticket-tear-line"></span>
                        <span class="ticket-tear-circle right"></span>
                    </div>

                    <button type="submit" class="btn btn-primary btn-lg w-100 d-flex align-items-center justify-content-center gap-2">
                        <i class="bi bi-search"></i>
                        <span>Search Available Buses</span>
                    </button>
                </form>
            </div>
        </div>
    </div>
</section>

<!-- Scheduled Available Buses Timetable Section (for Walk-ins & Visitors) -->
<section class="container py-5" id="schedules">
    <div class="d-flex flex-wrap justify-content-between align-items-end mb-4">
        <div>
            <span class="badge bg-primary-subtle text-primary px-3 py-1 rounded-pill mb-2 fw-semibold">
                <i class="bi bi-calendar2-week-fill me-1"></i> Live Departure Timetable
            </span>
            <h2 class="h3 mb-1 fw-bold">Scheduled Available Buses</h2>
            <p class="text-muted mb-0 small">Passenger departure timetable: view departure dates, times, coach types, and live available seats on upcoming runs.</p>
        </div>
        <a href="#search" class="btn btn-outline-dark btn-sm rounded-pill px-3 d-none d-md-inline-flex align-items-center gap-1">
            <i class="bi bi-search me-1"></i> Search by Date
        </a>
    </div>

    <?php if (!$upcomingTrips): ?>
        <div class="panel p-5 bg-white rounded-4 border shadow-sm text-center">
            <i class="bi bi-calendar-x fs-1 text-muted mb-2"></i>
            <h3 class="h5 fw-bold mb-1">No Upcoming Trips Scheduled</h3>
            <p class="text-muted small mb-0">Check back later or contact terminal administration for new schedule announcements.</p>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($upcomingTrips as $t): ?>
                <?php
                    $left = max(0, (int)$t['available_seats']);
                    $depTs = strtotime($t['departure_time']);
                    $arrTs = strtotime($t['arrival_time']);
                    $duration = $arrTs > $depTs ? round(($arrTs - $depTs) / 3600, 1) : null;
                ?>
                <div class="col-lg-6">
                    <div class="schedule-card bg-white p-3 p-md-4 rounded-4 border shadow-sm h-100 d-flex flex-column justify-content-between">
                        <div>
                            <!-- Top bar: Date pill & Bus type -->
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 pb-2 border-bottom">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2 rounded-pill fw-semibold">
                                        <i class="bi bi-calendar-event me-1"></i> <?= e(date('l, F j, Y', $depTs)) ?>
                                    </span>
                                    <span class="badge bg-light text-dark border px-2 py-1 rounded-pill small">
                                        <i class="bi bi-bus-front me-1 text-primary"></i> <?= e($t['bus_type']) ?>
                                    </span>
                                </div>
                                <span class="badge bg-light text-muted border small font-monospace">Bus #<?= e($t['bus_number']) ?></span>
                            </div>

                            <!-- Route & Time visual -->
                            <div class="d-flex align-items-center justify-content-between my-3">
                                <div>
                                    <div class="h4 fw-bold text-dark mb-0"><?= e(fmt_time($t['departure_time'])) ?></div>
                                    <span class="text-muted small fw-semibold text-truncate d-block" style="max-width: 140px;"><?= e($t['origin']) ?></span>
                                    <span class="badge bg-light text-muted border small mt-1">Terminal</span>
                                </div>

                                <div class="flex-grow-1 px-3 text-center">
                                    <?php if ($duration): ?>
                                        <span class="small text-muted d-block mb-1"><?= $duration ?> hrs</span>
                                    <?php endif; ?>
                                    <div class="position-relative d-flex align-items-center justify-content-center">
                                        <div style="height: 2px; width: 100%; background: #CBD5E1;"></div>
                                        <i class="bi bi-arrow-right-circle-fill text-primary position-absolute bg-white px-1 fs-5"></i>
                                    </div>
                                    <span class="small text-muted d-block mt-1"><?= e(number_format((float)$t['distance_km'])) ?> km</span>
                                </div>

                                <div class="text-end">
                                    <div class="h4 fw-bold text-dark mb-0"><?= e(fmt_time($t['arrival_time'])) ?></div>
                                    <span class="text-muted small fw-semibold text-truncate d-block" style="max-width: 140px;"><?= e($t['destination']) ?></span>
                                    <span class="badge bg-light text-muted border small mt-1">Arrival</span>
                                </div>
                            </div>
                        </div>

                        <!-- Card Footer: Seats, Fare, Action Button -->
                        <div class="d-flex flex-wrap justify-content-between align-items-center pt-3 border-top mt-2 gap-2">
                            <div>
                                <?php if ($left <= 0): ?>
                                    <span class="badge bg-danger-subtle text-danger px-2 py-1 rounded-pill small">
                                        <i class="bi bi-x-circle me-1"></i> Sold out
                                    </span>
                                <?php elseif ($left <= 5): ?>
                                    <span class="badge bg-danger-subtle text-danger px-2 py-1 rounded-pill small">
                                        <i class="bi bi-fire me-1"></i> Only <?= $left ?> seats left
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-success-subtle text-success px-2 py-1 rounded-pill small">
                                        <i class="bi bi-check2-circle me-1"></i> <?= $left ?> seats available
                                    </span>
                                <?php endif; ?>
                                <div class="fw-bold fs-5 text-primary mt-1"><?= money($t['fare']) ?> <span class="small fw-normal text-muted">/ regular</span></div>
                                <div class="small text-success mt-1" style="font-size: 0.75rem;">
                                    <i class="bi bi-tag-fill me-1"></i><?= money($t['fare'] * 0.80) ?> (20% off for Student/Senior/PWD)
                                </div>
                            </div>
                            <div class="d-flex gap-2">
                                <a href="<?= url('index.php') ?>?origin=<?= urlencode($t['origin']) ?>&amp;destination=<?= urlencode($t['destination']) ?>&amp;date=<?= e(date('Y-m-d', $depTs)) ?>#search"
                                   class="btn btn-outline-dark btn-sm rounded-pill px-3">
                                    <i class="bi bi-calendar-check me-1"></i> View Route
                                </a>
                                <?php if ($left > 0): ?>
                                    <a href="<?= url('passenger/seat_selection.php?trip_id=' . (int)$t['trip_id']) ?>" class="btn btn-primary btn-sm rounded-pill shadow-sm px-3 d-inline-flex align-items-center gap-1">
                                        <i class="bi bi-ticket-perforated"></i> Select Seats
                                    </a>
                                <?php else: ?>
                                    <button class="btn btn-secondary btn-sm rounded-pill px-3" disabled>Sold out</button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>

<!-- Feature Badges Section -->
<section class="features-section py-5">
    <div class="container">
        <div class="row g-4">
            <div class="col-md-6 col-lg-3">
                <div class="feature-card h-100">
                    <div class="feature-icon bg-primary-subtle text-primary">
                        <i class="bi bi-grid-3x3-gap-fill"></i>
                    </div>
                    <h3 class="h6 fw-bold mb-2">Pick Your Seat</h3>
                    <p class="text-muted small mb-0">Select your preferred window, aisle, or front seat directly on our real-time interactive bus map.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="feature-card h-100">
                    <div class="feature-icon bg-warning-subtle text-warning">
                        <i class="bi bi-ticket-detailed-fill"></i>
                    </div>
                    <h3 class="h6 fw-bold mb-2">Instant e-Ticket</h3>
                    <p class="text-muted small mb-0">Get your ticket reference immediately. Show it on your phone or print a physical boarding pass.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="feature-card h-100">
                    <div class="feature-icon bg-success-subtle text-success">
                        <i class="bi bi-shield-check"></i>
                    </div>
                    <h3 class="h6 fw-bold mb-2">Verified Drivers</h3>
                    <p class="text-muted small mb-0">All trips are operated by licensed drivers and registered modern coaches with regular safety checks.</p>
                </div>
            </div>
            <div class="col-md-6 col-lg-3">
                <div class="feature-card h-100">
                    <div class="feature-icon bg-info-subtle text-info">
                        <i class="bi bi-wallet2"></i>
                    </div>
                    <h3 class="h6 fw-bold mb-2">Flexible Payment</h3>
                    <p class="text-muted small mb-0">Pay conveniently at the terminal counter with cash, or choose GCash, Maya, and card options.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- How It Works Section -->
<section class="how-it-works-section py-5 bg-white border-top border-bottom" id="how-it-works">
    <div class="container">
        <div class="text-center max-w-600 mx-auto mb-5">
            <span class="badge bg-primary-subtle text-primary px-3 py-1 rounded-pill mb-2 fw-semibold">Simple 4-Step Process</span>
            <h2 class="h3 fw-bold">How It Works</h2>
            <p class="text-muted small mb-0">From search to boarding, booking your next journey takes less than two minutes.</p>
        </div>
        <div class="row g-4 text-center">
            <div class="col-md-3">
                <div class="step-card p-3 p-md-4 rounded-4 bg-light border shadow-sm h-100">
                    <div class="step-number mb-3 mx-auto bg-primary text-white rounded-circle d-flex align-items-center justify-content-center fw-bold fs-5 shadow-sm" style="width: 48px; height: 48px;">1</div>
                    <h3 class="h6 fw-bold mb-2 text-dark">1. Search Trips</h3>
                    <p class="text-muted small mb-0">Type your departure and destination terminal to find available buses.</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="step-card p-3 p-md-4 rounded-4 bg-light border shadow-sm h-100">
                    <div class="step-number mb-3 mx-auto bg-primary text-white rounded-circle d-flex align-items-center justify-content-center fw-bold fs-5 shadow-sm" style="width: 48px; height: 48px;">2</div>
                    <h3 class="h6 fw-bold mb-2 text-dark">2. Choose Seats</h3>
                    <p class="text-muted small mb-0">Pick your favorite seat (window, aisle, front) on our real-time interactive coach layout.</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="step-card p-3 p-md-4 rounded-4 bg-light border shadow-sm h-100">
                    <div class="step-number mb-3 mx-auto bg-primary text-white rounded-circle d-flex align-items-center justify-content-center fw-bold fs-5 shadow-sm" style="width: 48px; height: 48px;">3</div>
                    <h3 class="h6 fw-bold mb-2 text-dark">3. Pay &amp; Confirm</h3>
                    <p class="text-muted small mb-0">Pay online (GCash, Maya, Card) or choose terminal walk-in.</p>
                </div>
            </div>
            <div class="col-md-3">
                <div class="step-card p-3 p-md-4 rounded-4 bg-light border shadow-sm h-100">
                    <div class="step-number mb-3 mx-auto bg-primary text-white rounded-circle d-flex align-items-center justify-content-center fw-bold fs-5 shadow-sm" style="width: 48px; height: 48px;">4</div>
                    <h3 class="h6 fw-bold mb-2 text-dark">4. Board &amp; Travel</h3>
                    <p class="text-muted small mb-0">Show your digital e-ticket or printable QR boarding pass upon boarding at the terminal.</p>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- Routes We Serve Section -->
<section class="container py-5" id="routes">
    <div class="d-flex flex-wrap justify-content-between align-items-end mb-4">
        <div>
            <span class="badge bg-primary-subtle text-primary px-3 py-1 rounded-pill mb-2 fw-semibold">Available Network</span>
            <h2 class="h3 mb-1 fw-bold">Routes We Serve</h2>
            <p class="text-muted mb-0 small">Explore our regional intercity routes connecting Zamboanga City, Zamboanga Peninsula, and Mindanao hubs with real-time seat availability.</p>
        </div>
        <a href="#search" class="btn btn-outline-dark btn-sm d-none d-md-inline-flex align-items-center gap-1">
            <i class="bi bi-arrow-up-circle"></i> Back to Search
        </a>
    </div>

    <div class="row g-3">
        <?php foreach ($routes as $r): ?>
            <?php
                $nextDateParam = !empty($r['next_departure']) ? date('Y-m-d', strtotime($r['next_departure'])) : '';
                $routeBusImg = url(bus_type_image($r['next_bus_type'] ?? 'Standard Regular Coach'));
            ?>
            <div class="col-md-6 col-xl-4">
                <a class="route-card-modern position-relative overflow-hidden"
                   style="background-image: linear-gradient(135deg, rgba(255,255,255,0.96) 0%, rgba(255,255,255,0.92) 55%, rgba(248,250,252,0.85) 100%), url('<?= e($routeBusImg) ?>');"
                   href="<?= is_logged_in() ? url('passenger/trip_results.php?origin=' . urlencode($r['origin']) . '&destination=' . urlencode($r['destination']) . '&date=' . e($nextDateParam)) : url('index.php?origin=' . urlencode($r['origin']) . '&destination=' . urlencode($r['destination']) . '&date=' . e($nextDateParam) . '#search') ?>">
                    <div class="route-card-bus-watermark" style="background-image: url('<?= e($routeBusImg) ?>');"></div>
                    <div class="position-relative z-1">
                        <div class="route-card-header d-flex justify-content-between align-items-center mb-2">
                            <span class="route-badge-km badge bg-white border text-muted shadow-xs"><i class="bi bi-signpost-split me-1"></i><?= e(number_format((float)$r['distance_km'])) ?> km</span>
                            <?php if ($r['upcoming_trips'] > 0): ?>
                                <span class="badge bg-success text-white shadow-xs">
                                    <i class="bi bi-clock me-1"></i><?= (int)$r['upcoming_trips'] ?> upcoming trip<?= $r['upcoming_trips'] > 1 ? 's' : '' ?>
                                </span>
                            <?php else: ?>
                                <span class="badge bg-secondary-subtle text-muted">No upcoming trips</span>
                            <?php endif; ?>
                        </div>
                        <div class="route-line-modern">
                            <span class="origin-city"><?= e($r['origin']) ?></span>
                            <div class="route-arrow-icon"><i class="bi bi-arrow-right"></i></div>
                            <span class="destination-city"><?= e($r['destination']) ?></span>
                        </div>

                        <?php if (!empty($r['next_departure'])): ?>
                            <div class="next-departure-tag small mt-2 p-2 bg-white bg-opacity-75 rounded-2 border d-flex align-items-center justify-content-between shadow-xs">
                                <span class="text-muted"><i class="bi bi-calendar2-check text-primary me-1"></i> Next departure:</span>
                                <strong class="text-dark"><?= e(fmt_date($r['next_departure'])) ?> &bull; <?= e(fmt_time($r['next_departure'])) ?></strong>
                            </div>
                        <?php endif; ?>

                        <div class="route-card-footer d-flex justify-content-between align-items-center mt-3 pt-2 border-top">
                            <span class="text-muted small">Starting from</span>
                            <?php if ($r['upcoming_trips'] > 0): ?>
                                <span class="route-price"><?= money($r['from_fare']) ?></span>
                            <?php else: ?>
                                <span class="text-muted small">—</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </a>
            </div>
        <?php endforeach; ?>
        <?php if (!$routes): ?>
            <div class="col-12"><div class="alert alert-info mb-0">No active routes are available yet.</div></div>
        <?php endif; ?>
    </div>
</section>

<script>
const availableOrigins = <?= json_encode(array_values($origins)) ?>;
const availableDestinations = <?= json_encode(array_values($destinations)) ?>;

function setupAutocomplete(inputId, suggestionsId, itemsList) {
    const input = document.getElementById(inputId);
    const box = document.getElementById(suggestionsId);
    if (!input || !box) return;

    input.addEventListener('input', function() {
        const val = this.value.trim().toLowerCase();
        box.innerHTML = '';
        if (!val) {
            box.classList.add('d-none');
            return;
        }

        const matches = itemsList.filter(item => item.toLowerCase().includes(val));
        if (matches.length === 0) {
            box.innerHTML = '<div class="list-group-item text-muted small py-2"><i class="bi bi-info-circle me-1"></i>No matching terminal found</div>';
            box.classList.remove('d-none');
            return;
        }

        matches.forEach(item => {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'list-group-item list-group-item-action py-2 px-3 d-flex align-items-center justify-content-between';
            
            // Highlight matching letters
            const regex = new RegExp(`(${val.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')})`, 'gi');
            const highlighted = item.replace(regex, '<strong class="text-primary">$1</strong>');
            
            btn.innerHTML = `<span><i class="bi bi-geo-alt me-2 text-muted"></i>${highlighted}</span><i class="bi bi-arrow-up-left small text-muted"></i>`;
            btn.addEventListener('click', function() {
                input.value = item;
                box.classList.add('d-none');
            });
            box.appendChild(btn);
        });

        box.classList.remove('d-none');
    });

    // Close when clicking outside
    document.addEventListener('click', function(e) {
        if (!input.contains(e.target) && !box.contains(e.target)) {
            box.classList.add('d-none');
        }
    });

    input.addEventListener('focus', function() {
        if (this.value.trim() !== '') {
            input.dispatchEvent(new Event('input'));
        }
    });
}

setupAutocomplete('originInput', 'originSuggestions', availableOrigins);
setupAutocomplete('destinationInput', 'destinationSuggestions', availableDestinations);

// Dropdown item selection
document.querySelectorAll('.city-opt').forEach(btn => {
    btn.addEventListener('click', function() {
        const targetId = this.dataset.target;
        const targetInput = document.getElementById(targetId);
        if (targetInput) {
            targetInput.value = this.textContent.trim();
        }
    });
});

// Swap cities
const swapBtn = document.getElementById('swapCities');
if (swapBtn) {
    swapBtn.addEventListener('click', function() {
        const orig = document.getElementById('originInput');
        const dest = document.getElementById('destinationInput');
        if (orig && dest) {
            const tmp = orig.value;
            orig.value = dest.value;
            dest.value = tmp;
        }
    });
}

function fillSearch(from, to, date) {
    var originInput = document.getElementById('originInput');
    var destInput = document.getElementById('destinationInput');
    var dateInput = document.getElementById('date');
    if (originInput && destInput) {
        originInput.value = from;
        destInput.value = to;
        if (dateInput && date) {
            dateInput.value = date;
        }
        var searchEl = document.getElementById('search');
        if (searchEl) {
            searchEl.scrollIntoView({ behavior: 'smooth' });
        }
    }
}
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
