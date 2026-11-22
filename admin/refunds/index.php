<?php
require_once __DIR__ . '/../../includes/auth.php';
require_role('ADMIN');

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $paymentId = filter_var($_POST['payment_id'] ?? null, FILTER_VALIDATE_INT);
    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$paymentId) {
        $errors[] = 'Choose a valid refund record to process.';
    } else {
        try {
            $pdo->beginTransaction();
            $result = process_automated_api_refund($pdo, $paymentId);
            $pdo->commit();
            set_flash('success', 'Refund of ' . money($result['amount']) . ' automatically disbursed via API. Gateway Ref: ' . $result['reference_no']);
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
            error_log('admin automated refund: ' . $e->getMessage());
            $errors[] = 'Could not process automated refund API transfer. Please try again.';
        }
    }
}

$refunds = $pdo->query(
    "SELECT p.payment_id, p.amount, p.payment_status, p.refund_payment_option,
            p.refund_account_name, p.refund_account_number, p.refund_transfer_reference,
            p.refund_processed_at, bk.booking_id, bk.booking_reference, bk.booking_date,
            CONCAT(u.first_name, ' ', u.last_name) AS passenger, u.phone_number,
            CONCAT(r.origin, ' to ', r.destination) AS route, t.departure_time
     FROM payments p JOIN bookings bk ON bk.booking_id = p.booking_id
     JOIN users u ON u.user_id = bk.user_id
     JOIN trips t ON t.trip_id = bk.trip_id
     JOIN routes r ON r.route_id = t.route_id
     WHERE p.payment_status IN ('REFUND_REQUESTED', 'REFUNDED')
     ORDER BY (p.payment_status = 'REFUND_REQUESTED') DESC, p.payment_date DESC"
)->fetchAll();

$pageTitle = 'Refunds';
$layout = 'admin';
$activePage = 'refunds';
require __DIR__ . '/../../includes/header.php';
?>
<div class="mb-4">
    <h1 class="h3 fw-bold mb-1 text-dark">Refunds</h1>
    <p class="text-muted small mb-0">Review passenger refund requests and disburse automated refunds directly to their registered e-wallet.</p>
</div>

<?php if ($errors): ?>
    <div class="alert alert-danger py-2 border-0 shadow-sm small mb-4">
        <ul class="mb-0 ps-3"><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<div class="panel bg-white rounded-4 border shadow-sm overflow-hidden mb-4">
    <div class="panel-head p-3 px-4 border-bottom bg-light bg-opacity-50 d-flex justify-content-between align-items-center">
        <h2 class="h6 fw-bold mb-0 text-uppercase letter-spacing-1 text-muted">Refund Transactions</h2>
        <span class="badge bg-light text-muted border"><?= count($refunds) ?> records</span>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 custom-modern-table">
            <thead>
                <tr>
                    <th class="ps-4">Booking / Trip</th>
                    <th>Passenger</th>
                    <th>Refund Amount</th>
                    <th>Refund Account</th>
                    <th>Status</th>
                    <th>Gateway Trace / Reference</th>
                    <th class="text-end pe-4">Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($refunds as $refund): ?>
                <tr>
                    <td class="ps-4">
                        <a class="fw-bold font-monospace text-decoration-none text-dark" href="<?= url('admin/reservations/view.php?id=' . (int)$refund['booking_id']) ?>"><?= e($refund['booking_reference']) ?></a>
                        <div class="small text-muted"><?= e($refund['route']) ?> &bull; <?= e(fmt_datetime($refund['departure_time'])) ?></div>
                    </td>
                    <td>
                        <div class="fw-semibold text-dark"><?= e($refund['passenger']) ?></div>
                        <div class="small text-muted"><?= e($refund['phone_number'] ?: '—') ?></div>
                    </td>
                    <td class="fw-bold text-dark fs-6"><?= money($refund['amount']) ?></td>
                    <td>
                        <strong><?= e(online_payment_option_label($refund['refund_payment_option'] ?: 'GCASH')) ?></strong>
                        <div class="small text-dark"><?= e($refund['refund_account_name'] ?: $refund['passenger']) ?></div>
                        <div class="small text-muted font-monospace"><?= e($refund['refund_account_number'] ?: $refund['phone_number'] ?: 'On File') ?></div>
                    </td>
                    <td>
                        <?= status_badge($refund['payment_status']) ?>
                        <?php if ($refund['refund_processed_at']): ?>
                            <div class="small text-muted mt-1" style="font-size:0.75rem;"><?= e(fmt_datetime($refund['refund_processed_at'])) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($refund['refund_transfer_reference'])): ?>
                            <span class="badge bg-light text-dark border font-monospace px-2 py-1">
                                <i class="bi bi-shield-check text-success me-1"></i><?= e($refund['refund_transfer_reference']) ?>
                            </span>
                        <?php else: ?>
                            <span class="text-muted small">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-end pe-4">
                        <?php if ($refund['payment_status'] === 'REFUND_REQUESTED'): ?>
                            <div class="dropdown d-inline-block">
                                <button class="btn btn-light btn-sm rounded-circle p-2 shadow-xs border" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Actions" style="width: 32px; height: 32px; display: inline-flex; align-items: center; justify-content: center;">
                                    <i class="bi bi-three-dots-vertical"></i>
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end shadow border-0 rounded-3 py-1">
                                    <li>
                                        <form method="post" data-confirm="Disburse automated instant refund of <?= money($refund['amount']) ?> via Payment API to <?= e($refund['passenger']) ?>?">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="payment_id" value="<?= (int)$refund['payment_id'] ?>">
                                            <button class="dropdown-item py-2 px-3 small text-primary d-flex align-items-center gap-2" type="submit">
                                                <i class="bi bi-lightning-charge-fill text-primary"></i> Disburse via API
                                            </button>
                                        </form>
                                    </li>
                                </ul>
                            </div>
                        <?php else: ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-1 rounded-pill small">
                                <i class="bi bi-check-all me-1"></i> Refund Disbursed
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$refunds): ?>
                <tr><td colspan="7" class="text-center text-muted py-5">No refund requests or disbursements on record.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>