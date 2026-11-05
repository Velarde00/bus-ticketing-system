<?php
/**
 * includes/auth.php
 * Login checks and role-based access control.
 * Put this at the top of any page:  require_once __DIR__ . '/../includes/auth.php';
 */

require_once __DIR__ . '/functions.php';

function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

function current_user(): ?array
{
    if (!is_logged_in()) {
        return null;
    }
    return [
        'id'   => (int)$_SESSION['user_id'],
        'name' => $_SESSION['user_name'] ?? '',
        'role' => $_SESSION['role'] ?? '',
    ];
}

/** Send visitors who are not logged in to the login page. */
function require_login(): void
{
    global $pdo;

    if (!is_logged_in()) {
        set_flash('warning', 'Please log in to continue.');
        redirect('auth/login.php');
    }

    // Re-check the database so a deactivated account is signed out immediately.
    $stmt = $pdo->prepare('SELECT role, account_status FROM users WHERE user_id = :id');
    $stmt->execute([':id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();

    if (!$user || $user['account_status'] !== 'ACTIVE') {
        $_SESSION = [];
        session_destroy();
        session_start();
        set_flash('danger', 'Your account is no longer active.');
        redirect('auth/login.php');
    }
    $_SESSION['role'] = $user['role'];
}

/** Allow a role to open a page. Super admins inherit regular admin access. */
function require_role(string $role): void
{
    require_login();
    $currentRole = $_SESSION['role'];
    $allowed = $currentRole === $role || ($role === 'ADMIN' && $currentRole === 'SUPER_ADMIN');
    if (!$allowed) {
        set_flash('danger', 'You do not have permission to open that page.');
        redirect(in_array($currentRole, ['ADMIN', 'SUPER_ADMIN'], true) ? 'admin/dashboard.php' : 'passenger/dashboard.php');
    }
}
