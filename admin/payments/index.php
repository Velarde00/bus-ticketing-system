<?php
require_once __DIR__ . '/../../includes/auth.php';
require_role('ADMIN');

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawPaymentId = $_POST['payment_id'] ?? '';
    $paymentId = filter_var(is_scalar($rawPaymentId) ? $rawPaymentId : '', FILTER_VALIDATE_INT);
    $status = is_string($_POST['payment_status'] ?? null) ? $_POST['payment_status'] : '';
    $method = is_string($_POST['payment_method'] ?? null) ? $_POST['payment_method'] : '';
    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$paymentId || !in_array($status, ['PENDING', 'PAID', 'FAILED', 'REFUNDED'], true) || !in_array($method, ['CASH', 'E_WALLET', 'CARD'], true)) {
        $errors[] = 'Choose a valid payment status and method.';
    } else {
        if ($status === 'REFUNDED') {
            $stmt = $pdo->prepare(
                "SELECT bk.status FROM payments p JOIN bookings bk ON bk.booking_id = p.booking_id
                 WHERE p.payment_id = :id"
            );
            $stmt->execute([':id' => $paymentId]);
            if ($stmt->fetchColumn() !== 'CANCELLED') {
                $errors[] = 'Cancel the reservation before recording a refund.';
            }
        }
        if (!$errors) {
            $stmt = $pdo->prepare(
                "UPDATE payments SET payment_status = :status, payment_method = :method, payment_date = NOW()
                 WHERE payment_id = :id"
            );
            $stmt->execute([':status' => $status, ':method' => $method, ':id' => $paymentId]);
            set_flash('success', 'Payment record updated.');
            redirect('admin/payments/index.php');
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
<div class="mb-4"><h1 class="h3 mb-0">Manage payments</h1><p class="text-muted mb-0">Record payment outcomes. Mark a pending payment as paid before confirming its reservation.</p></div>
<?php if ($errors): ?><div class="alert alert-danger"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>
<div class="panel"><div class="table-responsive">
    <table class="table align-middle mb-0">
        <thead><tr><th>Reference</th><th>Passenger</th><th>Amount</th><th>Method</th><th>Status</th><th>Date</th><th>Update</th></tr></thead>
        <tbody>
        <?php foreach ($payments as $payment): ?>
            <tr>
                <td><a href="<?= url('admin/reservations/view.php?id=' . (int)$payment['booking_id']) ?>"><?= e($payment['booking_reference']) ?></a></td>
                <td><?= e($payment['passenger']) ?></td><td><?= money($payment['amount']) ?></td>
                <td><?= e(str_replace('_', '-', $payment['payment_method'])) ?></td><td><?= status_badge($payment['payment_status']) ?></td>
                <td><?= e(fmt_datetime($payment['payment_date'])) ?></td>
                <td>
                    <form method="post" class="d-flex flex-wrap gap-2">
                        <?= csrf_field() ?><input type="hidden" name="payment_id" value="<?= (int)$payment['payment_id'] ?>">
                        <select name="payment_status" class="form-select form-select-sm" aria-label="Payment status">
                            <?php foreach (['PENDING', 'PAID', 'FAILED', 'REFUNDED'] as $status): ?><option value="<?= e($status) ?>" <?= $payment['payment_status'] === $status ? 'selected' : '' ?>><?= e(ucfirst(strtolower($status))) ?></option><?php endforeach; ?>
                        </select>
                        <select name="payment_method" class="form-select form-select-sm" aria-label="Payment method">
                            <?php foreach (['CASH', 'E_WALLET', 'CARD'] as $method): ?><option value="<?= e($method) ?>" <?= $payment['payment_method'] === $method ? 'selected' : '' ?>><?= e(str_replace('_', '-', $method)) ?></option><?php endforeach; ?>
                        </select>
                        <button class="btn btn-outline-dark btn-sm" type="submit">Save</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (!$payments): ?><tr><td colspan="7" class="text-center text-muted py-4">No payment records yet. Payment rows are created when passengers book seats.</td></tr><?php endif; ?>
        </tbody>
    </table>
</div></div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
