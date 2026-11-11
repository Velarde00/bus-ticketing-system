<?php
/**
 * admin/dashboard.php
 * Administrator home: system statistics and the latest reservations.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('ADMIN');

$stats = $pdo->query(
    "SELECT
        (SELECT COUNT(*) FROM users   WHERE role = 'PASSENGER')     AS passengers,
        (SELECT COUNT(*) FROM buses)                                AS buses,
        (SELECT COUNT(*) FROM drivers)                              AS drivers,
        (SELECT COUNT(*) FROM routes)                               AS routes,
        (SELECT COUNT(*) FROM trips   WHERE status = 'SCHEDULED')   AS trips,
        (SELECT COUNT(*) FROM bookings)                             AS bookings,
        (SELECT COUNT(*) FROM bookings WHERE status = 'PENDING')    AS pending,
        (SELECT COUNT(*) FROM bookings WHERE status = 'CONFIRMED')  AS confirmed,
        (SELECT COUNT(*) FROM bookings WHERE status = 'CANCELLED')  AS cancelled,
        (SELECT COALESCE(SUM(amount), 0) FROM payments WHERE payment_status = 'PAID') AS sales"
)->fetch();

$recent = $pdo->query(
    "SELECT bk.booking_id, bk.booking_reference, bk.booking_date, bk.status,
            CONCAT(u.first_name, ' ', u.last_name) AS passenger,
            CONCAT(r.origin, ' to ', r.destination) AS route
     FROM bookings bk
     JOIN users  u ON u.user_id  = bk.user_id
     JOIN trips  t ON t.trip_id  = bk.trip_id
     JOIN routes r ON r.route_id = t.route_id
     ORDER BY bk.booking_date DESC LIMIT 8"
)->fetchAll();

// Pending refund / rebooking alerts (only count UNRESOLVED alerts)
// An alert is unresolved if:
// 1) Passenger requested refund but payment is not yet REFUNDED
// 2) Passenger requested rebook but booking status is still CANCELLED without an active replacement
$alertCount = (int)$pdo->query(
    "SELECT COUNT(*) FROM bookings bk
     LEFT JOIN payments p ON p.booking_id = bk.booking_id
     WHERE bk.status = 'CANCELLED'
       AND (
           (bk.cancellation_choice = 'REFUND_REQUESTED' AND (p.payment_status IS NULL OR p.payment_status <> 'REFUNDED'))
           OR
           (bk.cancellation_choice = 'REBOOK_REQUESTED' AND NOT EXISTS (
               SELECT 1 FROM bookings b2 WHERE b2.user_id = bk.user_id AND b2.trip_id = bk.trip_id AND b2.status = 'CONFIRMED'
           ))
       )"
)->fetchColumn();

$pageTitle  = 'Dashboard';
$layout     = 'admin';
$activePage = 'dashboard';
require __DIR__ . '/../includes/header.php';
?>
<!-- Admin Header Bar -->
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <div class="d-flex align-items-center gap-2 mb-1">
            <span class="badge bg-primary-subtle text-primary rounded-pill px-3 py-1 fw-semibold small">Operations Center</span>
            <span class="text-muted small">&bull;</span>
            <span class="text-muted small"><?= e(date('l, F j, Y')) ?></span>
        </div>
        <h1 class="h3 fw-bold mb-0 text-dark">Executive Dashboard</h1>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= url('admin/notifications/index.php') ?>" class="btn btn-outline-primary btn-sm rounded-pill px-3 position-relative">
            <i class="bi bi-bell-fill me-1"></i> Alerts
            <?php if ($alertCount > 0): ?>
                <span class="badge bg-danger rounded-pill ms-1"><?= $alertCount ?></span>
            <?php endif; ?>
        </a>
        <a href="<?= url('admin/trips/index.php') ?>" class="btn btn-outline-dark btn-sm rounded-pill px-3">
            <i class="bi bi-calendar-plus me-1"></i> Add Trip
        </a>
        <a href="<?= url('admin/reservations/index.php') ?>" class="btn btn-primary btn-sm rounded-pill shadow-sm px-3">
            <i class="bi bi-ticket-perforated me-1"></i> Review Reservations
        </a>
    </div>
</div>

<!-- Operations Section KPI Cards -->
<div class="mb-4">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h2 class="h6 fw-bold text-uppercase text-muted mb-0 letter-spacing-1">
            <i class="bi bi-hdd-network me-1 text-primary"></i> Fleet &amp; Operations
        </h2>
    </div>
    <div class="row row-cols-2 row-cols-md-3 row-cols-xl-5 g-3">
        <div class="col">
            <div class="stat-card-modern p-3 rounded-4 bg-white border shadow-sm h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="stat-card-label text-muted small fw-semibold">Passengers</span>
                    <span class="stat-icon-wrapper bg-info-subtle text-info"><i class="bi bi-people-fill"></i></span>
                </div>
                <div class="stat-card-value h3 fw-bold text-dark mb-0"><?= (int)$stats['passengers'] ?></div>
                <div class="stat-card-sub text-muted mt-1 small">Registered users</div>
            </div>
        </div>

        <div class="col">
            <div class="stat-card-modern p-3 rounded-4 bg-white border shadow-sm h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="stat-card-label text-muted small fw-semibold">Buses</span>
                    <span class="stat-icon-wrapper bg-primary-subtle text-primary"><i class="bi bi-bus-front-fill"></i></span>
                </div>
                <div class="stat-card-value h3 fw-bold text-dark mb-0"><?= (int)$stats['buses'] ?></div>
                <div class="stat-card-sub text-muted mt-1 small">Active fleet</div>
            </div>
        </div>

        <div class="col">
            <div class="stat-card-modern p-3 rounded-4 bg-white border shadow-sm h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="stat-card-label text-muted small fw-semibold">Drivers</span>
                    <span class="stat-icon-wrapper bg-warning-subtle text-warning"><i class="bi bi-person-vcard-fill"></i></span>
                </div>
                <div class="stat-card-value h3 fw-bold text-dark mb-0"><?= (int)$stats['drivers'] ?></div>
                <div class="stat-card-sub text-muted mt-1 small">Licensed personnel</div>
            </div>
        </div>

        <div class="col">
            <div class="stat-card-modern p-3 rounded-4 bg-white border shadow-sm h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="stat-card-label text-muted small fw-semibold">Routes</span>
                    <span class="stat-icon-wrapper bg-primary-subtle text-primary"><i class="bi bi-signpost-split-fill"></i></span>
                </div>
                <div class="stat-card-value h3 fw-bold text-dark mb-0"><?= (int)$stats['routes'] ?></div>
                <div class="stat-card-sub text-muted mt-1 small">Active connections</div>
            </div>
        </div>

        <div class="col">
            <div class="stat-card-modern p-3 rounded-4 bg-white border shadow-sm h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="stat-card-label text-muted small fw-semibold">Scheduled Trips</span>
                    <span class="stat-icon-wrapper bg-success-subtle text-success"><i class="bi bi-calendar2-check-fill"></i></span>
                </div>
                <div class="stat-card-value h3 fw-bold text-dark mb-0"><?= (int)$stats['trips'] ?></div>
                <div class="stat-card-sub text-muted mt-1 small">Upcoming runs</div>
            </div>
        </div>
    </div>
</div>

<!-- Reservations & Sales Financial KPIs -->
<div class="mb-4">
    <div class="d-flex align-items-center justify-content-between mb-3">
        <h2 class="h6 fw-bold text-uppercase text-muted mb-0 letter-spacing-1">
            <i class="bi bi-cash-stack me-1 text-success"></i> Bookings &amp; Financial Overview
        </h2>
    </div>
    <div class="row row-cols-2 row-cols-md-3 row-cols-xl-5 g-3">
        <div class="col">
            <div class="stat-card-modern p-3 rounded-4 bg-white border shadow-sm h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="stat-card-label text-muted small fw-semibold">Total Bookings</span>
                    <span class="stat-icon-wrapper bg-light text-dark"><i class="bi bi-collection"></i></span>
                </div>
                <div class="stat-card-value h3 fw-bold text-dark mb-0"><?= (int)$stats['bookings'] ?></div>
                <div class="stat-card-sub text-muted mt-1 small">All time</div>
            </div>
        </div>

        <div class="col">
            <div class="stat-card-modern p-3 rounded-4 bg-white border shadow-sm h-100 stat-pending-glow">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="stat-card-label text-muted small fw-semibold">Pending</span>
                    <span class="stat-icon-wrapper bg-warning-subtle text-warning"><i class="bi bi-hourglass-split"></i></span>
                </div>
                <div class="stat-card-value h3 fw-bold text-warning mb-0"><?= (int)$stats['pending'] ?></div>
                <div class="stat-card-sub text-muted mt-1 small">Requires review</div>
            </div>
        </div>

        <div class="col">
            <div class="stat-card-modern p-3 rounded-4 bg-white border shadow-sm h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="stat-card-label text-muted small fw-semibold">Confirmed</span>
                    <span class="stat-icon-wrapper bg-success-subtle text-success"><i class="bi bi-check-circle-fill"></i></span>
                </div>
                <div class="stat-card-value h3 fw-bold text-success mb-0"><?= (int)$stats['confirmed'] ?></div>
                <div class="stat-card-sub text-muted mt-1 small">Tickets issued</div>
            </div>
        </div>

        <div class="col">
            <div class="stat-card-modern p-3 rounded-4 bg-white border shadow-sm h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="stat-card-label text-muted small fw-semibold">Cancelled</span>
                    <span class="stat-icon-wrapper bg-secondary-subtle text-secondary"><i class="bi bi-x-circle-fill"></i></span>
                </div>
                <div class="stat-card-value h3 fw-bold text-muted mb-0"><?= (int)$stats['cancelled'] ?></div>
                <div class="stat-card-sub text-muted mt-1 small">Seats released</div>
            </div>
        </div>

        <div class="col">
            <div class="stat-card-modern p-3 rounded-4 bg-dark text-white border-0 shadow-sm h-100 stat-sales-banner">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="stat-card-label text-white-50 small fw-semibold">Recorded Sales</span>
                    <span class="stat-icon-wrapper bg-white bg-opacity-10 text-warning"><i class="bi bi-graph-up-arrow"></i></span>
                </div>
                <div class="stat-card-value h3 fw-bold text-signal mb-0"><?= money($stats['sales']) ?></div>
                <div class="stat-card-sub text-white-50 mt-1 small">Verified paid revenue</div>
            </div>
        </div>
    </div>
</div>

<!-- Latest Reservations Panel -->
<div class="panel bg-white rounded-4 border shadow-sm overflow-hidden mb-4">
    <div class="panel-head p-3 px-4 border-bottom d-flex justify-content-between align-items-center bg-light bg-opacity-50">
        <div>
            <h2 class="h5 fw-bold mb-0">Recent Reservations</h2>
            <small class="text-muted">Live incoming passenger bookings</small>
        </div>
        <a href="<?= url('admin/reservations/index.php') ?>" class="btn btn-outline-secondary btn-sm rounded-pill px-3">
            View All <i class="bi bi-arrow-right ms-1"></i>
        </a>
    </div>

    <?php if (!$recent): ?>
        <div class="empty-state p-5 text-center">
            <i class="bi bi-ticket-perforated fs-1 text-muted mb-2"></i>
            <p class="text-muted mb-0">No reservations placed in the system yet.</p>
        </div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 custom-modern-table">
                <thead>
                    <tr>
                        <th class="ps-4">Reference</th>
                        <th>Passenger</th>
                        <th>Route</th>
                        <th>Booked On</th>
                        <th>Status</th>
                        <th class="text-end pe-4">Action</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($recent as $b): ?>
                    <tr>
                        <td class="ps-4 font-monospace fw-bold text-dark"><?= e($b['booking_reference']) ?></td>
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <span class="table-avatar-circle"><?= strtoupper(substr($b['passenger'], 0, 1)) ?></span>
                                <span class="fw-semibold text-dark"><?= e($b['passenger']) ?></span>
                            </div>
                        </td>
                        <td><span class="text-dark fw-medium"><?= e($b['route']) ?></span></td>
                        <td><span class="small text-muted"><?= e(fmt_datetime($b['booking_date'])) ?></span></td>
                        <td><?= status_badge($b['status']) ?></td>
                        <td class="text-end pe-4">
                            <a class="btn btn-outline-dark btn-sm rounded-pill px-3" href="<?= url('admin/reservations/view.php?id=' . (int)$b['booking_id']) ?>">
                                Details
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
