<?php

use App\Http\Controllers\Customer\LoginController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Customer Routes
|--------------------------------------------------------------------------
|
| Routes for customer users (homeowners). All routes are prefixed
| with /customer and use the 'customer' guard for authentication.
|
*/

// Guest routes
Route::middleware('guest:customer')->group(function () {
    Route::get('login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('login', [LoginController::class, 'login']);
});

// Authenticated routes
Route::middleware(['auth:customer', 'auth.customer'])->group(function () {
    Route::post('logout', [LoginController::class, 'logout'])->name('logout');

    // Onboarding (excluded from onboarded middleware)
    Route::livewire('onboarding', 'customer.onboarding')->name('onboarding');

    // All other routes require onboarding to be completed
    Route::middleware('auth.customer.onboarded')->group(function () {
        // Dashboard
        Route::livewire('dashboard', 'customer.dashboard')->name('dashboard');

        // Projects (Deals)
        Route::livewire('projects', 'customer.projects.index')->name('projects.index');
        Route::livewire('projects/{deal}', 'customer.projects.show')->name('projects.show');

        // Properties
        Route::livewire('properties', 'customer.properties.index')->name('properties.index');
        Route::livewire('properties/{property}', 'customer.properties.show')->name('properties.show');

        // Estimates
        Route::livewire('estimates', 'customer.estimates.index')->name('estimates.index');
        Route::livewire('estimates/{estimate}', 'customer.estimates.show')->name('estimates.show');

        // Jobs
        Route::livewire('jobs', 'customer.jobs-index')->name('jobs.index');
        Route::livewire('jobs/{job}', 'customer.job-progress')->name('jobs.show');

        // Invoices
        Route::livewire('invoices', 'customer.invoices.index')->name('invoices.index');
        Route::livewire('invoices/{invoice}', 'customer.invoices.show')->name('invoices.show');

        // Referrals
        Route::livewire('referrals', 'customer.referrals')->name('referrals');

        // Chat / Messages
        Route::livewire('chat', 'customer.chat.thread-list')->name('chat.index');
        Route::livewire('chat/{thread}', 'customer.chat.thread-view')->name('chat.show');

        // Settings
        Route::redirect('settings', 'customer/settings/profile');
        Route::livewire('settings/profile', 'customer.settings.profile')->name('settings.profile');
        Route::livewire('settings/password', 'customer.settings.password')->name('settings.password');
        Route::livewire('settings/appearance', 'customer.settings.appearance')->name('settings.appearance');
        Route::livewire('settings/two-factor', 'customer.settings.two-factor')->name('settings.two-factor');
    });
});
