<?php

use App\Models\Activity;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Job;
use App\Models\User;
use App\Notifications\TaskAssigned;
use App\Services\ChatService;
use Illuminate\Support\Facades\Event;
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

    it('can toggle completion on call type activities', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $activity = Activity::factory()->call()->create([
            'user_id' => $user->id,
            'assigned_to' => $user->id,
            'is_completed' => false,
        ]);

        Livewire::actingAs($user)
            ->test('planner.my-day')
            ->call('toggleComplete', $activity->id);

        $activity->refresh();
        expect($activity->is_completed)->toBeTrue();
        expect($activity->completed_at)->not->toBeNull();
    });

    it('can toggle completion on meeting type activities', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $activity = Activity::factory()->meeting()->create([
            'user_id' => $user->id,
            'assigned_to' => $user->id,
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

describe('Planner deal stage advancement', function () {
    it('shows deal context on deal-linked activities', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->createdBy($user)->create([
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ]);
        $deal = Deal::factory()->forCustomer($customer)->notContacted()->create([
            'title' => 'Kitchen Repaint',
            'assigned_to' => $user->id,
            'created_by' => $user->id,
        ]);

        Activity::factory()->create([
            'user_id' => $user->id,
            'assigned_to' => $user->id,
            'subject_type' => Deal::class,
            'subject_id' => $deal->id,
            'type' => 'call',
            'title' => 'Follow up: Jane Doe - Kitchen Repaint',
            'due_at' => today(),
        ]);

        Livewire::actingAs($user)
            ->test('planner.my-day')
            ->assertSee('Follow up: Jane Doe - Kitchen Repaint')
            ->assertSee('Not Contacted')
            ->assertSee('Jane Doe');
    });

    it('can advance deal from planner to quote_scheduled with date modal', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->createdBy($user)->create();
        $deal = Deal::factory()->forCustomer($customer)->notContacted()->create([
            'assigned_to' => $user->id,
            'created_by' => $user->id,
        ]);

        $activity = Activity::factory()->create([
            'user_id' => $user->id,
            'assigned_to' => $user->id,
            'subject_type' => Deal::class,
            'subject_id' => $deal->id,
            'type' => 'call',
            'title' => 'Follow up',
            'due_at' => today(),
            'is_completed' => false,
        ]);

        $scheduleDate = now()->addDays(2)->setTime(10, 0)->format('Y-m-d\TH:i');

        Livewire::actingAs($user)
            ->test('planner.my-day')
            ->call('advanceDealStage', $activity->id, 'quote_scheduled')
            ->assertSet('showDealFollowUpModal', true)
            ->assertSet('advancingStage', 'quote_scheduled')
            ->assertSet('advancingActivityId', $activity->id)
            ->set('dealFollowUpDate', $scheduleDate)
            ->call('confirmDealFollowUp');

        $deal->refresh();
        $activity->refresh();
        expect($deal->stage)->toBe('quote_scheduled');
        expect($activity->is_completed)->toBeTrue();

        // A quote appointment activity should have been created
        $newActivity = Activity::where('subject_type', Deal::class)
            ->where('subject_id', $deal->id)
            ->where('id', '!=', $activity->id)
            ->first();
        expect($newActivity)->not->toBeNull();
        expect($newActivity->type)->toBe('meeting');
        expect($newActivity->title)->toContain('Quote appointment');
    });

    it('can advance deal to follow_up with date modal', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->createdBy($user)->create();
        $deal = Deal::factory()->forCustomer($customer)->notContacted()->create([
            'assigned_to' => $user->id,
            'created_by' => $user->id,
        ]);

        $activity = Activity::factory()->create([
            'user_id' => $user->id,
            'assigned_to' => $user->id,
            'subject_type' => Deal::class,
            'subject_id' => $deal->id,
            'type' => 'call',
            'title' => 'Follow up',
            'due_at' => today(),
            'is_completed' => false,
        ]);

        $followUpDate = now()->addDays(3)->toDateString();

        Livewire::actingAs($user)
            ->test('planner.my-day')
            ->call('advanceDealStage', $activity->id, 'follow_up')
            ->assertSet('showDealFollowUpModal', true)
            ->assertSet('advancingActivityId', $activity->id)
            ->set('dealFollowUpDate', $followUpDate)
            ->call('confirmDealFollowUp');

        $deal->refresh();
        $activity->refresh();
        expect($deal->stage)->toBe('follow_up');
        expect($activity->is_completed)->toBeTrue();

        // A new follow-up activity should have been created
        $newActivity = Activity::where('subject_type', Deal::class)
            ->where('subject_id', $deal->id)
            ->where('id', '!=', $activity->id)
            ->first();
        expect($newActivity)->not->toBeNull();
    });

    it('can mark deal as lost from planner', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->createdBy($user)->create();
        $deal = Deal::factory()->forCustomer($customer)->followUp()->create([
            'assigned_to' => $user->id,
            'created_by' => $user->id,
        ]);

        $activity = Activity::factory()->create([
            'user_id' => $user->id,
            'assigned_to' => $user->id,
            'subject_type' => Deal::class,
            'subject_id' => $deal->id,
            'type' => 'call',
            'title' => 'Follow up',
            'due_at' => today(),
            'is_completed' => false,
        ]);

        Livewire::actingAs($user)
            ->test('planner.my-day')
            ->call('advanceDealStage', $activity->id, 'lost');

        $deal->refresh();
        expect($deal->stage)->toBe('lost');
    });

    it('shows date modal when advancing to quote_scheduled', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->createdBy($user)->create();
        $deal = Deal::factory()->forCustomer($customer)->notContacted()->create([
            'assigned_to' => $user->id,
            'created_by' => $user->id,
        ]);

        $activity = Activity::factory()->create([
            'user_id' => $user->id,
            'assigned_to' => $user->id,
            'subject_type' => Deal::class,
            'subject_id' => $deal->id,
            'type' => 'call',
            'title' => 'Follow up',
            'due_at' => today(),
            'is_completed' => false,
        ]);

        Livewire::actingAs($user)
            ->test('planner.my-day')
            ->call('advanceDealStage', $activity->id, 'quote_scheduled')
            ->assertSet('showDealFollowUpModal', true)
            ->assertSet('advancingStage', 'quote_scheduled')
            ->assertSet('advancingActivityId', $activity->id);
    });

    it('creates meeting activity when confirming quote schedule', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->createdBy($user)->create([
            'first_name' => 'Alice',
            'last_name' => 'Smith',
        ]);
        $deal = Deal::factory()->forCustomer($customer)->notContacted()->create([
            'assigned_to' => $user->id,
            'created_by' => $user->id,
        ]);

        $activity = Activity::factory()->create([
            'user_id' => $user->id,
            'assigned_to' => $user->id,
            'subject_type' => Deal::class,
            'subject_id' => $deal->id,
            'type' => 'call',
            'title' => 'Follow up',
            'due_at' => today(),
            'is_completed' => false,
        ]);

        $scheduleDate = now()->addDays(2)->setTime(10, 0)->format('Y-m-d\TH:i');

        Livewire::actingAs($user)
            ->test('planner.my-day')
            ->call('advanceDealStage', $activity->id, 'quote_scheduled')
            ->assertSet('showDealFollowUpModal', true)
            ->set('dealFollowUpDate', $scheduleDate)
            ->call('confirmDealFollowUp');

        $deal->refresh();
        $activity->refresh();
        expect($deal->stage)->toBe('quote_scheduled');
        expect($activity->is_completed)->toBeTrue();

        // A meeting activity should have been created
        $meetingActivity = Activity::where('subject_type', Deal::class)
            ->where('subject_id', $deal->id)
            ->where('type', 'meeting')
            ->where('id', '!=', $activity->id)
            ->first();
        expect($meetingActivity)->not->toBeNull();
        expect($meetingActivity->title)->toContain('Quote appointment');
        expect($meetingActivity->title)->toContain('Alice Smith');
    });

    it('shows quick action buttons only for planner-stage deals', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->createdBy($user)->create();
        $deal = Deal::factory()->forCustomer($customer)->lead()->create([
            'assigned_to' => $user->id,
            'created_by' => $user->id,
        ]);

        Activity::factory()->create([
            'user_id' => $user->id,
            'assigned_to' => $user->id,
            'subject_type' => Deal::class,
            'subject_id' => $deal->id,
            'type' => 'call',
            'title' => 'Deal activity for lead',
            'due_at' => today(),
            'is_completed' => false,
        ]);

        // Lead stage is NOT a planner stage, so quick actions should not appear
        Livewire::actingAs($user)
            ->test('planner.my-day')
            ->assertSee('Deal activity for lead')
            ->assertDontSee('Schedule Quote');
    });
});

