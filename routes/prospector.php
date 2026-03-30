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

// Authenticated routes (internal users only)
Route::middleware(['auth:web', 'auth.internal'])->group(function () {
    // Dashboard
    Route::get('/', fn () => redirect()->route('prospector.dashboard'))->name('home');
    Route::livewire('/dashboard', 'prospector.dashboard')->name('dashboard');

    // Settings (Twilio account connection)
    Route::livewire('/settings', 'prospector.settings')->name('settings');

    // Companies
    Route::livewire('/companies', 'prospector.companies')->name('companies');
    Route::livewire('/companies/{companyId}', 'prospector.company-detail')->name('companies.show');

    // Phone Numbers
    Route::livewire('/phone-numbers', 'prospector.phone-numbers')->name('phone-numbers');

    // Phase 2 - Contacts & Campaigns
    Route::livewire('/contacts', 'prospector.contacts')->name('contacts');
    Route::livewire('/campaigns', 'prospector.campaigns')->name('campaigns');
    Route::livewire('/campaigns/{campaignId}', 'prospector.campaign-detail')->name('campaigns.show');

    // Phase 3 - Dialer
    Route::livewire('/dialer', 'prospector.dialer')->name('dialer');
    Route::livewire('/call-history', 'prospector.call-history')->name('call-history');
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

    // Twilio SMS callbacks
    Route::post('/twilio/sms/incoming', [\App\Http\Controllers\Prospector\ProspectorWebhookController::class, 'incomingSms'])
        ->name('twilio.sms.incoming');
    Route::post('/twilio/sms/status', [\App\Http\Controllers\Prospector\ProspectorWebhookController::class, 'smsStatus'])
        ->name('twilio.sms.status');
});
