<?php
/**
 * GetMyBin — Ad Hoc Payment
 * Creates and confirms a Stripe PaymentIntent for one-time bookings
 */
require_once __DIR__ . '/config.php';
corsHeaders();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'POST required']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

$paymentMethodId = $body['paymentMethodId'] ?? '';
$amount          = (int)($body['amount'] ?? 0);
$customerName    = $body['customerName'] ?? '';
$customerEmail   = $body['customerEmail'] ?? '';
$bookingId       = $body['bookingId'] ?? '';

if (!$paymentMethodId || $amount <= 0) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

// Create and confirm PaymentIntent
$result = stripeRequest('POST', '/payment_intents', [
    'amount'               => $amount,
    'currency'             => 'cad',
    'payment_method'       => $paymentMethodId,
    'confirm'              => 'true',
    'receipt_email'        => $customerEmail,
    'description'          => 'GetMyBin booking ' . $bookingId,
    'metadata[booking_id]' => $bookingId,
    'metadata[customer]'   => $customerName,
    'return_url'           => 'https://agentrocketman.com',
]);

$pi = $result['body'];

if ($result['code'] >= 400) {
    $errorMsg = $pi['error']['message'] ?? 'Payment failed';
    echo json_encode(['success' => false, 'error' => $errorMsg]);
    exit;
}

$status = $pi['status'] ?? '';

if ($status === 'succeeded') {
    echo json_encode(['success' => true, 'paymentIntentId' => $pi['id']]);
} elseif ($status === 'requires_action' || $status === 'requires_confirmation') {
    echo json_encode([
        'success'        => false,
        'requiresAction' => true,
        'clientSecret'   => $pi['client_secret'],
        'paymentIntentId'=> $pi['id'],
    ]);
} else {
    echo json_encode(['success' => false, 'error' => 'Unexpected payment status: ' . $status]);
}
