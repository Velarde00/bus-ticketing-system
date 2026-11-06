<?php
require_once __DIR__ . '/../config/database.php';

function paymongo_is_configured(): bool
{
    $publicUrl = getenv('APP_PUBLIC_URL');
    if (!(bool)getenv('PAYMONGO_SECRET_KEY') || !is_string($publicUrl)
        || filter_var($publicUrl, FILTER_VALIDATE_URL) === false) {
        return false;
    }

    $parts = parse_url($publicUrl);
    if (!$parts || empty($parts['host']) || !in_array($parts['scheme'] ?? '', ['https', 'http'], true)
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
        return false;
    }
    return ($parts['scheme'] === 'https')
        || in_array(strtolower($parts['host']), ['localhost', '127.0.0.1', '::1'], true);
}

function paymongo_checkout_url_is_valid(string $url): bool
{
    $parts = parse_url($url);
    return $parts !== false
        && ($parts['scheme'] ?? '') === 'https'
        && strtolower($parts['host'] ?? '') === 'checkout.paymongo.com'
        && !isset($parts['user'])
        && !isset($parts['pass'])
        && !isset($parts['port']);
}

function paymongo_public_url(string $path): string
{
    $baseUrl = rtrim((string)getenv('APP_PUBLIC_URL'), '/');
    $parts = parse_url($baseUrl);
    $localHosts = ['localhost', '127.0.0.1', '::1'];

    if (!$parts || empty($parts['host']) || !in_array($parts['scheme'] ?? '', ['https', 'http'], true)
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
        throw new RuntimeException('APP_PUBLIC_URL must be an absolute HTTP or HTTPS URL.');
    }
    if (($parts['scheme'] !== 'https') && !in_array(strtolower($parts['host']), $localHosts, true)) {
        throw new RuntimeException('APP_PUBLIC_URL must use HTTPS outside local development.');
    }

    return $baseUrl . '/' . ltrim($path, '/');
}

function paymongo_request(string $method, string $endpoint, ?array $payload = null): array
{
    $secretKey = (string)getenv('PAYMONGO_SECRET_KEY');
    if ($secretKey === '') {
        throw new RuntimeException('PayMongo is not configured. Set PAYMONGO_SECRET_KEY.');
    }
    if (!extension_loaded('curl')) {
        throw new RuntimeException('The PHP cURL extension is required for PayMongo checkout.');
    }

    $curl = curl_init('https://api.paymongo.com/v2/' . ltrim($endpoint, '/'));
    if ($curl === false) {
        throw new RuntimeException('Could not initialize the PayMongo request.');
    }

    $options = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $secretKey . ':',
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    ];
    if ($payload !== null) {
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_THROW_ON_ERROR);
    }
    curl_setopt_array($curl, $options);
    $body = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $curlError = curl_error($curl);
    curl_close($curl);

    if ($body === false) {
        error_log('PayMongo request failed: ' . $curlError);
        throw new RuntimeException('Could not connect to PayMongo. Please try again later.');
    }

    $result = json_decode($body, true);
    if (!is_array($result)) {
        error_log('PayMongo returned invalid JSON with HTTP status ' . $status . '.');
        throw new RuntimeException('PayMongo returned an invalid response. Please try again later.');
    }
    if ($status < 200 || $status >= 300) {
        $providerMessage = $result['errors'][0]['detail'] ?? 'Unknown provider error';
        if (!is_string($providerMessage)) {
            $providerMessage = 'Unknown provider error';
        }
        error_log('PayMongo returned HTTP ' . $status . ': ' . $providerMessage);
        throw new RuntimeException('PayMongo could not start checkout. Check the payment configuration and try again.');
    }

    return $result;
}

function paymongo_create_checkout(array $payment, string $bookingReference, int $bookingId): array
{
    if (!paymongo_is_configured()) {
        throw new RuntimeException('Online checkout is not configured. Set PAYMONGO_SECRET_KEY and APP_PUBLIC_URL.');
    }

    $successUrl = paymongo_public_url('actions/paymongo_return.php?booking_id=' . $bookingId . '&result=success');
    $cancelUrl = paymongo_public_url('actions/paymongo_return.php?booking_id=' . $bookingId . '&result=cancelled');
    $amount = (int)round((float)$payment['amount'] * 100);
    if ($amount < 1) {
        throw new RuntimeException('The online payment amount must be greater than zero.');
    }

    $response = paymongo_request('POST', 'checkout_sessions', [
        'data' => [
            'attributes' => [
                'line_items' => [[
                    'name' => 'BusTicket reservation ' . $bookingReference,
                    'amount' => $amount,
                    'currency' => 'PHP',
                    'quantity' => 1,
                ]],
                'payment_method_types' => ['qrph', 'gcash', 'paymaya', 'card'],
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
                'reference_number' => $bookingReference,
            ],
        ],
    ]);

    $session = $response['data'] ?? [];
    $checkoutId = $session['id'] ?? '';
    $checkoutUrl = $session['attributes']['checkout_url'] ?? '';
    if (!is_string($checkoutId) || $checkoutId === '' || !is_string($checkoutUrl)
        || !paymongo_checkout_url_is_valid($checkoutUrl)) {
        throw new RuntimeException('PayMongo returned an invalid checkout session.');
    }

    return ['id' => $checkoutId, 'url' => $checkoutUrl];
}
