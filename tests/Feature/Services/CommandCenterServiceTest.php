<?php

use App\Models\Activity;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Job;
use App\Models\User;
use App\Services\CommandCenterService;

beforeEach(function () {
    $this->user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($this->user);
    $this->service = app(CommandCenterService::class);
});

describe('getSmartInbox', function () {
    it('returns high priority items', function () {
        $highPriority = Activity::factory()->byUser($this->user)->create([
            'assigned_to' => $this->user->id,
            'priority' => 'high',
            'priority_score' => 30,
            'is_completed' => false,
            'title' => 'High priority task',
        ]);

        $normalPriority = Activity::factory()->byUser($this->user)->create([
            'assigned_to' => $this->user->id,
            'priority' => 'normal',
            'priority_score' => 0,
            'is_completed' => false,
            'due_at' => now()->addWeek(),
            'title' => 'Normal priority task',
        ]);

        $result = $this->service->getSmartInbox($this->user);

        expect($result->pluck('id')->toArray())->toContain($highPriority->id)
            ->and($result->pluck('id')->toArray())->not->toContain($normalPriority->id);
    });

    it('excludes completed items', function () {
        $completed = Activity::factory()->byUser($this->user)->completed()->create([
            'assigned_to' => $this->user->id,
            'priority' => 'urgent',
            'priority_score' => 50,
            'title' => 'Completed urgent task',
        ]);

        $incomplete = Activity::factory()->byUser($this->user)->create([
            'assigned_to' => $this->user->id,
            'priority' => 'high',
            'priority_score' => 30,
            'is_completed' => false,
            'title' => 'Incomplete high task',
        ]);

        $result = $this->service->getSmartInbox($this->user);

        expect($result->pluck('id')->toArray())->not->toContain($completed->id)
            ->and($result->pluck('id')->toArray())->toContain($incomplete->id);
    });

    it('excludes snoozed items', function () {
        $snoozed = Activity::factory()->byUser($this->user)->create([
            'assigned_to' => $this->user->id,
            'priority' => 'urgent',
            'priority_score' => 50,
            'is_completed' => false,
            'snoozed_until' => now()->addDay(),
            'title' => 'Snoozed task',
        ]);

        $notSnoozed = Activity::factory()->byUser($this->user)->create([
            'assigned_to' => $this->user->id,
            'priority' => 'high',
            'priority_score' => 30,
            'is_completed' => false,
            'title' => 'Not snoozed task',
        ]);

        $result = $this->service->getSmartInbox($this->user);

        expect($result->pluck('id')->toArray())->not->toContain($snoozed->id)
            ->and($result->pluck('id')->toArray())->toContain($notSnoozed->id);
    });

    it('returns items due today', function () {
        $dueToday = Activity::factory()->byUser($this->user)->dueToday()->create([
            'assigned_to' => $this->user->id,
            'priority' => 'normal',
            'priority_score' => 0,
            'title' => 'Due today task',
        ]);

        $result = $this->service->getSmartInbox($this->user);

        expect($result->pluck('id')->toArray())->toContain($dueToday->id);
    });
});

