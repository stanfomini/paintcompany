<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\LeadSource;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Models\WebhookFieldMapping;
use App\Models\WebhookLog;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('returns 404 for invalid webhook token', function () {
    $this->postJson('/webhooks/leads/invalid-token-here', ['name' => 'Test'])
        ->assertNotFound()
        ->assertJson(['error' => 'Invalid webhook token']);
});

it('stores sample payload in listening mode', function () {
    $endpoint = WebhookEndpoint::factory()->create();

    $payload = ['firstName' => 'John', 'lastName' => 'Doe', 'email' => 'john@test.com'];

    $this->postJson("/webhooks/leads/{$endpoint->token}", $payload)
        ->assertOk()
        ->assertJson(['status' => 'received', 'message' => 'Sample payload stored']);

    $endpoint->refresh();
    expect($endpoint->sample_payload)->toBe($payload);
    expect($endpoint->last_payload_at)->not->toBeNull();
});

it('returns 401 for invalid api key auth', function () {
    $endpoint = WebhookEndpoint::factory()->withApiKeyAuth('X-Api-Key', 'correct-key')->create();

    // Add a mapping so it doesn't go into listening mode
    WebhookFieldMapping::factory()->create(['webhook_endpoint_id' => $endpoint->id]);

    $this->postJson("/webhooks/leads/{$endpoint->token}", ['name' => 'Test'])
        ->assertUnauthorized()
        ->assertJson(['error' => 'Unauthorized']);
});

it('accepts valid api key auth', function () {
    \Illuminate\Support\Facades\Event::fake([\App\Events\NewLeadCreated::class]);

    $endpoint = WebhookEndpoint::factory()->withApiKeyAuth('X-Api-Key', 'correct-key')->create();

    WebhookFieldMapping::factory()->forCustomer('first_name', 'first_name')->create(['webhook_endpoint_id' => $endpoint->id]);
    WebhookFieldMapping::factory()->forCustomer('last_name', 'last_name')->create(['webhook_endpoint_id' => $endpoint->id, 'sort_order' => 1]);
    WebhookFieldMapping::factory()->forCustomer('email', 'email')->create(['webhook_endpoint_id' => $endpoint->id, 'sort_order' => 2]);

    $this->postJson("/webhooks/leads/{$endpoint->token}", [
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'john@test.com',
    ], ['X-Api-Key' => 'correct-key'])
        ->assertOk()
        ->assertJson(['status' => 'success']);
});

it('accepts valid bearer token auth', function () {
    \Illuminate\Support\Facades\Event::fake([\App\Events\NewLeadCreated::class]);

    $endpoint = WebhookEndpoint::factory()->withBearerAuth('my-secret-token')->create();

    WebhookFieldMapping::factory()->forCustomer('first_name', 'first_name')->create(['webhook_endpoint_id' => $endpoint->id]);
    WebhookFieldMapping::factory()->forCustomer('email', 'email')->create(['webhook_endpoint_id' => $endpoint->id, 'sort_order' => 1]);

    $this->postJson("/webhooks/leads/{$endpoint->token}", [
        'first_name' => 'Jane',
        'email' => 'jane@test.com',
    ], ['Authorization' => 'Bearer my-secret-token'])
        ->assertOk()
        ->assertJson(['status' => 'success']);
});

it('returns 403 for disabled webhook with mappings', function () {
    $endpoint = WebhookEndpoint::factory()->inactive()->create();

    WebhookFieldMapping::factory()->create(['webhook_endpoint_id' => $endpoint->id]);

    $this->postJson("/webhooks/leads/{$endpoint->token}", ['name' => 'Test'])
        ->assertForbidden()
        ->assertJson(['error' => 'Webhook is disabled']);
});

