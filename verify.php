<?php
/**
 * verify.php
 * Official Ticket & Booking Verification Portal.
 * Allows passengers, conductors, and terminal gate staff to authenticate
 * any ticket number (TKT-YYYY-XXXXXX) or booking reference (BT-... / WI-...).
 */
require_once __DIR__ . '/includes/auth.php';

$code = trim($_GET['code'] ?? $_POST['code'] ?? '');
$result = null;
$searched = false;

if ($code !== '') {
    $searched = true;
    
    // Search by Ticket Number first
    $stmt = $pdo->prepare(
        "SELECT tk.ticket_id, tk.ticket_number, bs.seat_number, bs.price, bs.seat_status,
                bk.booking_id, bk.booking_reference, bk.status AS booking_status, bk.booking_date,
                r.origin, r.destination, t.departure_time, t.arrival_time,
                b.bus_number, b.bus_type,
                CONCAT(u.first_name, ' ', u.last_name) AS passenger_name,
                (SELECT p.payment_status FROM payments p WHERE p.booking_id = bk.booking_id ORDER BY p.payment_date DESC LIMIT 1) AS payment_status
         FROM tickets tk
         JOIN booking_seats bs ON bs.booking_seat_id = tk.booking_seat_id
         JOIN bookings bk ON bk.booking_id = bs.booking_id
         JOIN trips t ON t.trip_id = bk.trip_id
         JOIN routes r ON r.route_id = t.route_id
         JOIN buses b ON b.bus_id = t.bus_id
         JOIN users u ON u.user_id = bk.user_id
         WHERE tk.ticket_number = :code"
    );
    $stmt->execute([':code' => $code]);
    $result = $stmt->fetch();

    // If not found by ticket number, search by Booking Reference
    if (!$result) {
        $stmt2 = $pdo->prepare(
            "SELECT bk.booking_id, bk.booking_reference, bk.status AS booking_status, bk.booking_date,
                    r.origin, r.destination, t.departure_time, t.arrival_time,
                    b.bus_number, b.bus_type,
                    CONCAT(u.first_name, ' ', u.last_name) AS passenger_name,
                    (SELECT p.payment_status FROM payments p WHERE p.booking_id = bk.booking_id ORDER BY p.payment_date DESC LIMIT 1) AS payment_status,
                    (SELECT GROUP_CONCAT(bs.seat_number ORDER BY bs.seat_number SEPARATOR ', ')
                     FROM booking_seats bs WHERE bs.booking_id = bk.booking_id AND bs.seat_status = 'BOOKED') AS seat_numbers,
                    (SELECT COUNT(*) FROM booking_seats bs WHERE bs.booking_id = bk.booking_id AND bs.seat_status = 'BOOKED') AS total_seats
             FROM bookings bk
             JOIN trips t ON t.trip_id = bk.trip_id
             JOIN routes r ON r.route_id = t.route_id
             JOIN buses b ON b.bus_id = t.bus_id
             JOIN users u ON u.user_id = bk.user_id
             WHERE bk.booking_reference = :code"
        );
        $stmt2->execute([':code' => $code]);
        $result = $stmt2->fetch();
        if ($result) {
            $result['is_booking_ref'] = true;
        }
    }
}

