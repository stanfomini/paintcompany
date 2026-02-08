<?php

use App\Http\Controllers\Api\VideoDataController;
use App\Http\Controllers\CalendarFeedController;
use App\Http\Controllers\EstimateBreakdownExportController;
use App\Http\Controllers\EstimatePdfController;
use App\Http\Controllers\InvoicePdfController;
use App\Http\Controllers\PainterBreakdownExportController;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
})->name('home');

Route::get('/welcome', function () {
    return view('welcome');
})->name('welcome');

Route::view('dashboard', 'dashboard')
    ->middleware(['auth', 'verified', 'auth.internal'])
    ->name('dashboard');

// Invitation acceptance route (public with signed URL)
Route::livewire('/invitation/accept/{token}', 'invitations.accept-invitation')
    ->middleware('signed')
    ->name('invitation.accept');

// Public open house sign-up route
Route::livewire('/open-house/{property}', 'public.open-house-signup')
    ->name('open-house.signup')
    ->middleware('throttle:60,1');

// Public estimate proposal route
Route::livewire('/proposal/{token}', 'public.estimate-proposal')
    ->name('estimates.public')
    ->middleware('throttle:60,1');

// Public iCal calendar feed (for Google Calendar / Apple Calendar subscription)
Route::get('/calendar/{token}.ics', CalendarFeedController::class)
    ->name('calendar.feed')
    ->middleware('throttle:60,1');

// Invitation management routes (authenticated)
Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('invitations', 'invitations')
        ->name('invitations');
});

