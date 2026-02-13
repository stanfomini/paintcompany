<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Python Service Configuration
    |--------------------------------------------------------------------------
    |
    | Configuration for the Python ML/processing service integration.
    | The Python service runs as a FastAPI application alongside Laravel.
    |
    */

    'enabled' => env('PYTHON_ENABLED', false),

    'api_key' => env('PYTHON_API_KEY', ''),

    'service_url' => env('PYTHON_SERVICE_URL', 'http://127.0.0.1:8000'),

    'timeout' => env('PYTHON_SERVICE_TIMEOUT', 60),

    'retry_attempts' => env('PYTHON_RETRY_ATTEMPTS', 3),

    'retry_delay' => env('PYTHON_RETRY_DELAY', 1000), // milliseconds

    /*
    |--------------------------------------------------------------------------
    | ML Configuration
    |--------------------------------------------------------------------------
    */

    'ml' => [
        'models_path' => env('ML_MODELS_PATH', base_path('python/models')),
        'gpu_enabled' => env('ML_GPU_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scraping Configuration
    |--------------------------------------------------------------------------
    */

    'scraping' => [
        'proxy_url' => env('SCRAPING_PROXY_URL'),
        'rate_limit' => env('SCRAPING_RATE_LIMIT', 10), // requests per minute
        'timeout' => env('SCRAPING_TIMEOUT', 120), // seconds
    ],

    /*
    |--------------------------------------------------------------------------
    | Media Configuration
    |--------------------------------------------------------------------------
    */

    'media' => [
        'rtmp_url' => env('RTMP_SERVER_URL', 'rtmp://127.0.0.1:1935'),
        'hls_path' => env('HLS_OUTPUT_PATH', '/var/www/hls'),
        'whisper_model' => env('WHISPER_MODEL', 'base'),
    ],
];
