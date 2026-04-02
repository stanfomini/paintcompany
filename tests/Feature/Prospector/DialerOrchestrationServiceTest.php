<?php

use App\Events\Prospector\ContactDispositioned;
use App\Events\Prospector\DialerCallConnected;
use App\Events\Prospector\DialerCallEnded;
use App\Events\Prospector\DialerCallRinging;
use App\Models\Prospector\Agent;
use App\Models\Prospector\Call;
use App\Models\Prospector\Campaign;
use App\Models\Prospector\CampaignContact;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Models\Prospector\DialerSession;
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
        'max_calls_per_day' => 3,
        'max_total_attempts' => 10,
        'max_days_in_campaign' => 30,
        'min_minutes_between' => 0,
        'calling_hours_start' => null,
        'calling_hours_end' => null,
    ]);

    // A ready-to-call contact and queue entry, created BEFORE any Event::fake
    $this->contact = Contact::factory()->create([
        'company_id' => $this->company->id,
        'timezone' => 'America/Denver',
    ]);
    $this->entry = CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $this->contact->id,
        'status' => 'queued',
        'calls_today' => 0,
        'total_attempts' => 0,
    ]);

    // Mock ConferenceService to avoid Twilio API calls
    $this->conferenceMock = Mockery::mock(ConferenceService::class);
    $this->conferenceMock->shouldReceive('dialContactIntoConference')
        ->andReturn('CA1234567890abcdef1234567890abcdef')
        ->byDefault();

    $this->service = new DialerOrchestrationService(
        app(CampaignQueueService::class),
        $this->conferenceMock,
        new NumberSelectionService,
        new AgentStateService,
        new AmdService,
    );
});

// ──────────────────────────────────────
// Session lifecycle
// ──────────────────────────────────────

it('starts a session with a conference name and sets agent available', function () {
    $session = $this->service->startSession($this->agent, $this->campaign);

    expect($session)->toBeInstanceOf(DialerSession::class);
    expect($session->agent_id)->toBe($this->agent->id);
    expect($session->campaign_id)->toBe($this->campaign->id);
    expect($session->status)->toBe('active');
    expect($session->conference_name)->not->toBeNull();
    expect($this->agent->fresh()->status)->toBe('available');
});

it('pauses a session and sets agent to break', function () {
    $session = $this->service->startSession($this->agent, $this->campaign);
    $this->service->pauseSession($session);

    expect($session->fresh()->status)->toBe('paused');
    expect($this->agent->fresh()->status)->toBe('break');
});

it('resumes a paused session and sets agent available', function () {
    $session = $this->service->startSession($this->agent, $this->campaign);
    $this->service->pauseSession($session);
    $this->service->resumeSession($session);

    expect($session->fresh()->status)->toBe('active');
    expect($this->agent->fresh()->status)->toBe('available');
});

it('completes the session on end when calls were attempted', function () {
    $session = $this->service->startSession($this->agent, $this->campaign);
    $session->update(['calls_attempted' => 1]);
    $this->service->endSession($session);

    expect($session->fresh()->status)->toBe('completed');
    expect($session->fresh()->ended_at)->not->toBeNull();
    expect($this->agent->fresh()->status)->toBe('offline');
});

it('abandons the session on end when no calls were attempted', function () {
    $session = $this->service->startSession($this->agent, $this->campaign);
    $this->service->endSession($session);

    expect($session->fresh()->status)->toBe('abandoned');
    expect($this->agent->fresh()->status)->toBe('offline');
});

// ──────────────────────────────────────
// dialNext
// ──────────────────────────────────────

it('dials the next contact and returns a Call', function () {
    Event::fake([DialerCallRinging::class, DialerCallConnected::class, DialerCallEnded::class, ContactDispositioned::class]);

    $session = $this->service->startSession($this->agent, $this->campaign);
    $call = $this->service->dialNext($session);

    expect($call)->toBeInstanceOf(Call::class);
    expect($call->contact_id)->toBe($this->contact->id);
    expect($call->agent_id)->toBe($this->agent->id);
    expect($call->campaign_id)->toBe($this->campaign->id);
    expect($call->dialer_session_id)->toBe($session->id);
    expect($call->status)->toBe('ringing');
    expect($call->twilio_conference_sid)->toBe($session->conference_name);
    expect($call->twilio_call_sid)->toBe('CA1234567890abcdef1234567890abcdef');
});

