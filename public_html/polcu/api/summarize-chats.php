<?php
/**
 * GET /polcu/api/summarize-chats.php?fromDate=YYYY-MM-DD&toDate=YYYY-MM-DD
 * 
 * POLCU chat analytics — fetches from PolcuChatLogs table,
 * groups by session, summarizes via OpenAI.
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

$fromDate = $_GET['fromDate'] ?? date('Y-m-d', strtotime('-7 days'));
$toDate = $_GET['toDate'] ?? date('Y-m-d');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid date format. Use YYYY-MM-DD']);
    exit();
}

// Fetch all chats from Airtable PolcuChatLogs table
$result = airtableCall('GET', '/' . POLCU_CHATLOGS_TABLE . '?maxRecords=1000');

if ($result['code'] !== 200) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch chats from Airtable', 'details' => $result['body']]);
    exit();
}

$allRecords = $result['body']['records'] ?? [];

// Filter by date range
$records = array_filter($allRecords, function($record) use ($fromDate, $toDate) {
    $date = $record['fields']['date'] ?? '';
    return $date >= $fromDate && $date <= $toDate;
});

if (empty($records)) {
    http_response_code(200);
    echo json_encode([
        'summary' => 'No chats found in this date range.',
        'topIssues' => [],
        'sessions' => [],
        'sessionCount' => 0,
        'messageCount' => 0
    ]);
    exit();
}

// Group messages by sessionId
$sessions = [];
$allQuestions = [];

foreach ($records as $record) {
    $f = $record['fields'];
    $sessionId = $f['sessionId'] ?? 'unknown';
    $messageType = $f['messageType'] ?? 'answer';
    $message = $f['message'] ?? '';
    $timestamp = $f['timestamp'] ?? '';

    if (!isset($sessions[$sessionId])) {
        $sessions[$sessionId] = [
            'sessionId' => $sessionId,
            'timestamp' => $timestamp,
            'messages' => [],
            'questions' => [],
            'answers' => []
        ];
    }

    $sessions[$sessionId]['messages'][] = [
        'type' => $messageType,
        'content' => $message,
        'timestamp' => $timestamp
    ];

    if ($messageType === 'question') {
        $sessions[$sessionId]['questions'][] = $message;
        $allQuestions[] = $message;
    } else {
        $sessions[$sessionId]['answers'][] = $message;
    }
}

// Sort messages within each session chronologically
foreach ($sessions as &$session) {
    usort($session['messages'], function($a, $b) {
        return strtotime($a['timestamp']) - strtotime($b['timestamp']);
    });
}

// Call OpenAI for top issues summary
$topIssuesPrompt = "Analyze these POLCU Credit Union customer support questions and list the top 5 most frequent issue types/topics. Return as a JSON array of strings like [\"Issue 1\", \"Issue 2\", ...]\n\n" . implode("\n", array_slice($allQuestions, 0, 50));

$openaiResult = openaiCall([
    ['role' => 'system', 'content' => 'You are a customer support analyst for a credit union. Analyze support conversations and identify common issues. Be concise and specific.'],
    ['role' => 'user', 'content' => $topIssuesPrompt]
], 'gpt-3.5-turbo', 0.5);

$topIssuesSummary = 'Unable to summarize at this time.';
$topIssues = [];

if ($openaiResult['code'] === 200 && isset($openaiResult['body']['choices'][0]['message']['content'])) {
    $content = $openaiResult['body']['choices'][0]['message']['content'];
    $topIssuesSummary = $content;
    preg_match('/\[.*\]/s', $content, $matches);
    if ($matches) {
        $parsed = json_decode($matches[0], true);
        if (is_array($parsed)) {
            $topIssues = array_slice($parsed, 0, 5);
        }
    }
}

// Summarize each session
$sessionResults = [];
foreach ($sessions as $sessionId => $sessionData) {
    $sessionOpenaiResult = openaiCall([
        ['role' => 'system', 'content' => 'You are a customer support analyst for a credit union. Summarize chat sessions in 1-2 sentences.'],
        ['role' => 'user', 'content' => "Summarize this POLCU Credit Union customer support chat in 1 sentence:\n" . implode(" | ", array_slice($sessionData['questions'], 0, 5))]
    ], 'gpt-3.5-turbo', 0.5);

    $sessionSummary = 'No summary available';
    if ($sessionOpenaiResult['code'] === 200 && isset($sessionOpenaiResult['body']['choices'][0]['message']['content'])) {
        $sessionSummary = $sessionOpenaiResult['body']['choices'][0]['message']['content'];
    }

    $sessionResults[] = [
        'sessionId' => $sessionId,
        'timestamp' => $sessionData['timestamp'],
        'messageCount' => count($sessionData['messages']),
        'preview' => substr($sessionData['messages'][0]['content'] ?? '', 0, 80),
        'summary' => $sessionSummary,
        'messages' => $sessionData['messages']
    ];
}

http_response_code(200);
echo json_encode([
    'dateRange' => ['from' => $fromDate, 'to' => $toDate],
    'summary' => $topIssuesSummary,
    'topIssues' => $topIssues,
    'sessionCount' => count($sessionResults),
    'messageCount' => count($records),
    'sessions' => $sessionResults
]);
