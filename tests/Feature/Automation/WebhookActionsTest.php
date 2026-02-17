<?php

use App\Automation\Actions\CreateCustomer;
use App\Automation\Actions\CreateDeal;
use App\Automation\Actions\CreateProperty;
use App\Automation\Actions\HTTPRequest;
use App\Automation\Actions\InviteToPortal;
use App\Automation\Actions\RoundRobinAssign;
use App\Automation\Actions\SendEmail;
use App\Automation\Actions\SetLeadSource;
use App\Automation\Conditions\LeadSourceIs;
use App\Automation\Conditions\WebhookFieldEquals;
use App\Automation\DynamicValues\WebhookValues;
use App\Automation\Registry;
use App\Mail\WorkflowEmail;
use App\Models\AutomationWebhook;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\LeadSource;
use App\Models\Property;
use App\Models\User;
use App\Models\WorkflowRun;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

// ============================================================================
// WebhookValues Dynamic Resolver
// ============================================================================

it('resolves flat webhook payload values', function () {
    $run = WorkflowRun::factory()->create([
        'context' => [
            'payload' => [
                'email' => 'test@example.com',
                'name' => 'John Doe',
            ],
        ],
    ]);

    $resolver = new WebhookValues;

    expect($resolver->resolve('email', $run))->toBe('test@example.com')
        ->and($resolver->resolve('name', $run))->toBe('John Doe');
});

it('resolves nested webhook payload values with dot notation', function () {
    $run = WorkflowRun::factory()->create([
        'context' => [
            'payload' => [
                'lead' => [
                    'first_name' => 'Jane',
                    'contact' => [
                        'email' => 'jane@example.com',
                    ],
                ],
            ],
        ],
    ]);

    $resolver = new WebhookValues;

    expect($resolver->resolve('lead.first_name', $run))->toBe('Jane')
        ->and($resolver->resolve('lead.contact.email', $run))->toBe('jane@example.com');
});

it('returns null for missing webhook payload keys', function () {
    $run = WorkflowRun::factory()->create([
        'context' => [
            'payload' => ['email' => 'test@example.com'],
        ],
    ]);

    $resolver = new WebhookValues;
    expect($resolver->resolve('nonexistent', $run))->toBeNull();
});

it('returns null when no payload in context', function () {
    $run = WorkflowRun::factory()->create([
        'context' => [],
    ]);

    $resolver = new WebhookValues;
    expect($resolver->resolve('email', $run))->toBeNull();
});

it('has correct prefix for WebhookValues', function () {
    expect(WebhookValues::getPrefix())->toBe('webhook')
        ->and(WebhookValues::getAvailableKeys())->toBe([]);
});

// ============================================================================
// Registry Regex Fix (dot-notation in dynamic values)
// ============================================================================

it('resolves dot-notation dynamic values through registry', function () {
    $run = WorkflowRun::factory()->create([
        'context' => [
            'payload' => [
                'lead' => ['email' => 'nested@example.com'],
            ],
        ],
    ]);

    $registry = app(Registry::class);
    $result = $registry->resolveDynamicValues('Email: {{webhook.lead.email}}', $run);

    expect($result)->toBe('Email: nested@example.com');
});

// ============================================================================
// CreateCustomer Action
// ============================================================================

it('creates a new customer from config', function () {
    $user = User::factory()->internal()->create();
    $webhook = AutomationWebhook::factory()->create();

    $run = WorkflowRun::factory()->create([
        'subject_type' => AutomationWebhook::class,
        'subject_id' => $webhook->id,
        'triggered_by' => $user->id,
    ]);

    $action = new CreateCustomer;
    $result = $action->execute($run, [
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'john@example.com',
        'phone' => '555-1234',
    ]);

    expect($result)->toHaveKey('customer_id')
        ->and($result['created'])->toBeTrue();

    $customer = Customer::find($result['customer_id']);
    expect($customer->first_name)->toBe('John')
        ->and($customer->last_name)->toBe('Doe')
        ->and($customer->email)->toBe('john@example.com')
        ->and($customer->phone)->toBe('555-1234');
});

