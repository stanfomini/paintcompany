<?php

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
use App\Services\Prospector\ConferenceService;
use App\Services\Prospector\NumberSelectionService;
use App\Services\Prospector\ParallelDialerService;
use App\Services\Prospector\PowerDialerService;
use App\Services\Prospector\TcpaComplianceService;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    // Mock Redis to avoid dependency on a running Redis server
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
    $this->campaign = Campaign::factory()->create([
        'company_id' => $this->company->id,
        'status' => 'active',
    ]);

    // Mock ConferenceService to avoid Twilio API calls
    $this->conferenceMock = Mockery::mock(ConferenceService::class);
    $this->conferenceMock->shouldReceive('createConferenceRoom')->andReturn('prospector-session-test');
    $this->conferenceMock->shouldReceive('dialContactIntoConference')->andReturn('CA1234567890abcdef1234567890abcdef');

    $this->parallelMock = Mockery::mock(ParallelDialerService::class);
    $this->parallelMock->shouldReceive('clearBatchLock')->byDefault();

    $this->service = new PowerDialerService(
        $this->conferenceMock,
        new NumberSelectionService,
        new AgentStateService,
        new TcpaComplianceService,
        new AmdService,
        $this->parallelMock,
    );
});

it('starts a dialer session', function () {
    $session = $this->service->startSession($this->agent, $this->campaign);

    expect($session)->toBeInstanceOf(DialerSession::class);
    expect($session->agent_id)->toBe($this->agent->id);
    expect($session->campaign_id)->toBe($this->campaign->id);
    expect($session->status)->toBe('active');

    $this->agent->refresh();
    expect($this->agent->status)->toBe('available');
});

it('pauses a session and sets agent to break', function () {
    $session = $this->service->startSession($this->agent, $this->campaign);

    $this->service->pauseSession($session);

    $session->refresh();
    expect($session->status)->toBe('paused');

    $this->agent->refresh();
    expect($this->agent->status)->toBe('break');
});

it('ends a session and sets agent offline', function () {
    $session = $this->service->startSession($this->agent, $this->campaign);
    $session->update(['calls_attempted' => 5]);

    $this->service->endSession($session);

    $session->refresh();
    expect($session->status)->toBe('completed');
    expect($session->ended_at)->not->toBeNull();

    $this->agent->refresh();
    expect($this->agent->status)->toBe('offline');
});

it('abandons session with zero calls', function () {
    $session = $this->service->startSession($this->agent, $this->campaign);

    $this->service->endSession($session);

    $session->refresh();
    expect($session->status)->toBe('abandoned');
});

it('gets next contact from campaign queue', function () {
    config(['prospector.tcpa.calling_hours_start' => '00:00']);
    config(['prospector.tcpa.calling_hours_end' => '23:59']);

    $contact = Contact::factory()->forCompany($this->company)->create();

    CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $contact->id,
        'status' => 'queued',
        'sort_order' => 1,
        'attempts' => 0,
        'added_at' => now(),
    ]);

    $entry = $this->service->getNextContact($this->campaign);

    expect($entry)->not->toBeNull();
    expect($entry->contact_id)->toBe($contact->id);
});

it('skips DNC contacts when finding next contact', function () {
    config(['prospector.tcpa.calling_hours_start' => '00:00']);
    config(['prospector.tcpa.calling_hours_end' => '23:59']);

    $dncContact = Contact::factory()->forCompany($this->company)->dnc()->create();
    $goodContact = Contact::factory()->forCompany($this->company)->create();

    CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $dncContact->id,
        'status' => 'queued',
        'sort_order' => 1,
        'attempts' => 0,
        'added_at' => now(),
    ]);

    CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $goodContact->id,
        'status' => 'queued',
        'sort_order' => 2,
        'attempts' => 0,
        'added_at' => now(),
    ]);

    $entry = $this->service->getNextContact($this->campaign);

    expect($entry)->not->toBeNull();
    expect($entry->contact_id)->toBe($goodContact->id);

    // Verify DNC contact was skipped
    $dncEntry = CampaignContact::where('contact_id', $dncContact->id)->first();
    expect($dncEntry->status)->toBe('skipped');
});

it('returns null when queue is empty', function () {
    $entry = $this->service->getNextContact($this->campaign);

    expect($entry)->toBeNull();
});

it('dispositions a call and updates contact', function () {
    config(['prospector.tcpa.calling_hours_start' => '00:00']);
    config(['prospector.tcpa.calling_hours_end' => '23:59']);

    $contact = Contact::factory()->forCompany($this->company)->create([
        'total_calls' => 0,
        'last_contacted_at' => null,
    ]);

    $session = DialerSession::factory()->create([
        'agent_id' => $this->agent->id,
        'campaign_id' => $this->campaign->id,
    ]);

    $call = Call::factory()->create([
        'company_id' => $this->company->id,
        'agent_id' => $this->agent->id,
        'contact_id' => $contact->id,
        'campaign_id' => $this->campaign->id,
        'dialer_session_id' => $session->id,
        'status' => 'in_progress',
        'started_at' => now()->subMinutes(5),
        'answered_at' => now()->subMinutes(4),
    ]);

    CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $contact->id,
        'status' => 'dialing',
        'sort_order' => 1,
        'attempts' => 1,
        'added_at' => now(),
    ]);

    $this->service->dispositionCall($call, 'interested', 'Very interested in painting');

    $call->refresh();
    expect($call->status)->toBe('completed');
    expect($call->disposition)->toBe('interested');
    expect($call->disposition_notes)->toBe('Very interested in painting');

    $contact->refresh();
    expect($contact->total_calls)->toBe(1);
    expect($contact->last_contacted_at)->not->toBeNull();
    expect($contact->last_disposition)->toBe('interested');
});

it('marks contact as DNC when disposition is dnc', function () {
    $contact = Contact::factory()->forCompany($this->company)->create([
        'do_not_call' => false,
    ]);

    $call = Call::factory()->create([
        'company_id' => $this->company->id,
        'agent_id' => $this->agent->id,
        'contact_id' => $contact->id,
        'campaign_id' => $this->campaign->id,
        'status' => 'in_progress',
        'started_at' => now()->subMinutes(1),
    ]);

    CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $contact->id,
        'status' => 'dialing',
        'sort_order' => 1,
        'attempts' => 1,
        'added_at' => now(),
    ]);

    $this->service->dispositionCall($call, 'dnc');

    $contact->refresh();
    expect($contact->do_not_call)->toBeTrue();
});
