<?php
/**
 * includes/functions.php
 * Small helper functions used by every page.
 */

require_once __DIR__ . '/../config/database.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'path'     => '/',
        'httponly' => true,    // JavaScript cannot read the session cookie
        'samesite' => 'Lax',
    ]);
    session_start();
}

/** Escape text before printing it in HTML (prevents XSS). Use e() on EVERY value you echo. */
function e($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** Build a link inside the project: url('auth/login.php') */
function url(string $path = ''): string
{
    return BASE_URL . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . url($path));
    exit;
}

/* ---------- CSRF protection ---------- */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function csrf_verify(): bool
{
    $sent = $_POST['csrf_token'] ?? '';
    return is_string($sent) && hash_equals($_SESSION['csrf_token'] ?? '', $sent);
}

/* ---------- One-time messages ("flash") ---------- */
function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $flash;
}

/* ---------- Formatting ---------- */
function money($amount): string
{
    return '₱' . number_format((float)$amount, 2);
}

function fmt_datetime(?string $value): string
{
    return $value ? date('M j, Y g:i A', strtotime($value)) : '—';
}

function fmt_date(?string $value): string
{
    return $value ? date('M j, Y', strtotime($value)) : '—';
}

function fmt_time(?string $value): string
{
    return $value ? date('g:i A', strtotime($value)) : '—';
}

function online_payment_option_label(?string $option): string
{
    return [
        'GCASH' => 'GCash',
        'MAYA' => 'Maya',
        'MARIBANK' => 'MariBank',
        'BANK_TRANSFER' => 'Online Bank Transfer (BPI / UnionBank)',
    ][$option ?? ''] ?? 'Not specified';
}

/**
 * Disburse automated instant refund via Simulated Payment Gateway API (GCash/Maya/Bank API).
 * Automatically generates external gateway transaction tracking ID and timestamp.
 */
function process_automated_api_refund(PDO $pdo, int $paymentId, ?string $destination = null): array
{
    $stmt = $pdo->prepare(
        "SELECT p.payment_id, p.amount, p.booking_id, p.payment_status, p.payment_method,
                bk.booking_reference, bk.user_id,
                u.first_name, u.last_name, u.email, u.phone_number
         FROM payments p
         JOIN bookings bk ON bk.booking_id = p.booking_id
         JOIN users u ON u.user_id = bk.user_id
         WHERE p.payment_id = :id FOR UPDATE"
    );
    $stmt->execute([':id' => $paymentId]);
    $payment = $stmt->fetch();

    if (!$payment) {
        throw new RuntimeException('Payment record not found.');
    }

    if ($payment['payment_status'] === 'REFUNDED') {
        throw new RuntimeException('This payment has already been refunded.');
    }

    $passengerName = trim($payment['first_name'] . ' ' . $payment['last_name']);
    $accountNumber = $destination ?: ($payment['phone_number'] ?: '09171234567');

    // Generate automated Payment Gateway API Transaction & Trace ID
    $gatewayRefNo = 'TXN-API-' . date('Ymd') . '-' . strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 8));

    // Update payment record in database directly via API simulation
    $pdo->prepare(
        "UPDATE payments
         SET payment_status = 'REFUNDED',
             refund_payment_option = 'GCASH',
             refund_account_name = :acct_name,
             refund_account_number = :acct_num,
             refund_transfer_reference = :ref_no,
             refund_processed_at = NOW(),
             payment_date = NOW()
         WHERE payment_id = :id"
    )->execute([
        ':acct_name' => $passengerName,
        ':acct_num'  => $accountNumber,
        ':ref_no'    => $gatewayRefNo,
        ':id'        => $paymentId,
    ]);

    // Update booking cancellation choice status
    $pdo->prepare("UPDATE bookings SET cancellation_choice = 'REFUND_REQUESTED' WHERE booking_id = :bid")
        ->execute([':bid' => $payment['booking_id']]);

    // Send instant notification to passenger
    send_user_notification(
        $pdo,
        (int)$payment['user_id'],
        "Refund Processed Directly ({$gatewayRefNo})",
        "A full automated refund of " . money($payment['amount']) . " for booking {$payment['booking_reference']} has been sent directly to your account ({$accountNumber}) via payment gateway. Trace ID: {$gatewayRefNo}.",
        "passenger/booking_confirmation.php?booking_id=" . (int)$payment['booking_id'],
        $payment['email'] ?? null
    );

    return [
        'success'      => true,
        'reference_no' => $gatewayRefNo,
        'amount'       => (float)$payment['amount'],
        'passenger'    => $passengerName,
        'account'      => $accountNumber,
        'booking_ref'  => $payment['booking_reference'],
    ];
}

