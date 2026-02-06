<?php

use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateSignature;
use App\Models\Job;
use App\Models\JobColor;
use App\Models\User;
use App\Notifications\JobCreated;
use App\Services\JobService;
use Illuminate\Support\Facades\Notification;

it('can create a job with factory', function () {
    $job = Job::factory()->create();

    expect($job)->toBeInstanceOf(Job::class)
        ->and($job->stage)->toBe('estimate_accepted')
        ->and($job->job_number)->toStartWith('JOB-')
        ->and($job->deal)->not->toBeNull();
});

it('generates unique job numbers', function () {
    $job1 = Job::factory()->create();
    $job2 = Job::factory()->create();

    expect($job1->job_number)->not->toBe($job2->job_number);
});

it('has correct stage constants', function () {
    expect(Job::STAGES)->toHaveCount(5)
        ->and(array_keys(Job::STAGES))->toBe([
            'estimate_accepted',
            'scheduling_colors',
            'job_prep',
            'in_progress',
            'completed',
        ]);
});

it('can move to valid stage', function () {
    $job = Job::factory()->create();

    expect($job->moveTo('scheduling_colors'))->toBeTrue()
        ->and($job->fresh()->stage)->toBe('scheduling_colors');
});

it('cannot move to invalid stage', function () {
    $job = Job::factory()->create();

    expect($job->moveTo('invalid_stage'))->toBeFalse()
        ->and($job->fresh()->stage)->toBe('estimate_accepted');
});

it('can advance through stages in order', function () {
    $job = Job::factory()->create();

    expect($job->stage)->toBe('estimate_accepted');

    $job->advanceStage();
    expect($job->fresh()->stage)->toBe('scheduling_colors');

    $job->refresh()->advanceStage();
    expect($job->fresh()->stage)->toBe('job_prep');

    $job->refresh()->advanceStage();
    expect($job->fresh()->stage)->toBe('in_progress');

    $job->refresh()->advanceStage();
    expect($job->fresh()->stage)->toBe('completed');

    // Cannot advance past completed
    expect($job->refresh()->advanceStage())->toBeFalse();
});

it('returns stage number correctly', function () {
    $job = Job::factory()->create(['stage' => 'estimate_accepted']);
    expect($job->stage_number)->toBe(1);

    $job->stage = 'scheduling_colors';
    expect($job->stage_number)->toBe(2);

    $job->stage = 'completed';
    expect($job->stage_number)->toBe(5);
});

it('detects ready for dispatch', function () {
    $job = Job::factory()->create([
        'company_date_confirmed' => false,
        'customer_date_confirmed' => false,
        'company_colors_confirmed' => false,
        'customer_colors_confirmed' => false,
    ]);

    expect($job->isReadyForDispatch())->toBeFalse();

    $job->update([
        'company_date_confirmed' => true,
        'customer_date_confirmed' => true,
        'company_colors_confirmed' => true,
        'customer_colors_confirmed' => true,
    ]);

    expect($job->fresh()->isReadyForDispatch())->toBeTrue();
});

it('has colors relationship', function () {
    $job = Job::factory()->create();
    JobColor::factory()->count(3)->create(['job_id' => $job->id]);

    expect($job->colors)->toHaveCount(3);
});

it('has crews relationship', function () {
    $job = Job::factory()->create();
    $crew = User::factory()->crew()->create();

    $job->crews()->attach($crew->id, [
        'status' => 'dispatched',
        'dispatched_at' => now(),
    ]);

    expect($job->crews)->toHaveCount(1)
        ->and($job->crews->first()->id)->toBe($crew->id)
        ->and($job->crews->first()->pivot->status)->toBe('dispatched');
});

it('can filter accepted crews', function () {
    $job = Job::factory()->create();
    $crew1 = User::factory()->crew()->create();
    $crew2 = User::factory()->crew()->create();

    $job->crews()->attach($crew1->id, ['status' => 'accepted', 'dispatched_at' => now()]);
    $job->crews()->attach($crew2->id, ['status' => 'rejected', 'dispatched_at' => now()]);

    expect($job->acceptedCrew)->toHaveCount(1)
        ->and($job->acceptedCrew->first()->id)->toBe($crew1->id);
});

it('belongs to deal', function () {
    $deal = Deal::factory()->create();
    $job = Job::factory()->create(['deal_id' => $deal->id]);

    expect($job->deal->id)->toBe($deal->id);
});

it('deal has job relationship', function () {
    $deal = Deal::factory()->create();
    $job = Job::factory()->create(['deal_id' => $deal->id]);

    expect($deal->job->id)->toBe($job->id);
});