it('returns null when queue is empty', function () {
    $this->entry->update(['status' => 'completed']);

    $session = $this->service->startSession($this->agent, $this->campaign);
    $call = $this->service->dialNext($session);

    expect($call)->toBeNull();
});

it('returns null when session is not active', function () {
    $session = $this->service->startSession($this->agent, $this->campaign);
    $this->service->pauseSession($session);

    $call = $this->service->dialNext($session->fresh());

    expect($call)->toBeNull();
});

it('increments session calls_attempted after dialing', function () {
    Event::fake([DialerCallRinging::class, DialerCallConnected::class, DialerCallEnded::class, ContactDispositioned::class]);

    $session = $this->service->startSession($this->agent, $this->campaign);
    $this->service->dialNext($session);

    expect($session->fresh()->calls_attempted)->toBe(1);
});

it('sets agent status to ringing after dialing', function () {
    Event::fake([DialerCallRinging::class, DialerCallConnected::class, DialerCallEnded::class, ContactDispositioned::class]);

    $session = $this->service->startSession($this->agent, $this->campaign);
    $this->service->dialNext($session);

    expect($this->agent->fresh()->status)->toBe('ringing');
});

it('uses the same conference name for every dial in the session', function () {
    Event::fake([DialerCallRinging::class, DialerCallConnected::class, DialerCallEnded::class, ContactDispositioned::class]);

    // Add a second contact before any Event::fake calls (already done in beforeEach for first)
    // Use the already-created contact, then mark it complete and add another
    $session = $this->service->startSession($this->agent, $this->campaign);
    $conferenceName = $session->conference_name;

    $call1 = $this->service->dialNext($session);

    // Simulate call ended and contact completed
    $this->entry->update(['status' => 'completed']);

    // Second contact created BEFORE calling dialNext again (no Event::fake interference)
    // Event::fake is already active, so we use Contact::create with explicit ulid
    $contact2 = Contact::create([
        'ulid' => \Illuminate\Support\Str::ulid()->toBase32(),
        'company_id' => $this->company->id,
        'phone' => '+17205551111',
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'timezone' => 'America/Denver',
    ]);
    CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $contact2->id,
        'status' => 'queued',
        'calls_today' => 0,
        'total_attempts' => 0,
        'sort_order' => 2,
    ]);

    $call2 = $this->service->dialNext($session->fresh());

    expect($call1->twilio_conference_sid)->toBe($conferenceName);
    expect($call2)->not->toBeNull();
    expect($call2->twilio_conference_sid)->toBe($conferenceName);
});

// ──────────────────────────────────────
// handleAnswered
// ──────────────────────────────────────

it('marks call as in_progress and sets agent on_call when answered', function () {
    Event::fake([DialerCallRinging::class, DialerCallConnected::class, DialerCallEnded::class, ContactDispositioned::class]);

    $session = DialerSession::factory()->create([
        'agent_id' => $this->agent->id,
        'campaign_id' => $this->campaign->id,
        'status' => 'active',
        'calls_attempted' => 1,
        'calls_connected' => 0,
    ]);
    $call = Call::factory()->create([
        'agent_id' => $this->agent->id,
        'contact_id' => $this->contact->id,
        'company_id' => $this->company->id,
        'dialer_session_id' => $session->id,
        'status' => 'ringing',
    ]);

    $this->service->handleAnswered($call);

    expect($call->fresh()->status)->toBe('in_progress');
    expect($call->fresh()->answered_at)->not->toBeNull();
    expect($this->agent->fresh()->status)->toBe('on_call');
    expect($session->fresh()->calls_connected)->toBe(1);
});

// ──────────────────────────────────────
// disposition
// ──────────────────────────────────────

