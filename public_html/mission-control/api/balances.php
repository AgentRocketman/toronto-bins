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
        if (isset($data['data']['balance'])) {
            $response['balances']['kimi'] = floatval($data['data']['balance']);
        } else {
            $response['balances']['errors'][] = 'OpenRouter: No balance in response';
        }
    } else {
        $response['balances']['errors'][] = 'OpenRouter: Failed to fetch';
    }
} else {
    $response['balances']['errors'][] = 'OpenRouter key not configured';
}

echo json_encode($response);
