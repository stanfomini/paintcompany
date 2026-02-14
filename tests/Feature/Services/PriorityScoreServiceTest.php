<?php

use App\Models\Activity;
use App\Models\Deal;
use App\Models\User;
use App\Services\PriorityScoreService;

beforeEach(function () {
    $this->user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($this->user);
    $this->service = app(PriorityScoreService::class);
});

describe('PriorityScoreService', function () {
    it('completed activities get score of -100', function () {
        $activity = Activity::factory()->completed()->byUser($this->user)->create([
            'subject_type' => null,
            'subject_id' => null,
        ]);

        $score = $this->service->calculateScore($activity);

        expect($score)->toBe(-100);
    });

    it('urgent priority adds 40 points', function () {
        $activity = Activity::factory()->byUser($this->user)->create([
            'priority' => 'urgent',
            'is_completed' => false,
            'due_at' => null,
            'subject_type' => null,
            'subject_id' => null,
        ]);

        $score = $this->service->calculateScore($activity);

        // urgent (+40) + no due date (-5) = 35
        expect($score)->toBe(35);
    });

    it('high priority adds 20 points', function () {
        $activity = Activity::factory()->byUser($this->user)->create([
            'priority' => 'high',
            'is_completed' => false,
            'due_at' => null,
            'subject_type' => null,
            'subject_id' => null,
        ]);

        $score = $this->service->calculateScore($activity);

        // high (+20) + no due date (-5) = 15
        expect($score)->toBe(15);
    });

    it('low priority subtracts 10 points', function () {
        $activity = Activity::factory()->byUser($this->user)->create([
            'priority' => 'low',
            'is_completed' => false,
            'due_at' => null,
            'subject_type' => null,
            'subject_id' => null,
        ]);

        $score = $this->service->calculateScore($activity);

        // low (-10) + no due date (-5) = -15
        expect($score)->toBe(-15);
    });

    it('overdue 3+ days adds 50 points', function () {
        $activity = Activity::factory()->byUser($this->user)->create([
            'priority' => 'normal',
            'is_completed' => false,
            'due_at' => now()->subDays(4),
            'subject_type' => null,
            'subject_id' => null,
        ]);

        $score = $this->service->calculateScore($activity);

        // normal (+0) + overdue 3+ days (+50) = 50
        expect($score)->toBe(50);
    });

    it('overdue 1-2 days adds 30 points', function () {
        $activity = Activity::factory()->byUser($this->user)->create([
            'priority' => 'normal',
            'is_completed' => false,
            'due_at' => now()->subDay(),
            'subject_type' => null,
            'subject_id' => null,
        ]);

        $score = $this->service->calculateScore($activity);

        // normal (+0) + overdue 1-2 days (+30) = 30
        expect($score)->toBe(30);
    });

    it('due today adds 20 points', function () {
        $activity = Activity::factory()->byUser($this->user)->create([
            'priority' => 'normal',
            'is_completed' => false,
            'due_at' => now(),
            'subject_type' => null,
            'subject_id' => null,
        ]);

        $score = $this->service->calculateScore($activity);

        // normal (+0) + due today (+20) = 20
        expect($score)->toBe(20);
    });

    it('due tomorrow adds 10 points', function () {
        $activity = Activity::factory()->byUser($this->user)->create([
            'priority' => 'normal',
            'is_completed' => false,
            'due_at' => now()->addDay(),
            'subject_type' => null,
            'subject_id' => null,
        ]);

        $score = $this->service->calculateScore($activity);

        // normal (+0) + due tomorrow (+10) = 10
        expect($score)->toBe(10);
    });

    it('no due date subtracts 5 points', function () {
        $activity = Activity::factory()->byUser($this->user)->create([
            'priority' => 'normal',
            'is_completed' => false,
            'due_at' => null,
            'subject_type' => null,
            'subject_id' => null,
        ]);

        $score = $this->service->calculateScore($activity);

        // normal (+0) + no due date (-5) = -5
        expect($score)->toBe(-5);
    });

    it('new lead deal adds 35 points', function () {
        $deal = Deal::factory()->lead()->create([
            'created_at' => now()->subHours(2),
        ]);

        $activity = Activity::factory()->byUser($this->user)->forDeal($deal)->create([
            'priority' => 'normal',
            'is_completed' => false,
            'due_at' => null,
        ]);

        $score = $this->service->calculateScore($activity);

        // normal (+0) + no due date (-5) + new lead (+35) = 30
        expect($score)->toBe(30);
    });

    it('updateScore persists the score', function () {
        $activity = Activity::factory()->byUser($this->user)->create([
            'priority' => 'urgent',
            'is_completed' => false,
            'due_at' => null,
            'priority_score' => 0,
            'subject_type' => null,
            'subject_id' => null,
        ]);

        $this->service->updateScore($activity);

        $activity->refresh();

        // urgent (+40) + no due date (-5) = 35
        expect($activity->priority_score)->toBe(35);
    });

    it('recalculateForUser only updates user activities', function () {
        $otherUser = User::factory()->internal()->create();

        // Activity for our user
        $userActivity = Activity::factory()->byUser($this->user)->create([
            'priority' => 'urgent',
            'is_completed' => false,
            'due_at' => null,
            'priority_score' => 0,
            'subject_type' => null,
            'subject_id' => null,
        ]);

        // Activity for another user (not assigned to our user either)
        $otherActivity = Activity::factory()->byUser($otherUser)->create([
            'priority' => 'urgent',
            'is_completed' => false,
            'due_at' => null,
            'priority_score' => 0,
            'assigned_to' => null,
            'subject_type' => null,
            'subject_id' => null,
        ]);

        $updated = $this->service->recalculateForUser($this->user);

        $userActivity->refresh();
        $otherActivity->refresh();

        expect($updated)->toBeGreaterThanOrEqual(1)
            ->and($userActivity->priority_score)->toBe(35)
            ->and($otherActivity->priority_score)->toBe(0);
    });

    it('recalculateAll updates all incomplete activities', function () {
        $otherUser = User::factory()->internal()->create();

        $activity1 = Activity::factory()->byUser($this->user)->create([
            'priority' => 'urgent',
            'is_completed' => false,
            'due_at' => null,
            'priority_score' => 0,
            'subject_type' => null,
            'subject_id' => null,
        ]);

        $activity2 = Activity::factory()->byUser($otherUser)->create([
            'priority' => 'high',
            'is_completed' => false,
            'due_at' => null,
            'priority_score' => 0,
            'subject_type' => null,
            'subject_id' => null,
        ]);

        $completedActivity = Activity::factory()->byUser($this->user)->completed()->create([
            'priority' => 'urgent',
            'priority_score' => 0,
            'subject_type' => null,
            'subject_id' => null,
        ]);

        $updated = $this->service->recalculateAll();

        $activity1->refresh();
        $activity2->refresh();
        $completedActivity->refresh();

        expect($updated)->toBeGreaterThanOrEqual(2)
            ->and($activity1->priority_score)->toBe(35)   // urgent (+40) + no due date (-5)
            ->and($activity2->priority_score)->toBe(15)    // high (+20) + no due date (-5)
            ->and($completedActivity->priority_score)->toBe(0); // completed, not in query
    });
});
