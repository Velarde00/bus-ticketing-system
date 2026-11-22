<?php
/**
 * passenger/dashboard.php
 * Passenger home: Quick trip search, available trips timetable, booking counts and recent reservations.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('PASSENGER');

$uid = (int)$_SESSION['user_id'];

// 1. User Booking Statistics
$counts = $pdo->prepare(
    "SELECT COUNT(*) AS total,
            COALESCE(SUM(status = 'PENDING'), 0)   AS pending,
            COALESCE(SUM(status = 'CONFIRMED'), 0) AS confirmed,
            COALESCE(SUM(status = 'CANCELLED'), 0) AS cancelled
     FROM bookings WHERE user_id = :uid"
);
$counts->execute([':uid' => $uid]);
$stats = $counts->fetch();

$upcomingStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM bookings bk JOIN trips t ON t.trip_id = bk.trip_id
     WHERE bk.user_id = :uid AND bk.status <> 'CANCELLED' AND t.departure_time > NOW()"
);
$upcomingStmt->execute([':uid' => $uid]);
$upcoming = (int)$upcomingStmt->fetchColumn();

// 2. Cities for Search Dropdowns
$origins = $pdo->query("SELECT DISTINCT origin FROM routes WHERE status = 'ACTIVE' ORDER BY origin")->fetchAll(PDO::FETCH_COLUMN);
$destinations = $pdo->query("SELECT DISTINCT destination FROM routes WHERE status = 'ACTIVE' ORDER BY destination")->fetchAll(PDO::FETCH_COLUMN);

// 3. Upcoming Available Scheduled Trips (with live available seat counts)
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

// 4. Passenger's Recent Bookings
$recentStmt = $pdo->prepare(
    "SELECT bk.booking_id, bk.booking_reference, bk.status, r.origin, r.destination, t.departure_time,
            (SELECT COUNT(*) FROM booking_seats bs WHERE bs.booking_id = bk.booking_id AND bs.seat_status = 'BOOKED') AS seats,
            (SELECT p.payment_status FROM payments p WHERE p.booking_id = bk.booking_id
             ORDER BY p.payment_date DESC, p.payment_id DESC LIMIT 1) AS payment_status
     FROM bookings bk
     JOIN trips  t ON t.trip_id  = bk.trip_id
     JOIN routes r ON r.route_id = t.route_id
     WHERE bk.user_id = :uid
     ORDER BY bk.booking_date DESC LIMIT 5"
);
$recentStmt->execute([':uid' => $uid]);
$recent = $recentStmt->fetchAll();

$pageTitle = 'Passenger Dashboard';
$layout    = 'public';
require __DIR__ . '/../includes/header.php';
?>
<div class="container py-4">
    <!-- Welcome Header Banner -->
    <div class="dashboard-welcome-banner p-4 rounded-4 mb-4 shadow-sm bg-white border d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <div class="d-flex align-items-center gap-2 mb-1">
                <span class="badge bg-primary-subtle text-primary rounded-pill px-3 py-1 fw-semibold small">Passenger Portal</span>
                <span class="text-muted small">&bull;</span>
                <span class="text-muted small"><?= e(date('F j, Y')) ?></span>
            </div>
            <h1 class="h3 fw-bold mb-1">Welcome back, <?= e(explode(' ', $_SESSION['user_name'])[0]) ?>!</h1>
            <p class="text-muted mb-0 small">
                You have <strong class="text-dark"><?= $upcoming ?></strong> upcoming booking<?= $upcoming === 1 ? '' : 's' ?> scheduled. Search trips below or reserve your seats instantly.
            </p>
        </div>
        <div class="d-flex gap-2">
            <a href="#searchSection" class="btn btn-primary rounded-pill shadow-sm px-4">
                <i class="bi bi-search me-1"></i> Search Trips
            </a>
            <a href="<?= url('passenger/my_bookings.php') ?>" class="btn btn-outline-dark rounded-pill px-3">
                <i class="bi bi-ticket-perforated me-1"></i> My Bookings
            </a>
        </div>
    </div>

    <!-- Quick Stats KPI Row -->
    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-3">
            <div class="stat-card-modern p-3 rounded-4 bg-white border shadow-sm h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="stat-card-label text-muted small fw-semibold">All Bookings</span>
                    <span class="stat-icon-wrapper bg-primary-subtle text-primary"><i class="bi bi-collection"></i></span>
                </div>
                <div class="stat-card-value h3 fw-bold text-dark mb-0"><?= (int)$stats['total'] ?></div>
                <div class="stat-card-sub text-muted mt-1 small">Total reservations</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card-modern p-3 rounded-4 bg-white border shadow-sm h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="stat-card-label text-muted small fw-semibold">Upcoming</span>
                    <span class="stat-icon-wrapper bg-info-subtle text-info"><i class="bi bi-calendar-check"></i></span>
                </div>
                <div class="stat-card-value h3 fw-bold text-dark mb-0"><?= $upcoming ?></div>
                <div class="stat-card-sub text-muted mt-1 small">Upcoming journeys</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card-modern p-3 rounded-4 bg-white border shadow-sm h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="stat-card-label text-muted small fw-semibold">Confirmed</span>
                    <span class="stat-icon-wrapper bg-success-subtle text-success"><i class="bi bi-check-circle"></i></span>
                </div>
                <div class="stat-card-value h3 fw-bold text-dark mb-0"><?= (int)$stats['confirmed'] ?></div>
                <div class="stat-card-sub text-muted mt-1 small">Ready to board</div>
            </div>
        </div>
        <div class="col-6 col-lg-3">
            <div class="stat-card-modern p-3 rounded-4 bg-white border shadow-sm h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="stat-card-label text-muted small fw-semibold">Discounts</span>
                    <span class="stat-icon-wrapper bg-warning-subtle text-warning"><i class="bi bi-tag-fill"></i></span>
                </div>
                <div class="stat-card-value h3 fw-bold text-success mb-0">20% Off</div>
                <div class="stat-card-sub text-muted mt-1 small">Students, Seniors, PWD</div>
            </div>
        </div>
    </div>

    <!-- Search Trips Panel Section -->
    <div class="panel bg-white rounded-4 border shadow-sm p-4 p-md-5 mb-5" id="searchSection">
        <div class="d-flex flex-wrap justify-content-between align-items-center mb-4 pb-2 border-bottom">
            <div>
                <h2 class="h5 fw-bold mb-1 text-dark d-flex align-items-center gap-2">
                    <i class="bi bi-search text-primary"></i> Search Trips
                </h2>
                <p class="text-muted small mb-0">Find and book scheduled bus departures across our destination routes.</p>
            </div>
            <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2 rounded-pill small">
                <i class="bi bi-lightning-charge-fill me-1"></i> Instant Seat Confirmation
            </span>
        </div>

        <form method="get" action="<?= url('passenger/trip_results.php') ?>">
            <div class="row g-3 align-items-end">
                <div class="col-md-4 position-relative">
                    <label for="origInput" class="form-label small fw-semibold text-dark">
                        <i class="bi bi-geo-alt-fill text-primary me-1"></i> Departure City
                    </label>
                    <div class="input-group">
                        <input type="text" class="form-control autocomplete-city" id="origInput" name="origin" placeholder="Type departure city (e.g. Manila)..." autocomplete="off" required>
                        <button class="btn btn-outline-secondary dropdown-toggle dropdown-toggle-split" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="visually-hidden">Toggle Dropdown</span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow w-100" id="origDropdown">
                            <?php foreach ($origins as $city): ?>
                                <li><button class="dropdown-item city-opt" type="button" data-target="origInput"><?= e($city) ?></button></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <div class="autocomplete-suggestions list-group shadow position-absolute w-100 d-none" id="origSuggestions" style="z-index: 1050; max-height: 220px; overflow-y: auto;"></div>
                </div>

                <div class="col-md-4 position-relative">
                    <label for="destInput" class="form-label small fw-semibold text-dark">
                        <i class="bi bi-geo-fill text-danger me-1"></i> Destination City
                    </label>
                    <div class="input-group">
                        <input type="text" class="form-control autocomplete-city" id="destInput" name="destination" placeholder="Type destination (e.g. Baguio)..." autocomplete="off" required>
                        <button class="btn btn-outline-secondary dropdown-toggle dropdown-toggle-split" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <span class="visually-hidden">Toggle Dropdown</span>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow w-100" id="destDropdown">
                            <?php foreach ($destinations as $city): ?>
                                <li><button class="dropdown-item city-opt" type="button" data-target="destInput"><?= e($city) ?></button></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                    <div class="autocomplete-suggestions list-group shadow position-absolute w-100 d-none" id="destSuggestions" style="z-index: 1050; max-height: 220px; overflow-y: auto;"></div>
                </div>

                <div class="col-md-2">
                    <label for="tripDate" class="form-label small fw-semibold text-dark">
                        <i class="bi bi-calendar3 text-warning me-1"></i> Departure Date
                    </label>
                    <input type="date" class="form-control" id="tripDate" name="date" min="<?= date('Y-m-d') ?>" value="<?= date('Y-m-d') ?>" required>
                </div>

                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary w-100 rounded-pill shadow-sm d-flex align-items-center justify-content-center gap-1" style="height: 42px;">
                        <i class="bi bi-search"></i>
                        <span>Find Buses</span>
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Available Trips Timetable Section -->
    <div class="d-flex flex-wrap justify-content-between align-items-end mb-4">
        <div>
            <span class="badge bg-primary-subtle text-primary px-3 py-1 rounded-pill mb-2 fw-semibold">
                <i class="bi bi-calendar2-week-fill me-1"></i> Live Schedule Timetable
            </span>
            <h2 class="h4 mb-1 fw-bold">Available Scheduled Trips</h2>
            <p class="text-muted mb-0 small">Select any scheduled trip below to view real-time seats and reserve your tickets.</p>
        </div>
        <a href="#searchSection" class="btn btn-outline-dark btn-sm rounded-pill px-3">
            <i class="bi bi-search me-1"></i> Search by Date
        </a>
    </div>

    <?php if (!$upcomingTrips): ?>
        <div class="panel p-5 bg-white rounded-4 border shadow-sm text-center mb-5">
            <i class="bi bi-calendar-x fs-1 text-muted mb-2 d-block"></i>
            <h3 class="h5 fw-bold mb-1">No Upcoming Trips Scheduled</h3>
            <p class="text-muted small mb-0">Check back later or contact administration for newly published departure schedules.</p>
        </div>
    <?php else: ?>
        <div class="row g-3 mb-5">
            <?php foreach ($upcomingTrips as $t): ?>
                <?php
                    $left = max(0, (int)$t['available_seats']);
                    $depTs = strtotime($t['departure_time']);
                    $arrTs = strtotime($t['arrival_time']);
                    $duration = $arrTs > $depTs ? round(($arrTs - $depTs) / 3600, 1) : null;
                    $busImg = url(bus_type_image($t['bus_type'] ?? ''));
                ?>
                <div class="col-lg-6">
                    <div class="passenger-trip-card rounded-4 border shadow-sm h-100 d-flex flex-column justify-content-between p-3 p-md-4 position-relative overflow-hidden"
                         style="background-image: linear-gradient(135deg, rgba(255,255,255,0.96) 0%, rgba(255,255,255,0.91) 60%, rgba(248,250,252,0.85) 100%), url('<?= e($busImg) ?>');">
                        <div class="trip-card-bus-watermark" style="background-image: url('<?= e($busImg) ?>');"></div>
                        <div class="position-relative z-1">
                            <!-- Header pill: Date & Bus -->
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 pb-2 border-bottom">
                                <div class="d-flex align-items-center gap-2">
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle px-3 py-2 rounded-pill fw-semibold">
                                        <i class="bi bi-calendar-event me-1"></i> <?= e(date('l, F j, Y', $depTs)) ?>
                                    </span>
                                    <span class="badge bg-white text-dark border shadow-xs px-2 py-1 rounded-pill small">
                                        <i class="bi bi-bus-front me-1 text-primary"></i> <?= e($t['bus_type']) ?>
                                    </span>
                                </div>
                                <span class="badge bg-white text-muted border small font-monospace shadow-xs">Bus #<?= e($t['bus_number']) ?></span>
                            </div>

                            <!-- Route Visual -->
                            <div class="d-flex align-items-center justify-content-between my-3">
                                <div>
                                    <div class="h4 fw-bold text-dark mb-0"><?= e(fmt_time($t['departure_time'])) ?></div>
                                    <span class="text-muted small fw-semibold text-truncate d-block" style="max-width: 140px;"><?= e($t['origin']) ?></span>
                                    <span class="badge bg-white text-muted border small mt-1 shadow-xs">Terminal</span>
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
                                    <span class="badge bg-white text-muted border small mt-1 shadow-xs">Arrival</span>
                                </div>
                            </div>
                        </div>

                        <!-- Card Footer: Seats, Fare, Action Button -->
                        <div class="d-flex flex-wrap justify-content-between align-items-center pt-3 border-top mt-2 gap-2 position-relative z-1">
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
                                <div class="fw-bold fs-5 text-primary mt-1"><?= money($t['fare']) ?> <span class="small fw-normal text-muted">/ seat</span></div>
                                <div class="small text-success" style="font-size: 0.72rem;">
                                    <i class="bi bi-tag-fill me-1"></i><?= money($t['fare'] * 0.80) ?> (20% off for Student/Senior/PWD)
                                </div>
                            </div>
                            <div>
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

    <!-- Recent Bookings Table Panel -->
    <div class="panel bg-white rounded-4 border shadow-sm overflow-hidden mb-4">
        <div class="panel-head p-3 px-4 border-bottom d-flex justify-content-between align-items-center bg-light bg-opacity-50">
            <div>
                <h2 class="h5 fw-bold mb-0">My Recent Reservations</h2>
                <small class="text-muted">Your latest trip bookings</small>
            </div>
            <a href="<?= url('passenger/my_bookings.php') ?>" class="btn btn-outline-secondary btn-sm rounded-pill">
                View All <i class="bi bi-arrow-right ms-1"></i>
            </a>
        </div>

        <?php if (!$recent): ?>
            <div class="empty-state p-5 text-center">
                <div class="empty-state-icon mb-3">
                    <i class="bi bi-ticket-perforated fs-1 text-primary"></i>
                </div>
                <h3 class="h5 fw-bold mb-2">No bookings placed yet</h3>
                <p class="text-muted small max-w-500 mx-auto mb-3">
                    You haven't reserved any bus seats yet. Select any of the available trips above to book your seats.
                </p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 custom-modern-table">
                    <thead>
                        <tr>
                            <th class="ps-4">Reference</th>
                            <th>Route</th>
                            <th>Departure Time</th>
                            <th>Seats</th>
                            <th>Status</th>
                            <th>Payment</th>
                            <th class="text-end pe-4">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($recent as $b): ?>
                        <tr>
                            <td class="ps-4">
                                <span class="font-monospace fw-bold text-dark"><?= e($b['booking_reference']) ?></span>
                            </td>
                            <td>
                                <div class="fw-semibold text-dark"><?= e($b['origin']) ?> &rarr; <?= e($b['destination']) ?></div>
                            </td>
                            <td>
                                <div class="small fw-medium text-dark"><?= e(fmt_datetime($b['departure_time'])) ?></div>
                            </td>
                            <td>
                                <span class="badge bg-light text-dark border px-2 py-1 rounded-pill">
                                    <i class="bi bi-person me-1"></i><?= (int)$b['seats'] ?> seat<?= (int)$b['seats'] === 1 ? '' : 's' ?>
                                </span>
                            </td>
                            <td><?= status_badge($b['status']) ?></td>
                            <td><?= $b['payment_status'] ? status_badge($b['payment_status']) : '<span class="text-muted small">Pending</span>' ?></td>
                            <td class="text-end pe-4 text-nowrap">
                                <?php if ($b['status'] === 'CONFIRMED'): ?>
                                    <a class="btn btn-primary btn-sm rounded-pill px-3 shadow-sm" href="<?= url('passenger/ticket.php?booking_id=' . (int)$b['booking_id']) ?>">
                                        <i class="bi bi-printer me-1"></i> Tickets
                                    </a>
                                <?php else: ?>
                                    <a class="btn btn-outline-secondary btn-sm rounded-pill px-3" href="<?= url('passenger/booking_confirmation.php?booking_id=' . (int)$b['booking_id']) ?>">
                                        Details
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
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

setupAutocomplete('origInput', 'origSuggestions', availableOrigins);
setupAutocomplete('destInput', 'destSuggestions', availableDestinations);

document.querySelectorAll('.city-opt').forEach(btn => {
    btn.addEventListener('click', function() {
        const targetId = this.dataset.target;
        const targetInput = document.getElementById(targetId);
        if (targetInput) {
            targetInput.value = this.textContent.trim();
        }
    });
});
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
