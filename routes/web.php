<?php

use App\Http\Controllers\Api\VideoDataController;
use App\Http\Controllers\EstimatePdfController;
use App\Livewire\Activities\TaskList as ActivitiesTaskList;
use App\Livewire\AgentListings\Index as AgentListingsIndex;
use App\Livewire\Agents\Index as AgentsIndex;
use App\Livewire\Agents\Show as AgentsShow;
use App\Livewire\Chat\ThreadList as ChatThreadList;
use App\Livewire\Chat\ThreadView as ChatThreadView;
use App\Livewire\Customers\Index as CustomersIndex;
use App\Livewire\Customers\Show as CustomersShow;
use App\Livewire\Deals\Pipeline as DealsPipeline;
use App\Livewire\Deals\Show as DealsShow;
use App\Livewire\Estimates\Builder as EstimatesBuilder;
use App\Livewire\Estimates\Create as EstimatesCreate;
use App\Livewire\Estimates\Index as EstimatesIndex;
use App\Livewire\Estimates\Show as EstimatesShow;
use App\Livewire\Estimates\SimpleBuilder as EstimatesSimpleBuilder;
use App\Livewire\Estimates\TemplateBuilder as EstimatesTemplateBuilder;
use App\Livewire\Invitations\AcceptInvitation;
use App\Livewire\ProductionRates\Index as ProductionRatesIndex;
use App\Livewire\Properties\Index as PropertiesIndex;
use App\Livewire\Properties\Show as PropertiesShow;
use App\Livewire\Public\EstimateProposal;
use App\Livewire\Public\OpenHouseSignup;
use App\Livewire\VideoCall\Room as VideoCallRoom;
use App\Livewire\WalkieTalkie\Room as WalkieTalkieRoom;
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
Route::get('/invitation/accept/{token}', AcceptInvitation::class)
    ->middleware('signed')
    ->name('invitation.accept');

// Public open house sign-up route
Route::get('/open-house/{agentListing}', OpenHouseSignup::class)
    ->name('open-house.signup')
    ->middleware('throttle:60,1');

// Public estimate proposal route
Route::get('/proposal/{token}', EstimateProposal::class)
    ->name('estimates.public')
    ->middleware('throttle:60,1');

// Invitation management routes (authenticated)
Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('invitations', 'invitations')
        ->name('invitations');
});

// Internal user routes (customers management)
Route::middleware(['auth', 'verified', 'auth.internal'])->group(function () {
    Route::get('/customers', CustomersIndex::class)->name('customers.index');
    Route::get('/customers/{customer}', CustomersShow::class)->name('customers.show');
    Route::get('/properties', PropertiesIndex::class)->name('properties.index');
    Route::get('/properties/{property}', PropertiesShow::class)->name('properties.show');
    Route::get('/deals', DealsPipeline::class)->name('deals.index');
    Route::get('/deals/{deal}', DealsShow::class)->name('deals.show');
    Route::get('/activities', ActivitiesTaskList::class)->name('activities.index');

    // Agent listings routes
    Route::get('/agent-listings', AgentListingsIndex::class)->name('agent-listings.index');

    // Agents routes
    Route::get('/agents', AgentsIndex::class)->name('agents.index');
    Route::get('/agents/{user}', AgentsShow::class)->name('agents.show');

    // Estimates routes
    Route::get('/estimates', EstimatesIndex::class)->name('estimates.index');
    Route::get('/estimates/create/{deal}', EstimatesCreate::class)->name('estimates.create');
    Route::get('/estimates/create/{deal}/simple', EstimatesSimpleBuilder::class)->name('estimates.create.simple');
    Route::get('/estimates/create/{deal}/template', EstimatesTemplateBuilder::class)->name('estimates.create.template');
    Route::get('/estimates/create/{deal}/detailed', EstimatesBuilder::class)->name('estimates.create.detailed');
    Route::get('/estimates/{estimate}', EstimatesShow::class)->name('estimates.show');
    Route::get('/estimates/{estimate}/edit', EstimatesBuilder::class)->name('estimates.edit');
    Route::get('/estimates/{estimate}/edit/simple', EstimatesSimpleBuilder::class)->name('estimates.edit.simple');
    Route::get('/estimates/{estimate}/edit/template', EstimatesTemplateBuilder::class)->name('estimates.edit.template');
    Route::get('/estimates/{estimate}/pdf', [EstimatePdfController::class, 'download'])->name('estimates.pdf');

    // Production rates routes (includes substrates, paint catalog, nested templates)
    Route::get('/production-rates', ProductionRatesIndex::class)->name('production-rates.index');

    // Area Templates routes (under Production Rates section)
    Route::get('/production-rates/area-templates', \App\Livewire\AreaTemplates\Index::class)
        ->name('area-templates.index');
    Route::get('/production-rates/area-templates/create', \App\Livewire\AreaTemplates\Builder::class)
        ->name('area-templates.create');
    Route::get('/production-rates/area-templates/{template}/edit', \App\Livewire\AreaTemplates\Builder::class)
        ->name('area-templates.edit');

    // Chat routes
    Route::get('/chat', ChatThreadList::class)->name('chat.index');
    Route::get('/chat/{thread}', ChatThreadView::class)->name('chat.show');

    // Walkie-talkie routes
    Route::get('/walkie-talkie/{room?}', WalkieTalkieRoom::class)->name('walkie-talkie.room');

    // Video call routes
    Route::get('/video-call/{room?}', VideoCallRoom::class)->name('video-call.room');
});

// Video API endpoint (public for player embeds)
Route::get('/api/video-data/{type}/{id}', [VideoDataController::class, 'show'])
    ->name('api.video-data')
    ->where('type', 'listing|property')
    ->where('id', '[0-9]+');

// Video player page (public)
Route::view('/video/showcase', 'video.showcase')
    ->name('video.showcase');

require __DIR__.'/settings.php';