describe('getActivityStream', function () {
    it('filters by mine scope', function () {
        $myActivity = Activity::factory()->byUser($this->user)->create([
            'assigned_to' => $this->user->id,
            'is_completed' => false,
            'title' => 'My activity',
        ]);

        $otherUser = User::factory()->internal()->create();
        $otherActivity = Activity::factory()->byUser($otherUser)->create([
            'assigned_to' => $otherUser->id,
            'is_completed' => false,
            'title' => 'Other activity',
        ]);

        $result = $this->service->getActivityStream($this->user, ['scope' => 'mine']);

        expect($result->pluck('id')->toArray())->toContain($myActivity->id)
            ->and($result->pluck('id')->toArray())->not->toContain($otherActivity->id);
    });

    it('filters active status', function () {
        $activeActivity = Activity::factory()->byUser($this->user)->create([
            'assigned_to' => $this->user->id,
            'is_completed' => false,
            'snoozed_until' => null,
            'title' => 'Active task',
        ]);

        $completedActivity = Activity::factory()->byUser($this->user)->completed()->create([
            'assigned_to' => $this->user->id,
            'title' => 'Completed task',
        ]);

        $snoozedActivity = Activity::factory()->byUser($this->user)->create([
            'assigned_to' => $this->user->id,
            'is_completed' => false,
            'snoozed_until' => now()->addDay(),
            'title' => 'Snoozed task',
        ]);

        $result = $this->service->getActivityStream($this->user, ['scope' => 'mine', 'status' => 'active']);

        $ids = $result->pluck('id')->toArray();
        expect($ids)->toContain($activeActivity->id)
            ->and($ids)->not->toContain($completedActivity->id)
            ->and($ids)->not->toContain($snoozedActivity->id);
    });

    it('filters completed status', function () {
        $completedActivity = Activity::factory()->byUser($this->user)->completed()->create([
            'assigned_to' => $this->user->id,
            'title' => 'Completed task',
        ]);

        $activeActivity = Activity::factory()->byUser($this->user)->create([
            'assigned_to' => $this->user->id,
            'is_completed' => false,
            'title' => 'Active task',
        ]);

        $result = $this->service->getActivityStream($this->user, ['scope' => 'mine', 'status' => 'completed']);

        $ids = $result->pluck('id')->toArray();
        expect($ids)->toContain($completedActivity->id)
            ->and($ids)->not->toContain($activeActivity->id);
    });

    it('filters by type', function () {
        $callActivity = Activity::factory()->call()->byUser($this->user)->create([
            'assigned_to' => $this->user->id,
            'is_completed' => false,
        ]);

        $taskActivity = Activity::factory()->task()->byUser($this->user)->create([
            'assigned_to' => $this->user->id,
            'is_completed' => false,
        ]);

        $result = $this->service->getActivityStream($this->user, [
            'scope' => 'mine',
            'status' => 'active',
            'type' => 'call',
        ]);

        $ids = $result->pluck('id')->toArray();
        expect($ids)->toContain($callActivity->id)
            ->and($ids)->not->toContain($taskActivity->id);
    });

    it('searches by title', function () {
        $matchingActivity = Activity::factory()->byUser($this->user)->create([
            'assigned_to' => $this->user->id,
            'is_completed' => false,
            'title' => 'Follow up with Johnson family',
        ]);

        $nonMatchingActivity = Activity::factory()->byUser($this->user)->create([
            'assigned_to' => $this->user->id,
            'is_completed' => false,
            'title' => 'Prepare materials for project',
        ]);

        $result = $this->service->getActivityStream(
            $this->user,
            ['scope' => 'mine', 'status' => 'active'],
            'Johnson',
        );

        $ids = $result->pluck('id')->toArray();
        expect($ids)->toContain($matchingActivity->id)
            ->and($ids)->not->toContain($nonMatchingActivity->id);
    });

    it('filters by time today', function () {
        $dueTodayActivity = Activity::factory()->byUser($this->user)->dueToday()->create([
            'assigned_to' => $this->user->id,
            'title' => 'Due today',
        ]);

        $dueLaterActivity = Activity::factory()->byUser($this->user)->create([
            'assigned_to' => $this->user->id,
            'is_completed' => false,
            'due_at' => now()->addWeek(),
            'title' => 'Due next week',
        ]);

        $result = $this->service->getActivityStream($this->user, [
            'scope' => 'mine',
            'status' => 'active',
            'time' => 'today',
        ]);

        $ids = $result->pluck('id')->toArray();
        expect($ids)->toContain($dueTodayActivity->id)
            ->and($ids)->not->toContain($dueLaterActivity->id);
    });

    it('filters overdue', function () {
        $overdueActivity = Activity::factory()->byUser($this->user)->overdue()->create([
            'assigned_to' => $this->user->id,
            'title' => 'Overdue task',
        ]);

        $futureActivity = Activity::factory()->byUser($this->user)->create([
            'assigned_to' => $this->user->id,
            'is_completed' => false,
            'due_at' => now()->addWeek(),
            'title' => 'Future task',
        ]);

        $result = $this->service->getActivityStream($this->user, [
            'scope' => 'mine',
            'status' => 'all',
            'time' => 'overdue',
        ]);

        $ids = $result->pluck('id')->toArray();
        expect($ids)->toContain($overdueActivity->id)
            ->and($ids)->not->toContain($futureActivity->id);
    });
});

describe('getContextForSubject', function () {
    it('returns deal context', function () {
        $customer = Customer::factory()->create();
        $deal = Deal::factory()->forCustomer($customer)->create();

        $activity = Activity::factory()->byUser($this->user)->forDeal($deal)->create([
            'assigned_to' => $this->user->id,
            'title' => 'Deal activity',
        ]);

        $result = $this->service->getContextForSubject($deal);

        expect($result)->toHaveKeys(['subject', 'activities', 'related'])
            ->and($result['subject']->id)->toBe($deal->id)
            ->and($result['activities'])->toHaveCount(1)
            ->and($result['activities']->first()->id)->toBe($activity->id)
            ->and($result['related'])->toHaveKey('estimates')
            ->and($result['related'])->toHaveKey('job');
    });

    it('returns job context', function () {
        $deal = Deal::factory()->create();
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        $activity = Activity::factory()->byUser($this->user)->forJob($job)->create([
            'assigned_to' => $this->user->id,
            'title' => 'Job activity',
        ]);

        $result = $this->service->getContextForSubject($job);

        expect($result)->toHaveKeys(['subject', 'activities', 'related'])
            ->and($result['subject']->id)->toBe($job->id)
            ->and($result['activities'])->toHaveCount(1)
            ->and($result['activities']->first()->id)->toBe($activity->id)
            ->and($result['related'])->toHaveKey('deal')
            ->and($result['related']['deal']->id)->toBe($deal->id);
    });
});
