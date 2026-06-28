<?php
require_once __DIR__ . '/agents/anthropic.php';

header('Content-Type: application/json');
// No auth required for balance info - it's internal use only

$balances = [
    'kimi' => null,
    'anthropic' => 'N/A',
    'errors' => [],
    '_debug' => 'API called at ' . date('Y-m-d H:i:s')
];

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        $balances['errors'][] = 'Method not allowed';
    } else {
        // Fetch OpenRouter (Kimi) balance
        $openrouter_key = defined('MC_OPENROUTER_KEY') ? MC_OPENROUTER_KEY : null;
        if ($openrouter_key) {
            $ch = curl_init('https://openrouter.ai/api/v1/auth/key');
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Authorization: Bearer ' . $openrouter_key,
                'Content-Type: application/json',
                'User-Agent: Mission-Control/1.0'
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            $response = curl_exec($ch);
            $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curl_error = curl_error($ch);
            curl_close($ch);

            if ($curl_error) {
                $balances['errors'][] = 'OpenRouter curl error: ' . $curl_error;
            } elseif ($http_code === 200) {
                $data = json_decode($response, true);
                if (isset($data['data']['balance'])) {
                    $balances['kimi'] = floatval($data['data']['balance']);
                } else {
                    $balances['errors'][] = 'OpenRouter: No balance in response';
                }
            } else {
                $balances['errors'][] = 'OpenRouter: HTTP ' . $http_code;
            }
        } else {
            $balances['errors'][] = 'OpenRouter key not configured';
        }

        // Anthropic doesn't have a public API endpoint for balance info
        $balances['anthropic'] = 'N/A';
    }
} catch (Exception $e) {
    $balances['errors'][] = 'Exception: ' . $e->getMessage();
}

echo json_encode([
    'success' => true,
    'balances' => $balances
]);
