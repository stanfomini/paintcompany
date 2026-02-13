<?php

use App\Models\Activity;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\User;
use App\Services\DealStageService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('DealStageService', function () {
    it('transitions deal to a new stage', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $this->actingAs($user);

        $deal = Deal::factory()->lead()->create(['assigned_to' => $user->id]);

        $service = app(DealStageService::class);
        $service->transitionTo($deal, 'quote_scheduled');

        $deal->refresh();
        expect($deal->stage)->toBe('quote_scheduled');
    });

    it('creates activity when transitioning to not_contacted', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $this->actingAs($user);

        $customer = Customer::factory()->create(['first_name' => 'John', 'last_name' => 'Doe']);
        $deal = Deal::factory()->forCustomer($customer)->lead()->create([
            'title' => 'Kitchen Repaint',
            'assigned_to' => $user->id,
        ]);

        $service = app(DealStageService::class);
        $service->transitionTo($deal, 'not_contacted');

        $deal->refresh();
        expect($deal->stage)->toBe('not_contacted');

        $activity = Activity::where('subject_type', Deal::class)
            ->where('subject_id', $deal->id)
            ->first();

        expect($activity)->not->toBeNull();
        expect($activity->type)->toBe('call');
        expect($activity->title)->toContain('Follow up: John Doe');
        expect($activity->title)->toContain('Kitchen Repaint');
        expect($activity->assigned_to)->toBe($user->id);
        expect($activity->due_at)->not->toBeNull();
        expect($activity->is_completed)->toBeFalse();
    });

    it('creates activity when transitioning to follow_up with specified date', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $this->actingAs($user);

        $customer = Customer::factory()->create();
        $deal = Deal::factory()->forCustomer($customer)->lead()->create([
            'assigned_to' => $user->id,
        ]);

        $service = app(DealStageService::class);
        $followUpDate = now()->addDays(3)->toDateString();
        $service->transitionTo($deal, 'follow_up', $followUpDate);

        $deal->refresh();
        expect($deal->stage)->toBe('follow_up');
        expect($deal->follow_up_date->toDateString())->toBe($followUpDate);

        $activity = Activity::where('subject_type', Deal::class)
            ->where('subject_id', $deal->id)
            ->first();

        expect($activity)->not->toBeNull();
        expect($activity->due_at->toDateString())->toBe($followUpDate);
    });

    it('creates meeting activity for quote_scheduled transition', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $this->actingAs($user);

        $customer = Customer::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);
        $deal = Deal::factory()->forCustomer($customer)->lead()->create(['assigned_to' => $user->id]);

        $service = app(DealStageService::class);
        $service->transitionTo($deal, 'quote_scheduled');

        $activity = Activity::where('subject_type', Deal::class)
            ->where('subject_id', $deal->id)
            ->first();

        expect($activity)->not->toBeNull();
        expect($activity->type)->toBe('meeting');
        expect($activity->title)->toContain('Quote appointment');
        expect($activity->title)->toContain('Jane Doe');
        expect($activity->assigned_to)->toBe($user->id);
        expect($activity->is_completed)->toBeFalse();
    });

    it('creates meeting activity with provided date for quote_scheduled', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $this->actingAs($user);

        $customer = Customer::factory()->create();
        $deal = Deal::factory()->forCustomer($customer)->lead()->create(['assigned_to' => $user->id]);

        $service = app(DealStageService::class);
        $scheduleDate = now()->addDays(3)->setTime(14, 30)->format('Y-m-d\TH:i');
        $service->transitionTo($deal, 'quote_scheduled', $scheduleDate);

        $activity = Activity::where('subject_type', Deal::class)
            ->where('subject_id', $deal->id)
            ->first();

        expect($activity)->not->toBeNull();
        expect($activity->type)->toBe('meeting');
        expect($activity->due_at->format('H:i'))->toBe('14:30');
    });

    it('does not create activity for signed transition', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $this->actingAs($user);

        $deal = Deal::factory()->quoted()->create(['assigned_to' => $user->id]);

        $service = app(DealStageService::class);
        $service->transitionTo($deal, 'signed');

        expect(Activity::where('subject_type', Deal::class)->where('subject_id', $deal->id)->count())->toBe(0);
    });

    it('does not create activity for lost transition', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $this->actingAs($user);

        $deal = Deal::factory()->lead()->create(['assigned_to' => $user->id]);

        $service = app(DealStageService::class);
        $service->transitionTo($deal, 'lost');

        expect(Activity::where('subject_type', Deal::class)->where('subject_id', $deal->id)->count())->toBe(0);
    });

    it('ignores invalid stage', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $this->actingAs($user);

        $deal = Deal::factory()->lead()->create();

        $service = app(DealStageService::class);
        $service->transitionTo($deal, 'invalid_stage');

        $deal->refresh();
        expect($deal->stage)->toBe('lead');
    });

    it('assigns activity to deal assigned_to user', function () {
        $creator = User::factory()->internal()->withFullAccess()->create();
        $assignee = User::factory()->internal()->withFullAccess()->create();
        $this->actingAs($creator);

        $customer = Customer::factory()->create();
        $deal = Deal::factory()->forCustomer($customer)->lead()->create([
            'created_by' => $creator->id,
            'assigned_to' => $assignee->id,
        ]);

        $service = app(DealStageService::class);
        $service->transitionTo($deal, 'not_contacted');

        $activity = Activity::where('subject_type', Deal::class)
            ->where('subject_id', $deal->id)
            ->first();

        expect($activity->assigned_to)->toBe($assignee->id);
    });
});

