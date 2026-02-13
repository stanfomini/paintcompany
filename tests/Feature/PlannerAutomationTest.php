<?php

use App\Models\Activity;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\Job;
use App\Models\User;
use App\Services\JobService;
use App\Services\PlannerAutomationService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    Event::fake();
    Notification::fake();
    $this->service = app(PlannerAutomationService::class);
});

// ========================================
// Job Lifecycle
// ========================================

describe('Job lifecycle automation', function () {
    it('creates scheduling task when job is created', function () {
        $user = User::factory()->internal()->create();
        $customer = Customer::factory()->create(['first_name' => 'John', 'last_name' => 'Smith']);
        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'assigned_to' => $user->id,
        ]);
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        $this->service->onJobCreated($job);

        $activity = Activity::where('automation_key', 'job_scheduling_needed')
            ->where('subject_type', Job::class)
            ->where('subject_id', $job->id)
            ->first();

        expect($activity)->not->toBeNull()
            ->and($activity->type)->toBe('task')
            ->and($activity->title)->toContain('John Smith')
            ->and($activity->assigned_to)->toBe($user->id)
            ->and($activity->is_completed)->toBeFalse()
            ->and($activity->due_at)->not->toBeNull();
    });

    it('deduplicates scheduling task for same job', function () {
        $job = Job::factory()->create();

        $this->service->onJobCreated($job);
        $this->service->onJobCreated($job);

        $count = Activity::where('automation_key', 'job_scheduling_needed')
            ->where('subject_type', Job::class)
            ->where('subject_id', $job->id)
            ->count();

        expect($count)->toBe(1);
    });

    it('creates review task when customer proposes date', function () {
        $user = User::factory()->internal()->create();
        $customer = Customer::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);
        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'assigned_to' => $user->id,
        ]);
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        $this->service->onCustomerProposedDate($job, 'Feb 15, 2026');

        $activity = Activity::where('automation_key', 'review_proposed_date')
            ->where('subject_id', $job->id)
            ->first();

        expect($activity)->not->toBeNull()
            ->and($activity->type)->toBe('task')
            ->and($activity->title)->toContain('Jane Doe')
            ->and($activity->title)->toContain('Feb 15, 2026')
            ->and($activity->is_completed)->toBeFalse();
    });

    it('creates info note when company confirms date', function () {
        $job = Job::factory()->create();

        $this->service->onCompanyDateConfirmed($job);

        $activity = Activity::where('automation_key', 'company_confirmed_date')
            ->where('subject_id', $job->id)
            ->first();

        expect($activity)->not->toBeNull()
            ->and($activity->type)->toBe('note')
            ->and($activity->is_completed)->toBeTrue()
            ->and($activity->completed_at)->not->toBeNull();
    });

    it('creates info note when customer confirms date', function () {
        $job = Job::factory()->create();

        $this->service->onCustomerDateConfirmed($job);

        $activity = Activity::where('automation_key', 'customer_confirmed_date')
            ->where('subject_id', $job->id)
            ->first();

        expect($activity)->not->toBeNull()
            ->and($activity->type)->toBe('note')
            ->and($activity->is_completed)->toBeTrue();
    });

    it('creates info note when company confirms colors', function () {
        $job = Job::factory()->create();

        $this->service->onCompanyColorsConfirmed($job);

        $activity = Activity::where('automation_key', 'company_confirmed_colors')
            ->where('subject_id', $job->id)
            ->first();

        expect($activity)->not->toBeNull()
            ->and($activity->type)->toBe('note')
            ->and($activity->is_completed)->toBeTrue();
    });

    it('creates info note when customer confirms colors', function () {
        $job = Job::factory()->create();

        $this->service->onCustomerColorsConfirmed($job);

        $activity = Activity::where('automation_key', 'customer_confirmed_colors')
            ->where('subject_id', $job->id)
            ->first();

        expect($activity)->not->toBeNull()
            ->and($activity->type)->toBe('note')
            ->and($activity->is_completed)->toBeTrue();
    });

    it('creates dispatch task when all confirmations are done', function () {
        $job = Job::factory()->create([
            'company_date_confirmed' => true,
            'customer_date_confirmed' => true,
            'company_colors_confirmed' => true,
            'customer_colors_confirmed' => false,
        ]);

        // Create the scheduling task first
        $this->service->onJobCreated($job);

        // Last confirmation triggers ready-for-dispatch
        $job->update(['customer_colors_confirmed' => true]);
        $this->service->onCustomerColorsConfirmed($job);

        // Scheduling task should be auto-completed
        $schedulingTask = Activity::where('automation_key', 'job_scheduling_needed')
            ->where('subject_id', $job->id)
            ->first();
        expect($schedulingTask->is_completed)->toBeTrue();

        // Dispatch task should be created
        $dispatchTask = Activity::where('automation_key', 'ready_for_dispatch')
            ->where('subject_id', $job->id)
            ->first();
        expect($dispatchTask)->not->toBeNull()
            ->and($dispatchTask->type)->toBe('task')
            ->and($dispatchTask->is_completed)->toBeFalse();
    });

    it('does not create dispatch task when not all confirmations done', function () {
        $job = Job::factory()->create([
            'company_date_confirmed' => true,
            'customer_date_confirmed' => false,
            'company_colors_confirmed' => true,
            'customer_colors_confirmed' => false,
        ]);

        $this->service->onCustomerColorsConfirmed($job);

        $dispatchTask = Activity::where('automation_key', 'ready_for_dispatch')
            ->where('subject_id', $job->id)
            ->first();

        expect($dispatchTask)->toBeNull();
    });

    it('auto-completes proposed date review when company confirms date', function () {
        $job = Job::factory()->create();

        $this->service->onCustomerProposedDate($job, 'Feb 15, 2026');
        $this->service->onCompanyDateConfirmed($job);

        $reviewTask = Activity::where('automation_key', 'review_proposed_date')
            ->where('subject_id', $job->id)
            ->first();

        expect($reviewTask->is_completed)->toBeTrue();
    });
});

