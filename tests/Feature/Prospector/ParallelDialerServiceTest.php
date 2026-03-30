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
use App\Services\Prospector\TcpaComplianceService;
use Illuminate\Support\Facades\Redis;

beforeEach(function () {
    Redis::shouldReceive('get')->andReturn(null)->byDefault();
    Redis::shouldReceive('setex')->andReturn(true)->byDefault();
    Redis::shouldReceive('set')->andReturn(true)->byDefault();
    Redis::shouldReceive('del')->andReturn(1)->byDefault();

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

    $this->conferenceMock = Mockery::mock(ConferenceService::class);
    $this->conferenceMock->shouldReceive('createConferenceRoom')->andReturn('prospector-session-test');
    $this->conferenceMock->shouldReceive('dialContactIntoConference')->andReturn('CA1234567890abcdef1234567890abcdef');
    $this->conferenceMock->shouldReceive('removeParticipant')->byDefault();

    config(['prospector.tcpa.calling_hours_start' => '00:00']);
    config(['prospector.tcpa.calling_hours_end' => '23:59']);

    $this->service = new ParallelDialerService(
        $this->conferenceMock,
        new NumberSelectionService,
        new AgentStateService,
        new TcpaComplianceService,
        new AmdService,
    );
});

it('dials a batch of contacts in parallel', function () {
    $contacts = Contact::factory()->forCompany($this->company)->count(3)->create();

    foreach ($contacts as $i => $contact) {
        CampaignContact::create([
            'campaign_id' => $this->campaign->id,
            'contact_id' => $contact->id,
            'status' => 'queued',
            'sort_order' => $i + 1,
            'attempts' => 0,
            'added_at' => now(),
        ]);
    }

    $session = DialerSession::factory()->create([
        'agent_id' => $this->agent->id,
        'campaign_id' => $this->campaign->id,
        'mode' => 'parallel',
        'parallel_lines' => 3,
    ]);

    $calls = $this->service->dialNextBatch($session);

    expect($calls)->toHaveCount(3);

    foreach ($calls as $call) {
        expect($call)->toBeInstanceOf(Call::class);
        expect($call->status)->toBe('ringing');
        expect($call->batch_id)->not->toBeNull();
        expect($call->dialer_session_id)->toBe($session->id);
    }

    // All calls should have the same batch_id
    $batchIds = collect($calls)->pluck('batch_id')->unique();
    expect($batchIds)->toHaveCount(1);
});

it('returns empty array when no contacts are queued', function () {
    $session = DialerSession::factory()->create([
        'agent_id' => $this->agent->id,
        'campaign_id' => $this->campaign->id,
        'mode' => 'parallel',
        'parallel_lines' => 3,
    ]);

    $calls = $this->service->dialNextBatch($session);

    expect($calls)->toBeEmpty();
});

it('respects parallel_lines setting and picks correct number of contacts', function () {
    $contacts = Contact::factory()->forCompany($this->company)->count(5)->create();

    foreach ($contacts as $i => $contact) {
        CampaignContact::create([
            'campaign_id' => $this->campaign->id,
            'contact_id' => $contact->id,
            'status' => 'queued',
            'sort_order' => $i + 1,
            'attempts' => 0,
            'added_at' => now(),
        ]);
    }

    $session = DialerSession::factory()->create([
        'agent_id' => $this->agent->id,
        'campaign_id' => $this->campaign->id,
        'mode' => 'parallel',
        'parallel_lines' => 2,
    ]);

    $calls = $this->service->dialNextBatch($session);

    expect($calls)->toHaveCount(2);

    // 3 contacts should still be queued
    $queued = CampaignContact::where('campaign_id', $this->campaign->id)
        ->where('status', 'queued')
        ->count();

    expect($queued)->toBe(3);
});

it('skips DNC contacts during parallel batch', function () {
    $dncContact = Contact::factory()->forCompany($this->company)->dnc()->create();
    $goodContacts = Contact::factory()->forCompany($this->company)->count(2)->create();

    CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $dncContact->id,
        'status' => 'queued',
        'sort_order' => 1,
        'attempts' => 0,
        'added_at' => now(),
    ]);

    foreach ($goodContacts as $i => $contact) {
        CampaignContact::create([
            'campaign_id' => $this->campaign->id,
            'contact_id' => $contact->id,
            'status' => 'queued',
            'sort_order' => $i + 2,
            'attempts' => 0,
            'added_at' => now(),
        ]);
    }

    $session = DialerSession::factory()->create([
        'agent_id' => $this->agent->id,
        'campaign_id' => $this->campaign->id,
        'mode' => 'parallel',
        'parallel_lines' => 3,
    ]);

    $calls = $this->service->dialNextBatch($session);

    // Only 2 good contacts, DNC was skipped
    expect($calls)->toHaveCount(2);

    $dncEntry = CampaignContact::where('contact_id', $dncContact->id)->first();
    expect($dncEntry->status)->toBe('skipped');
});

