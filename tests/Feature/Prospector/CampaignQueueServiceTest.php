<?php

use App\Models\Prospector\Agent;
use App\Models\Prospector\Campaign;
use App\Models\Prospector\CampaignContact;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Services\Prospector\CampaignQueueService;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->campaign = Campaign::factory()->active()->create([
        'company_id' => $this->company->id,
        'max_calls_per_day' => 3,
        'max_total_attempts' => 10,
        'max_days_in_campaign' => 30,
        'min_minutes_between' => 240,
        'calling_hours_start' => null,
        'calling_hours_end' => null,
    ]);
    $this->service = app(CampaignQueueService::class);
});

// ──────────────────────────────────────
// getNext — basic queue selection
// ──────────────────────────────────────

it('returns the next queued contact', function () {
    $contact = Contact::factory()->create(['company_id' => $this->company->id, 'timezone' => 'America/Denver']);
    $entry = CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $contact->id,
        'status' => 'queued',
        'calls_today' => 0,
        'total_attempts' => 0,
    ]);

    $result = $this->service->getNext($this->campaign);

    expect($result)->not->toBeNull();
    expect($result->id)->toBe($entry->id);
});

it('returns null when queue is empty', function () {
    $result = $this->service->getNext($this->campaign);

    expect($result)->toBeNull();
});

it('skips non-queued contacts', function () {
    $contact = Contact::factory()->create(['company_id' => $this->company->id]);
    CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $contact->id,
        'status' => 'completed',
        'calls_today' => 0,
    ]);

    $result = $this->service->getNext($this->campaign);

    expect($result)->toBeNull();
});

// ──────────────────────────────────────
// Call rules — daily limit
// ──────────────────────────────────────

it('skips contacts that have hit the daily call limit', function () {
    $contact = Contact::factory()->create(['company_id' => $this->company->id]);
    CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $contact->id,
        'status' => 'queued',
        'calls_today' => 3, // equal to max_calls_per_day
        'total_attempts' => 3,
    ]);

    $result = $this->service->getNext($this->campaign);

    expect($result)->toBeNull();
});

it('returns contact still under the daily limit', function () {
    $contact = Contact::factory()->create(['company_id' => $this->company->id, 'timezone' => 'America/Denver']);
    CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $contact->id,
        'status' => 'queued',
        'calls_today' => 2, // one below max (3)
        'total_attempts' => 2,
    ]);

    $result = $this->service->getNext($this->campaign);

    expect($result)->not->toBeNull();
});

// ──────────────────────────────────────
// Call rules — cooldown (next_callable_at)
// ──────────────────────────────────────

it('skips contacts still in cooldown window', function () {
    $contact = Contact::factory()->create(['company_id' => $this->company->id]);
    CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $contact->id,
        'status' => 'queued',
        'calls_today' => 1,
        'total_attempts' => 1,
        'next_callable_at' => now()->addHours(2),
    ]);

    $result = $this->service->getNext($this->campaign);

    expect($result)->toBeNull();
});

it('returns contact whose cooldown has expired', function () {
    $contact = Contact::factory()->create(['company_id' => $this->company->id, 'timezone' => 'America/Denver']);
    CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $contact->id,
        'status' => 'queued',
        'calls_today' => 1,
        'total_attempts' => 1,
        'next_callable_at' => now()->subMinutes(1),
    ]);

    $result = $this->service->getNext($this->campaign);

    expect($result)->not->toBeNull();
});

// ──────────────────────────────────────
// Call rules — exhaustion
// ──────────────────────────────────────

it('exhausts a contact that has reached max_total_attempts', function () {
    $contact = Contact::factory()->create(['company_id' => $this->company->id]);
    $entry = CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $contact->id,
        'status' => 'queued',
        'calls_today' => 0,
        'total_attempts' => 10, // equal to max_total_attempts
        'entered_at' => now(),
    ]);

    $result = $this->service->getNext($this->campaign);

    expect($result)->toBeNull();
    expect($entry->fresh()->status)->toBe('exhausted');
    expect($entry->fresh()->exhausted_at)->not->toBeNull();
});

