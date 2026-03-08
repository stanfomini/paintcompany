<?php

use App\Http\Controllers\ActivityCalendarController;
use App\Http\Controllers\ActivityCalendarFeedController;
use App\Http\Controllers\Api\VideoDataController;
use App\Http\Controllers\CalendarFeedController;
use App\Http\Controllers\ContractPdfController;
use App\Http\Controllers\CorporateDocumentPdfController;
use App\Http\Controllers\CrewDocumentPdfController;
use App\Http\Controllers\EstimateBreakdownExportController;
use App\Http\Controllers\EstimatePdfController;
use App\Http\Controllers\InvoicePdfController;
use App\Http\Controllers\PainterBreakdownExportController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\ScheduleCalendarFeedController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\SubcontractorExportController;
use App\Http\Controllers\VideoCallPlaybackController;
use App\Http\Controllers\VideoRecordingPlaybackController;
use App\Http\Controllers\VideoRecordingStreamController;
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

// Service area location pages (local SEO)
Route::livewire('/areas', 'public.areas-index')
    ->name('areas.index')
    ->middleware('throttle:60,1');

Route::livewire('/areas/{slug}', 'public.area-page')
    ->name('areas.show')
    ->middleware('throttle:60,1');

Route::livewire('dashboard', 'dashboard')
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

// Public vendor onboarding route
Route::livewire('/vendor/onboarding/{token}', 'public.vendor-onboarding')
    ->name('vendor.onboarding')
    ->middleware('throttle:60,1');

// Public communications hub showcase page
Route::livewire('/features/communications-hub', 'public.communications-hub')
    ->name('features.communications-hub')
    ->middleware('throttle:60,1');

// Public calculator showcase page (marketing landing page for agents)
Route::livewire('/calculator', 'public.calculator')
    ->name('calculator')
    ->middleware('throttle:60,1');

// Public booking routes
Route::livewire('/book', 'public.vendor-booking')
    ->name('booking.general')
    ->middleware('throttle:60,1');

Route::livewire('/book/{slug}', 'public.vendor-booking')
    ->name('booking.vendor')
    ->middleware('throttle:60,1');

Route::livewire('/book/messenger/{token}', 'public.messenger-booking')
    ->name('booking.messenger')
    ->middleware('throttle:60,1');

Route::livewire('/booking/{token}/confirmed', 'public.booking-confirmed')
    ->name('booking.confirmed')
    ->middleware('throttle:60,1');

Route::livewire('/booking/{token}/cancel', 'public.booking-cancel')
    ->name('booking.cancel')
    ->middleware('throttle:60,1');

// Public color visualizer
Route::livewire('/visualize', 'public.color-visualizer')
    ->name('visualize')
    ->middleware('throttle:30,1');

Route::livewire('/visualize/{uuid}', 'public.visualization-result')
    ->name('visualize.result')
    ->middleware('throttle:60,1');

// Public ROI viewer for agents to share with customers
Route::livewire('/roi/{token}', 'public.roi-viewer')
    ->name('roi.public')
    ->middleware('throttle:60,1');

// Public careers routes
Route::livewire('/careers', 'public.careers')
    ->name('careers')
    ->middleware('throttle:60,1');

Route::livewire('/careers/apply/{jobListing?}', 'public.apply')
    ->name('careers.apply')
    ->middleware('throttle:30,1');

Route::get('/careers/qr/{token}', function (string $token) {
    $listing = \App\Models\JobListing::where('qr_code_token', $token)->firstOrFail();

    return redirect()->route('careers.apply', ['jobListing' => $listing, 'source' => 'qr_code']);
})->name('careers.qr')->middleware('throttle:60,1');

// Chat audio streaming (signed URL, proper headers for browser playback)
Route::get('/chat-audio/{filename}', [\App\Http\Controllers\ChatAudioController::class, 'stream'])
    ->name('chat.audio.stream')
    ->middleware(['signed', 'throttle:120,1']);

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

// Public iCal calendar feed for employee work schedule
Route::get('/calendar/{token}/schedule.ics', ScheduleCalendarFeedController::class)
    ->name('calendar.schedule-feed')
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

// Guest recording invite (public, no auth - token serves as authentication)
Route::livewire('/record/{token}', 'studio.public-recorder')
    ->name('recording.invite')
    ->middleware('throttle:30,1');