describe('Crew dispatch automation', function () {
    it('creates awaiting response task when crew dispatched', function () {
        $job = Job::factory()->create(['title' => 'Paint Kitchen']);

        $this->service->onCrewDispatched($job, 2);

        $activity = Activity::where('automation_key', 'await_crew_response')
            ->where('subject_id', $job->id)
            ->first();

        expect($activity)->not->toBeNull()
            ->and($activity->type)->toBe('task')
            ->and($activity->title)->toContain('Paint Kitchen')
            ->and($activity->is_completed)->toBeFalse();
    });

    it('auto-completes dispatch task when crew dispatched', function () {
        $job = Job::factory()->create();

        // Create dispatch readiness task
        $this->service->onJobCreated($job);
        $job->update([
            'company_date_confirmed' => true,
            'customer_date_confirmed' => true,
            'company_colors_confirmed' => true,
            'customer_colors_confirmed' => true,
        ]);
        $this->service->onCustomerColorsConfirmed($job);

        // Now dispatch
        $this->service->onCrewDispatched($job, 1);

        $dispatchTask = Activity::where('automation_key', 'ready_for_dispatch')
            ->where('subject_id', $job->id)
            ->first();

        expect($dispatchTask->is_completed)->toBeTrue();
    });

    it('creates info note and auto-completes await when crew accepts', function () {
        $crew = User::factory()->crew()->create(['name' => 'Bob Builder']);
        $job = Job::factory()->create(['title' => 'Paint Kitchen']);

        $this->service->onCrewDispatched($job, 1);
        $this->service->onCrewAccepted($job, $crew);

        // Note should exist
        $note = Activity::where('automation_key', 'crew_accepted')
            ->where('subject_id', $job->id)
            ->first();
        expect($note)->not->toBeNull()
            ->and($note->title)->toContain('Bob Builder')
            ->and($note->is_completed)->toBeTrue();

        // Await task should be auto-completed
        $awaitTask = Activity::where('automation_key', 'await_crew_response')
            ->where('subject_id', $job->id)
            ->first();
        expect($awaitTask->is_completed)->toBeTrue();
    });

    it('creates info note when crew declines', function () {
        $crew = User::factory()->crew()->create(['name' => 'Busy Bob']);
        $job = Job::factory()->create(['title' => 'Paint Kitchen']);

        $this->service->onCrewRejected($job, $crew);

        $note = Activity::where('automation_key', 'crew_declined')
            ->where('subject_id', $job->id)
            ->first();

        expect($note)->not->toBeNull()
            ->and($note->title)->toContain('Busy Bob')
            ->and($note->is_completed)->toBeTrue();
    });
});

describe('Job completion automation', function () {
    it('creates review task and cleans up on job completion', function () {
        $job = Job::factory()->create(['title' => 'Paint Kitchen']);

        // Create some open tasks
        $this->service->onJobCreated($job);
        $this->service->onCrewDispatched($job, 1);

        // Complete the job
        $this->service->onJobCompleted($job);

        // Review task should exist
        $reviewTask = Activity::where('automation_key', 'job_completed_review')
            ->where('subject_id', $job->id)
            ->first();
        expect($reviewTask)->not->toBeNull()
            ->and($reviewTask->type)->toBe('task')
            ->and($reviewTask->title)->toContain('Paint Kitchen')
            ->and($reviewTask->is_completed)->toBeFalse();

        // All previous open tasks should be completed
        $openTasks = Activity::where('subject_type', Job::class)
            ->where('subject_id', $job->id)
            ->where('automation_key', '!=', 'job_completed_review')
            ->where('type', 'task')
            ->incomplete()
            ->count();
        expect($openTasks)->toBe(0);
    });
});

