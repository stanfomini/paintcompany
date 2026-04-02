<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Prospector Dialer Configuration
    |--------------------------------------------------------------------------
    */

    'enabled' => env('PROSPECTOR_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Dialer Defaults
    |--------------------------------------------------------------------------
    */

    'defaults' => [
        'wrap_up_seconds' => 30,
        'max_concurrent_lines' => 1,
        'ring_timeout_seconds' => 30,
        'amd_enabled' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Twilio Voice SDK (Browser WebRTC)
    |--------------------------------------------------------------------------
    |
    | API Key + TwiML App SID used to generate Access Tokens for the
    | Twilio Voice JS SDK. The agent's browser connects via WebRTC.
    |
    */

    'twilio' => [
        'api_key_sid' => env('TWILIO_API_KEY_SID'),
        'api_key_secret' => env('TWILIO_API_KEY_SECRET'),
        'twiml_app_sid' => env('TWILIO_TWIML_APP_SID'),
    ],

    /*
    |--------------------------------------------------------------------------
    | TCPA Compliance
    |--------------------------------------------------------------------------
    */

    'tcpa' => [
        'calling_hours_start' => '08:00',
        'calling_hours_end' => '21:00',
        'max_attempts_per_contact' => 3,
        'min_hours_between_attempts' => 4,
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    */

    'rate_limits' => [
        'calls_per_second' => 1,
        'daily_call_limit' => 1000,
        'daily_sms_limit' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Dispositions
    |--------------------------------------------------------------------------
    */

    'dispositions' => [
        'booked' => [
            'label' => 'Booked Appointment',
        ],
        'interested' => [
            'label' => 'Interested',
        ],
        'callback' => [
            'label' => 'Callback Requested',
        ],
        'not_interested' => [
            'label' => 'Not Interested',
        ],
        'no_answer' => [
            'label' => 'No Answer',
            'auto_sequence' => null,
        ],
        'voicemail' => [
            'label' => 'Left Voicemail',
            'auto_sequence' => null,
        ],
        'wrong_number' => [
            'label' => 'Wrong Number',
        ],
        'dnc' => [
            'label' => 'Do Not Call',
        ],
        'busy' => [
            'label' => 'Busy',
        ],
        'disconnected' => [
            'label' => 'Disconnected',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhook
    |--------------------------------------------------------------------------
    */

    'webhook' => [
        'csrf_exempt' => true,
    ],
];
