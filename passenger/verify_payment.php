<?php
/**
 * passenger/verify_payment.php
 * Premium E-Wallet (GCash / Maya) Online Payment OTP Verification Gateway.
 */
require_once __DIR__ . '/../includes/auth.php';
require_role('PASSENGER');

$uid = (int)$_SESSION['user_id'];
$bookingId = filter_var($_GET['booking_id'] ?? ($_SESSION['pending_payment_otp']['booking_id'] ?? null), FILTER_VALIDATE_INT);

if (!$bookingId) {
    redirect('passenger/my_bookings.php');
}

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
    set_flash('success', 'A fresh payment authorization code has been dispatched.');
    redirect('passenger/verify_payment.php?booking_id=' . $bookingId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        $digitArr = $_POST['otp_digits'] ?? [];
        if (is_array($digitArr) && count($digitArr) === 6) {
            $enteredCode = implode('', $digitArr);
        } else {
            $enteredCode = trim($_POST['otp_code'] ?? '');
        }

        if ($enteredCode === '' || strlen($enteredCode) !== 6 || !ctype_digit($enteredCode)) {
            $errors[] = 'Please enter all 6 digits of the payment authorization code.';
        } else {
            $isValid = verify_otp_code($pdo, $userEmail, $enteredCode, 'PAYMENT');

            if (!$isValid) {
                $errors[] = 'The authorization code is invalid or has expired. Please try again.';
            } else {
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
                        $chk = $pdo->prepare('SELECT ticket_id FROM tickets WHERE booking_seat_id = :seat');
                        $chk->execute([':seat' => $seatId]);
                        if (!$chk->fetchColumn()) {
                            $ticketInsert->execute([':seat' => $seatId, ':number' => 'TMP-' . bin2hex(random_bytes(8))]);
                            $tId = (int)$pdo->lastInsertId();
                            $ticketUpdate->execute([':number' => sprintf('TKT-%s-%06d', date('Y'), $tId), ':id' => $tId]);
                        }
                    }

                    // 4. Send notification
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
    <!-- Modern Gateway Card -->
    <div class="card border-0 shadow-lg rounded-4 overflow-hidden mb-4">
        <!-- Gateway Header with Brand Colors -->
        <div class="p-4 text-white text-center" style="background: linear-gradient(135deg, #0E7C66 0%, #095C4C 100%);">
            <div class="gateway-security-badge d-inline-flex align-items-center gap-2 px-3 py-1 rounded-pill mb-2">
                <i class="bi bi-shield-lock-fill text-warning"></i>
                <span class="small fw-semibold letter-spacing-sm">256-BIT ENCRYPTED GATEWAY</span>
            </div>
            <h1 class="h4 fw-bold mb-1 text-white">Verify Your Online Payment</h1>
            <p class="small text-white-50 mb-0">Booking Reference: <strong class="text-white font-monospace"><?= e($booking['booking_reference']) ?></strong></p>
        </div>

        <div class="card-body p-4 p-sm-5">
            <!-- Payment breakdown card -->
            <div class="p-3 bg-light rounded-4 border mb-4">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted small"><i class="bi bi-geo-alt-fill text-primary me-1"></i>Trip Route:</span>
                    <strong class="text-dark small"><?= e($booking['origin']) ?> &rarr; <?= e($booking['destination']) ?></strong>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted small"><i class="bi bi-wallet2 text-primary me-1"></i>Selected Wallet:</span>
                    <?php if ($pending['wallet'] === 'GCASH'): ?>
                        <span class="badge bg-primary text-white rounded-pill px-3 py-1"><i class="bi bi-phone me-1"></i>GCash</span>
                    <?php else: ?>
                        <span class="badge bg-success text-white rounded-pill px-3 py-1"><i class="bi bi-credit-card me-1"></i>Maya</span>
                    <?php endif; ?>
                </div>
                <div class="d-flex justify-content-between align-items-baseline pt-2 border-top">
                    <span class="fw-bold text-dark">Total Amount Due:</span>
                    <span class="fw-bold fs-3 text-primary"><?= money($pending['amount']) ?></span>
                </div>
            </div>

            <!-- Target Phone / Email Info -->
            <div class="p-3 bg-light bg-opacity-75 rounded-3 border mb-4">
                <div class="d-flex justify-content-between align-items-center text-muted small mb-1">
                    <span><i class="bi bi-envelope text-primary me-1"></i>Sent to Email:</span>
                    <span class="fw-semibold text-dark font-monospace"><?= e($pending['masked_email']) ?></span>
                </div>
                <div class="d-flex justify-content-between align-items-center text-muted small">
                    <span><i class="bi bi-phone text-primary me-1"></i>Sent to Mobile:</span>
                    <span class="fw-semibold text-dark font-monospace"><?= e($pending['masked_phone']) ?></span>
                </div>
            </div>

            <!-- Demo Helper Banner -->
            <div class="alert alert-info border-0 shadow-sm rounded-4 py-3 px-3 small d-flex align-items-center gap-3 mb-4" style="background-color: #f0f9ff; border-left: 4px solid #0284c7 !important;">
                <div class="p-2 bg-white rounded-circle shadow-sm text-info d-flex align-items-center justify-content-center flex-shrink-0" style="width: 38px; height: 38px;">
                    <i class="bi bi-key-fill fs-5"></i>
                </div>
                <div class="flex-grow-1">
                    <div class="fw-semibold text-dark">Authorization Code:</div>
                    <div class="text-muted">Your payment code is <strong class="badge bg-primary text-white fs-6 px-2 py-1 font-monospace ms-1"><?= e($pending['code']) ?></strong></div>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3 shadow-none" onclick="fillPaymentOtp('<?= e($pending['code']) ?>')">
                    Autofill
                </button>
            </div>

            <?php foreach ($errors as $error): ?>
                <div class="alert alert-danger py-2 px-3 d-flex align-items-center gap-2 border-0 shadow-sm rounded-3 small mb-3">
                    <i class="bi bi-exclamation-circle-fill text-danger flex-shrink-0"></i>
                    <div><?= e($error) ?></div>
                </div>
            <?php endforeach; ?>

            <form method="post" id="paymentOtpForm" novalidate>
                <?= csrf_field() ?>
                <input type="hidden" name="otp_code" id="hiddenPaymentOtpInput">

                <div class="mb-4">
                    <label class="form-label small fw-semibold text-center d-block mb-3 text-secondary">
                        ENTER 6-DIGIT PAYMENT AUTHORIZATION CODE
                    </label>
                    <div class="d-flex justify-content-between gap-2 mb-2" id="paymentOtpBoxes">
                        <?php for ($i = 0; $i < 6; $i++): ?>
                            <input type="text"
                                   class="form-control text-center font-monospace fw-bold rounded-3 payment-otp-box"
                                   name="otp_digits[<?= $i ?>]"
                                   id="pdigit-<?= $i ?>"
                                   maxlength="1"
                                   inputmode="numeric"
                                   pattern="[0-9]"
                                   autocomplete="off"
                                   style="font-size: 1.6rem; height: 58px; padding: 0; box-shadow: 0 1px 3px rgba(0,0,0,0.05);"
                                   <?= $i === 0 ? 'autofocus' : '' ?>>
                        <?php endfor; ?>
                    </div>
                    <div class="text-center">
                        <span class="text-muted small" style="font-size: 0.75rem;"><i class="bi bi-shield-check text-success me-1"></i> Validated by BusTicket E-Wallet Security</span>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary btn-lg w-100 rounded-pill shadow-sm py-3 fw-bold d-flex align-items-center justify-content-center gap-2 mb-3">
                    <i class="bi bi-lock-fill"></i>
                    <span>Authorize &amp; Pay <?= money($pending['amount']) ?></span>
                </button>
            </form>

            <div class="text-center pt-3 border-top d-flex justify-content-between align-items-center small">
                <a href="<?= url('passenger/verify_payment.php?booking_id=' . $bookingId . '&resend=1') ?>" class="text-primary fw-semibold text-decoration-none d-inline-flex align-items-center gap-1">
                    <i class="bi bi-arrow-repeat"></i> Resend Code
                </a>
                <a href="<?= url('passenger/seat_selection.php?trip_id=' . (int)$booking['trip_id']) ?>" class="text-muted text-decoration-none d-inline-flex align-items-center gap-1">
                    <i class="bi bi-x-circle"></i> Cancel Payment
                </a>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    const boxes = document.querySelectorAll('.payment-otp-box');
    const hiddenInput = document.getElementById('hiddenPaymentOtpInput');
    const form = document.getElementById('paymentOtpForm');

    function syncHiddenInput() {
        let code = '';
        boxes.forEach(b => code += b.value);
        hiddenInput.value = code;
    }

    window.fillPaymentOtp = function(code) {
        if (!code || code.length !== 6) return;
        boxes.forEach((box, idx) => {
            box.value = code[idx] || '';
        });
        syncHiddenInput();
        boxes[5].focus();
    };

    boxes.forEach((box, idx) => {
        box.addEventListener('input', function(e) {
            this.value = this.value.replace(/[^0-9]/g, '');
            if (this.value.length >= 1) {
                this.value = this.value[0];
                if (idx < 5) {
                    boxes[idx + 1].focus();
                    boxes[idx + 1].select();
                }
            }
            syncHiddenInput();
        });

        box.addEventListener('keydown', function(e) {
            if (e.key === 'Backspace' && !this.value && idx > 0) {
                boxes[idx - 1].focus();
                boxes[idx - 1].value = '';
                syncHiddenInput();
            }
        });

        box.addEventListener('paste', function(e) {
            e.preventDefault();
            const pasteData = (e.clipboardData || window.clipboardData).getData('text').trim().replace(/[^0-9]/g, '');
            if (pasteData) {
                fillPaymentOtp(pasteData.slice(0, 6));
            }
        });
    });

    form.addEventListener('submit', function() {
        syncHiddenInput();
    });
})();
</script>
<?php require __DIR__ . '/../includes/footer.php'; ?>
