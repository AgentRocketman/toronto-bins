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
        // Debug: log actual response
        $response['_debug_openrouter_keys'] = array_keys($data);
        if (isset($data['data'])) {
            $response['_debug_data_keys'] = array_keys($data['data']);
        }
        
        // Try multiple possible locations for balance
        if (isset($data['data']['balance'])) {
            $response['balances']['kimi'] = floatval($data['data']['balance']);
        } elseif (isset($data['balance'])) {
            $response['balances']['kimi'] = floatval($data['balance']);
        } elseif (isset($data['credit_balance'])) {
            $response['balances']['kimi'] = floatval($data['credit_balance']);
        } else {
            $response['balances']['errors'][] = 'OpenRouter: ' . json_encode($data);
        }
    } else {
        $response['balances']['errors'][] = 'OpenRouter: Failed to fetch';
    }
} else {
    $response['balances']['errors'][] = 'OpenRouter key not configured';
}

echo json_encode($response);
