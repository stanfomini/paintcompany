<?php

use App\Events\Prospector\NewLeadArrived;
use App\Events\Prospector\QueueUpdated;
use App\Models\Prospector\Campaign;
use App\Models\Prospector\CampaignContact;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Models\Prospector\Pipeline;
use App\Models\Prospector\PipelineStage;
use App\Services\Prospector\WebhookIntakeService;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->service = new WebhookIntakeService;
});

// ─── Auto-Enrollment in Default Campaign ─────────────────

it('auto-enrolls new contact in default campaign', function () {
    Event::fake([NewLeadArrived::class, QueueUpdated::class]);

    $campaign = Campaign::factory()->forCompany($this->company)->active()->create();
    $this->company->update(['default_campaign_id' => $campaign->id]);

    $result = $this->service->processLead($this->company, [
        'first_name' => 'Speed',
        'last_name' => 'Lead',
        'phone' => '7205551234',
    ]);

    expect($result['created'])->toBeTrue();
    expect($result['campaign_enrolled'])->toBeTrue();

    $entry = CampaignContact::where('campaign_id', $campaign->id)
        ->where('contact_id', $result['contact']->id)
        ->first();

    expect($entry)->not->toBeNull();
    expect($entry->sort_order)->toBe(0); // Front of queue
});

it('auto-enrolled contact has sort_order 0 with existing contacts shifted', function () {
    Event::fake([NewLeadArrived::class, QueueUpdated::class]);

    $campaign = Campaign::factory()->forCompany($this->company)->active()->create();
    $this->company->update(['default_campaign_id' => $campaign->id]);

    // Add some existing contacts to the campaign
    $existing1 = Contact::factory()->forCompany($this->company)->create();
    $existing2 = Contact::factory()->forCompany($this->company)->create();
    CampaignContact::create([
        'campaign_id' => $campaign->id,
        'contact_id' => $existing1->id,
        'sort_order' => 0,
        'status' => 'queued',
        'added_at' => now(),
    ]);
    CampaignContact::create([
        'campaign_id' => $campaign->id,
        'contact_id' => $existing2->id,
        'sort_order' => 1,
        'status' => 'queued',
        'added_at' => now(),
    ]);

    // New lead arrives
    $result = $this->service->processLead($this->company, [
        'first_name' => 'New',
        'phone' => '7205559999',
    ]);

    // New contact should be at front (sort_order 0)
    $newEntry = CampaignContact::where('contact_id', $result['contact']->id)->first();
    expect($newEntry->sort_order)->toBe(0);

    // Existing contacts should be shifted up
    $e1 = CampaignContact::where('contact_id', $existing1->id)->first();
    $e2 = CampaignContact::where('contact_id', $existing2->id)->first();
    expect($e1->sort_order)->toBe(1);
    expect($e2->sort_order)->toBe(2);
});

it('auto-enrolled contact gets default pipeline first stage', function () {
    Event::fake([NewLeadArrived::class, QueueUpdated::class]);

    $campaign = Campaign::factory()->forCompany($this->company)->active()->create();
    $this->company->update(['default_campaign_id' => $campaign->id]);

    $pipeline = Pipeline::factory()->create(['is_default' => true]);
    $stage1 = PipelineStage::factory()->create([
        'pipeline_id' => $pipeline->id,
        'name' => 'New Lead',
        'sort_order' => 0,
    ]);
    PipelineStage::factory()->create([
        'pipeline_id' => $pipeline->id,
        'name' => 'Contacted',
        'sort_order' => 1,
    ]);

    $result = $this->service->processLead($this->company, [
        'first_name' => 'Pipeline',
        'phone' => '7205554444',
    ]);

    $result['contact']->refresh();
    expect($result['contact']->pipeline_stage_id)->toBe($stage1->id);
});

