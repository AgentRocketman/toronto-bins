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

    // JSON file used to track pending requests: request_id -> message_id + reply
    'request_store' => __DIR__ . '/../uploads/requests.json',

    // Persistent conversation log (per conversation_id -> array of messages)
    'conversation_store' => __DIR__ . '/../uploads/conversation.json',

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
