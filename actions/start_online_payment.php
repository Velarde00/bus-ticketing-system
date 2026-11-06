<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/paymongo.php';
require_role('PASSENGER');

$bookingId = filter_var($_GET['booking_id'] ?? null, FILTER_VALIDATE_INT);
if (!$bookingId) {
    set_flash('warning', 'Choose a booking first.');
    redirect('passenger/my_bookings.php');
}

$stmt = $pdo->prepare(
    "SELECT p.payment_id, p.amount, p.payment_method, p.payment_status,
            p.paymongo_checkout_id, p.paymongo_checkout_url, bk.booking_reference
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
if ($payment['payment_status'] !== 'PENDING') {
    set_flash('info', 'This payment is already ' . strtolower($payment['payment_status']) . '.');
    redirect('passenger/booking_confirmation.php?booking_id=' . $bookingId);
}

if (is_string($payment['paymongo_checkout_url']) && $payment['paymongo_checkout_url'] !== '') {
    $checkoutUrl = $payment['paymongo_checkout_url'];
    if (!paymongo_checkout_url_is_valid($checkoutUrl)) {
        error_log('start_online_payment: stored PayMongo checkout URL has an invalid host or scheme.');
        set_flash('danger', 'The saved online checkout link is invalid. Contact the administrator.');
        redirect('passenger/booking_confirmation.php?booking_id=' . $bookingId);
    }
} else {
    try {
        $session = paymongo_create_checkout($payment, $payment['booking_reference'], $bookingId);
        $pdo->prepare(
            'UPDATE payments SET paymongo_checkout_id = :checkout_id, paymongo_checkout_url = :checkout_url WHERE payment_id = :id'
        )->execute([
            ':checkout_id' => $session['id'],
            ':checkout_url' => $session['url'],
            ':id' => $payment['payment_id'],
        ]);
        $checkoutUrl = $session['url'];
    } catch (RuntimeException $e) {
        error_log('start_online_payment: ' . $e->getMessage());
        set_flash('danger', $e->getMessage());
        redirect('passenger/booking_confirmation.php?booking_id=' . $bookingId);
    } catch (JsonException $e) {
        error_log('start_online_payment: could not encode checkout request: ' . $e->getMessage());
        set_flash('danger', 'We could not prepare online checkout. Please try again later.');
        redirect('passenger/booking_confirmation.php?booking_id=' . $bookingId);
    } catch (PDOException $e) {
        error_log('start_online_payment: could not save checkout session: ' . $e->getMessage());
        set_flash('danger', 'We could not save the checkout session. Please contact the administrator before paying again.');
        redirect('passenger/booking_confirmation.php?booking_id=' . $bookingId);
    }
}

header('Location: ' . $checkoutUrl, true, 303);
exit;
