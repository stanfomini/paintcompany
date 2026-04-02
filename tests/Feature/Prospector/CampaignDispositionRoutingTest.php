<?php

use App\Models\Prospector\Agent;
use App\Models\Prospector\Call;
use App\Models\Prospector\Campaign;
use App\Models\Prospector\CampaignContact;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Models\Prospector\PhoneNumber;
use App\Models\Prospector\TwilioAccount;
use App\Services\Prospector\AgentStateService;
use App\Services\Prospector\AmdService;
use App\Services\Prospector\CampaignQueueService;
use App\Services\Prospector\ConferenceService;
use App\Services\Prospector\DialerOrchestrationService;
use App\Services\Prospector\NumberSelectionService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    Redis::shouldReceive('get')->andReturn(null)->byDefault();
    Redis::shouldReceive('setex')->andReturn(true)->byDefault();

    // Create DB records BEFORE Event::fake() to avoid interfering with model booted hooks
    $this->account = TwilioAccount::factory()->create();
    $this->company = Company::factory()->forAccount($this->account)->create();
    $this->mainNumber = PhoneNumber::factory()->forAccount($this->account)->main()->create([
        'company_id' => $this->company->id,
        'is_active' => true,
    ]);
    $this->company->update(['main_phone_number_id' => $this->mainNumber->id]);

    $this->agent = Agent::factory()->forCompany($this->company)->offline()->create();
    $this->campaign = Campaign::factory()->active()->create([
        'company_id' => $this->company->id,
    ]);

    $this->contact = Contact::factory()->create([
        'company_id' => $this->company->id,
    ]);

    $this->entry = CampaignContact::factory()->create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $this->contact->id,
        'status' => 'queued',
    ]);

    $this->call = Call::factory()->completed()->create([
        'company_id' => $this->company->id,
        'agent_id' => $this->agent->id,
        'contact_id' => $this->contact->id,
        'campaign_id' => $this->campaign->id,
    ]);

    Event::fake();

    $conferenceMock = Mockery::mock(ConferenceService::class);

    $this->service = new DialerOrchestrationService(
        app(CampaignQueueService::class),
        $conferenceMock,
        new NumberSelectionService,
        new AgentStateService,
        new AmdService,
    );
});

// ──────────────────────────────────────
// Default routing fallbacks
// ──────────────────────────────────────

it('routes dnc disposition to dnc action by default', function () {
    $route = $this->campaign->getDispositionRoute('dnc');

    expect($route['action'])->toBe('dnc');
});

it('routes booked disposition to remove action by default', function () {
    $route = $this->campaign->getDispositionRoute('booked');

    expect($route['action'])->toBe('remove');
});

it('routes wrong_number disposition to remove action by default', function () {
    $route = $this->campaign->getDispositionRoute('wrong_number');

    expect($route['action'])->toBe('remove');
});

it('routes unknown dispositions to stay action by default', function () {
    $route = $this->campaign->getDispositionRoute('no_answer');

    expect($route['action'])->toBe('stay');
});

// ──────────────────────────────────────
// Explicit disposition_routes overrides
// ──────────────────────────────────────

it('returns the explicit route configured on the campaign', function () {
    $this->campaign->update([
        'disposition_routes' => [
            'no_answer' => ['action' => 'move', 'target_campaign_id' => 999],
        ],
    ]);
    $this->campaign->refresh();

    $route = $this->campaign->getDispositionRoute('no_answer');

    expect($route['action'])->toBe('move')
        ->and($route['target_campaign_id'])->toBe(999);
});

// ──────────────────────────────────────
// Stay action — contact re-queued
// ──────────────────────────────────────

it('stay action re-queues the contact after disposition', function () {
    $this->campaign->update([
        'disposition_routes' => ['no_answer' => ['action' => 'stay']],
    ]);
    $this->campaign->refresh();

    $this->service->disposition($this->call, 'no_answer');

    expect($this->entry->fresh()->status)->toBe('queued');
});

// ──────────────────────────────────────
// Remove action — contact completed
// ──────────────────────────────────────

it('remove action marks the contact completed after disposition', function () {
    $this->campaign->update([
        'disposition_routes' => ['booked' => ['action' => 'remove']],
    ]);
    $this->campaign->refresh();

    $this->service->disposition($this->call, 'booked');

    expect($this->entry->fresh()->status)->toBe('completed');
});

