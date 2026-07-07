<?php
// CurbIn Voice Assistant — PWA backend config
// Fill in TELEGRAM_BOT_TOKEN after creating a dedicated bot via @BotFather.

return [
    // Telegram Bot API token for the assistant bot
    'telegram_bot_token' => '8991461374:AAGfPscA15vMZ4L3BmkmGta-hG8YhpZV60U',

    // Only accept messages / replies from this Telegram user ID
    'allowed_chat_id' => '8714809782',

    // Simple auth token the PWA must send in the X-Auth-Token header
    'api_auth_token' => 'curbin-assistant-dev-2026',

    // Where uploaded audio files are stored temporarily
    'upload_dir' => __DIR__ . '/../uploads/',

    // JSON file used to track pending requests: request_id -> message_id
    'request_store' => __DIR__ . '/../uploads/requests.json',

    // Telegram Bot API base URL (without trailing bot/token path)
    'telegram_api_base' => 'https://api.telegram.org/',

    // OpenAI API key (used for Whisper transcription and TTS)
    'openai_api_key' => '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',

    // Secret token the OpenClaw hook must send when posting replies
    'hook_auth_token' => 'curbin-hook-auth-2026',

    // OpenClaw hook endpoint — fires an agent turn in the main session.
    // URL stored in /data/.openclaw/workspace/tunnel-url.txt on the OpenClaw side.
    'openclaw_hook_url'    => 'https://announcements-landscapes-premier-assumed.trycloudflare.com/hooks/agent',
    'openclaw_session_key' => 'agent:main:main',
    'openclaw_hook_token'  => 'hooks_ykIDvQ5jja3heZvUhzjSPQP_UblMlxfR',
];
