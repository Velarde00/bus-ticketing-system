<?php
require_once __DIR__ . '/../../includes/auth.php';
require_role('ADMIN');

$from = $_GET['from'] ?? date('Y-m-01');
$to = $_GET['to'] ?? date('Y-m-d');
if (!is_string($from)) {
    $from = '';
}
if (!is_string($to)) {
    $to = '';
}
$isDate = static function ($value): bool {
    $date = DateTime::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value;
};
$errors = [];
if (!$isDate($from) || !$isDate($to)) {
    $errors[] = 'Choose valid start and end dates.';
} elseif ($from > $to) {
    $errors[] = 'The start date must be on or before the end date.';
}

$totals = ['transactions' => 0, 'paid' => 0, 'pending' => 0, 'refunded' => 0];
$byDay = [];
$byMethod = [];
if (!$errors) {
    $start = $from . ' 00:00:00';
    $end = date('Y-m-d H:i:s', strtotime($to . ' +1 day'));
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS transactions,
                COALESCE(SUM(payment_status = 'PAID'), 0) AS paid,
                COALESCE(SUM(payment_status = 'PENDING'), 0) AS pending,
                COALESCE(SUM(payment_status = 'REFUNDED'), 0) AS refunded,
                COALESCE(SUM(CASE WHEN payment_status = 'PAID' THEN amount ELSE 0 END), 0) AS sales
         FROM payments WHERE payment_date >= :start AND payment_date < :end"
    );
    $stmt->execute([':start' => $start, ':end' => $end]);
    $totals = $stmt->fetch();
    $stmt = $pdo->prepare(
        "SELECT DATE(payment_date) AS day, COUNT(*) AS transactions,
                COALESCE(SUM(CASE WHEN payment_status = 'PAID' THEN amount ELSE 0 END), 0) AS sales
         FROM payments WHERE payment_date >= :start AND payment_date < :end
         GROUP BY DATE(payment_date) ORDER BY day DESC"
    );
    $stmt->execute([':start' => $start, ':end' => $end]);
    $byDay = $stmt->fetchAll();
    $stmt = $pdo->prepare(
        "SELECT payment_method, COUNT(*) AS transactions,
                COALESCE(SUM(CASE WHEN payment_status = 'PAID' THEN amount ELSE 0 END), 0) AS sales
         FROM payments WHERE payment_date >= :start AND payment_date < :end
         GROUP BY payment_method ORDER BY sales DESC"
    );
    $stmt->execute([':start' => $start, ':end' => $end]);
    $byMethod = $stmt->fetchAll();
}

$pageTitle = 'Sales reports';
$layout = 'admin';
$activePage = 'reports';
require __DIR__ . '/../../includes/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="h3 fw-bold mb-1 text-dark">Financial &amp; Sales Reports</h1>
        <p class="text-muted small mb-0">Paid revenue metrics calculated from confirmed payment records in the selected date range.</p>
    </div>
</div>

<form method="get" class="panel bg-white p-3 px-4 rounded-4 border shadow-sm mb-4 d-flex flex-wrap align-items-end gap-3">
    <div>
        <label class="form-label small fw-semibold mb-1" for="from">Start Date</label>
        <input class="form-control form-control-sm" type="date" id="from" name="from" value="<?= e($from) ?>" required>
    </div>
    <div>
        <label class="form-label small fw-semibold mb-1" for="to">End Date</label>
        <input class="form-control form-control-sm" type="date" id="to" name="to" value="<?= e($to) ?>" required>
    </div>
    <button class="btn btn-primary btn-sm rounded-pill shadow-sm px-4" type="submit">
        <i class="bi bi-funnel me-1"></i> Filter Date Range
    </button>
</form>

<?php if ($errors): ?>
    <div class="alert alert-danger py-2 border-0 shadow-sm small mb-4"><?= e(implode(' ', $errors)) ?></div>
