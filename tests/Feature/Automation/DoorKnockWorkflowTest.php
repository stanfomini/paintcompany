<?php

use App\Automation\Actions\CreateEstimate;
use App\Automation\Conditions\FieldValue;
use App\Automation\DynamicValues\DoorKnockValues;
use App\Automation\Triggers\DoorKnockRecorded as DoorKnockRecordedTrigger;
use App\Events\Automation\DoorKnockRecorded;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\DoorKnock;
use App\Models\Estimate;
use App\Models\Property;
use App\Models\User;
use App\Models\WorkflowRun;
use App\Services\DoorKnockService;
use Illuminate\Support\Facades\Event;

// --- DoorKnockService dispatches event ---

it('dispatches DoorKnockRecorded when knock has contact info', function () {
    Event::fake([DoorKnockRecorded::class]);

    $user = User::factory()->internal()->create();
    $service = app(DoorKnockService::class);

    $service->recordKnock($user, [
        'address' => '123 Main St',
        'city' => 'Portland',
        'state' => 'OR',
        'zip' => '97201',
        'lat' => 45.5,
        'lng' => -122.6,
        'status' => 'interested',
        'customer_name' => 'John Doe',
        'phone' => '555-1234',
        'email' => 'john@example.com',
    ]);

    Event::assertDispatched(DoorKnockRecorded::class);
});

it('does not dispatch DoorKnockRecorded when knock has no contact info', function () {
    Event::fake([DoorKnockRecorded::class]);

    $user = User::factory()->internal()->create();
    $service = app(DoorKnockService::class);

    $service->recordKnock($user, [
        'address' => '456 Elm St',
        'city' => 'Portland',
        'state' => 'OR',
        'zip' => '97201',
        'lat' => 45.5,
        'lng' => -122.6,
        'status' => 'not_home',
        'customer_name' => null,
        'phone' => null,
        'email' => null,
    ]);

    Event::assertNotDispatched(DoorKnockRecorded::class);
});

// --- DoorKnockRecorded Trigger ---

it('trigger matches doorknock.recorded event', function () {
    $trigger = new DoorKnockRecordedTrigger;
    $knock = DoorKnock::factory()->interested()->make();

    expect($trigger->matches('doorknock.recorded', $knock, ['status' => 'interested'], []))
        ->toBeTrue();
});

it('trigger does not match wrong event', function () {
    $trigger = new DoorKnockRecordedTrigger;
    $knock = DoorKnock::factory()->make();

    expect($trigger->matches('deal.stage_changed', $knock, [], []))
        ->toBeFalse();
});

it('trigger respects status_filter config', function () {
    $trigger = new DoorKnockRecordedTrigger;
    $knock = DoorKnock::factory()->interested()->make();

    expect($trigger->matches('doorknock.recorded', $knock, ['status' => 'interested'], ['status_filter' => 'interested']))
        ->toBeTrue();

    expect($trigger->matches('doorknock.recorded', $knock, ['status' => 'interested'], ['status_filter' => 'not_home']))
        ->toBeFalse();
});

it('trigger matches all statuses when status_filter is empty', function () {
    $trigger = new DoorKnockRecordedTrigger;
    $knock = DoorKnock::factory()->make();

    expect($trigger->matches('doorknock.recorded', $knock, ['status' => 'sold'], ['status_filter' => '']))
        ->toBeTrue();

    expect($trigger->matches('doorknock.recorded', $knock, ['status' => 'sold'], []))
        ->toBeTrue();
});

it('has correct metadata for DoorKnockRecorded trigger', function () {
    expect(DoorKnockRecordedTrigger::getName())->toBe('Door Knock Recorded')
        ->and(DoorKnockRecordedTrigger::getCategory())->toBe('Sales')
        ->and(DoorKnockRecordedTrigger::getDescription())->toBeString()
        ->and(DoorKnockRecordedTrigger::getConfigSchema())->toHaveKey('status_filter');
});

// --- DoorKnockValues Dynamic Value Resolver ---

it('resolves all keys from DoorKnock subject', function () {
    $knock = DoorKnock::factory()->create([
        'customer_name' => 'Jane Smith',
        'email' => 'jane@example.com',
        'phone' => '555-9876',
        'address' => '789 Oak Ave',
        'city' => 'Seattle',
        'state' => 'WA',
        'zip' => '98101',
        'status' => 'interested',
        'notes' => 'Very interested in exterior paint',
    ]);

    $run = WorkflowRun::factory()->create([
        'subject_type' => DoorKnock::class,
        'subject_id' => $knock->id,
        'context' => ['doorknock_id' => $knock->id],
    ]);

    $resolver = new DoorKnockValues;

    expect($resolver->resolve('customer_name', $run))->toBe('Jane Smith')
        ->and($resolver->resolve('email', $run))->toBe('jane@example.com')
        ->and($resolver->resolve('phone', $run))->toBe('555-9876')
        ->and($resolver->resolve('address', $run))->toBe('789 Oak Ave')
        ->and($resolver->resolve('city', $run))->toBe('Seattle')
        ->and($resolver->resolve('state', $run))->toBe('WA')
        ->and($resolver->resolve('zip', $run))->toBe('98101')
        ->and($resolver->resolve('status', $run))->toBe('interested')
        ->and($resolver->resolve('notes', $run))->toBe('Very interested in exterior paint');
});

