<?php
// OpenClaw text-chat bridge — shared config with /assistant/ where sensible.

return [
    // Telegram Bot API token (same bot as PWA voice assistant)
    'telegram_bot_token' => '8991461374:AAGfPscA15vMZ4L3BmkmGta-hG8YhpZV60U',

    // Only route messages to this Telegram user (Chris)
    'allowed_chat_id' => '8714809782',

    // Auth token the chat UI must send with every request
    'api_auth_token' => 'curbin-chat-dev-2026',

    // Secret the OpenClaw agent must send when POSTing a reply back
    'hook_auth_token' => 'curbin-chat-hook-2026',

    // Where the JSON request/reply store lives
    'store_dir'   => __DIR__ . '/../store/',
    'store_file'  => __DIR__ . '/../store/messages.json',

    // Telegram API base
    'telegram_api_base' => 'https://api.telegram.org/',

    // OpenAI API key (used for Whisper transcription on mic input).
    'openai_api_key' => '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',

    // Temp uploads dir for audio files.
    'upload_dir' => __DIR__ . '/../uploads/',

    // OpenClaw hook endpoint that wakes the main session when a chat message arrives.
    // Points to a cloudflared quick tunnel → gateway 127.0.0.1:18789/hooks/wake.
    // URL is stored in /data/.openclaw/workspace/tunnel-url.txt on the OpenClaw side
    // and can be rotated by editing this value (or making it env-driven).
    'openclaw_hook_url'   => 'https://announcements-landscapes-premier-assumed.trycloudflare.com/hooks/agent',
    // Pin the isolated agent turn to the main Telegram session so it shares context.
    'openclaw_session_key' => 'agent:main:main',
    'openclaw_hook_token' => 'hooks_ykIDvQ5jja3heZvUhzjSPQP_UblMlxfR',
];
