<?php

use App\Events\Prospector\ContactDispositioned;
use App\Events\Prospector\DialerCallEnded;
use App\Events\Prospector\DialerCallRinging;
use App\Models\Prospector\Agent;
use App\Models\Prospector\Campaign;
use App\Models\Prospector\CampaignContact;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Models\Prospector\PhoneNumber;
use App\Models\Prospector\TwilioAccount;
use App\Services\Prospector\ConferenceService;
use App\Services\Prospector\DialerOrchestrationService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;

/**
 * End-to-end integration test for the persistent-conference dialer flow.
 *
 * Covers the full dial cycle:
 *   start session → dialNext (contact 1) → handleAnswered → disposition
 *   → dialNext (contact 2) → disposition → endSession
 *
 * ConferenceService is mocked to avoid Twilio API calls.
 * Eloquent model events are preserved (targeted Event::fake for broadcasts only).
 */
beforeEach(function () {
    Redis::shouldReceive('get')->andReturn(null)->byDefault();
    Redis::shouldReceive('setex')->andReturn(true)->byDefault();

    // Infrastructure
    $this->account = TwilioAccount::factory()->create(['is_primary' => true, 'is_active' => true]);
    $this->company = Company::factory()->forAccount($this->account)->create();
    $this->phone = PhoneNumber::factory()->forAccount($this->account)->main()->create([
        'company_id' => $this->company->id,
        'is_active' => true,
    ]);
    $this->company->update(['main_phone_number_id' => $this->phone->id]);

    $this->agent = Agent::factory()->forCompany($this->company)->offline()->create();

    $this->campaign = Campaign::factory()->active()->create([
        'company_id' => $this->company->id,
        'max_calls_per_day' => 3,
        'max_total_attempts' => 90,
        'max_days_in_campaign' => 30,
        'min_minutes_between' => 0,
        'calling_hours_start' => null,
        'calling_hours_end' => null,
    ]);

    // Two contacts in the queue — created BEFORE any Event::fake
    $this->contact1 = Contact::factory()->create([
        'company_id' => $this->company->id,
        'phone' => '+13035551111',
        'timezone' => 'America/Denver',
    ]);
    $this->contact2 = Contact::factory()->create([
        'company_id' => $this->company->id,
        'phone' => '+13035552222',
        'timezone' => 'America/Denver',
    ]);

    $this->entry1 = CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $this->contact1->id,
        'status' => 'queued',
        'sort_order' => 1,
        'calls_today' => 0,
        'total_attempts' => 0,
    ]);

    $this->entry2 = CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $this->contact2->id,
        'status' => 'queued',
        'sort_order' => 2,
        'calls_today' => 0,
        'total_attempts' => 0,
    ]);

    // Mock ConferenceService — captures every conference/contact/number combination
    $this->conferenceMock = Mockery::mock(ConferenceService::class);
    $this->conferenceMock->shouldReceive('dialContactIntoConference')
        ->andReturnUsing(function (string $conf, $contact) {
            return 'CA_FAKE_'.strtoupper(substr(md5($contact->phone), 0, 8));
        });

    app()->instance(ConferenceService::class, $this->conferenceMock);

    $this->service = app(DialerOrchestrationService::class);
});

// ──────────────────────────────────────
// Full two-contact dial cycle
// ──────────────────────────────────────

it('completes a full dial cycle: start session → dial → disposition → advance → end', function () {
    Event::fake([DialerCallRinging::class, DialerCallConnected::class, DialerCallEnded::class, ContactDispositioned::class]);

    // Configure routes: not_interested removes, no_answer removes (so contacts advance)
    $this->campaign->update(['disposition_routes' => [
        'not_interested' => ['action' => 'remove'],
        'no_answer' => ['action' => 'remove'],
    ]]);

    // ── 1. Start session ──────────────────────────────────────────────────
    $session = $this->service->startSession($this->agent, $this->campaign);

    expect($session->status)->toBe('active');
    expect($session->conference_name)->toStartWith('prospector-session-');
    expect($session->agent_id)->toBe($this->agent->id);
    expect($session->campaign_id)->toBe($this->campaign->id);
    expect($this->agent->fresh()->status)->toBe('available');

    $conferenceName = $session->conference_name;

    // ── 2. Dial contact 1 ────────────────────────────────────────────────
    $call1 = $this->service->dialNext($session);

    expect($call1)->not->toBeNull();
    expect($call1->contact_id)->toBe($this->contact1->id);
    expect($call1->status)->toBe('ringing');
    expect($call1->twilio_conference_sid)->toBe($conferenceName);
    expect($call1->twilio_call_sid)->toStartWith('CA_FAKE_');
    expect($call1->dialer_session_id)->toBe($session->id);

    // Session attempted count bumped
    expect($session->fresh()->calls_attempted)->toBe(1);
    expect($this->agent->fresh()->status)->toBe('ringing');

    // Contact 1 entry is now dialing
    expect($this->entry1->fresh()->status)->toBe('dialing');

    // ── 3. Contact 1 answers ─────────────────────────────────────────────
    $this->service->handleAnswered($call1);

    expect($call1->fresh()->status)->toBe('in_progress');
    expect($call1->fresh()->answered_at)->not->toBeNull();
    expect($this->agent->fresh()->status)->toBe('on_call');
    expect($session->fresh()->calls_connected)->toBe(1);

    // ── 4. Disposition contact 1 ─────────────────────────────────────────
    $this->service->disposition($call1, 'not_interested', 'Not a good fit');

    expect($call1->fresh()->disposition)->toBe('not_interested');
    expect($call1->fresh()->status)->toBe('completed');

    // Entry should be completed, claim released
    expect($this->entry1->fresh()->status)->toBe('completed'); // 'not_interested' configured to 'remove'
    expect($this->entry1->fresh()->claimed_by_agent_id)->toBeNull();

    // Campaign stats updated
    expect($this->campaign->fresh()->calls_attempted)->toBe(1);
    expect($this->campaign->fresh()->calls_connected)->toBe(1); // not_interested counts as connected

    // ── 5. Dial contact 2 ────────────────────────────────────────────────
    $call2 = $this->service->dialNext($session->fresh());

    expect($call2)->not->toBeNull();
    expect($call2->contact_id)->toBe($this->contact2->id);
    expect($call2->status)->toBe('ringing');

    // Persistent conference — same name for second call
    expect($call2->twilio_conference_sid)->toBe($conferenceName);

    expect($session->fresh()->calls_attempted)->toBe(2);

    // ── 6. Disposition contact 2 (no answer) ─────────────────────────────
    $this->service->disposition($call2, 'no_answer');

    expect($call2->fresh()->disposition)->toBe('no_answer');
    expect($this->entry2->fresh()->status)->toBe('completed'); // 'no_answer' configured to 'remove'
    expect($this->campaign->fresh()->calls_connected)->toBe(1); // no_answer doesn't increment

    // ── 7. End session ───────────────────────────────────────────────────
    $this->service->endSession($session->fresh());

    expect($session->fresh()->status)->toBe('completed');
    expect($session->fresh()->ended_at)->not->toBeNull();
    expect($this->agent->fresh()->status)->toBe('offline');
});

