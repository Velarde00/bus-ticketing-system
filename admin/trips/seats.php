<?php
require_once __DIR__ . '/../../includes/auth.php';
require_role('ADMIN');

$tripId = filter_var($_GET['trip_id'] ?? null, FILTER_VALIDATE_INT);
$stmt = $pdo->prepare(
    "SELECT t.trip_id, t.departure_time, t.status, b.bus_number, b.bus_type, b.total_seats,
            r.origin, r.destination
     FROM trips t JOIN buses b ON b.bus_id = t.bus_id
     JOIN routes r ON r.route_id = t.route_id
     WHERE t.trip_id = :id"
);
$stmt->execute([':id' => $tripId ?: 0]);
$trip = $stmt->fetch();
if (!$trip) {
    set_flash('warning', 'Trip not found.');
    redirect('admin/trips/index.php');
}

$stmt = $pdo->prepare(
    "SELECT bs.seat_number, bk.booking_reference, bk.status AS booking_status,
            CONCAT(u.first_name, ' ', u.last_name) AS passenger
     FROM booking_seats bs JOIN bookings bk ON bk.booking_id = bs.booking_id
     JOIN users u ON u.user_id = bk.user_id
     WHERE bs.trip_id = :id AND bs.seat_status = 'BOOKED'
     ORDER BY bs.seat_number"
);
$stmt->execute([':id' => $tripId]);
$reserved = [];
foreach ($stmt->fetchAll() as $seat) {
    $reserved[(int)$seat['seat_number']] = $seat;
}
$total = (int)$trip['total_seats'];
$available = $total - count($reserved);
$rows = (int)ceil($total / 4);

$pageTitle = 'Trip seat map';
$layout = 'admin';
$activePage = 'trips';
require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <div>
        <h1 class="h3 mb-0">Seat map · <?= e($trip['bus_number']) ?></h1>
        <p class="text-muted mb-0"><?= e($trip['origin']) ?> to <?= e($trip['destination']) ?> · <?= e(fmt_datetime($trip['departure_time'])) ?> · <?= e($trip['bus_type']) ?></p>
    </div>
    <?= status_badge($trip['status']) ?>
</div>
<a href="<?= url('admin/trips/index.php') ?>" class="btn btn-booking-action btn-sm mb-3"><i class="bi bi-arrow-left"></i> All trips</a>
<div class="panel p-4">
    <div class="seat-legend">
        <span><i class="legend-box legend-free"></i> Available (<?= $available ?>)</span>
        <span><i class="legend-box legend-taken"></i> Reserved (<?= count($reserved) ?>)</span>
    </div>
    <div class="bus-shell">
        <div class="bus-front"><i class="bi bi-person-badge"></i> Driver</div>
        <div class="seat-grid">
            <?php for ($row = 0; $row < $rows; $row++): ?>
                <?php for ($column = 0; $column < 4; $column++): ?>
                    <?php
                    if ($column === 2) {
                        echo '<div class="seat-aisle"></div>';
                    }
                    $number = $row * 4 + $column + 1;
                    if ($number > $total) {
                        echo '<div></div>';
                        continue;
                    }
                    $seat = $reserved[$number] ?? null;
                    $details = $seat
                        ? 'Reserved by ' . $seat['passenger'] . ' · ' . $seat['booking_reference']
                        : 'Seat ' . $number . ' available';
                    ?>
                    <div class="seat-cell">
                        <span class="seat <?= $seat ? 'seat-admin-taken' : 'seat-admin-free' ?>" title="<?= e($details) ?>" aria-label="<?= e($details) ?>">
                            <?= $number ?>
                        </span>
                    </div>
                <?php endfor; ?>
            <?php endfor; ?>
        </div>
    </div>
    <?php if ($reserved): ?>
        <div class="table-responsive mt-4">
            <table class="table align-middle mb-0">
                <thead><tr><th>Seat</th><th>Passenger</th><th>Booking reference</th><th>Booking status</th></tr></thead>
                <tbody>
                <?php foreach ($reserved as $number => $seat): ?>
                    <tr><td class="fw-semibold"><?= $number ?></td><td><?= e($seat['passenger']) ?></td><td><?= e($seat['booking_reference']) ?></td><td><?= status_badge($seat['booking_status']) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>