it('exhausts a contact that has exceeded max_days_in_campaign', function () {
    $contact = Contact::factory()->create(['company_id' => $this->company->id]);
    $entry = CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $contact->id,
        'status' => 'queued',
        'calls_today' => 0,
        'total_attempts' => 1,
        'entered_at' => now()->subDays(31),
    ]);

    $result = $this->service->getNext($this->campaign);

    expect($result)->toBeNull();
    expect($entry->fresh()->status)->toBe('exhausted');
});

// ──────────────────────────────────────
// Call rules — DNC
// ──────────────────────────────────────

it('skips DNC contacts and marks them skipped', function () {
    $contact = Contact::factory()->create([
        'company_id' => $this->company->id,
        'do_not_call' => true,
    ]);
    $entry = CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $contact->id,
        'status' => 'queued',
        'calls_today' => 0,
        'total_attempts' => 0,
    ]);

    $result = $this->service->getNext($this->campaign);

    expect($result)->toBeNull();
    expect($entry->fresh()->status)->toBe('skipped');
});

// ──────────────────────────────────────
// Priority ordering
// ──────────────────────────────────────

it('returns priority contacts before regular contacts', function () {
    $regular = Contact::factory()->create(['company_id' => $this->company->id, 'timezone' => 'America/Denver']);
    $priority = Contact::factory()->create(['company_id' => $this->company->id, 'timezone' => 'America/Denver']);

    CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $regular->id,
        'status' => 'queued',
        'sort_order' => 1,
        'is_priority' => false,
        'calls_today' => 0,
        'total_attempts' => 0,
    ]);

    $priorityEntry = CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $priority->id,
        'status' => 'queued',
        'sort_order' => 2,
        'is_priority' => true,
        'calls_today' => 0,
        'total_attempts' => 0,
    ]);

    $result = $this->service->getNext($this->campaign);

    expect($result->id)->toBe($priorityEntry->id);
});

// ──────────────────────────────────────
// Claim / Release
// ──────────────────────────────────────

it('claims an entry atomically', function () {
    $agent = Agent::factory()->forCompany($this->company)->create();
    $contact = Contact::factory()->create(['company_id' => $this->company->id]);
    $entry = CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $contact->id,
        'status' => 'queued',
    ]);

    $claimed = $this->service->claim($entry, $agent->id);

    expect($claimed)->toBeTrue();
    expect($entry->fresh()->claimed_by_agent_id)->toBe($agent->id);
});

it('cannot claim an already-claimed entry', function () {
    $agentA = Agent::factory()->forCompany($this->company)->create();
    $agentB = Agent::factory()->forCompany($this->company)->create();
    $contact = Contact::factory()->create(['company_id' => $this->company->id]);
    $entry = CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $contact->id,
        'status' => 'queued',
        'claimed_by_agent_id' => $agentA->id,
    ]);

    $claimed = $this->service->claim($entry, $agentB->id);

    expect($claimed)->toBeFalse();
});

it('releases a claimed entry', function () {
    $agent = Agent::factory()->forCompany($this->company)->create();
    $contact = Contact::factory()->create(['company_id' => $this->company->id]);
    $entry = CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $contact->id,
        'status' => 'queued',
        'claimed_by_agent_id' => $agent->id,
    ]);

    $this->service->release($entry);

    expect($entry->fresh()->claimed_by_agent_id)->toBeNull();
});

// ──────────────────────────────────────
// hasCallable / countCallable
// ──────────────────────────────────────

it('reports callable contacts correctly', function () {
    $contact = Contact::factory()->create(['company_id' => $this->company->id]);
    CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $contact->id,
        'status' => 'queued',
        'calls_today' => 0,
    ]);

    expect($this->service->hasCallable($this->campaign))->toBeTrue();
    expect($this->service->countCallable($this->campaign))->toBe(1);
});

it('reports no callable when all contacts hit daily limit', function () {
    $contact = Contact::factory()->create(['company_id' => $this->company->id]);
    CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $contact->id,
        'status' => 'queued',
        'calls_today' => 3,
    ]);

    expect($this->service->hasCallable($this->campaign))->toBeFalse();
    expect($this->service->countCallable($this->campaign))->toBe(0);
});
