<?php

use App\Http\Controllers\Customer\LoginController;
use App\Livewire\Customer\Chat\ThreadList as ChatIndex;
use App\Livewire\Customer\Chat\ThreadView as ChatShow;
use App\Livewire\Customer\Dashboard;
use App\Livewire\Customer\Estimates\Index as EstimatesIndex;
use App\Livewire\Customer\Estimates\Show as EstimatesShow;
use App\Livewire\Customer\Projects\Index as ProjectsIndex;
use App\Livewire\Customer\Projects\Show as ProjectsShow;
use App\Livewire\Customer\Properties\Index as PropertiesIndex;
use App\Livewire\Customer\Properties\Show as PropertiesShow;
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

    // Dashboard
    Route::get('dashboard', Dashboard::class)->name('dashboard');

    // Projects (Deals)
    Route::get('projects', ProjectsIndex::class)->name('projects.index');
    Route::get('projects/{deal}', ProjectsShow::class)->name('projects.show');

    // Properties
    Route::get('properties', PropertiesIndex::class)->name('properties.index');
    Route::get('properties/{property}', PropertiesShow::class)->name('properties.show');

    // Estimates
    Route::get('estimates', EstimatesIndex::class)->name('estimates.index');
    Route::get('estimates/{estimate}', EstimatesShow::class)->name('estimates.show');

    // Chat / Messages
    Route::get('chat', ChatIndex::class)->name('chat.index');
    Route::get('chat/{thread}', ChatShow::class)->name('chat.show');
});
