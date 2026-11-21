<?php
/**
 * auth/register.php
 * Redirects to sliding login/register panel with register tab open.
 */
require_once __DIR__ . '/../includes/auth.php';

if (is_logged_in()) {
    redirect('passenger/dashboard.php');
}

redirect('auth/login.php?tab=register');
