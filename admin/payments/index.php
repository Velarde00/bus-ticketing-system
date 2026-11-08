<?php
require_once __DIR__ . '/../../includes/auth.php';
require_role('ADMIN');

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawPaymentId = $_POST['payment_id'] ?? '';
    $paymentId = filter_var(is_scalar($rawPaymentId) ? $rawPaymentId : '', FILTER_VALIDATE_INT);
    $status = is_string($_POST['payment_status'] ?? null) ? $_POST['payment_status'] : '';
    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$paymentId || $status !== 'PAID') {
        $errors[] = 'Choose a valid payment action.';
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                "SELECT p.payment_method, p.payment_status, p.transfer_reference,
                    bk.booking_id, bk.status AS booking_status,
                    bk.booking_date, t.status AS trip_status, t.departure_time
                 FROM payments p JOIN bookings bk ON bk.booking_id = p.booking_id
                 JOIN trips t ON t.trip_id = bk.trip_id
                 WHERE p.payment_id = :id FOR UPDATE"
            );
            $stmt->execute([':id' => $paymentId]);
            $paymentRecord = $stmt->fetch();
            if (!$paymentRecord) {
                $errors[] = 'Payment record not found.';
            } elseif ($status === 'PAID'
                && ($paymentRecord['payment_method'] !== 'MANUAL_TRANSFER'
                    || $paymentRecord['payment_status'] !== 'PENDING'
                    || $paymentRecord['booking_status'] !== 'PENDING'
                    || !$paymentRecord['transfer_reference']
                    || $paymentRecord['trip_status'] === 'CANCELLED')) {
                $errors[] = 'A valid transfer reference is required before verifying this payment.';
            }
            if ($errors) {
                $pdo->rollBack();
            } else {
                $stmt = $pdo->prepare('UPDATE payments SET payment_status = :status, payment_date = NOW() WHERE payment_id = :id');
                $stmt->execute([':status' => $status, ':id' => $paymentId]);
                if ($status === 'PAID') {
                    $pdo->prepare("UPDATE bookings SET status = 'CONFIRMED' WHERE booking_id = :id")
                        ->execute([':id' => $paymentRecord['booking_id']]);
                    $seats = $pdo->prepare(
                        "SELECT bs.booking_seat_id FROM booking_seats bs
                         LEFT JOIN tickets tk ON tk.booking_seat_id = bs.booking_seat_id
                         WHERE bs.booking_id = :id AND bs.seat_status = 'BOOKED' AND tk.ticket_id IS NULL"
                    );
                    $seats->execute([':id' => $paymentRecord['booking_id']]);
                    $ticketInsert = $pdo->prepare('INSERT INTO tickets (booking_seat_id, ticket_number) VALUES (:seat, :number)');
                    $ticketUpdate = $pdo->prepare('UPDATE tickets SET ticket_number = :number WHERE ticket_id = :id');
                    foreach ($seats->fetchAll(PDO::FETCH_COLUMN) as $seatId) {
                        $ticketInsert->execute([':seat' => $seatId, ':number' => 'TMP-' . bin2hex(random_bytes(8))]);
                        $ticketId = (int)$pdo->lastInsertId();
                        $ticketUpdate->execute([':number' => sprintf('TKT-%s-%06d', date('Y'), $ticketId), ':id' => $ticketId]);
                    }
                }
                $pdo->commit();
                set_flash('success', 'Transfer verified, booking confirmed, and tickets issued.');
                redirect('admin/payments/index.php');
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('admin payment update: ' . $e->getMessage());
            $errors[] = 'Could not update this refund. Please try again.';
        }
    }
}

$payments = $pdo->query(
    "SELECT p.payment_id, p.amount, p.payment_method, p.online_payment_option, p.transfer_reference, p.transfer_submitted_at, p.payment_status, p.payment_date,
            bk.booking_id, bk.booking_reference, CONCAT(u.first_name, ' ', u.last_name) AS passenger
     FROM payments p JOIN bookings bk ON bk.booking_id = p.booking_id
     JOIN users u ON u.user_id = bk.user_id
     ORDER BY p.payment_date DESC, p.payment_id DESC"
)->fetchAll();

$pageTitle = 'Manage payments';
$layout = 'admin';
$activePage = 'payments';
require __DIR__ . '/../../includes/header.php';
?>
<div class="mb-4"><h1 class="h3 mb-0">Manage payments</h1><p class="text-muted mb-0">Review transfer references, verify receipts outside the system, and issue tickets after confirming payment.</p><a class="small" href="<?= url('admin/settings.php#payment-instructions') ?>">Manage receiving accounts</a></div>
<?php if ($errors): ?><div class="alert alert-danger"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
<div class="panel"><div class="table-responsive">
    <table class="table align-middle mb-0">
        <thead><tr><th>Reference</th><th>Passenger</th><th>Amount</th><th>Method</th><th>Transfer reference</th><th>Status</th><th>Date</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($payments as $payment): ?>
            <tr>
                <td><a href="<?= url('admin/reservations/view.php?id=' . (int)$payment['booking_id']) ?>"><?= e($payment['booking_reference']) ?></a></td>
                <td><?= e($payment['passenger']) ?></td><td><?= money($payment['amount']) ?></td>
                <td><?= e($payment['online_payment_option'] ? online_payment_option_label($payment['online_payment_option']) : payment_method_label($payment['payment_method'])) ?></td>
                <td><?= e($payment['transfer_reference'] ?: '—') ?></td>
                <td><?= status_badge($payment['payment_status']) ?></td>
                <td><?= e(fmt_datetime($payment['payment_date'])) ?></td>
                <td>
                    <?php if ($payment['payment_method'] === 'MANUAL_TRANSFER' && $payment['payment_status'] === 'PENDING' && $payment['transfer_reference']): ?>
                        <form method="post" data-confirm="Verify this transfer and issue tickets?">
                            <?= csrf_field() ?><input type="hidden" name="payment_id" value="<?= (int)$payment['payment_id'] ?>"><input type="hidden" name="payment_status" value="PAID">
                            <button class="btn btn-primary btn-sm" type="submit">Verify &amp; confirm</button>
                        </form>
                    <?php elseif ($payment['payment_status'] === 'REFUND_REQUESTED'): ?>
                        <a class="btn btn-outline-primary btn-sm" href="<?= url('admin/refunds/index.php') ?>">Manage refund</a>
                    <?php elseif ($payment['payment_method'] === 'MANUAL_TRANSFER' && $payment['payment_status'] === 'PENDING'): ?>
                        <span class="text-muted small">Awaiting transfer reference</span>
                    <?php else: ?>
                        <span class="text-muted small">Read only</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$payments): ?><tr><td colspan="8" class="text-center text-muted py-4">No payment records yet. Payment rows are created when passengers reserve seats.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
