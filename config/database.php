<?php
/**
 * config/database.php
 * Connects PHP to MySQL using PDO. Every page loads this (through includes/functions.php).
 * Default XAMPP login: user "root" with an empty password.
 */

date_default_timezone_set('Asia/Manila');

// Derive the app URL prefix from the web server's document root so the app
// works both from XAMPP's htdocs folder and when served directly from its root.
$appRoot = realpath(dirname(__DIR__));
$documentRoot = realpath($_SERVER['DOCUMENT_ROOT'] ?? '');
$baseUrl = '/bus-ticketing-system';
if ($appRoot !== false && $documentRoot !== false) {
    $appPath = str_replace('\\', '/', $appRoot);
    $documentPath = rtrim(str_replace('\\', '/', $documentRoot), '/');
    if (strcasecmp($appPath, $documentPath) === 0) {
        $baseUrl = '';
    } elseif (strncasecmp($appPath, $documentPath . '/', strlen($documentPath) + 1) === 0) {
        $baseUrl = substr($appPath, strlen($documentPath));
    }
}
define('BASE_URL', $baseUrl);
define('APP_NAME', 'BusTicket');

$dbHost = 'localhost';
$dbName = 'bus_ticketing_db';
$dbUser = 'root';
$dbPass = '';

try {
    $pdo = new PDO(
        "mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,   // show errors as exceptions
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,         // rows come back as arrays
            PDO::ATTR_EMULATE_PREPARES   => false,                    // real prepared statements
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '+08:00'" // match Philippine time
        ]
    );
} catch (PDOException $e) {
    error_log('DB connection failed: ' . $e->getMessage());
    http_response_code(500);
    die('Database connection failed. Check that MySQL is running in XAMPP and that bus_ticketing_db was imported.');
}
