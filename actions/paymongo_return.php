<?php
require_once __DIR__ . '/../includes/auth.php';
require_role('PASSENGER');

$bookingId = filter_var($_GET['booking_id'] ?? null, FILTER_VALIDATE_INT);
$result = $_GET['result'] ?? '';
if (!$bookingId || !in_array($result, ['success', 'cancelled'], true)) {
    set_flash('warning', 'Invalid online payment response.');
    redirect('passenger/my_bookings.php');
}

$stmt = $pdo->prepare(
    "SELECT p.payment_method, p.payment_status, bk.status
     FROM payments p
     JOIN bookings bk ON bk.booking_id = p.booking_id
     WHERE p.booking_id = :booking AND bk.user_id = :user
     ORDER BY p.payment_date DESC, p.payment_id DESC LIMIT 1"
);
$stmt->execute([':booking' => $bookingId, ':user' => (int)$_SESSION['user_id']]);
$payment = $stmt->fetch();
if (!$payment || $payment['payment_method'] !== 'ONLINE') {
    set_flash('warning', 'Online payment was not found for this booking.');
    redirect('passenger/my_bookings.php');
}

if ($result === 'cancelled') {
    set_flash('warning', 'Checkout was cancelled. Your reservation remains pending and can be paid later.');
} elseif ($payment['payment_status'] === 'PAID') {
    set_flash('success', 'Online payment received. Your reservation is waiting for administrator confirmation.');
} else {
    set_flash('info', 'Checkout returned. We are waiting for PayMongo to confirm your payment; do not pay again while it is processing.');
}
redirect('passenger/booking_confirmation.php?booking_id=' . $bookingId);
