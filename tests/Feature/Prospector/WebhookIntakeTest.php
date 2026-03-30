<?php

use App\Models\Prospector\Campaign;
use App\Models\Prospector\CampaignContact;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Services\Prospector\WebhookIntakeService;

beforeEach(function () {
    $this->company = Company::factory()->create();
    $this->service = new WebhookIntakeService;
    $this->webhookUrl = '/prospector/webhook/'.$this->company->ulid.'/leads';
});

// ─── Single Lead Endpoint ─────────────────────────────────

it('creates a contact from valid webhook payload', function () {
    $response = $this->postJson($this->webhookUrl, [
        'first_name' => 'John',
        'last_name' => 'Doe',
        'phone' => '(720) 555-1234',
        'email' => 'john@example.com',
    ], ['X-Webhook-Secret' => $this->company->webhook_secret]);

    $response->assertStatus(201)
        ->assertJson([
            'success' => true,
            'created' => true,
            'campaign_enrolled' => false,
            'contact' => [
                'first_name' => 'John',
                'last_name' => 'Doe',
                'phone' => '+17205551234',
                'email' => 'john@example.com',
            ],
        ]);

    $contact = Contact::where('company_id', $this->company->id)->first();
    expect($contact)
        ->first_name->toBe('John')
        ->last_name->toBe('Doe')
        ->phone->toBe('+17205551234')
        ->source->toBe('webhook');
});

it('deduplicates by phone and updates existing contact', function () {
    $existing = Contact::factory()->forCompany($this->company)->create([
        'phone' => '+17205551234',
        'first_name' => 'Jane',
        'last_name' => 'Old',
        'email' => 'old@example.com',
        'tags' => ['existing-tag'],
    ]);

    $response = $this->postJson($this->webhookUrl, [
        'first_name' => 'Jane',
        'last_name' => 'Updated',
        'phone' => '7205551234',
        'email' => 'new@example.com',
        'tags' => ['new-tag'],
    ], ['X-Webhook-Secret' => $this->company->webhook_secret]);

    $response->assertStatus(201)
        ->assertJson([
            'success' => true,
            'created' => false,
        ]);

    $existing->refresh();
    expect($existing)
        ->last_name->toBe('Updated')
        ->email->toBe('new@example.com');
    expect($existing->tags)->toContain('existing-tag')
        ->toContain('new-tag');

    // Only one contact should exist for this phone
    expect(Contact::where('company_id', $this->company->id)->count())->toBe(1);
});

it('returns 401 for invalid webhook secret', function () {
    $response = $this->postJson($this->webhookUrl, [
        'first_name' => 'John',
        'phone' => '7205551234',
    ], ['X-Webhook-Secret' => 'wrong-secret']);

    $response->assertStatus(401)
        ->assertJson(['error' => 'Invalid webhook secret.']);
});

it('returns 401 when webhook secret header is missing', function () {
    $response = $this->postJson($this->webhookUrl, [
        'first_name' => 'John',
        'phone' => '7205551234',
    ]);

    $response->assertStatus(401);
});

it('returns 422 when required fields are missing', function () {
    $response = $this->postJson($this->webhookUrl, [
        'email' => 'test@example.com',
    ], ['X-Webhook-Secret' => $this->company->webhook_secret]);

    $response->assertStatus(422)
        ->assertJson([
            'error' => 'Validation failed.',
        ]);

    $errors = $response->json('errors');
    expect($errors)->toContain('first_name is required.')
        ->toContain('phone is required.');
});

it('returns 422 for invalid phone number', function () {
    $response = $this->postJson($this->webhookUrl, [
        'first_name' => 'John',
        'phone' => '123',
    ], ['X-Webhook-Secret' => $this->company->webhook_secret]);

    $response->assertStatus(422);
    expect($response->json('errors'))->toContain('phone must contain at least 10 digits.');
});

it('returns 404 for inactive company', function () {
    $inactive = Company::factory()->inactive()->create();

    $response = $this->postJson('/prospector/webhook/'.$inactive->ulid.'/leads', [
        'first_name' => 'John',
        'phone' => '7205551234',
    ], ['X-Webhook-Secret' => $inactive->webhook_secret]);

    $response->assertNotFound();
});

it('returns 404 for unknown company ulid', function () {
    $response = $this->postJson('/prospector/webhook/NONEXISTENT123456789012345/leads', [
        'first_name' => 'John',
        'phone' => '7205551234',
    ], ['X-Webhook-Secret' => 'any']);

    $response->assertNotFound();
});