/** Keep what the user typed in a form after an error. */
function old(string $key, string $default = ''): string
{
    return e($_POST[$key] ?? $default);
}

/** Colored status label (Modern status badge with icon and dot). */
function status_badge(string $status): string
{
    $map = [
        'PENDING'     => ['warning', 'bi-clock-history'],
        'CONFIRMED'   => ['success', 'bi-check-circle-fill'],
        'CANCELLED'   => ['secondary', 'bi-x-circle-fill'],
        'PAID'        => ['success', 'bi-cash-stack'],
        'FAILED'      => ['danger', 'bi-exclamation-octagon-fill'],
        'REFUNDED'    => ['info', 'bi-arrow-counterclockwise'],
        'SCHEDULED'   => ['primary', 'bi-calendar2-check-fill'],
        'SOLD OUT'    => ['danger', 'bi-x-circle-fill'],
        'COMPLETED'   => ['success', 'bi-flag-fill'],
        'ACTIVE'      => ['success', 'bi-check2-circle'],
        'INACTIVE'    => ['secondary', 'bi-dash-circle'],
        'MAINTENANCE' => ['warning', 'bi-tools'],
    ];
    $cfg = $map[$status] ?? ['secondary', 'bi-record-fill'];
    $variant = $cfg[0];
    $icon = $cfg[1];
    $label = e(ucfirst(strtolower(str_replace('_', ' ', $status))));
    return '<span class="status-badge status-' . strtolower($status) . ' status-variant-' . $variant . '"><span class="status-dot"></span><i class="bi ' . $icon . '"></i>' . $label . '</span>';
}

/** Discount rate for Students, Senior Citizens, and PWDs (20% mandatory discount under Philippine Law). */
const DISCOUNT_RATE_PERCENT = 20;

function passenger_type_badge(string $type): string
{
    $type = strtoupper($type);
    return match ($type) {
        'STUDENT' => '<span class="badge bg-info-subtle text-info border border-info-subtle px-2 py-1 rounded-pill small"><i class="bi bi-mortarboard-fill me-1"></i>Student (20% off)</span>',
        'SENIOR'  => '<span class="badge bg-warning-subtle text-dark border border-warning-subtle px-2 py-1 rounded-pill small"><i class="bi bi-person-heart me-1"></i>Senior Citizen (20% off)</span>',
        'PWD'     => '<span class="badge bg-primary-subtle text-primary border border-primary-subtle px-2 py-1 rounded-pill small"><i class="bi bi-universal-access me-1"></i>PWD (20% off)</span>',
        default   => '<span class="badge bg-light text-dark border px-2 py-1 rounded-pill small">Regular</span>',
    };
}

function passenger_type_label(string $type): string
{
    $type = strtoupper($type);
    return match ($type) {
        'STUDENT' => 'Student (20% Discount)',
        'SENIOR'  => 'Senior Citizen (20% Discount)',
        'PWD'     => 'PWD (20% Discount)',
        default   => 'Regular (Standard Fare)',
    };
}

/**
 * Generates and stores a 6-digit OTP for Email or Mobile Number delivery.
 * Supports passenger channel selection ('EMAIL' or 'SMS').
 */
