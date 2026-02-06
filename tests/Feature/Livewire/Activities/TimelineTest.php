<?php

use App\Models\Activity;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\User;
use Livewire\Livewire;

describe('Timeline component', function () {
    it('renders the timeline component for a deal', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test('activities.timeline', [
                'subjectType' => Deal::class,
                'subjectId' => $deal->id,
            ])
            ->assertStatus(200);
    });

    it('renders the timeline component for a customer', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();

        Livewire::actingAs($user)
            ->test('activities.timeline', [
                'subjectType' => Customer::class,
                'subjectId' => $customer->id,
            ])
            ->assertStatus(200);
    });

    it('displays activities for the subject', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $activity = Activity::factory()->forDeal($deal)->byUser($user)->create([
            'title' => 'Deal Activity',
        ]);

        Livewire::actingAs($user)
            ->test('activities.timeline', [
                'subjectType' => Deal::class,
                'subjectId' => $deal->id,
            ])
            ->assertSee('Deal Activity');
    });

    it('does not display activities from other subjects', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal1 = Deal::factory()->create();
        $deal2 = Deal::factory()->create();

        Activity::factory()->forDeal($deal1)->byUser($user)->create([
            'title' => 'Deal 1 Activity',
        ]);
        Activity::factory()->forDeal($deal2)->byUser($user)->create([
            'title' => 'Deal 2 Activity',
        ]);

        Livewire::actingAs($user)
            ->test('activities.timeline', [
                'subjectType' => Deal::class,
                'subjectId' => $deal1->id,
            ])
            ->assertSee('Deal 1 Activity')
            ->assertDontSee('Deal 2 Activity');
    });

    it('displays all activity types', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        Activity::factory()->task()->forDeal($deal)->byUser($user)->create(['title' => 'Task Item']);
        Activity::factory()->note()->forDeal($deal)->byUser($user)->create(['title' => 'Note Item']);
        Activity::factory()->call()->forDeal($deal)->byUser($user)->create(['title' => 'Call Item']);
        Activity::factory()->email()->forDeal($deal)->byUser($user)->create(['title' => 'Email Item']);
        Activity::factory()->meeting()->forDeal($deal)->byUser($user)->create(['title' => 'Meeting Item']);

        Livewire::actingAs($user)
            ->test('activities.timeline', [
                'subjectType' => Deal::class,
                'subjectId' => $deal->id,
            ])
            ->assertSee('Task Item')
            ->assertSee('Note Item')
            ->assertSee('Call Item')
            ->assertSee('Email Item')
            ->assertSee('Meeting Item');
    });

    it('shows completion status for tasks', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        Activity::factory()->task()->completed()->forDeal($deal)->byUser($user)->create([
            'title' => 'Completed Task',
        ]);

        Livewire::actingAs($user)
            ->test('activities.timeline', [
                'subjectType' => Deal::class,
                'subjectId' => $deal->id,
            ])
            ->assertSee('Completed Task')
            ->assertSee('Completed');
    });

    it('can toggle task completion', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $activity = Activity::factory()->task()->forDeal($deal)->byUser($user)->create([
            'title' => 'Toggle Task',
            'is_completed' => false,
        ]);

        Livewire::actingAs($user)
            ->test('activities.timeline', [
                'subjectType' => Deal::class,
                'subjectId' => $deal->id,
            ])
            ->call('toggleComplete', $activity->id);

        $activity->refresh();
        expect($activity->is_completed)->toBeTrue();
    });

    it('can create activity inline', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test('activities.timeline', [
                'subjectType' => Deal::class,
                'subjectId' => $deal->id,
            ])
            ->set('showForm', true)
            ->set('title', 'New Activity')
            ->set('type', 'note')
            ->set('description', 'Activity description')
            ->call('createActivity')
            ->assertHasNoErrors()
            ->assertDispatched('activity-created')
            ->assertSet('title', '')
            ->assertSet('showForm', false);

        $this->assertDatabaseHas('activities', [
            'user_id' => $user->id,
            'subject_type' => Deal::class,
            'subject_id' => $deal->id,
            'title' => 'New Activity',
            'type' => 'note',
        ]);
    });

    it('validates activity title is required', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test('activities.timeline', [
                'subjectType' => Deal::class,
                'subjectId' => $deal->id,
            ])
            ->set('showForm', true)
            ->set('title', '')
            ->call('createActivity')
            ->assertHasErrors(['title']);
    });

    it('validates activity type is valid', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test('activities.timeline', [
                'subjectType' => Deal::class,
                'subjectId' => $deal->id,
            ])
            ->set('showForm', true)
            ->set('title', 'Test')
            ->set('type', 'invalid_type')
            ->call('createActivity')
            ->assertHasErrors(['type']);
    });

    it('can cancel form', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test('activities.timeline', [
                'subjectType' => Deal::class,
                'subjectId' => $deal->id,
            ])
            ->set('showForm', true)
            ->set('title', 'Test Title')
            ->call('cancelForm')
            ->assertSet('showForm', false)
            ->assertSet('title', '');
    });

    it('displays activities in descending order by creation date', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $olderActivity = Activity::factory()->forDeal($deal)->byUser($user)->create([
            'title' => 'Older Activity',
            'created_at' => now()->subDay(),
        ]);
        $newerActivity = Activity::factory()->forDeal($deal)->byUser($user)->create([
            'title' => 'Newer Activity',
            'created_at' => now(),
        ]);

        $component = Livewire::actingAs($user)
            ->test('activities.timeline', [
                'subjectType' => Deal::class,
                'subjectId' => $deal->id,
            ]);

        $activities = $component->get('activities');
        expect($activities->first()->id)->toBe($newerActivity->id);
        expect($activities->last()->id)->toBe($olderActivity->id);
    });

    it('displays author name', function () {
        $user = User::factory()->internal()->withFullAccess()->create(['name' => 'John Doe']);
        $deal = Deal::factory()->create();

        Activity::factory()->forDeal($deal)->byUser($user)->create([
            'title' => 'Test Activity',
        ]);

        Livewire::actingAs($user)
            ->test('activities.timeline', [
                'subjectType' => Deal::class,
                'subjectId' => $deal->id,
            ])
            ->assertSee('John Doe');
    });

    it('shows no activities message when empty', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test('activities.timeline', [
                'subjectType' => Deal::class,
                'subjectId' => $deal->id,
            ])
            ->assertSee('No activities yet.');
    });

    it('shows overdue badge for overdue tasks', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        Activity::factory()->task()->overdue()->forDeal($deal)->byUser($user)->create([
            'title' => 'Overdue Task',
        ]);

        Livewire::actingAs($user)
            ->test('activities.timeline', [
                'subjectType' => Deal::class,
                'subjectId' => $deal->id,
            ])
            ->assertSee('Overdue Task')
            ->assertSee('Overdue');
    });

    it('displays type badges', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        Activity::factory()->call()->forDeal($deal)->byUser($user)->create([
            'title' => 'Call Activity',
        ]);

        Livewire::actingAs($user)
            ->test('activities.timeline', [
                'subjectType' => Deal::class,
                'subjectId' => $deal->id,
            ])
            ->assertSee('Call');
    });

    it('displays activity description', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        Activity::factory()->forDeal($deal)->byUser($user)->create([
            'title' => 'Test Activity',
            'description' => 'This is the activity description.',
        ]);

        Livewire::actingAs($user)
            ->test('activities.timeline', [
                'subjectType' => Deal::class,
                'subjectId' => $deal->id,
            ])
            ->assertSee('This is the activity description.');
    });

    it('refreshes on activity-created event', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $component = Livewire::actingAs($user)
            ->test('activities.timeline', [
                'subjectType' => Deal::class,
                'subjectId' => $deal->id,
            ]);

        // Create an activity after the component is mounted
        Activity::factory()->forDeal($deal)->byUser($user)->create([
            'title' => 'New Activity After Mount',
        ]);

        // Dispatch the event
        $component->dispatch('activity-created')
            ->assertSee('New Activity After Mount');
    });
});
