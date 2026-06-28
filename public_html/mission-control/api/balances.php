<?php
header('Content-Type: application/json');

$response = [
    'success' => true,
    'balances' => [
        'kimi' => null,
        'kimi_usage' => null,
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
        
        // OpenRouter returns total_credits and total_usage
        if (isset($data['data']) && is_array($data['data'])) {
            if (isset($data['data']['total_credits'])) {
                $response['balances']['kimi'] = floatval($data['data']['total_credits']);
            }
            if (isset($data['data']['total_usage'])) {
                $response['balances']['kimi_usage'] = floatval($data['data']['total_usage']);
            }
            if (!isset($data['data']['total_credits'])) {
                $response['balances']['errors'][] = 'Missing total_credits';
            }
        } else {
            $response['balances']['errors'][] = 'Invalid response';
        }
    } else {
        $response['balances']['errors'][] = 'Request failed';
    }
} else {
    $response['balances']['errors'][] = 'Key not set';
}

echo json_encode($response);
