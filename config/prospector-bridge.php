<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Model Bridge Enabled
    |--------------------------------------------------------------------------
    |
    | Toggle the model bridge feature on/off. When disabled, no observers
    | are registered and sync does not run.
    |
    */

    'enabled' => env('PROSPECTOR_BRIDGE_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Company Model Mapping
    |--------------------------------------------------------------------------
    |
    | Map your host application's company/client model to Prospector's
    | Company model. Set 'model' to your fully qualified model class.
    | 'fields' maps Prospector field names to your model's field names.
    |
    */

    'companies' => [
        'model' => null, // e.g., \App\Models\Client::class
        'fields' => [
            // 'prospector_field' => 'your_model_field',
            // 'name' => 'business_name',
            // 'timezone' => 'timezone',
            // 'notes' => 'internal_notes',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Contact Model Mapping
    |--------------------------------------------------------------------------
    |
    | Map your host application's lead/contact model to Prospector's
    | Contact model. 'company_key' is the foreign key on your model
    | that links to your company model (used for company association).
    |
    */

    'contacts' => [
        'model' => null, // e.g., \App\Models\Lead::class
        'company_key' => null, // e.g., 'client_id'
        'fields' => [
            // 'prospector_field' => 'your_model_field',
            // 'first_name' => 'first_name',
            // 'last_name' => 'last_name',
            // 'phone' => 'phone_number',
            // 'email' => 'email_address',
            // 'address' => 'street_address',
            // 'city' => 'city',
            // 'state' => 'state',
            // 'zip' => 'zip_code',
            // 'tags' => 'tags',
            // 'custom_fields' => 'metadata',
        ],
    ],

];
