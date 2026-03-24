<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
        'webhook_signing_key' => env('MAILGUN_WEBHOOK_SIGNING_KEY'),
    ],

    'forge' => [
        'token' => env('FORGE_API_TOKEN'),
    ],

    'locationiq' => [
        'key' => env('LOCATIONIQ_API_KEY'),
    ],

    'webpush' => [
        'vapid_public_key' => env('VAPID_PUBLIC_KEY'),
        'vapid_private_key' => env('VAPID_PRIVATE_KEY'),
    ],

    'remotion' => [
        'build_secret' => env('REMOTION_BUILD_SECRET'),
        'timeout_minutes' => env('REMOTION_BUILD_TIMEOUT', 15),
    ],

    'twilio' => [
        'sid' => env('TWILIO_SID'),
        'token' => env('TWILIO_AUTH_TOKEN'),
        'from' => env('TWILIO_PHONE_NUMBER'),
        'enabled' => env('TWILIO_ENABLED', false),
        'daily_limit' => env('TWILIO_DAILY_LIMIT', 500),
        'voice_enabled' => env('TWILIO_VOICE_ENABLED', false),
    ],

    'meta' => [
        'pixel_id' => env('META_PIXEL_ID'),
    ],

    'bunny' => [
        'cdn_url' => env('BUNNY_CDN_URL', ''),
        'enabled' => env('BUNNY_CDN_ENABLED', false),
        'token_key' => env('BUNNY_TOKEN_KEY', ''),
    ],

    'color_visualizer' => [
        'enabled' => env('COLOR_VISUALIZER_ENABLED', false),
        'free_limit' => env('COLOR_VISUALIZER_FREE_LIMIT', 2),
        'image_provider' => env('COLOR_VISUALIZER_IMAGE_PROVIDER', 'openai'),
        'image_model' => env('COLOR_VISUALIZER_IMAGE_MODEL', 'gpt-image-1'),
        'default_agent_prompt' => <<<'PROMPT'
You are a paint color expert for a professional painting company. Your job is to take a customer's description of desired paint colors and resolve each one to an exact hex color code using the PaintColorLookupTool.

When a customer says something like "Benjamin Moore White Dove on the walls and Sherwin-Williams Iron Ore on the trim", you must:

1. Identify each color mentioned and which surface it applies to (walls, trim, ceiling, doors, cabinets, shutters, siding, fascia, etc.)
2. Use the PaintColorLookupTool to look up each color by name or code
3. Return your response in this EXACT format, one line per color:

- Surface: [surface] | Color: [Brand] [Color Name] ([Code]) | Hex: [#HEXVALUE]

Rules:
- Always use the exact hex values from the database — never guess or approximate hex values
- If the customer gives a generic description like "light blue" without a brand, suggest the closest matching paint color from the database and note it as a suggestion
- If a color cannot be found, say so clearly and suggest the closest match
- Common surface mappings: "body" = exterior walls/siding, "accent" = shutters/doors, "trim" = baseboards/crown/window frames
PROMPT,
        'default_image_prompt' => <<<'PROMPT'
Edit this photograph of a room/building. {color_instructions}. Remove any clutter, mess, or personal items from countertops, tables, and surfaces to show a clean, staged look. Fix any wall blemishes, scuffs, or imperfections. Keep the architecture, layout, furniture placement, camera angle, and lighting exactly the same. The result should look like a real, professional real estate photo of this exact space freshly painted.
PROMPT,
    ],

];