// Studio - Feed (deal browser, my videos, recordings, media hub)
Route::livewire('/studio', 'studio.studio-feed')
    ->middleware(['auth:web,agent'])
    ->name('studio');

// Studio - Quick Creator
Route::livewire('/studio/create/{videoProject?}', 'studio.quick-creator')
    ->middleware(['auth:web,agent'])
    ->name('studio.create');

// Studio - Create from Deal
Route::get('/studio/create-from-deal/{deal}', function (\App\Models\Deal $deal) {
    $project = \App\Models\VideoProject::create([
        'user_id' => auth()->id(),
        'title' => $deal->title.' Video',
        'status' => 'draft',
        'creation_mode' => 'quick',
        'aspect_ratio' => '9:16',
        'metadata' => ['deal_id' => $deal->id],
    ]);

    return redirect()->route('studio.create', $project);
})->middleware(['auth:web,agent'])->name('studio.create-from-deal');

// Studio - Project Editor
Route::livewire('/studio/{videoProject}/edit', 'studio.editor')
    ->middleware(['auth:web,agent'])
    ->name('studio.edit');

// Studio - Remotion AI Video Builder
Route::livewire('/studio/remotion', 'studio.remotion-builder')
    ->middleware(['auth:web,agent'])
    ->name('studio.remotion');

// Studio - Public share view (no auth, token-based)
Route::livewire('/studio/share/{token}', 'studio.share-view')
    ->name('studio.share')
    ->middleware('throttle:60,1');

// Studio - Live Streaming (deprioritized, hidden from nav)
Route::livewire('/studio/live/{session?}', 'studio.studio')
    ->middleware(['auth:web,agent'])
    ->name('studio.live');

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
    Route::get('/{recording}/stream', [VideoRecordingStreamController::class, 'stream'])->name('video-recordings.stream');
    Route::get('/{recording}/download', [VideoRecordingStreamController::class, 'download'])->name('video-recordings.download');
    Route::get('/{recording}/thumbnail', [VideoRecordingStreamController::class, 'thumbnail'])->name('video-recordings.thumbnail');
    Route::post('/', [VideoRecordingUploadController::class, 'store'])->name('video-recordings.store');
    Route::post('/start', [VideoRecordingUploadController::class, 'startChunkedUpload'])->name('video-recordings.start');
    Route::post('/{recording}/chunk', [VideoRecordingUploadController::class, 'uploadChunk'])->name('video-recordings.chunk');
    Route::post('/{recording}/finalize', [VideoRecordingUploadController::class, 'finalizeUpload'])->name('video-recordings.finalize');
    Route::get('/{recording}/status', [VideoRecordingUploadController::class, 'status'])->name('video-recordings.status');
    Route::patch('/{recording}/title', [VideoRecordingUploadController::class, 'updateTitle'])->name('video-recordings.update-title');
    Route::delete('/{recording}', [VideoRecordingUploadController::class, 'destroy'])->name('video-recordings.destroy');
});

// Push notification subscription (all authenticated user types)
Route::middleware(['auth:web,customer,crew,agent'])->group(function () {
    Route::post('/push/subscribe', [PushSubscriptionController::class, 'store'])->name('push.subscribe');
    Route::delete('/push/unsubscribe', [PushSubscriptionController::class, 'destroy'])->name('push.unsubscribe');
});

// Public video recording playback (share token, no auth)
Route::get('/v/{shareToken}', [VideoRecordingPlaybackController::class, 'show'])
    ->name('video-recording.public')
    ->middleware('throttle:60,1');

// Public watch hub (no auth)
Route::livewire('/watch', 'public.watch-hub')
    ->name('watch.index')
    ->middleware('throttle:60,1');
Route::livewire('/watch/{channel:slug}', 'public.watch-channel')
    ->name('watch.channel')
    ->middleware('throttle:60,1');
Route::livewire('/watch/{channel:slug}/{recording}', 'public.watch-player')
    ->name('watch.player')
    ->middleware('throttle:60,1');

// Media Hub API (view tracking - public, rate limited)
Route::post('/api/video-views', [\App\Http\Controllers\VideoViewController::class, 'store'])
    ->name('api.video-views.store')
    ->middleware('throttle:120,1');

