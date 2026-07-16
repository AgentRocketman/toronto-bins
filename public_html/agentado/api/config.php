<?php
/**
 * Agentado API Configuration
 * 
 * Shotstack integration (optional) — sign up at https://shotstack.io
 * Uncomment and add your key to enable server-side rendering.
 */

// Shotstack API key (sandbox key is free — sign up at https://shotstack.io)
define('SHOTSTACK_API_KEY', '');  // Add key to enable pro rendering

// Apify API key (free tier: $5/mo credits) — sign up at https://console.apify.com
define('APIFY_API_KEY', 'apify_api_bokZYqNg2F2uW2b9YzQj3Rn08Dk6A90ewlVH');  // realtor.ca photo scraping

// Oxylabs AI Studio API key — bypasses anti-bot (Cloudflare, Incapsula) for realtor.ca
define('OXYLABS_API_KEY', 'VjWaPcAkrqGXqgYV6ru6SenKGl6gQivFnfbt1xYN');
define('OPENROUTER_API_KEY', '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');

// Shotstack endpoints
define('SHOTSTACK_RENDER_URL', 'https://api.shotstack.io/serve/v1/render');
define('SHOTSTACK_POLL_URL', 'https://api.shotstack.io/serve/v1/render/%s');

// Upload settings
define('MAX_PHOTOS', 20);
define('MIN_PHOTOS', 5);
define('MAX_FILE_SIZE', 10 * 1024 * 1024); // 10MB per photo
define('ALLOWED_TYPES', ['image/jpeg', 'image/png', 'image/webp']);

/**
 * Shotstack render styles (Ken Burns + transitions)
 */
function get_shotstack_style($name) {
    $styles = [
        'cinematic' => [
            'zoom' => ['start' => 1.0, 'end' => 1.12],
            'pan' => ['x' => 'center', 'y' => 'center'],
            'transition' => 'fade',
            'transitionDuration' => 1.0
        ],
        'energetic' => [
            'zoom' => ['start' => 1.0, 'end' => 1.2],
            'pan' => ['x' => 'left', 'y' => 'center'],
            'transition' => 'wipeLeft',
            'transitionDuration' => 0.5
        ],
        'smooth' => [
            'zoom' => ['start' => 1.0, 'end' => 1.06],
            'pan' => ['x' => 'center', 'y' => 'top'],
            'transition' => 'fade',
            'transitionDuration' => 1.5
        ]
    ];
    return $styles[$name] ?? $styles['cinematic'];
}

/**
 * Create a Shotstack render payload
 */
function build_shotstack_payload($imageUrls, $styleName, $clipDuration, $includeTikTok) {
    $style = get_shotstack_style($styleName);
    $clips = [];

    $panDirs = [
        ['x' => 'center', 'y' => 'center'],
        ['x' => 'left', 'y' => 'center'],
        ['x' => 'right', 'y' => 'center'],
        ['x' => 'center', 'y' => 'top'],
        ['x' => 'center', 'y' => 'bottom'],
        ['x' => 'left', 'y' => 'top'],
        ['x' => 'right', 'y' => 'bottom'],
        ['x' => 'left', 'y' => 'bottom']
    ];

    foreach ($imageUrls as $i => $url) {
        $pan = $panDirs[$i % count($panDirs)];
        $clips[] = [
            'asset' => ['type' => 'image', 'src' => $url],
            'start' => $i * ($clipDuration - ($i > 0 ? $style['transitionDuration'] : 0)),
            'length' => $clipDuration,
            'transition' => ['in' => $style['transition']],
            'motion' => [
                'zoom' => [
                    'start' => $style['zoom']['start'],
                    'end' => $style['zoom']['end']
                ],
                'pan' => [
                    'start' => $pan, // pan starts at different positions
                    'end' => ['x' => 'center', 'y' => 'center'] // all pans converge to center
                ]
            ]
        ];
    }

    $timeline = [
        'soundtrack' => [
            'src' => 'https://shotstack-assets.s3.ap-southeast-2.amazonaws.com/music/dreams.mp3',
            'effect' => 'fadeInFadeOut'
        ],
        'tracks' => [
            ['clips' => $clips],
            [
                'clips' => [[
                    'asset' => [
                        'type' => 'title',
                        'text' => 'Property Tour',
                        'style' => 'minimal',
                        'position' => 'bottom',
                        'size' => 'medium'
                    ],
                    'start' => 0,
                    'length' => count($clips) * $clipDuration
                ]]
            ]
        ],
        'output' => [
            'format' => 'mp4',
            'resolution' => 'hd',
            'aspectRatio' => '16:9'
        ]
    ];

    $payload = ['timeline' => $timeline];
    return $payload;
}