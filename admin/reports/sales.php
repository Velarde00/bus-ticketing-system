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

$totals = ['transactions' => 0, 'paid' => 0, 'pending' => 0, 'cancelled' => 0];
$byDay = [];
$byMethod = [];
$busStats = [];
if (!$errors) {
    $start = $from . ' 00:00:00';
    $end = date('Y-m-d H:i:s', strtotime($to . ' +1 day'));
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) AS transactions,
                COALESCE(SUM(payment_status = 'PAID'), 0) AS paid,
                COALESCE(SUM(payment_status = 'PENDING'), 0) AS pending,
                COALESCE(SUM(payment_status = 'CANCELLED'), 0) AS cancelled,
                COALESCE(SUM(CASE WHEN payment_status = 'PAID' THEN amount ELSE 0 END), 0) AS sales
         FROM payments WHERE payment_date >= :start AND payment_date < :end"
    );
    $stmt->execute([':start' => $start, ':end' => $end]);
    $totals = $stmt->fetch();
    $stmt = $pdo->prepare(
        "SELECT DATE(payment_date) AS day, COUNT(*) AS transactions,
                COALESCE(SUM(CASE WHEN payment_status = 'PAID' THEN amount ELSE 0 END), 0) AS sales
         FROM payments WHERE payment_date >= :start AND payment_date < :end
         GROUP BY DATE(payment_date) ORDER BY day ASC"
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

    $stmt = $pdo->prepare(
        "SELECT b.bus_id, b.bus_number, b.bus_type, b.total_seats,
                COUNT(DISTINCT t.trip_id) AS trips,
                COUNT(DISTINCT bs.booking_seat_id) AS booked_seats
         FROM buses b
         LEFT JOIN trips t
           ON t.bus_id = b.bus_id
          AND t.status <> 'CANCELLED'
          AND t.departure_time >= :start
          AND t.departure_time < :end
         LEFT JOIN bookings bk
           ON bk.trip_id = t.trip_id
          AND bk.status <> 'CANCELLED'
         LEFT JOIN booking_seats bs
           ON bs.booking_id = bk.booking_id
          AND bs.trip_id = t.trip_id
          AND bs.seat_status = 'BOOKED'
         GROUP BY b.bus_id, b.bus_number, b.bus_type, b.total_seats, b.status
         ORDER BY trips DESC, booked_seats DESC, b.bus_number"
    );
    $stmt->execute([':start' => $start, ':end' => $end]);
    $busStats = $stmt->fetchAll();
}
$maxMethodSales = max(array_map(static fn ($row) => (float)$row['sales'], $byMethod) ?: [0]);

$pageTitle = 'Sales reports';
$layout = 'admin';
$activePage = 'reports';
require __DIR__ . '/../../includes/header.php';
?>
<div class="mb-3"><h1 class="h3 mb-0">Sales reports</h1><p class="text-muted mb-0">Review paid sales, payment methods, and bus utilization for the selected date range.</p></div>
<form method="get" class="panel p-3 mb-3 d-flex flex-wrap align-items-end gap-3">
    <div><label class="form-label" for="from">From</label><input class="form-control" type="date" id="from" name="from" value="<?= e($from) ?>" required></div>
    <div><label class="form-label" for="to">To</label><input class="form-control" type="date" id="to" name="to" value="<?= e($to) ?>" required></div>
    <button class="btn btn-primary" type="submit">Run report</button>
