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
                "SELECT bk.status, p.payment_status, p.payment_method
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
            if ($action === 'cancel' && $booking['payment_status'] === 'PAID') {
                throw new RuntimeException('Paid reservations cannot be cancelled. Refunds are not available.');
            }

            if ($action === 'confirm') {
                $onlinePaid = $booking['payment_method'] === 'ONLINE' && $booking['payment_status'] === 'PAID';
                $offlineDueInPerson = in_array($booking['payment_method'], ['CASH', 'CARD', 'E_WALLET'], true)
                    && $booking['payment_status'] === 'PENDING';
                if (!$onlinePaid && !$offlineDueInPerson) {
                    throw new RuntimeException('Online payments must be marked paid before confirmation. Offline payment methods must be pending and are collected in person.');
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
                $pdo->prepare("UPDATE bookings SET status = 'CANCELLED' WHERE booking_id = :id")->execute([':id' => $bookingId]);
                $pdo->prepare("UPDATE booking_seats SET seat_status = 'CANCELLED' WHERE booking_id = :id")->execute([':id' => $bookingId]);
                $pdo->prepare(
                    "UPDATE payments SET payment_status = 'CANCELLED', payment_date = NOW()
                     WHERE booking_id = :id AND payment_status <> 'PAID'"
                )->execute([':id' => $bookingId]);
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

$reservations = $pdo->query(
    "SELECT bk.booking_id, bk.booking_reference, bk.booking_date, bk.status,
            CONCAT(u.first_name, ' ', u.last_name) AS passenger,
            CONCAT(r.origin, ' to ', r.destination) AS route, t.departure_time,
            (SELECT p.payment_status FROM payments p WHERE p.booking_id = bk.booking_id
             ORDER BY p.payment_date DESC, p.payment_id DESC LIMIT 1) AS payment_status,
            (SELECT p.payment_method FROM payments p WHERE p.booking_id = bk.booking_id
             ORDER BY p.payment_date DESC, p.payment_id DESC LIMIT 1) AS payment_method
     FROM bookings bk JOIN users u ON u.user_id = bk.user_id
     JOIN trips t ON t.trip_id = bk.trip_id JOIN routes r ON r.route_id = t.route_id
     ORDER BY bk.booking_date DESC"
)->fetchAll();

$pageTitle = 'Manage reservations';
$layout = 'admin';
$activePage = 'reservations';
require __DIR__ . '/../../includes/header.php';
?>
<div class="mb-4"><h1 class="h3 mb-0">Manage reservations</h1><p class="text-muted mb-0">Confirm online bookings after payment, or confirm offline bookings for payment in person.</p></div>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
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
                                    <?= in_array($booking['payment_method'], ['CASH', 'CARD', 'E_WALLET'], true) ? '· due in person' : '' ?>
                                </div>
                            <?php endif; ?>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </td>
                    <td>
                        <?= status_badge($booking['status']) ?>
                        <?php if ($booking['payment_status'] === 'PAID'): ?>
                            <div class="small text-muted">Paid booking cannot be cancelled</div>
                        <?php endif; ?>
                    </td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-outline-dark btn-sm" href="<?= url('admin/reservations/view.php?id=' . (int)$booking['booking_id']) ?>">Details</a>
                        <?php if ($booking['status'] === 'PENDING'): ?>
                            <?php
                            $onlinePaid = $booking['payment_method'] === 'ONLINE' && $booking['payment_status'] === 'PAID';
                            $offlineDueInPerson = in_array($booking['payment_method'], ['CASH', 'CARD', 'E_WALLET'], true)
                                && $booking['payment_status'] === 'PENDING';
                            $canConfirm = $onlinePaid || $offlineDueInPerson;
                            $confirmMessage = $offlineDueInPerson
                                ? 'Confirm this reservation and issue tickets? Payment is due in person.'
                                : 'Confirm this paid online reservation and issue tickets?';
                            ?>
                            <form method="post" class="d-inline" data-confirm="<?= e($confirmMessage) ?>">
                                <?= csrf_field() ?><input type="hidden" name="booking_id" value="<?= (int)$booking['booking_id'] ?>"><input type="hidden" name="action" value="confirm">
                                <button class="btn btn-primary btn-sm" type="submit" <?= !$canConfirm ? 'disabled title="Online payments must be paid; offline payments must be pending."' : '' ?>>Confirm</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($booking['status'] !== 'CANCELLED' && $booking['payment_status'] !== 'PAID'): ?>
                            <form method="post" class="d-inline" data-confirm="Cancel this reservation and mark its unpaid payment cancelled?">
                                <?= csrf_field() ?><input type="hidden" name="booking_id" value="<?= (int)$booking['booking_id'] ?>"><input type="hidden" name="action" value="cancel">
                                <button class="btn btn-outline-danger btn-sm" type="submit">Cancel</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$reservations): ?><tr><td colspan="8" class="text-center text-muted py-4">No reservations yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