function create_and_dispatch_otp(PDO $pdo, string $email, string $phone, string $purpose, ?int $userId = null, ?int $referenceId = null, string $channel = 'EMAIL'): array
{
    $channel = strtoupper($channel) === 'SMS' ? 'SMS' : 'EMAIL';

    // Generate secure 6-digit OTP
    $code = sprintf('%06d', random_int(100000, 999999));
    $expiresAt = date('Y-m-d H:i:s', time() + 600); // 10 minutes validity

    // Invalidate prior unused OTPs for this email and purpose
    $inv = $pdo->prepare('UPDATE user_otps SET is_used = 1 WHERE email = :email AND purpose = :purpose AND is_used = 0');
    $inv->execute([':email' => $email, ':purpose' => $purpose]);

    // Save newly generated OTP
    $ins = $pdo->prepare(
        'INSERT INTO user_otps (user_id, email, otp_code, purpose, reference_id, expires_at, is_used)
         VALUES (:uid, :email, :code, :purpose, :ref, :expires, 0)'
    );
    $ins->execute([
        ':uid'     => $userId,
        ':email'   => $email,
        ':code'    => $code,
        ':purpose' => $purpose,
        ':ref'     => $referenceId,
        ':expires' => $expiresAt
    ]);

    // Dispatch via chosen channel
    if ($channel === 'EMAIL') {
        $subject = $purpose === 'REGISTRATION' ? 'Your BusTicket Registration OTP' : ($purpose === 'LOGIN' ? 'Your BusTicket Sign-In OTP' : 'BusTicket Payment Verification OTP');
        $message = "Hello,\r\n\r\nYour One-Time Password (OTP) is: $code\r\n\r\nThis code is valid for 10 minutes.\r\nIf you did not request this code, please ignore this email.\r\n\r\nThank you,\r\nBusTicket Reservation System";
        $headers = "From: noreply@busticket.ph\r\nReply-To: support@busticket.ph\r\nX-Mailer: PHP/" . phpversion();
        @mail($email, $subject, $message, $headers);
    } else {
        // SMS Gateway dispatch simulation (logs or SMS API)
        error_log("SMS OTP dispatched to {$phone}: {$code} (Valid for 10 mins)");
    }

    // Mask phone and email for display
    $maskedEmail = preg_replace('/(?<=.{2}).(?=.*@)/u', '*', $email);
    $maskedPhone = strlen($phone) >= 7 ? substr($phone, 0, 4) . '***' . substr($phone, -3) : $phone;

    return [
        'code'         => $code,
        'expires_at'   => $expiresAt,
        'channel'      => $channel,
        'masked_email' => $maskedEmail,
        'masked_phone' => $maskedPhone,
    ];
}

/**
 * Validates an OTP entered by the user.
 */
function verify_otp_code(PDO $pdo, string $email, string $code, string $purpose): bool
{
    $stmt = $pdo->prepare(
        'SELECT otp_id, expires_at FROM user_otps
         WHERE email = :email AND otp_code = :code AND purpose = :purpose AND is_used = 0
         ORDER BY otp_id DESC LIMIT 1'
    );
    $stmt->execute([':email' => $email, ':code' => trim($code), ':purpose' => $purpose]);
    $row = $stmt->fetch();

    if (!$row) {
        return false;
    }

    if (strtotime($row['expires_at']) < time()) {
        return false;
    }

    // Mark as used
    $pdo->prepare('UPDATE user_otps SET is_used = 1 WHERE otp_id = :id')
        ->execute([':id' => $row['otp_id']]);

    return true;
}

/**
 * Send an in-app notification (and optional email) to a user.
 */
function send_user_notification(PDO $pdo, int $userId, string $title, string $message, ?string $link = null, ?string $userEmail = null): void
{
    try {
        $stmt = $pdo->prepare(
            "INSERT INTO notifications (user_id, title, message, link, created_at)
             VALUES (:uid, :title, :msg, :link, NOW())"
        );
        $stmt->execute([
            ':uid'   => $userId,
            ':title' => mb_substr($title, 0, 120),
            ':msg'   => mb_substr($message, 0, 500),
            ':link'  => $link ? mb_substr($link, 0, 255) : null
        ]);

        if ($userEmail) {
            $subject = 'BusTicket Alert: ' . $title;
            $emailBody = "Hello,\r\n\r\n" . $message . "\r\n\r\n";
            if ($link) {
                $emailBody .= "View details: " . url($link) . "\r\n\r\n";
            }
            $emailBody .= "Thank you,\r\n" . APP_NAME . " Express";
            $headers = "From: noreply@busticket.ph\r\nReply-To: support@busticket.ph\r\nX-Mailer: PHP/" . phpversion();
            @mail($userEmail, $subject, $emailBody, $headers);
        }
    } catch (Throwable $e) {
        error_log('send_user_notification error: ' . $e->getMessage());
    }
}

/**
 * Returns the relative image path corresponding to a bus type.
 */
function bus_type_image(string $busType): string
{
    $type = strtolower($busType);
    if (str_contains($type, 'super deluxe') || str_contains($type, 'sleeper') || str_contains($type, 'lazy boy')) {
        return 'assets/images/buses/super-deluxe.jpg';
    }
    if (str_contains($type, 'deluxe') || str_contains($type, 'first class')) {
        return 'assets/images/buses/deluxe.jpg';
    }
    if (str_contains($type, 'ordinary') || str_contains($type, 'non-aircon')) {
        return 'assets/images/buses/ordinary.jpg';
    }
    if (str_contains($type, 'double-decker') || str_contains($type, 'double decker')) {
        return 'assets/images/buses/double-decker.jpg';
    }
    // Default / Standard Regular / P2P / Provincial
    return 'assets/images/buses/standard.jpg';
}

