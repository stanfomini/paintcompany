<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\LeadSource;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Models\WebhookFieldMapping;
use App\Models\WebhookLog;
use App\Services\WebhookLeadProcessingService;
use App\Services\WebhookMappingPresetService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('processes angi payload and creates customer and deal', function () {
    \Illuminate\Support\Facades\Event::fake([\App\Events\NewLeadCreated::class]);

    $user = User::factory()->internal()->create();
    $leadSource = LeadSource::factory()->webhook()->create(['name' => 'Angi', 'slug' => 'angi']);
    $endpoint = WebhookEndpoint::factory()->createdBy($user)->create(['lead_source_id' => $leadSource->id]);

    $presetService = app(WebhookMappingPresetService::class);
    $presetService->applyPreset($endpoint, 'angi');

    $payload = [
        'firstName' => 'Sarah',
        'lastName' => 'Johnson',
        'email' => 'sarah@example.com',
        'primaryPhone' => '(303) 555-1234',
        'address' => '123 Oak St',
        'city' => 'Denver',
        'stateProvince' => 'CO',
        'postalCode' => '80202',
        'taskName' => 'Interior Painting',
        'comments' => 'Need living room and bedroom painted',
        'leadOid' => 'ANGI-999',
    ];

    $service = app(WebhookLeadProcessingService::class);
    $log = $service->process($endpoint, $payload, '127.0.0.1');

    expect($log->status)->toBe('processed');

    $customer = Customer::where('email', 'sarah@example.com')->first();
    expect($customer)->not->toBeNull();
    expect($customer->first_name)->toBe('Sarah');
    expect($customer->last_name)->toBe('Johnson');
    expect($customer->phone)->toBe('3035551234');
    expect($customer->city)->toBe('Denver');

    $deal = Deal::where('customer_id', $customer->id)->first();
    expect($deal)->not->toBeNull();
    expect($deal->title)->toBe('Interior Painting');
    expect($deal->description)->toBe('Need living room and bedroom painted');
    expect($deal->external_id)->toBe('ANGI-999');
    expect($deal->lead_source_id)->toBe($leadSource->id);
});

it('processes craftjack payload', function () {
    \Illuminate\Support\Facades\Event::fake([\App\Events\NewLeadCreated::class]);

    $user = User::factory()->internal()->create();
    $leadSource = LeadSource::factory()->webhook()->create(['name' => 'CraftJack', 'slug' => 'craftjack']);
    $endpoint = WebhookEndpoint::factory()->createdBy($user)->create(['lead_source_id' => $leadSource->id]);

    $presetService = app(WebhookMappingPresetService::class);
    $presetService->applyPreset($endpoint, 'craftjack');

    $payload = [
        'FirstName' => 'Bob',
        'LastName' => 'Williams',
        'EmailAddress' => 'bob@test.com',
        'DirectLeadPhoneNumber' => '720-555-9876',
        'Address' => '456 Pine Ave',
        'City' => 'Boulder',
        'State' => 'CO',
        'ZipCode' => '80302',
        'ServiceName' => 'Exterior House Painting',
        'Notes' => 'Two-story colonial, needs full repaint',
        'LeadId' => 'CJ-54321',
    ];

    $service = app(WebhookLeadProcessingService::class);
    $log = $service->process($endpoint, $payload, '127.0.0.1');

    expect($log->status)->toBe('processed');

    $customer = Customer::where('email', 'bob@test.com')->first();
    expect($customer)->not->toBeNull();
    expect($customer->first_name)->toBe('Bob');
    expect($customer->last_name)->toBe('Williams');
    expect($customer->phone)->toBe('7205559876');

    $deal = Deal::where('customer_id', $customer->id)->first();
    expect($deal)->not->toBeNull();
    expect($deal->title)->toBe('Exterior House Painting');
    expect($deal->external_id)->toBe('CJ-54321');
});

it('processes thumbtack payload with name splitting', function () {
    \Illuminate\Support\Facades\Event::fake([\App\Events\NewLeadCreated::class]);

    $user = User::factory()->internal()->create();
    $leadSource = LeadSource::factory()->webhook()->create(['name' => 'Thumbtack', 'slug' => 'thumbtack']);
    $endpoint = WebhookEndpoint::factory()->createdBy($user)->create(['lead_source_id' => $leadSource->id]);

    $presetService = app(WebhookMappingPresetService::class);
    $presetService->applyPreset($endpoint, 'thumbtack');

    $payload = [
        'customer' => [
            'name' => 'Emily Davis',
        ],
        'request' => [
            'category' => 'Interior Painting',
            'description' => 'Kitchen cabinets need refinishing',
            'location' => [
                'city' => 'Fort Collins',
                'state' => 'CO',
                'zipCode' => '80525',
            ],
        ],
        'leadID' => 'TT-11111',
    ];

    $service = app(WebhookLeadProcessingService::class);
    $log = $service->process($endpoint, $payload, '127.0.0.1');

    expect($log->status)->toBe('processed');

    $customer = Customer::where('first_name', 'Emily')->first();
    expect($customer)->not->toBeNull();
    expect($customer->last_name)->toBe('Davis');
    expect($customer->city)->toBe('Fort Collins');
    expect($customer->state)->toBe('CO');

    $deal = Deal::where('customer_id', $customer->id)->first();
    expect($deal)->not->toBeNull();
    expect($deal->title)->toBe('Interior Painting');
    expect($deal->external_id)->toBe('TT-11111');
});