it('booked disposition uses remove by default without explicit routes', function () {
    $this->service->disposition($this->call, 'booked');

    expect($this->entry->fresh()->status)->toBe('completed');
});

// ──────────────────────────────────────
// DNC action — contact completed + do_not_call
// ──────────────────────────────────────

it('dnc action marks the contact completed and do_not_call', function () {
    $this->campaign->update([
        'disposition_routes' => ['dnc' => ['action' => 'dnc']],
    ]);
    $this->campaign->refresh();

    $this->service->disposition($this->call, 'dnc');

    expect($this->entry->fresh()->status)->toBe('completed')
        ->and($this->contact->fresh()->do_not_call)->toBeTrue();
});

it('dnc disposition uses dnc action by default without explicit routes', function () {
    $this->service->disposition($this->call, 'dnc');

    expect($this->entry->fresh()->status)->toBe('completed')
        ->and($this->contact->fresh()->do_not_call)->toBeTrue();
});

// ──────────────────────────────────────
// Move action — contact exhausted and enrolled in target
// ──────────────────────────────────────

it('move action exhausts the contact in current campaign and enrolls in target campaign', function () {
    // Create target campaign BEFORE Event::fake() in beforeEach already ran — create fresh here
    // We need to temporarily re-enable model events; simplest is to create & store the id first
    $targetCampaign = Campaign::withoutEvents(fn () => Campaign::factory()->active()->create([
        'company_id' => $this->company->id,
        'ulid' => \Illuminate\Support\Str::ulid()->toBase32(),
    ]));

    $this->campaign->update([
        'disposition_routes' => [
            'no_answer' => ['action' => 'move', 'target_campaign_id' => $targetCampaign->id],
        ],
    ]);
    $this->campaign->refresh();

    $this->service->disposition($this->call, 'no_answer');

    expect($this->entry->fresh()->status)->toBe('exhausted');

    $newEntry = CampaignContact::where('campaign_id', $targetCampaign->id)
        ->where('contact_id', $this->contact->id)
        ->first();

    expect($newEntry)->not->toBeNull()
        ->and($newEntry->status)->toBe('queued');
});

it('move action without target_campaign_id just exhausts the current entry', function () {
    $this->campaign->update([
        'disposition_routes' => [
            'no_answer' => ['action' => 'move'],
        ],
    ]);
    $this->campaign->refresh();

    $this->service->disposition($this->call, 'no_answer');

    expect($this->entry->fresh()->status)->toBe('exhausted');
});

it('move action does not create duplicate enrollment in target campaign', function () {
    $targetCampaign = Campaign::withoutEvents(fn () => Campaign::factory()->active()->create([
        'company_id' => $this->company->id,
        'ulid' => \Illuminate\Support\Str::ulid()->toBase32(),
    ]));

    // Already enrolled in target
    CampaignContact::factory()->create([
        'campaign_id' => $targetCampaign->id,
        'contact_id' => $this->contact->id,
        'status' => 'queued',
    ]);

    $this->campaign->update([
        'disposition_routes' => [
            'no_answer' => ['action' => 'move', 'target_campaign_id' => $targetCampaign->id],
        ],
    ]);
    $this->campaign->refresh();

    $this->service->disposition($this->call, 'no_answer');

    $count = CampaignContact::where('campaign_id', $targetCampaign->id)
        ->where('contact_id', $this->contact->id)
        ->count();

    expect($count)->toBe(1);
});

// ──────────────────────────────────────
// Callback disposition — scheduling + status preserved
// ──────────────────────────────────────

it('callback disposition schedules follow-up and marks status callback_scheduled', function () {
    $this->campaign->update([
        'disposition_routes' => ['callback' => ['action' => 'stay']],
    ]);
    $this->campaign->refresh();

    $this->service->disposition($this->call, 'callback');

    expect($this->entry->fresh()->status)->toBe('callback_scheduled');
});

// ──────────────────────────────────────
// defaultDispositionRoutes helper
// ──────────────────────────────────────

it('defaultDispositionRoutes returns an array keyed by disposition with defaults applied', function () {
    $routes = Campaign::defaultDispositionRoutes();

    expect($routes)->toBeArray()
        ->and($routes)->toHaveKey('dnc')
        ->and($routes['dnc']['action'])->toBe('dnc')
        ->and($routes)->toHaveKey('wrong_number')
        ->and($routes['wrong_number']['action'])->toBe('remove')
        ->and($routes)->toHaveKey('no_answer')
        ->and($routes['no_answer']['action'])->toBe('stay');
});