// Media Hub API (email tracking pixel/click)
Route::get('/api/video-emails/{videoEmail}/pixel', [\App\Http\Controllers\VideoEmailTrackingController::class, 'pixel'])
    ->name('api.video-emails.pixel')
    ->middleware('throttle:120,1');
Route::get('/api/video-emails/{videoEmail}/click', [\App\Http\Controllers\VideoEmailTrackingController::class, 'click'])
    ->name('api.video-emails.click')
    ->middleware('throttle:120,1');

// Internal user routes - grouped by permission
Route::middleware(['auth', 'verified', 'auth.internal'])->group(function () {

    // People routes
    Route::middleware(['permission:people.view'])->group(function () {
        Route::livewire('/people', 'people.index')->name('people.index');
        Route::livewire('/customers', 'customers.index')->name('customers.index');
        Route::livewire('/customers/{customer}', 'customers.show')->name('customers.show');
        Route::livewire('/customers/{customer}/merge/{duplicate}', 'customers.merge')->name('customers.merge');
        Route::livewire('/customers/{customer}/communications', 'customers.communications-hub')->name('customers.communications');
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

        // Color visualizer with estimate context
        Route::livewire('/estimates/{estimate}/visualize', 'estimates.color-visualizer')
            ->middleware('permission:estimates.edit')
            ->name('estimates.visualize');
    });

    // Standalone color visualizer (no estimate context)
    Route::livewire('/color-visualizer', 'estimates.color-visualizer')
        ->name('color-visualizer');

    // Jobs routes
    Route::middleware(['permission:jobs.view'])->group(function () {
        Route::livewire('/jobs', 'jobs.kanban')->name('jobs.index');
        Route::livewire('/jobs/{job}', 'jobs.show')->name('jobs.show');
        Route::livewire('/jobs/{job}/cost-card', 'jobs.cost-card')
            ->middleware('permission:financials.view_cost_breakdown')
            ->name('jobs.cost-card');
        Route::livewire('/jobs/{job}/painter-breakdown', 'jobs.painter-breakdown')
            ->middleware('permission:financials.view_painter_breakdown')
            ->name('jobs.painter-breakdown');
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

    // Communications hub (unified chat + email)
    Route::livewire('/communications', 'communications.hub')
        ->name('communications.index');

    // Chat routes
    Route::middleware(['permission:chat.access'])->group(function () {
        Route::redirect('/chat', '/communications')->name('chat.index');
        Route::livewire('/chat/{thread}', 'chat.thread-view')->name('chat.show');
    });

    // Email routes
    Route::middleware(['permission:email.access'])->group(function () {
        Route::livewire('/email', 'email.inbox')->name('email.inbox');
        Route::livewire('/email/setup', 'email.account-setup')->name('email.setup');
        Route::get('/email/attachments/{attachment}/download', [\App\Http\Controllers\EmailAttachmentController::class, 'download'])
            ->name('email.attachment.download');
        Route::get('/email/attachments/{attachment}/preview', [\App\Http\Controllers\EmailAttachmentController::class, 'preview'])
            ->name('email.attachment.preview');
    });

    // Walkie-talkie routes
    Route::livewire('/walkie-talkie/{room?}', 'walkie-talkie.room')
        ->middleware('permission:walkie_talkie.access')
        ->name('walkie-talkie.room');

    // Master Business Plan (unified page with tabs)
    Route::livewire('/business-plan', 'business-plan.index')
        ->name('business-plan.index')
        ->middleware('permission:business_plan.view');

    // Corporate document PDF routes
    Route::middleware(['permission:business_plan.view'])->prefix('corporate')->group(function () {
        Route::get('/documents/{corporateDocument}/pdf', [CorporateDocumentPdfController::class, 'download'])
            ->name('corporate.document.pdf');
        Route::get('/documents/{corporateDocument}/view', [CorporateDocumentPdfController::class, 'view'])
            ->name('corporate.document.view');
        Route::get('/minutes/{meetingMinutes}/pdf', [CorporateDocumentPdfController::class, 'downloadMinutes'])
            ->name('corporate.minutes.pdf');
        Route::get('/minutes/{meetingMinutes}/audio', [CorporateDocumentPdfController::class, 'streamAudio'])
            ->name('corporate.minutes.audio');
        Route::get('/packet/download', [CorporateDocumentPdfController::class, 'downloadPacket'])
            ->name('corporate.packet.download');
        Route::post('/packet/send', [CorporateDocumentPdfController::class, 'sendPacket'])
            ->middleware('permission:business_plan.manage')
            ->name('corporate.packet.send');
    });

    // Media Hub routes
    Route::middleware(['permission:media.view'])->group(function () {
        Route::livewire('/media', 'media.hub')->name('media.index');
        Route::livewire('/media/videos/{recording}', 'media.video-show')->name('media.video.show');
        Route::livewire('/media/channels/{channel}', 'media.channel-manage')
            ->middleware('permission:media.manage_channels')
            ->name('media.channel.manage');
        Route::livewire('/media/training/dashboard', 'media.training-dashboard')
            ->middleware('permission:media.manage_training')
            ->name('media.training.dashboard');
        Route::livewire('/media/training/{trainingPath}', 'media.training-manage')
            ->middleware('permission:media.manage_training')
            ->name('media.training.manage');
    });

    // Bookings routes
    Route::livewire('/bookings', 'bookings.index')
        ->middleware('permission:settings.company')
        ->name('bookings.index');

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

    // Employee routes
    Route::middleware(['permission:employees.view'])->group(function () {
        Route::livewire('/employees', 'employees.index')->name('employees.index');
        Route::livewire('/employees/{employeeProfile}', 'employees.show')->name('employees.show');
        Route::livewire('/document-center', 'employees.document-center')->name('document-center');
    });

    // Contract template routes
    Route::middleware(['permission:settings.contracts'])->group(function () {
        Route::livewire('/contracts/templates', 'contracts.templates')->name('contracts.templates');
        Route::livewire('/contracts/templates/{contractTemplate}/edit', 'contracts.template-editor')->name('contracts.templates.edit');
        Route::get('/contracts/templates/{contractTemplate}/pdf-preview', [ContractPdfController::class, 'templatePdfPreview'])->name('contracts.templates.pdf-preview');
    });

    // Employee contract routes
    Route::middleware(['permission:employees.view'])->group(function () {
        Route::livewire('/contracts/{employeeContract}', 'contracts.show')->name('contracts.show');
        Route::get('/contracts/{employeeContract}/pdf', [ContractPdfController::class, 'download'])->name('contracts.pdf');
        Route::get('/contracts/{employeeContract}/document', [ContractPdfController::class, 'viewDocument'])->name('contracts.document');
        Route::get('/contracts/templates/{contractTemplate}/assign-preview', [ContractPdfController::class, 'templatePdfForAssign'])->name('contracts.templates.assign-preview');
    });

    // Hiring routes
    Route::middleware(['permission:hiring.view'])->group(function () {
        Route::livewire('/hiring', 'hiring.index')->name('hiring.index');
        Route::livewire('/hiring/listings', 'hiring.job-listings')->name('hiring.listings');
        Route::livewire('/hiring/pipeline', 'hiring.applicant-pipeline')->name('hiring.pipeline');
    });

    // Time tracking routes
    Route::middleware(['permission:time_tracking.view'])->group(function () {
        Route::livewire('/time-tracking', 'time-tracking.dashboard')->name('time-tracking.index');
        Route::livewire('/time-tracking/timesheet/{employeeProfile?}', 'time-tracking.employee-timesheet')->name('time-tracking.timesheet');
    });

    // Onboarding routes
    Route::middleware(['permission:onboarding.view'])->group(function () {
        Route::livewire('/onboarding', 'onboarding.checklist-manager')->name('onboarding.index');
        Route::livewire('/employees/{employeeProfile}/onboarding', 'onboarding.employee-progress')->name('onboarding.progress');
    });

    // Scheduling routes
    Route::middleware(['permission:scheduling.view'])->group(function () {
        Route::livewire('/scheduling', 'scheduling.calendar')->name('scheduling.index');
        Route::livewire('/scheduling/pto', 'scheduling.pto-requests')->name('scheduling.pto');
        Route::livewire('/scheduling/employee/{employeeProfile?}', 'scheduling.employee-schedule')->name('scheduling.employee');
        Route::livewire('/scheduling/availability/{employeeProfile}', 'scheduling.availability')->name('scheduling.availability');
    });

    // Payroll routes
    Route::middleware(['permission:payroll.view'])->group(function () {
        Route::livewire('/payroll', 'payroll.index')->name('payroll.index');
        Route::livewire('/payroll/stubs/{payStub}', 'payroll.pay-stub')->name('payroll.stub');
        Route::livewire('/payroll/deductions/{employeeProfile}', 'payroll.deductions')
            ->middleware('permission:payroll.manage')
            ->name('payroll.deductions');
        Route::livewire('/payroll/commissions', 'payroll.commissions')
            ->middleware('permission:payroll.manage')
            ->name('payroll.commissions');
        Route::livewire('/payroll/{period}', 'payroll.run')->name('payroll.run');

        Route::get('/payroll/stubs/{payStub}/check', function (\App\Models\PayStub $payStub) {
            abort_unless(auth()->user()->can('payroll.manage'), 403);

            $payStub->load(['employeeProfile.user', 'payPeriod']);

            $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.payroll-check', [
                'stub' => $payStub,
                'employee' => $payStub->employeeProfile,
                'period' => $payStub->payPeriod,
                'company' => \App\Models\CompanySetting::getInstance(),
            ]);

            return $pdf->download("check-{$payStub->check_number}.pdf");
        })->middleware('permission:payroll.manage')->name('payroll.check');
    });

    // Employee onboarding wizard
    Route::livewire('/employee/onboarding', 'employees.employee-onboarding')->name('employee.onboarding');

    // Employee self-service routes (any internal user with employee profile)
    Route::livewire('/my/profile', 'employees.my-profile')->name('my.profile');
    Route::livewire('/my/schedule', 'scheduling.employee-schedule')->name('my.schedule');
    Route::livewire('/my/availability', 'scheduling.availability')->name('my.availability');
    Route::livewire('/my/timesheet', 'time-tracking.employee-timesheet')->name('my.timesheet');
    Route::livewire('/my/pay-stubs', 'payroll.my-pay-stubs')->name('my.pay-stubs');
    Route::livewire('/my/contracts', 'contracts.my-contracts')->name('my.contracts');
    Route::livewire('/my/documents', 'employees.my-documents')->name('my.documents');
    Route::livewire('/my/onboarding', 'onboarding.my-progress')->name('my.onboarding');
    Route::livewire('/my/pto', 'scheduling.my-pto')->name('my.pto');
    Route::livewire('/my/commissions', 'payroll.my-commissions')->name('my.commissions');
    Route::livewire('/my/training', 'media.training-center')->name('my.training');
    Route::livewire('/my/training/{trainingPath}/{step}', 'media.training-player')->name('my.training.play');

    // Sales Territory routes
    Route::middleware(['permission:sales_territories.view'])->group(function () {
        Route::livewire('/territories', 'sales.territory-manager')->name('territories.index');
        Route::livewire('/territories/{territory}', 'sales.territory-manager')->name('territories.show');
    });

    Route::livewire('/door-knocks', 'sales.door-knock-map')
        ->middleware('permission:door_knocks.record')
        ->name('door-knocks.index');

    Route::livewire('/territory-queue', 'sales.territory-queue')
        ->middleware('permission:territory_dispatch.claim')
        ->name('territory-queue');

    Route::livewire('/territory-dashboard', 'sales.territory-dashboard')
        ->middleware('permission:sales_territories.view')
        ->name('territory-dashboard');

    Route::livewire('/territory-analytics', 'sales.territory-analytics')
        ->middleware('permission:sales_territories.view')
        ->name('territory-analytics');

    Route::livewire('/compensation-planner', 'sales.compensation-planner')
        ->middleware('permission:compensation.planner')
        ->name('compensation-planner');

    // AR Portal routes
    Route::middleware(['permission:ar.view'])->group(function () {
        Route::livewire('/ar', 'accounting.ar-index')->name('ar.index');
        Route::livewire('/ar/{deal}', 'accounting.ar-deal')->name('ar.show');
    });

    // Payments Hub routes
    Route::livewire('/payments', 'accounting.payments-hub')
        ->middleware('permission:ar.view')
        ->name('payments.index');

    Route::get('/payments/1099-export/{year}', [SubcontractorExportController::class, 'export'])
        ->middleware('permission:ar.manage')
        ->name('payments.1099-export');

    // Financial summary routes
    Route::livewire('/team/{user}/financial-summary', 'team.financial-summary')
        ->middleware('permission:team.view_financials')
        ->name('team.financial-summary');

    Route::livewire('/crew/{user}/financial-summary', 'crew.financial-summary')
        ->middleware('permission:crew.view_financials')
        ->name('crew.financial-summary');

    // Legacy redirects - keep old URLs working
    Route::get('/roadmap', fn () => redirect()->route('business-plan.index', ['tab' => 'roadmap']))
        ->name('roadmap.index');
    Route::get('/kpis/sales', fn () => redirect()->route('business-plan.index', ['tab' => 'sales-plan']))
        ->name('kpis.sales');
    Route::get('/kpis/production', fn () => redirect()->route('business-plan.index', ['tab' => 'production-plan']))
        ->name('kpis.production');
});

