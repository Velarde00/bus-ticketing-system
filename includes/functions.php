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
