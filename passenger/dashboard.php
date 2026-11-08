<?php
/**
 * passenger/dashboard.php
 * Passenger home: booking counts and the latest bookings. Only the logged-in passenger's data is read.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('PASSENGER');

$uid = (int)$_SESSION['user_id'];

$tripStmt = $pdo->query(
    "SELECT t.trip_id, b.bus_number, b.bus_type, b.total_seats,
        r.origin, r.destination, t.departure_time, t.arrival_time, t.fare,
        b.total_seats - (SELECT COUNT(*) FROM booking_seats bs
                 WHERE bs.trip_id = t.trip_id AND bs.seat_status = 'BOOKED') AS available_seats
         FROM trips t JOIN buses b ON b.bus_id = t.bus_id
         JOIN routes r ON r.route_id = t.route_id
         WHERE t.status = 'SCHEDULED' AND b.status = 'ACTIVE' AND r.status = 'ACTIVE'
       AND t.departure_time > NOW()
       AND (SELECT COUNT(*) FROM booking_seats bs WHERE bs.trip_id = t.trip_id AND bs.seat_status = 'BOOKED') < b.total_seats
     ORDER BY t.departure_time LIMIT 30"
);
$trips = $tripStmt->fetchAll();

$pageTitle = 'My dashboard';
$layout    = 'public';
require __DIR__ . '/../includes/header.php';
?>
<div class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h1 class="h3 mb-0">Hello, <?= e(explode(' ', $_SESSION['user_name'])[0]) ?></h1>
            <p class="text-muted mb-0">Choose from upcoming trips with available seats.</p>
        </div>
        <a href="<?= url('passenger/my_bookings.php') ?>" class="btn btn-outline-dark"><i class="bi bi-ticket-perforated"></i> My bookings</a>
    </div>

    <div class="panel">
        <div class="panel-head">
            <h2 class="h5 mb-0">Available trips</h2>
        </div>
        <?php if (!$trips): ?>
            <div class="empty-state">
                <i class="bi bi-ticket-perforated"></i>
                <p class="mb-0">There are no upcoming trips with seats available right now.</p>
            </div>
        <?php else: ?>
            <div class="p-3">
                <?php foreach ($trips as $trip): ?>
                    <article class="trip-row">
                        <div class="trip-bus"><strong><?= e($trip['bus_number']) ?></strong><span class="text-muted small"><?= e($trip['bus_type']) ?></span></div>
                        <div class="trip-times">
                            <div><span class="time"><?= e(fmt_time($trip['departure_time'])) ?></span><span class="small text-muted"><?= e($trip['origin']) ?></span></div>
                            <i class="bi bi-arrow-right text-muted"></i>
                            <div><span class="time"><?= e(fmt_time($trip['arrival_time'])) ?></span><span class="small text-muted"><?= e($trip['destination']) ?></span></div>
                        </div>
                        <div class="trip-seats"><strong><?= (int)$trip['available_seats'] ?></strong><span class="small text-muted">of <?= (int)$trip['total_seats'] ?> seats left</span></div>
                        <div class="trip-fare"><strong><?= money($trip['fare']) ?></strong><span class="small text-muted">per seat</span></div>
                        <div class="trip-action"><a class="btn btn-primary btn-sm" href="<?= url('passenger/seat_selection.php?trip_id=' . (int)$trip['trip_id']) ?>">Select trip</a></div>
                    </article>
                    <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
