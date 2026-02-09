<?php

use App\Events\JobAcceptedByCrew;
use App\Models\Estimate;
use App\Models\Job;
use App\Models\User;
use App\Services\JobDispatchService;
use Illuminate\Support\Facades\Event;
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
            ->assertSee('Progress');
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
        app(JobDispatchService::class)->acceptJob($job, $crew);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->call('setTab', 'colors')
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

    it('shows inline chat after accepting job', function () {
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
            ->assertSee('Job Conversation');
    });

    it('hides chat before accepting job', function () {
        $crew = User::factory()->crew()->create();
        $deal = \App\Models\Deal::factory()->create();
        $chatThread = \App\Models\ChatThread::factory()->create([
            'deal_id' => $deal->id,
        ]);
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->assertDontSee('Job Conversation');
    });

    it('shows financials tab after accepting job', function () {
        $crew = User::factory()->crew()->create();
        $estimate = \App\Models\Estimate::factory()->create();
        $job = Job::factory()->create(['estimate_id' => $estimate->id]);

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);
        app(JobDispatchService::class)->acceptJob($job, $crew);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->call('setTab', 'financials')
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
            ->call('setTab', 'financials')
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
            ->call('setTab', 'financials')
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
            ->call('setTab', 'financials')
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
            ->call('setTab', 'financials')
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
            ->call('setTab', 'financials')
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

describe('Crew Painter Breakdown', function () {
    it('allows crew to view painter breakdown for dispatched job', function () {
        $crew = User::factory()->crew()->create();
        $estimate = Estimate::factory()->create();
        $job = Job::factory()->create(['estimate_id' => $estimate->id]);

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-breakdown', ['job' => $job])
            ->assertStatus(200)
            ->assertSee('Pay Breakdown');
    });

    it('denies breakdown access to crew not dispatched to this job', function () {
        $crew = User::factory()->crew()->create();
        $estimate = Estimate::factory()->create();
        $job = Job::factory()->create(['estimate_id' => $estimate->id]);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-breakdown', ['job' => $job])
            ->assertNotFound();
    });

    it('returns 404 when job has no estimate', function () {
        $crew = User::factory()->crew()->create();
        $job = Job::factory()->create(['estimate_id' => null]);

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-breakdown', ['job' => $job])
            ->assertNotFound();
    });

    it('shows breakdown button on job show when estimate exists', function () {
        $crew = User::factory()->crew()->create();
        $estimate = Estimate::factory()->create();
        $job = Job::factory()->create(['estimate_id' => $estimate->id]);

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->assertSee('View Pay Breakdown');
    });
});

describe('Crew Job Acceptance Race Condition', function () {
    it('rejects second crew when first already accepted', function () {
        $crew1 = User::factory()->crew()->create();
        $crew2 = User::factory()->crew()->create();
        $job = Job::factory()->create();

        $service = app(JobDispatchService::class);
        $service->dispatchToCrews($job, [$crew1->id, $crew2->id]);

        Event::fake();

        // First crew accepts
        expect($service->acceptJob($job, $crew1))->toBeTrue();

        // Second crew tries to accept - should fail
        expect($service->acceptJob($job, $crew2))->toBeFalse();
    });

    it('auto-rejects other dispatched crews on acceptance', function () {
        $crew1 = User::factory()->crew()->create();
        $crew2 = User::factory()->crew()->create();
        $crew3 = User::factory()->crew()->create();
        $job = Job::factory()->create();

        $service = app(JobDispatchService::class);
        $service->dispatchToCrews($job, [$crew1->id, $crew2->id, $crew3->id]);

        Event::fake();

        // Crew 1 accepts
        $service->acceptJob($job, $crew1);

        // Crew 2 and 3 should be auto-rejected
        $crew2Pivot = $job->crews()->where('crew_user_id', $crew2->id)->first()->pivot;
        $crew3Pivot = $job->crews()->where('crew_user_id', $crew3->id)->first()->pivot;

        expect($crew2Pivot->status)->toBe('rejected');
        expect($crew3Pivot->status)->toBe('rejected');
        expect($crew2Pivot->notes)->toContain('Auto-rejected');
        expect($crew3Pivot->notes)->toContain('Auto-rejected');
    });

    it('dispatches JobAcceptedByCrew event on acceptance', function () {
        $crew = User::factory()->crew()->create();
        $job = Job::factory()->create();

        $service = app(JobDispatchService::class);
        $service->dispatchToCrews($job, [$crew->id]);

        Event::fake();

        $service->acceptJob($job, $crew);

        Event::assertDispatched(JobAcceptedByCrew::class, function ($event) use ($job, $crew) {
            return $event->job->id === $job->id
                && $event->acceptedBy->id === $crew->id;
        });
    });

    it('shows error when trying to accept already taken job via UI', function () {
        $crew1 = User::factory()->crew()->create();
        $crew2 = User::factory()->crew()->create();
        $job = Job::factory()->create();

        $service = app(JobDispatchService::class);
        $service->dispatchToCrews($job, [$crew1->id, $crew2->id]);

        Event::fake();

        // Crew 1 accepts first
        $service->acceptJob($job, $crew1);

        // Crew 2 tries to accept via the available-jobs UI
        Livewire::actingAs($crew2, 'crew')
            ->test('crew.available-jobs')
            ->call('acceptJob', $job->id)
            ->assertSee('already been accepted');
    });
});

describe('Crew Confirmation Status Display', function () {
    it('shows confirmed badge when company date confirmed', function () {
        $crew = User::factory()->crew()->create();
        $job = Job::factory()->create([
            'stage' => 'scheduling_colors',
            'company_date_confirmed' => true,
            'customer_date_confirmed' => true,
        ]);

        $service = app(JobDispatchService::class);
        $service->dispatchToCrews($job, [$crew->id]);
        $service->acceptJob($job, $crew);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->assertSee('Company Confirmed')
            ->assertSee('Confirmed');
    });

    it('shows pending badge when confirmations are missing', function () {
        $crew = User::factory()->crew()->create();
        $job = Job::factory()->create([
            'stage' => 'scheduling_colors',
            'company_date_confirmed' => false,
            'customer_date_confirmed' => false,
        ]);

        $service = app(JobDispatchService::class);
        $service->dispatchToCrews($job, [$crew->id]);
        $service->acceptJob($job, $crew);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->assertSee('Pending')
            ->assertSee('Waiting for all confirmations');
    });
});

describe('Crew Audio Messaging', function () {
    beforeEach(function () {
        \Illuminate\Support\Facades\Storage::fake('public');
    });

    it('renders crew message composer with audio recording state', function () {
        $crew = User::factory()->crew()->create();
        $deal = \App\Models\Deal::factory()->create();
        $thread = \App\Models\ChatThread::factory()->create(['deal_id' => $deal->id]);
        $thread->addParticipant($crew);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.chat.message-composer', ['thread' => $thread])
            ->assertSet('isRecording', false)
            ->assertSet('isProcessingAudio', false);
    });

    it('crew can send audio message via processRecordedAudio', function () {
        Event::fake([\App\Events\MessageSent::class]);

        $this->mock(\App\Services\PythonBridgeService::class)
            ->shouldReceive('isEnabled')
            ->andReturn(true)
            ->shouldReceive('transcribeAudioFile')
            ->andReturn([
                'success' => true,
                'text' => 'Hello from the crew',
                'duration' => 4.2,
            ]);

        $crew = User::factory()->crew()->create();
        $deal = \App\Models\Deal::factory()->create();
        $thread = \App\Models\ChatThread::factory()->create(['deal_id' => $deal->id]);
        $thread->addParticipant($crew);

        $fakeAudio = base64_encode('fake audio content');

        Livewire::actingAs($crew, 'crew')
            ->test('crew.chat.message-composer', ['thread' => $thread])
            ->call('processRecordedAudio', $fakeAudio)
            ->assertDispatched('message-sent');

        $message = $thread->messages()->where('type', \App\Models\ChatMessage::TYPE_AUDIO)->first();
        expect($message)->not->toBeNull()
            ->and($message->metadata['transcription'])->toBe('Hello from the crew')
            ->and($message->metadata['audio_duration'])->toEqual(4.2)
            ->and($message->is_internal)->toBeFalse();
    });

    it('crew audio messages are always non-internal', function () {
        Event::fake([\App\Events\MessageSent::class]);

        $this->mock(\App\Services\PythonBridgeService::class)
            ->shouldReceive('isEnabled')
            ->andReturn(true)
            ->shouldReceive('transcribeAudioFile')
            ->andReturn([
                'success' => true,
                'text' => 'Test audio',
                'duration' => 2.0,
            ]);

        $crew = User::factory()->crew()->create();
        $deal = \App\Models\Deal::factory()->create();
        $thread = \App\Models\ChatThread::factory()->create(['deal_id' => $deal->id]);
        $thread->addParticipant($crew);

        $fakeAudio = base64_encode('fake audio content');

        Livewire::actingAs($crew, 'crew')
            ->test('crew.chat.message-composer', ['thread' => $thread])
            ->call('processRecordedAudio', $fakeAudio)
            ->assertDispatched('message-sent');

        $message = $thread->messages()->where('type', \App\Models\ChatMessage::TYPE_AUDIO)->first();
        expect($message->is_internal)->toBeFalse();
    });

    it('handles audio processing errors gracefully', function () {
        $this->mock(\App\Services\AudioMessageService::class)
            ->shouldReceive('processBase64Audio')
            ->andThrow(new \Exception('Processing failed'));

        $crew = User::factory()->crew()->create();
        $deal = \App\Models\Deal::factory()->create();
        $thread = \App\Models\ChatThread::factory()->create(['deal_id' => $deal->id]);
        $thread->addParticipant($crew);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.chat.message-composer', ['thread' => $thread])
            ->call('processRecordedAudio', base64_encode('fake'))
            ->assertHasErrors(['audio']);
    });

    it('non-participant crew cannot send audio messages', function () {
        $crew = User::factory()->crew()->create();
        $deal = \App\Models\Deal::factory()->create();
        $thread = \App\Models\ChatThread::factory()->create(['deal_id' => $deal->id]);
        // Crew is NOT added as participant

        Livewire::actingAs($crew, 'crew')
            ->test('crew.chat.message-composer', ['thread' => $thread])
            ->call('processRecordedAudio', base64_encode('fake audio'))
            ->assertHasErrors(['audio']);

        // No audio message should have been created
        $audioMessage = $thread->messages()->where('type', \App\Models\ChatMessage::TYPE_AUDIO)->first();
        expect($audioMessage)->toBeNull();
    });

    it('renders audio messages in embedded job chat', function () {
        $crew = User::factory()->crew()->create();
        $deal = \App\Models\Deal::factory()->create();
        $thread = \App\Models\ChatThread::factory()->create(['deal_id' => $deal->id]);
        $thread->addParticipant($crew);
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        app(JobDispatchService::class)->dispatchToCrews($job, [$crew->id]);
        app(JobDispatchService::class)->acceptJob($job, $crew);

        // Create an audio message in the thread
        $thread->messages()->create([
            'user_id' => $crew->id,
            'content' => 'Transcribed audio text',
            'type' => \App\Models\ChatMessage::TYPE_AUDIO,
            'is_internal' => false,
            'metadata' => [
                'audio_url' => 'http://localhost/storage/chat-audio/test.webm',
                'audio_path' => 'chat-audio/test.webm',
                'audio_duration' => 5.0,
                'transcription' => 'Transcribed audio text',
                'transcription_status' => 'completed',
            ],
        ]);

        Livewire::actingAs($crew, 'crew')
            ->test('crew.job-show', ['job' => $job])
            ->assertSee('Transcribed audio text');
    });
});
