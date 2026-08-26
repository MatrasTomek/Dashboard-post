<?php

declare(strict_types=1);

// Copy this file to config.local.php (gitignored) and fill in real values.
// config.local.php lives outside public_html/ and is never web-reachable.
return [
    'app_env' => 'development',
    'debug' => true,

    'db' => [
        'host' => '127.0.0.1',
        'name' => 'post_dashboard',
        'user' => 'post_dashboard',
        'pass' => 'dev_password_change_me',
    ],

    // 32-byte random key, base64-encoded. Generate with:
    // php -r "echo base64_encode(random_bytes(32));"
    'encryption_key' => 'CHANGE_ME_32_BYTE_BASE64_KEY',

    'session_secret' => 'CHANGE_ME',

    'meta_app_id' => '',
    'meta_app_secret' => '',
    'meta_redirect_uri' => 'https://example.com/api/social/connect/callback',
    'app_base_url' => 'https://example.com',

    'default_anthropic_model' => 'claude-sonnet-5',
    'default_history_token_budget' => 6000,
];
