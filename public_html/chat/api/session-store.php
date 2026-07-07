<?php
// Shared per-session conversation log for the GetMyBin chat PWA.
require_once __DIR__ . '/config.php';

function chatLogPath(string $sessionId): string {
    $cfg = require __DIR__ . '/config.php';
    $safe = preg_replace('/[^a-zA-Z0-9_-]/', '', substr($sessionId, 0, 64));
    return $cfg['sessions_dir'] . $safe . '.json';
}

function chatLogLoad(string $sessionId): array {
    $path = chatLogPath($sessionId);
    if (!file_exists($path)) return [];
    $data = json_decode(file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function chatLogSave(string $sessionId, array $log): void {
    $cfg = require __DIR__ . '/config.php';
    if (!is_dir($cfg['sessions_dir'])) {
        mkdir($cfg['sessions_dir'], 0755, true);
    }
    $path = chatLogPath($sessionId);
    file_put_contents($path, json_encode($log, JSON_PRETTY_PRINT), LOCK_EX);
}

function chatLogAppend(string $sessionId, string $role, string $text): void {
    $log = chatLogLoad($sessionId);
    if (trim($text) === '') return;
    $log[] = ['role' => $role, 'text' => trim($text), 'ts' => time()];
    chatLogSave($sessionId, $log);
}

function chatLogInitFromClient(string $sessionId, array $clientHistory): void {
    if (chatLogLoad($sessionId)) return; // already has server-side history
    $log = [];
    foreach ($clientHistory as $m) {
        if (!is_array($m) || empty($m['text'])) continue;
        $role = ($m['role'] ?? '') === 'user' ? 'user' : 'assistant';
        $log[] = ['role' => $role, 'text' => trim($m['text']), 'ts' => $m['ts'] ?? time()];
    }
    if ($log) chatLogSave($sessionId, $log);
}

function chatLogHistoryText(string $sessionId, int $limit = 20): string {
    $log = chatLogLoad($sessionId);
    $recent = array_slice($log, -$limit);
    if (!$recent) return '';
    $lines = ['Earlier in this conversation:'];
    foreach ($recent as $m) {
        $label = ($m['role'] ?? '') === 'user' ? 'Customer' : 'Assistant';
        $text = str_replace(["\r", "\n"], ' ', (string)($m['text'] ?? ''));
        $lines[] = "{$label}: {$text}";
    }
    return implode("\n", $lines);
}
