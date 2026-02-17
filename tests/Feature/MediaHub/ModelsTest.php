<?php

use App\Models\TrainingPath;
use App\Models\TrainingPathStep;
use App\Models\TrainingProgress;
use App\Models\User;
use App\Models\VideoChannel;
use App\Models\VideoChannelItem;
use App\Models\VideoEmail;
use App\Models\VideoRecording;
use App\Models\VideoView;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// --- VideoChannel ---

it('auto-generates slug from name on creation', function () {
    $channel = VideoChannel::factory()->create(['name' => 'Our Best Work']);

    expect($channel->slug)->toBe('our-best-work');
});

it('generates unique slugs by appending numbers', function () {
    VideoChannel::factory()->create(['name' => 'How To Paint']);
    $second = VideoChannel::factory()->create(['name' => 'How To Paint']);
    $third = VideoChannel::factory()->create(['name' => 'How To Paint']);

    expect($second->slug)->toBe('how-to-paint-2')
        ->and($third->slug)->toBe('how-to-paint-3');
});

it('does not overwrite an explicitly set slug', function () {
    $channel = VideoChannel::factory()->create([
        'name' => 'Custom Slug Channel',
        'slug' => 'my-custom-slug',
    ]);

    expect($channel->slug)->toBe('my-custom-slug');
});

it('scopes public channels', function () {
    VideoChannel::factory()->public()->create();
    VideoChannel::factory()->private()->create();

    expect(VideoChannel::public()->count())->toBe(1);
});

it('scopes active channels', function () {
    VideoChannel::factory()->create();
    VideoChannel::factory()->inactive()->create();

    expect(VideoChannel::active()->count())->toBe(1);
});

it('has a user relationship', function () {
    $user = User::factory()->create();
    $channel = VideoChannel::factory()->create(['user_id' => $user->id]);

    expect($channel->user->id)->toBe($user->id);
});

// --- VideoChannelItem ---

it('belongs to a channel and video', function () {
    $channel = VideoChannel::factory()->create();
    $video = VideoRecording::factory()->ready()->create();

    $item = VideoChannelItem::factory()->create([
        'video_channel_id' => $channel->id,
        'video_recording_id' => $video->id,
    ]);

    expect($item->channel->id)->toBe($channel->id)
        ->and($item->video->id)->toBe($video->id);
});

it('scopes published items', function () {
    $channel = VideoChannel::factory()->create();
    $video = VideoRecording::factory()->ready()->create();

    VideoChannelItem::factory()->published()->create([
        'video_channel_id' => $channel->id,
        'video_recording_id' => $video->id,
    ]);

    $unpublishedVideo = VideoRecording::factory()->ready()->create();
    VideoChannelItem::factory()->create([
        'video_channel_id' => $channel->id,
        'video_recording_id' => $unpublishedVideo->id,
        'published_at' => null,
    ]);

    expect(VideoChannelItem::published()->count())->toBe(1);
});

// --- VideoRecording channels relationship ---

it('allows a video to belong to multiple channels', function () {
    $video = VideoRecording::factory()->ready()->create();
    $channelA = VideoChannel::factory()->create();
    $channelB = VideoChannel::factory()->create();

    VideoChannelItem::factory()->create([
        'video_channel_id' => $channelA->id,
        'video_recording_id' => $video->id,
    ]);
    VideoChannelItem::factory()->create([
        'video_channel_id' => $channelB->id,
        'video_recording_id' => $video->id,
    ]);

    expect($video->channels)->toHaveCount(2);
});

