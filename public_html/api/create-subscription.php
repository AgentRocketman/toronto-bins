<?php
/**
 * GetMyBin — Recurring Subscription
 * Creates Stripe Customer + Product + Price + Subscription.
 * Uses allow_incomplete so Stripe attempts payment immediately.
 * Returns prepaid:true when payment succeeds, clientSecret when 3DS is needed.
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

function fail($msg) { echo json_encode(['success' => false, 'error' => $msg]); exit; }

// Step 1: Create Customer
$r = stripeRequest('POST', '/customers', [
    'name'                                     => $customerName,
    'email'                                    => $customerEmail,
    'payment_method'                           => $paymentMethodId,
    'invoice_settings[default_payment_method]' => $paymentMethodId,
    'metadata[booking_id]'                     => $bookingId,
]);
if ($r['code'] >= 400) fail('Customer: ' . ($r['body']['error']['message'] ?? 'failed'));
$customerId = $r['body']['id'];

// Step 2: Create Product
$r = stripeRequest('POST', '/products', ['name' => $productName, 'type' => 'service']);
if ($r['code'] >= 400) fail('Product: ' . ($r['body']['error']['message'] ?? 'failed'));
$productId = $r['body']['id'];

// Step 3: Create Price
$r = stripeRequest('POST', '/prices', [
    'product'             => $productId,
    'currency'            => 'cad',
    'unit_amount'         => $weeklyAmount,
    'recurring[interval]' => 'week',
]);
if ($r['code'] >= 400) fail('Price: ' . ($r['body']['error']['message'] ?? 'failed'));
$priceId = $r['body']['id'];

// Step 4: Create Subscription (allow_incomplete = attempt payment, don't block on failure)
$r = stripeRequest('POST', '/subscriptions', [
    'customer'                                               => $customerId,
    'items[0][price]'                                        => $priceId,
    'default_payment_method'                                => $paymentMethodId,
    'payment_behavior'                                      => 'allow_incomplete',
    'payment_settings[payment_method_types][0]'             => 'card',
    'payment_settings[save_default_payment_method]'         => 'on_subscription',
    'metadata[booking_id]'                                  => $bookingId,
    'metadata[service_type]'                                => $serviceType,
]);
if ($r['code'] >= 400) fail('Subscription: ' . ($r['body']['error']['message'] ?? 'failed'));
$sub            = $r['body'];
$subscriptionId = $sub['id'];
$subStatus      = $sub['status'] ?? 'unknown';

// Step 5: Check subscription status
// active = payment succeeded (no 3DS needed) → prepaid
// incomplete = payment pending (3DS or failed) → try to get PI for confirmation
if ($subStatus === 'active') {
    echo json_encode([
        'success'        => true,
        'subscriptionId' => $subscriptionId,
        'customerId'     => $customerId,
        'prepaid'        => true,
    ]);
    exit;
}

// Subscription is incomplete — find the payment intent for browser confirmation
if (in_array($subStatus, ['incomplete', 'past_due', 'unpaid'])) {
    $invoiceId = $sub['latest_invoice'] ?? null;
    if (!$invoiceId) fail('No invoice on incomplete subscription');

    // Look up payment intents associated with this customer
    $piList = stripeRequest('GET', '/payment_intents', [
        'customer' => $customerId,
        'limit'    => '5',
    ]);
    if ($piList['code'] < 400 && !empty($piList['body']['data'])) {
        foreach ($piList['body']['data'] as $pi) {
            if (in_array($pi['status'] ?? '', ['requires_action', 'requires_confirmation', 'requires_payment_method'])
                && !empty($pi['client_secret'])) {
                echo json_encode([
                    'success'         => true,
                    'subscriptionId'  => $subscriptionId,
                    'customerId'      => $customerId,
                    'clientSecret'    => $pi['client_secret'],
                    'paymentIntentId' => $pi['id'],
                    'prepaid'         => false,
                ]);
                exit;
            }
        }
    }

    fail('Subscription is ' . $subStatus . ' but no actionable payment intent found');
}

fail('Unexpected subscription status: ' . $subStatus);