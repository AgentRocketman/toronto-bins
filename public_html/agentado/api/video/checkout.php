<?php
/**
 * Agentado Checkout — creates payment session
 * POST: { tier, photoCount, listingData, email, jobId }
 * Returns: { checkoutUrl } (Stripe) or { success, sessionId } (direct, no Stripe)
 */
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'POST only']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);

$tier = $input['tier'] ?? 'kenburns';
$photoCount = intval($input['photoCount'] ?? 0);
$listingData = $input['listingData'] ?? [];
$email = $input['email'] ?? '';
$jobId = $input['jobId'] ?? '';

if ($photoCount < 1) {
    http_response_code(400);
    echo json_encode(['error' => 'No photos selected']);
    exit;
}

// Calculate price
if ($tier === 'kenburns') {
    $amount = 29.00;
} else {
    $amount = round($photoCount * 4.99, 2);
}

// Generate order session
require_once __DIR__ . '/../config.php';
$sessionId = 'ord_' . bin2hex(random_bytes(12));

// Store order in sessions directory
$sessionsDir = __DIR__ . '/../../../data/orders';
if (!is_dir($sessionsDir)) mkdir($sessionsDir, 0755, true);

$orderData = [
    'sessionId' => $sessionId,
    'tier' => $tier,
    'photoCount' => $photoCount,
    'amount' => $amount,
    'listingData' => $listingData,
    'email' => $email,
    'jobId' => $jobId,
    'status' => 'pending',
    'createdAt' => date('c'),
];

file_put_contents("$sessionsDir/$sessionId.json", json_encode($orderData, JSON_PRETTY_PRINT));

// Check if Stripe is configured
$stripeSecret = defined('STRIPE_SECRET_KEY') ? STRIPE_SECRET_KEY : '';
$stripePriceId = defined('STRIPE_PRICE_ID') ? STRIPE_PRICE_ID : '';

if ($stripeSecret && $stripePriceId) {
    // Create Stripe Checkout Session
    require_once __DIR__ . '/../../../vendor/autoload.php'; // if using composer
    
    try {
        $stripe = new \Stripe\StripeClient($stripeSecret);
        $checkout = $stripe->checkout->sessions->create([
            'mode' => 'payment',
            'line_items' => [[
                'price_data' => [
                    'currency' => 'usd',
                    'product_data' => [
                        'name' => $tier === 'kenburns' ? 'Ken Burns Highlight' : 'AI Walkthrough',
                        'description' => $tier === 'kenburns'
                            ? "$photoCount photos, cinematic pan & zoom"
                            : "$photoCount AI-cinematic clips",
                    ],
                    'unit_amount' => intval($amount * 100),
                ],
                'quantity' => 1,
            ]],
            'success_url' => 'https://' . $_SERVER['HTTP_HOST'] . '/?session_id=' . $sessionId . '&status=success',
            'cancel_url' => 'https://' . $_SERVER['HTTP_HOST'] . '/?session_id=' . $sessionId . '&status=cancelled',
            'customer_email' => $email,
            'metadata' => [
                'sessionId' => $sessionId,
                'tier' => $tier,
            ],
        ]);
        
        echo json_encode(['checkoutUrl' => $checkout->url, 'sessionId' => $sessionId]);
    } catch (\Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => 'Stripe error: ' . $e->getMessage()]);
    }
} else {
    // No Stripe — proceed directly (MVP mode)
    $orderData['status'] = 'paid';
    file_put_contents("$sessionsDir/$sessionId.json", json_encode($orderData, JSON_PRETTY_PRINT));
    
    echo json_encode([
        'success' => true,
        'sessionId' => $sessionId,
    ]);
}