it('deduplicates customers by email', function () {
    $user = User::factory()->internal()->create();
    $existing = Customer::factory()->create(['email' => 'existing@example.com']);
    $webhook = AutomationWebhook::factory()->create();

    $run = WorkflowRun::factory()->create([
        'subject_type' => AutomationWebhook::class,
        'subject_id' => $webhook->id,
        'triggered_by' => $user->id,
    ]);

    $action = new CreateCustomer;
    $result = $action->execute($run, [
        'first_name' => 'Different',
        'email' => 'existing@example.com',
        'deduplicate_by_email' => true,
    ]);

    expect($result['customer_id'])->toBe($existing->id)
        ->and($result['created'])->toBeFalse();

    // Should not create a duplicate
    expect(Customer::where('email', 'existing@example.com')->count())->toBe(1);
});

it('swaps run subject to the created customer', function () {
    $user = User::factory()->internal()->create();
    $webhook = AutomationWebhook::factory()->create();

    $run = WorkflowRun::factory()->create([
        'subject_type' => AutomationWebhook::class,
        'subject_id' => $webhook->id,
        'triggered_by' => $user->id,
    ]);

    $action = new CreateCustomer;
    $result = $action->execute($run, [
        'first_name' => 'Jane',
        'email' => 'jane@example.com',
    ]);

    $run->refresh();
    expect($run->subject_type)->toBe(Customer::class)
        ->and($run->subject_id)->toBe($result['customer_id'])
        ->and($run->context['customer_id'])->toBe($result['customer_id']);
});

it('has correct metadata for CreateCustomer', function () {
    expect(CreateCustomer::getName())->toBe('Create Customer')
        ->and(CreateCustomer::getCategory())->toBe('Record Management')
        ->and(CreateCustomer::getDescription())->toBeString()
        ->and(CreateCustomer::getConfigSchema())->toHaveKeys(['first_name', 'last_name', 'email', 'phone', 'deduplicate_by_email']);
});

// ============================================================================
// CreateDeal Action
// ============================================================================

it('creates a deal linked to customer subject', function () {
    $user = User::factory()->internal()->create();
    $customer = Customer::factory()->create();

    $run = WorkflowRun::factory()->create([
        'subject_type' => Customer::class,
        'subject_id' => $customer->id,
        'triggered_by' => $user->id,
    ]);

    $action = new CreateDeal;
    $result = $action->execute($run, [
        'title' => 'Facebook Lead',
        'stage' => 'lead',
        'lead_source' => 'facebook',
    ]);

    expect($result)->toHaveKey('deal_id');

    $deal = Deal::find($result['deal_id']);
    expect($deal->title)->toBe('Facebook Lead')
        ->and($deal->stage)->toBe('lead')
        ->and($deal->lead_source)->toBe('facebook')
        ->and($deal->customer_id)->toBe($customer->id);
});

it('creates a deal using customer_id from context', function () {
    $user = User::factory()->internal()->create();
    $customer = Customer::factory()->create();

    $run = WorkflowRun::factory()->create([
        'subject_type' => null,
        'subject_id' => null,
        'triggered_by' => $user->id,
        'context' => ['customer_id' => $customer->id],
    ]);

    $action = new CreateDeal;
    $result = $action->execute($run, ['title' => 'Context Deal']);

    $deal = Deal::find($result['deal_id']);
    expect($deal->customer_id)->toBe($customer->id);

    $run->refresh();
    expect($run->context['deal_id'])->toBe($deal->id);
});

it('has correct metadata for CreateDeal', function () {
    expect(CreateDeal::getName())->toBe('Create Deal')
        ->and(CreateDeal::getCategory())->toBe('Record Management')
        ->and(CreateDeal::getConfigSchema())->toHaveKeys(['title', 'stage', 'lead_source', 'description']);
});

// ============================================================================
// CreateProperty Action
// ============================================================================

it('creates a property linked to customer', function () {
    $user = User::factory()->internal()->create();
    $customer = Customer::factory()->create();

    $run = WorkflowRun::factory()->create([
        'subject_type' => Customer::class,
        'subject_id' => $customer->id,
        'triggered_by' => $user->id,
    ]);

    $action = new CreateProperty;
    $result = $action->execute($run, [
        'address' => '123 Main St',
        'city' => 'Springfield',
        'state' => 'IL',
        'zip' => '62701',
    ]);

    expect($result)->toHaveKey('property_id')
        ->and($result['created'])->toBeTrue();

    $property = Property::find($result['property_id']);
    expect($property->address)->toBe('123 Main St')
        ->and($property->city)->toBe('Springfield')
        ->and($property->customer_id)->toBe($customer->id);
});

