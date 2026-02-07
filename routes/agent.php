<?php

use App\Http\Controllers\Agent\LoginController;
use App\Http\Controllers\Agent\RegisterController;
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
    Route::post('login', [LoginController::class, 'login']);
    Route::get('register', [RegisterController::class, 'showRegistrationForm'])->name('register');
    Route::post('register', [RegisterController::class, 'register']);
});

// Authenticated routes
Route::middleware(['auth:agent', 'auth.agent'])->group(function () {
    Route::post('logout', [LoginController::class, 'logout'])->name('logout');

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

    // Chat routes
    Route::livewire('chat', 'agent.chat.thread-list')->name('chat.index');
    Route::livewire('chat/{thread}', 'agent.chat.thread-view')->name('chat.show');
});
