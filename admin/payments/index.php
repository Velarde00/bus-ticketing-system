<?php
require_once __DIR__ . '/../../includes/auth.php';
require_role('ADMIN');

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawPaymentId = $_POST['payment_id'] ?? '';
    $paymentId = filter_var(is_scalar($rawPaymentId) ? $rawPaymentId : '', FILTER_VALIDATE_INT);
    $status = is_string($_POST['payment_status'] ?? null) ? trim($_POST['payment_status']) : '';
    $method = is_string($_POST['payment_method'] ?? null) ? trim($_POST['payment_method']) : '';

    // Allow all database valid statuses & methods
    $validStatuses = ['PENDING', 'PAID', 'FAILED', 'REFUND_REQUESTED', 'REFUNDED', 'CANCELLED'];
    $validMethods  = ['CASH', 'E_WALLET', 'CARD', 'ONLINE', 'MANUAL_TRANSFER'];

    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please try again.';
    } elseif (!$paymentId || !in_array($status, $validStatuses, true) || !in_array($method, $validMethods, true)) {
        $errors[] = 'Choose a valid payment status and method.';
    } else {
        // Fetch payment and passenger details
        $stmt = $pdo->prepare(
            "SELECT p.payment_id, p.amount, p.booking_id, p.payment_method, bk.booking_reference, bk.status AS booking_status,
                    u.user_id, CONCAT(u.first_name, ' ', u.last_name) AS passenger_name, u.phone_number
             FROM payments p
             JOIN bookings bk ON bk.booking_id = p.booking_id
             JOIN users u ON u.user_id = bk.user_id
             WHERE p.payment_id = :id"
        );
        $stmt->execute([':id' => $paymentId]);
        $curr = $stmt->fetch();

        if (!$curr) {
            $errors[] = 'Payment record not found.';
        } else {
            // Processing refund directly to e-wallet
            if ($status === 'REFUNDED') {
                $refNo = 'REF-EW-' . strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 8));
                
                $pdo->prepare(
                    "UPDATE payments
                     SET payment_status = 'REFUNDED',
                         refund_payment_option = 'GCASH',
                         refund_account_name = :acct_name,
                         refund_account_number = :acct_num,
                         refund_transfer_reference = :ref_no,
                         refund_processed_at = NOW(),
                         payment_date = NOW()
                     WHERE payment_id = :id"
                )->execute([
                    ':acct_name' => $curr['passenger_name'],
                    ':acct_num'  => $curr['phone_number'] ?: '09123456789',
                    ':ref_no'    => $refNo,
                    ':id'        => $paymentId,
                ]);

                // Notify passenger about their e-wallet refund
                $notif = $pdo->prepare(
                    "INSERT INTO notifications (user_id, title, message, link) VALUES (:uid, :title, :msg, :link)"
                );
                $notif->execute([
                    ':uid'   => $curr['user_id'],
                    ':title' => "Refund Sent to Your E-Wallet ({$refNo})",
                    ':msg'   => "A full refund of " . money($curr['amount']) . " for booking {$curr['booking_reference']} has been sent to your registered E-Wallet ({$curr['phone_number']}). Ref: {$refNo}.",
                    ':link'  => "passenger/booking_confirmation.php?booking_id=" . (int)$curr['booking_id'],
                ]);

                set_flash('success', "Refund of " . money($curr['amount']) . " processed and sent directly to {$curr['passenger_name']}'s E-Wallet ({$curr['phone_number']}). Ref: {$refNo}.");
                redirect('admin/payments/index.php');
            } else {
                // Normal update
                $stmt = $pdo->prepare(
                    "UPDATE payments SET payment_status = :status, payment_method = :method, payment_date = NOW()
                     WHERE payment_id = :id"
                );
                $stmt->execute([':status' => $status, ':method' => $method, ':id' => $paymentId]);

                // Auto-confirm if marked paid
                if ($status === 'PAID' && $curr['booking_status'] === 'PENDING') {
                    $bId = (int)$curr['booking_id'];
                    $pdo->prepare("UPDATE bookings SET status = 'CONFIRMED' WHERE booking_id = :id")->execute([':id' => $bId]);
                    
                    $seats = $pdo->prepare(
                        "SELECT bs.booking_seat_id FROM booking_seats bs
                         LEFT JOIN tickets tk ON tk.booking_seat_id = bs.booking_seat_id
                         WHERE bs.booking_id = :id AND bs.seat_status = 'BOOKED' AND tk.ticket_id IS NULL"
                    );
                    $seats->execute([':id' => $bId]);
                    $ticketInsert = $pdo->prepare('INSERT INTO tickets (booking_seat_id, ticket_number) VALUES (:seat, :number)');
                    $ticketUpdate = $pdo->prepare('UPDATE tickets SET ticket_number = :number WHERE ticket_id = :id');
                    foreach ($seats->fetchAll(PDO::FETCH_COLUMN) as $seatId) {
                        $ticketInsert->execute([':seat' => $seatId, ':number' => 'TMP-' . bin2hex(random_bytes(8))]);
                        $ticketId = (int)$pdo->lastInsertId();
                        $ticketUpdate->execute([':number' => sprintf('TKT-%s-%06d', date('Y'), $ticketId), ':id' => $ticketId]);
                    }
                }

                set_flash('success', 'Payment record updated successfully.');
                redirect('admin/payments/index.php');
            }
        }
    }
}

