<?php

use App\Models\Activity;
use App\Models\Deal;
use App\Models\User;
use Livewire\Livewire;

describe('TaskList component', function () {
    it('renders the task list component', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('activities.task-list')
            ->assertStatus(200)
            ->assertSee('Tasks');
    });

    it('requires authentication', function () {
        $this->get(route('planner.index'))
            ->assertRedirect(route('login'));
    });

    it('redirects non-internal users to their dashboard', function () {
        $user = User::factory()->customer()->create();

        // Now redirects to customer dashboard instead of showing 403
        $this->actingAs($user)
            ->get(route('planner.index'))
            ->assertRedirect(route('customer.dashboard'))
            ->assertSessionHas('warning');
    });

    it('displays activities for current user', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $activity = Activity::factory()->forDeal($deal)->byUser($user)->create([
            'title' => 'My Test Activity',
        ]);

        Livewire::actingAs($user)
            ->test('activities.task-list')
            ->assertSee('My Test Activity');
    });

    it('does not display activities from other users', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $myActivity = Activity::factory()->forDeal($deal)->byUser($user)->create([
            'title' => 'My Activity',
        ]);
        $otherActivity = Activity::factory()->forDeal($deal)->byUser($otherUser)->create([
            'title' => 'Other User Activity',
        ]);

        Livewire::actingAs($user)
            ->test('activities.task-list')
            ->assertSee('My Activity')
            ->assertDontSee('Other User Activity');
    });

    it('can filter activities by type', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $taskActivity = Activity::factory()->task()->forDeal($deal)->byUser($user)->create([
            'title' => 'Task Activity',
        ]);
        $callActivity = Activity::factory()->call()->forDeal($deal)->byUser($user)->create([
            'title' => 'Call Activity',
        ]);

        Livewire::actingAs($user)
            ->test('activities.task-list')
            ->set('typeFilter', 'task')
            ->assertSee('Task Activity')
            ->assertDontSee('Call Activity');
    });

    it('can filter activities by incomplete status', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $incompleteActivity = Activity::factory()->forDeal($deal)->byUser($user)->create([
            'title' => 'Incomplete Activity',
            'is_completed' => false,
        ]);
        $completedActivity = Activity::factory()->completed()->forDeal($deal)->byUser($user)->create([
            'title' => 'Completed Activity',
        ]);

        Livewire::actingAs($user)
            ->test('activities.task-list')
            ->set('statusFilter', 'incomplete')
            ->assertSee('Incomplete Activity')
            ->assertDontSee('Completed Activity');
    });

    it('can filter activities by completed status', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $incompleteActivity = Activity::factory()->forDeal($deal)->byUser($user)->create([
            'title' => 'Incomplete Activity',
            'is_completed' => false,
        ]);
        $completedActivity = Activity::factory()->completed()->forDeal($deal)->byUser($user)->create([
            'title' => 'Completed Activity',
        ]);

        Livewire::actingAs($user)
            ->test('activities.task-list')
            ->set('statusFilter', 'completed')
            ->assertSee('Completed Activity')
            ->assertDontSee('Incomplete Activity');
    });

    it('can filter activities by overdue status', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $overdueActivity = Activity::factory()->overdue()->forDeal($deal)->byUser($user)->create([
            'title' => 'Overdue Activity',
        ]);
        $futureActivity = Activity::factory()->forDeal($deal)->byUser($user)->create([
            'title' => 'Future Activity',
            'due_at' => now()->addWeek(),
            'is_completed' => false,
        ]);

        Livewire::actingAs($user)
            ->test('activities.task-list')
            ->set('statusFilter', 'overdue')
            ->assertSee('Overdue Activity')
            ->assertDontSee('Future Activity');
    });

    it('can filter activities due today', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $todayActivity = Activity::factory()->dueToday()->forDeal($deal)->byUser($user)->create([
            'title' => 'Today Activity',
        ]);
        $tomorrowActivity = Activity::factory()->forDeal($deal)->byUser($user)->create([
            'title' => 'Tomorrow Activity',
            'due_at' => now()->addDay(),
            'is_completed' => false,
        ]);

        Livewire::actingAs($user)
            ->test('activities.task-list')
            ->set('dueDateFilter', 'today')
            ->assertSee('Today Activity')
            ->assertDontSee('Tomorrow Activity');
    });

    it('can toggle task completion', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $activity = Activity::factory()->forDeal($deal)->byUser($user)->create([
            'title' => 'Toggle Activity',
            'is_completed' => false,
        ]);

        Livewire::actingAs($user)
            ->test('activities.task-list')
            ->call('toggleComplete', $activity->id);

        $activity->refresh();
        expect($activity->is_completed)->toBeTrue();
        expect($activity->completed_at)->not->toBeNull();
    });

    it('can toggle completed task back to incomplete', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $activity = Activity::factory()->completed()->forDeal($deal)->byUser($user)->create([
            'title' => 'Completed Activity',
        ]);

        Livewire::actingAs($user)
            ->test('activities.task-list')
            ->call('toggleComplete', $activity->id);

        $activity->refresh();
        expect($activity->is_completed)->toBeFalse();
        expect($activity->completed_at)->toBeNull();
    });

    it('resets pagination when type filter changes', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        Activity::factory()->count(20)->forDeal($deal)->byUser($user)->create();

        Livewire::actingAs($user)
            ->test('activities.task-list')
            ->set('typeFilter', 'task')
            ->assertSet('paginators.page', 1);
    });

    it('resets pagination when status filter changes', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        Activity::factory()->count(20)->forDeal($deal)->byUser($user)->create();

        Livewire::actingAs($user)
            ->test('activities.task-list')
            ->set('statusFilter', 'completed')
            ->assertSet('paginators.page', 1);
    });

    it('resets pagination when due date filter changes', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        Activity::factory()->count(20)->forDeal($deal)->byUser($user)->create();

        Livewire::actingAs($user)
            ->test('activities.task-list')
            ->set('dueDateFilter', 'today')
            ->assertSet('paginators.page', 1);
    });

    it('shows empty state when no activities exist', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('activities.task-list')
            ->assertSee('No activities found');
    });

    it('shows empty state with appropriate message when filter yields no results', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        Activity::factory()->task()->forDeal($deal)->byUser($user)->create();

        Livewire::actingAs($user)
            ->test('activities.task-list')
            ->set('typeFilter', 'email')
            ->assertSee('No activities found')
            ->assertSee('Try adjusting your filter criteria');
    });

    it('displays correct type badges', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        Activity::factory()->task()->forDeal($deal)->byUser($user)->create([
            'title' => 'Task Item',
        ]);

        Livewire::actingAs($user)
            ->test('activities.task-list')
            ->assertSee('Task');
    });

    it('displays subject link for deal activities', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create(['title' => 'Test Deal']);

        Activity::factory()->forDeal($deal)->byUser($user)->create([
            'title' => 'Deal Activity',
        ]);

        Livewire::actingAs($user)
            ->test('activities.task-list')
            ->assertSee('Deal Activity')
            ->assertSee('Test Deal');
    });

    it('has correct default filter values', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('activities.task-list')
            ->assertSet('typeFilter', 'all')
            ->assertSet('statusFilter', 'all')
            ->assertSet('dueDateFilter', 'all')
            ->assertSet('perPage', 15);
    });

    it('cannot toggle completion for other users activities', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $activity = Activity::factory()->forDeal($deal)->byUser($otherUser)->create([
            'is_completed' => false,
        ]);

        expect(fn () => Livewire::actingAs($user)
            ->test('activities.task-list')
            ->call('toggleComplete', $activity->id)
        )->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    });
});