</form>
<?php if ($errors): ?><div class="alert alert-danger"><?= e(implode(' ', $errors)) ?></div><?php else: ?>
    <div class="row row-cols-2 row-cols-xl-4 g-3 mb-3">
        <div class="col"><div class="stat-card stat-sales"><span class="stat-label">Paid sales</span><span class="stat-value"><?= money($totals['sales']) ?></span></div></div>
        <div class="col"><div class="stat-card"><span class="stat-label">Transactions</span><span class="stat-value"><?= (int)$totals['transactions'] ?></span></div></div>
        <div class="col"><div class="stat-card stat-confirmed"><span class="stat-label">Paid</span><span class="stat-value"><?= (int)$totals['paid'] ?></span></div></div>
        <div class="col"><div class="stat-card stat-pending"><span class="stat-label">Pending / cancelled</span><span class="stat-value"><?= (int)$totals['pending'] ?> / <?= (int)$totals['cancelled'] ?></span></div></div>
    </div>
    <div class="row g-3">
        <div class="col-lg-8">
            <section class="panel p-3 h-100" aria-labelledby="sales-by-day-title">
                <h2 class="h5 mb-3" id="sales-by-day-title">Sales by day</h2>
                <?php if (!$byDay): ?>
                    <p class="text-muted mb-0">No payments in this period.</p>
                <?php else: ?>
                    <?php
                    $chartWidth = 800;
                    $chartHeight = 280;
                    $chartLeft = 72;
                    $chartRight = 785;
                    $chartTop = 18;
                    $chartBottom = 224;
                    $salesMax = max(array_map(static fn ($row) => (float)$row['sales'], $byDay) ?: [0]);
                    $salesScale = $salesMax > 0 ? $salesMax : 1;
                    $lastDayIndex = count($byDay) - 1;
                    $salesPoints = [];
                    foreach ($byDay as $index => $row) {
                        $x = $lastDayIndex > 0
                            ? $chartLeft + ($chartRight - $chartLeft) * $index / $lastDayIndex
                            : ($chartLeft + $chartRight) / 2;
                        $y = $chartBottom - ((float)$row['sales'] / $salesScale) * ($chartBottom - $chartTop);
                        $salesPoints[] = [
                            'x' => $x,
                            'y' => $y,
                            'row' => $row,
                        ];
                    }
                    ?>
                    <div class="report-line-chart">
                        <svg viewBox="0 0 <?= $chartWidth ?> <?= $chartHeight ?>" role="img" aria-labelledby="sales-chart-title sales-chart-description">
                            <title id="sales-chart-title">Paid sales by day line graph</title>
                            <desc id="sales-chart-description">Daily paid sales from <?= e(fmt_date($from)) ?> to <?= e(fmt_date($to)) ?>.</desc>
                            <?php for ($tick = 0; $tick <= 4; $tick++):
                                $tickY = $chartTop + ($chartBottom - $chartTop) * $tick / 4;
                                $tickValue = $salesScale * (4 - $tick) / 4;
                            ?>
                                <line x1="<?= $chartLeft ?>" y1="<?= $tickY ?>" x2="<?= $chartRight ?>" y2="<?= $tickY ?>" class="report-chart-grid" />
                                <text x="<?= $chartLeft - 10 ?>" y="<?= $tickY + 4 ?>" text-anchor="end" class="report-chart-axis-label"><?= e(money($tickValue)) ?></text>
                            <?php endfor; ?>
                            <?php if (count($salesPoints) > 1): ?>
                                <polyline class="report-line" points="<?php foreach ($salesPoints as $point): ?><?= $point['x'] ?>,<?= $point['y'] ?> <?php endforeach; ?>" />
                            <?php endif; ?>
                            <?php foreach ($salesPoints as $point): ?>
                                <circle cx="<?= $point['x'] ?>" cy="<?= $point['y'] ?>" r="4" class="report-line-point">
                                    <title><?= e(fmt_date($point['row']['day'])) ?>: <?= e(money($point['row']['sales'])) ?>; <?= (int)$point['row']['transactions'] ?> transactions</title>
                                </circle>
                            <?php endforeach; ?>
                            <?php if ($salesPoints): ?>
                                <text x="<?= $chartLeft ?>" y="252" text-anchor="start" class="report-chart-axis-label"><?= e(fmt_date($byDay[0]['day'])) ?></text>
                                <?php if (count($salesPoints) > 2): ?>
                                    <text x="<?= ($chartLeft + $chartRight) / 2 ?>" y="252" text-anchor="middle" class="report-chart-axis-label"><?= e(fmt_date($byDay[(int)floor($lastDayIndex / 2)]['day'])) ?></text>
                                <?php endif; ?>
                                <?php if ($lastDayIndex > 0): ?>
                                    <text x="<?= $chartRight ?>" y="252" text-anchor="end" class="report-chart-axis-label"><?= e(fmt_date($byDay[$lastDayIndex]['day'])) ?></text>
                                <?php endif; ?>
                            <?php endif; ?>
                        </svg>
                    </div>
                <?php endif; ?>
            </section>
        </div>
        <div class="col-lg-4">
            <section class="panel p-3 h-100" aria-labelledby="sales-by-method-title">
                <h2 class="h5 mb-3" id="sales-by-method-title">Sales by method</h2>
                <?php if (!$byMethod): ?>
                    <p class="text-muted mb-0">No payments in this period.</p>
                <?php else: ?>
                    <div class="report-chart" role="list" aria-label="Paid sales by payment method">
                        <?php foreach ($byMethod as $row):
                            $barWidth = $maxMethodSales > 0 ? ((float)$row['sales'] / $maxMethodSales) * 100 : 0;
                        ?>
                            <div class="report-chart-row" role="listitem" aria-label="<?= e(payment_method_label($row['payment_method'])) ?>: <?= (int)$row['transactions'] ?> transactions, <?= e(money($row['sales'])) ?>">
                                <span class="report-chart-label"><?= e(payment_method_label($row['payment_method'])) ?></span>
                                <span class="report-chart-track"><span class="report-chart-bar" style="width: <?= e((string)$barWidth) ?>%"></span></span>
                                <span class="report-chart-value"><?= money($row['sales']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </div>
    <section class="panel p-3 mt-3" aria-labelledby="bus-report-title">
        <h2 class="h5 mb-1" id="bus-report-title">Bus report</h2>
        <p class="text-muted small mb-3">Trips and booked-seat utilization are based on trip departure dates in this period. Cancelled trips and bookings are excluded.</p>
        <?php if (!$busStats): ?>
            <p class="text-muted mb-0">No buses are available to report.</p>
        <?php else: ?>
            <div class="bus-report-list">
                <?php foreach ($busStats as $bus):
                    $tripCount = (int)$bus['trips'];
                    $seatCapacity = $tripCount * (int)$bus['total_seats'];
                    $bookedSeats = (int)$bus['booked_seats'];
                    $utilization = $seatCapacity > 0 ? min(100, $bookedSeats / $seatCapacity * 100) : 0;
                ?>
                    <div class="bus-report-row">
                        <div class="bus-report-info">
                            <strong><?= e($bus['bus_number']) ?></strong>
                            <span class="text-muted"><?= e($bus['bus_type']) ?> · <?= $tripCount ?> <?= $tripCount === 1 ? 'trip' : 'trips' ?></span>
                        </div>
                        <div class="bus-report-track" role="progressbar" aria-label="<?= e($bus['bus_number']) ?> seat utilization" aria-valuenow="<?= (int)round($utilization) ?>" aria-valuemin="0" aria-valuemax="100">
                            <span class="bus-report-bar" style="width: <?= e((string)$utilization) ?>%"></span>
                        </div>
                        <div class="bus-report-total">
                            <?= $seatCapacity > 0 ? $bookedSeats . ' / ' . $seatCapacity . ' seats · ' . number_format($utilization, 1) . '%' : 'No trips' ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>
<?php endif; ?>
<?php require __DIR__ . '/../../includes/footer.php'; ?>