it('deduplicates properties by address and city', function () {
    $customer = Customer::factory()->create();
    $existing = Property::factory()->create([
        'customer_id' => $customer->id,
        'address' => '123 Main St',
        'city' => 'Springfield',
    ]);

    $run = WorkflowRun::factory()->create([
        'subject_type' => Customer::class,
        'subject_id' => $customer->id,
    ]);

    $action = new CreateProperty;
    $result = $action->execute($run, [
        'address' => '123 Main St',
        'city' => 'Springfield',
    ]);

    expect($result['property_id'])->toBe($existing->id)
        ->and($result['created'])->toBeFalse();
});

it('has correct metadata for CreateProperty', function () {
    expect(CreateProperty::getName())->toBe('Create Property')
        ->and(CreateProperty::getCategory())->toBe('Record Management')
        ->and(CreateProperty::getConfigSchema())->toHaveKeys(['address', 'city', 'state', 'zip', 'type']);
});

// ============================================================================
// SendEmail Action
// ============================================================================

it('queues an email with resolved values', function () {
    Mail::fake();

    $run = WorkflowRun::factory()->create();

    $action = new SendEmail;
    $result = $action->execute($run, [
        'to' => 'customer@example.com',
        'subject' => 'Welcome!',
        'body' => 'Thanks for signing up.',
    ]);

    expect($result['sent'])->toBeTrue()
        ->and($result['to'])->toBe('customer@example.com');

    Mail::assertQueued(WorkflowEmail::class, function (WorkflowEmail $mail) {
        return $mail->emailSubject === 'Welcome!'
            && $mail->emailBody === 'Thanks for signing up.';
    });
});

it('has correct metadata for SendEmail', function () {
    expect(SendEmail::getName())->toBe('Send Email')
        ->and(SendEmail::getCategory())->toBe('Communication')
        ->and(SendEmail::getConfigSchema())->toHaveKeys(['to', 'subject', 'body', 'from_name']);
});

// ============================================================================
// RoundRobinAssign Action
// ============================================================================

it('assigns round-robin from user list', function () {
    Cache::flush();

    $users = User::factory()->internal()->count(3)->create();
    $customer = Customer::factory()->create();

    $run = WorkflowRun::factory()->create([
        'subject_type' => Customer::class,
        'subject_id' => $customer->id,
    ]);

    $userIds = $users->pluck('id')->implode(',');

    $action = new RoundRobinAssign;
    $result1 = $action->execute($run, ['user_ids' => $userIds]);

    expect($result1['assigned'])->toBeTrue()
        ->and($result1['assigned_to'])->toBe($users[0]->id);
});

it('rotates through users on subsequent calls', function () {
    Cache::flush();

    $users = User::factory()->internal()->count(3)->create();
    $customer = Customer::factory()->create();

    $userIds = $users->pluck('id')->implode(',');
    $action = new RoundRobinAssign;

    // Use the same workflow for all runs so round-robin key is consistent
    $workflow = \App\Models\Workflow::factory()->create();

    $results = [];
    for ($i = 0; $i < 3; $i++) {
        $run = WorkflowRun::factory()->create([
            'subject_type' => Customer::class,
            'subject_id' => $customer->id,
            'workflow_id' => $workflow->id,
        ]);
        $results[] = $action->execute($run, ['user_ids' => $userIds]);
    }

    expect($results[0]['assigned_to'])->toBe($users[0]->id)
        ->and($results[1]['assigned_to'])->toBe($users[1]->id)
        ->and($results[2]['assigned_to'])->toBe($users[2]->id);
});

it('has correct metadata for RoundRobinAssign', function () {
    expect(RoundRobinAssign::getName())->toBe('Round Robin Assign')
        ->and(RoundRobinAssign::getCategory())->toBe('Assignment')
        ->and(RoundRobinAssign::getConfigSchema())->toHaveKeys(['user_ids', 'assignment_field']);
});

// ============================================================================
// HTTPRequest Action
// ============================================================================

it('makes HTTP request with correct method and body', function () {
    Http::fake([
        'https://api.example.com/*' => Http::response(['ok' => true], 200),
    ]);

    $run = WorkflowRun::factory()->create();

    $action = new HTTPRequest;
    $result = $action->execute($run, [
        'url' => 'https://api.example.com/leads',
        'method' => 'POST',
        'body' => '{"name": "Test"}',
    ]);

    expect($result['status_code'])->toBe(200)
        ->and($result['successful'])->toBeTrue();

    Http::assertSent(function ($request) {
        return $request->url() === 'https://api.example.com/leads'
            && $request->method() === 'POST';
    });
});

