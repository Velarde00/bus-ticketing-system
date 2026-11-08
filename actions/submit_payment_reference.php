<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('PASSENGER');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    set_flash('danger', 'Your session expired. Please try again.');
    redirect('passenger/my_bookings.php');
}

$bookingId = filter_var($_POST['booking_id'] ?? null, FILTER_VALIDATE_INT);
$reference = trim(is_string($_POST['transfer_reference'] ?? null) ? $_POST['transfer_reference'] : '');
if (!$bookingId || !preg_match('/^[A-Za-z0-9][A-Za-z0-9 .#\/-]{3,99}$/', $reference)) {
    set_flash('danger', 'Enter a valid transfer reference (4 to 100 characters).');
    redirect('passenger/booking_confirmation.php?booking_id=' . (int)$bookingId);
}

$submitted = false;
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        "UPDATE payments p
         JOIN bookings bk ON bk.booking_id = p.booking_id
         JOIN trips t ON t.trip_id = bk.trip_id
         SET p.transfer_reference = :reference, p.transfer_submitted_at = NOW()
         WHERE bk.booking_id = :booking AND bk.user_id = :user
           AND bk.status = 'PENDING' AND t.status <> 'CANCELLED'
           AND p.payment_method = 'MANUAL_TRANSFER'
           AND p.payment_status = 'PENDING'"
    );
    $stmt->execute([
        ':reference' => $reference,
        ':booking' => $bookingId,
        ':user' => (int)$_SESSION['user_id'],
    ]);
    $submitted = $stmt->rowCount() > 0;
    if ($submitted) {
        $referenceStmt = $pdo->prepare('SELECT booking_reference FROM bookings WHERE booking_id = :id');
        $referenceStmt->execute([':id' => $bookingId]);
        $bookingReference = (string)$referenceStmt->fetchColumn();
        notify_active_roles(
            $pdo,
            ['ADMIN', 'SUPER_ADMIN'],
            'Transfer reference submitted',
            'A transfer reference was submitted for booking ' . $bookingReference . '.',
            'admin/payments/index.php'
        );
    }
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('submit payment reference: ' . $e->getMessage());
    set_flash('danger', 'Could not submit the transfer reference. Please try again.');
    redirect('passenger/booking_confirmation.php?booking_id=' . $bookingId);
}

if ($submitted) {
    set_flash('success', 'Transfer reference submitted. Your reservation will be confirmed after admin verification.');
} else {
    set_flash('warning', 'This payment can no longer accept a transfer reference.');
}
redirect('passenger/booking_confirmation.php?booking_id=' . $bookingId);