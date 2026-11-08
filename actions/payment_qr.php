<?php
require_once __DIR__ . '/../includes/auth.php';
require_login();

if (!in_array($_SESSION['role'] ?? '', ['PASSENGER', 'ADMIN', 'SUPER_ADMIN'], true)) {
    http_response_code(403);
    exit;
}

$option = $_GET['option'] ?? '';
if (!is_string($option) || !in_array($option, ['GCASH', 'MAYA', 'MARIBANK', 'BANK_TRANSFER'], true)) {
    http_response_code(404);
    exit;
}

$stmt = $pdo->prepare(
    'SELECT qr_image_path FROM manual_payment_instructions WHERE payment_option = :option AND is_enabled = 1'
);
$stmt->execute([':option' => $option]);
$filename = $stmt->fetchColumn();
if (!is_string($filename) || $filename === '' || basename($filename) !== $filename) {
    http_response_code(404);
    exit;
}

$path = __DIR__ . '/../uploads/payment_qr/' . $filename;
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = is_file($path) ? $finfo->file($path) : false;
if (!in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true)) {
    http_response_code(404);
    exit;
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
readfile($path);