it('has correct metadata for HTTPRequest', function () {
    expect(HTTPRequest::getName())->toBe('HTTP Request')
        ->and(HTTPRequest::getCategory())->toBe('Integrations')
        ->and(HTTPRequest::getConfigSchema())->toHaveKeys(['url', 'method', 'headers', 'body', 'timeout']);
});

// ============================================================================
// SetLeadSource Action
// ============================================================================

it('sets lead source on deal by slug', function () {
    $leadSource = LeadSource::factory()->create(['slug' => 'facebook', 'name' => 'Facebook']);
    $deal = Deal::factory()->create();

    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
    ]);

    $action = new SetLeadSource;
    $result = $action->execute($run, ['lead_source_slug' => 'facebook']);

    expect($result['lead_source_set'])->toBeTrue()
        ->and($result['slug'])->toBe('facebook');

    $deal->refresh();
    expect($deal->lead_source)->toBe('facebook')
        ->and($deal->lead_source_id)->toBe($leadSource->id);
});

it('returns error when no deal found for SetLeadSource', function () {
    $user = User::factory()->internal()->create();

    $run = WorkflowRun::factory()->create([
        'subject_type' => User::class,
        'subject_id' => $user->id,
    ]);

    $action = new SetLeadSource;
    $result = $action->execute($run, ['lead_source_slug' => 'facebook']);

    expect($result['lead_source_set'])->toBeFalse();
});

it('has correct metadata for SetLeadSource', function () {
    expect(SetLeadSource::getName())->toBe('Set Lead Source')
        ->and(SetLeadSource::getCategory())->toBe('Record Management')
        ->and(SetLeadSource::getConfigSchema())->toHaveKeys(['lead_source_slug']);
});

// ============================================================================
// InviteToPortal Action
// ============================================================================

it('has correct metadata for InviteToPortal', function () {
    expect(InviteToPortal::getName())->toBe('Invite to Portal')
        ->and(InviteToPortal::getCategory())->toBe('Communication')
        ->and(InviteToPortal::getConfigSchema())->toHaveKeys(['email']);
});

// ============================================================================
// WebhookFieldEquals Condition
// ============================================================================

it('matches equals operator on webhook field', function () {
    $run = WorkflowRun::factory()->create([
        'context' => [
            'payload' => ['source' => 'facebook'],
        ],
    ]);

    $condition = new WebhookFieldEquals;

    expect($condition->evaluate($run, [
        'field_path' => 'source',
        'operator' => '=',
        'value' => 'facebook',
    ]))->toBeTrue();

    expect($condition->evaluate($run, [
        'field_path' => 'source',
        'operator' => '=',
        'value' => 'google',
    ]))->toBeFalse();
});

it('matches not-equals operator on webhook field', function () {
    $run = WorkflowRun::factory()->create([
        'context' => [
            'payload' => ['source' => 'facebook'],
        ],
    ]);

    $condition = new WebhookFieldEquals;

    expect($condition->evaluate($run, [
        'field_path' => 'source',
        'operator' => '!=',
        'value' => 'google',
    ]))->toBeTrue();
});

it('matches contains operator on webhook field', function () {
    $run = WorkflowRun::factory()->create([
        'context' => [
            'payload' => ['message' => 'I need interior painting'],
        ],
    ]);

    $condition = new WebhookFieldEquals;

    expect($condition->evaluate($run, [
        'field_path' => 'message',
        'operator' => 'contains',
        'value' => 'interior',
    ]))->toBeTrue();
});

it('matches starts_with operator on webhook field', function () {
    $run = WorkflowRun::factory()->create([
        'context' => [
            'payload' => ['source' => 'facebook_ads'],
        ],
    ]);

    $condition = new WebhookFieldEquals;

    expect($condition->evaluate($run, [
        'field_path' => 'source',
        'operator' => 'starts_with',
        'value' => 'facebook',
    ]))->toBeTrue();
});

it('matches is_set and is_empty operators on webhook field', function () {
    $run = WorkflowRun::factory()->create([
        'context' => [
            'payload' => ['email' => 'test@example.com', 'phone' => ''],
        ],
    ]);

    $condition = new WebhookFieldEquals;

    expect($condition->evaluate($run, [
        'field_path' => 'email',
        'operator' => 'is_set',
    ]))->toBeTrue();

    expect($condition->evaluate($run, [
        'field_path' => 'phone',
        'operator' => 'is_empty',
    ]))->toBeTrue();

    expect($condition->evaluate($run, [
        'field_path' => 'nonexistent',
        'operator' => 'is_empty',
    ]))->toBeTrue();

    expect($condition->evaluate($run, [
        'field_path' => 'nonexistent',
        'operator' => 'is_set',
    ]))->toBeFalse();
});

