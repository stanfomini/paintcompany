<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Forge Server & Site IDs
    |--------------------------------------------------------------------------
    |
    | These identify which Forge server and site to sync workers to.
    | Set these in .env for each environment (dev vs production).
    |
    */

    'server_id' => env('FORGE_SERVER_ID'),
    'site_id' => env('FORGE_SITE_ID'),

    /*
    |--------------------------------------------------------------------------
    | Queue Workers
    |--------------------------------------------------------------------------
    |
    | Each entry defines a queue worker that should exist on the Forge server.
    | Run `php artisan forge:sync-workers` after changing this file.
    |
    | Available options per worker:
    |   - queue: (string) Queue name (required)
    |   - connection: (string) Queue connection, default 'redis'
    |   - processes: (int) Number of worker processes, default 1
    |   - timeout: (int) Max seconds a job can run, default 60
    |   - sleep: (int) Seconds to sleep when no jobs, default 10
    |   - tries: (int) Max attempts before marking failed, default 3
    |   - force: (bool) Process jobs even in maintenance mode, default false
    |   - php_version: (string) PHP version, default 'php83'
    |
    */

    'workers' => [
        ['queue' => 'default', 'processes' => 1, 'timeout' => 60, 'sleep' => 10],
        ['queue' => 'ai', 'processes' => 3, 'timeout' => 310, 'sleep' => 3],
        ['queue' => 'notifications', 'processes' => 1, 'timeout' => 60, 'sleep' => 10],
        ['queue' => 'broadcasts', 'processes' => 1, 'timeout' => 60, 'sleep' => 10],
        ['queue' => 'webhooks', 'processes' => 1, 'timeout' => 60, 'sleep' => 10],
        ['queue' => 'slack', 'processes' => 4, 'timeout' => 60, 'sleep' => 8],
        ['queue' => 'meilisearch', 'processes' => 1, 'timeout' => 60, 'sleep' => 10],
        ['queue' => 'meilisearch_customer', 'processes' => 1, 'timeout' => 60, 'sleep' => 10],
        ['queue' => 'video-processing', 'processes' => 1, 'timeout' => 900, 'sleep' => 3],
    ],

    /*
    |--------------------------------------------------------------------------
    | Worker Defaults
    |--------------------------------------------------------------------------
    |
    | Default values applied to all workers unless overridden per-worker above.
    |
    */

    'defaults' => [
        'connection' => 'redis',
        'processes' => 1,
        'timeout' => 60,
        'sleep' => 10,
        'tries' => 3,
        'force' => false,
        'php_version' => 'php83',
    ],

];
