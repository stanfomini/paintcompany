<?php

return [
    'user_model' => 'App\\Models\\User',
    'route_prefix' => 'prospector',

    'dispositions' => [
        'booked' => ['label' => 'Booked Appointment'],
        'interested' => ['label' => 'Interested'],
        'callback' => ['label' => 'Callback Requested'],
        'not_interested' => ['label' => 'Not Interested'],
        'no_answer' => ['label' => 'No Answer', 'auto_sequence' => null],
        'voicemail' => ['label' => 'Left Voicemail', 'auto_sequence' => null],
        'wrong_number' => ['label' => 'Wrong Number'],
        'dnc' => ['label' => 'Do Not Call'],
        'busy' => ['label' => 'Busy'],
        'disconnected' => ['label' => 'Disconnected'],
    ],

    'webhook' => [
        'csrf_exempt' => true,
    ],
];
