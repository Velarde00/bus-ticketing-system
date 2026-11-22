<?php
/**
 * passenger/verify_payment.php
 * E-Wallet (GCash / Maya) Online Payment Gateway with Merchant QR & Official Account Number.
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

$selectedWallet = strtoupper($booking['online_payment_option'] ?: 'GCASH');
if (isset($_GET['wallet']) && in_array(strtoupper($_GET['wallet']), ['GCASH', 'MAYA'], true)) {
    $selectedWallet = strtoupper($_GET['wallet']);
}

// Merchant details for display
$merchantAccounts = [
    'GCASH' => [
        'name'        => 'BusTicket Express Philippines Inc.',
        'account_num' => '0917-889-2878',
        'raw_num'     => '09178892878',
        'badge_color' => '#007DFE',
        'label'       => 'GCash Merchant QR',
        'icon'        => 'bi-phone',
    ],
    'MAYA' => [
        'name'        => 'BusTicket Express Philippines Inc.',
        'account_num' => '0998-552-3210',
        'raw_num'     => '09985523210',
        'badge_color' => '#059669',
        'label'       => 'Maya Merchant QR',
        'icon'        => 'bi-credit-card',
    ],
];
$merchant = $merchantAccounts[$selectedWallet] ?? $merchantAccounts['GCASH'];

// Build dynamic QR string (QR code payload)
$qrPayload = "PAYTO:{$selectedWallet};ACCOUNT:{$merchant['raw_num']};NAME:" . rawurlencode($merchant['name']) . ";AMOUNT:" . number_format((float)$booking['amount'], 2, '.', '') . ";REF:{$booking['booking_reference']}";
$qrImageUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=250x250&margin=10&data=' . urlencode($qrPayload);

// Generate / ensure an official payment session reference
if (empty($_SESSION['pending_payment_session']) || (int)($_SESSION['pending_payment_session']['booking_id'] ?? 0) !== $bookingId) {
    $_SESSION['pending_payment_session'] = [
        'booking_id'    => $bookingId,
        'wallet'        => $selectedWallet,
        'amount'        => (float)$booking['amount'],
        'reference'     => $booking['booking_reference'],
    ];
}

$errors = [];

// Handle Confirm Payment (Simulated instant QR scan verification)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        $errors[] = 'Your session expired. Please try again.';
    } else {
        try {
            $pdo->beginTransaction();

            // 1. Confirm booking
            $pdo->prepare("UPDATE bookings SET status = 'CONFIRMED' WHERE booking_id = :id")
                ->execute([':id' => $bookingId]);

            // 2. Mark payment PAID with transaction reference
            $transferRef = $selectedWallet . '-QR-' . strtoupper(bin2hex(random_bytes(5)));
            $pdo->prepare(
                "UPDATE payments SET payment_status = 'PAID', online_payment_option = :wallet, transfer_reference = :ref, transfer_submitted_at = NOW()
                 WHERE booking_id = :id"
            )->execute([
                ':wallet' => $selectedWallet,
                ':ref'    => $transferRef,
                ':id'     => $bookingId,
            ]);

            // 3. Issue tickets for all booked seats
            $seatsStmt = $pdo->prepare("SELECT booking_seat_id FROM booking_seats WHERE booking_id = :id AND seat_status = 'BOOKED'");
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
                ':msg'   => 'Your online payment for booking ' . $booking['booking_reference'] . ' via ' . $selectedWallet . ' QR has been confirmed.',
                ':link'  => 'passenger/booking_confirmation.php?booking_id=' . $bookingId
            ]);

            $pdo->commit();
            unset($_SESSION['pending_payment_session']);
            unset($_SESSION['pending_payment_otp']);

            set_flash('success', 'Payment verified successfully! Your tickets and boarding pass are ready.');
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

$pageTitle = 'Scan & Pay';
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
                <span class="small fw-semibold letter-spacing-sm">OFFICIAL MERCHANT PAYMENT</span>
            </div>
            <h1 class="h4 fw-bold mb-1 text-white">Scan &amp; Pay via <?= e($selectedWallet) ?></h1>
            <p class="small text-white-50 mb-0">Booking Ref: <strong class="text-white font-monospace"><?= e($booking['booking_reference']) ?></strong></p>
        </div>

        <div class="card-body p-4 p-sm-5 text-center">
            <!-- Wallet Switcher Tabs -->
            <div class="d-flex justify-content-center gap-2 mb-4">
                <a href="<?= url('passenger/verify_payment.php?booking_id=' . (int)$bookingId . '&wallet=GCASH') ?>"
                   class="btn btn-sm px-4 py-2 rounded-pill fw-semibold d-flex align-items-center gap-2 <?= $selectedWallet === 'GCASH' ? 'btn-primary' : 'btn-outline-secondary' ?>">
                    <i class="bi bi-phone"></i> GCash
                </a>
                <a href="<?= url('passenger/verify_payment.php?booking_id=' . (int)$bookingId . '&wallet=MAYA') ?>"
                   class="btn btn-sm px-4 py-2 rounded-pill fw-semibold d-flex align-items-center gap-2 <?= $selectedWallet === 'MAYA' ? 'btn-success' : 'btn-outline-secondary' ?>">
                    <i class="bi bi-credit-card"></i> Maya
                </a>
            </div>

            <!-- Amount Due Banner -->
            <div class="p-3 bg-light rounded-4 border mb-4 text-start">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-muted small"><i class="bi bi-geo-alt-fill text-primary me-1"></i>Trip Route:</span>
                    <strong class="text-dark small"><?= e($booking['origin']) ?> &rarr; <?= e($booking['destination']) ?></strong>
                </div>
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="text-muted small"><i class="bi bi-bus-front text-primary me-1"></i>Departure:</span>
                    <span class="small text-dark fw-medium"><?= e(fmt_datetime($booking['departure_time'])) ?></span>
                </div>
                <div class="d-flex justify-content-between align-items-baseline pt-2 border-top">
                    <span class="fw-bold text-dark">Total Amount Due:</span>
                    <span class="fw-bold fs-3 text-primary"><?= money($booking['amount']) ?></span>
                </div>
            </div>

            <!-- QR Code Display Box -->
            <div class="qr-container-box p-4 bg-white rounded-4 border shadow-sm d-inline-block mx-auto mb-3" style="max-width: 320px;">
                <div class="text-center mb-2">
                    <span class="badge bg-light text-dark border px-3 py-1 rounded-pill small fw-semibold">
                        <i class="bi bi-qr-code-scan text-primary me-1"></i> Scan with your <?= e($selectedWallet) ?> App
                    </span>
                </div>
                <div class="p-2 border rounded-3 bg-light d-inline-block shadow-xs">
                    <img src="<?= e($qrImageUrl) ?>" alt="Merchant QR Code" class="img-fluid rounded" style="width: 210px; height: 210px; object-fit: contain;">
                </div>
                <div class="small text-muted mt-2">
                    Or send payment to Merchant Number below:
                </div>
            </div>

            <!-- Merchant Number & Account Details -->
            <div class="p-3 bg-light rounded-3 border mb-4 text-start">
                <div class="d-flex justify-content-between align-items-center mb-1">
                    <span class="text-muted small">Merchant Account Name:</span>
                    <strong class="text-dark small"><?= e($merchant['name']) ?></strong>
                </div>
                <div class="d-flex justify-content-between align-items-center">
                    <span class="text-muted small"><?= e($selectedWallet) ?> Mobile Number:</span>
                    <div class="d-flex align-items-center gap-1">
                        <strong class="text-primary font-monospace fs-6"><?= e($merchant['account_num']) ?></strong>
                        <button type="button" class="btn btn-outline-secondary btn-sm p-0 px-2 py-1 rounded-pill" style="font-size: 0.7rem;"
                                onclick="navigator.clipboard.writeText('<?= e($merchant['raw_num']) ?>'); this.textContent = 'Copied!'; setTimeout(() => this.textContent = 'Copy', 1500);" title="Copy Number">
                            Copy
                        </button>
                    </div>
                </div>
            </div>

            <?php foreach ($errors as $error): ?>
                <div class="alert alert-danger py-2 px-3 d-flex align-items-center gap-2 border-0 shadow-sm rounded-3 small mb-3 text-start">
                    <i class="bi bi-exclamation-circle-fill text-danger flex-shrink-0"></i>
                    <div><?= e($error) ?></div>
                </div>
            <?php endforeach; ?>

            <!-- Complete Payment Action -->
            <form method="post" id="qrPaymentForm">
                <?= csrf_field() ?>
                <button type="submit" class="btn btn-primary btn-lg w-100 rounded-pill shadow-sm py-3 fw-bold d-flex align-items-center justify-content-center gap-2 mb-3">
                    <span>Proceed</span>
                    <i class="bi bi-arrow-right"></i>
                </button>
            </form>

            <div class="text-center pt-2 border-top d-flex justify-content-between align-items-center small">
                <span class="text-muted" style="font-size:0.75rem;">
                    <i class="bi bi-shield-check text-success me-1"></i> Instant automated verification
                </span>
                <a href="<?= url('passenger/seat_selection.php?trip_id=' . (int)$booking['trip_id']) ?>" class="text-muted text-decoration-none d-inline-flex align-items-center gap-1">
                    <i class="bi bi-x-circle"></i> Cancel Payment
                </a>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../includes/footer.php'; ?>