it('resolves from context doorknock_id after subject swap', function () {
    $knock = DoorKnock::factory()->create([
        'customer_name' => 'Bob Jones',
        'email' => 'bob@example.com',
    ]);

    // After CreateCustomer, the subject is swapped to Customer
    $customer = Customer::factory()->create();

    $run = WorkflowRun::factory()->create([
        'subject_type' => Customer::class,
        'subject_id' => $customer->id,
        'context' => ['doorknock_id' => $knock->id],
    ]);

    $resolver = new DoorKnockValues;

    expect($resolver->resolve('customer_name', $run))->toBe('Bob Jones')
        ->and($resolver->resolve('email', $run))->toBe('bob@example.com');
});

it('extracts first and last name from full name', function () {
    $knock = DoorKnock::factory()->create([
        'customer_name' => 'Mary Jane Watson',
    ]);

    $run = WorkflowRun::factory()->create([
        'subject_type' => DoorKnock::class,
        'subject_id' => $knock->id,
    ]);

    $resolver = new DoorKnockValues;

    expect($resolver->resolve('first_name', $run))->toBe('Mary')
        ->and($resolver->resolve('last_name', $run))->toBe('Jane Watson');
});

it('handles single name gracefully', function () {
    $knock = DoorKnock::factory()->create([
        'customer_name' => 'Madonna',
    ]);

    $run = WorkflowRun::factory()->create([
        'subject_type' => DoorKnock::class,
        'subject_id' => $knock->id,
    ]);

    $resolver = new DoorKnockValues;

    expect($resolver->resolve('first_name', $run))->toBe('Madonna')
        ->and($resolver->resolve('last_name', $run))->toBe('');
});

// --- CreateEstimate Action ---

it('creates a draft estimate linked to deal', function () {
    $user = User::factory()->internal()->create();
    $deal = Deal::factory()->create();

    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
        'context' => ['deal_id' => $deal->id],
        'triggered_by' => $user->id,
    ]);

    $action = new CreateEstimate;
    $result = $action->execute($run, [
        'title' => 'Test Estimate',
        'project_type' => 'exterior',
        'estimator_mode' => 'simple',
    ]);

    expect($result)->toHaveKey('estimate_id');

    $estimate = Estimate::find($result['estimate_id']);
    expect($estimate->deal_id)->toBe($deal->id)
        ->and($estimate->title)->toBe('Test Estimate')
        ->and($estimate->status)->toBe('draft')
        ->and($estimate->project_type)->toBe('exterior')
        ->and($estimate->estimator_mode)->toBe('simple')
        ->and($estimate->created_by)->toBe($user->id);
});

it('CreateEstimate stores estimate_id in run context', function () {
    $user = User::factory()->internal()->create();
    $deal = Deal::factory()->create();

    $run = WorkflowRun::factory()->create([
        'subject_type' => Deal::class,
        'subject_id' => $deal->id,
        'context' => ['deal_id' => $deal->id],
        'triggered_by' => $user->id,
    ]);

    $action = new CreateEstimate;
    $result = $action->execute($run, ['title' => 'Pipeline Estimate']);

    $run->refresh();
    expect($run->context['estimate_id'])->toBe($result['estimate_id']);
});

it('has correct metadata for CreateEstimate', function () {
    expect(CreateEstimate::getName())->toBe('Create Estimate')
        ->and(CreateEstimate::getCategory())->toBe('Record Management')
        ->and(CreateEstimate::getDescription())->toBeString()
        ->and(CreateEstimate::getConfigSchema())->toHaveKeys(['title', 'project_type', 'estimator_mode']);
});

// --- FieldValue is_empty / is_not_empty ---

it('FieldValue is_empty returns true for null value', function () {
    $knock = DoorKnock::factory()->create(['email' => null]);

    $run = WorkflowRun::factory()->create([
        'subject_type' => DoorKnock::class,
        'subject_id' => $knock->id,
    ]);

    $condition = new FieldValue;

    expect($condition->evaluate($run, ['field' => 'email', 'operator' => 'is_empty']))->toBeTrue();
});