it('prevents duplicate video in the same channel', function () {
    $channel = VideoChannel::factory()->create();
    $video = VideoRecording::factory()->ready()->create();

    VideoChannelItem::factory()->create([
        'video_channel_id' => $channel->id,
        'video_recording_id' => $video->id,
    ]);

    expect(fn () => VideoChannelItem::factory()->create([
        'video_channel_id' => $channel->id,
        'video_recording_id' => $video->id,
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('accesses videos through channel items', function () {
    $channel = VideoChannel::factory()->create();
    $video = VideoRecording::factory()->ready()->create();

    VideoChannelItem::factory()->create([
        'video_channel_id' => $channel->id,
        'video_recording_id' => $video->id,
    ]);

    expect($channel->videos)->toHaveCount(1)
        ->and($channel->videos->first()->id)->toBe($video->id);
});

// --- VideoRecording scopes ---

it('scopes public visible videos', function () {
    VideoRecording::factory()->public()->create();
    VideoRecording::factory()->internal()->create();
    VideoRecording::factory()->ready()->create(); // default unlisted

    expect(VideoRecording::publicVisible()->count())->toBe(1);
});

it('scopes internal videos', function () {
    VideoRecording::factory()->public()->create();
    VideoRecording::factory()->internal()->create();

    expect(VideoRecording::internal()->count())->toBe(1);
});

it('scopes unlisted videos', function () {
    VideoRecording::factory()->ready()->create(); // default unlisted
    VideoRecording::factory()->public()->create();

    expect(VideoRecording::unlisted()->count())->toBe(1);
});

// --- VideoView ---

it('tracks watch percentage', function () {
    $view = VideoView::factory()->fullyWatched()->create();

    expect($view->watch_percentage)->toBe(100);
});

it('belongs to a video and viewer', function () {
    $user = User::factory()->create();
    $video = VideoRecording::factory()->ready()->create();

    $view = VideoView::factory()->create([
        'video_recording_id' => $video->id,
        'viewer_id' => $user->id,
    ]);

    expect($view->video->id)->toBe($video->id)
        ->and($view->viewer->id)->toBe($user->id);
});

it('allows anonymous views', function () {
    $view = VideoView::factory()->anonymous()->create();

    expect($view->viewer_id)->toBeNull()
        ->and($view->viewer_email)->not->toBeNull();
});

it('has views relationship on video recording', function () {
    $video = VideoRecording::factory()->ready()->create();
    VideoView::factory()->count(3)->create(['video_recording_id' => $video->id]);

    expect($video->views)->toHaveCount(3);
});

// --- VideoEmail ---

it('creates a video email with sender and video', function () {
    $user = User::factory()->create();
    $video = VideoRecording::factory()->ready()->create();

    $email = VideoEmail::factory()->create([
        'video_recording_id' => $video->id,
        'sent_by' => $user->id,
    ]);

    expect($email->video->id)->toBe($video->id)
        ->and($email->sender->id)->toBe($user->id);
});

it('marks email as opened only once', function () {
    $email = VideoEmail::factory()->create();

    expect($email->opened_at)->toBeNull();

    $email->markOpened();
    $email->refresh();
    $firstOpened = $email->opened_at;

    expect($firstOpened)->not->toBeNull();

    // Calling again should not change the timestamp
    $email->markOpened();
    $email->refresh();

    expect($email->opened_at->equalTo($firstOpened))->toBeTrue();
});

it('marks email as clicked only once', function () {
    $email = VideoEmail::factory()->create();

    expect($email->clicked_at)->toBeNull();

    $email->markClicked();
    $email->refresh();
    $firstClicked = $email->clicked_at;

    expect($firstClicked)->not->toBeNull();

    $email->markClicked();
    $email->refresh();

    expect($email->clicked_at->equalTo($firstClicked))->toBeTrue();
});

it('has opened and clicked factory states', function () {
    $opened = VideoEmail::factory()->opened()->create();
    $clicked = VideoEmail::factory()->clicked()->create();

    expect($opened->opened_at)->not->toBeNull()
        ->and($opened->clicked_at)->toBeNull()
        ->and($clicked->opened_at)->not->toBeNull()
        ->and($clicked->clicked_at)->not->toBeNull();
});

it('has emails relationship on video recording', function () {
    $video = VideoRecording::factory()->ready()->create();
    VideoEmail::factory()->count(2)->create(['video_recording_id' => $video->id]);

    expect($video->emails)->toHaveCount(2);
});

// --- TrainingPath ---

it('has steps ordered by sort_order', function () {
    $path = TrainingPath::factory()->create();

    TrainingPathStep::factory()->create(['training_path_id' => $path->id, 'sort_order' => 2, 'title' => 'Second']);
    TrainingPathStep::factory()->create(['training_path_id' => $path->id, 'sort_order' => 0, 'title' => 'First']);
    TrainingPathStep::factory()->create(['training_path_id' => $path->id, 'sort_order' => 1, 'title' => 'Middle']);

    $steps = $path->steps;

    expect($steps)->toHaveCount(3)
        ->and($steps[0]->sort_order)->toBe(0)
        ->and($steps[1]->sort_order)->toBe(1)
        ->and($steps[2]->sort_order)->toBe(2);
});

it('calculates completion percentage for a user', function () {
    $user = User::factory()->create();
    $path = TrainingPath::factory()->create();

    $step1 = TrainingPathStep::factory()->create(['training_path_id' => $path->id, 'is_required' => true]);
    $step2 = TrainingPathStep::factory()->create(['training_path_id' => $path->id, 'is_required' => true]);
    $step3 = TrainingPathStep::factory()->create(['training_path_id' => $path->id, 'is_required' => true]);
    TrainingPathStep::factory()->optional()->create(['training_path_id' => $path->id]); // optional, not counted

    // Complete 1 of 3 required steps
    TrainingProgress::factory()->completed()->create([
        'user_id' => $user->id,
        'training_path_step_id' => $step1->id,
    ]);

    expect($path->getCompletionPercentageForUser($user))->toBe(33.3);
});

it('returns 100% when all required steps are completed', function () {
    $user = User::factory()->create();
    $path = TrainingPath::factory()->create();

    $step1 = TrainingPathStep::factory()->create(['training_path_id' => $path->id, 'is_required' => true]);
    $step2 = TrainingPathStep::factory()->create(['training_path_id' => $path->id, 'is_required' => true]);

    TrainingProgress::factory()->completed()->create(['user_id' => $user->id, 'training_path_step_id' => $step1->id]);
    TrainingProgress::factory()->completed()->create(['user_id' => $user->id, 'training_path_step_id' => $step2->id]);

    expect($path->getCompletionPercentageForUser($user))->toBe(100.0);
});

it('returns 100% for path with no required steps', function () {
    $user = User::factory()->create();
    $path = TrainingPath::factory()->create();

    TrainingPathStep::factory()->optional()->create(['training_path_id' => $path->id]);

    expect($path->getCompletionPercentageForUser($user))->toBe(100.0);
});

it('has factory states for required, employees, and crews', function () {
    $required = TrainingPath::factory()->required()->create();
    $employees = TrainingPath::factory()->forEmployees()->create();
    $crews = TrainingPath::factory()->forCrews()->create();

    expect($required->is_required)->toBeTrue()
        ->and($employees->visibility)->toBe('employees')
        ->and($crews->visibility)->toBe('crews');
});

// --- TrainingPathStep ---

it('belongs to a training path and video', function () {
    $path = TrainingPath::factory()->create();
    $video = VideoRecording::factory()->ready()->create();

    $step = TrainingPathStep::factory()->create([
        'training_path_id' => $path->id,
        'video_recording_id' => $video->id,
    ]);

    expect($step->path->id)->toBe($path->id)
        ->and($step->video->id)->toBe($video->id);
});

it('has progress relationship', function () {
    $step = TrainingPathStep::factory()->create();
    TrainingProgress::factory()->count(2)->create(['training_path_step_id' => $step->id]);

    expect($step->progress)->toHaveCount(2);
});

// --- TrainingProgress ---

it('enforces unique constraint on user and step', function () {
    $user = User::factory()->create();
    $step = TrainingPathStep::factory()->create();

    TrainingProgress::factory()->create([
        'user_id' => $user->id,
        'training_path_step_id' => $step->id,
    ]);

    expect(fn () => TrainingProgress::factory()->create([
        'user_id' => $user->id,
        'training_path_step_id' => $step->id,
    ]))->toThrow(\Illuminate\Database\QueryException::class);
});

it('marks progress as completed', function () {
    $progress = TrainingProgress::factory()->create(['watch_percentage' => 50]);

    expect($progress->completed_at)->toBeNull();

    $progress->markCompleted();
    $progress->refresh();

    expect($progress->watch_percentage)->toBe(100)
        ->and($progress->completed_at)->not->toBeNull();
});

it('does not overwrite completion timestamp when already completed', function () {
    $progress = TrainingProgress::factory()->completed()->create();
    $originalCompleted = $progress->completed_at;

    $progress->markCompleted();
    $progress->refresh();

    expect($progress->completed_at->equalTo($originalCompleted))->toBeTrue();
});

it('belongs to a user and step', function () {
    $user = User::factory()->create();
    $step = TrainingPathStep::factory()->create();

    $progress = TrainingProgress::factory()->create([
        'user_id' => $user->id,
        'training_path_step_id' => $step->id,
    ]);

    expect($progress->user->id)->toBe($user->id)
        ->and($progress->step->id)->toBe($step->id);
});

// --- VideoRecording factory states ---

it('has public and internal factory states', function () {
    $public = VideoRecording::factory()->public()->create();
    $internal = VideoRecording::factory()->internal()->create();

    expect($public->visibility)->toBe('public')
        ->and($public->status)->toBe('ready')
        ->and($internal->visibility)->toBe('internal')
        ->and($internal->status)->toBe('ready');
});

it('has withDescription factory state', function () {
    $video = VideoRecording::factory()->withDescription()->create();

    expect($video->description)->not->toBeNull();
});

it('casts metadata as array on video recording', function () {
    $video = VideoRecording::factory()->ready()->create([
        'metadata' => ['resolution' => '1080p', 'fps' => 30],
    ]);

    $video->refresh();

    expect($video->metadata)->toBeArray()
        ->and($video->metadata['resolution'])->toBe('1080p')
        ->and($video->metadata['fps'])->toBe(30);
});
