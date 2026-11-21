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
 * Generates and stores a 6-digit OTP for Email & Mobile Number delivery.
 * Supports mail() sending and local inbox delivery.
 */
function create_and_dispatch_otp(PDO $pdo, string $email, string $phone, string $purpose, ?int $userId = null, ?int $referenceId = null): array
{
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

    // Dispatch via email
    $subject = $purpose === 'LOGIN' ? 'Your BusTicket Sign-In OTP' : 'BusTicket Payment Verification OTP';
    $message = "Hello,\r\n\r\nYour One-Time Password (OTP) is: $code\r\n\r\nThis code is valid for 10 minutes.\r\nIf you did not request this code, please ignore this email.\r\n\r\nThank you,\r\nBusTicket Reservation System";
    $headers = "From: noreply@busticket.ph\r\nReply-To: support@busticket.ph\r\nX-Mailer: PHP/" . phpversion();

    @mail($email, $subject, $message, $headers);

    // Mask phone and email for display
    $maskedEmail = preg_replace('/(?<=.{2}).(?=.*@)/u', '*', $email);
    $maskedPhone = strlen($phone) >= 7 ? substr($phone, 0, 4) . '***' . substr($phone, -3) : $phone;

    return [
        'code'         => $code,
        'expires_at'   => $expiresAt,
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