it('FieldValue is_empty returns true for empty string', function () {
    $knock = DoorKnock::factory()->create(['email' => '']);

    $run = WorkflowRun::factory()->create([
        'subject_type' => DoorKnock::class,
        'subject_id' => $knock->id,
    ]);

    $condition = new FieldValue;

    expect($condition->evaluate($run, ['field' => 'email', 'operator' => 'is_empty']))->toBeTrue();
});

it('FieldValue is_empty returns false when field has value', function () {
    $knock = DoorKnock::factory()->create(['email' => 'test@example.com']);

    $run = WorkflowRun::factory()->create([
        'subject_type' => DoorKnock::class,
        'subject_id' => $knock->id,
    ]);

    $condition = new FieldValue;

    expect($condition->evaluate($run, ['field' => 'email', 'operator' => 'is_empty']))->toBeFalse();
});

it('FieldValue is_not_empty returns true when field has value', function () {
    $knock = DoorKnock::factory()->create(['email' => 'test@example.com']);

    $run = WorkflowRun::factory()->create([
        'subject_type' => DoorKnock::class,
        'subject_id' => $knock->id,
    ]);

    $condition = new FieldValue;

    expect($condition->evaluate($run, ['field' => 'email', 'operator' => 'is_not_empty']))->toBeTrue();
});

it('FieldValue is_not_empty returns false for null', function () {
    $knock = DoorKnock::factory()->create(['email' => null]);

    $run = WorkflowRun::factory()->create([
        'subject_type' => DoorKnock::class,
        'subject_id' => $knock->id,
    ]);

    $condition = new FieldValue;

    expect($condition->evaluate($run, ['field' => 'email', 'operator' => 'is_not_empty']))->toBeFalse();
});

// --- CreateDeal auto-links property_id from context ---

it('CreateDeal sets property_id from workflow context', function () {
    $user = User::factory()->internal()->create();
    $customer = Customer::factory()->create();
    $property = Property::factory()->create(['customer_id' => $customer->id]);

    $run = WorkflowRun::factory()->create([
        'subject_type' => Customer::class,
        'subject_id' => $customer->id,
        'context' => [
            'customer_id' => $customer->id,
            'property_id' => $property->id,
        ],
        'triggered_by' => $user->id,
    ]);

    $action = new \App\Automation\Actions\CreateDeal;
    $result = $action->execute($run, [
        'title' => 'Property Lead',
        'stage' => 'lead',
    ]);

    $deal = Deal::find($result['deal_id']);
    expect($deal->property_id)->toBe($property->id)
        ->and($deal->customer_id)->toBe($customer->id);
});

// --- CreateDeal links DoorKnock ---

it('CreateDeal links DoorKnock record when doorknock_id in context', function () {
    $user = User::factory()->internal()->create();
    $knock = DoorKnock::factory()->create();
    $customer = Customer::factory()->create();
    $property = Property::factory()->create(['customer_id' => $customer->id]);

    $run = WorkflowRun::factory()->create([
        'subject_type' => Customer::class,
        'subject_id' => $customer->id,
        'context' => [
            'doorknock_id' => $knock->id,
            'customer_id' => $customer->id,
            'property_id' => $property->id,
        ],
        'triggered_by' => $user->id,
    ]);

    $action = new \App\Automation\Actions\CreateDeal;
    $result = $action->execute($run, [
        'title' => 'Door Knock Lead',
        'stage' => 'lead',
    ]);

    $knock->refresh();
    expect($knock->deal_id)->toBe($result['deal_id'])
        ->and($knock->customer_id)->toBe($customer->id)
        ->and($knock->property_id)->toBe($property->id);
});

// --- DoorKnockRecorded Event ---

it('DoorKnockRecorded event has correct properties', function () {
    $knock = DoorKnock::factory()->create([
        'status' => 'interested',
        'email' => 'test@example.com',
        'phone' => '555-1234',
        'customer_name' => 'John Doe',
    ]);

    $event = new DoorKnockRecorded($knock);

    expect($event->getEventName())->toBe('doorknock.recorded')
        ->and($event->getSubject())->toBe($knock)
        ->and($event->getContext())->toMatchArray([
            'doorknock_id' => $knock->id,
            'status' => 'interested',
            'has_email' => true,
            'has_phone' => true,
            'has_customer_name' => true,
        ]);
});

it('DoorKnockRecorded event reflects missing contact info', function () {
    $knock = DoorKnock::factory()->create([
        'status' => 'not_home',
        'email' => null,
        'phone' => null,
        'customer_name' => 'Just a Name',
    ]);

    $event = new DoorKnockRecorded($knock);

    expect($event->getContext())->toMatchArray([
        'has_email' => false,
        'has_phone' => false,
        'has_customer_name' => true,
    ]);
});