it('matches nested webhook fields with dot notation', function () {
    $run = WorkflowRun::factory()->create([
        'context' => [
            'payload' => [
                'lead' => ['type' => 'residential'],
            ],
        ],
    ]);

    $condition = new WebhookFieldEquals;

    expect($condition->evaluate($run, [
        'field_path' => 'lead.type',
        'operator' => '=',
        'value' => 'residential',
    ]))->toBeTrue();
});

it('returns false when payload is missing for WebhookFieldEquals', function () {
    $run = WorkflowRun::factory()->create([
        'context' => [],
    ]);

    $condition = new WebhookFieldEquals;

    expect($condition->evaluate($run, [
        'field_path' => 'source',
        'operator' => '=',
        'value' => 'facebook',
    ]))->toBeFalse();
});

it('has correct metadata for WebhookFieldEquals', function () {
    expect(WebhookFieldEquals::getName())->toBe('Webhook Field Equals')
        ->and(WebhookFieldEquals::getCategory())->toBe('Webhook Conditions')
        ->and(WebhookFieldEquals::getConfigSchema())->toHaveKeys(['field_path', 'operator', 'value']);
});

// ============================================================================
// LeadSourceIs Condition
// ============================================================================

it('matches when deal lead_source is in slug list', function () {
    $deal = Deal::factory()->create(['lead_source' => 'facebook']);

    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
    ]);

    $condition = new LeadSourceIs;

    expect($condition->evaluate($run, [
        'lead_source_slugs' => 'facebook,google_ads',
    ]))->toBeTrue();
});

it('returns false when deal lead_source not in slug list', function () {
    $deal = Deal::factory()->create(['lead_source' => 'referral']);

    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
    ]);

    $condition = new LeadSourceIs;

    expect($condition->evaluate($run, [
        'lead_source_slugs' => 'facebook,google_ads',
    ]))->toBeFalse();
});

it('resolves deal from context for LeadSourceIs', function () {
    $deal = Deal::factory()->create(['lead_source' => 'facebook']);

    $run = WorkflowRun::factory()->create([
        'context' => ['deal_id' => $deal->id],
    ]);

    $condition = new LeadSourceIs;

    expect($condition->evaluate($run, [
        'lead_source_slugs' => 'facebook',
    ]))->toBeTrue();
});

it('has correct metadata for LeadSourceIs', function () {
    expect(LeadSourceIs::getName())->toBe('Lead Source Is')
        ->and(LeadSourceIs::getCategory())->toBe('Deal Conditions')
        ->and(LeadSourceIs::getConfigSchema())->toHaveKeys(['lead_source_slugs']);
});

// ============================================================================
// End-to-End: Webhook -> CreateCustomer -> CreateDeal chain
// ============================================================================

it('chains webhook through CreateCustomer and CreateDeal', function () {
    $user = User::factory()->internal()->create();
    $webhook = AutomationWebhook::factory()->create();

    $run = WorkflowRun::factory()->create([
        'subject_type' => AutomationWebhook::class,
        'subject_id' => $webhook->id,
        'triggered_by' => $user->id,
        'context' => [
            'payload' => [
                'first_name' => 'Alice',
                'last_name' => 'Johnson',
                'email' => 'alice@example.com',
            ],
        ],
    ]);

    $registry = app(Registry::class);

    // Step 1: CreateCustomer with webhook values
    $createCustomer = new CreateCustomer;
    $customerResult = $createCustomer->execute($run, [
        'first_name' => '{{webhook.first_name}}',
        'last_name' => '{{webhook.last_name}}',
        'email' => '{{webhook.email}}',
    ]);

    // Config values need to be resolved through the engine, but action does it internally
    // Let's check the run was updated
    $run->refresh();
    expect($run->subject_type)->toBe(Customer::class);

    // Step 2: CreateDeal using the now-customer subject
    $createDeal = new CreateDeal;
    $dealResult = $createDeal->execute($run, [
        'title' => 'New Lead',
        'stage' => 'lead',
        'lead_source' => 'facebook',
    ]);

    $deal = Deal::find($dealResult['deal_id']);
    expect($deal->customer_id)->toBe($customerResult['customer_id'])
        ->and($deal->stage)->toBe('lead');
});