// ========================================
// Estimate Lifecycle
// ========================================

describe('Estimate lifecycle automation', function () {
    it('creates follow-up task when estimate sent', function () {
        $user = User::factory()->internal()->create();
        $customer = Customer::factory()->create(['first_name' => 'Alice', 'last_name' => 'Jones']);
        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'assigned_to' => $user->id,
        ]);
        $estimate = Estimate::factory()->create([
            'deal_id' => $deal->id,
            'created_by' => $user->id,
        ]);

        $this->service->onEstimateSent($estimate);

        $activity = Activity::where('automation_key', 'estimate_sent_followup')
            ->where('subject_type', Estimate::class)
            ->where('subject_id', $estimate->id)
            ->first();

        expect($activity)->not->toBeNull()
            ->and($activity->type)->toBe('task')
            ->and($activity->title)->toContain('Alice Jones')
            ->and($activity->assigned_to)->toBe($user->id)
            ->and($activity->is_completed)->toBeFalse()
            ->and($activity->due_at->diffInDays(now()))->toBeLessThanOrEqual(4);
    });

    it('auto-completes follow-up when estimate signed', function () {
        $estimate = Estimate::factory()->create();

        $this->service->onEstimateSent($estimate);
        $this->service->onEstimateSigned($estimate);

        $followUp = Activity::where('automation_key', 'estimate_sent_followup')
            ->where('subject_id', $estimate->id)
            ->first();
        expect($followUp->is_completed)->toBeTrue();

        $signedNote = Activity::where('automation_key', 'estimate_signed')
            ->where('subject_id', $estimate->id)
            ->first();
        expect($signedNote)->not->toBeNull()
            ->and($signedNote->type)->toBe('note')
            ->and($signedNote->is_completed)->toBeTrue();
    });

    it('auto-completes follow-up when estimate rejected', function () {
        $estimate = Estimate::factory()->create();

        $this->service->onEstimateSent($estimate);
        $this->service->onEstimateRejected($estimate);

        $followUp = Activity::where('automation_key', 'estimate_sent_followup')
            ->where('subject_id', $estimate->id)
            ->first();
        expect($followUp->is_completed)->toBeTrue();

        $rejectedNote = Activity::where('automation_key', 'estimate_rejected')
            ->where('subject_id', $estimate->id)
            ->first();
        expect($rejectedNote)->not->toBeNull()
            ->and($rejectedNote->type)->toBe('note')
            ->and($rejectedNote->is_completed)->toBeTrue();
    });

    it('deduplicates estimate follow-up task', function () {
        $estimate = Estimate::factory()->create();

        $this->service->onEstimateSent($estimate);
        $this->service->onEstimateSent($estimate);

        $count = Activity::where('automation_key', 'estimate_sent_followup')
            ->where('subject_id', $estimate->id)
            ->count();

        expect($count)->toBe(1);
    });
});

// ========================================
// Assignment Routing
// ========================================

describe('Assignment routing', function () {
    it('assigns job tasks to deal assigned_to user', function () {
        $assignee = User::factory()->internal()->create();
        $creator = User::factory()->internal()->create();
        $deal = Deal::factory()->create([
            'assigned_to' => $assignee->id,
            'created_by' => $creator->id,
        ]);
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        $this->service->onJobCreated($job);

        $activity = Activity::where('automation_key', 'job_scheduling_needed')
            ->where('subject_id', $job->id)
            ->first();

        expect($activity->assigned_to)->toBe($assignee->id);
    });

    it('falls back to deal created_by when no assigned_to', function () {
        $creator = User::factory()->internal()->create();
        $deal = Deal::factory()->create([
            'assigned_to' => null,
            'created_by' => $creator->id,
        ]);
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        $this->service->onJobCreated($job);

        $activity = Activity::where('automation_key', 'job_scheduling_needed')
            ->where('subject_id', $job->id)
            ->first();

        expect($activity->assigned_to)->toBe($creator->id);
    });
});

// ========================================
// Integration with existing services
// ========================================

describe('Integration with JobService', function () {
    it('creates planner activity when job service creates job from estimate', function () {
        $user = User::factory()->internal()->create();
        $this->actingAs($user);

        $customer = Customer::factory()->create();
        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'assigned_to' => $user->id,
        ]);
        $estimate = Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'accepted',
        ]);

        $service = app(JobService::class);
        $job = $service->createFromEstimate($estimate);

        $activity = Activity::where('automation_key', 'job_scheduling_needed')
            ->where('subject_type', Job::class)
            ->where('subject_id', $job->id)
            ->first();

        expect($activity)->not->toBeNull();
    });

    it('creates planner activities through job service confirmation flow', function () {
        $user = User::factory()->internal()->create();
        $this->actingAs($user);

        $deal = Deal::factory()->create(['assigned_to' => $user->id]);
        $job = Job::factory()->create([
            'deal_id' => $deal->id,
            'scheduled_date' => now()->addWeek(),
        ]);

        $service = app(JobService::class);
        $service->confirmCompanyDate($job);

        $note = Activity::where('automation_key', 'company_confirmed_date')
            ->where('subject_id', $job->id)
            ->first();

        expect($note)->not->toBeNull()
            ->and($note->is_completed)->toBeTrue();
    });
});

