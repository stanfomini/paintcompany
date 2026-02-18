<?php

use App\Http\Controllers\Agent\LoginController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Agent Routes
|--------------------------------------------------------------------------
|
| Routes for agent users (real estate agents). All routes are prefixed
| with /agent and use the 'agent' guard for authentication.
|
*/

// Guest routes
Route::middleware('guest:agent')->group(function () {
    Route::get('login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('login', [LoginController::class, 'login'])->middleware('throttle:5,1');
});

// Authenticated routes (no onboarding check - accessible during onboarding)
Route::middleware(['auth:agent', 'auth.agent'])->group(function () {
    Route::post('logout', [LoginController::class, 'logout'])->name('logout');

    // Onboarding route (must be outside onboarding middleware)
    Route::livewire('onboarding', 'agent.onboarding')->name('onboarding');

    // Routes that require onboarding to be complete
    Route::middleware('auth.agent.onboarded')->group(function () {
        Route::livewire('dashboard', 'agent.dashboard')->name('dashboard');

        Route::view('invitations', 'agent.invitations')->name('invitations');

        // Listing routes
        Route::livewire('listings', 'agent.listings.index')->name('listings.index');
        Route::livewire('listings/{property}', 'agent.listings.show')->name('listings.show');
        Route::get('listings/{property}/flyer', [\App\Http\Controllers\Agent\PropertyFlyerController::class, 'download'])->name('listings.flyer');

        // Customer routes (read-only)
        Route::livewire('customers', 'agent.customers.index')->name('customers.index');
        Route::livewire('customers/{customer}', 'agent.customers.show')->name('customers.show');

        // Deal routes (read-only)
        Route::livewire('deals', 'agent.deals.index')->name('deals.index');

        // Estimate routes (collaboration)
        Route::livewire('estimates/{estimate}', 'agent.estimates.show')->name('estimates.show');

        // Chat routes
        // Referrals
        Route::livewire('referrals', 'agent.referrals')->name('referrals');

        Route::livewire('chat', 'agent.chat.thread-list')->name('chat.index');
        Route::livewire('chat/{thread}', 'agent.chat.thread-view')->name('chat.show');

        // Settings
        Route::redirect('settings', 'agent/settings/profile');
        Route::livewire('settings/profile', 'agent.settings.profile')->name('settings.profile');
        Route::livewire('settings/password', 'agent.settings.password')->name('settings.password');
        Route::livewire('settings/appearance', 'agent.settings.appearance')->name('settings.appearance');
        Route::livewire('settings/two-factor', 'agent.settings.two-factor')->name('settings.two-factor');
    });
});
