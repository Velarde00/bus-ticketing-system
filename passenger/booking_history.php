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
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h1 class="h3 fw-bold mb-1">Booking History</h1>
            <p class="text-muted small mb-0">Complete record of your past and present bus reservations.</p>
        </div>
        <form method="get" class="d-flex align-items-center gap-2">
            <label for="status" class="small text-muted text-nowrap fw-semibold">Filter:</label>
            <select class="form-select form-select-sm rounded-pill px-3" id="status" name="status" onchange="this.form.submit()">
                <option value="">All statuses</option>
                <?php foreach ($allowed as $s): ?>
                    <option value="<?= e($s) ?>" <?= $filter === $s ? 'selected' : '' ?>><?= e(ucfirst(strtolower($s))) ?></option>
                <?php endforeach; ?>
            </select>
        </form>
    </div>

    <?php if (!$bookings): ?>
        <div class="panel p-5 bg-white rounded-4 border shadow-sm text-center">
            <div class="empty-state py-4">
                <i class="bi bi-clock-history fs-1 text-muted mb-2"></i>
                <h2 class="h5 fw-bold mb-1">No Bookings Found</h2>
                <p class="text-muted small mb-3">No reservations matched your selected filter.</p>
                <a href="<?= url('index.php') ?>#search" class="btn btn-primary btn-sm rounded-pill px-4">
                    <i class="bi bi-search me-1"></i> Search Trips
                </a>
            </div>
        </div>
    <?php else: ?>
        <div class="panel bg-white rounded-4 border shadow-sm overflow-hidden">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 custom-modern-table">
                    <thead>
                        <tr>
                            <th class="ps-4">Reference</th>
                            <th>Booked On</th>
                            <th>Route</th>
                            <th>Departure Time</th>
                            <th>Total Fare</th>
                            <th>Status</th>
                            <th>Payment</th>
                            <th class="text-end pe-4">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($bookings as $b): ?>
                        <tr>
                            <td class="ps-4 font-monospace fw-bold text-dark"><?= e($b['booking_reference']) ?></td>
                            <td><span class="small text-muted"><?= e(fmt_date($b['booking_date'])) ?></span></td>
                            <td><div class="fw-semibold text-dark"><?= e($b['origin']) ?> &rarr; <?= e($b['destination']) ?></div></td>
                            <td><div class="small fw-medium text-dark"><?= e(fmt_datetime($b['departure_time'])) ?></div></td>
                            <td><span class="fw-bold text-primary"><?= money($b['total_fare']) ?></span></td>
                            <td><?= status_badge($b['status']) ?></td>
                            <td><?= $b['payment_status'] ? status_badge($b['payment_status']) : '<span class="text-muted small">—</span>' ?></td>
                            <td class="text-end pe-4">
                                <a class="btn btn-outline-secondary btn-sm rounded-pill px-3" href="<?= url('passenger/booking_confirmation.php?booking_id=' . (int)$b['booking_id']) ?>">
                                    Details
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
