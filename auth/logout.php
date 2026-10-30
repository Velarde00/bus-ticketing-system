<?php
/**
 * auth/logout.php
 * Logs out. Only accepts a POST with a valid CSRF token (the Log out buttons send one).
 */
require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !csrf_verify()) {
    redirect('index.php');
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

session_start();            // fresh session just to show the message
session_regenerate_id(true);
set_flash('success', 'You have been logged out.');
redirect('auth/login.php');
