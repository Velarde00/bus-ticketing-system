<?php
/**
 * actions/handle_cancellation_choice.php
 * Handles the passenger's decision when their trip was cancelled by admin:
 * 1) Request Refund -> sets cancellation_choice = 'REFUND_REQUESTED' and payment status = 'REFUND_REQUESTED'
 * 2) Rebook -> sets cancellation_choice = 'REBOOK_REQUESTED' and redirects to similar trips
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
$choice    = trim($_POST['choice'] ?? '');

if (!$bookingId || !in_array($choice, ['REFUND', 'REBOOK'], true)) {
    set_flash('warning', 'Invalid choice.');
    redirect('passenger/my_bookings.php');
}

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare(
        "SELECT bk.booking_id, bk.booking_reference, bk.trip_id, bk.status, bk.cancelled_by, bk.cancellation_choice,
                r.origin, r.destination, t.route_id, t.departure_time, t.status AS trip_status,
                CONCAT(u.first_name, ' ', u.last_name) AS passenger_name, u.phone_number
         FROM bookings bk
         JOIN users  u ON u.user_id  = bk.user_id
         JOIN trips  t ON t.trip_id  = bk.trip_id
         JOIN routes r ON r.route_id = t.route_id
         WHERE bk.booking_id = :id AND bk.user_id = :uid FOR UPDATE"
    );
    $stmt->execute([':id' => $bookingId, ':uid' => $uid]);
    $booking = $stmt->fetch();

    if (!$booking) {
        throw new RuntimeException('Booking record not found.');
    }

    if ($booking['status'] !== 'CANCELLED') {
        throw new RuntimeException('This booking is not cancelled.');
    }

    // Get all admin user IDs to notify
    $adminIds = $pdo->query("SELECT user_id FROM users WHERE role IN ('ADMIN', 'SUPER_ADMIN')")->fetchAll(PDO::FETCH_COLUMN);

    if ($choice === 'REFUND') {
        // Only automatically refund if the cancellation was made by ADMIN or the trip itself was CANCELLED
        $isCancelledByAdminOrTrip = ($booking['cancelled_by'] === 'ADMIN' || $booking['trip_status'] === 'CANCELLED');

        // Find ALL cancelled bookings for this SAME passenger on this SAME cancelled trip
        $tripId = (int)$booking['trip_id'];
        $relatedBookingsStmt = $pdo->prepare(
            "SELECT bk.booking_id, bk.booking_reference, bk.status, bk.cancelled_by,
                    p.payment_id, p.amount, p.payment_status
             FROM bookings bk
             JOIN payments p ON p.booking_id = bk.booking_id
             WHERE bk.user_id = :uid AND bk.trip_id = :trip_id AND bk.status = 'CANCELLED'
             ORDER BY bk.booking_id ASC"
        );
        $relatedBookingsStmt->execute([':uid' => $uid, ':trip_id' => $tripId]);
        $relatedBookings = $relatedBookingsStmt->fetchAll();

        // If no rows found via join, fallback to just this booking
        if (empty($relatedBookings)) {
            $payStmt = $pdo->prepare(
                "SELECT payment_id, amount, payment_status FROM payments WHERE booking_id = :id ORDER BY payment_date DESC LIMIT 1"
            );
            $payStmt->execute([':id' => $bookingId]);
            $payment = $payStmt->fetch();
            $relatedBookings = [[
                'booking_id'        => $bookingId,
                'booking_reference' => $booking['booking_reference'],
                'status'            => 'CANCELLED',
                'cancelled_by'      => $booking['cancelled_by'],
                'payment_id'        => $payment['payment_id'] ?? null,
                'amount'            => $payment['amount'] ?? 0,
                'payment_status'    => $payment['payment_status'] ?? 'PAID'
            ]];
        }

        if ($isCancelledByAdminOrTrip) {
            // AUTOMATIC BULK REFUND: Refund all bookings for this passenger on this trip simultaneously
            $totalRefundAmount = 0.0;
            $refundedRefs = [];
            $notifStmt = $pdo->prepare(
                "INSERT INTO notifications (user_id, title, message, link) VALUES (:uid, :title, :msg, :link)"
            );
            $updateBooking = $pdo->prepare("UPDATE bookings SET cancellation_choice = 'REFUND_REQUESTED' WHERE booking_id = :id");
            $updatePayment = $pdo->prepare(
                "UPDATE payments
                 SET payment_status = 'REFUNDED',
                     refund_payment_option = 'GCASH',
                     refund_account_name = :acct_name,
                     refund_account_number = :acct_num,
                     refund_transfer_reference = :ref_no,
                     refund_processed_at = NOW(),
                     payment_date = NOW()
                 WHERE payment_id = :pid"
            );

            foreach ($relatedBookings as $rb) {
                $bId = (int)$rb['booking_id'];
                $pId = (int)($rb['payment_id'] ?? 0);
                $pStatus = $rb['payment_status'] ?? '';
                $refNo = 'REF-EW-' . strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 8));

                $updateBooking->execute([':id' => $bId]);

                if ($pId && in_array($pStatus, ['PAID', 'REFUND_REQUESTED', 'PENDING'], true)) {
                    $updatePayment->execute([
                        ':acct_name' => $booking['passenger_name'],
                        ':acct_num'  => $booking['phone_number'] ?: '09123456789',
                        ':ref_no'    => $refNo,
                        ':pid'       => $pId
                    ]);
                    $totalRefundAmount += (float)$rb['amount'];
                    $refundedRefs[] = $rb['booking_reference'] . " ({$refNo})";
                }
            }

            $refsList = implode(', ', $refundedRefs);

            // 1. Notify Passenger of full automated refund across all tickets/seats for this trip
            $notifStmt->execute([
                ':uid'   => $uid,
                ':title' => "All Trip Bookings Refunded to Your E-Wallet",
                ':msg'   => "The operator cancelled trip ({$booking['origin']} → {$booking['destination']}). A full total refund of " . money($totalRefundAmount) . " for your booking(s) [{$refsList}] has been automatically sent to your registered E-Wallet ({$booking['phone_number']}).",
                ':link'  => "passenger/booking_confirmation.php?booking_id=" . $bookingId,
            ]);

            // 2. Notify Admins
            $adminTitle = "Automatic Full Refund Disbursed: {$booking['passenger_name']}";
            $adminMsg   = "Trip ({$booking['origin']} → {$booking['destination']}) cancelled. All bookings for {$booking['passenger_name']} [{$refsList}] were automatically refunded to their E-Wallet for a total of " . money($totalRefundAmount) . ".";
            $adminLink  = "admin/payments/index.php?search=" . urlencode($booking['passenger_name']);
            foreach ($adminIds as $adminId) {
                $notifStmt->execute([':uid' => $adminId, ':title' => $adminTitle, ':msg' => $adminMsg, ':link' => $adminLink]);
            }

            $pdo->commit();
            set_flash('success', "All your bookings for this cancelled trip were automatically refunded! A total of " . money($totalRefundAmount) . " has been sent directly to your registered E-Wallet ({$booking['phone_number']}).");
            redirect('passenger/booking_confirmation.php?booking_id=' . $bookingId);
        } else {
            // Standard refund request (voluntary cancellation by passenger)
            $updateBooking = $pdo->prepare("UPDATE bookings SET cancellation_choice = 'REFUND_REQUESTED' WHERE booking_id = :id");
            $updatePayment = $pdo->prepare("UPDATE payments SET payment_status = 'REFUND_REQUESTED' WHERE booking_id = :id AND payment_status IN ('PAID', 'PENDING')");
            foreach ($relatedBookings as $rb) {
                $updateBooking->execute([':id' => (int)$rb['booking_id']]);
                $updatePayment->execute([':id' => (int)$rb['booking_id']]);
            }

            $notifStmt = $pdo->prepare(
                "INSERT INTO notifications (user_id, title, message, link) VALUES (:uid, :title, :msg, :link)"
            );
            $title = "Refund Requested: {$booking['passenger_name']} ({$booking['booking_reference']})";
            $msg   = "Passenger {$booking['passenger_name']} requested a refund for booking ({$booking['origin']} → {$booking['destination']}).";
            $link  = "admin/payments/index.php?search=" . urlencode($booking['passenger_name']);
            foreach ($adminIds as $adminId) {
                $notifStmt->execute([':uid' => $adminId, ':title' => $title, ':msg' => $msg, ':link' => $link]);
            }

            $pdo->commit();
            set_flash('success', "Refund request submitted for all your bookings on this trip. The terminal administrator has been notified to process your refund.");
            redirect('passenger/booking_confirmation.php?booking_id=' . $bookingId);
        }
    } else {
        // REBOOK
        $pdo->prepare(
            "UPDATE bookings SET cancellation_choice = 'REBOOK_REQUESTED' WHERE booking_id = :id"
        )->execute([':id' => $bookingId]);

        // Create notification for admin
        $notifStmt = $pdo->prepare(
            "INSERT INTO notifications (user_id, title, message, link) VALUES (:uid, :title, :msg, :link)"
        );
        $title = "Rebook Requested: {$booking['booking_reference']}";
        $msg   = "Passenger {$booking['passenger_name']} chose to rebook their journey for cancelled trip ({$booking['origin']} → {$booking['destination']}).";
        $link  = "admin/reservations/view.php?id=" . $bookingId;
        foreach ($adminIds as $adminId) {
            $notifStmt->execute([':uid' => $adminId, ':title' => $title, ':msg' => $msg, ':link' => $link]);
        }

        $pdo->commit();
        set_flash('info', "Please choose a similar trip below to rebook your travel from {$booking['origin']} to {$booking['destination']}.");
        redirect('passenger/rebook_trip.php?booking_id=' . $bookingId);
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('handle_cancellation_choice: ' . $e->getMessage());
    set_flash('danger', $e->getMessage());
    redirect('passenger/booking_confirmation.php?booking_id=' . $bookingId);
}
