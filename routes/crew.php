<?php

use App\Http\Controllers\Crew\LoginController;
use App\Http\Controllers\Crew\RegisterController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Crew Routes
|--------------------------------------------------------------------------
|
| Routes for crew users (paint crews). All routes are prefixed
| with /crew and use the 'crew' guard for authentication.
|
*/

// Guest routes
Route::middleware('guest:crew')->group(function () {
    Route::get('login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('login', [LoginController::class, 'login']);
    Route::get('register', [RegisterController::class, 'showRegistrationForm'])->name('register');
    Route::post('register', [RegisterController::class, 'register']);
});

// Authenticated routes
Route::middleware(['auth:crew', 'auth.crew'])->group(function () {
    Route::post('logout', [LoginController::class, 'logout'])->name('logout');

    Route::livewire('dashboard', 'crew.dashboard')->name('dashboard');

    // Job routes
    Route::livewire('available-jobs', 'crew.available-jobs')->name('jobs.available');
    Route::livewire('available-jobs/{job}', 'crew.job-show')->name('jobs.show');
    Route::livewire('my-jobs', 'crew.my-jobs')->name('jobs.mine');

    // Chat routes
    Route::livewire('chat', 'crew.chat.thread-list')->name('chat.index');
    Route::livewire('chat/{thread}', 'crew.chat.thread-view')->name('chat.show');

    // Settings
    Route::livewire('settings', 'crew.settings')->name('settings');
});