describe('Integration with Estimate model', function () {
    it('creates follow-up when estimate is marked as sent', function () {
        $user = User::factory()->internal()->create();
        $this->actingAs($user);

        $deal = Deal::factory()->create(['assigned_to' => $user->id]);
        $estimate = Estimate::factory()->create([
            'deal_id' => $deal->id,
            'created_by' => $user->id,
        ]);

        $estimate->markAsSent();

        $activity = Activity::where('automation_key', 'estimate_sent_followup')
            ->where('subject_type', Estimate::class)
            ->where('subject_id', $estimate->id)
            ->first();

        expect($activity)->not->toBeNull()
            ->and($activity->type)->toBe('task');
    });

    it('auto-completes follow-up when estimate is accepted', function () {
        $user = User::factory()->internal()->create();
        $this->actingAs($user);

        $deal = Deal::factory()->create(['assigned_to' => $user->id]);
        $estimate = Estimate::factory()->create([
            'deal_id' => $deal->id,
            'created_by' => $user->id,
        ]);

        $estimate->markAsSent();
        $estimate->markAsAccepted(force: true);

        $followUp = Activity::where('automation_key', 'estimate_sent_followup')
            ->where('subject_id', $estimate->id)
            ->first();

        expect($followUp->is_completed)->toBeTrue();
    });

    it('auto-completes follow-up when estimate is rejected', function () {
        $user = User::factory()->internal()->create();
        $this->actingAs($user);

        $deal = Deal::factory()->create(['assigned_to' => $user->id]);
        $estimate = Estimate::factory()->create([
            'deal_id' => $deal->id,
            'created_by' => $user->id,
            'status' => 'sent',
            'sent_at' => now(),
        ]);

        // Create the follow-up first
        $this->service->onEstimateSent($estimate);

        $estimate->markAsRejected();

        $followUp = Activity::where('automation_key', 'estimate_sent_followup')
            ->where('subject_id', $estimate->id)
            ->first();

        expect($followUp->is_completed)->toBeTrue();
    });
});

// ========================================
// Activity Model Scopes
// ========================================

describe('Activity model scopes', function () {
    it('can scope by automation key', function () {
        Activity::factory()->create(['automation_key' => 'test_key_1']);
        Activity::factory()->create(['automation_key' => 'test_key_2']);
        Activity::factory()->create(['automation_key' => null]);

        $results = Activity::byAutomationKey('test_key_1')->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->automation_key)->toBe('test_key_1');
    });

    it('can scope by subject', function () {
        $job = Job::factory()->create();
        $otherJob = Job::factory()->create();

        Activity::factory()->forJob($job)->create();
        Activity::factory()->forJob($job)->create();
        Activity::factory()->forJob($otherJob)->create();

        $results = Activity::forSubject($job)->get();

        expect($results)->toHaveCount(2);
    });
});

describe('Activity model User subject', function () {
    it('getSubjectUrl returns agents.show route for agent User', function () {
        $agent = User::factory()->agent()->create();

        $activity = Activity::factory()->forUser($agent)->create();

        expect($activity->getSubjectUrl())->toBe(route('agents.show', $agent->id));
    });

    it('getSubjectUrl returns crews.show route for crew User', function () {
        $crew = User::factory()->crew()->create();

        $activity = Activity::factory()->forUser($crew)->create();

        expect($activity->getSubjectUrl())->toBe(route('crews.show', $crew->id));
    });

    it('getSubjectUrl returns null for internal User', function () {
        $internal = User::factory()->internal()->create();

        $activity = Activity::factory()->forUser($internal)->create();

        expect($activity->getSubjectUrl())->toBeNull();
    });

    it('getSubjectLabel returns user name', function () {
        $agent = User::factory()->agent()->create(['name' => 'Jane Smith']);

        $activity = Activity::factory()->forUser($agent)->create();

        expect($activity->getSubjectLabel())->toBe('Jane Smith');
    });

    it('forUser factory state sets correct subject_type and subject_id', function () {
        $agent = User::factory()->agent()->create();

        $activity = Activity::factory()->forUser($agent)->create();

        expect($activity->subject_type)->toBe(User::class)
            ->and($activity->subject_id)->toBe($agent->id);
    });
});