$pageTitle = 'Verify Ticket & Booking Authenticity';
$layout    = 'public';
require __DIR__ . '/includes/header.php';
?>
<div class="container py-5" style="max-width: 800px;">
    <!-- Headline Card -->
    <div class="text-center mb-4">
        <span class="badge bg-primary-subtle text-primary rounded-pill px-3 py-1 fw-semibold small mb-2">
            <i class="bi bi-shield-check me-1"></i> Official Verification System
        </span>
        <h1 class="h3 fw-bold text-dark">Verify Ticket Authenticity</h1>
        <p class="text-muted small mb-0">Enter a Ticket Number (e.g. <code>TKT-2026-000001</code>) or Booking Reference (e.g. <code>BT-2026...</code> or <code>WI-...</code>) to check live status.</p>
    </div>

    <!-- Search / Verify Form -->
    <div class="card border-0 shadow-sm rounded-4 p-4 mb-4 bg-white">
        <form method="get" action="<?= url('verify.php') ?>" class="row g-2 align-items-center">
            <div class="col-sm-9">
                <div class="input-group">
                    <span class="input-group-text bg-light border-end-0 text-muted"><i class="bi bi-upc-scan"></i></span>
                    <input type="text" name="code" class="form-control form-control-lg border-start-0 font-monospace" placeholder="e.g. TKT-2026-000001 or BT-..." value="<?= e($code) ?>" required autofocus>
                </div>
            </div>
            <div class="col-sm-3">
                <button type="submit" class="btn btn-primary btn-lg w-100 rounded-pill shadow-sm">
                    <i class="bi bi-check2-circle me-1"></i> Verify
                </button>
            </div>
        </form>
    </div>

    <?php if ($searched): ?>
        <?php if ($result): ?>
            <?php
                $isConfirmed = ($result['booking_status'] === 'CONFIRMED');
                $isPaid = ($result['payment_status'] === 'PAID');
                $isValid = $isConfirmed && $isPaid;
            ?>
            <div class="card border-0 shadow-sm rounded-4 overflow-hidden mb-4">
                <div class="p-4 <?= $isValid ? 'bg-success text-white' : 'bg-warning text-dark' ?> d-flex flex-wrap justify-content-between align-items-center gap-3">
                    <div class="d-flex align-items-center gap-3">
                        <i class="bi <?= $isValid ? 'bi-patch-check-fill' : 'bi-exclamation-triangle-fill' ?> fs-1"></i>
                        <div>
                            <h2 class="h5 fw-bold mb-0"><?= $isValid ? 'Valid &amp; Authentic Boarding Pass' : 'Ticket Requires Attention' ?></h2>
                            <small class="opacity-75 font-monospace"><?= e($code) ?></small>
                        </div>
                    </div>
                    <div>
                        <span class="badge bg-white <?= $isValid ? 'text-success' : 'text-dark' ?> px-3 py-2 rounded-pill fw-bold">
                            Booking: <?= e($result['booking_status']) ?> &bull; Payment: <?= e($result['payment_status'] ?? 'PENDING') ?>
                        </span>
                    </div>
                </div>

                <div class="card-body p-4 bg-white">
                    <div class="row g-4">
                        <div class="col-sm-6">
                            <span class="text-muted small d-block mb-1">Passenger Name</span>
                            <div class="fw-bold text-dark fs-5"><?= e($result['passenger_name']) ?></div>
                        </div>
                        <div class="col-sm-6">
                            <span class="text-muted small d-block mb-1">Route</span>
                            <div class="fw-bold text-dark fs-5"><?= e($result['origin']) ?> &rarr; <?= e($result['destination']) ?></div>
                        </div>
                        <div class="col-sm-6">
                            <span class="text-muted small d-block mb-1">Departure Schedule</span>
                            <div class="fw-semibold text-dark"><?= e(fmt_datetime($result['departure_time'])) ?></div>
                        </div>
                        <div class="col-sm-6">
                            <span class="text-muted small d-block mb-1">Assigned Coach &amp; Class</span>
                            <div class="fw-semibold text-dark">Bus <?= e($result['bus_number']) ?> (<?= e($result['bus_type']) ?>)</div>
                        </div>
                        <div class="col-sm-6">
                            <span class="text-muted small d-block mb-1">Seat Assignment</span>
                            <div class="badge bg-primary fs-6 px-3 py-2 rounded-pill">
                                <i class="bi bi-person-fill me-1"></i>
                                <?= !empty($result['is_booking_ref']) ? 'Seats: ' . e($result['seat_numbers']) : 'Seat ' . (int)$result['seat_number'] ?>
                            </div>
                        </div>
                        <div class="col-sm-6">
                            <span class="text-muted small d-block mb-1">Booking Reference</span>
                            <div class="font-monospace fw-bold text-dark"><?= e($result['booking_reference']) ?></div>
                        </div>
                    </div>
                </div>

                <div class="card-footer bg-light p-3 px-4 d-flex justify-content-between align-items-center">
                    <span class="small text-muted"><i class="bi bi-shield-lock-fill text-success me-1"></i> Digitally Verified against Central Terminal Database</span>
                    <?php if (current_user() && current_user()['role'] !== 'PASSENGER'): ?>
                        <a href="<?= url('admin/reservations/view.php?id=' . (int)$result['booking_id']) ?>" class="btn btn-sm btn-outline-dark rounded-pill px-3">
                            Open in Admin <i class="bi bi-arrow-right ms-1"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="card border-0 shadow-sm rounded-4 p-5 text-center bg-white">
                <i class="bi bi-x-circle-fill fs-1 text-danger mb-2"></i>
                <h3 class="h5 fw-bold text-dark">No Record Found</h3>
                <p class="text-muted small mb-0">No active ticket or booking matched code <code><?= e($code) ?></code>. Please verify the numbers on the printed pass.</p>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>