// Employee contract signing (authenticated, owner-only)
Route::middleware(['auth:web,crew'])->group(function () {
    Route::livewire('/sign/{token}', 'contracts.signing')
        ->name('contracts.sign');

    Route::get('/sign/{token}/pdf', [ContractPdfController::class, 'publicDownload'])
        ->name('contracts.sign.pdf');

    Route::get('/sign/{token}/document', [ContractPdfController::class, 'publicViewDocument'])
        ->name('contracts.sign.document');

    Route::get('/sign/{token}/template-pdf', [ContractPdfController::class, 'publicTemplatePdf'])
        ->name('contracts.sign.template-pdf');
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

// Remotion build callback (verified by API secret header)
Route::post('/api/remotion/build-complete', \App\Http\Controllers\Api\RemotionBuildCallbackController::class)
    ->middleware('throttle:30,1')
    ->name('api.remotion.build-complete');

// AI Build webhooks (verified by API secret header)
Route::post('/webhooks/ai-build', [\App\Http\Controllers\Api\AiBuildWebhookController::class, 'trigger'])
    ->middleware('throttle:30,1')
    ->name('webhooks.ai-build');
Route::get('/webhooks/ai-build/{requestId}', [\App\Http\Controllers\Api\AiBuildWebhookController::class, 'status'])
    ->middleware('throttle:30,1')
    ->name('webhooks.ai-build.status');
Route::post('/webhooks/ai-build-callback', [\App\Http\Controllers\Api\AiBuildCallbackController::class, 'handle'])
    ->middleware('throttle:30,1')
    ->name('webhooks.ai-build-callback');

// Automation webhook (public - authenticated by token + optional auth header)
Route::post('/webhooks/automation/{token}', \App\Http\Controllers\AutomationWebhookController::class)
    ->middleware('throttle:60,1')
    ->name('webhook.automation');

// Video API endpoint (public for player embeds)
Route::get('/api/video-data/{type}/{id}', [VideoDataController::class, 'show'])
    ->name('api.video-data')
    ->where('type', 'listing|property')
    ->where('id', '[0-9]+');

// Video player page (public)
Route::view('/video/showcase', 'video.showcase')
    ->name('video.showcase');

// Email inbound webhook (Mailgun)
Route::post('/webhooks/email/inbound', [\App\Http\Controllers\EmailInboundWebhookController::class, 'handle'])
    ->name('webhooks.email.inbound')
    ->middleware('throttle:120,1');

// Facebook Messenger webhook (no auth - verified by signature)
Route::get('/webhook/facebook/receive', [\App\Http\Controllers\FacebookWebhookController::class, 'verify'])
    ->name('webhook.facebook.verify')
    ->middleware('throttle:60,1');
Route::post('/webhook/facebook/receive', [\App\Http\Controllers\FacebookWebhookController::class, 'receive'])
    ->name('webhook.facebook.receive')
    ->middleware('throttle:120,1');

// Twilio SMS webhook (no auth - verified by Twilio signature)
Route::post('/webhooks/twilio/sms', [\App\Http\Controllers\TwilioWebhookController::class, 'receive'])
    ->name('webhooks.twilio.sms')
    ->middleware('throttle:120,1');

require __DIR__.'/settings.php';