it('dispositions a call and marks the campaign contact completed', function () {
    Event::fake([DialerCallRinging::class, DialerCallConnected::class, DialerCallEnded::class, ContactDispositioned::class]);

    $this->entry->update(['status' => 'dialing', 'calls_today' => 1, 'total_attempts' => 1, 'claimed_by_agent_id' => $this->agent->id]);
    $call = Call::factory()->create([
        'agent_id' => $this->agent->id,
        'contact_id' => $this->contact->id,
        'company_id' => $this->company->id,
        'campaign_id' => $this->campaign->id,
        'status' => 'in_progress',
        'answered_at' => now()->subMinutes(3),
    ]);

    $this->service->disposition($call, 'connected', 'Left voicemail');

    expect($call->fresh()->disposition)->toBe('connected');
    expect($call->fresh()->status)->toBe('completed');
    expect($this->entry->fresh()->status)->toBe('completed');
    expect($this->entry->fresh()->claimed_by_agent_id)->toBeNull();
    expect($this->campaign->fresh()->calls_attempted)->toBe(1);
    expect($this->campaign->fresh()->calls_connected)->toBe(1);
});

it('marks contact as DNC on dnc disposition', function () {
    Event::fake([DialerCallRinging::class, DialerCallConnected::class, DialerCallEnded::class, ContactDispositioned::class]);

    $call = Call::factory()->create([
        'agent_id' => $this->agent->id,
        'contact_id' => $this->contact->id,
        'company_id' => $this->company->id,
        'campaign_id' => $this->campaign->id,
        'status' => 'in_progress',
    ]);

    $this->service->disposition($call, 'dnc');

    expect($this->contact->fresh()->do_not_call)->toBeTrue();
});

it('marks entry as callback_scheduled on callback disposition', function () {
    Event::fake([DialerCallRinging::class, DialerCallConnected::class, DialerCallEnded::class, ContactDispositioned::class]);

    $this->entry->update(['status' => 'dialing', 'calls_today' => 1, 'total_attempts' => 1]);
    $call = Call::factory()->create([
        'agent_id' => $this->agent->id,
        'contact_id' => $this->contact->id,
        'company_id' => $this->company->id,
        'campaign_id' => $this->campaign->id,
        'status' => 'in_progress',
    ]);

    $this->service->disposition($call, 'callback');

    expect($this->entry->fresh()->status)->toBe('callback_scheduled');
});

it('does not increment calls_connected for no_answer disposition', function () {
    Event::fake([DialerCallRinging::class, DialerCallConnected::class, DialerCallEnded::class, ContactDispositioned::class]);

    $this->entry->update(['status' => 'dialing', 'calls_today' => 1, 'total_attempts' => 1]);
    $call = Call::factory()->create([
        'agent_id' => $this->agent->id,
        'contact_id' => $this->contact->id,
        'company_id' => $this->company->id,
        'campaign_id' => $this->campaign->id,
        'status' => 'ringing',
    ]);

    $this->service->disposition($call, 'no_answer');

    expect($this->campaign->fresh()->calls_attempted)->toBe(1);
    expect($this->campaign->fresh()->calls_connected)->toBe(0);
});

// ──────────────────────────────────────
// Next-campaign advancement
// ──────────────────────────────────────

it('enrolls an exhausted contact in the next campaign', function () {
    // Create next campaign BEFORE Event::fake
    $nextCampaign = Campaign::factory()->active()->create([
        'company_id' => $this->company->id,
    ]);
    $this->campaign->update(['next_campaign_id' => $nextCampaign->id]);

    // Set this entry to the exhaustion threshold
    $this->entry->update([
        'status' => 'dialing',
        'calls_today' => 0,
        'total_attempts' => 10, // = max_total_attempts
        'entered_at' => now(),
    ]);

    Event::fake([DialerCallRinging::class, DialerCallConnected::class, DialerCallEnded::class, ContactDispositioned::class]);

    $call = Call::factory()->create([
        'agent_id' => $this->agent->id,
        'contact_id' => $this->contact->id,
        'company_id' => $this->company->id,
        'campaign_id' => $this->campaign->id,
        'status' => 'ringing',
    ]);

    $this->service->disposition($call, 'no_answer');

    $enrolled = CampaignContact::where('campaign_id', $nextCampaign->id)
        ->where('contact_id', $this->contact->id)
        ->first();

    expect($enrolled)->not->toBeNull();
    expect($enrolled->status)->toBe('queued');
});
