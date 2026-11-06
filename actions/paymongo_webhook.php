<?php
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$webhookSecret = (string)getenv('PAYMONGO_WEBHOOK_SECRET');
$rawBody = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_PAYMONGO_SIGNATURE'] ?? '';
if ($webhookSecret === '') {
    error_log('PayMongo webhook received but PAYMONGO_WEBHOOK_SECRET is not configured.');
    http_response_code(500);
    echo json_encode(['error' => 'Webhook is not configured']);
    exit;
}
if (!is_string($rawBody) || $rawBody === '' || !is_string($signature)
    || !preg_match('/^[a-f0-9]{64}$/i', $signature)
    || !hash_equals(hash_hmac('sha256', $rawBody, $webhookSecret), strtolower($signature))) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid webhook signature']);
    exit;
}

try {
    $event = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON payload']);
    exit;
}

$eventData = $event['data'] ?? null;
if (!is_array($eventData) || ($eventData['type'] ?? '') !== 'checkout_session.payment.paid') {
    http_response_code(200);
    echo json_encode(['received' => true]);
    exit;
}

$session = $eventData['data'] ?? null;
$attributes = is_array($session) ? ($session['attributes'] ?? null) : null;
$checkoutId = is_array($session) ? ($session['id'] ?? '') : '';
$reference = is_array($attributes) ? ($attributes['reference_number'] ?? '') : '';
if (!is_string($checkoutId) || $checkoutId === '' || !is_string($reference) || $reference === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing checkout session reference']);
    exit;
}

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        "SELECT p.payment_id, p.payment_status, bk.status AS booking_status, bk.booking_reference
         FROM payments p
         JOIN bookings bk ON bk.booking_id = p.booking_id
         WHERE p.paymongo_checkout_id = :checkout_id
           AND p.payment_method = 'ONLINE'
         FOR UPDATE"
    );
    $stmt->execute([':checkout_id' => $checkoutId]);
    $payment = $stmt->fetch();

    if (!$payment || !hash_equals((string)$payment['booking_reference'], $reference)) {
        $pdo->rollBack();
        error_log('PayMongo webhook did not match a saved checkout session.');
        http_response_code(200);
        echo json_encode(['received' => true]);
        exit;
    }

    if ($payment['payment_status'] !== 'CANCELLED' && $payment['booking_status'] !== 'CANCELLED') {
        $pdo->prepare(
            "UPDATE payments
             SET payment_status = 'PAID', payment_date = NOW()
             WHERE payment_id = :id AND payment_status <> 'PAID'"
        )->execute([':id' => $payment['payment_id']]);
    } else {
        error_log('PayMongo reported payment for a cancelled booking or payment.');
    }

    $pdo->commit();
    http_response_code(200);
    echo json_encode(['received' => true]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('PayMongo webhook database error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Could not process payment event']);
}
