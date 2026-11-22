<?php
/**
 * auth/register.php
 * Redirects to the sliding login/register portal with register panel open.
 */
require_once __DIR__ . '/../includes/auth.php';

if (is_logged_in()) {
    redirect(in_array($_SESSION['role'], ['ADMIN', 'SUPER_ADMIN'], true) ? 'admin/dashboard.php' : 'passenger/dashboard.php');
}

redirect('auth/login.php?tab=register');
