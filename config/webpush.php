<?php

return [
    'vapid' => [
        'subject' => config('app.url'),
        'public_key' => config('services.webpush.vapid_public_key'),
        'private_key' => config('services.webpush.vapid_private_key'),
    ],
];
