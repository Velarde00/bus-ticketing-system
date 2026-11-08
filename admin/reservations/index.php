<?php
require_once __DIR__ . '/../../includes/auth.php';
require_role('ADMIN');

$reservations = $pdo->query(
    "SELECT bk.booking_id, bk.booking_reference, bk.booking_date, bk.status,
            CONCAT(u.first_name, ' ', u.last_name) AS passenger,
            CONCAT(r.origin, ' to ', r.destination) AS route, t.departure_time,
            (SELECT p.payment_status FROM payments p WHERE p.booking_id = bk.booking_id
             ORDER BY p.payment_date DESC, p.payment_id DESC LIMIT 1) AS payment_status,
            (SELECT p.payment_method FROM payments p WHERE p.booking_id = bk.booking_id
             ORDER BY p.payment_date DESC, p.payment_id DESC LIMIT 1) AS payment_method,
            (SELECT p.online_payment_option FROM payments p WHERE p.booking_id = bk.booking_id
             ORDER BY p.payment_date DESC, p.payment_id DESC LIMIT 1) AS online_payment_option
     FROM bookings bk JOIN users u ON u.user_id = bk.user_id
     JOIN trips t ON t.trip_id = bk.trip_id JOIN routes r ON r.route_id = t.route_id
     ORDER BY bk.booking_date DESC"
)->fetchAll();

$pageTitle = 'Manage reservations';
$layout = 'admin';
$activePage = 'reservations';
require __DIR__ . '/../../includes/header.php';
?>
<div class="mb-4"><h1 class="h3 mb-0">Manage reservations</h1><p class="text-muted mb-0">Online payments automatically confirm reservations and issue tickets.</p></div>
<div class="panel">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th>Reference</th><th>Passenger</th><th>Route</th><th>Departure</th><th>Booked on</th><th>Payment</th><th>Status</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($reservations as $booking): ?>
                <tr>
                    <td class="fw-semibold"><?= e($booking['booking_reference']) ?></td><td><?= e($booking['passenger']) ?></td>
                    <td><?= e($booking['route']) ?></td><td><?= e(fmt_datetime($booking['departure_time'])) ?></td>
                    <td><?= e(fmt_datetime($booking['booking_date'])) ?></td>
                    <td>
                        <?php if ($booking['payment_status']): ?>
                            <?= status_badge($booking['payment_status']) ?>
                            <?php if ($booking['payment_method']): ?>
                                <div class="small text-muted">
                                    <?= e(payment_method_label($booking['payment_method'])) ?>
                                    <?php if ($booking['online_payment_option']): ?> · <?= e(online_payment_option_label($booking['online_payment_option'])) ?><?php endif; ?>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= status_badge($booking['status']) ?>
                    </td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-outline-dark btn-sm" href="<?= url('admin/reservations/view.php?id=' . (int)$booking['booking_id']) ?>">Details</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$reservations): ?><tr><td colspan="8" class="text-center text-muted py-4">No reservations yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
