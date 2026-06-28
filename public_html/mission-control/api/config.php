<?php
/**
 * Mission Control Configuration
 * Extends the main GetMyBin config.php
 */

require_once __DIR__ . '/../../api/config.php';

// Mission Control Auth
define('MC_ADMIN_PASSWORD', 'MissionControl2026!');

// Shared secret for internal/server-side calls (runner, webhooks) to bypass session auth
define('MC_INTERNAL_SECRET', 'mc-runner-heartbeat-2026');

// Mission Control Airtable Tables
define('MC_PROJECTS_TABLE', 'tblZDjRO5OSIqzmEY');
define('MC_AGENTSTATUS_TABLE', 'tblwlhJRTnuHzivlb');
define('MC_APPROVALS_TABLE', 'tblr4Wex6GwRwz4WE');

// Telegram Integration
define('TG_BOT_TOKEN', 'REPLACE_WITH_TELEGRAM_BOT_TOKEN');
define('TG_CHAT_ID', '8714809782');

// Session-based auth check helper
function requireMCAuth() {
    session_start();
    if (!isset($_SESSION['mc_authenticated']) || $_SESSION['mc_authenticated'] !== true) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized', 'redirect' => '/mission-control/']);
        exit;
    }
}

// UUID generator
function generateUUID() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}

// Telegram notification helper
function sendTelegramNotification($message) {
    if (TG_BOT_TOKEN === 'REPLACE_WITH_TELEGRAM_BOT_TOKEN') {
        return false; // Skip if not configured
    }

    $url = "https://api.telegram.org/bot" . TG_BOT_TOKEN . "/sendMessage";
    $data = [
        'chat_id' => TG_CHAT_ID,
        'text' => $message,
        'parse_mode' => 'Markdown'
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $response = curl_exec($ch);
    curl_close($ch);

    return $response !== false;
}
