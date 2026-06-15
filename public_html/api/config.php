<?php
// CurbIn Stripe Configuration
define('STRIPE_SECRET_KEY', 'sk_test_51SFgOXRoaqSc6FkpqmcozU4mGNxDZTQJfkgcwti8z2kg7Lq3SkuCrsenYDn2kDYDZ9Gu6v4xPCtZiPDhALX7w9KN00Zw7AOcpt');
define('STRIPE_API_BASE', 'https://api.stripe.com/v1');

function corsHeaders() {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

function stripeRequest($method, $endpoint, $data = []) {
    $url = STRIPE_API_BASE . $endpoint;
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_USERPWD, STRIPE_SECRET_KEY . ':');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['code' => $httpCode, 'body' => json_decode($response, true)];
}
