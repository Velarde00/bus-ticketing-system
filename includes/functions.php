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

function payment_method_label(string $method): string
{
    return [
        'CASH' => 'Cash',
        'E_WALLET' => 'E-wallet',
        'CARD' => 'Card',
        'ONLINE' => 'Online (PayMongo)',
    ][$method] ?? str_replace('_', '-', ucfirst(strtolower($method)));
}

/** Keep what the user typed in a form after an error. */
function old(string $key, string $default = ''): string
{
    return e($_POST[$key] ?? $default);
}

/** Colored status label (Bootstrap badge). */
function status_badge(string $status): string
{
    $map = [
        'PENDING'     => 'warning',
        'CONFIRMED'   => 'success',
        'CANCELLED'   => 'secondary',
        'PAID'        => 'success',
        'FAILED'      => 'danger',
        'REFUNDED'    => 'info',
        'SCHEDULED'   => 'primary',
        'COMPLETED'   => 'success',
        'ACTIVE'      => 'success',
        'INACTIVE'    => 'secondary',
        'MAINTENANCE' => 'warning',
    ];
    $color = $map[$status] ?? 'secondary';
    $text  = in_array($color, ['warning', 'info'], true) ? 'text-dark' : '';
    return '<span class="badge rounded-pill text-bg-' . $color . ' ' . $text . '">' . e(ucfirst(strtolower($status))) . '</span>';
}
