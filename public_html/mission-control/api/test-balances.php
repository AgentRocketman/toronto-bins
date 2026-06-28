<?php
require_once __DIR__ . '/agents/anthropic.php';

echo "Testing balance API...\n\n";

// Test OpenRouter
echo "1. Testing OpenRouter key...\n";
$key = defined('MC_OPENROUTER_KEY') ? MC_OPENROUTER_KEY : null;
echo "   Key defined: " . ($key ? "YES" : "NO") . "\n";
echo "   Key length: " . strlen($key) . "\n";
echo "   Key preview: " . substr($key, 0, 20) . "...\n\n";

if ($key) {
    echo "2. Fetching OpenRouter balance...\n";
    $ch = curl_init('https://openrouter.ai/api/v1/auth/key');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $key,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    
    echo "   HTTP Code: $http_code\n";
    echo "   Error: " . ($err ?: "none") . "\n";
    echo "   Response (first 500 chars): " . substr($response, 0, 500) . "\n\n";
}

// Test Anthropic
echo "3. Testing Anthropic key...\n";
$key = defined('MC_ANTHROPIC_KEY') ? MC_ANTHROPIC_KEY : null;
echo "   Key defined: " . ($key ? "YES" : "NO") . "\n";
echo "   Key length: " . strlen($key) . "\n";
echo "   Key preview: " . substr($key, 0, 20) . "...\n\n";

if ($key) {
    echo "4. Fetching Anthropic balance...\n";
    $ch = curl_init('https://api.anthropic.com/v1/credits');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'x-api-key: ' . $key,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    
    echo "   HTTP Code: $http_code\n";
    echo "   Error: " . ($err ?: "none") . "\n";
    echo "   Response (first 500 chars): " . substr($response, 0, 500) . "\n\n";
}
