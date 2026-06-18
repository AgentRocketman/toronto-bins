<?php
/**
 * POST /api/chat.php
 * 
 * Handles chat messages through OpenAI API (server-side)
 * API key is stored securely, never exposed to client
 * 
 * Request: { messages: [...] }
 * Response: { content: "response text" } or { error: "..." }
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['messages'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing messages array']);
    exit();
}

$messages = $input['messages'];

// Validate messages format
if (!is_array($messages) || empty($messages)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid messages format']);
    exit();
}

// Call OpenAI API (server-side only, API key never exposed to client)
$result = openaiCall($messages, 'gpt-3.5-turbo', 0.7);

if ($result['code'] !== 200) {
    http_response_code($result['code'] ?? 500);
    echo json_encode([
        'error' => 'OpenAI API error',
        'details' => $result['body']['error'] ?? 'Unknown error'
    ]);
    exit();
}

$responseContent = $result['body']['choices'][0]['message']['content'] ?? null;

if (!$responseContent) {
    http_response_code(500);
    echo json_encode(['error' => 'No response from OpenAI']);
    exit();
}

http_response_code(200);
echo json_encode(['content' => $responseContent]);

?>