it('logs failed processing', function () {
    $user = User::factory()->internal()->create();
    $endpoint = WebhookEndpoint::factory()->createdBy($user)->create();

    // Create a mapping that targets an invalid field, which will cause an error
    WebhookFieldMapping::factory()->create([
        'webhook_endpoint_id' => $endpoint->id,
        'source_path' => 'name',
        'target_model' => 'customer',
        'target_field' => 'first_name',
    ]);

    // Mock to force an exception
    $service = $this->mock(WebhookLeadProcessingService::class, function ($mock) {
        $mock->shouldReceive('process')->andReturnUsing(function ($endpoint, $payload, $ip) {
            $log = WebhookLog::create([
                'webhook_endpoint_id' => $endpoint->id,
                'payload' => $payload,
                'status' => 'failed',
                'error_message' => 'Simulated processing error',
                'ip_address' => $ip,
            ]);

            return $log;
        });
    });

    $log = $service->process($endpoint, ['name' => 'Test'], '127.0.0.1');

    expect($log->status)->toBe('failed');
    expect($log->error_message)->toBe('Simulated processing error');
});

it('increments total_leads counter', function () {
    \Illuminate\Support\Facades\Event::fake([\App\Events\NewLeadCreated::class]);

    $user = User::factory()->internal()->create();
    $endpoint = WebhookEndpoint::factory()->createdBy($user)->create(['total_leads' => 0]);

    WebhookFieldMapping::factory()->forCustomer('first_name', 'first_name')->create(['webhook_endpoint_id' => $endpoint->id, 'sort_order' => 0]);
    WebhookFieldMapping::factory()->forCustomer('email', 'email')->create(['webhook_endpoint_id' => $endpoint->id, 'sort_order' => 1]);

    $service = app(WebhookLeadProcessingService::class);
    $service->process($endpoint, ['first_name' => 'Lead1', 'email' => 'lead1@test.com'], '127.0.0.1');

    $endpoint->refresh();
    expect($endpoint->total_leads)->toBe(1);

    $service->process($endpoint, ['first_name' => 'Lead2', 'email' => 'lead2@test.com'], '127.0.0.1');

    $endpoint->refresh();
    expect($endpoint->total_leads)->toBe(2);
});

it('sets lead_source on created deal', function () {
    \Illuminate\Support\Facades\Event::fake([\App\Events\NewLeadCreated::class]);

    $user = User::factory()->internal()->create();
    $leadSource = LeadSource::factory()->webhook()->create(['slug' => 'test_source']);
    $endpoint = WebhookEndpoint::factory()->createdBy($user)->create(['lead_source_id' => $leadSource->id]);

    WebhookFieldMapping::factory()->forCustomer('first_name', 'first_name')->create(['webhook_endpoint_id' => $endpoint->id, 'sort_order' => 0]);
    WebhookFieldMapping::factory()->forCustomer('email', 'email')->create(['webhook_endpoint_id' => $endpoint->id, 'sort_order' => 1]);

    $service = app(WebhookLeadProcessingService::class);
    $service->process($endpoint, ['first_name' => 'Test', 'email' => 'test@test.com'], '127.0.0.1');

    $deal = Deal::where('lead_source_id', $leadSource->id)->first();
    expect($deal)->not->toBeNull();
    expect($deal->lead_source_id)->toBe($leadSource->id);
    expect($deal->lead_source)->toBe('test_source');
});

it('assigns to default user', function () {
    \Illuminate\Support\Facades\Event::fake([\App\Events\NewLeadCreated::class]);

    $creator = User::factory()->internal()->create();
    $assignee = User::factory()->internal()->create();
    $endpoint = WebhookEndpoint::factory()->createdBy($creator)->assignedTo($assignee)->create();

    WebhookFieldMapping::factory()->forCustomer('first_name', 'first_name')->create(['webhook_endpoint_id' => $endpoint->id, 'sort_order' => 0]);
    WebhookFieldMapping::factory()->forCustomer('email', 'email')->create(['webhook_endpoint_id' => $endpoint->id, 'sort_order' => 1]);

    $service = app(WebhookLeadProcessingService::class);
    $service->process($endpoint, ['first_name' => 'Assigned', 'email' => 'assign@test.com'], '127.0.0.1');

    $deal = Deal::latest()->first();
    expect($deal->assigned_to)->toBe($assignee->id);

    $customer = Customer::where('email', 'assign@test.com')->first();
    expect($customer->assigned_to)->toBe($assignee->id);
});
