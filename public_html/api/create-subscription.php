<?php
/**
 * CurbIn — Recurring Subscription
 * Creates a Stripe Customer + weekly Subscription for recurring bookings
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
$weeklyAmount    = (int)($body['weeklyAmount'] ?? 0);
$customerName    = $body['customerName'] ?? '';
$customerEmail   = $body['customerEmail'] ?? '';
$serviceType     = $body['serviceType'] ?? 'rollout';
$bookingId       = $body['bookingId'] ?? '';

if (!$paymentMethodId || $weeklyAmount <= 0 || !$customerEmail) {
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

$serviceLabel = $serviceType === 'both' ? 'Roll Out + Roll In' : ($serviceType === 'rollin' ? 'Roll In' : 'Roll Out');
$productName  = 'CurbIn Weekly — ' . $serviceLabel;

// Step 1: Create Stripe Customer
$customerResult = stripeRequest('POST', '/customers', [
    'name'                                       => $customerName,
    'email'                                      => $customerEmail,
    'payment_method'                             => $paymentMethodId,
    'invoice_settings[default_payment_method]'   => $paymentMethodId,
    'metadata[booking_id]'                       => $bookingId,
]);

if ($customerResult['code'] >= 400) {
    $errorMsg = $customerResult['body']['error']['message'] ?? 'Failed to create customer';
    echo json_encode(['success' => false, 'error' => $errorMsg]);
    exit;
}

$customerId = $customerResult['body']['id'];

// Step 2: Create weekly Subscription
$subscriptionResult = stripeRequest('POST', '/subscriptions', [
    'customer'                                                       => $customerId,
    'items[0][price_data][currency]'                                => 'cad',
    'items[0][price_data][unit_amount]'                             => $weeklyAmount,
    'items[0][price_data][recurring][interval]'                     => 'week',
    'items[0][price_data][product_data][name]'                      => $productName,
    'default_payment_method'                                        => $paymentMethodId,
    'payment_behavior'                                              => 'default_incomplete',
    'payment_settings[payment_method_types][0]'                     => 'card',
    'payment_settings[save_default_payment_method]'                 => 'on_subscription',
    'expand[0]'                                                     => 'latest_invoice.payment_intent',
    'metadata[booking_id]'                                          => $bookingId,
    'metadata[service_type]'                                        => $serviceType,
]);

if ($subscriptionResult['code'] >= 400) {
    $errorMsg = $subscriptionResult['body']['error']['message'] ?? 'Failed to create subscription';
    echo json_encode(['success' => false, 'error' => $errorMsg]);
    exit;
}

$subscription  = $subscriptionResult['body'];
$subscriptionId = $subscription['id'];
$clientSecret   = $subscription['latest_invoice']['payment_intent']['client_secret'] ?? null;

if (!$clientSecret) {
    echo json_encode(['success' => false, 'error' => 'Could not get payment confirmation secret']);
    exit;
}

echo json_encode([
    'success'        => true,
    'subscriptionId' => $subscriptionId,
    'customerId'     => $customerId,
    'clientSecret'   => $clientSecret,
]);
