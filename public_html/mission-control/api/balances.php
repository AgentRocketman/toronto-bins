<?php
header('Content-Type: application/json');

$response = [
    'success' => true,
    'balances' => [
        'kimi' => null,
        'anthropic' => 'N/A',
        'errors' => []
    ]
];

// Load API keys
require_once __DIR__ . '/agents/anthropic.php';

// Fetch OpenRouter balance if key exists
if (defined('MC_OPENROUTER_KEY') && MC_OPENROUTER_KEY) {
    $url = 'https://openrouter.ai/api/v1/auth/key';
    $options = [
        'http' => [
            'method' => 'GET',
            'header' => "Authorization: Bearer " . MC_OPENROUTER_KEY . "\r\nContent-Type: application/json\r\n",
            'timeout' => 10
        ]
    ];
    
    $context = stream_context_create($options);
    $result = @file_get_contents($url, false, $context);
    
    if ($result) {
        $data = json_decode($result, true);
        $response['_debug_full_response'] = $data; // Log entire response for debugging
        
        // data field exists, check what's inside
        if (isset($data['data']) && is_array($data['data'])) {
            $response['_debug_data_keys'] = array_keys($data['data']);
            
            // Check for balance in data field
            if (isset($data['data']['balance'])) {
                $response['balances']['kimi'] = floatval($data['data']['balance']);
            } else {
                $response['balances']['errors'][] = 'OpenRouter: data keys: ' . implode(', ', array_keys($data['data']));
            }
        } else {
            $response['balances']['errors'][] = 'OpenRouter: data is not array';
        }
    } else {
        $response['balances']['errors'][] = 'OpenRouter: Failed to fetch';
    }
} else {
    $response['balances']['errors'][] = 'OpenRouter key not configured';
}

echo json_encode($response);
