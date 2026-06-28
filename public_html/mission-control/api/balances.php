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
    $url = 'https://openrouter.ai/api/v1/credits';
    $options = [
        'http' => [
            'method' => 'GET',
            'header' => "Authorization: Bearer " . MC_OPENROUTER_KEY . "\r\nContent-Type: application/json\r\n",
            'timeout' => 10
        ]
    ];
    
    $context = stream_context_create($options);
    $result = @file_get_contents($url, false, $context);
    
    if ($result !== false) {
        $data = json_decode($result, true);
        $response['_debug_or'] = [$result, array_keys($data ?? [])];
        
        // Check for balance in response - try multiple paths
        if (isset($data['data']['balance'])) {
            $response['balances']['kimi'] = floatval($data['data']['balance']);
        } elseif (isset($data['balance'])) {
            $response['balances']['kimi'] = floatval($data['balance']);
        } elseif (is_array($data) && count($data) > 0) {
            $response['balances']['errors'][] = 'Keys: ' . implode(', ', array_keys($data));
        } else {
            $response['balances']['errors'][] = 'No balance in response';
        }
    } else {
        $response['balances']['errors'][] = 'Request failed';
    }
} else {
    $response['balances']['errors'][] = 'Key not set';
}

echo json_encode($response);
