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
        
        // Log what's in data
        if (isset($data['data']) && is_array($data['data'])) {
            $inner_keys = array_keys($data['data']);
            $response['_debug_or'] = ['data keys: ' . implode(', ', $inner_keys), 'full data: ' . substr(json_encode($data['data']), 0, 200)];
            
            // Check for balance
            if (isset($data['data']['balance'])) {
                $response['balances']['kimi'] = floatval($data['data']['balance']);
            } else {
                $response['balances']['errors'][] = 'Data keys: ' . implode(', ', $inner_keys);
            }
        } else {
            $response['balances']['errors'][] = 'Top keys: ' . implode(', ', array_keys($data ?? []));
        }
    } else {
        $response['balances']['errors'][] = 'Request failed';
    }
} else {
    $response['balances']['errors'][] = 'Key not set';
}

echo json_encode($response);