describe('Planner unread messages', function () {
    it('shows unread messages section when threads have unread messages', function () {
        Event::fake([\App\Events\MessageSent::class, \App\Events\NewThreadNotification::class]);

        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();

        $chatService = app(ChatService::class);
        $thread = $chatService->getOrCreateDirectThread($user, $otherUser, 'Test Thread');

        // Other user sends a message (creates unread for $user)
        $chatService->sendMessage($thread, $otherUser, 'Hey, can you check this estimate?');

        Livewire::actingAs($user)
            ->test('planner.my-day')
            ->assertSee('Unread Messages')
            ->assertSee('Hey, can you check this estimate?');
    });

    it('does not show unread messages section when no unread messages', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('planner.my-day')
            ->assertDontSee('Unread Messages');
    });

    it('unread messages link to chat thread', function () {
        Event::fake([\App\Events\MessageSent::class, \App\Events\NewThreadNotification::class]);

        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();

        $chatService = app(ChatService::class);
        $thread = $chatService->getOrCreateDirectThread($user, $otherUser, 'Urgent Thread');
        $chatService->sendMessage($thread, $otherUser, 'Please respond ASAP');

        Livewire::actingAs($user)
            ->test('planner.my-day')
            ->assertSeeHtml(route('chat.show', $thread));
    });
});