it('first pickup acquires Redis lock and connects', function () {
    $contact = Contact::factory()->forCompany($this->company)->create();

    $session = DialerSession::factory()->create([
        'agent_id' => $this->agent->id,
        'campaign_id' => $this->campaign->id,
        'mode' => 'parallel',
        'parallel_lines' => 3,
        'current_batch_id' => 'test-batch-123',
    ]);

    $call = Call::factory()->create([
        'company_id' => $this->company->id,
        'dialer_session_id' => $session->id,
        'agent_id' => $this->agent->id,
        'contact_id' => $contact->id,
        'campaign_id' => $this->campaign->id,
        'batch_id' => 'test-batch-123',
        'status' => 'ringing',
        'started_at' => now(),
    ]);

    // Redis::set with NX returns true (lock acquired)
    Redis::shouldReceive('set')
        ->with("prospector:parallel:{$session->ulid}:connected", $call->id, 'NX', 'EX', 30)
        ->once()
        ->andReturn(true);

    $result = $this->service->handleFirstPickup($call, $session);

    expect($result)->toBeTrue();

    $call->refresh();
    expect($call->status)->toBe('in_progress');
    expect($call->answered_at)->not->toBeNull();
});

it('second pickup fails to acquire lock and is canceled', function () {
    $contact = Contact::factory()->forCompany($this->company)->create();

    $session = DialerSession::factory()->create([
        'agent_id' => $this->agent->id,
        'campaign_id' => $this->campaign->id,
        'mode' => 'parallel',
        'parallel_lines' => 3,
        'current_batch_id' => 'test-batch-456',
    ]);

    $call = Call::factory()->create([
        'company_id' => $this->company->id,
        'dialer_session_id' => $session->id,
        'agent_id' => $this->agent->id,
        'contact_id' => $contact->id,
        'campaign_id' => $this->campaign->id,
        'batch_id' => 'test-batch-456',
        'status' => 'ringing',
        'started_at' => now(),
    ]);

    // Redis::set with NX returns false (lock already held)
    Redis::shouldReceive('set')
        ->with("prospector:parallel:{$session->ulid}:connected", $call->id, 'NX', 'EX', 30)
        ->once()
        ->andReturn(false);

    $result = $this->service->handleFirstPickup($call, $session);

    expect($result)->toBeFalse();

    $call->refresh();
    expect($call->status)->toBe('canceled');
});

it('cancels other lines when first pickup wins', function () {
    $contacts = Contact::factory()->forCompany($this->company)->count(3)->create();
    $batchId = 'test-batch-789';

    $session = DialerSession::factory()->create([
        'agent_id' => $this->agent->id,
        'campaign_id' => $this->campaign->id,
        'mode' => 'parallel',
        'parallel_lines' => 3,
        'current_batch_id' => $batchId,
    ]);

    $calls = [];

    foreach ($contacts as $contact) {
        CampaignContact::create([
            'campaign_id' => $this->campaign->id,
            'contact_id' => $contact->id,
            'status' => 'dialing',
            'sort_order' => 1,
            'attempts' => 1,
            'added_at' => now(),
        ]);

        $calls[] = Call::factory()->create([
            'company_id' => $this->company->id,
            'dialer_session_id' => $session->id,
            'agent_id' => $this->agent->id,
            'contact_id' => $contact->id,
            'campaign_id' => $this->campaign->id,
            'batch_id' => $batchId,
            'status' => 'ringing',
            'twilio_call_sid' => 'CA'.fake()->regexify('[a-f0-9]{32}'),
            'twilio_conference_sid' => 'prospector-session-test',
            'started_at' => now(),
        ]);
    }

    $winningCall = $calls[0];

    $this->service->cancelOtherLines($session, $winningCall);

    // Other two calls should be canceled
    $calls[1] = Call::find($calls[1]->id);
    $calls[2] = Call::find($calls[2]->id);

    expect($calls[1]->status)->toBe('canceled');
    expect($calls[2]->status)->toBe('canceled');

    // Their contacts should be re-queued
    $requeued = CampaignContact::where('campaign_id', $this->campaign->id)
        ->where('status', 'queued')
        ->count();

    expect($requeued)->toBe(2);
});

it('clears batch lock on session', function () {
    $session = DialerSession::factory()->create([
        'agent_id' => $this->agent->id,
        'campaign_id' => $this->campaign->id,
        'mode' => 'parallel',
        'current_batch_id' => 'test-batch-clear',
    ]);

    Redis::shouldReceive('del')
        ->with("prospector:parallel:{$session->ulid}:connected")
        ->once()
        ->andReturn(1);

    $this->service->clearBatchLock($session);

    $session->refresh();
    expect($session->current_batch_id)->toBeNull();
});

it('returns empty for inactive session', function () {
    $session = DialerSession::factory()->create([
        'agent_id' => $this->agent->id,
        'campaign_id' => $this->campaign->id,
        'mode' => 'parallel',
        'parallel_lines' => 3,
        'status' => 'paused',
    ]);

    $calls = $this->service->dialNextBatch($session);

    expect($calls)->toBeEmpty();
});

it('increments session stats on parallel dial', function () {
    $contacts = Contact::factory()->forCompany($this->company)->count(2)->create();

    foreach ($contacts as $i => $contact) {
        CampaignContact::create([
            'campaign_id' => $this->campaign->id,
            'contact_id' => $contact->id,
            'status' => 'queued',
            'sort_order' => $i + 1,
            'attempts' => 0,
            'added_at' => now(),
        ]);
    }

    $session = DialerSession::factory()->create([
        'agent_id' => $this->agent->id,
        'campaign_id' => $this->campaign->id,
        'mode' => 'parallel',
        'parallel_lines' => 2,
    ]);

    $this->service->dialNextBatch($session);

    $session->refresh();
    expect($session->calls_attempted)->toBe(2);
});