describe('Deal model stage helpers', function () {
    it('has correct stages constant', function () {
        expect(array_keys(Deal::STAGES))->toBe([
            'lead', 'not_contacted', 'follow_up', 'quote_scheduled', 'quoted', 'signed', 'completed', 'lost', 'cancelled',
        ]);
    });

    it('has kanban stages', function () {
        expect(Deal::KANBAN_STAGES)->toBe(['lead', 'quote_scheduled', 'quoted']);
    });

    it('has planner stages', function () {
        expect(Deal::PLANNER_STAGES)->toBe(['not_contacted', 'follow_up']);
    });

    it('reports isOnKanban for kanban stages', function () {
        $deal = Deal::factory()->lead()->create();
        expect($deal->isOnKanban())->toBeTrue();

        $deal = Deal::factory()->quoteScheduled()->create();
        expect($deal->isOnKanban())->toBeTrue();

        $deal = Deal::factory()->notContacted()->create();
        expect($deal->isOnKanban())->toBeFalse();
    });

    it('reports isOnPlanner for planner stages', function () {
        $deal = Deal::factory()->notContacted()->create();
        expect($deal->isOnPlanner())->toBeTrue();

        $deal = Deal::factory()->followUp()->create();
        expect($deal->isOnPlanner())->toBeTrue();

        $deal = Deal::factory()->lead()->create();
        expect($deal->isOnPlanner())->toBeFalse();
    });

    it('scopes kanban deals', function () {
        Deal::factory()->lead()->create();
        Deal::factory()->quoteScheduled()->create();
        Deal::factory()->quoted()->create();
        Deal::factory()->notContacted()->create();
        Deal::factory()->followUp()->create();
        Deal::factory()->signed()->create();

        expect(Deal::kanban()->count())->toBe(3);
    });

    it('scopes planner deals', function () {
        Deal::factory()->lead()->create();
        Deal::factory()->notContacted()->create();
        Deal::factory()->followUp()->create();
        Deal::factory()->signed()->create();

        expect(Deal::plannerOnly()->count())->toBe(2);
    });

    it('has follow_up_date cast', function () {
        $deal = Deal::factory()->followUp(now()->addDays(5)->toDateString())->create();
        expect($deal->follow_up_date)->not->toBeNull();
    });
});