it('does not auto-enroll existing contacts in default campaign', function () {
    Event::fake([NewLeadArrived::class, QueueUpdated::class]);

    $campaign = Campaign::factory()->forCompany($this->company)->active()->create();
    $this->company->update(['default_campaign_id' => $campaign->id]);

    // Create existing contact first
    Contact::factory()->forCompany($this->company)->create([
        'phone' => '+17205551234',
    ]);

    // Process the same phone again (update, not create)
    $result = $this->service->processLead($this->company, [
        'first_name' => 'Updated',
        'phone' => '7205551234',
    ]);

    expect($result['created'])->toBeFalse();
    expect($result['campaign_enrolled'])->toBeFalse();

    $entries = CampaignContact::where('campaign_id', $campaign->id)->count();
    expect($entries)->toBe(0);
});

it('does not auto-enroll when explicit campaign_ulid is provided', function () {
    Event::fake([NewLeadArrived::class, QueueUpdated::class]);

    $defaultCampaign = Campaign::factory()->forCompany($this->company)->active()->create();
    $this->company->update(['default_campaign_id' => $defaultCampaign->id]);

    $explicitCampaign = Campaign::factory()->forCompany($this->company)->active()->create();

    $result = $this->service->processLead($this->company, [
        'first_name' => 'Explicit',
        'phone' => '7205553333',
        'campaign_ulid' => $explicitCampaign->ulid,
    ]);

    expect($result['campaign_enrolled'])->toBeTrue();

    // Should be enrolled in the explicit campaign, NOT the default
    $defaultEntry = CampaignContact::where('campaign_id', $defaultCampaign->id)
        ->where('contact_id', $result['contact']->id)
        ->exists();
    expect($defaultEntry)->toBeFalse();

    $explicitEntry = CampaignContact::where('campaign_id', $explicitCampaign->id)
        ->where('contact_id', $result['contact']->id)
        ->exists();
    expect($explicitEntry)->toBeTrue();
});

it('does not auto-enroll when default campaign is inactive', function () {
    Event::fake([NewLeadArrived::class, QueueUpdated::class]);

    $campaign = Campaign::factory()->forCompany($this->company)->create(['status' => 'draft']);
    $this->company->update(['default_campaign_id' => $campaign->id]);

    $result = $this->service->processLead($this->company, [
        'first_name' => 'Inactive',
        'phone' => '7205552222',
    ]);

    expect($result['created'])->toBeTrue();
    expect($result['campaign_enrolled'])->toBeFalse();
});

// ─── NewLeadArrived Event ────────────────────────────────

it('broadcasts NewLeadArrived event when new contact is created', function () {
    Event::fake([NewLeadArrived::class, QueueUpdated::class]);

    $result = $this->service->processLead($this->company, [
        'first_name' => 'Broadcast',
        'phone' => '7205551111',
    ]);

    Event::assertDispatched(NewLeadArrived::class, function ($event) use ($result) {
        return $event->contact->id === $result['contact']->id
            && $event->company->id === $this->company->id;
    });
});

it('does not broadcast NewLeadArrived for existing contacts', function () {
    Event::fake([NewLeadArrived::class, QueueUpdated::class]);

    Contact::factory()->forCompany($this->company)->create([
        'phone' => '+17205551111',
    ]);

    $this->service->processLead($this->company, [
        'first_name' => 'Updated',
        'phone' => '7205551111',
    ]);

    Event::assertNotDispatched(NewLeadArrived::class);
});

it('NewLeadArrived event has correct broadcast data', function () {
    $event = new NewLeadArrived(
        Contact::factory()->forCompany($this->company)->create([
            'first_name' => 'Test',
            'last_name' => 'Lead',
            'phone' => '+17205551234',
            'source' => 'webhook',
        ]),
        $this->company,
        Campaign::factory()->forCompany($this->company)->create(['name' => 'Test Campaign']),
    );

    $data = $event->broadcastWith();

    expect($data)
        ->toHaveKey('contact_name', 'Test Lead')
        ->toHaveKey('company_id', $this->company->id)
        ->toHaveKey('company_name', $this->company->name)
        ->toHaveKey('campaign_name', 'Test Campaign')
        ->toHaveKey('source', 'webhook');

    expect($event->broadcastAs())->toBe('lead.arrived');
});

