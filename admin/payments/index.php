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
    } elseif (!$paymentId || !in_array($status, ['PENDING', 'FAILED'], true)) {
        $errors[] = 'Choose a valid payment status.';
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                "SELECT p.payment_method, p.payment_status, bk.status AS booking_status
                 FROM payments p JOIN bookings bk ON bk.booking_id = p.booking_id
                 WHERE p.payment_id = :id FOR UPDATE"
            );
            $stmt->execute([':id' => $paymentId]);
            $paymentRecord = $stmt->fetch();
            if (!$paymentRecord) {
                $errors[] = 'Payment record not found.';
            } elseif (in_array($paymentRecord['payment_status'], ['CANCELLED', 'REFUNDED'], true)
                || $paymentRecord['booking_status'] === 'CANCELLED') {
                $errors[] = 'Cancelled payments are managed by reservation cancellation and cannot be changed here.';
            } elseif ($paymentRecord['payment_method'] === 'ONLINE') {
                $errors[] = 'Online payment status is updated automatically from verified PayMongo events.';
            }
            if ($errors) {
                $pdo->rollBack();
            } else {
                $stmt = $pdo->prepare(
                    "UPDATE payments SET payment_status = :status, payment_date = NOW()
                     WHERE payment_id = :id"
                );
                $stmt->execute([':status' => $status, ':id' => $paymentId]);
                $pdo->commit();
                set_flash('success', 'Payment record updated.');
                redirect('admin/payments/index.php');
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('admin payment update: ' . $e->getMessage());
            $errors[] = 'Could not update this payment. Please try again.';
        }
    }
}

$payments = $pdo->query(
    "SELECT p.payment_id, p.amount, p.payment_method, p.payment_status, p.payment_date,
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
<div class="mb-4"><h1 class="h3 mb-0">Manage payments</h1><p class="text-muted mb-0">Online payment statuses update automatically after PayMongo confirmation. Cash, card, and e-wallet payments are due in person. Cancelling an unpaid reservation automatically cancels its payment.</p></div>
<?php if ($errors): ?><div class="alert alert-danger"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
<div class="panel"><div class="table-responsive">
    <table class="table align-middle mb-0">
        <thead><tr><th>Reference</th><th>Passenger</th><th>Amount</th><th>Method</th><th>Status</th><th>Date</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach ($payments as $payment): ?>
            <tr>
                <td><a href="<?= url('admin/reservations/view.php?id=' . (int)$payment['booking_id']) ?>"><?= e($payment['booking_reference']) ?></a></td>
                <td><?= e($payment['passenger']) ?></td><td><?= money($payment['amount']) ?></td>
                <td><?= e(payment_method_label($payment['payment_method'])) ?></td><td><?= status_badge($payment['payment_status']) ?></td>
                <td><?= e(fmt_datetime($payment['payment_date'])) ?></td>
                <td>
                    <form method="post" class="d-flex flex-wrap gap-2">
                        <?= csrf_field() ?><input type="hidden" name="payment_id" value="<?= (int)$payment['payment_id'] ?>">
                        <?php if ($payment['payment_method'] === 'ONLINE'): ?>
                            <span class="text-muted small align-self-center">PayMongo updates status automatically</span>
                        <?php elseif (in_array($payment['payment_status'], ['CANCELLED', 'REFUNDED'], true)
                            || $payment['payment_status'] === 'PAID'): ?>
                            <span class="text-muted small align-self-center">
                                <?= $payment['payment_status'] === 'REFUNDED' ? 'Legacy record (read only)' : ucfirst(strtolower($payment['payment_status'])) . ' (read only)' ?>
                            </span>
                        <?php else: ?>
                        <select name="payment_status" class="form-select form-select-sm" aria-label="Payment status">
                            <?php foreach (['PENDING', 'FAILED'] as $status): ?>
                                <option value="<?= e($status) ?>" <?= $payment['payment_status'] === $status ? 'selected' : '' ?>><?= e(ucfirst(strtolower($status))) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-outline-dark btn-sm" type="submit">Save</button>
                        <?php endif; ?>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$payments): ?><tr><td colspan="7" class="text-center text-muted py-4">No payment records yet. Payment rows are created when passengers book seats.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
