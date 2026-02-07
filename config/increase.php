<?php

return [
    'client_id' => env('INCREASE_CLIENT_ID'),
    'client_secret' => env('INCREASE_CLIENT_SECRET'),
    'webhook_secret' => env('INCREASE_WEBHOOK_SECRET'),
    'environment' => env('INCREASE_ENVIRONMENT', 'sandbox'),
    'sandbox_url' => 'https://sandbox.increase.com/api',
    'production_url' => 'https://api.increase.com',
];
