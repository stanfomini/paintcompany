<?php

use App\Models\Activity;
use App\Models\User;
use App\Notifications\TaskAssigned;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Planner access', function () {
    it('allows internal user to view planner', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $response = $this->actingAs($user)->get(route('planner.index'));

        $response->assertOk();
        $response->assertSeeLivewire('planner.my-day');
    });

    it('redirects from old activities route to planner', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $response = $this->actingAs($user)->get('/activities');

        $response->assertRedirect('/planner');
    });
});

describe('Planner views', function () {
    it('shows tasks assigned to user in my day view', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();

        // Task assigned to user, due today
        $myTask = Activity::factory()->create([
            'user_id' => $otherUser->id,
            'assigned_to' => $user->id,
            'title' => 'My Task Today',
            'due_at' => today(),
            'is_completed' => false,
        ]);

        // Task assigned to someone else
        Activity::factory()->create([
            'user_id' => $user->id,
            'assigned_to' => $otherUser->id,
            'title' => 'Other Task',
            'due_at' => today(),
        ]);

        Livewire::actingAs($user)
            ->test('planner.my-day')
            ->assertSet('view', 'my_day')
            ->assertSee('My Task Today')
            ->assertDontSee('Other Task');
    });

    it('shows overdue tasks in my day view', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Activity::factory()->create([
            'user_id' => $user->id,
            'assigned_to' => $user->id,
            'title' => 'Overdue Task',
            'due_at' => today()->subDays(2),
            'is_completed' => false,
        ]);

        Livewire::actingAs($user)
            ->test('planner.my-day')
            ->assertSee('Overdue Task')
            ->assertSee('Overdue');
    });

    it('shows tasks in my week view', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Activity::factory()->create([
            'user_id' => $user->id,
            'assigned_to' => $user->id,
            'title' => 'This Week Task',
            'due_at' => now()->startOfWeek()->addDays(2),
        ]);

        Activity::factory()->create([
            'user_id' => $user->id,
            'assigned_to' => $user->id,
            'title' => 'Next Week Task',
            'due_at' => now()->addWeeks(1),
        ]);

        Livewire::actingAs($user)
            ->test('planner.my-day')
            ->set('view', 'my_week')
            ->assertSee('This Week Task')
            ->assertDontSee('Next Week Task');
    });

    it('shows all team tasks in team view', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();

        Activity::factory()->create([
            'user_id' => $user->id,
            'assigned_to' => $user->id,
            'title' => 'My Team Task',
        ]);

        Activity::factory()->create([
            'user_id' => $otherUser->id,
            'assigned_to' => $otherUser->id,
            'title' => 'Other Team Task',
        ]);

        Livewire::actingAs($user)
            ->test('planner.my-day')
            ->set('view', 'team')
            ->assertSee('My Team Task')
            ->assertSee('Other Team Task');
    });
});

describe('Planner task creation', function () {
    it('can create task assigned to self', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('planner.my-day')
            ->call('openAddTask')
            ->assertSet('showAddTask', true)
            ->set('taskTitle', 'New Personal Task')
            ->set('taskType', 'task')
            ->set('taskDueAt', today()->format('Y-m-d'))
            ->call('saveTask')
            ->assertHasNoErrors()
            ->assertDispatched('activity-created');

        $this->assertDatabaseHas('activities', [
            'title' => 'New Personal Task',
            'user_id' => $user->id,
            'assigned_to' => $user->id,
            'type' => 'task',
        ]);
    });

    it('can create task assigned to another user', function () {
        Notification::fake();

        $user = User::factory()->internal()->withFullAccess()->create();
        $assignee = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('planner.my-day')
            ->call('openAddTask')
            ->set('taskTitle', 'Delegated Task')
            ->set('taskAssignedTo', $assignee->id)
            ->call('saveTask')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('activities', [
            'title' => 'Delegated Task',
            'user_id' => $user->id,
            'assigned_to' => $assignee->id,
        ]);

        Notification::assertSentTo($assignee, TaskAssigned::class);
    });

    it('does not send notification when assigning to self', function () {
        Notification::fake();

        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('planner.my-day')
            ->call('openAddTask')
            ->set('taskTitle', 'Self Task')
            ->set('taskAssignedTo', $user->id)
            ->call('saveTask');

        Notification::assertNotSentTo($user, TaskAssigned::class);
    });
});

