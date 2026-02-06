<?php

use App\Models\Job;
use App\Models\User;
use App\Services\JobDispatchService;
use Livewire\Livewire;

describe('Crew Dashboard', function () {
    it('renders with job counts', function () {
        $crew = User::factory()->crew()->create();

        Livewire::actingAs($crew, 'crew')
            ->test('crew.dashboard')
            ->assertStatus(200)
            ->assertSee('Crew Dashboard')
            ->assertSee('Available Jobs')
            ->assertSee('Active Jobs');
    });

    it('shows correct available job count', function () {
        $crew = User::factory()->crew()->create();
        $job = Job::factory()->create();

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.dashboard')
            ->assertSee('1');
    });
});

describe('Crew Available Jobs', function () {
    it('renders available jobs page', function () {
        $crew = User::factory()->crew()->create();

        Livewire::actingAs($crew, 'crew')
            ->test('crew.available-jobs')
            ->assertStatus(200)
            ->assertSee('Available Jobs');
    });

    it('shows dispatched jobs', function () {
        $crew = User::factory()->crew()->create();
        $job = Job::factory()->create(['title' => 'Kitchen Repaint']);

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.available-jobs')
            ->assertSee('Kitchen Repaint')
            ->assertSee($job->job_number);
    });

    it('does not show jobs dispatched to other crews', function () {
        $crew = User::factory()->crew()->create();
        $otherCrew = User::factory()->crew()->create();
        $job = Job::factory()->create(['title' => 'Not For Me']);

        app(JobDispatchService::class)->dispatchToCrews($job, [$otherCrew->id]);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.available-jobs')
            ->assertDontSee('Not For Me');
    });

    it('can accept a job from the list', function () {
        $crew = User::factory()->crew()->create();
        $job = Job::factory()->create();

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.available-jobs')
            ->call('acceptJob', $job->id)
            ->assertSee('Job accepted');

        $pivot = $job->crews()->where('crew_user_id', $crew->id)->first()->pivot;
        expect($pivot->status)->toBe('accepted');
    });

    it('can reject a job from the list', function () {
        $crew = User::factory()->crew()->create();
        $job = Job::factory()->create();

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.available-jobs')
            ->call('rejectJob', $job->id)
            ->assertSee('Job declined');
    });

    it('shows empty state when no available jobs', function () {
        $crew = User::factory()->crew()->create();

        Livewire::actingAs($crew, 'crew')
            ->test('crew.available-jobs')
            ->assertSee('No Available Jobs');
    });
});

