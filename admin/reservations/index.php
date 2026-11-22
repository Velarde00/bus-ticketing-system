<?php
require_once __DIR__ . '/../../includes/auth.php';
require_role('ADMIN');

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawBookingId = $_POST['booking_id'] ?? '';
    $bookingId = filter_var(is_scalar($rawBookingId) ? $rawBookingId : '', FILTER_VALIDATE_INT);
    $action = $_POST['action'] ?? '';
    $action = is_string($action) ? $action : '';
    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$bookingId || !in_array($action, ['confirm', 'cancel'], true)) {
        $errors[] = 'Choose a valid reservation action.';
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                "SELECT bk.status, p.payment_status
                 FROM bookings bk LEFT JOIN payments p ON p.payment_id = (
                     SELECT p2.payment_id FROM payments p2 WHERE p2.booking_id = bk.booking_id
                     ORDER BY p2.payment_date DESC, p2.payment_id DESC LIMIT 1
                 )
                 WHERE bk.booking_id = :id FOR UPDATE"
            );
            $stmt->execute([':id' => $bookingId]);
            $booking = $stmt->fetch();
            if (!$booking) {
                throw new RuntimeException('Reservation not found.');
            }
            if (($action === 'confirm' && $booking['status'] !== 'PENDING') || ($action === 'cancel' && $booking['status'] === 'CANCELLED')) {
                throw new RuntimeException('This reservation can no longer be changed.');
            }

            if ($action === 'confirm') {
                if ($booking['payment_status'] !== 'PAID') {
                    throw new RuntimeException('Record the payment as paid before confirming this reservation.');
                }
                $pdo->prepare("UPDATE bookings SET status = 'CONFIRMED' WHERE booking_id = :id")->execute([':id' => $bookingId]);
                $seats = $pdo->prepare(
                    "SELECT bs.booking_seat_id FROM booking_seats bs
                     LEFT JOIN tickets tk ON tk.booking_seat_id = bs.booking_seat_id
                     WHERE bs.booking_id = :id AND bs.seat_status = 'BOOKED' AND tk.ticket_id IS NULL"
                );
                $seats->execute([':id' => $bookingId]);
                $ticketInsert = $pdo->prepare('INSERT INTO tickets (booking_seat_id, ticket_number) VALUES (:seat, :number)');
                $ticketUpdate = $pdo->prepare('UPDATE tickets SET ticket_number = :number WHERE ticket_id = :id');
                foreach ($seats->fetchAll(PDO::FETCH_COLUMN) as $seatId) {
                    $ticketInsert->execute([':seat' => $seatId, ':number' => 'TMP-' . bin2hex(random_bytes(8))]);
                    $ticketId = (int)$pdo->lastInsertId();
                    $ticketUpdate->execute([':number' => sprintf('TKT-%s-%06d', date('Y'), $ticketId), ':id' => $ticketId]);
                }
            } else {
                // Fetch booking details to notify the passenger
                $bkInfoStmt = $pdo->prepare(
                    "SELECT bk.booking_id, bk.booking_reference, bk.user_id, u.email AS user_email,
                            r.origin, r.destination, t.departure_time
                     FROM bookings bk
                     JOIN users u ON u.user_id = bk.user_id
                     JOIN trips t ON t.trip_id = bk.trip_id
                     JOIN routes r ON r.route_id = t.route_id
                     WHERE bk.booking_id = :id"
                );
                $bkInfoStmt->execute([':id' => $bookingId]);
                $bkInfo = $bkInfoStmt->fetch();

                $pdo->prepare("UPDATE bookings SET status = 'CANCELLED', cancelled_by = 'ADMIN' WHERE booking_id = :id")->execute([':id' => $bookingId]);
                $pdo->prepare("UPDATE booking_seats SET seat_status = 'CANCELLED' WHERE booking_id = :id")->execute([':id' => $bookingId]);

                if ($bkInfo) {
                    $originDest = ($bkInfo['origin'] ?? 'Origin') . ' → ' . ($bkInfo['destination'] ?? 'Destination');
                    $depFormatted = !empty($bkInfo['departure_time']) ? fmt_datetime($bkInfo['departure_time']) : 'scheduled departure';
                    $notifTitle = 'Reservation Cancelled by Terminal';
                    $notifMsg = "Your reservation {$bkInfo['booking_reference']} ({$originDest}) scheduled for {$depFormatted} has been cancelled by administration. You may rebook another trip or request a full refund.";
                    $notifLink = 'passenger/booking_confirmation.php?booking_id=' . (int)$bookingId;

                    send_user_notification(
                        $pdo,
                        (int)$bkInfo['user_id'],
                        $notifTitle,
                        $notifMsg,
                        $notifLink,
                        $bkInfo['user_email'] ?? null
                    );
                }
            }
            $pdo->commit();
            set_flash('success', $action === 'confirm' ? 'Reservation confirmed and tickets issued.' : 'Reservation cancelled and seats released.');
            redirect('admin/reservations/index.php');
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $e->getMessage();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('admin reservation action: ' . $e->getMessage());
            $errors[] = 'Could not update this reservation. Please check the booking and try again.';
        }
    }
}

