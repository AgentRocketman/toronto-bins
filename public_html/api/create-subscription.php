<?php
/**
 * GetMyBin — Recurring Subscription
 * Creates Stripe Customer + Product + Price + Subscription.
 * Uses pending_if_incomplete so Stripe attempts payment immediately.
 * If payment succeeds → prepaid:true. If 3D Secure needed → returns clientSecret.
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

// Step 4: Create Subscription with pending_if_incomplete
// Stripe attempts payment immediately. 4242 cards succeed right away.
// 3D Secure cards need browser confirmation.
$r = stripeRequest('POST', '/subscriptions', [
    'customer'                                               => $customerId,
    'items[0][price]'                                        => $priceId,
    'default_payment_method'                                => $paymentMethodId,
    'payment_behavior'                                      => 'pending_if_incomplete',
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
// active = payment succeeded immediately
// incomplete = payment requires confirmation (3D Secure etc.)
// past_due / unpaid = payment failed
if ($subStatus === 'active') {
    echo json_encode([
        'success'        => true,
        'subscriptionId' => $subscriptionId,
        'customerId'     => $customerId,
        'prepaid'        => true,
    ]);
    exit;
}

if ($subStatus === 'incomplete' || $subStatus === 'past_due') {
    // Try to get the latest invoice's payment_intent for confirmation
    $invoiceId = $sub['latest_invoice'] ?? null;
    if (!$invoiceId) fail('No latest_invoice on subscription');

    // Direct API call to get invoice with payment_intent expanded
    $ch = curl_init('https://api.stripe.com/v1/invoices/' . $invoiceId . '?expand%5B%5D=payment_intent');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => STRIPE_SECRET_KEY . ':',
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $invBody = json_decode($resp, true);
    $pi      = $invBody['payment_intent'] ?? null;

    if (is_array($pi) && !empty($pi['client_secret'])) {
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

    // Payment intent not available — check if there's a pending setup intent
    $pendingIntent = $sub['pending_setup_intent'] ?? null;
    if ($pendingIntent) {
        $piResp = stripeRequest('GET', "/setup_intents/$pendingIntent");
        if ($piResp['code'] < 400 && $piResp['body']['payment_intent'] ?? null) {
            $piId = $piResp['body']['payment_intent'];
            $piResult = stripeRequest('GET', "/payment_intents/$piId");
            if ($piResult['code'] < 400 && !empty($piResult['body']['client_secret'])) {
                echo json_encode([
                    'success'         => true,
                    'subscriptionId'  => $subscriptionId,
                    'customerId'      => $customerId,
                    'clientSecret'    => $piResult['body']['client_secret'],
                    'paymentIntentId' => $piId,
                    'prepaid'         => false,
                ]);
                exit;
            }
        }
    }

    fail('Payment requires confirmation but no client_secret found. Sub status: ' . $subStatus);
}

// Unknown status — debug
fail('Unexpected subscription status: ' . $subStatus . ' — ' . json_encode(array_keys($sub)));