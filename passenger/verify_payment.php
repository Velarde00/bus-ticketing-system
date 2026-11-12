<?php
/**
 * passenger/verify_payment.php
 * E-Wallet (GCash / Maya) Online Payment OTP Verification Gateway.
 * Dispatches an authorization code to the user's email and mobile number.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('PASSENGER');

$uid = (int)$_SESSION['user_id'];
$bookingId = filter_var($_GET['booking_id'] ?? ($_SESSION['pending_payment_otp']['booking_id'] ?? null), FILTER_VALIDATE_INT);

if (!$bookingId) {
    redirect('passenger/my_bookings.php');
}

// Fetch booking details
$stmt = $pdo->prepare(
    "SELECT bk.booking_id, bk.booking_reference, bk.status, bk.trip_id,
            t.departure_time, r.origin, r.destination, b.bus_number,
            p.payment_id, p.amount, p.payment_method, p.online_payment_option, p.payment_status
     FROM bookings bk
     JOIN trips   t ON t.trip_id   = bk.trip_id
     JOIN routes  r ON r.route_id  = t.route_id
     JOIN buses   b ON b.bus_id    = t.bus_id
     JOIN payments p ON p.booking_id = bk.booking_id
     WHERE bk.booking_id = :id AND bk.user_id = :uid
     ORDER BY p.payment_id DESC LIMIT 1"
);
$stmt->execute([':id' => $bookingId, ':uid' => $uid]);
$booking = $stmt->fetch();

if (!$booking) {
    set_flash('danger', 'Booking not found.');
    redirect('passenger/my_bookings.php');
}

if ($booking['status'] === 'CONFIRMED' && $booking['payment_status'] === 'PAID') {
    redirect('passenger/booking_confirmation.php?booking_id=' . $bookingId);
}

// Get user contact info
$uStmt = $pdo->prepare('SELECT email, phone_number FROM users WHERE user_id = :id');
$uStmt->execute([':id' => $uid]);
$user = $uStmt->fetch();

$userEmail = $user['email'] ?? '';
$userPhone = $user['phone_number'] ?? '';

// Ensure pending OTP session exists
if (empty($_SESSION['pending_payment_otp']) || (int)($_SESSION['pending_payment_otp']['booking_id'] ?? 0) !== $bookingId) {
    $otpData = create_and_dispatch_otp($pdo, $userEmail, $userPhone, 'PAYMENT', $uid, $bookingId);
    $_SESSION['pending_payment_otp'] = [
        'booking_id'   => $bookingId,
        'wallet'       => $booking['online_payment_option'] ?: 'GCASH',
        'amount'       => (float)$booking['amount'],
        'email'        => $userEmail,
        'phone'        => $userPhone,
        'masked_email' => $otpData['masked_email'],
        'masked_phone' => $otpData['masked_phone'],
        'code'         => $otpData['code'],
        'expires_at'   => $otpData['expires_at'],
    ];
}

$pending = $_SESSION['pending_payment_otp'];
$errors = [];

// Handle Resend Request
if (isset($_GET['resend']) && $_GET['resend'] === '1') {
    $newOtp = create_and_dispatch_otp($pdo, $userEmail, $userPhone, 'PAYMENT', $uid, $bookingId);
    $_SESSION['pending_payment_otp']['code'] = $newOtp['code'];
    $_SESSION['pending_payment_otp']['expires_at'] = $newOtp['expires_at'];
    $pending = $_SESSION['pending_payment_otp'];
    set_flash('success', 'A new payment verification code has been dispatched to your email and mobile number.');
    redirect('passenger/verify_payment.php?booking_id=' . $bookingId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $enteredCode = trim($_POST['otp_code'] ?? '');

        if ($enteredCode === '' || strlen($enteredCode) !== 6 || !ctype_digit($enteredCode)) {
            $errors[] = 'Please enter a valid 6-digit verification code.';
        } else {
            $isValid = verify_otp_code($pdo, $userEmail, $enteredCode, 'PAYMENT');

            if (!$isValid) {
                $errors[] = 'The code you entered is invalid or has expired. Please try again.';
            } else {
                // Payment verified! Update booking & payment to CONFIRMED / PAID and issue tickets
                try {
                    $pdo->beginTransaction();

                    // 1. Confirm booking
                    $pdo->prepare("UPDATE bookings SET status = 'CONFIRMED' WHERE booking_id = :id")
                        ->execute([':id' => $bookingId]);

                    // 2. Mark payment PAID
                    $transferRef = strtoupper($pending['wallet']) . '-AUTH-' . bin2hex(random_bytes(5));
                    $pdo->prepare(
                        "UPDATE payments SET payment_status = 'PAID', transfer_reference = :ref, transfer_submitted_at = NOW()
                         WHERE booking_id = :id"
                    )->execute([':ref' => $transferRef, ':id' => $bookingId]);

                    // 3. Issue tickets for all booked seats
                    $seatsStmt = $pdo->prepare('SELECT booking_seat_id FROM booking_seats WHERE booking_id = :id AND seat_status = \'BOOKED\'');
                    $seatsStmt->execute([':id' => $bookingId]);
                    $seatIds = $seatsStmt->fetchAll(PDO::FETCH_COLUMN);

                    $ticketInsert = $pdo->prepare('INSERT INTO tickets (booking_seat_id, ticket_number) VALUES (:seat, :number)');
                    $ticketUpdate = $pdo->prepare('UPDATE tickets SET ticket_number = :number WHERE ticket_id = :id');

                    foreach ($seatIds as $seatId) {
                        // Check if ticket already exists
                        $chk = $pdo->prepare('SELECT ticket_id FROM tickets WHERE booking_seat_id = :seat');
                        $chk->execute([':seat' => $seatId]);
                        if (!$chk->fetchColumn()) {
                            $ticketInsert->execute([':seat' => $seatId, ':number' => 'TMP-' . bin2hex(random_bytes(8))]);
                            $tId = (int)$pdo->lastInsertId();
                            $ticketUpdate->execute([':number' => sprintf('TKT-%s-%06d', date('Y'), $tId), ':id' => $tId]);
                        }
                    }

                    // 4. Send confirmation notification to passenger
                    $pdo->prepare(
                        "INSERT INTO notifications (user_id, title, message, link)
                         VALUES (:uid, :title, :msg, :link)"
                    )->execute([
                        ':uid'   => $uid,
                        ':title' => 'Payment Verified & Confirmed',
                        ':msg'   => 'Your online payment for booking ' . $booking['booking_reference'] . ' has been verified via OTP.',
                        ':link'  => 'passenger/booking_confirmation.php?booking_id=' . $bookingId
                    ]);

                    $pdo->commit();
                    unset($_SESSION['pending_payment_otp']);

                    set_flash('success', 'Online payment verified successfully! Your tickets are ready.');
                    redirect('passenger/booking_confirmation.php?booking_id=' . $bookingId);
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    error_log('verify payment error: ' . $e->getMessage());
                    $errors[] = 'A database error occurred while confirming your payment. Please contact support.';
                }
            }
        }
    }
}

$pageTitle = 'Verify Online Payment';
$layout    = 'public';
require __DIR__ . '/../includes/header.php';
?>
<div class="container py-5" style="max-width: 580px;">
    <!-- Brand / Gateway header -->
    <div class="card border-0 shadow-lg rounded-4 overflow-hidden mb-4">
        <div class="bg-primary text-white p-4 text-center">
            <div class="badge bg-white text-primary rounded-pill px-3 py-1 mb-2 fw-semibold">
                <i class="bi bi-shield-lock-fill me-1"></i> Secure E-Wallet Gateway
            </div>
            <h1 class="h4 fw-bold mb-1">Verify Your Online Payment</h1>
            <p class="small text-white-50 mb-0">Booking Ref: <span class="font-monospace fw-bold text-white"><?= e($booking['booking_reference']) ?></span></p>
        </div>

        <div class="card-body p-4 p-sm-5">
            <!-- Payment summary box -->
            <div class="p-3 bg-light rounded-3 border mb-4">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted small">Route &amp; Journey:</span>
                    <strong class="text-dark small"><?= e($booking['origin']) ?> &rarr; <?= e($booking['destination']) ?></strong>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted small">E-Wallet Provider:</span>
                    <span class="badge bg-primary text-white rounded-pill px-2 py-1"><?= e($pending['wallet']) ?></span>
                </div>
                <div class="d-flex justify-content-between align-items-center pt-2 border-top">
                    <span class="fw-bold text-dark">Amount to Pay:</span>
                    <span class="fw-bold fs-4 text-primary"><?= money($pending['amount']) ?></span>
                </div>
            </div>

            <!-- OTP Target Details -->
            <div class="alert alert-secondary border-0 py-2 px-3 small rounded-3 mb-4">
                <div class="text-muted small mb-1"><i class="bi bi-envelope me-1 text-primary"></i> OTP sent to Email: <strong><?= e($pending['masked_email']) ?></strong></div>
                <div class="text-muted small"><i class="bi bi-phone me-1 text-primary"></i> OTP sent to Mobile: <strong><?= e($pending['masked_phone']) ?></strong></div>
            </div>

            <!-- Simulation Banner for Easy Local Testing -->
            <div class="alert alert-info border-0 shadow-sm rounded-3 py-2 px-3 small d-flex align-items-center gap-2 mb-4">
                <i class="bi bi-chat-left-dots-fill text-info flex-shrink-0 fs-5"></i>
                <div>
                    <strong>Payment Authorization Code:</strong><br>
                    <span>Sent code: <strong class="badge bg-primary text-white fs-6 px-2 py-1 font-monospace"><?= e($pending['code']) ?></strong> (Click <a href="javascript:void(0);" onclick="document.getElementById('otp_code').value='<?= e($pending['code']) ?>';" class="fw-bold text-decoration-underline">Autofill</a>)</span>
                </div>
            </div>

            <?php foreach ($errors as $error): ?>
                <div class="alert alert-danger py-2 d-flex align-items-center gap-2 border-0 shadow-sm small mb-3">
                    <i class="bi bi-exclamation-circle-fill text-danger"></i>
                    <div><?= e($error) ?></div>
                </div>
            <?php endforeach; ?>

            <form method="post" novalidate>
                <?= csrf_field() ?>
                <div class="mb-4">
                    <label for="otp_code" class="form-label small fw-semibold text-center d-block">Enter 6-Digit Payment OTP</label>
                    <input type="text" class="form-control form-control-lg text-center font-monospace fw-bold"
                           id="otp_code" name="otp_code" maxlength="6" pattern="\d{6}" placeholder="000000" inputmode="numeric" autocomplete="one-time-code" required autofocus
                           style="font-size: 1.8rem; letter-spacing: 0.4em;">
                </div>

                <button type="submit" class="btn btn-primary btn-lg w-100 rounded-pill shadow-sm mb-3 d-flex align-items-center justify-content-center gap-2">
                    <i class="bi bi-lock-fill"></i>
                    <span>Authorize &amp; Pay <?= money($pending['amount']) ?></span>
                </button>
            </form>

            <div class="text-center pt-3 border-top d-flex justify-content-between align-items-center small">
                <a href="<?= url('passenger/verify_payment.php?booking_id=' . $bookingId . '&resend=1') ?>" class="text-primary fw-semibold text-decoration-none">
                    <i class="bi bi-arrow-repeat me-1"></i> Resend Code
                </a>
                <a href="<?= url('passenger/seat_selection.php?trip_id=' . (int)$booking['trip_id']) ?>" class="text-muted text-decoration-none">
                    <i class="bi bi-x-circle me-1"></i> Cancel Payment
                </a>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
