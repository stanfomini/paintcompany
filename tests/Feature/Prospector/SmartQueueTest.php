<?php

use App\Models\Prospector\Agent;
use App\Models\Prospector\Campaign;
use App\Models\Prospector\CampaignContact;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Services\Prospector\SmartQueueService;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->agent = Agent::factory()->create(['company_id' => $this->company->id]);
    $this->agent->companies()->attach($this->company->id);
    $this->campaign = Campaign::factory()->create([
        'company_id' => $this->company->id,
        'status' => 'active',
    ]);
    $this->service = app(SmartQueueService::class);
});

it('returns priority items before regular queue items', function () {
    $regularContact = Contact::factory()->create(['company_id' => $this->company->id]);
    $priorityContact = Contact::factory()->create(['company_id' => $this->company->id]);

    CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $regularContact->id,
        'status' => 'queued',
        'sort_order' => 1,
        'added_at' => now(),
    ]);
    CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $priorityContact->id,
        'status' => 'queued',
        'sort_order' => 2,
        'is_priority' => true,
        'priority_reason' => 'inbound_call',
        'added_at' => now(),
    ]);

    $queue = $this->service->getQueue($this->agent);

    expect($queue->first()->contact_id)->toBe($priorityContact->id);
    expect($queue->last()->contact_id)->toBe($regularContact->id);
});

it('returns queue items across multiple companies', function () {
    $companyB = Company::factory()->create();
    $this->agent->companies()->attach($companyB->id);

    $campaignB = Campaign::factory()->create(['company_id' => $companyB->id, 'status' => 'active']);
    $contactA = Contact::factory()->create(['company_id' => $this->company->id]);
    $contactB = Contact::factory()->create(['company_id' => $companyB->id]);

    CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $contactA->id,
        'status' => 'queued', 'sort_order' => 1, 'added_at' => now(),
    ]);
    CampaignContact::create([
        'campaign_id' => $campaignB->id,
        'contact_id' => $contactB->id,
        'status' => 'queued', 'sort_order' => 1, 'added_at' => now(),
    ]);

    $queue = $this->service->getQueue($this->agent);

    expect($queue)->toHaveCount(2);
});

it('excludes items claimed by other agents', function () {
    $otherAgent = Agent::factory()->create(['company_id' => $this->company->id]);
    $contact = Contact::factory()->create(['company_id' => $this->company->id]);

    CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $contact->id,
        'status' => 'queued', 'sort_order' => 1,
        'claimed_by_agent_id' => $otherAgent->id,
        'added_at' => now(),
    ]);

    $queue = $this->service->getQueue($this->agent);

    expect($queue)->toHaveCount(0);
});

it('claims a queue item atomically', function () {
    $contact = Contact::factory()->create(['company_id' => $this->company->id]);
    $entry = CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $contact->id,
        'status' => 'queued', 'sort_order' => 1, 'added_at' => now(),
    ]);

    $claimed = $this->service->claim($entry, $this->agent);

    expect($claimed)->toBeTrue();
    expect($entry->fresh()->claimed_by_agent_id)->toBe($this->agent->id);
});

it('fails to claim an already claimed item', function () {
    $otherAgent = Agent::factory()->create(['company_id' => $this->company->id]);
    $contact = Contact::factory()->create(['company_id' => $this->company->id]);
    $entry = CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $contact->id,
        'status' => 'queued', 'sort_order' => 1,
        'claimed_by_agent_id' => $otherAgent->id,
        'added_at' => now(),
    ]);

    $claimed = $this->service->claim($entry, $this->agent);

    expect($claimed)->toBeFalse();
});

it('filters queue by company', function () {
    $companyB = Company::factory()->create();
    $this->agent->companies()->attach($companyB->id);

    $campaignB = Campaign::factory()->create(['company_id' => $companyB->id, 'status' => 'active']);
    $contactA = Contact::factory()->create(['company_id' => $this->company->id]);
    $contactB = Contact::factory()->create(['company_id' => $companyB->id]);

    CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $contactA->id,
        'status' => 'queued', 'sort_order' => 1, 'added_at' => now(),
    ]);
    CampaignContact::create([
        'campaign_id' => $campaignB->id,
        'contact_id' => $contactB->id,
        'status' => 'queued', 'sort_order' => 1, 'added_at' => now(),
    ]);

    $queue = $this->service->getQueue($this->agent, companyId: $companyB->id);

    expect($queue)->toHaveCount(1);
    expect($queue->first()->contact_id)->toBe($contactB->id);
});

it('filters queue by campaign', function () {
    $campaignB = Campaign::factory()->create(['company_id' => $this->company->id, 'status' => 'active']);
    $contactA = Contact::factory()->create(['company_id' => $this->company->id]);
    $contactB = Contact::factory()->create(['company_id' => $this->company->id]);

    CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $contactA->id,
        'status' => 'queued', 'sort_order' => 1, 'added_at' => now(),
    ]);
    CampaignContact::create([
        'campaign_id' => $campaignB->id,
        'contact_id' => $contactB->id,
        'status' => 'queued', 'sort_order' => 1, 'added_at' => now(),
    ]);

    $queue = $this->service->getQueue($this->agent, campaignId: $campaignB->id);

    expect($queue)->toHaveCount(1);
    expect($queue->first()->contact_id)->toBe($contactB->id);
});

it('returns the next item as the first unclaimed priority or queue item', function () {
    $contact = Contact::factory()->create(['company_id' => $this->company->id]);
    CampaignContact::create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $contact->id,
        'status' => 'queued', 'sort_order' => 1, 'added_at' => now(),
    ]);

    $next = $this->service->getNext($this->agent);

    expect($next)->not->toBeNull();
    expect($next->contact_id)->toBe($contact->id);
});