// Internal user routes - grouped by permission
Route::middleware(['auth', 'verified', 'auth.internal'])->group(function () {

    // People routes
    Route::middleware(['permission:people.view'])->group(function () {
        Route::livewire('/people', 'people.index')->name('people.index');
        Route::livewire('/customers', 'customers.index')->name('customers.index');
        Route::livewire('/customers/{customer}', 'customers.show')->name('customers.show');
        Route::livewire('/agents', 'agents.index')->name('agents.index');
        Route::livewire('/agents/{user}', 'agents.show')->name('agents.show');
        Route::livewire('/crews', 'crews.index')->name('crews.index');
        Route::livewire('/crews/{user}', 'crews.show')->name('crews.show');
    });

    // Properties routes
    Route::middleware(['permission:properties.view'])->group(function () {
        Route::livewire('/properties', 'properties.index')->name('properties.index');
        Route::livewire('/properties/{property}', 'properties.show')->name('properties.show');
    });

    // Deals routes
    Route::middleware(['permission:deals.view'])->group(function () {
        Route::livewire('/deals', 'deals.pipeline')->name('deals.index');
        Route::livewire('/deals/{deal}', 'deals.show')->name('deals.show');
    });

    // Estimates routes
    Route::middleware(['permission:estimates.view'])->group(function () {
        Route::livewire('/estimates', 'estimates.index')->name('estimates.index');
        Route::livewire('/estimates/create/{deal}', 'estimates.create')
            ->middleware('permission:estimates.create')->name('estimates.create');
        Route::livewire('/estimates/create/{deal}/simple', 'estimates.simple-builder')
            ->middleware('permission:estimates.create')->name('estimates.create.simple');
        Route::livewire('/estimates/create/{deal}/template', 'estimates.template-builder')
            ->middleware('permission:estimates.create')->name('estimates.create.template');
        Route::livewire('/estimates/{estimate}', 'estimates.show')->name('estimates.show');
        Route::get('/estimates/{estimate}/edit', function (\App\Models\Estimate $estimate) {
            return match ($estimate->estimator_mode) {
                \App\Models\Estimate::MODE_SIMPLE => redirect()->route('estimates.edit.simple', $estimate),
                default => redirect()->route('estimates.edit.template', $estimate),
            };
        })->middleware('permission:estimates.edit')->name('estimates.edit');
        Route::livewire('/estimates/{estimate}/edit/simple', 'estimates.simple-builder')
            ->middleware('permission:estimates.edit')->name('estimates.edit.simple');
        Route::livewire('/estimates/{estimate}/edit/template', 'estimates.template-builder')
            ->middleware('permission:estimates.edit')->name('estimates.edit.template');
        Route::get('/estimates/{estimate}/pdf', [EstimatePdfController::class, 'download'])->name('estimates.pdf');

        // Financial breakdowns (separate permissions)
        Route::livewire('/estimates/{estimate}/breakdown', 'estimates.cost-breakdown')
            ->middleware('permission:financials.view_cost_breakdown')
            ->name('estimates.breakdown');
        Route::get('/estimates/{estimate}/breakdown/pdf', [EstimateBreakdownExportController::class, 'pdf'])
            ->middleware('permission:financials.view_cost_breakdown')
            ->name('estimates.breakdown.pdf');
        Route::get('/estimates/{estimate}/breakdown/csv', [EstimateBreakdownExportController::class, 'csv'])
            ->middleware('permission:financials.view_cost_breakdown')
            ->name('estimates.breakdown.csv');
        Route::livewire('/estimates/{estimate}/painter-breakdown', 'estimates.painter-breakdown')
            ->middleware('permission:financials.view_painter_breakdown')
            ->name('estimates.painter-breakdown');
        Route::get('/estimates/{estimate}/painter-breakdown/pdf/work-order', [PainterBreakdownExportController::class, 'workOrder'])
            ->middleware('permission:financials.view_painter_breakdown')
            ->name('estimates.painter-breakdown.pdf.work-order');
        Route::get('/estimates/{estimate}/painter-breakdown/pdf/financials', [PainterBreakdownExportController::class, 'financials'])
            ->middleware('permission:financials.view_painter_breakdown')
            ->name('estimates.painter-breakdown.pdf.financials');
    });

    // Jobs routes
    Route::middleware(['permission:jobs.view'])->group(function () {
        Route::livewire('/jobs', 'jobs.kanban')->name('jobs.index');
        Route::livewire('/jobs/{job}', 'jobs.show')->name('jobs.show');
        Route::livewire('/jobs/{job}/cost-card', 'jobs.cost-card')
            ->middleware('permission:financials.view_cost_breakdown')
            ->name('jobs.cost-card');
    });

    // Planner routes
    Route::livewire('/planner', 'planner.my-day')
        ->middleware('permission:planner.view')
        ->name('planner.index');
    Route::redirect('/activities', '/planner');

    // Production rates routes
    Route::middleware(['permission:production_rates.view'])->group(function () {
        Route::livewire('/production-rates', 'production-rates.index')->name('production-rates.index');
        Route::livewire('/production-rates/area-templates', 'area-templates.index')
            ->name('area-templates.index');
        Route::livewire('/production-rates/area-templates/create', 'area-templates.builder')
            ->middleware('permission:production_rates.manage')
            ->name('area-templates.create');
        Route::livewire('/production-rates/area-templates/{template}/edit', 'area-templates.builder')
            ->middleware('permission:production_rates.manage')
            ->name('area-templates.edit');
    });

    // Chat routes
    Route::middleware(['permission:chat.access'])->group(function () {
        Route::livewire('/chat', 'chat.thread-list')->name('chat.index');
        Route::livewire('/chat/{thread}', 'chat.thread-view')->name('chat.show');
    });

    // Walkie-talkie routes
    Route::livewire('/walkie-talkie/{room?}', 'walkie-talkie.room')
        ->middleware('permission:walkie_talkie.access')
        ->name('walkie-talkie.room');

    // Video call routes
    Route::livewire('/video-call/{room?}', 'video-call.room')
        ->middleware('permission:video_call.access')
        ->name('video-call.room');

    // Master Business Plan (unified page with tabs)
    Route::livewire('/business-plan', 'business-plan.index')
        ->name('business-plan.index')
        ->middleware('permission:business_plan.view');

    // Invoice routes
    Route::middleware(['permission:invoices.view'])->group(function () {
        Route::livewire('/invoices', 'invoices.index')->name('invoices.index');
        Route::livewire('/invoices/{invoice}', 'invoices.show')->name('invoices.show');
        Route::get('/invoices/{invoice}/pdf', [InvoicePdfController::class, 'download'])
            ->name('invoices.pdf');
    });

    // Legacy redirects - keep old URLs working
    Route::get('/roadmap', fn () => redirect()->route('business-plan.index', ['tab' => 'roadmap']))
        ->name('roadmap.index');
    Route::get('/kpis/sales', fn () => redirect()->route('business-plan.index', ['tab' => 'sales-plan']))
        ->name('kpis.sales');
    Route::get('/kpis/production', fn () => redirect()->route('business-plan.index', ['tab' => 'production-plan']))
        ->name('kpis.production');
});

// Public invoice payment page
Route::livewire('/pay/{token}', 'public.invoice-payment')
    ->name('invoice.pay')
    ->middleware('throttle:60,1');

// Stripe webhook (no auth - verified by Stripe signature)
Route::post('/webhooks/stripe', [StripeWebhookController::class, 'handle'])
    ->name('webhooks.stripe')
    ->middleware('throttle:120,1');

// Increase OAuth callback
Route::get('/increase/oauth/callback', [\App\Http\Controllers\IncreaseOAuthController::class, 'callback'])
    ->middleware(['auth', 'verified', 'permission:settings.payment_integrations'])
    ->name('increase.oauth.callback');

// Increase webhook (no auth - verified by Increase signature)
Route::post('/webhooks/increase', [\App\Http\Controllers\IncreaseWebhookController::class, 'handle'])
    ->name('webhooks.increase')
    ->middleware('throttle:120,1');

// Video API endpoint (public for player embeds)
Route::get('/api/video-data/{type}/{id}', [VideoDataController::class, 'show'])
    ->name('api.video-data')
    ->where('type', 'listing|property')
    ->where('id', '[0-9]+');

// Video player page (public)
Route::view('/video/showcase', 'video.showcase')
    ->name('video.showcase');

require __DIR__.'/settings.php';