describe('Planner task completion', function () {
    it('can toggle task completion', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $activity = Activity::factory()->create([
            'user_id' => $user->id,
            'assigned_to' => $user->id,
            'type' => 'task',
            'is_completed' => false,
        ]);

        Livewire::actingAs($user)
            ->test('planner.my-day')
            ->call('toggleComplete', $activity->id);

        $activity->refresh();
        expect($activity->is_completed)->toBeTrue();
        expect($activity->completed_at)->not->toBeNull();
    });

    it('cannot toggle tasks not assigned to or created by user', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();

        $activity = Activity::factory()->create([
            'user_id' => $otherUser->id,
            'assigned_to' => $otherUser->id,
            'is_completed' => false,
        ]);

        // Expect 404 because the query filters by user
        Livewire::actingAs($user)
            ->test('planner.my-day')
            ->call('toggleComplete', $activity->id);

        $activity->refresh();
        expect($activity->is_completed)->toBeFalse();
    })->throws(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

describe('Planner filters', function () {
    it('can filter by type', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Activity::factory()->create([
            'user_id' => $user->id,
            'assigned_to' => $user->id,
            'type' => 'task',
            'title' => 'Task Activity',
        ]);

        Activity::factory()->create([
            'user_id' => $user->id,
            'assigned_to' => $user->id,
            'type' => 'call',
            'title' => 'Call Activity',
        ]);

        Livewire::actingAs($user)
            ->test('planner.my-day')
            ->set('view', 'all_mine')
            ->set('typeFilter', 'task')
            ->assertSee('Task Activity')
            ->assertDontSee('Call Activity');
    });

    it('can filter by status', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Activity::factory()->create([
            'user_id' => $user->id,
            'assigned_to' => $user->id,
            'title' => 'Complete Task',
            'is_completed' => true,
        ]);

        Activity::factory()->create([
            'user_id' => $user->id,
            'assigned_to' => $user->id,
            'title' => 'Incomplete Task',
            'is_completed' => false,
        ]);

        Livewire::actingAs($user)
            ->test('planner.my-day')
            ->set('view', 'all_mine')
            ->set('statusFilter', 'incomplete')
            ->assertSee('Incomplete Task')
            ->assertDontSee('Complete Task');
    });
});

describe('Activity model assigned_to', function () {
    it('has assignee relationship', function () {
        $assignee = User::factory()->internal()->withFullAccess()->create();

        $activity = Activity::factory()->create([
            'assigned_to' => $assignee->id,
        ]);

        expect($activity->assignee)->not->toBeNull();
        expect($activity->assignee->id)->toBe($assignee->id);
    });

    it('scopes by assigned_to', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();

        Activity::factory()->create(['assigned_to' => $user->id]);
        Activity::factory()->create(['assigned_to' => $otherUser->id]);

        $count = Activity::assignedTo($user)->count();
        expect($count)->toBe(1);
    });

    it('scopes by user or assigned to', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();

        // Created by user
        Activity::factory()->create([
            'user_id' => $user->id,
            'assigned_to' => $otherUser->id,
        ]);

        // Assigned to user
        Activity::factory()->create([
            'user_id' => $otherUser->id,
            'assigned_to' => $user->id,
        ]);

        // Neither
        Activity::factory()->create([
            'user_id' => $otherUser->id,
            'assigned_to' => $otherUser->id,
        ]);

        $count = Activity::forUserOrAssignedTo($user)->count();
        expect($count)->toBe(2);
    });
});
