<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('PASSENGER');

$stmt = $pdo->prepare(
    "SELECT bk.booking_id, bk.booking_reference, bk.booking_date, bk.cancellation_choice,
            t.departure_time, r.origin, r.destination, b.bus_number,
            p.payment_status, p.payment_method, p.refund_payment_option,
            p.refund_account_name, p.refund_account_number, p.refund_transfer_reference,
            (p.payment_status IN ('PAID', 'REFUND_REQUESTED')
                AND bk.booking_date <= DATE_SUB(t.departure_time, INTERVAL 2 DAY)) AS refund_eligible
     FROM bookings bk JOIN trips t ON t.trip_id = bk.trip_id
     JOIN routes r ON r.route_id = t.route_id JOIN buses b ON b.bus_id = t.bus_id
     LEFT JOIN payments p ON p.payment_id = (
         SELECT p2.payment_id FROM payments p2 WHERE p2.booking_id = bk.booking_id
         ORDER BY p2.payment_date DESC, p2.payment_id DESC LIMIT 1
     )
     WHERE bk.user_id = :uid AND bk.status = 'CANCELLED' AND t.status = 'CANCELLED'
     ORDER BY bk.booking_date DESC"
);
$stmt->execute([':uid' => (int)$_SESSION['user_id']]);
$notifications = $stmt->fetchAll();

$pageTitle = 'Trip notifications';
$layout = 'public';
require __DIR__ . '/../includes/header.php';
?>
<div class="container py-4">
    <div class="mb-4">
        <h1 class="h3 mb-1">Trip notifications</h1>
        <p class="text-muted mb-0">Updates about trips cancelled by the operator.</p>
    </div>
    <?php if (!$notifications): ?>
        <div class="empty-state"><i class="bi bi-bell-slash"></i><p class="mb-0">You have no trip cancellation notifications.</p></div>
    <?php else: ?>
        <div class="panel">
            <?php foreach ($notifications as $notification): ?>
                <?php
                $needsRefundDetails = $notification['refund_eligible']
                    && ($notification['cancellation_choice'] === null
                        || ($notification['cancellation_choice'] === 'REFUND_REQUESTED' && !$notification['refund_payment_option']));
                ?>
                <article class="p-3 border-bottom">
                    <div class="d-flex flex-wrap justify-content-between gap-2">
                        <div>
                            <h2 class="h5 mb-1"><?= e($notification['origin']) ?> to <?= e($notification['destination']) ?></h2>
                            <p class="text-muted mb-2"><?= e(fmt_datetime($notification['departure_time'])) ?> · Bus <?= e($notification['bus_number']) ?> · <?= e($notification['booking_reference']) ?></p>
                        </div>
                        <?php if ($notification['cancellation_choice'] === 'REFUND_REQUESTED'): ?>
                            <span class="badge text-bg-info align-self-start"><?= $notification['payment_status'] === 'REFUNDED' ? 'Refund sent' : 'Refund requested' ?></span>
                        <?php elseif ($notification['cancellation_choice'] === 'REBOOK_REQUESTED'): ?>
                            <span class="badge text-bg-secondary align-self-start">Rebooking selected</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($needsRefundDetails): ?>
                        <form method="post" action="<?= url('actions/resolve_trip_cancellation.php') ?>" class="mb-3">
                            <?= csrf_field() ?>
                            <input type="hidden" name="booking_id" value="<?= (int)$notification['booking_id'] ?>">
                            <input type="hidden" name="choice" value="refund">
                            <fieldset>
                                <legend class="h6">Online refund destination</legend>
                                <div class="row g-2">
                                    <div class="col-md-4"><label class="form-label" for="refund-method-<?= (int)$notification['booking_id'] ?>">Method</label>
                                        <select class="form-select" id="refund-method-<?= (int)$notification['booking_id'] ?>" name="refund_payment_option" required>
                                            <option value="">Choose a method</option>
                                            <?php foreach (['GCASH' => 'GCash', 'MAYA' => 'Maya', 'MARIBANK' => 'MariBank', 'BANK_TRANSFER' => 'Online bank transfer'] as $option => $label): ?>
                                                <option value="<?= e($option) ?>" <?= $notification['refund_payment_option'] === $option ? 'selected' : '' ?>><?= e($label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-4"><label class="form-label" for="refund-name-<?= (int)$notification['booking_id'] ?>">Account name</label><input class="form-control" id="refund-name-<?= (int)$notification['booking_id'] ?>" name="refund_account_name" maxlength="120" value="<?= e($notification['refund_account_name'] ?? '') ?>" required></div>
                                    <div class="col-md-4"><label class="form-label" for="refund-number-<?= (int)$notification['booking_id'] ?>">Account number / mobile</label><input class="form-control" id="refund-number-<?= (int)$notification['booking_id'] ?>" name="refund_account_number" maxlength="120" value="<?= e($notification['refund_account_number'] ?? '') ?>" required></div>
                                </div>
                                <button class="btn btn-primary btn-sm mt-3" type="submit"><i class="bi bi-arrow-counterclockwise"></i> <?= $notification['cancellation_choice'] === 'REFUND_REQUESTED' ? 'Save refund destination' : 'Request online refund' ?></button>
                            </fieldset>
                        </form>
                    <?php endif; ?>
                    <?php if ($notification['cancellation_choice'] === null): ?>
                        <div class="d-flex flex-wrap align-items-center gap-2">
                            <?php if (!$notification['refund_eligible']): ?>
                                <span class="small text-muted">Refund unavailable: this reservation was not paid at least 2 days before departure.</span>
                            <?php endif; ?>
                            <form method="post" action="<?= url('actions/resolve_trip_cancellation.php') ?>">
                                <?= csrf_field() ?><input type="hidden" name="booking_id" value="<?= (int)$notification['booking_id'] ?>"><input type="hidden" name="choice" value="rebook">
                                <button class="btn btn-outline-dark btn-sm" type="submit"><i class="bi bi-arrow-repeat"></i> Choose another trip</button>
                            </form>
                        </div>
                    <?php elseif ($notification['cancellation_choice'] === 'REBOOK_REQUESTED'): ?>
                        <a class="btn btn-primary btn-sm" href="<?= url('passenger/dashboard.php') ?>">View available trips</a>
                    <?php elseif ($notification['cancellation_choice'] === 'REFUND_REQUESTED'): ?>
                        <?php if ($notification['payment_status'] === 'REFUNDED'): ?>
                            <p class="small text-muted mb-0">Your refund was sent as <?= e(online_payment_option_label($notification['refund_payment_option'])) ?>. Transfer reference: <?= e($notification['refund_transfer_reference'] ?: 'Not provided') ?></p>
                        <?php elseif (!$needsRefundDetails): ?>
                            <p class="small text-muted mb-0">The administrator will send the online transfer to <?= e(online_payment_option_label($notification['refund_payment_option'])) ?> account <?= e($notification['refund_account_number']) ?>.</p>
                        <?php endif; ?>
                    <?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>