// ──────────────────────────────────────
// Conference name persists across dials
// ──────────────────────────────────────

it('reuses the same conference name for every dial in a session', function () {
    Event::fake([DialerCallRinging::class, DialerCallConnected::class, DialerCallEnded::class, ContactDispositioned::class]);

    $session = $this->service->startSession($this->agent, $this->campaign);
    $conferenceName = $session->conference_name;

    $call1 = $this->service->dialNext($session);
    $this->service->disposition($call1, 'no_answer');

    $call2 = $this->service->dialNext($session->fresh());

    expect($call1->twilio_conference_sid)->toBe($conferenceName);
    expect($call2->twilio_conference_sid)->toBe($conferenceName);
});

// ──────────────────────────────────────
// Queue exhaustion
// ──────────────────────────────────────

it('returns null from dialNext when no more contacts are callable', function () {
    Event::fake([DialerCallRinging::class, DialerCallConnected::class, DialerCallEnded::class, ContactDispositioned::class]);

    // Configure disposition routes to remove contacts on 'wrong_number'
    $this->campaign->update(['disposition_routes' => [
        'wrong_number' => ['action' => 'remove'],
    ]]);

    $session = $this->service->startSession($this->agent, $this->campaign);

    // Drain both contacts with a 'remove' disposition
    $call1 = $this->service->dialNext($session);
    $this->service->disposition($call1, 'wrong_number');

    $call2 = $this->service->dialNext($session->fresh());
    $this->service->disposition($call2, 'wrong_number');

    // Queue should now be empty
    $call3 = $this->service->dialNext($session->fresh());
    expect($call3)->toBeNull();
});

// ──────────────────────────────────────
// Daily limit gate
// ──────────────────────────────────────

it('does not dial a contact that has hit the daily call limit', function () {
    // Max out contact 1's daily calls
    $this->entry1->update([
        'calls_today' => 3, // = max_calls_per_day
        'status' => 'queued',
    ]);

    $session = $this->service->startSession($this->agent, $this->campaign);
    $call = $this->service->dialNext($session);

    // Should skip contact 1 and dial contact 2
    expect($call)->not->toBeNull();
    expect($call->contact_id)->toBe($this->contact2->id);
});

// ──────────────────────────────────────
// Cooldown gate
// ──────────────────────────────────────

it('skips contacts still in their cooldown window', function () {
    // Put contact 1 in cooldown
    $this->entry1->update([
        'next_callable_at' => now()->addHours(3),
        'calls_today' => 1,
        'total_attempts' => 1,
    ]);

    $session = $this->service->startSession($this->agent, $this->campaign);
    $call = $this->service->dialNext($session);

    // Should dial contact 2 instead
    expect($call)->not->toBeNull();
    expect($call->contact_id)->toBe($this->contact2->id);
});

// ──────────────────────────────────────
// Session abandonment when zero calls
// ──────────────────────────────────────

it('abandons the session when ended with zero calls', function () {
    // Put both contacts in cooldown so queue is gated
    $this->entry1->update(['next_callable_at' => now()->addHours(1), 'calls_today' => 1]);
    $this->entry2->update(['next_callable_at' => now()->addHours(1), 'calls_today' => 1]);

    $session = $this->service->startSession($this->agent, $this->campaign);
    $this->service->endSession($session);

    expect($session->fresh()->status)->toBe('abandoned');
    expect($this->agent->fresh()->status)->toBe('offline');
});