// --- Filter parameters ---
$search = trim($_GET['search'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$filterPaymentStatus = trim($_GET['payment_status'] ?? '');
$filterChannel = trim($_GET['channel'] ?? '');
$filterDate = trim($_GET['date'] ?? '');

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(bk.booking_reference LIKE :search OR u.first_name LIKE :search OR u.last_name LIKE :search OR r.origin LIKE :search OR r.destination LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if ($filterStatus !== '' && $filterStatus !== 'ALL') {
    $where[] = "bk.status = :status";
    $params[':status'] = $filterStatus;
}

if ($filterPaymentStatus !== '' && $filterPaymentStatus !== 'ALL') {
    $where[] = "(SELECT p.payment_status FROM payments p WHERE p.booking_id = bk.booking_id ORDER BY p.payment_date DESC, p.payment_id DESC LIMIT 1) = :pay_status";
    $params[':pay_status'] = $filterPaymentStatus;
}

if ($filterChannel === 'ONLINE') {
    $where[] = "bk.booking_reference LIKE 'BT-%'";
} elseif ($filterChannel === 'WALK_IN') {
    $where[] = "bk.booking_reference LIKE 'WI-%'";
}

if ($filterDate !== '') {
    $where[] = "(DATE(bk.booking_date) = :bdate OR DATE(t.departure_time) = :bdate)";
    $params[':bdate'] = $filterDate;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare(
    "SELECT bk.booking_id, bk.booking_reference, bk.booking_date, bk.status,
            CONCAT(u.first_name, ' ', u.last_name) AS passenger,
            CONCAT(r.origin, ' to ', r.destination) AS route, t.departure_time,
            (SELECT p.payment_status FROM payments p WHERE p.booking_id = bk.booking_id
             ORDER BY p.payment_date DESC, p.payment_id DESC LIMIT 1) AS payment_status
     FROM bookings bk JOIN users u ON u.user_id = bk.user_id
     JOIN trips t ON t.trip_id = bk.trip_id JOIN routes r ON r.route_id = t.route_id
     {$whereSql}
     ORDER BY bk.booking_date DESC"
);
$stmt->execute($params);
$reservations = $stmt->fetchAll();

$pageTitle = 'Manage reservations';
$layout = 'admin';
$activePage = 'reservations';
require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="h3 fw-bold mb-1 text-dark">Manage Reservations</h1>
        <p class="text-muted small mb-0">Review passenger bookings, confirm paid reservations, and issue tickets.</p>
    </div>
    <div class="d-flex gap-2 align-items-center">
        <span class="badge bg-light text-muted border px-3 py-2 rounded-pill"><?= count($reservations) ?> bookings</span>
        <a href="<?= url('admin/reservations/walk_in.php') ?>" class="btn btn-primary rounded-pill shadow-sm px-4">
            <i class="bi bi-person-walking me-1"></i> New Walk-in Booking
        </a>
    </div>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger py-2 border-0 shadow-sm small mb-4">
        <ul class="mb-0 ps-3">
            <?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- Filter & Search Controls -->
<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-body p-3">
        <form method="get" action="<?= url('admin/reservations/index.php') ?>" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small fw-semibold text-muted mb-1"><i class="bi bi-search me-1"></i>Search</label>
                <input type="text" name="search" class="form-control form-control-sm rounded-pill" placeholder="Ref, Passenger, Route..." value="<?= e($search) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold text-muted mb-1">Booking Status</label>
                <select name="status" class="form-select form-select-sm rounded-pill">
                    <option value="ALL">All Statuses</option>
                    <option value="CONFIRMED" <?= $filterStatus === 'CONFIRMED' ? 'selected' : '' ?>>Confirmed</option>
                    <option value="PENDING" <?= $filterStatus === 'PENDING' ? 'selected' : '' ?>>Pending</option>
                    <option value="CANCELLED" <?= $filterStatus === 'CANCELLED' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold text-muted mb-1">Payment Status</label>
                <select name="payment_status" class="form-select form-select-sm rounded-pill">
                    <option value="ALL">All Payments</option>
                    <option value="PAID" <?= $filterPaymentStatus === 'PAID' ? 'selected' : '' ?>>Paid</option>
                    <option value="REFUNDED" <?= $filterPaymentStatus === 'REFUNDED' ? 'selected' : '' ?>>Refunded</option>
                    <option value="REFUND_REQUESTED" <?= $filterPaymentStatus === 'REFUND_REQUESTED' ? 'selected' : '' ?>>Refund Requested</option>
                    <option value="PENDING" <?= $filterPaymentStatus === 'PENDING' ? 'selected' : '' ?>>Pending</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold text-muted mb-1">Channel</label>
                <select name="channel" class="form-select form-select-sm rounded-pill">
                    <option value="ALL">All Channels</option>
                    <option value="ONLINE" <?= $filterChannel === 'ONLINE' ? 'selected' : '' ?>>Online Booker</option>
                    <option value="WALK_IN" <?= $filterChannel === 'WALK_IN' ? 'selected' : '' ?>>Walk-in</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold text-muted mb-1">Date</label>
                <input type="date" name="date" class="form-control form-control-sm rounded-pill" value="<?= e($filterDate) ?>">
            </div>
            <div class="col-md-1 d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-primary rounded-pill w-100 shadow-sm" title="Apply Filter">
                    <i class="bi bi-funnel-fill"></i>
                </button>
                <?php if ($search !== '' || ($filterStatus !== '' && $filterStatus !== 'ALL') || ($filterPaymentStatus !== '' && $filterPaymentStatus !== 'ALL') || ($filterChannel !== '' && $filterChannel !== 'ALL') || $filterDate !== ''): ?>
                    <a href="<?= url('admin/reservations/index.php') ?>" class="btn btn-sm btn-outline-secondary rounded-pill" title="Reset Filters">
                        <i class="bi bi-arrow-counterclockwise"></i>
                    </a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<div class="panel bg-white rounded-4 border shadow-sm overflow-hidden">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 custom-modern-table">
            <thead>
                <tr>
                    <th class="ps-4">Reference</th>
                    <th>Passenger</th>
                    <th>Route</th>
                    <th>Departure</th>
                    <th>Booked On</th>
                    <th>Payment</th>
                    <th>Status</th>
                    <th class="text-end pe-4">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($reservations as $booking): ?>
                <tr>
                    <td class="ps-4 font-monospace fw-bold text-dark">
                        <?php $isWalkIn = str_starts_with($booking['booking_reference'], 'WI-'); ?>
                        <?= e($booking['booking_reference']) ?>
                        <?php if ($isWalkIn): ?>
                            <span class="badge bg-warning-subtle text-warning border border-warning-subtle ms-1 small" style="font-size:0.65rem;">Walk-in</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="d-flex align-items-center gap-2">
                            <span class="table-avatar-circle"><?= strtoupper(substr($booking['passenger'], 0, 1)) ?></span>
                            <span class="fw-semibold text-dark"><?= e($booking['passenger']) ?></span>
                        </div>
                    </td>
                    <td><span class="fw-medium text-dark"><?= e($booking['route']) ?></span></td>
                    <td><span class="small text-dark"><?= e(fmt_datetime($booking['departure_time'])) ?></span></td>
                    <td><span class="small text-muted"><?= e(fmt_datetime($booking['booking_date'])) ?></span></td>
                    <td><?= $booking['payment_status'] ? status_badge($booking['payment_status']) : '<span class="text-muted small">—</span>' ?></td>
                    <td><?= status_badge($booking['status']) ?></td>
                    <td class="text-end text-nowrap pe-4">
                        <div class="dropdown d-inline-block">
                            <button class="btn btn-light btn-sm rounded-circle p-2 shadow-xs border" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Actions" style="width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center;">
                                <i class="bi bi-three-dots-vertical"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end shadow border-0 rounded-3 py-1">
                                <li>
                                    <button type="button" class="dropdown-item py-2 px-3 small d-flex align-items-center gap-2 btn-view-reservation"
                                            data-id="<?= (int)$booking['booking_id'] ?>"
                                            data-ref="<?= e($booking['booking_reference']) ?>">
                                        <i class="bi bi-eye text-primary"></i> View Details
                                    </button>
                                </li>
                                <?php if ($booking['status'] === 'PENDING'): ?>
                                    <li>
                                        <form method="post" data-confirm="Confirm this paid reservation and issue tickets?">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="booking_id" value="<?= (int)$booking['booking_id'] ?>">
                                            <input type="hidden" name="action" value="confirm">
                                            <button class="dropdown-item py-2 px-3 small text-success d-flex align-items-center gap-2" type="submit" <?= $booking['payment_status'] !== 'PAID' ? 'disabled title="Payment must be marked paid first."' : '' ?>>
                                                <i class="bi bi-check2-circle text-success"></i> Confirm Booking
                                            </button>
                                        </form>
                                    </li>
                                <?php endif; ?>
                                <?php if ($booking['status'] !== 'CANCELLED'): ?>
                                    <li><hr class="dropdown-divider my-1"></li>
                                    <li>
                                        <form method="post" data-confirm="Cancel this reservation and release its seats?">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="booking_id" value="<?= (int)$booking['booking_id'] ?>">
                                            <input type="hidden" name="action" value="cancel">
                                            <button class="dropdown-item py-2 px-3 small text-danger d-flex align-items-center gap-2" type="submit">
                                                <i class="bi bi-x-circle text-danger"></i> Cancel Booking
                                            </button>
                                        </form>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$reservations): ?>
                <tr><td colspan="8" class="text-center text-muted py-5">No reservations placed in the system yet.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal for Reservation Details -->
<div class="modal fade" id="reservationDetailsModal" tabindex="-1" aria-labelledby="reservationDetailsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-xl">
        <div class="modal-content rounded-4 border-0 shadow">
            <div class="modal-header border-bottom px-4 pt-4 pb-3">
                <div class="d-flex align-items-center gap-2">
                    <span class="stat-icon-wrapper bg-primary-subtle text-primary">
                        <i class="bi bi-ticket-perforated"></i>
                    </span>
                    <h5 class="modal-title fw-bold text-dark mb-0" id="reservationDetailsModalLabel">
                        Reservation Details
                    </h5>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body px-4 py-4" id="reservationDetailsModalBody">
                <div class="text-center py-5 text-muted">
                    <div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>
                    Loading reservation details...
                </div>
            </div>
            <div class="modal-footer border-top px-4 py-3 bg-light bg-opacity-50">
                <button type="button" class="btn btn-outline-secondary rounded-pill px-4" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var modalEl = document.getElementById('reservationDetailsModal');
    var modalBody = document.getElementById('reservationDetailsModalBody');
    var modalLabel = document.getElementById('reservationDetailsModalLabel');
    var bsModal = modalEl && typeof bootstrap !== 'undefined' ? new bootstrap.Modal(modalEl) : null;

    document.querySelectorAll('.btn-view-reservation').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var id = this.getAttribute('data-id');
            var ref = this.getAttribute('data-ref');

            if (modalLabel) modalLabel.textContent = 'Reservation ' + ref;
            if (modalBody) {
                modalBody.innerHTML = '<div class="text-center py-5 text-muted"><div class="spinner-border spinner-border-sm text-primary me-2" role="status"></div>Loading reservation details...</div>';
            }

            if (bsModal) bsModal.show();

            fetch('<?= url("admin/reservations/view.php") ?>?id=' + encodeURIComponent(id) + '&modal=1')
                .then(function (res) { return res.text(); })
                .then(function (html) {
                    if (modalBody) modalBody.innerHTML = html;
                })
                .catch(function () {
                    if (modalBody) {
                        modalBody.innerHTML = '<div class="alert alert-danger mb-0">Failed to load reservation details. Please try again.</div>';
                    }
                });
        });
    });
});
</script>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
