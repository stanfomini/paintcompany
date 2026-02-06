<?php

use App\Models\ChatThread;
use App\Models\Deal;
use App\Models\Job;
use App\Models\JobActivity;
use App\Models\User;
use Livewire\Livewire;

describe('Job Show - Deal Conversation', function () {
    it('displays deal conversation section when deal has chat thread', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        ChatThread::factory()->create([
            'deal_id' => $deal->id,
            'type' => ChatThread::TYPE_DEAL,
            'subject' => $deal->title,
        ]);
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        Livewire::actingAs($user)
            ->test('jobs.show', ['job' => $job])
            ->assertStatus(200)
            ->assertSee('Deal Conversation');
    });

    it('auto-creates chat thread and adds internal user as participant', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        // Confirm no thread exists initially
        expect($deal->chatThread)->toBeNull();

        Livewire::actingAs($user)
            ->test('jobs.show', ['job' => $job])
            ->assertStatus(200);

        // Thread should now exist on the deal
        $deal->refresh();
        $thread = $deal->chatThread;
        expect($thread)->not->toBeNull()
            ->and($thread->type)->toBe(ChatThread::TYPE_DEAL)
            ->and($thread->hasParticipant($user))->toBeTrue();
    });

    it('adds current user as participant to existing thread', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $thread = ChatThread::factory()->create([
            'deal_id' => $deal->id,
            'type' => ChatThread::TYPE_DEAL,
            'subject' => $deal->title,
        ]);
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        // User is not yet a participant
        expect($thread->hasParticipant($user))->toBeFalse();

        Livewire::actingAs($user)
            ->test('jobs.show', ['job' => $job])
            ->assertStatus(200);

        // After mount, user should be a participant
        expect($thread->fresh()->hasParticipant($user))->toBeTrue();
    });

    it('renders chat thread-view as nested component', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        ChatThread::factory()->create([
            'deal_id' => $deal->id,
            'type' => ChatThread::TYPE_DEAL,
            'subject' => $deal->title,
        ]);
        $job = Job::factory()->create(['deal_id' => $deal->id]);

        Livewire::actingAs($user)
            ->test('jobs.show', ['job' => $job])
            ->assertSeeLivewire('chat.thread-view');
    });
});

describe('Job Show - Activity History', function () {
    it('displays activity history section', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->create();

        JobActivity::factory()->forJob($job)->create([
            'activity_type' => 'stage_changed',
            'description' => 'Job created from accepted estimate',
        ]);
        JobActivity::factory()->forJob($job)->create([
            'activity_type' => 'date_proposed',
            'description' => 'Date proposed for the project',
        ]);

        Livewire::actingAs($user)
            ->test('jobs.show', ['job' => $job])
            ->assertStatus(200)
            ->assertSee('Activity History');
    });

    it('does not show activity history when no activities exist', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $job = Job::factory()->create();

        // Ensure no activities exist for this job
        expect(JobActivity::where('job_id', $job->id)->count())->toBe(0);

        Livewire::actingAs($user)
            ->test('jobs.show', ['job' => $job])
            ->assertStatus(200)
            ->assertDontSee('Activity History');
    });
});
