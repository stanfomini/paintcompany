<?php

use App\Events\Automation\DealStageChanged;
use App\Events\Automation\EstimateStatusChanged;
use App\Events\Automation\JobStageChanged;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\Job;
use App\Models\User;
use App\Services\DealStageService;
use App\Services\PlannerAutomationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->internal()->create();
    $this->actingAs($this->user);
});

it('dispatches DealStageChanged event on deal stage transition', function () {
    Event::fake([DealStageChanged::class]);

    $deal = Deal::factory()->lead()->create(['created_by' => $this->user->id]);

    $service = new DealStageService;
    $service->transitionTo($deal, 'follow_up');

    Event::assertDispatched(DealStageChanged::class, function ($event) use ($deal) {
        return $event->getSubject()->is($deal)
            && $event->getEventName() === 'deal.stage_changed';
    });
});

it('DealStageChanged event contains correct from and to stages', function () {
    Event::fake([DealStageChanged::class]);

    $deal = Deal::factory()->lead()->create(['created_by' => $this->user->id]);

    $service = new DealStageService;
    $service->transitionTo($deal, 'quote_scheduled');

    Event::assertDispatched(DealStageChanged::class, function ($event) {
        $context = $event->getContext();

        return $context['from_stage'] === 'lead'
            && $context['to_stage'] === 'quote_scheduled';
    });
});

it('dispatches EstimateStatusChanged event when estimate sent', function () {
    Event::fake([EstimateStatusChanged::class]);

    $estimate = Estimate::factory()->create();

    $service = new PlannerAutomationService;
    $service->onEstimateSent($estimate);

    Event::assertDispatched(EstimateStatusChanged::class, function ($event) use ($estimate) {
        return $event->getSubject()->is($estimate);
    });
});

it('dispatches EstimateStatusChanged event when estimate signed', function () {
    Event::fake([EstimateStatusChanged::class]);

    $estimate = Estimate::factory()->sent()->create();

    $service = new PlannerAutomationService;
    $service->onEstimateSigned($estimate);

    Event::assertDispatched(EstimateStatusChanged::class, function ($event) {
        $context = $event->getContext();

        return $context['new_status'] === 'accepted';
    });
});

it('dispatches EstimateStatusChanged event when estimate rejected', function () {
    Event::fake([EstimateStatusChanged::class]);

    $estimate = Estimate::factory()->sent()->create();

    $service = new PlannerAutomationService;
    $service->onEstimateRejected($estimate);

    Event::assertDispatched(EstimateStatusChanged::class, function ($event) {
        $context = $event->getContext();

        return $context['new_status'] === 'rejected';
    });
});

it('dispatches JobStageChanged event when job created', function () {
    Event::fake([JobStageChanged::class]);

    $job = Job::factory()->create();

    $service = new PlannerAutomationService;
    $service->onJobCreated($job);

    Event::assertDispatched(JobStageChanged::class, function ($event) use ($job) {
        return $event->getSubject()->is($job);
    });
});

it('dispatches JobStageChanged event when job completed', function () {
    Event::fake([JobStageChanged::class]);

    $job = Job::factory()->inProgress()->create();

    $service = new PlannerAutomationService;
    $service->onJobCompleted($job);

    Event::assertDispatched(JobStageChanged::class, function ($event) {
        $context = $event->getContext();

        return $context['new_stage'] === 'completed';
    });
});
