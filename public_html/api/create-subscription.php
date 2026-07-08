<?php
/**
 * GetMyBin — Recurring Subscription
 * Creates Stripe Customer + Product + Price + Subscription.
 * Uses allow_incomplete so Stripe attempts payment immediately.
 * Returns client_secret for 3D Secure confirmation if needed.
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
    $errorMsg = $customerResult['body']['error']['message'] ?? 'Failed to create customer';
    echo json_encode(['success' => false, 'error' => $errorMsg]);
    exit;
}

$customerId = $customerResult['body']['id'];

// Step 2: Create Product
$productResult = stripeRequest('POST', '/products', [
    'name'                  => $productName,
    'type'                  => 'service',
]);

if ($productResult['code'] >= 400) {
    $errorMsg = $productResult['body']['error']['message'] ?? 'Failed to create product';
    echo json_encode(['success' => false, 'error' => $errorMsg]);
    exit;
}

$productId = $productResult['body']['id'];

// Step 3: Create Price
$priceResult = stripeRequest('POST', '/prices', [
    'product'              => $productId,
    'currency'             => 'cad',
    'unit_amount'          => $weeklyAmount,
    'recurring[interval]'  => 'week',
]);

if ($priceResult['code'] >= 400) {
    $errorMsg = $priceResult['body']['error']['message'] ?? 'Failed to create price';
    echo json_encode(['success' => false, 'error' => $errorMsg]);
    exit;
}

$priceId = $priceResult['body']['id'];

// Step 4: Create Subscription (allow_incomplete: attempt payment immediately)
$subscriptionResult = stripeRequest('POST', '/subscriptions', [
    'customer'                                                   => $customerId,
    'items[0][price]'                                            => $priceId,
    'default_payment_method'                                    => $paymentMethodId,
    'payment_behavior'                                          => 'allow_incomplete',
    'payment_settings[payment_method_types][0]'                 => 'card',
    'payment_settings[save_default_payment_method]'             => 'on_subscription',
    'metadata[booking_id]'                                      => $bookingId,
    'metadata[service_type]'                                    => $serviceType,
]);

if ($subscriptionResult['code'] >= 400) {
    $errorMsg = $subscriptionResult['body']['error']['message'] ?? 'Failed to create subscription';
    echo json_encode(['success' => false, 'error' => $errorMsg]);
    exit;
}

$subscription  = $subscriptionResult['body'];
$subscriptionId = $subscription['id'];
$invoiceId      = $subscription['latest_invoice']; // string ID

// Step 5: Retrieve invoice to get payment_intent (expand didn't work via form-urlencoded)
$invoiceResult = stripeRequest('GET', "/invoices/$invoiceId?expand[]=payment_intent");

if ($invoiceResult['code'] >= 400) {
    $errorMsg = $invoiceResult['body']['error']['message'] ?? 'Failed to retrieve invoice';
    echo json_encode(['success' => false, 'error' => $errorMsg]);
    exit;
}

$paymentIntent = $invoiceResult['body']['payment_intent'] ?? null;

// Check invoice state to determine if payment is done or needs confirmation
$invBody      = $invoiceResult['body'];
$invStatus    = $invBody['status'] ?? 'unknown';
$invCharge    = $invBody['charge'] ?? null;
$invPaymentIntent = $invBody['payment_intent'] ?? null;

if ($invStatus === 'paid' && $invCharge) {
    // Payment succeeded immediately — no confirmation needed
    echo json_encode([
        'success'        => true,
        'subscriptionId' => $subscriptionId,
        'customerId'     => $customerId,
        'chargeId'       => $invCharge,
        'prepaid'        => true,
    ]);
    exit;
}

if ($invStatus === 'paid') {
    echo json_encode([
        'success'        => true,
        'subscriptionId' => $subscriptionId,
        'customerId'     => $customerId,
        'invoiceStatus'  => $invStatus,
        'prepaid'        => true,
    ]);
    exit;
}

// Invoice is not paid yet — need a payment intent to confirm
if ($invPaymentIntent) {
    $piStatus = $invPaymentIntent['status'] ?? 'unknown';
    $piSecret = $invPaymentIntent['client_secret'] ?? null;
    if ($piSecret && ($piStatus === 'requires_action' || $piStatus === 'requires_confirmation' || $piStatus === 'requires_payment_method')) {
        echo json_encode([
            'success'        => true,
            'subscriptionId' => $subscriptionId,
            'customerId'     => $customerId,
            'clientSecret'   => $piSecret,
            'paymentIntentId'=> $invPaymentIntent['id'],
            'prepaid'        => false,
        ]);
        exit;
    }
}

// Fallback: return debug info
$debugFields = [
    'invoice_status' => $invStatus,
    'charge' => $invCharge ?? 'NULL',
    'payment_intent' => $invPaymentIntent ? $invPaymentIntent['id'] : 'NULL',
    'collection_method' => $invBody['collection_method'] ?? 'MISSING',
    'attempted' => $invBody['attempted'] ?? 'MISSING',
    'amount_due' => $invBody['amount_due'] ?? 0,
    'amount_paid' => $invBody['amount_paid'] ?? 0,
];
echo json_encode(['success' => false, 'error' => 'Invoice not paid, no PI: status=' . $invStatus, 'debug' => $debugFields]);