<?php

use App\Http\Controllers\Agent\LoginController;
use App\Http\Controllers\Agent\RegisterController;
use App\Livewire\Agent;
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

    Route::get('dashboard', Agent\Dashboard::class)->name('dashboard');

    Route::view('invitations', 'agent.invitations')->name('invitations');

    // Listing routes
    Route::get('listings', Agent\Listings\Index::class)->name('listings.index');
    Route::get('listings/{agentListing}', Agent\Listings\Show::class)->name('listings.show');
    Route::get('listings/{agentListing}/flyer', [\App\Http\Controllers\Agent\AgentListingFlyerController::class, 'download'])->name('listings.flyer');

    // Customer routes (read-only)
    Route::get('customers', Agent\Customers\Index::class)->name('customers.index');
    Route::get('customers/{customer}', Agent\Customers\Show::class)->name('customers.show');

    // Deal routes (read-only)
    Route::get('deals', Agent\Deals\Index::class)->name('deals.index');

    // Chat routes
    Route::get('chat', Agent\Chat\ThreadList::class)->name('chat.index');
    Route::get('chat/{thread}', Agent\Chat\ThreadView::class)->name('chat.show');
});
