<?php
/**
 * passenger/trip_results.php
 * Shows trips that match the origin, destination, and date from the search form.
 */
require_once __DIR__ . '/../includes/auth.php';

$origin = trim($_GET['origin'] ?? '');
$dest   = trim($_GET['destination'] ?? '');
$date   = trim($_GET['date'] ?? '');

$errors = [];
$trips  = [];

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
                r.origin, r.destination, t.departure_time, t.arrival_time, t.fare,
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
}

$pageTitle = 'Available trips';
$layout    = 'public';
require __DIR__ . '/../includes/header.php';
?>
<div class="container py-4">
    <a href="<?= url('index.php') ?>" class="small"><i class="bi bi-arrow-left"></i> Browse routes</a>

    <?php if ($errors): ?>
        <div class="alert alert-warning mt-3"><?= e($errors[0]) ?></div>
    <?php else: ?>
        <h1 class="h3 mt-2"><?= e($origin) ?> <i class="bi bi-arrow-right"></i> <?= e($dest) ?></h1>
        <p class="text-muted"><?= e(fmt_date($date)) ?> &middot; <?= count($trips) ?> trip<?= count($trips) === 1 ? '' : 's' ?> found</p>

        <?php if (!$trips): ?>
            <div class="empty-state">
                <i class="bi bi-bus-front"></i>
                <p class="mb-2">No buses leave on this date for this route.</p>
                <a href="<?= url('index.php') ?>" class="btn btn-primary btn-sm">Browse upcoming trips</a>
            </div>
        <?php endif; ?>

        <?php foreach ($trips as $t): ?>
            <article class="trip-row">
                <div class="trip-bus">
                    <strong><?= e($t['bus_number']) ?></strong>
                    <span class="text-muted small"><?= e($t['bus_type']) ?> · <?= (int)$t['total_seats'] ?> seats</span>
                </div>
                <div class="trip-times">
                    <div><span class="time"><?= e(fmt_time($t['departure_time'])) ?></span><span class="small text-muted"><?= e($t['origin']) ?></span></div>
                    <i class="bi bi-arrow-right text-muted"></i>
                    <div><span class="time"><?= e(fmt_time($t['arrival_time'])) ?></span><span class="small text-muted"><?= e($t['destination']) ?></span></div>
                </div>
                <div class="trip-seats">
                    <?php $left = max(0, (int)$t['available_seats']); ?>
                    <strong class="<?= $left <= 5 ? 'text-danger' : '' ?>"><?= $left ?></strong>
                    <span class="small text-muted">of <?= (int)$t['total_seats'] ?> seats left</span>
                </div>
                <div class="trip-fare">
                    <strong><?= money($t['fare']) ?></strong>
                    <span class="small text-muted">per seat</span>
                </div>
                <div class="trip-action">
                    <?php if ($left > 0): ?>
                        <a class="btn btn-primary btn-sm" href="<?= url('passenger/seat_selection.php?trip_id=' . (int)$t['trip_id']) ?>">Select trip</a>
                    <?php else: ?>
                        <button class="btn btn-secondary btn-sm" disabled>Sold out</button>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