// ─── Campaign Enrollment ──────────────────────────────────

it('enrolls contact in campaign when campaign_ulid is provided', function () {
    $campaign = Campaign::factory()->forCompany($this->company)->active()->create();

    $response = $this->postJson($this->webhookUrl, [
        'first_name' => 'Campaign',
        'last_name' => 'Lead',
        'phone' => '7205559999',
        'campaign_ulid' => $campaign->ulid,
    ], ['X-Webhook-Secret' => $this->company->webhook_secret]);

    $response->assertStatus(201)
        ->assertJson([
            'success' => true,
            'created' => true,
            'campaign_enrolled' => true,
        ]);

    $contact = Contact::where('company_id', $this->company->id)->first();
    expect(CampaignContact::where('campaign_id', $campaign->id)->where('contact_id', $contact->id)->exists())->toBeTrue();

    $campaign->refresh();
    expect($campaign->contact_count)->toBe(1);
});

it('does not enroll contact in inactive campaign', function () {
    $campaign = Campaign::factory()->forCompany($this->company)->create(['status' => 'draft']);

    $response = $this->postJson($this->webhookUrl, [
        'first_name' => 'Draft',
        'phone' => '7205558888',
        'campaign_ulid' => $campaign->ulid,
    ], ['X-Webhook-Secret' => $this->company->webhook_secret]);

    $response->assertStatus(201)
        ->assertJson(['campaign_enrolled' => false]);

    expect(CampaignContact::where('campaign_id', $campaign->id)->count())->toBe(0);
});

it('does not double-enroll contact in same campaign', function () {
    $campaign = Campaign::factory()->forCompany($this->company)->active()->create();
    $contact = Contact::factory()->forCompany($this->company)->create([
        'phone' => '+17205557777',
    ]);

    // Manually enroll first
    CampaignContact::create([
        'campaign_id' => $campaign->id,
        'contact_id' => $contact->id,
        'sort_order' => 1,
        'added_at' => now(),
    ]);

    // Send webhook with same phone
    $response = $this->postJson($this->webhookUrl, [
        'first_name' => $contact->first_name,
        'phone' => '7205557777',
        'campaign_ulid' => $campaign->ulid,
    ], ['X-Webhook-Secret' => $this->company->webhook_secret]);

    $response->assertStatus(201)
        ->assertJson(['campaign_enrolled' => false]);

    expect(CampaignContact::where('campaign_id', $campaign->id)->where('contact_id', $contact->id)->count())->toBe(1);
});

// ─── DNC Protection ───────────────────────────────────────

it('does not update do_not_call contacts', function () {
    $dncContact = Contact::factory()->forCompany($this->company)->dnc()->create([
        'phone' => '+17205556666',
        'first_name' => 'DNC',
        'last_name' => 'Person',
        'email' => 'original@example.com',
    ]);

    $response = $this->postJson($this->webhookUrl, [
        'first_name' => 'Attempted',
        'last_name' => 'Update',
        'phone' => '7205556666',
        'email' => 'hacker@example.com',
    ], ['X-Webhook-Secret' => $this->company->webhook_secret]);

    $response->assertStatus(201);

    $dncContact->refresh();
    expect($dncContact)
        ->first_name->toBe('DNC')
        ->last_name->toBe('Person')
        ->email->toBe('original@example.com');
});

// ─── Bulk Endpoint ────────────────────────────────────────

it('processes bulk leads', function () {
    $response = $this->postJson($this->webhookUrl.'/bulk', [
        'leads' => [
            ['first_name' => 'Bulk One', 'phone' => '7205551001'],
            ['first_name' => 'Bulk Two', 'phone' => '7205551002'],
            ['first_name' => 'Bulk Three', 'phone' => '7205551003'],
        ],
    ], ['X-Webhook-Secret' => $this->company->webhook_secret]);

    $response->assertStatus(201)
        ->assertJson([
            'success' => true,
            'processed' => 3,
            'created' => 3,
            'updated' => 0,
        ]);

    expect(Contact::where('company_id', $this->company->id)->count())->toBe(3);
});

