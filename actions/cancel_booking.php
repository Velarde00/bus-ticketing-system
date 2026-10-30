<?php
/**
 * actions/cancel_booking.php
 * Cancels the logged-in passenger's own booking and frees its seats.
 * Rule: a booking can be cancelled until 2 hours before departure.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('PASSENGER');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('passenger/my_bookings.php');
}
if (!csrf_verify()) {
    set_flash('danger', 'Your session expired. Please try again.');
    redirect('passenger/my_bookings.php');
}

$uid       = (int)$_SESSION['user_id'];
$bookingId = filter_var($_POST['booking_id'] ?? null, FILTER_VALIDATE_INT);
if (!$bookingId) {
    redirect('passenger/my_bookings.php');
}

try {
    $pdo->beginTransaction();

    // "AND user_id" makes sure passengers can only cancel their OWN bookings
    $stmt = $pdo->prepare(
        "SELECT bk.status, t.departure_time
         FROM bookings bk JOIN trips t ON t.trip_id = bk.trip_id
         WHERE bk.booking_id = :id AND bk.user_id = :uid FOR UPDATE"
    );
    $stmt->execute([':id' => $bookingId, ':uid' => $uid]);
    $booking = $stmt->fetch();

    if (!$booking) {
        throw new RuntimeException('Booking not found.');
    }
    if ($booking['status'] === 'CANCELLED') {
        throw new RuntimeException('This booking is already cancelled.');
    }
    if (strtotime($booking['departure_time']) - time() < 2 * 3600) {
        throw new RuntimeException('Bookings cannot be cancelled within 2 hours of departure.');
    }

    $pdo->prepare("UPDATE bookings SET status = 'CANCELLED' WHERE booking_id = :id")
        ->execute([':id' => $bookingId]);
    // Cancelled seats become available again
    $pdo->prepare("UPDATE booking_seats SET seat_status = 'CANCELLED' WHERE booking_id = :id")
        ->execute([':id' => $bookingId]);

    $pdo->commit();
    set_flash('success', 'Your booking was cancelled and the seats are available again.');
} catch (RuntimeException $e) {   // includes PDOException
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($e instanceof PDOException) {
        error_log('cancel_booking: ' . $e->getMessage());
        set_flash('danger', 'We could not cancel the booking. Please try again.');
    } else {
        set_flash('warning', $e->getMessage());
    }
}

redirect('passenger/booking_confirmation.php?booking_id=' . $bookingId);