// --- Filter parameters ---
$search = trim($_GET['search'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$filterMethod = trim($_GET['method'] ?? '');
$filterChannel = trim($_GET['channel'] ?? '');
$filterDate = trim($_GET['date'] ?? '');

$where = [];
$params = [];

if ($search !== '') {
    $where[] = "(bk.booking_reference LIKE :search OR u.first_name LIKE :search OR u.last_name LIKE :search OR u.phone_number LIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if ($filterStatus !== '' && $filterStatus !== 'ALL') {
    $where[] = "p.payment_status = :status";
    $params[':status'] = $filterStatus;
}

if ($filterMethod !== '' && $filterMethod !== 'ALL') {
    $where[] = "p.payment_method = :method";
    $params[':method'] = $filterMethod;
}

if ($filterChannel === 'ONLINE') {
    $where[] = "bk.booking_reference LIKE 'BT-%'";
} elseif ($filterChannel === 'WALK_IN') {
    $where[] = "bk.booking_reference LIKE 'WI-%'";
}

if ($filterDate !== '') {
    $where[] = "DATE(p.payment_date) = :pdate";
    $params[':pdate'] = $filterDate;
}

$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$stmt = $pdo->prepare(
    "SELECT p.payment_id, p.amount, p.payment_method, p.payment_status, p.payment_date,
            p.refund_transfer_reference, p.refund_processed_at,
            bk.booking_id, bk.booking_reference, bk.status AS booking_status, bk.cancelled_by, bk.cancellation_choice,
            CONCAT(u.first_name, ' ', u.last_name) AS passenger, u.phone_number
     FROM payments p JOIN bookings bk ON bk.booking_id = p.booking_id
     JOIN users u ON u.user_id = bk.user_id
     {$whereSql}
     ORDER BY p.payment_date DESC, p.payment_id DESC"
);
$stmt->execute($params);
$payments = $stmt->fetchAll();

$pageTitle = 'Manage payments';
$layout = 'admin';
$activePage = 'payments';
require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="h3 fw-bold mb-1 text-dark">Manage Payments</h1>
        <p class="text-muted small mb-0">Payments are automatically recorded as Paid and Confirmed upon booking. Refunds can be disbursed directly to the passenger's registered E-Wallet here.</p>
    </div>
    <div class="d-flex gap-2">
        <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2 rounded-pill">
            <i class="bi bi-lightning-charge-fill me-1"></i> Auto-Sync Active
        </span>
        <span class="badge bg-light text-muted border px-3 py-2 rounded-pill"><?= count($payments) ?> payment records</span>
    </div>
</div>

<?php if ($errors): ?><div class="alert alert-danger py-2 border-0 shadow-sm small mb-4"><?= e(implode(' ', $errors)) ?></div><?php endif; ?>

<!-- Filter & Search Controls -->
<div class="card border-0 shadow-sm rounded-4 mb-4">
    <div class="card-body p-3">
        <form method="get" action="<?= url('admin/payments/index.php') ?>" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small fw-semibold text-muted mb-1"><i class="bi bi-search me-1"></i>Search</label>
                <input type="text" name="search" class="form-control form-control-sm rounded-pill" placeholder="Ref, Passenger, Phone..." value="<?= e($search) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold text-muted mb-1">Status</label>
                <select name="status" class="form-select form-select-sm rounded-pill">
                    <option value="ALL">All Statuses</option>
                    <option value="PAID" <?= $filterStatus === 'PAID' ? 'selected' : '' ?>>Paid</option>
                    <option value="REFUND_REQUESTED" <?= $filterStatus === 'REFUND_REQUESTED' ? 'selected' : '' ?>>Refund Requested</option>
                    <option value="REFUNDED" <?= $filterStatus === 'REFUNDED' ? 'selected' : '' ?>>Refunded</option>
                    <option value="PENDING" <?= $filterStatus === 'PENDING' ? 'selected' : '' ?>>Pending</option>
                    <option value="FAILED" <?= $filterStatus === 'FAILED' ? 'selected' : '' ?>>Failed</option>
                    <option value="CANCELLED" <?= $filterStatus === 'CANCELLED' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small fw-semibold text-muted mb-1">Method</label>
                <select name="method" class="form-select form-select-sm rounded-pill">
                    <option value="ALL">All Methods</option>
                    <option value="E_WALLET" <?= $filterMethod === 'E_WALLET' ? 'selected' : '' ?>>E-Wallet</option>
                    <option value="CASH" <?= $filterMethod === 'CASH' ? 'selected' : '' ?>>Cash</option>
                    <option value="CARD" <?= $filterMethod === 'CARD' ? 'selected' : '' ?>>Card</option>
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
                <?php if ($search !== '' || ($filterStatus !== '' && $filterStatus !== 'ALL') || ($filterMethod !== '' && $filterMethod !== 'ALL') || ($filterChannel !== '' && $filterChannel !== 'ALL') || $filterDate !== ''): ?>
                    <a href="<?= url('admin/payments/index.php') ?>" class="btn btn-sm btn-outline-secondary rounded-pill" title="Reset Filters">
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
                    <th>Booking Channel</th>
                    <th>Passenger &amp; E-Wallet</th>
                    <th>Amount</th>
                    <th>Payment Method</th>
                    <th>Payment Status</th>
                    <th>Date</th>
                    <th class="pe-4 text-end">Action / E-Wallet Refund</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($payments as $payment): ?>
                <?php
                    $isWalkIn = str_starts_with($payment['booking_reference'], 'WI-');
                    $isCancelled = $payment['booking_status'] === 'CANCELLED';
                    $eligibleForRefund = $isCancelled && in_array($payment['payment_status'], ['PAID', 'REFUND_REQUESTED'], true);
                ?>
                <tr>
                    <td class="ps-4 font-monospace fw-bold">
                        <a href="<?= url('admin/reservations/view.php?id=' . (int)$payment['booking_id']) ?>" class="text-decoration-none">
                            <?= e($payment['booking_reference']) ?>
                        </a>
                    </td>
                    <td>
                        <?php if ($isWalkIn): ?>
                            <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2 py-1 rounded-pill small">
                                <i class="bi bi-person-walking me-1"></i> Walk-in
                            </span>
                        <?php else: ?>
                            <span class="badge bg-info-subtle text-info border border-info-subtle px-2 py-1 rounded-pill small">
                                <i class="bi bi-globe me-1"></i> Online Booker
                            </span>
                        <?php endif; ?>
                        <?php if ($isCancelled): ?>
                            <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1 rounded-pill small ms-1">
                                Cancelled <?= $payment['cancelled_by'] === 'ADMIN' ? '(by Admin/Trip)' : '(by User)' ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="fw-semibold text-dark"><?= e($payment['passenger']) ?></div>
                        <div class="small text-muted" style="font-size: 0.75rem;">
                            <i class="bi bi-phone me-1 text-primary"></i><?= e($payment['phone_number'] ?: 'No phone recorded') ?>
                        </div>
                    </td>
                    <td class="fw-bold text-dark fs-6"><?= money($payment['amount']) ?></td>
                    <td>
                        <span class="badge bg-light text-dark border px-2 py-1 rounded-pill">
                            <?php if ($payment['payment_method'] === 'CASH'): ?>
                                <i class="bi bi-cash-stack text-success me-1"></i> Cash
                            <?php elseif ($payment['payment_method'] === 'CARD'): ?>
                                <i class="bi bi-credit-card text-warning me-1"></i> Card
                            <?php else: ?>
                                <i class="bi bi-phone text-primary me-1"></i> E-Wallet
                            <?php endif; ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($payment['payment_status'] === 'REFUNDED'): ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 rounded-pill">
                                <i class="bi bi-arrow-counterclockwise me-1"></i> Refunded to E-Wallet
                            </span>
                            <?php if (!empty($payment['refund_transfer_reference'])): ?>
                                <div class="font-monospace text-muted mt-1" style="font-size: 0.68rem;">
                                    Ref: <?= e($payment['refund_transfer_reference']) ?>
                                </div>
                            <?php endif; ?>
                        <?php elseif ($payment['cancellation_choice'] === 'REFUND_REQUESTED' || $payment['payment_status'] === 'REFUND_REQUESTED'): ?>
                            <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2 py-1 rounded-pill">
                                <i class="bi bi-exclamation-circle me-1"></i> Refund Requested
                            </span>
                        <?php else: ?>
                            <?= status_badge($payment['payment_status']) ?>
                        <?php endif; ?>
                    </td>
                    <td class="small text-muted"><?= e(fmt_datetime($payment['payment_date'])) ?></td>
                    <td class="pe-4 text-end">
                        <span class="badge bg-success-subtle text-success border border-success-subtle px-3 py-2 rounded-pill small me-1">
                            <i class="bi bi-shield-check me-1"></i> Auto-Managed
                        </span>
                        <?php if ($eligibleForRefund && $payment['payment_status'] !== 'REFUNDED'): ?>
                            <form method="post" class="d-inline" data-confirm="Disburse full refund of <?= money($payment['amount']) ?> directly to <?= e($payment['passenger']) ?>'s E-Wallet (<?= e($payment['phone_number']) ?>)?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="payment_id" value="<?= (int)$payment['payment_id'] ?>">
                                <input type="hidden" name="payment_status" value="REFUNDED">
                                <input type="hidden" name="payment_method" value="E_WALLET">
                                <button type="submit" class="btn btn-sm btn-danger rounded-pill px-3 shadow-sm" title="Send refund to passenger's E-Wallet">
                                    <i class="bi bi-phone me-1"></i> Send to E-Wallet
                                </button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$payments): ?>
                <tr><td colspan="8" class="text-center text-muted py-5">No payment records yet. Payment rows are created when passengers book seats.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
