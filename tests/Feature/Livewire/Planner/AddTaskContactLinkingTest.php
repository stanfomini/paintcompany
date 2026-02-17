<?php

use App\Models\Activity;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->internal()->withFullAccess()->create();
});

it('creates a task linked to a user contact', function () {
    $agent = User::factory()->agent()->create();

    Livewire::actingAs($this->user)->test('planner.my-day')
        ->set('taskTitle', 'Follow up with agent')
        ->set('taskType', 'call')
        ->set('taskPriority', 'high')
        ->set('taskSubjectType', 'App\Models\User')
        ->set('taskSubjectId', $agent->id)
        ->call('saveTask')
        ->assertHasNoErrors();

    $activity = Activity::where('title', 'Follow up with agent')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->subject_type)->toBe(User::class)
        ->and($activity->subject_id)->toBe($agent->id)
        ->and($activity->type)->toBe('call')
        ->and($activity->priority)->toBe('high');
});

it('creates a task linked to a customer contact', function () {
    $customer = Customer::factory()->createdBy($this->user)->create();

    Livewire::actingAs($this->user)->test('planner.my-day')
        ->set('taskTitle', 'Send estimate to customer')
        ->set('taskType', 'task')
        ->set('taskPriority', 'normal')
        ->set('taskSubjectType', 'App\Models\Customer')
        ->set('taskSubjectId', $customer->id)
        ->call('saveTask')
        ->assertHasNoErrors();

    $activity = Activity::where('title', 'Send estimate to customer')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->subject_type)->toBe(Customer::class)
        ->and($activity->subject_id)->toBe($customer->id)
        ->and($activity->type)->toBe('task')
        ->and($activity->priority)->toBe('normal');
});

it('creates a task without a contact when none selected', function () {
    Livewire::actingAs($this->user)->test('planner.my-day')
        ->set('taskTitle', 'General office task')
        ->set('taskType', 'task')
        ->set('taskPriority', 'low')
        ->call('saveTask')
        ->assertHasNoErrors();

    $activity = Activity::where('title', 'General office task')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->subject_type)->toBeNull()
        ->and($activity->subject_id)->toBeNull();
});

it('resets subject fields when closing add task modal', function () {
    $agent = User::factory()->agent()->create();

    Livewire::actingAs($this->user)->test('planner.my-day')
        ->set('taskSubjectType', 'App\Models\User')
        ->set('taskSubjectId', $agent->id)
        ->assertSet('taskSubjectType', 'App\Models\User')
        ->assertSet('taskSubjectId', $agent->id)
        ->call('closeAddTask')
        ->assertSet('taskSubjectType', null)
        ->assertSet('taskSubjectId', null);
});
