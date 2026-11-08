<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('PASSENGER');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    set_flash('danger', 'Your session expired. Please try again.');
    redirect('passenger/notifications.php');
}

$bookingId = filter_var($_POST['booking_id'] ?? null, FILTER_VALIDATE_INT);
$choice = $_POST['choice'] ?? '';
$refundOption = is_string($_POST['refund_payment_option'] ?? null) ? $_POST['refund_payment_option'] : '';
$refundAccountName = trim(is_string($_POST['refund_account_name'] ?? null) ? $_POST['refund_account_name'] : '');
$refundAccountNumber = trim(is_string($_POST['refund_account_number'] ?? null) ? $_POST['refund_account_number'] : '');
if (!$bookingId || !in_array($choice, ['refund', 'rebook'], true)) {
    set_flash('danger', 'Choose a valid option for this cancelled trip.');
    redirect('passenger/notifications.php');
}

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        "SELECT bk.booking_id, bk.booking_reference, bk.booking_date, bk.status AS booking_status,
                bk.cancellation_choice, t.departure_time, t.status AS trip_status,
                p.payment_id, p.payment_status, p.refund_payment_option,
                p.refund_account_name, p.refund_account_number
         FROM bookings bk JOIN trips t ON t.trip_id = bk.trip_id
         LEFT JOIN payments p ON p.payment_id = (
             SELECT p2.payment_id FROM payments p2 WHERE p2.booking_id = bk.booking_id
             ORDER BY p2.payment_date DESC, p2.payment_id DESC LIMIT 1
         )
         WHERE bk.booking_id = :id AND bk.user_id = :uid
         FOR UPDATE"
    );
    $stmt->execute([':id' => $bookingId, ':uid' => (int)$_SESSION['user_id']]);
    $booking = $stmt->fetch();
    if (!$booking || $booking['booking_status'] !== 'CANCELLED' || $booking['trip_status'] !== 'CANCELLED') {
        throw new RuntimeException('This trip cancellation is no longer available.');
    }
    $updatingMissingRefundDetails = $booking['cancellation_choice'] === 'REFUND_REQUESTED'
        && $booking['payment_status'] === 'REFUND_REQUESTED'
        && !$booking['refund_payment_option'];
    if ($booking['cancellation_choice'] !== null && !($choice === 'refund' && $updatingMissingRefundDetails)) {
        throw new RuntimeException('You have already chosen an option for this trip.');
    }

    if ($choice === 'refund') {
        $eligible = $booking['payment_status'] === 'PAID'
            || ($updatingMissingRefundDetails && $booking['payment_status'] === 'REFUND_REQUESTED');
        $eligible = $eligible
            && strtotime($booking['booking_date']) <= strtotime($booking['departure_time']) - 2 * 24 * 3600;
        if (!$eligible || !$booking['payment_id']) {
            throw new RuntimeException('A refund is available only for paid reservations made at least 2 days before departure.');
        }
        if (!in_array($refundOption, ['GCASH', 'MAYA', 'MARIBANK', 'BANK_TRANSFER'], true)
            || $refundAccountName === '' || strlen($refundAccountName) > 120
            || !preg_match('/^[A-Za-z0-9][A-Za-z0-9 +().#\/-]{3,119}$/', $refundAccountNumber)) {
            throw new RuntimeException('Enter a valid online refund method, account name, and account number.');
        }
        if (!$updatingMissingRefundDetails) {
            $pdo->prepare("UPDATE bookings SET cancellation_choice = 'REFUND_REQUESTED' WHERE booking_id = :id")
                ->execute([':id' => $bookingId]);
        }
        $pdo->prepare(
            "UPDATE payments SET payment_status = 'REFUND_REQUESTED',
                refund_payment_option = :option, refund_account_name = :name,
                refund_account_number = :number
             WHERE payment_id = :id"
        )->execute([
            ':option' => $refundOption,
            ':name' => $refundAccountName,
            ':number' => $refundAccountNumber,
            ':id' => $booking['payment_id'],
        ]);
        notify_active_roles(
            $pdo,
            ['ADMIN', 'SUPER_ADMIN'],
            'Refund requested',
            'A refund was requested for booking ' . $booking['booking_reference'] . '.',
            'admin/refunds/index.php'
        );
    } else {
        $pdo->prepare("UPDATE bookings SET cancellation_choice = 'REBOOK_REQUESTED' WHERE booking_id = :id")
            ->execute([':id' => $bookingId]);
    }
    $pdo->commit();
    set_flash('success', $choice === 'refund'
        ? 'Your online refund destination was sent to the administrator.'
        : 'You can now choose another available trip from your dashboard.');
} catch (RuntimeException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    set_flash('warning', $e->getMessage());
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('resolve trip cancellation: ' . $e->getMessage());
    set_flash('danger', 'Could not save your choice. Please try again.');
}

redirect($choice === 'rebook' ? 'passenger/dashboard.php' : 'passenger/notifications.php');