describe('Planner job context', function () {
    it('shows job stage badge for job activities', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create(['first_name' => 'Test', 'last_name' => 'Customer']);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);
        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'stage' => 'scheduling_colors',
        ]);

        Activity::factory()->create([
            'user_id' => $user->id,
            'assigned_to' => $user->id,
            'subject_type' => Job::class,
            'subject_id' => $job->id,
            'title' => 'Schedule date for job',
            'type' => 'task',
            'due_at' => today(),
            'is_completed' => false,
            'automation_key' => 'job_scheduling_needed',
        ]);

        Livewire::actingAs($user)
            ->test('planner.my-day')
            ->assertSee('Schedule date for job')
            ->assertSee('Scheduling colors')
            ->assertSee('Test Customer');
    });

    it('shows scheduled date in job context', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'scheduled_date' => '2026-03-15',
        ]);

        Activity::factory()->create([
            'user_id' => $user->id,
            'assigned_to' => $user->id,
            'subject_type' => Job::class,
            'subject_id' => $job->id,
            'title' => 'Job task',
            'type' => 'task',
            'due_at' => today(),
            'is_completed' => false,
        ]);

        Livewire::actingAs($user)
            ->test('planner.my-day')
            ->assertSee('Mar 15, 2026');
    });

    it('shows confirm date button for proposed date review activities', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'scheduled_date' => now()->addWeek(),
        ]);

        Activity::factory()->create([
            'user_id' => $user->id,
            'assigned_to' => $user->id,
            'subject_type' => Job::class,
            'subject_id' => $job->id,
            'title' => 'Review proposed date',
            'type' => 'task',
            'due_at' => today(),
            'is_completed' => false,
            'automation_key' => 'review_proposed_date',
        ]);

        Livewire::actingAs($user)
            ->test('planner.my-day')
            ->assertSee('Confirm Date');
    });

    it('shows dispatch crew button for ready-to-dispatch activities', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        Activity::factory()->create([
            'user_id' => $user->id,
            'assigned_to' => $user->id,
            'subject_type' => Job::class,
            'subject_id' => $job->id,
            'title' => 'Ready to dispatch',
            'type' => 'task',
            'due_at' => today(),
            'is_completed' => false,
            'automation_key' => 'ready_for_dispatch',
        ]);

        Livewire::actingAs($user)
            ->test('planner.my-day')
            ->assertSee('Dispatch Crew');
    });

    it('shows view job button for all job activities', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        Activity::factory()->create([
            'user_id' => $user->id,
            'assigned_to' => $user->id,
            'subject_type' => Job::class,
            'subject_id' => $job->id,
            'title' => 'Some job activity',
            'type' => 'task',
            'due_at' => today(),
            'is_completed' => false,
        ]);

        Livewire::actingAs($user)
            ->test('planner.my-day')
            ->assertSee('View Job');
    });

    it('can confirm job date from planner', function () {
        Event::fake();
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'scheduled_date' => now()->addWeek(),
            'company_date_confirmed' => false,
        ]);

        $activity = Activity::factory()->create([
            'user_id' => $user->id,
            'assigned_to' => $user->id,
            'subject_type' => Job::class,
            'subject_id' => $job->id,
            'title' => 'Review proposed date',
            'type' => 'task',
            'due_at' => today(),
            'is_completed' => false,
            'automation_key' => 'review_proposed_date',
        ]);

        Livewire::actingAs($user)
            ->test('planner.my-day')
            ->call('confirmJobDate', $activity->id);

        expect($job->fresh()->company_date_confirmed)->toBeTrue()
            ->and($activity->fresh()->is_completed)->toBeTrue();
    });

    it('can navigate to job via view job action', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        $activity = Activity::factory()->create([
            'user_id' => $user->id,
            'assigned_to' => $user->id,
            'subject_type' => Job::class,
            'subject_id' => $job->id,
            'title' => 'Job task',
            'type' => 'task',
            'due_at' => today(),
            'is_completed' => false,
        ]);

        Livewire::actingAs($user)
            ->test('planner.my-day')
            ->call('viewJob', $activity->id)
            ->assertRedirect(route('jobs.show', $job->id));
    });
});

