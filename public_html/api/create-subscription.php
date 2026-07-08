<?php
/**
 * GetMyBin — Recurring Subscription
 * Creates Stripe Customer + Product + Price + Subscription.
 * Returns prepaid:true if Stripe charges immediately (no 3DS),
 * or clientSecret for browser-side 3D Secure confirmation.
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
$productName  = 'GetMyBin Weekly — ' . $serviceLabel;

// Step 1: Create Stripe Customer
$customerResult = stripeRequest('POST', '/customers', [
    'name'                                       => $customerName,
    'email'                                      => $customerEmail,
    'payment_method'                             => $paymentMethodId,
    'invoice_settings[default_payment_method]'   => $paymentMethodId,
    'metadata[booking_id]'                       => $bookingId,
]);
if ($customerResult['code'] >= 400) {
    echo json_encode(['success' => false, 'error' => 'Customer: ' . ($customerResult['body']['error']['message'] ?? 'failed')]);
    exit;
}
$customerId = $customerResult['body']['id'];

// Step 2: Create Product
$productResult = stripeRequest('POST', '/products', [
    'name' => $productName,
    'type' => 'service',
]);
if ($productResult['code'] >= 400) {
    echo json_encode(['success' => false, 'error' => 'Product: ' . ($productResult['body']['error']['message'] ?? 'failed')]);
    exit;
}
$productId = $productResult['body']['id'];

// Step 3: Create Price
$priceResult = stripeRequest('POST', '/prices', [
    'product'             => $productId,
    'currency'            => 'cad',
    'unit_amount'         => $weeklyAmount,
    'recurring[interval]' => 'week',
]);
if ($priceResult['code'] >= 400) {
    echo json_encode(['success' => false, 'error' => 'Price: ' . ($priceResult['body']['error']['message'] ?? 'failed')]);
    exit;
}
$priceId = $priceResult['body']['id'];

// Step 4: Create Subscription
// default_incomplete: creates draft invoice (NOT auto-charged).
// We finalize it ourselves so we can control the confirmation flow.
$subscriptionResult = stripeRequest('POST', '/subscriptions', [
    'customer'                                               => $customerId,
    'items[0][price]'                                        => $priceId,
    'default_payment_method'                                => $paymentMethodId,
    'payment_behavior'                                      => 'default_incomplete',
    'payment_settings[payment_method_types][0]'             => 'card',
    'payment_settings[save_default_payment_method]'         => 'on_subscription',
    'metadata[booking_id]'                                  => $bookingId,
    'metadata[service_type]'                                => $serviceType,
]);
if ($subscriptionResult['code'] >= 400) {
    echo json_encode(['success' => false, 'error' => 'Subscription: ' . ($subscriptionResult['body']['error']['message'] ?? 'failed')]);
    exit;
}
$subscriptionId = $subscriptionResult['body']['id'];
$invoiceId      = $subscriptionResult['body']['latest_invoice']; // string

// Step 5: Retrieve invoice (no expand — just check status)
$invoiceResult = stripeRequest('GET', "/invoices/$invoiceId");
if ($invoiceResult['code'] >= 400) {
    echo json_encode(['success' => false, 'error' => 'Invoice fetch: ' . ($invoiceResult['body']['error']['message'] ?? 'failed')]);
    exit;
}

$invStatus = $invoiceResult['body']['status'] ?? 'unknown';

// Step 6: If invoice already paid (charge succeeded immediately), done
if ($invStatus === 'paid') {
    echo json_encode([
        'success'        => true,
        'subscriptionId' => $subscriptionId,
        'customerId'     => $customerId,
        'prepaid'        => true,
        'invoiceStatus'  => $invStatus,
    ]);
    exit;
}

// Step 7: Invoice is a draft — finalize it to create PaymentIntent
$finalizeResult = stripeRequest('POST', "/invoices/$invoiceId/finalize", []);
if ($finalizeResult['code'] >= 400) {
    echo json_encode(['success' => false, 'error' => 'Finalize: ' . ($finalizeResult['body']['error']['message'] ?? 'failed')]);
    exit;
}

// Step 8: Retrieve finalized invoice with payment_intent
$invResult2 = stripeRequest('GET', "/invoices/$invoiceId");
if ($invResult2['code'] >= 400) {
    echo json_encode(['success' => false, 'error' => 'Invoice fetch 2: ' . ($invResult2['body']['error']['message'] ?? 'failed')]);
    exit;
}

$invBody2       = $invResult2['body'];
$invStatus2     = $invBody2['status'] ?? 'unknown';
$paymentIntent  = $invBody2['payment_intent'] ?? null;

// If finalized and paid, no confirmation needed
if ($invStatus2 === 'paid') {
    echo json_encode([
        'success'        => true,
        'subscriptionId' => $subscriptionId,
        'customerId'     => $customerId,
        'prepaid'        => true,
        'invoiceStatus'  => $invStatus2,
    ]);
    exit;
}

// If the invoice has a payment_intent (as an expanded object), use it
if (is_array($paymentIntent) && !empty($paymentIntent['client_secret'])) {
    echo json_encode([
        'success'         => true,
        'subscriptionId'  => $subscriptionId,
        'customerId'      => $customerId,
        'clientSecret'    => $paymentIntent['client_secret'],
        'paymentIntentId' => $paymentIntent['id'],
        'prepaid'         => false,
    ]);
    exit;
}

// If payment_intent is a string (ID), look it up
$piString = $paymentIntent;
if (is_string($piString) && $piString) {
    $piResult = stripeRequest('GET', "/payment_intents/$piString");
    if ($piResult['code'] < 400 && !empty($piResult['body']['client_secret'])) {
        echo json_encode([
            'success'         => true,
            'subscriptionId'  => $subscriptionId,
            'customerId'      => $customerId,
            'clientSecret'    => $piResult['body']['client_secret'],
            'paymentIntentId' => $piResult['body']['id'],
            'prepaid'         => false,
        ]);
        exit;
    }
}

// Last resort: debug info
echo json_encode([
    'success' => false,
    'error'   => 'Invoice finalized but no client_secret found',
    'debug'   => [
        'invoiceStatus'     => $invStatus2,
        'paymentIntentType' => gettype($paymentIntent),
        'paymentIntentVal'  => is_string($paymentIntent) ? $paymentIntent : (is_array($paymentIntent) ? 'array[status=' . ($paymentIntent['status'] ?? '?') . ']' : 'null'),
        'invKeys'           => array_keys($invBody2),
    ],
]);