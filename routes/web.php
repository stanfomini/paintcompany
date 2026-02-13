<?php

use App\Http\Controllers\ActivityCalendarController;
use App\Http\Controllers\ActivityCalendarFeedController;
use App\Http\Controllers\Api\VideoDataController;
use App\Http\Controllers\CalendarFeedController;
use App\Http\Controllers\CrewDocumentPdfController;
use App\Http\Controllers\EstimateBreakdownExportController;
use App\Http\Controllers\EstimatePdfController;
use App\Http\Controllers\InvoicePdfController;
use App\Http\Controllers\PainterBreakdownExportController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\VideoCallPlaybackController;
use App\Http\Controllers\VideoRecordingPlaybackController;
use App\Http\Controllers\VideoRecordingUploadController;
use App\Http\Middleware\SetLocale;
use Illuminate\Support\Facades\Route;

// Language switcher
Route::get('/locale/{locale}', function (string $locale) {
    if (in_array($locale, SetLocale::SUPPORTED_LOCALES)) {
        session()->put('locale', $locale);
    }

    return redirect()->back();
})->name('locale.switch');

// Landing page (public marketing page)
Route::livewire('/', 'public.landing-page')
    ->name('home')
    ->middleware('throttle:60,1');

// Login selector page (moved from /)
Route::get('/login-selector', function () {
    return view('welcome');
})->name('login-selector');

Route::get('/welcome', function () {
    return view('welcome');
})->name('welcome');

// Next steps (post-lead-form)
Route::livewire('/next-steps', 'public.next-steps')
    ->name('next-steps')
    ->middleware('throttle:60,1');

// Public legal pages
Route::livewire('/terms', 'public.terms')
    ->name('legal.terms')
    ->middleware('throttle:60,1');

Route::livewire('/privacy', 'public.privacy')
    ->name('legal.privacy')
    ->middleware('throttle:60,1');

Route::livewire('/referral-terms', 'public.referral-terms')
    ->name('legal.referral-terms')
    ->middleware('throttle:60,1');

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

// Public calculator showcase page (marketing landing page for agents)
Route::livewire('/calculator', 'public.calculator')
    ->name('calculator')
    ->middleware('throttle:60,1');

// Public ROI viewer for agents to share with customers
Route::livewire('/roi/{token}', 'public.roi-viewer')
    ->name('roi.public')
    ->middleware('throttle:60,1');

// Public video call recording playback (signed URL)
Route::get('/recording/{videoCall:room_id}', [VideoCallPlaybackController::class, 'show'])
    ->name('video-call.recording.public')
    ->middleware('throttle:60,1');

// Public iCal calendar feed (for Google Calendar / Apple Calendar subscription)
Route::get('/calendar/{token}.ics', CalendarFeedController::class)
    ->name('calendar.feed')
    ->middleware('throttle:60,1');

// Public iCal calendar feed for activities (for Google Calendar / Apple Calendar subscription)
Route::get('/calendar/{token}/activities.ics', ActivityCalendarFeedController::class)
    ->name('calendar.activities')
    ->middleware('throttle:60,1');

// Invitation management routes (authenticated, internal only)
Route::middleware(['auth', 'verified', 'auth.internal', 'permission:team.manage_invitations'])->group(function () {
    Route::view('invitations', 'invitations')
        ->name('invitations');
});

// Video call test page (uses Reverb for signaling, accessible by all user types)
Route::livewire('/video-call-test/{room?}', 'video-call.test')
    ->middleware(['auth:web,customer,crew,agent'])
    ->name('video-call.test');

// Streaming studio (OBS-style browser broadcasting)
Route::livewire('/studio/{session?}', 'studio.studio')
    ->middleware(['auth:web,agent'])
    ->name('studio');

// Guest participant join (signed URL, 24-hour expiry)
Route::livewire('/studio/join/{token}', 'studio.guest-view')
    ->middleware('signed')
    ->name('studio.join');

// Video call recording API (accessible by all authenticated user types)
Route::middleware(['auth:web,customer,crew,agent'])->prefix('api/video-calls/{videoCall}')->group(function () {
    Route::post('/recording/start', [\App\Http\Controllers\VideoCallRecordingController::class, 'start'])->name('video-call.recording.start');
    Route::post('/recording/chunk', [\App\Http\Controllers\VideoCallRecordingController::class, 'chunk'])->name('video-call.recording.chunk');
    Route::post('/recording/stop', [\App\Http\Controllers\VideoCallRecordingController::class, 'stop'])->name('video-call.recording.stop');
    Route::get('/recording/playback-url', [\App\Http\Controllers\VideoCallRecordingController::class, 'playbackUrl'])->name('video-call.recording.playback');
});

// Video recording API (standalone walkaround recorder, all authenticated user types)
Route::middleware(['auth:web,customer,crew,agent'])->prefix('api/video-recordings')->group(function () {
    Route::post('/', [VideoRecordingUploadController::class, 'store'])->name('video-recordings.store');
    Route::post('/start', [VideoRecordingUploadController::class, 'startChunkedUpload'])->name('video-recordings.start');
    Route::post('/{recording}/chunk', [VideoRecordingUploadController::class, 'uploadChunk'])->name('video-recordings.chunk');
    Route::post('/{recording}/finalize', [VideoRecordingUploadController::class, 'finalizeUpload'])->name('video-recordings.finalize');
    Route::get('/{recording}/status', [VideoRecordingUploadController::class, 'status'])->name('video-recordings.status');
    Route::patch('/{recording}/title', [VideoRecordingUploadController::class, 'updateTitle'])->name('video-recordings.update-title');
    Route::delete('/{recording}', [VideoRecordingUploadController::class, 'destroy'])->name('video-recordings.destroy');
});

// Public video recording playback (share token, no auth)
Route::get('/v/{shareToken}', [VideoRecordingPlaybackController::class, 'show'])
    ->name('video-recording.public')
    ->middleware('throttle:60,1');

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

        // Crew document PDFs
        Route::get('/crews/{user}/documents/w9/pdf', [CrewDocumentPdfController::class, 'w9'])->name('crews.documents.w9');
        Route::get('/crew-contracts/{contract}/pdf', [CrewDocumentPdfController::class, 'contract'])->name('crews.documents.contract');
        Route::get('/crews/{user}/documents/compliance-pack', [CrewDocumentPdfController::class, 'compliancePack'])->name('crews.documents.compliance-pack');
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

    // Activity calendar JSON API
    Route::get('/api/activities/calendar', ActivityCalendarController::class)
        ->middleware('permission:planner.view')
        ->name('activities.calendar');

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

    // Master Business Plan (unified page with tabs)
    Route::livewire('/business-plan', 'business-plan.index')
        ->name('business-plan.index')
        ->middleware('permission:business_plan.view');

    // Referrals admin
    Route::middleware(['permission:referrals.view'])->group(function () {
        Route::livewire('/referrals', 'referrals.index')->name('referrals.index');
    });

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

// Lead source webhook (public - authenticated by token + optional auth header)
Route::post('/webhooks/leads/{token}', \App\Http\Controllers\WebhookLeadController::class)
    ->middleware('throttle:60,1')
    ->name('webhook.lead');

// Video API endpoint (public for player embeds)
Route::get('/api/video-data/{type}/{id}', [VideoDataController::class, 'show'])
    ->name('api.video-data')
    ->where('type', 'listing|property')
    ->where('id', '[0-9]+');

// Video player page (public)
Route::view('/video/showcase', 'video.showcase')
    ->name('video.showcase');

require __DIR__.'/settings.php';
