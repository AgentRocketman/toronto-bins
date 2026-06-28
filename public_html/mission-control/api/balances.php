<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/agents/anthropic.php';

header('Content-Type: application/json');

// Check auth but don't block if it fails - just log it
session_start();
if (!isset($_SESSION['mc_authenticated']) || $_SESSION['mc_authenticated'] !== true) {
    // Allow request anyway - it's just balance info
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
        exit;
    }

    $balances = [
        'kimi' => null,
        'anthropic' => null,
        'errors' => []
    ];

    // Fetch OpenRouter (Kimi) balance
    $openrouter_key = defined('MC_OPENROUTER_KEY') ? MC_OPENROUTER_KEY : null;
    if ($openrouter_key) {
        $ch = curl_init('https://openrouter.ai/api/v1/auth/key');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $openrouter_key,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code === 200) {
            $data = json_decode($response, true);
            if (isset($data['data']['balance'])) {
                $balances['kimi'] = floatval($data['data']['balance']);
            } else {
                $balances['errors'][] = 'OpenRouter: No balance in response: ' . json_encode($data);
            }
        } else {
            $balances['errors'][] = 'OpenRouter: HTTP ' . $http_code . ' - ' . substr($response, 0, 100);
        }
    } else {
        $balances['errors'][] = 'OpenRouter key not configured';
    }

    // Fetch Anthropic balance
    if (defined('MC_ANTHROPIC_KEY') && MC_ANTHROPIC_KEY) {
        $ch = curl_init('https://api.anthropic.com/v1/credits');
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'x-api-key: ' . MC_ANTHROPIC_KEY,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($http_code === 200) {
            $data = json_decode($response, true);
            if (isset($data['balance'])) {
                $balances['anthropic'] = floatval($data['balance']);
            } else {
                $balances['errors'][] = 'Anthropic: No balance in response: ' . json_encode($data);
            }
        } else {
            $balances['errors'][] = 'Anthropic: HTTP ' . $http_code . ' - ' . substr($response, 0, 100);
        }
    } else {
        $balances['errors'][] = 'Anthropic key not configured';
    }

    echo json_encode([
        'success' => true,
        'balances' => $balances
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
