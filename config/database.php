<?php
/**
 * config/database.php
 * Connects PHP to MySQL using PDO. Every page loads this (through includes/functions.php).
 * Default XAMPP login: user "root" with an empty password.
 */

date_default_timezone_set('Asia/Manila');

// The folder name inside htdocs. All links in the system are built from this.
define('BASE_URL', '/bus-ticketing-system');
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
