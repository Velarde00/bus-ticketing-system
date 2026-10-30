<?php
/**
 * passenger/booking_history.php
 * ALL of the passenger's bookings, newest first. Optional status filter.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('PASSENGER');

$filter  = $_GET['status'] ?? '';
$allowed = ['PENDING', 'CONFIRMED', 'CANCELLED'];
if (!in_array($filter, $allowed, true)) {
    $filter = '';
}

$sql = "SELECT bk.booking_id, bk.booking_reference, bk.booking_date, bk.status,
               r.origin, r.destination, t.departure_time,
               (SELECT COALESCE(SUM(bs.price), 0) FROM booking_seats bs WHERE bs.booking_id = bk.booking_id) AS total_fare,
               (SELECT p.payment_status FROM payments p WHERE p.booking_id = bk.booking_id
                ORDER BY p.payment_date DESC, p.payment_id DESC LIMIT 1) AS payment_status
        FROM bookings bk
        JOIN trips  t ON t.trip_id  = bk.trip_id
        JOIN routes r ON r.route_id = t.route_id
        WHERE bk.user_id = :uid";
$params = [':uid' => $_SESSION['user_id']];
if ($filter !== '') {
    $sql .= ' AND bk.status = :status';
    $params[':status'] = $filter;
}
$sql .= ' ORDER BY bk.booking_date DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$bookings = $stmt->fetchAll();

$pageTitle = 'Booking history';
$layout    = 'public';
require __DIR__ . '/../includes/header.php';
?>
<div class="container py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <h1 class="h3 mb-0">Booking history</h1>
        <form method="get" class="d-flex gap-2">
            <label for="status" class="visually-hidden">Filter by status</label>
            <select class="form-select form-select-sm" id="status" name="status" onchange="this.form.submit()">
                <option value="">All statuses</option>
                <?php foreach ($allowed as $s): ?>
                    <option value="<?= e($s) ?>" <?= $filter === $s ? 'selected' : '' ?>><?= e(ucfirst(strtolower($s))) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <?php if (!$bookings): ?>
        <div class="panel"><div class="empty-state"><i class="bi bi-clock-history"></i><p class="mb-0">No bookings found.</p></div></div>
    <?php else: ?>
        <div class="panel"><div class="table-responsive">
            <table class="table align-middle mb-0">
                <thead><tr><th>Reference</th><th>Booked on</th><th>Route</th><th>Departure</th><th>Total</th><th>Status</th><th>Payment</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($bookings as $b): ?>
                    <tr>
                        <td class="fw-semibold"><?= e($b['booking_reference']) ?></td>
                        <td><?= e(fmt_date($b['booking_date'])) ?></td>
                        <td><?= e($b['origin']) ?> &rarr; <?= e($b['destination']) ?></td>
                        <td><?= e(fmt_datetime($b['departure_time'])) ?></td>
                        <td><?= money($b['total_fare']) ?></td>
                        <td><?= status_badge($b['status']) ?></td>
                        <td><?= $b['payment_status'] ? status_badge($b['payment_status']) : '<span class="text-muted">None</span>' ?></td>
                        <td class="text-end"><a class="btn btn-outline-secondary btn-sm" href="<?= url('passenger/booking_confirmation.php?booking_id=' . (int)$b['booking_id']) ?>">Details</a></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div></div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