<?php else: ?>
    <div class="row row-cols-2 row-cols-xl-4 g-3 mb-4">
        <div class="col">
            <div class="stat-card-modern p-3 rounded-4 bg-dark text-white border-0 shadow-sm h-100 stat-sales-banner">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="stat-card-label text-white-50 small fw-semibold">Paid Sales</span>
                    <span class="stat-icon-wrapper bg-white bg-opacity-10 text-warning"><i class="bi bi-cash-stack"></i></span>
                </div>
                <div class="stat-card-value h3 fw-bold text-signal mb-0"><?= money($totals['sales']) ?></div>
                <div class="stat-card-sub text-white-50 mt-1 small">Total revenue generated</div>
            </div>
        </div>

        <div class="col">
            <div class="stat-card-modern p-3 rounded-4 bg-white border shadow-sm h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="stat-card-label text-muted small fw-semibold">Transactions</span>
                    <span class="stat-icon-wrapper bg-light text-dark"><i class="bi bi-receipt"></i></span>
                </div>
                <div class="stat-card-value h3 fw-bold text-dark mb-0"><?= (int)$totals['transactions'] ?></div>
                <div class="stat-card-sub text-muted mt-1 small">Total records</div>
            </div>
        </div>

        <div class="col">
            <div class="stat-card-modern p-3 rounded-4 bg-white border shadow-sm h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="stat-card-label text-muted small fw-semibold">Paid Count</span>
                    <span class="stat-icon-wrapper bg-success-subtle text-success"><i class="bi bi-check-circle-fill"></i></span>
                </div>
                <div class="stat-card-value h3 fw-bold text-success mb-0"><?= (int)$totals['paid'] ?></div>
                <div class="stat-card-sub text-muted mt-1 small">Successful payments</div>
            </div>
        </div>

        <div class="col">
            <div class="stat-card-modern p-3 rounded-4 bg-white border shadow-sm h-100">
                <div class="d-flex align-items-center justify-content-between mb-2">
                    <span class="stat-card-label text-muted small fw-semibold">Pending / Refunded</span>
                    <span class="stat-icon-wrapper bg-warning-subtle text-warning"><i class="bi bi-hourglass-split"></i></span>
                </div>
                <div class="stat-card-value h3 fw-bold text-warning mb-0"><?= (int)$totals['pending'] ?> <span class="fs-6 text-muted">/ <?= (int)$totals['refunded'] ?></span></div>
                <div class="stat-card-sub text-muted mt-1 small">Awaiting or returned</div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="panel bg-white rounded-4 border shadow-sm overflow-hidden">
                <div class="panel-head p-3 px-4 border-bottom bg-light bg-opacity-50">
                    <h2 class="h6 fw-bold mb-0 text-uppercase letter-spacing-1 text-muted">Daily Sales Summary</h2>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 custom-modern-table">
                        <thead><tr><th class="ps-4">Date</th><th>Transactions</th><th class="pe-4 text-end">Paid Sales</th></tr></thead>
                        <tbody>
                            <?php foreach ($byDay as $row): ?>
                                <tr>
                                    <td class="ps-4 fw-semibold text-dark"><?= e(fmt_date($row['day'])) ?></td>
                                    <td><span class="badge bg-light text-dark border px-2 py-1 rounded-pill"><?= (int)$row['transactions'] ?></span></td>
                                    <td class="pe-4 text-end fw-bold text-primary"><?= money($row['sales']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$byDay): ?><tr><td colspan="3" class="text-center text-muted py-5">No payments found in this date range.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="panel bg-white rounded-4 border shadow-sm overflow-hidden">
                <div class="panel-head p-3 px-4 border-bottom bg-light bg-opacity-50">
                    <h2 class="h6 fw-bold mb-0 text-uppercase letter-spacing-1 text-muted">Sales by Payment Channel</h2>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0 custom-modern-table">
                        <thead><tr><th class="ps-4">Channel</th><th>Transactions</th><th class="pe-4 text-end">Volume</th></tr></thead>
                        <tbody>
                            <?php foreach ($byMethod as $row): ?>
                                <tr>
                                    <td class="ps-4 fw-semibold text-dark"><i class="bi bi-wallet2 me-1 text-primary"></i> <?= e(str_replace('_', ' ', $row['payment_method'])) ?></td>
                                    <td><span class="badge bg-light text-dark border px-2 py-1 rounded-pill"><?= (int)$row['transactions'] ?></span></td>
                                    <td class="pe-4 text-end fw-bold text-primary"><?= money($row['sales']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$byMethod): ?><tr><td colspan="3" class="text-center text-muted py-5">No payment methods recorded.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
