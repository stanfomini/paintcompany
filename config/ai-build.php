<?php

return [
    'enabled' => env('AI_BUILD_ENABLED', false),
    'api_secret' => env('AI_BUILD_SECRET'),
    'dev_server_url' => env('AI_BUILD_DEV_URL', 'https://apexnegotiations.com'),
    'prod_server_url' => env('AI_BUILD_PROD_URL', 'https://paintingmadeeasydenver.com'),
    'ssh' => [
        'key' => env('AI_BUILD_SSH_KEY', '/home/forge/.ssh/id_rsa'),
        'user' => env('AI_BUILD_SSH_USER', 'forge'),
        'host' => env('AI_BUILD_SSH_HOST', 'paintingmadeeasydenver.com'),
        'prod_path' => env('AI_BUILD_PROD_PATH', '/home/forge/paintingmadeeasydenver.com'),
    ],
    'claude' => [
        'binary' => env('AI_BUILD_CLAUDE_BIN', '/home/forge/.local/bin/claude'),
        'working_dir' => env('AI_BUILD_WORKING_DIR', '/home/forge/apexnegotiations.com'),
    ],
    'timeout_minutes' => env('AI_BUILD_TIMEOUT', 10),
];