describe('Crew Job Show', function () {
    it('renders job details for dispatched crew', function () {
        $crew = User::factory()->crew()->create();
        $job = Job::factory()->create(['title' => 'Bedroom Paint']);

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->assertStatus(200)
            ->assertSee('Bedroom Paint')
            ->assertSee($job->job_number)
            ->assertSee('Work Order');
    });

    it('denies access to crew not dispatched to this job', function () {
        $crew = User::factory()->crew()->create();
        $job = Job::factory()->create();

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->assertNotFound();
    });

    it('shows action required for dispatched status', function () {
        $crew = User::factory()->crew()->create();
        $job = Job::factory()->create();

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->assertSee('Review')
            ->assertSee('Accept This Job')
            ->assertSee('Accept Job');
    });

    it('shows accepted status after accepting', function () {
        $crew = User::factory()->crew()->create();
        $job = Job::factory()->create();

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->call('acceptJob')
            ->assertSee('Job accepted');
    });

    it('shows time estimate when estimate exists', function () {
        $crew = User::factory()->crew()->create();
        $estimate = \App\Models\Estimate::factory()->create(['total' => 5000.00]);
        $job = Job::factory()->create(['estimate_id' => $estimate->id]);

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->assertSee('Time Estimate')
            ->assertSee('Total Hours')
            ->assertSee('Estimated Duration');
    });

    it('shows paint colors on colors tab', function () {
        $crew = User::factory()->crew()->create();
        $job = Job::factory()->create();
        \App\Models\JobColor::factory()->create([
            'job_id' => $job->id,
            'name' => 'Repose Gray',
            'brand' => 'Sherwin-Williams',
        ]);

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->call('setStage', 'colors')
            ->assertSee('Paint Colors')
            ->assertSee('Repose Gray')
            ->assertSee('Sherwin-Williams');
    });

    it('adds crew to deal chat when accepting job', function () {
        $crew = User::factory()->crew()->create();
        $deal = \App\Models\Deal::factory()->create();
        $chatThread = \App\Models\ChatThread::factory()->create([
            'deal_id' => $deal->id,
        ]);
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);

        // Crew should not be a participant before accepting
        expect($chatThread->hasParticipant($crew))->toBeFalse();

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->call('acceptJob')
            ->assertSee('Job accepted');

        // Crew should now be a participant
        expect($chatThread->fresh()->hasParticipant($crew))->toBeTrue();
    });

    it('posts system message when crew joins chat', function () {
        $crew = User::factory()->crew()->create(['name' => 'Pro Painters LLC']);
        $deal = \App\Models\Deal::factory()->create();
        $chatThread = \App\Models\ChatThread::factory()->create([
            'deal_id' => $deal->id,
        ]);
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->call('acceptJob');

        // Check that a system message was posted
        $systemMessage = $chatThread->messages()->where('type', 'system')->first();
        expect($systemMessage)->not->toBeNull();
        expect($systemMessage->content)->toContain('Pro Painters LLC');
        expect($systemMessage->content)->toContain('joined the conversation');
    });

    it('shows chat button after accepting job', function () {
        $crew = User::factory()->crew()->create();
        $deal = \App\Models\Deal::factory()->create();
        $chatThread = \App\Models\ChatThread::factory()->create([
            'deal_id' => $deal->id,
        ]);
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);
        app(JobDispatchService::class)->acceptJob($job, $crew);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->assertSee('Group Chat');
    });

    it('hides chat button before accepting job', function () {
        $crew = User::factory()->crew()->create();
        $deal = \App\Models\Deal::factory()->create();
        $chatThread = \App\Models\ChatThread::factory()->create([
            'deal_id' => $deal->id,
        ]);
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->assertDontSee('Group Chat');
    });

    it('shows financials tab after accepting job', function () {
        $crew = User::factory()->crew()->create();
        $estimate = \App\Models\Estimate::factory()->create();
        $job = Job::factory()->create(['estimate_id' => $estimate->id]);

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);
        app(JobDispatchService::class)->acceptJob($job, $crew);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->call('setStage', 'financials')
            ->assertSee('For Crew Lead Only')
            ->assertSee('Job Profit Summary');
    });

    it('shows painter pay calculator on financials tab', function () {
        $crew = User::factory()->crew()->create();
        $estimate = \App\Models\Estimate::factory()->create();
        $job = Job::factory()->create(['estimate_id' => $estimate->id]);

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);
        app(JobDispatchService::class)->acceptJob($job, $crew);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->call('setStage', 'financials')
            ->assertSee('Pay Calculator')
            ->assertSee('Base Hourly Wage')
            ->assertSee('Payroll Burden');
    });

    it('shows crew size calculator on financials tab', function () {
        $crew = User::factory()->crew()->create();
        $estimate = \App\Models\Estimate::factory()->create();
        $job = Job::factory()->create(['estimate_id' => $estimate->id]);

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);
        app(JobDispatchService::class)->acceptJob($job, $crew);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->call('setStage', 'financials')
            ->assertSee('Crew Size Calculator')
            ->assertSee('Hours/Day');
    });

    it('can add painters on financials tab', function () {
        $crew = User::factory()->crew()->create();
        $estimate = \App\Models\Estimate::factory()->create();
        $job = Job::factory()->create(['estimate_id' => $estimate->id]);

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);
        app(JobDispatchService::class)->acceptJob($job, $crew);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->call('setStage', 'financials')
            ->assertSet('painters', [])
            ->call('addPainter')
            ->assertCount('painters', 1);
    });

    it('can save actuals on financials tab', function () {
        $crew = User::factory()->crew()->create();
        $estimate = \App\Models\Estimate::factory()->create();
        $job = Job::factory()->create(['estimate_id' => $estimate->id]);

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);
        app(JobDispatchService::class)->acceptJob($job, $crew);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->call('setStage', 'financials')
            ->call('addPainter')
            ->set('painters.0.name', 'John Doe')
            ->set('painters.0.hours', 16)
            ->call('saveActuals')
            ->assertSee('Actuals saved');

        $estimate->refresh();
        expect($estimate->actual_painters)->toHaveCount(1);
        expect($estimate->actual_painters[0]['name'])->toBe('John Doe');
    });

    it('hides financials tab before accepting job', function () {
        $crew = User::factory()->crew()->create();
        $job = Job::factory()->create();

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);

        // Should not be able to access financials before accepting
        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->call('setStage', 'financials')
            ->assertDontSee('Job Profit Summary');
    });
});

describe('Crew My Jobs', function () {
    it('renders my jobs page', function () {
        $crew = User::factory()->crew()->create();

        Livewire::actingAs($crew, 'crew')
            ->test('crew.my-jobs')
            ->assertStatus(200)
            ->assertSee('My Jobs');
    });

    it('shows accepted jobs', function () {
        $crew = User::factory()->crew()->create();
        $job = Job::factory()->create(['title' => 'My Accepted Job']);

        $service = app(JobDispatchService::class);
        $service->dispatchToCrews($job, [$crew->id]);
        $service->acceptJob($job, $crew);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.my-jobs')
            ->assertSee('My Accepted Job');
    });

    it('does not show dispatched but not accepted jobs', function () {
        $crew = User::factory()->crew()->create();
        $job = Job::factory()->create(['title' => 'Still Pending']);

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.my-jobs')
            ->assertDontSee('Still Pending');
    });

    it('shows empty state with no accepted jobs', function () {
        $crew = User::factory()->crew()->create();

        Livewire::actingAs($crew, 'crew')
            ->test('crew.my-jobs')
            ->assertSee('No active jobs');
    });
});
