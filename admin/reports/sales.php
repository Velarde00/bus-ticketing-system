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
<div class="mb-4"><h1 class="h3 mb-0">Sales reports</h1><p class="text-muted mb-0">Paid sales are calculated from payment records in the selected date range.</p></div>
<form method="get" class="panel p-3 mb-4 d-flex flex-wrap align-items-end gap-3">
    <div><label class="form-label" for="from">From</label><input class="form-control" type="date" id="from" name="from" value="<?= e($from) ?>" required></div>
    <div><label class="form-label" for="to">To</label><input class="form-control" type="date" id="to" name="to" value="<?= e($to) ?>" required></div>
    <button class="btn btn-primary" type="submit">Run report</button>
</form>
<?php if ($errors): ?><div class="alert alert-danger"><?= e(implode(' ', $errors)) ?></div><?php else: ?>
    <div class="row row-cols-2 row-cols-xl-4 g-3 mb-4">
        <div class="col"><div class="stat-card stat-sales"><span class="stat-label">Paid sales</span><span class="stat-value"><?= money($totals['sales']) ?></span></div></div>
        <div class="col"><div class="stat-card"><span class="stat-label">Transactions</span><span class="stat-value"><?= (int)$totals['transactions'] ?></span></div></div>
        <div class="col"><div class="stat-card stat-confirmed"><span class="stat-label">Paid</span><span class="stat-value"><?= (int)$totals['paid'] ?></span></div></div>
        <div class="col"><div class="stat-card stat-pending"><span class="stat-label">Pending / refunded</span><span class="stat-value"><?= (int)$totals['pending'] ?> / <?= (int)$totals['refunded'] ?></span></div></div>
    </div>
    <div class="row g-4">
        <div class="col-lg-7"><div class="panel"><div class="panel-head"><h2 class="h5 mb-0">Sales by day</h2></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Date</th><th>Transactions</th><th>Paid sales</th></tr></thead><tbody>
            <?php foreach ($byDay as $row): ?><tr><td><?= e(fmt_date($row['day'])) ?></td><td><?= (int)$row['transactions'] ?></td><td><?= money($row['sales']) ?></td></tr><?php endforeach; ?>
            <?php if (!$byDay): ?><tr><td colspan="3" class="text-center text-muted py-4">No payments in this period.</td></tr><?php endif; ?>
        </tbody></table></div></div></div>
        <div class="col-lg-5"><div class="panel"><div class="panel-head"><h2 class="h5 mb-0">Sales by method</h2></div><div class="table-responsive"><table class="table align-middle mb-0"><thead><tr><th>Method</th><th>Transactions</th><th>Paid sales</th></tr></thead><tbody>
            <?php foreach ($byMethod as $row): ?><tr><td><?= e(str_replace('_', '-', $row['payment_method'])) ?></td><td><?= (int)$row['transactions'] ?></td><td><?= money($row['sales']) ?></td></tr><?php endforeach; ?>
            <?php if (!$byMethod): ?><tr><td colspan="3" class="text-center text-muted py-4">No payments in this period.</td></tr><?php endif; ?>
        </tbody></table></div></div></div>
    </div>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
