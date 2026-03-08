<?php

use App\Jobs\ProcessMeetingAudioJob;
use App\Models\Corporation;
use App\Models\MeetingMinutes;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->internal()->create();
    $this->user->givePermissionTo(['business_plan.view', 'business_plan.manage']);
    $this->actingAs($this->user);

    $this->corporation = Corporation::factory()->create(['is_active' => true]);
});

it('saves with audio and dispatches processing job instead of blocking', function () {
    Storage::fake();
    Queue::fake();

    $audio = UploadedFile::fake()->create('recording.webm', 500, 'audio/webm');

    Livewire::test('corporate.meeting-recorder', ['corporationId' => $this->corporation->id])
        ->set('title', 'Annual Meeting 2026')
        ->set('meeting_date', '2026-03-07')
        ->set('meeting_type', 'annual')
        ->set('audioFile', $audio)
        ->call('saveWithAudio')
        ->assertDispatched('recorder-saved');

    $this->assertDatabaseHas('meeting_minutes', [
        'corporation_id' => $this->corporation->id,
        'title' => 'Annual Meeting 2026',
        'transcript_status' => 'processing',
        'summary_status' => 'pending',
    ]);

    Queue::assertPushed(ProcessMeetingAudioJob::class, function ($job) {
        return $job->minutes->title === 'Annual Meeting 2026'
            && $job->speakerName === $this->user->name;
    });
});

it('saves without audio with no transcript status', function () {
    Livewire::test('corporate.meeting-recorder', ['corporationId' => $this->corporation->id])
        ->set('title', 'Quick Board Meeting')
        ->set('meeting_date', '2026-03-07')
        ->set('meeting_type', 'board')
        ->call('saveWithoutAudio')
        ->assertDispatched('recorder-saved');

    $this->assertDatabaseHas('meeting_minutes', [
        'corporation_id' => $this->corporation->id,
        'title' => 'Quick Board Meeting',
        'transcript_status' => 'none',
    ]);
});

it('stores audio file to pending location before dispatching job', function () {
    Storage::fake();
    Queue::fake();

    $audio = UploadedFile::fake()->create('recording.webm', 500, 'audio/webm');

    Livewire::test('corporate.meeting-recorder', ['corporationId' => $this->corporation->id])
        ->set('title', 'Test Meeting')
        ->set('meeting_date', '2026-03-07')
        ->set('meeting_type', 'annual')
        ->set('audioFile', $audio)
        ->call('saveWithAudio');

    Queue::assertPushed(ProcessMeetingAudioJob::class, function ($job) {
        return str_starts_with($job->tempAudioPath, 'meeting-audio-pending/');
    });
});

it('shows processing status in the minutes list', function () {
    $processing = MeetingMinutes::factory()
        ->for($this->corporation)
        ->transcribing()
        ->create(['title' => 'Processing Meeting']);

    Livewire::test('corporate.meeting-minutes-list', ['corporationId' => $this->corporation->id])
        ->assertSee('Processing Meeting')
        ->assertSee('Transcribing Audio');
});

it('shows completed status badges after processing finishes', function () {
    $completed = MeetingMinutes::factory()
        ->for($this->corporation)
        ->withAudio()
        ->withTranscript()
        ->withSummary()
        ->create(['title' => 'Completed Meeting']);

    Livewire::test('corporate.meeting-minutes-list', ['corporationId' => $this->corporation->id])
        ->assertSee('Completed Meeting')
        ->assertSee('Audio')
        ->assertSee('Transcript')
        ->assertSee('Summary');
});

it('shows transcription banner in editor when processing', function () {
    $processing = MeetingMinutes::factory()
        ->for($this->corporation)
        ->transcribing()
        ->create();

    Livewire::test('corporate.meeting-editor', ['minutesId' => $processing->id])
        ->assertSee('Transcribing audio...');
});

it('does not show transcription banner when completed', function () {
    $completed = MeetingMinutes::factory()
        ->for($this->corporation)
        ->withAudio()
        ->withTranscript()
        ->create();

    Livewire::test('corporate.meeting-editor', ['minutesId' => $completed->id])
        ->assertDontSee('Transcribing audio...');
});
