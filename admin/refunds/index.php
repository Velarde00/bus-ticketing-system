<?php
require_once __DIR__ . '/../../includes/auth.php';
require_role('ADMIN');

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $paymentId = filter_var($_POST['payment_id'] ?? null, FILTER_VALIDATE_INT);
    $transferReference = trim(is_string($_POST['refund_transfer_reference'] ?? null) ? $_POST['refund_transfer_reference'] : '');
    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$paymentId || !preg_match('/^[A-Za-z0-9][A-Za-z0-9 .#\/-]{3,99}$/', $transferReference)) {
        $errors[] = 'Enter a valid online transfer reference.';
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                "SELECT p.payment_status, p.refund_payment_option, p.refund_account_name,
                        p.refund_account_number, bk.status AS booking_status,
                        bk.booking_date, t.status AS trip_status, t.departure_time
                 FROM payments p JOIN bookings bk ON bk.booking_id = p.booking_id
                 JOIN trips t ON t.trip_id = bk.trip_id
                 WHERE p.payment_id = :id FOR UPDATE"
            );
            $stmt->execute([':id' => $paymentId]);
            $refund = $stmt->fetch();
            if (!$refund || $refund['payment_status'] !== 'REFUND_REQUESTED'
                || $refund['booking_status'] !== 'CANCELLED'
                || $refund['trip_status'] !== 'CANCELLED'
                || !$refund['refund_payment_option']
                || !$refund['refund_account_name']
                || !$refund['refund_account_number']
                || strtotime($refund['booking_date']) > strtotime($refund['departure_time']) - 2 * 24 * 3600) {
                throw new RuntimeException('This refund request is not ready to process. Check eligibility and the passenger destination.');
            }
            $pdo->prepare(
                "UPDATE payments SET payment_status = 'REFUNDED',
                    refund_transfer_reference = :reference, refund_processed_at = NOW(), payment_date = NOW()
                 WHERE payment_id = :id"
            )->execute([':reference' => $transferReference, ':id' => $paymentId]);
            $pdo->commit();
            set_flash('success', 'Online refund transfer recorded as sent.');
            redirect('admin/refunds/index.php');
        } catch (RuntimeException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $errors[] = $e->getMessage();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('admin refund processing: ' . $e->getMessage());
            $errors[] = 'Could not record this refund. Please try again.';
        }
    }
}

$refunds = $pdo->query(
    "SELECT p.payment_id, p.amount, p.payment_status, p.refund_payment_option,
            p.refund_account_name, p.refund_account_number, p.refund_transfer_reference,
            p.refund_processed_at, bk.booking_id, bk.booking_reference, bk.booking_date,
            CONCAT(u.first_name, ' ', u.last_name) AS passenger,
            CONCAT(r.origin, ' to ', r.destination) AS route, t.departure_time
     FROM payments p JOIN bookings bk ON bk.booking_id = p.booking_id
     JOIN users u ON u.user_id = bk.user_id
     JOIN trips t ON t.trip_id = bk.trip_id
     JOIN routes r ON r.route_id = t.route_id
     WHERE p.payment_status IN ('REFUND_REQUESTED', 'REFUNDED')
     ORDER BY (p.payment_status = 'REFUND_REQUESTED') DESC, p.payment_date DESC"
)->fetchAll();

$pageTitle = 'Manage refunds';
$layout = 'admin';
$activePage = 'refunds';
require __DIR__ . '/../../includes/header.php';
?>
<div class="mb-4">
    <h1 class="h3 mb-1">Manage refunds</h1>
    <p class="text-muted mb-0">Send eligible refunds to the passenger’s chosen account using your online banking or wallet, then record the transfer reference. This page tracks transfers but does not initiate them.</p>
</div>
<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<div class="panel">
    <div class="table-responsive">
        <table class="table align-middle mb-0">
            <thead><tr><th>Booking / trip</th><th>Passenger</th><th>Refund amount</th><th>Send to</th><th>Status</th><th>Transfer reference</th><th>Action</th></tr></thead>
            <tbody>
            <?php foreach ($refunds as $refund): ?>
                <tr>
                    <td>
                        <a class="fw-semibold" href="<?= url('admin/reservations/view.php?id=' . (int)$refund['booking_id']) ?>"><?= e($refund['booking_reference']) ?></a>
                        <div class="small text-muted"><?= e($refund['route']) ?> · <?= e(fmt_datetime($refund['departure_time'])) ?></div>
                    </td>
                    <td><?= e($refund['passenger']) ?></td>
                    <td><?= money($refund['amount']) ?></td>
                    <td>
                        <strong><?= e(online_payment_option_label($refund['refund_payment_option'])) ?></strong>
                        <div><?= e($refund['refund_account_name'] ?: 'Destination missing') ?></div>
                        <div class="small text-muted"><?= e($refund['refund_account_number'] ?: '—') ?></div>
                    </td>
                    <td>
                        <?= status_badge($refund['payment_status']) ?>
                        <?php if ($refund['refund_processed_at']): ?><div class="small text-muted"><?= e(fmt_datetime($refund['refund_processed_at'])) ?></div><?php endif; ?>
                    </td>
                    <td><?= e($refund['refund_transfer_reference'] ?: '—') ?></td>
                    <td>
                        <?php if ($refund['payment_status'] === 'REFUND_REQUESTED'
                            && $refund['refund_payment_option'] && $refund['refund_account_name'] && $refund['refund_account_number']): ?>
                            <form method="post" class="d-flex flex-column gap-2" data-confirm="Confirm the online refund was sent to this account?">
                                <?= csrf_field() ?><input type="hidden" name="payment_id" value="<?= (int)$refund['payment_id'] ?>">
                                <input class="form-control form-control-sm" name="refund_transfer_reference" maxlength="100" placeholder="Transfer reference" aria-label="Refund transfer reference" required>
                                <button class="btn btn-primary btn-sm" type="submit">Record refund sent</button>
                            </form>
                        <?php elseif ($refund['payment_status'] === 'REFUND_REQUESTED'): ?>
                            <span class="text-muted small">Waiting for passenger destination</span>
                        <?php else: ?>
                            <span class="text-muted small">Completed</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$refunds): ?><tr><td colspan="7" class="text-center text-muted py-4">No refund requests yet.</td></tr><?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>