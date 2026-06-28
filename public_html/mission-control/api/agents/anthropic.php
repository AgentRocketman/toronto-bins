<?php
/**
 * Anthropic API wrapper for Mission Control agents.
 * Stores the API key in this file (server-side only) and provides a callAnthropic() helper.
 */

// API key stored ONLY here, never exposed to clients.
define('MC_ANTHROPIC_KEY', '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');

/**
 * Make a single completion call to Anthropic Messages API.
 *
 * @param string $model      Model id (e.g. claude-sonnet-4-5)
 * @param string $system     System prompt
 * @param array  $messages   Array of {role, content} entries
 * @param int    $maxTokens  Max tokens
 * @return array {ok, response, error, usage}
 */
function callAnthropic($model, $system, $messages, $maxTokens = 4000) {
    $payload = [
        'model' => $model,
        'max_tokens' => $maxTokens,
        'system' => $system,
        'messages' => $messages
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'x-api-key: ' . MC_ANTHROPIC_KEY,
        'anthropic-version: 2023-06-01'
    ]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 180);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'error' => 'cURL error: ' . $curlErr, 'response' => null, 'usage' => null];
    }

    $data = json_decode($response, true);

    if ($httpCode !== 200) {
        $errorMsg = isset($data['error']['message']) ? $data['error']['message'] : ('HTTP ' . $httpCode);
        return ['ok' => false, 'error' => $errorMsg, 'response' => $data, 'usage' => null];
    }

    // Extract text content
    $text = '';
    if (isset($data['content']) && is_array($data['content'])) {
        foreach ($data['content'] as $block) {
            if (isset($block['type']) && $block['type'] === 'text') {
                $text .= $block['text'];
            }
        }
    }

    $usage = $data['usage'] ?? ['input_tokens' => 0, 'output_tokens' => 0];

    // Calculate cost — Sonnet pricing (rough): $3/M input, $15/M output
    $cost = ($usage['input_tokens'] / 1000000) * 3.0 + ($usage['output_tokens'] / 1000000) * 15.0;

    return [
        'ok' => true,
        'response' => $text,
        'usage' => $usage,
        'cost' => round($cost, 4),
        'total_tokens' => ($usage['input_tokens'] ?? 0) + ($usage['output_tokens'] ?? 0),
        'raw' => $data
    ];
}