it('deal has signed_accepted stage', function () {
    expect(Deal::STAGES)->toHaveKey('signed_accepted')
        ->and(Deal::STAGES['signed_accepted'])->toBe('Signed & Accepted');
});

describe('JobService', function () {
    it('creates job from accepted estimate', function () {
        Notification::fake();

        $internalUser = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();
        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'title' => 'Paint Living Room',
        ]);
        $estimate = Estimate::factory()->create([
            'deal_id' => $deal->id,
        ]);

        $service = app(JobService::class);
        $job = $service->createFromEstimate($estimate);

        expect($job)->toBeInstanceOf(Job::class)
            ->and($job->deal_id)->toBe($deal->id)
            ->and($job->estimate_id)->toBe($estimate->id)
            ->and($job->title)->toBe('Paint Living Room')
            ->and($job->stage)->toBe('estimate_accepted')
            ->and($job->job_number)->toStartWith('JOB-');

        Notification::assertSentTo($internalUser, JobCreated::class);
    });

    it('uses company default required crew size when creating job', function () {
        Notification::fake();

        User::factory()->internal()->withFullAccess()->create();
        $settings = CompanySetting::getInstance();
        $settings->update(['default_required_crew_size' => 3]);

        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->create(['deal_id' => $deal->id]);

        $service = app(JobService::class);
        $job = $service->createFromEstimate($estimate);

        expect($job->required_crew_size)->toBe(3);
    });

    it('uses database default crew size of 2 from migration', function () {
        Notification::fake();

        User::factory()->internal()->withFullAccess()->create();
        // Don't modify settings - the migration default is 2
        $settings = CompanySetting::getInstance();
        expect($settings->default_required_crew_size)->toBe(2);

        $deal = Deal::factory()->create();
        $estimate = Estimate::factory()->create(['deal_id' => $deal->id]);

        $service = app(JobService::class);
        $job = $service->createFromEstimate($estimate);

        expect($job->required_crew_size)->toBe(2);
    });

    it('completes a job', function () {
        $deal = Deal::factory()->create(['stage' => 'in_progress']);
        $job = Job::factory()->inProgress()->create(['deal_id' => $deal->id]);

        $service = app(JobService::class);
        $service->completeJob($job);

        expect($job->fresh()->stage)->toBe('completed')
            ->and($job->fresh()->actual_end_date)->not->toBeNull()
            ->and($deal->fresh()->stage)->toBe('completed');
    });

    it('confirms company date', function () {
        $job = Job::factory()->create(['company_date_confirmed' => false]);

        app(JobService::class)->confirmCompanyDate($job);

        expect($job->fresh()->company_date_confirmed)->toBeTrue();
    });

    it('confirms customer date', function () {
        $job = Job::factory()->create(['customer_date_confirmed' => false]);

        app(JobService::class)->confirmCustomerDate($job);

        expect($job->fresh()->customer_date_confirmed)->toBeTrue();
    });

    it('sets allowed colors', function () {
        $job = Job::factory()->create();

        app(JobService::class)->setAllowedColors($job, 3);

        expect($job->fresh()->allowed_colors)->toBe(3);
    });
});

describe('Auto-creation on signature', function () {
    it('creates job when estimate is signed', function () {
        Notification::fake();

        User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();
        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'stage' => 'quoted',
        ]);
        $estimate = Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'sent',
        ]);

        EstimateSignature::createFromRequest(
            $estimate,
            'John Doe',
            'john@example.com',
            'data:image/png;base64,test',
            'drawn'
        );

        // Deal should be signed_accepted
        expect($deal->fresh()->stage)->toBe('signed_accepted');

        // Job should be created
        $job = Job::where('deal_id', $deal->id)->first();
        expect($job)->not->toBeNull()
            ->and($job->estimate_id)->toBe($estimate->id)
            ->and($job->stage)->toBe('estimate_accepted');
    });

    it('estimate has job relationship after signing', function () {
        Notification::fake();

        User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create(['stage' => 'quoted']);
        $estimate = Estimate::factory()->create([
            'deal_id' => $deal->id,
            'status' => 'sent',
        ]);

        EstimateSignature::createFromRequest(
            $estimate,
            'Jane Doe',
            'jane@example.com',
            'data:image/png;base64,test',
            'typed'
        );

        expect($estimate->fresh()->job)->not->toBeNull()
            ->and($estimate->fresh()->job)->toBeInstanceOf(Job::class);
    });
});