it('processes payload and creates customer and deal', function () {
    \Illuminate\Support\Facades\Event::fake([\App\Events\NewLeadCreated::class]);

    $user = User::factory()->internal()->create();
    $endpoint = WebhookEndpoint::factory()->createdBy($user)->create();

    WebhookFieldMapping::factory()->forCustomer('first_name', 'first_name')->create(['webhook_endpoint_id' => $endpoint->id, 'sort_order' => 0]);
    WebhookFieldMapping::factory()->forCustomer('last_name', 'last_name')->create(['webhook_endpoint_id' => $endpoint->id, 'sort_order' => 1]);
    WebhookFieldMapping::factory()->forCustomer('email', 'email')->create(['webhook_endpoint_id' => $endpoint->id, 'sort_order' => 2]);
    WebhookFieldMapping::factory()->forDeal('project', 'title')->create(['webhook_endpoint_id' => $endpoint->id, 'sort_order' => 3]);

    $this->postJson("/webhooks/leads/{$endpoint->token}", [
        'first_name' => 'Mike',
        'last_name' => 'Smith',
        'email' => 'mike@example.com',
        'project' => 'Kitchen Repaint',
    ])
        ->assertOk()
        ->assertJson(['status' => 'success']);

    $this->assertDatabaseHas('customers', [
        'first_name' => 'Mike',
        'last_name' => 'Smith',
        'email' => 'mike@example.com',
    ]);

    $this->assertDatabaseHas('deals', [
        'title' => 'Kitchen Repaint',
        'stage' => 'lead',
    ]);

    $log = WebhookLog::where('webhook_endpoint_id', $endpoint->id)->first();
    expect($log)->not->toBeNull();
    expect($log->status)->toBe('processed');
    expect($log->customer_id)->not->toBeNull();
    expect($log->deal_id)->not->toBeNull();
});

it('detects duplicate by external_id', function () {
    \Illuminate\Support\Facades\Event::fake([\App\Events\NewLeadCreated::class]);

    $user = User::factory()->internal()->create();
    $leadSource = LeadSource::factory()->webhook()->create();
    $endpoint = WebhookEndpoint::factory()->createdBy($user)->create(['lead_source_id' => $leadSource->id]);

    WebhookFieldMapping::factory()->forCustomer('first_name', 'first_name')->create(['webhook_endpoint_id' => $endpoint->id, 'sort_order' => 0]);
    WebhookFieldMapping::factory()->forCustomer('email', 'email')->create(['webhook_endpoint_id' => $endpoint->id, 'sort_order' => 1]);
    WebhookFieldMapping::factory()->forDeal('lead_id', 'external_id')->create(['webhook_endpoint_id' => $endpoint->id, 'sort_order' => 2]);

    $payload = [
        'first_name' => 'John',
        'email' => 'john@test.com',
        'lead_id' => 'EXT-12345',
    ];

    // First request: creates customer + deal
    $this->postJson("/webhooks/leads/{$endpoint->token}", $payload)
        ->assertOk()
        ->assertJson(['status' => 'success']);

    // Second request: detected as duplicate
    $this->postJson("/webhooks/leads/{$endpoint->token}", $payload)
        ->assertOk()
        ->assertJson(['status' => 'duplicate']);

    // Only 1 deal with this external_id
    expect(Deal::where('external_id', 'EXT-12345')->count())->toBe(1);
});

it('finds existing customer by email', function () {
    \Illuminate\Support\Facades\Event::fake([\App\Events\NewLeadCreated::class]);

    $user = User::factory()->internal()->create();
    $existing = Customer::factory()->createdBy($user)->create([
        'first_name' => 'Existing',
        'last_name' => 'Customer',
        'email' => 'existing@test.com',
    ]);

    $endpoint = WebhookEndpoint::factory()->createdBy($user)->create();

    WebhookFieldMapping::factory()->forCustomer('first_name', 'first_name')->create(['webhook_endpoint_id' => $endpoint->id, 'sort_order' => 0]);
    WebhookFieldMapping::factory()->forCustomer('email', 'email')->create(['webhook_endpoint_id' => $endpoint->id, 'sort_order' => 1]);
    WebhookFieldMapping::factory()->forDeal('title', 'title')->create(['webhook_endpoint_id' => $endpoint->id, 'sort_order' => 2]);

    $this->postJson("/webhooks/leads/{$endpoint->token}", [
        'first_name' => 'Different Name',
        'email' => 'existing@test.com',
        'title' => 'New Project',
    ])
        ->assertOk()
        ->assertJson(['status' => 'success']);

    // Should not duplicate the customer
    expect(Customer::where('email', 'existing@test.com')->count())->toBe(1);

    // Deal should be created for existing customer
    $deal = Deal::where('customer_id', $existing->id)->first();
    expect($deal)->not->toBeNull();
    expect($deal->title)->toBe('New Project');
});