describe('Planner User context block', function () {
    it('shows type badge for agent user activity', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $agent = User::factory()->agent()->create(['name' => 'Test Agent']);

        Activity::factory()->forUser($agent)->create([
            'user_id' => $user->id,
            'assigned_to' => $user->id,
            'title' => 'Onboard agent',
            'type' => 'task',
            'due_at' => today(),
            'is_completed' => false,
        ]);

        Livewire::actingAs($user)
            ->test('planner.my-day')
            ->assertSee('Onboard agent')
            ->assertSee('Agent')
            ->assertSee('Test Agent');
    });

    it('shows company name for agent with profile', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $agent = User::factory()->agent()->create(['name' => 'Jane Realtor']);
        $agent->agentProfile->update(['company_name' => 'Apex Realty Co']);

        Activity::factory()->forUser($agent)->create([
            'user_id' => $user->id,
            'assigned_to' => $user->id,
            'title' => 'Follow up with agent',
            'type' => 'task',
            'due_at' => today(),
            'is_completed' => false,
        ]);

        Livewire::actingAs($user)
            ->test('planner.my-day')
            ->assertSee('Apex Realty Co');
    });

    it('shows View Profile link to agents.show for agent activity', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $agent = User::factory()->agent()->create();

        Activity::factory()->forUser($agent)->create([
            'user_id' => $user->id,
            'assigned_to' => $user->id,
            'title' => 'Agent task',
            'type' => 'task',
            'due_at' => today(),
            'is_completed' => false,
        ]);

        Livewire::actingAs($user)
            ->test('planner.my-day')
            ->assertSee('View Profile')
            ->assertSeeHtml(route('agents.show', $agent->id));
    });
});
