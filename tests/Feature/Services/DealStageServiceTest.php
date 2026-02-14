<?php

use App\Models\Activity;
use App\Models\Deal;
use App\Models\User;
use App\Services\DealStageService;

beforeEach(function () {
    $this->user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($this->user);
    $this->service = app(DealStageService::class);
});

describe('DealStageService deduplication', function () {
    it('creates follow-up activity with automation_key', function () {
        $deal = Deal::factory()->create([
            'stage' => 'lead',
            'assigned_to' => $this->user->id,
        ]);

        $this->service->transitionTo($deal, 'follow_up');

        $activity = Activity::query()
            ->forSubject($deal)
            ->byAutomationKey('deal_follow_up')
            ->first();

        expect($activity)->not->toBeNull()
            ->and($activity->automation_key)->toBe('deal_follow_up')
            ->and($activity->type)->toBe('call');
    });

    it('creates quote-scheduled activity with automation_key', function () {
        $deal = Deal::factory()->create([
            'stage' => 'follow_up',
            'assigned_to' => $this->user->id,
        ]);

        $this->service->transitionTo($deal, 'quote_scheduled');

        $activity = Activity::query()
            ->forSubject($deal)
            ->byAutomationKey('deal_quote_scheduled')
            ->first();

        expect($activity)->not->toBeNull()
            ->and($activity->automation_key)->toBe('deal_quote_scheduled')
            ->and($activity->type)->toBe('meeting');
    });

    it('does not create duplicate follow-up if incomplete one exists', function () {
        $deal = Deal::factory()->create([
            'stage' => 'lead',
            'assigned_to' => $this->user->id,
        ]);

        $this->service->transitionTo($deal, 'follow_up');
        $this->service->transitionTo($deal, 'follow_up');

        $count = Activity::query()
            ->forSubject($deal)
            ->byAutomationKey('deal_follow_up')
            ->incomplete()
            ->count();

        expect($count)->toBe(1);
    });

    it('does not create duplicate quote-scheduled if incomplete one exists', function () {
        $deal = Deal::factory()->create([
            'stage' => 'follow_up',
            'assigned_to' => $this->user->id,
        ]);

        $this->service->transitionTo($deal, 'quote_scheduled');

        // Manually reset stage to trigger again
        $deal->update(['stage' => 'follow_up']);
        $this->service->transitionTo($deal, 'quote_scheduled');

        $count = Activity::query()
            ->forSubject($deal)
            ->byAutomationKey('deal_quote_scheduled')
            ->incomplete()
            ->count();

        expect($count)->toBe(1);
    });

    it('allows new follow-up after previous one is completed', function () {
        $deal = Deal::factory()->create([
            'stage' => 'lead',
            'assigned_to' => $this->user->id,
        ]);

        $this->service->transitionTo($deal, 'follow_up');

        // Complete the first follow-up
        Activity::query()
            ->forSubject($deal)
            ->byAutomationKey('deal_follow_up')
            ->first()
            ->markAsCompleted();

        // Reset and transition again
        $deal->update(['stage' => 'lead']);
        $this->service->transitionTo($deal, 'follow_up');

        $count = Activity::query()
            ->forSubject($deal)
            ->byAutomationKey('deal_follow_up')
            ->count();

        expect($count)->toBe(2);
    });
});

describe('DealStageService auto-completion', function () {
    it('completes follow-up activities when deal moves to quote_scheduled', function () {
        $deal = Deal::factory()->create([
            'stage' => 'lead',
            'assigned_to' => $this->user->id,
        ]);

        // Create a follow-up first
        $this->service->transitionTo($deal, 'follow_up');

        $followUp = Activity::query()
            ->forSubject($deal)
            ->byAutomationKey('deal_follow_up')
            ->incomplete()
            ->first();

        expect($followUp)->not->toBeNull();

        // Now move to quote_scheduled
        $this->service->transitionTo($deal, 'quote_scheduled');

        $followUp->refresh();
        expect($followUp->is_completed)->toBeTrue()
            ->and($followUp->completed_at)->not->toBeNull();
    });

    it('completes all deal activities when deal reaches terminal stage lost', function () {
        $deal = Deal::factory()->create([
            'stage' => 'follow_up',
            'assigned_to' => $this->user->id,
        ]);

        // Create activities
        Activity::factory()->forDeal($deal)->byUser($this->user)->create([
            'title' => 'Task 1',
            'assigned_to' => $this->user->id,
            'automation_key' => 'deal_follow_up',
            'is_completed' => false,
        ]);
        Activity::factory()->forDeal($deal)->byUser($this->user)->create([
            'title' => 'Task 2',
            'assigned_to' => $this->user->id,
            'is_completed' => false,
        ]);

        $this->service->transitionTo($deal, 'lost');

        $incompleteCount = Activity::query()
            ->forSubject($deal)
            ->incomplete()
            ->count();

        expect($incompleteCount)->toBe(0);
    });

    it('completes all deal activities when deal reaches terminal stage cancelled', function () {
        $deal = Deal::factory()->create([
            'stage' => 'quoted',
            'assigned_to' => $this->user->id,
        ]);

        Activity::factory()->forDeal($deal)->byUser($this->user)->create([
            'title' => 'Open Task',
            'assigned_to' => $this->user->id,
            'is_completed' => false,
        ]);

        $this->service->transitionTo($deal, 'cancelled');

        $incompleteCount = Activity::query()
            ->forSubject($deal)
            ->incomplete()
            ->count();

        expect($incompleteCount)->toBe(0);
    });

    it('completes follow-up activities when deal leaves planner stages', function () {
        $deal = Deal::factory()->create([
            'stage' => 'lead',
            'assigned_to' => $this->user->id,
        ]);

        // Create follow-up
        $this->service->transitionTo($deal, 'follow_up');

        // Move to kanban stage (lead)
        $this->service->transitionTo($deal, 'lead');

        $incompleteFollowUps = Activity::query()
            ->forSubject($deal)
            ->byAutomationKey('deal_follow_up')
            ->incomplete()
            ->count();

        expect($incompleteFollowUps)->toBe(0);
    });

    it('does not complete non-follow-up activities when moving between planner stages', function () {
        $deal = Deal::factory()->create([
            'stage' => 'not_contacted',
            'assigned_to' => $this->user->id,
        ]);

        // Create a manual task (no automation_key)
        $manualTask = Activity::factory()->forDeal($deal)->byUser($this->user)->create([
            'title' => 'Manual Task',
            'assigned_to' => $this->user->id,
            'is_completed' => false,
        ]);

        $this->service->transitionTo($deal, 'follow_up');

        $manualTask->refresh();
        expect($manualTask->is_completed)->toBeFalse();
    });
});
