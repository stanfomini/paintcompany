<?php

use App\Livewire\Activities\QuickAdd;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\User;
use Livewire\Livewire;

describe('QuickAdd component', function () {
    it('renders the quick add component', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test(QuickAdd::class, [
                'subjectType' => Deal::class,
                'subjectId' => $deal->id,
            ])
            ->assertStatus(200);
    });

    it('renders with subject type and id', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test(QuickAdd::class, [
                'subjectType' => Deal::class,
                'subjectId' => $deal->id,
            ])
            ->assertStatus(200)
            ->assertSet('subjectType', Deal::class)
            ->assertSet('subjectId', $deal->id);
    });

    it('creates activity with deal subject', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test(QuickAdd::class, [
                'subjectType' => Deal::class,
                'subjectId' => $deal->id,
            ])
            ->set('title', 'Deal Activity')
            ->set('type', 'call')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('activity-created');

        $this->assertDatabaseHas('activities', [
            'user_id' => $user->id,
            'title' => 'Deal Activity',
            'type' => 'call',
            'subject_type' => Deal::class,
            'subject_id' => $deal->id,
        ]);
    });

    it('creates activity with customer subject', function () {
        $user = User::factory()->internal()->create();
        $customer = Customer::factory()->create();

        Livewire::actingAs($user)
            ->test(QuickAdd::class, [
                'subjectType' => Customer::class,
                'subjectId' => $customer->id,
            ])
            ->set('title', 'Customer Activity')
            ->set('type', 'meeting')
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('activity-created');

        $this->assertDatabaseHas('activities', [
            'user_id' => $user->id,
            'title' => 'Customer Activity',
            'type' => 'meeting',
            'subject_type' => Customer::class,
            'subject_id' => $customer->id,
        ]);
    });

    it('validates title is required', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test(QuickAdd::class, [
                'subjectType' => Deal::class,
                'subjectId' => $deal->id,
            ])
            ->set('title', '')
            ->call('save')
            ->assertHasErrors(['title']);
    });

    it('validates title max length', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test(QuickAdd::class, [
                'subjectType' => Deal::class,
                'subjectId' => $deal->id,
            ])
            ->set('title', str_repeat('a', 256))
            ->call('save')
            ->assertHasErrors(['title']);
    });

    it('validates type is required', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test(QuickAdd::class, [
                'subjectType' => Deal::class,
                'subjectId' => $deal->id,
            ])
            ->set('title', 'Test')
            ->set('type', '')
            ->call('save')
            ->assertHasErrors(['type']);
    });

    it('validates type is valid', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test(QuickAdd::class, [
                'subjectType' => Deal::class,
                'subjectId' => $deal->id,
            ])
            ->set('title', 'Test')
            ->set('type', 'invalid_type')
            ->call('save')
            ->assertHasErrors(['type']);
    });

    it('validates due date is a valid date', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test(QuickAdd::class, [
                'subjectType' => Deal::class,
                'subjectId' => $deal->id,
            ])
            ->set('title', 'Test')
            ->set('type', 'task')
            ->set('dueAt', 'not-a-date')
            ->call('save')
            ->assertHasErrors(['dueAt']);
    });

    it('validates description max length', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test(QuickAdd::class, [
                'subjectType' => Deal::class,
                'subjectId' => $deal->id,
            ])
            ->set('title', 'Test')
            ->set('type', 'task')
            ->set('description', str_repeat('a', 10001))
            ->call('save')
            ->assertHasErrors(['description']);
    });

    it('allows optional due date', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test(QuickAdd::class, [
                'subjectType' => Deal::class,
                'subjectId' => $deal->id,
            ])
            ->set('title', 'No Due Date Task')
            ->set('type', 'note')
            ->set('dueAt', null)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('activities', [
            'title' => 'No Due Date Task',
            'due_at' => null,
        ]);
    });

    it('allows optional description', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test(QuickAdd::class, [
                'subjectType' => Deal::class,
                'subjectId' => $deal->id,
            ])
            ->set('title', 'No Description Task')
            ->set('type', 'task')
            ->set('description', '')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('activities', [
            'title' => 'No Description Task',
            'description' => null,
        ]);
    });

    it('resets form after saving', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test(QuickAdd::class, [
                'subjectType' => Deal::class,
                'subjectId' => $deal->id,
            ])
            ->set('title', 'Test Activity')
            ->set('type', 'email')
            ->set('dueAt', now()->format('Y-m-d'))
            ->set('description', 'Test description')
            ->call('save')
            ->assertSet('title', '')
            ->assertSet('type', 'task')
            ->assertSet('dueAt', null)
            ->assertSet('description', '');
    });

    it('dispatches activity-created event', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test(QuickAdd::class, [
                'subjectType' => Deal::class,
                'subjectId' => $deal->id,
            ])
            ->set('title', 'Event Test')
            ->set('type', 'task')
            ->call('save')
            ->assertDispatched('activity-created');
    });

    it('has correct default values', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test(QuickAdd::class, [
                'subjectType' => Deal::class,
                'subjectId' => $deal->id,
            ])
            ->assertSet('title', '')
            ->assertSet('type', 'task')
            ->assertSet('dueAt', null)
            ->assertSet('description', '')
            ->assertSet('subjectType', Deal::class)
            ->assertSet('subjectId', $deal->id);
    });

    it('accepts all valid activity types', function ($type) {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test(QuickAdd::class, [
                'subjectType' => Deal::class,
                'subjectId' => $deal->id,
            ])
            ->set('title', "Test {$type}")
            ->set('type', $type)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('activities', [
            'title' => "Test {$type}",
            'type' => $type,
        ]);
    })->with(['task', 'note', 'call', 'email', 'meeting', 'other']);

    it('creates activity with is_completed set to false', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test(QuickAdd::class, [
                'subjectType' => Deal::class,
                'subjectId' => $deal->id,
            ])
            ->set('title', 'New Activity')
            ->set('type', 'task')
            ->call('save');

        $this->assertDatabaseHas('activities', [
            'title' => 'New Activity',
            'is_completed' => false,
        ]);
    });
});
