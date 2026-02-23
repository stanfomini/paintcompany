<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Email Domain
    |--------------------------------------------------------------------------
    |
    | The domain used for in-app email addresses. Each internal user gets
    | an address like matt@{domain}. This domain must have MX records
    | pointing to Mailgun for inbound delivery.
    |
    */

    'domain' => env('EMAIL_DOMAIN', 'paintingmadeeasydenver.com'),

    /*
    |--------------------------------------------------------------------------
    | Max Attachment Size
    |--------------------------------------------------------------------------
    |
    | Maximum total size of attachments per email in bytes. Default: 25MB.
    |
    */

    'max_attachment_size' => 25 * 1024 * 1024,

];