// ─── SMS Push-to-Front ───────────────────────────────────

it('pushes queued contact to front when pushToFront is called', function () {
    Event::fake([NewLeadArrived::class, QueueUpdated::class]);

    $campaign = Campaign::factory()->forCompany($this->company)->active()->create();

    $contact1 = Contact::factory()->forCompany($this->company)->create(['phone' => '+17205551001']);
    $contact2 = Contact::factory()->forCompany($this->company)->create(['phone' => '+17205551002']);
    $contact3 = Contact::factory()->forCompany($this->company)->create(['phone' => '+17205551003']);

    CampaignContact::create([
        'campaign_id' => $campaign->id,
        'contact_id' => $contact1->id,
        'sort_order' => 0,
        'status' => 'queued',
        'added_at' => now(),
    ]);
    CampaignContact::create([
        'campaign_id' => $campaign->id,
        'contact_id' => $contact2->id,
        'sort_order' => 1,
        'status' => 'queued',
        'added_at' => now(),
    ]);
    $entry3 = CampaignContact::create([
        'campaign_id' => $campaign->id,
        'contact_id' => $contact3->id,
        'sort_order' => 2,
        'status' => 'queued',
        'added_at' => now(),
    ]);

    // Push contact3 (sort_order=2) to front
    $this->service->pushToFront($contact3, $entry3);

    // Contact3 should now be at front
    $entry3->refresh();
    expect($entry3->sort_order)->toBe(0);

    // Others should be shifted
    $e1 = CampaignContact::where('contact_id', $contact1->id)->first();
    $e2 = CampaignContact::where('contact_id', $contact2->id)->first();
    expect($e1->sort_order)->toBe(1);
    expect($e2->sort_order)->toBe(2);
});

it('broadcasts QueueUpdated on push-to-front', function () {
    Event::fake([NewLeadArrived::class, QueueUpdated::class]);

    $campaign = Campaign::factory()->forCompany($this->company)->active()->create();
    $contact = Contact::factory()->forCompany($this->company)->create();

    $entry = CampaignContact::create([
        'campaign_id' => $campaign->id,
        'contact_id' => $contact->id,
        'sort_order' => 5,
        'status' => 'queued',
        'added_at' => now(),
    ]);

    $this->service->pushToFront($contact, $entry);

    Event::assertDispatched(QueueUpdated::class, function ($event) use ($contact, $campaign) {
        return $event->contact->id === $contact->id
            && $event->campaign->id === $campaign->id
            && $event->action === 'priority_bump';
    });
});

// ─── QueueUpdated Event ──────────────────────────────────

it('QueueUpdated event has correct broadcast data', function () {
    $campaign = Campaign::factory()->forCompany($this->company)->create();
    $contact = Contact::factory()->forCompany($this->company)->create([
        'first_name' => 'Queue',
        'last_name' => 'Test',
    ]);

    $event = new QueueUpdated($contact, $campaign, 'added');

    $data = $event->broadcastWith();

    expect($data)
        ->toHaveKey('action', 'added')
        ->toHaveKey('contact_id', $contact->id)
        ->toHaveKey('contact_name', 'Queue Test')
        ->toHaveKey('campaign_id', $campaign->id);

    expect($event->broadcastAs())->toBe('queue.updated');
});

it('broadcasts QueueUpdated when enrolling at front', function () {
    Event::fake([NewLeadArrived::class, QueueUpdated::class]);

    $campaign = Campaign::factory()->forCompany($this->company)->active()->create();
    $contact = Contact::factory()->forCompany($this->company)->create();

    $this->service->enrollAtFront($contact, $campaign);

    Event::assertDispatched(QueueUpdated::class, function ($event) use ($contact, $campaign) {
        return $event->contact->id === $contact->id
            && $event->campaign->id === $campaign->id
            && $event->action === 'added';
    });
});
