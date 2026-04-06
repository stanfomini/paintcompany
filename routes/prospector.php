<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Prospector Routes
|--------------------------------------------------------------------------
|
| Independent dialer hub routes. All prefixed with /prospector and
| named prospector.* via bootstrap/app.php registration.
|
*/

// First-run setup (only auth required, no agent record needed)
Route::middleware(['auth:web', 'auth.internal', 'prospector.access'])->group(function () {
    Route::livewire('/setup', 'pages::prospector.setup')->name('setup');
});

// Authenticated routes (internal users only) — gated by Prospector permissions
Route::middleware(['auth:web', 'auth.internal', 'prospector.access'])->group(function () {
    // Dashboard
    Route::get('/', fn () => redirect()->route('prospector.dashboard'))->name('home');
    Route::livewire('/dashboard', 'pages::prospector.dashboard')->name('dashboard');

    // Agents & Permissions Management
    Route::livewire('/agents', 'pages::prospector.agents')->name('agents')
        ->middleware('prospector.access:agents.view');

    // Settings (Twilio account connection)
    Route::livewire('/settings', 'pages::prospector.settings')->name('settings')
        ->middleware('prospector.access:settings.twilio');

    // Companies
    Route::livewire('/companies', 'pages::prospector.companies')->name('companies')
        ->middleware('prospector.access:companies.view');
    Route::livewire('/companies/{companyId}', 'pages::prospector.company-detail')->name('companies.show')
        ->middleware('prospector.access:companies.view');

    // Phone Numbers
    Route::livewire('/phone-numbers', 'pages::prospector.phone-numbers')->name('phone-numbers')
        ->middleware('prospector.access:settings.twilio');

    // Contacts & Campaigns
    Route::livewire('/contacts', 'pages::prospector.contacts')->name('contacts')
        ->middleware('prospector.access:contacts.view');
    Route::livewire('/reports', 'pages::prospector.reports')->name('reports')
        ->middleware('prospector.access:reports.view');
    Route::livewire('/campaigns', 'pages::prospector.campaigns')->name('campaigns')
        ->middleware('prospector.access:campaigns.view');
    Route::livewire('/campaigns/{campaignId}', 'pages::prospector.campaign-detail')->name('campaigns.show')
        ->middleware('prospector.access:campaigns.view');

    // Dialer
    Route::livewire('/dialer', 'pages::prospector.dialer')->name('dialer')
        ->middleware('prospector.access:dialer.use');
    Route::livewire('/call-history', 'pages::prospector.call-history')->name('call-history')
        ->middleware('prospector.access:recordings.listen');

    // Drip Sequences
    Route::livewire('/sequences', 'pages::prospector.sequences')->name('sequences')
        ->middleware('prospector.access:sequences.view');
    Route::livewire('/sequences/{sequenceId}', 'pages::prospector.sequence-builder')->name('sequences.show')
        ->middleware('prospector.access:sequences.view');

    // AI Call Intelligence
    Route::livewire('/call-intelligence', 'pages::prospector.call-intelligence')->name('call-intelligence')
        ->middleware('prospector.access:recordings.intelligence');

    // Ringless Voicemail
    Route::livewire('/rvm', 'pages::prospector.rvm-manager')->name('rvm')
        ->middleware('prospector.access:rvm.view');

    // Integrations
    Route::livewire('/settings/integrations', 'pages::prospector.integration-settings')->name('settings.integrations')
        ->middleware('prospector.access:settings.integrations');

    // Twilio Voice SDK token
    Route::get('/voice/token', [\App\Http\Controllers\Prospector\TwilioVoiceController::class, 'token'])->name('voice.token');
});

// Webhook routes (no auth, CSRF exempt via bootstrap/app.php)
Route::prefix('webhook')->name('webhook.')->group(function () {
    // Lead intake from external systems
    Route::post('/{companyUlid}/leads', [\App\Http\Controllers\Prospector\LeadWebhookController::class, 'store'])
        ->name('leads');
    Route::post('/{companyUlid}/leads/bulk', [\App\Http\Controllers\Prospector\LeadWebhookController::class, 'bulkStore'])
        ->name('leads.bulk');

    // Twilio voice callbacks
    Route::post('/twilio/voice/status', [\App\Http\Controllers\Prospector\ProspectorWebhookController::class, 'callStatus'])
        ->name('twilio.voice.status');
    Route::post('/twilio/amd', [\App\Http\Controllers\Prospector\ProspectorWebhookController::class, 'amdCallback'])
        ->name('twilio.amd');
    Route::post('/twilio/conference/status', [\App\Http\Controllers\Prospector\ProspectorWebhookController::class, 'conferenceStatus'])
        ->name('twilio.conference.status');
    Route::post('/twilio/conference/recording', [\App\Http\Controllers\Prospector\ProspectorWebhookController::class, 'conferenceRecording'])
        ->name('twilio.conference.recording');

    // Twilio SMS callbacks
    Route::post('/twilio/sms/incoming', [\App\Http\Controllers\Prospector\ProspectorWebhookController::class, 'incomingSms'])
        ->name('twilio.sms.incoming');
    Route::post('/twilio/sms/status', [\App\Http\Controllers\Prospector\ProspectorWebhookController::class, 'smsStatus'])
        ->name('twilio.sms.status');

    // Twilio Voice SDK — TwiML for browser-originated calls
    Route::post('/twilio/voice/incoming', [\App\Http\Controllers\Prospector\TwilioVoiceController::class, 'handleIncoming'])
        ->name('twilio.voice.incoming');

    // Inbound call routing
    Route::post('/twilio/voice/inbound', [\App\Http\Controllers\Prospector\TwilioVoiceController::class, 'handleInboundCall'])
        ->name('twilio.voice.inbound');

    // IVR response handling
    Route::post('/twilio/voice/ivr-response', [\App\Http\Controllers\Prospector\TwilioVoiceController::class, 'handleIvrResponse'])
        ->name('twilio.voice.ivr-response');

    // RVM callbacks
    Route::post('/twilio/rvm/twiml/{drop}', [\App\Http\Controllers\Prospector\ProspectorWebhookController::class, 'rvmTwiml'])
        ->name('twilio.rvm.twiml');
    Route::post('/twilio/rvm/amd/{drop}', [\App\Http\Controllers\Prospector\ProspectorWebhookController::class, 'rvmAmdCallback'])
        ->name('twilio.rvm.amd');
    Route::post('/twilio/rvm/status/{drop}', [\App\Http\Controllers\Prospector\ProspectorWebhookController::class, 'rvmStatusCallback'])
        ->name('twilio.rvm.status');
    Route::post('/twilio/rvm/callback', [\App\Http\Controllers\Prospector\ProspectorWebhookController::class, 'rvmCallback'])
        ->name('twilio.rvm.callback');
});
