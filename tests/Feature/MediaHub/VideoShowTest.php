<?php

use App\Mail\VideoSharedMail;
use App\Models\User;
use App\Models\VideoChannel;
use App\Models\VideoChannelItem;
use App\Models\VideoEmail;
use App\Models\VideoRecording;
use App\Models\VideoView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

// --- Video Show Page ---

it('renders the video show page with correct title', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $video = VideoRecording::factory()->ready()->create(['title' => 'My Test Video']);

    $this->actingAs($user)
        ->get(route('media.video.show', $video))
        ->assertSuccessful()
        ->assertSee('My Test Video');
});

it('displays section tabs on video show page', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $video = VideoRecording::factory()->ready()->create();

    Livewire::actingAs($user)
        ->test('media.video-show', ['recording' => $video])
        ->assertSee(__('Edit'))
        ->assertSee(__('Analytics'));
});

// --- Video Edit Form ---

it('loads existing video data in edit form', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $video = VideoRecording::factory()->ready()->create([
        'title' => 'Original Title',
        'description' => 'Original description',
        'visibility' => 'public',
    ]);

    Livewire::actingAs($user)
        ->test('media.video-edit-form', ['recordingId' => $video->id])
        ->assertSet('title', 'Original Title')
        ->assertSet('description', 'Original description')
        ->assertSet('visibility', 'public');
});

it('saves changes to video metadata', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $video = VideoRecording::factory()->ready()->create(['title' => 'Old Title']);

    Livewire::actingAs($user)
        ->test('media.video-edit-form', ['recordingId' => $video->id])
        ->set('title', 'New Title')
        ->set('description', 'New description')
        ->set('visibility', 'internal')
        ->call('save');

    $video->refresh();
    expect($video->title)->toBe('New Title')
        ->and($video->description)->toBe('New description')
        ->and($video->visibility)->toBe('internal');
});

it('validates title is required on edit form', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $video = VideoRecording::factory()->ready()->create();

    Livewire::actingAs($user)
        ->test('media.video-edit-form', ['recordingId' => $video->id])
        ->set('title', '')
        ->call('save')
        ->assertHasErrors(['title' => 'required']);
});

it('manages channel assignments via checkboxes', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $video = VideoRecording::factory()->ready()->create();
    $channel = VideoChannel::factory()->create();

    // Initially not assigned
    Livewire::actingAs($user)
        ->test('media.video-edit-form', ['recordingId' => $video->id])
        ->call('toggleChannel', $channel->id)
        ->call('save');

    $this->assertDatabaseHas('video_channel_items', [
        'video_channel_id' => $channel->id,
        'video_recording_id' => $video->id,
    ]);

    // Now unassign
    Livewire::actingAs($user)
        ->test('media.video-edit-form', ['recordingId' => $video->id])
        ->call('toggleChannel', $channel->id)
        ->call('save');

    $this->assertDatabaseMissing('video_channel_items', [
        'video_channel_id' => $channel->id,
        'video_recording_id' => $video->id,
    ]);
});

// --- Video Analytics ---

it('shows correct view count in analytics', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $video = VideoRecording::factory()->ready()->create();
    VideoView::factory()->count(5)->create(['video_recording_id' => $video->id]);

    Livewire::actingAs($user)
        ->test('media.video-analytics', ['recordingId' => $video->id])
        ->assertSee('5');
});

it('shows average watch percentage', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $video = VideoRecording::factory()->ready()->create();
    VideoView::factory()->create(['video_recording_id' => $video->id, 'watch_percentage' => 50]);
    VideoView::factory()->create(['video_recording_id' => $video->id, 'watch_percentage' => 100]);

    Livewire::actingAs($user)
        ->test('media.video-analytics', ['recordingId' => $video->id])
        ->assertSee('75');
});

// --- Video Send Email ---

it('creates a VideoEmail record when sending', function () {
    Mail::fake();

    $user = User::factory()->internal()->withFullAccess()->create();
    $video = VideoRecording::factory()->ready()->create(['title' => 'Demo Video']);

    Livewire::actingAs($user)
        ->test('media.video-send-email', ['recordingId' => $video->id])
        ->set('recipientEmail', 'client@example.com')
        ->set('recipientName', 'John Doe')
        ->set('message', 'Check this out!')
        ->call('sendEmail');

    $this->assertDatabaseHas('video_emails', [
        'video_recording_id' => $video->id,
        'sent_by' => $user->id,
        'recipient_email' => 'client@example.com',
        'recipient_name' => 'John Doe',
        'message' => 'Check this out!',
    ]);

    Mail::assertSent(VideoSharedMail::class, function ($mail) {
        return $mail->hasTo('client@example.com');
    });
});

it('validates required fields on send email', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $video = VideoRecording::factory()->ready()->create();

    Livewire::actingAs($user)
        ->test('media.video-send-email', ['recordingId' => $video->id])
        ->set('recipientEmail', '')
        ->set('subject', '')
        ->call('sendEmail')
        ->assertHasErrors(['recipientEmail', 'subject']);
});

it('pre-fills subject with video title', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $video = VideoRecording::factory()->ready()->create(['title' => 'Project Walkthrough']);

    Livewire::actingAs($user)
        ->test('media.video-send-email', ['recordingId' => $video->id])
        ->assertSet('subject', 'Check out this video: Project Walkthrough');
});

it('shows sent emails history', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $video = VideoRecording::factory()->ready()->create();

    VideoEmail::factory()->create([
        'video_recording_id' => $video->id,
        'sent_by' => $user->id,
        'recipient_email' => 'previous@example.com',
    ]);

    Livewire::actingAs($user)
        ->test('media.video-send-email', ['recordingId' => $video->id])
        ->assertSee('previous@example.com');
});