it('returns errors for invalid leads in bulk and processes valid ones', function () {
    $response = $this->postJson($this->webhookUrl.'/bulk', [
        'leads' => [
            ['first_name' => 'Valid Lead', 'phone' => '7205551001'],
            ['phone' => '7205551002'],  // Missing first_name
            ['first_name' => 'Another Valid', 'phone' => '7205551003'],
        ],
    ], ['X-Webhook-Secret' => $this->company->webhook_secret]);

    $response->assertStatus(201);

    $json = $response->json();
    expect($json['processed'])->toBe(2);
    expect($json['created'])->toBe(2);
    expect($json['errors'])->toHaveCount(1);
    expect($json['errors'][0]['index'])->toBe(1);
});

it('rejects bulk request exceeding 100 leads', function () {
    $leads = array_fill(0, 101, ['first_name' => 'Test', 'phone' => '7205551234']);

    $response = $this->postJson($this->webhookUrl.'/bulk', [
        'leads' => $leads,
    ], ['X-Webhook-Secret' => $this->company->webhook_secret]);

    $response->assertStatus(422);
    expect($response->json('errors'))->toContain('Maximum 100 leads per request.');
});

it('rejects bulk request with non-array leads', function () {
    $response = $this->postJson($this->webhookUrl.'/bulk', [
        'leads' => 'not-an-array',
    ], ['X-Webhook-Secret' => $this->company->webhook_secret]);

    $response->assertStatus(422);
    expect($response->json('errors'))->toContain('leads must be an array.');
});

it('validates bulk webhook secret', function () {
    $response = $this->postJson($this->webhookUrl.'/bulk', [
        'leads' => [
            ['first_name' => 'Test', 'phone' => '7205551234'],
        ],
    ], ['X-Webhook-Secret' => 'wrong-secret']);

    $response->assertStatus(401);
});

// ─── Service Unit Tests ───────────────────────────────────

it('formats various phone formats to E164', function (string $input, string $expected) {
    $service = new WebhookIntakeService;
    expect($service->formatPhone($input))->toBe($expected);
})->with([
    'parens format' => ['(720) 555-1234', '+17205551234'],
    'dashes' => ['720-555-1234', '+17205551234'],
    'bare digits' => ['7205551234', '+17205551234'],
    'with country code' => ['+17205551234', '+17205551234'],
    'with 1 prefix no plus' => ['17205551234', '+17205551234'],
]);

it('validates payload correctly', function () {
    $service = new WebhookIntakeService;

    // Valid payload
    expect($service->validatePayload([
        'first_name' => 'John',
        'phone' => '7205551234',
    ]))->toBeEmpty();

    // Missing first_name
    $errors = $service->validatePayload(['phone' => '7205551234']);
    expect($errors)->toContain('first_name is required.');

    // Missing phone
    $errors = $service->validatePayload(['first_name' => 'John']);
    expect($errors)->toContain('phone is required.');

    // Invalid phone (too short)
    $errors = $service->validatePayload(['first_name' => 'John', 'phone' => '123']);
    expect($errors)->toContain('phone must contain at least 10 digits.');
});

it('merges tags when updating existing contact', function () {
    $contact = Contact::factory()->forCompany($this->company)->create([
        'phone' => '+17205554444',
        'tags' => ['original', 'shared'],
    ]);

    $result = $this->service->processLead($this->company, [
        'first_name' => $contact->first_name,
        'phone' => '7205554444',
        'tags' => ['shared', 'new-tag'],
    ]);

    expect($result['created'])->toBeFalse();
    expect($result['contact']->tags)->toContain('original')
        ->toContain('shared')
        ->toContain('new-tag');
});

it('sets correct sort_order when enrolling multiple contacts', function () {
    $campaign = Campaign::factory()->forCompany($this->company)->active()->create();

    $contact1 = Contact::factory()->forCompany($this->company)->create(['phone' => '+17205551001']);
    $contact2 = Contact::factory()->forCompany($this->company)->create(['phone' => '+17205551002']);
    $contact3 = Contact::factory()->forCompany($this->company)->create(['phone' => '+17205551003']);

    $this->service->enrollInCampaign($contact1, $campaign);
    $this->service->enrollInCampaign($contact2, $campaign);
    $this->service->enrollInCampaign($contact3, $campaign);

    $entries = CampaignContact::where('campaign_id', $campaign->id)
        ->orderBy('sort_order')
        ->get();

    expect($entries)->toHaveCount(3);
    expect($entries[0]->sort_order)->toBe(1);
    expect($entries[1]->sort_order)->toBe(2);
    expect($entries[2]->sort_order)->toBe(3);

    $campaign->refresh();
    expect($campaign->contact_count)->toBe(3);
});
