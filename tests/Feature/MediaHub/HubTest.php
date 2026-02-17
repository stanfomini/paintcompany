<?php

use App\Models\User;
use App\Models\VideoChannel;
use App\Models\VideoRecording;
use App\Models\VideoView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    $this->user = User::factory()->internal()->withFullAccess()->create();
    $this->actingAs($this->user);
});

// --- Hub Tab Container ---

it('renders the media hub with default videos tab', function () {
    $this->get(route('media.index'))
        ->assertSuccessful()
        ->assertSee('Media Hub')
        ->assertSee('My Videos');
});

it('shows channels tab for users with manage_channels permission', function () {
    Livewire::test('media.hub')
        ->assertSee('Channels')
        ->assertSee('My Videos');
});

it('hides channels tab for users without manage_channels permission', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('media.view');

    Livewire::actingAs($user)
        ->test('media.hub')
        ->assertSee('My Videos')
        ->assertDontSee('Channels');
});

it('switches tabs via setTab', function () {
    Livewire::test('media.hub')
        ->assertSet('tab', 'videos')
        ->call('setTab', 'analytics')
        ->assertSet('tab', 'analytics');
});

// --- My Videos Grid ---

it('shows the current users videos', function () {
    $video = VideoRecording::factory()->ready()->create([
        'user_id' => $this->user->id,
        'title' => 'My Test Video',
    ]);

    // Another user's video - should not appear
    VideoRecording::factory()->ready()->create(['title' => 'Other Users Video']);

    Livewire::test('media.my-videos')
        ->assertSeeHtml('data-video-title="My Test Video"')
        ->assertDontSeeHtml('data-video-title="Other Users Video"');
});

it('excludes videos attached to estimates or jobs', function () {
    $standalone = VideoRecording::factory()->ready()->create([
        'user_id' => $this->user->id,
        'title' => 'Standalone Video',
    ]);

    $attached = VideoRecording::factory()->ready()->create([
        'user_id' => $this->user->id,
        'title' => 'Estimate Video',
        'recordable_type' => 'App\Models\Estimate',
        'recordable_id' => 1,
    ]);

    Livewire::test('media.my-videos')
        ->assertSeeHtml('data-video-title="Standalone Video"')
        ->assertDontSeeHtml('data-video-title="Estimate Video"');
});

it('filters videos by search term', function () {
    VideoRecording::factory()->ready()->create([
        'user_id' => $this->user->id,
        'title' => 'Kitchen Walkthrough',
    ]);
    VideoRecording::factory()->ready()->create([
        'user_id' => $this->user->id,
        'title' => 'Exterior Paint Job',
    ]);

    Livewire::test('media.my-videos')
        ->set('search', 'Kitchen')
        ->assertSeeHtml('data-video-title="Kitchen Walkthrough"')
        ->assertDontSeeHtml('data-video-title="Exterior Paint Job"');
});

it('filters videos by visibility', function () {
    VideoRecording::factory()->public()->create([
        'user_id' => $this->user->id,
        'title' => 'Public Video',
    ]);
    VideoRecording::factory()->internal()->create([
        'user_id' => $this->user->id,
        'title' => 'Internal Video',
    ]);

    Livewire::test('media.my-videos')
        ->set('filterVisibility', 'public')
        ->assertSeeHtml('data-video-title="Public Video"')
        ->assertDontSeeHtml('data-video-title="Internal Video"');
});

it('shows empty state when no videos exist', function () {
    Livewire::test('media.my-videos')
        ->assertSee('No videos found')
        ->assertSee('Record your first video');
});

it('shows filter hint in empty state when filters are active', function () {
    Livewire::test('media.my-videos')
        ->set('search', 'nonexistent')
        ->assertSee('Try adjusting your search');
});

it('can delete a video', function () {
    $video = VideoRecording::factory()->ready()->create([
        'user_id' => $this->user->id,
        'title' => 'Video to Delete',
    ]);

    Livewire::test('media.my-videos')
        ->assertSeeHtml('data-video-title="Video to Delete"')
        ->call('deleteVideo', $video->id);

    $this->assertDatabaseMissing('video_recordings', ['id' => $video->id]);
});

it('cannot delete another users video', function () {
    $otherVideo = VideoRecording::factory()->ready()->create([
        'title' => 'Not Mine',
    ]);

    expect(fn () => Livewire::test('media.my-videos')
        ->call('deleteVideo', $otherVideo->id)
    )->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

    $this->assertDatabaseHas('video_recordings', ['id' => $otherVideo->id]);
});

// --- Video Card ---

it('renders the video card with title and visibility', function () {
    $video = VideoRecording::factory()->public()->create([
        'user_id' => $this->user->id,
        'title' => 'Card Test Video',
    ]);

    Livewire::test('media.video-card', ['video' => $video])
        ->assertSee('Card Test Video')
        ->assertSee('Public');
});

it('shows view count on video card', function () {
    $video = VideoRecording::factory()->ready()->create([
        'user_id' => $this->user->id,
        'title' => 'Popular Video',
    ]);

    VideoView::factory()->count(5)->create(['video_recording_id' => $video->id]);

    $video->loadCount('views');

    Livewire::test('media.video-card', ['video' => $video])
        ->assertSee('5')
        ->assertSee('views');
});

it('shows processing badge for processing videos', function () {
    $video = VideoRecording::factory()->processing()->create([
        'user_id' => $this->user->id,
    ]);

    Livewire::test('media.video-card', ['video' => $video])
        ->assertSee('Processing');
});

// --- Channel List ---

it('renders channel list for users with manage_channels permission', function () {
    VideoChannel::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Our Portfolio',
    ]);

    Livewire::test('media.channel-list')
        ->assertSee('Our Portfolio')
        ->assertSee('New Channel');
});

it('denies channel list access without manage_channels permission', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('media.view');

    Livewire::actingAs($user)
        ->test('media.channel-list')
        ->assertForbidden();
});

it('creates a new channel', function () {
    Livewire::test('media.channel-list')
        ->set('showCreateModal', true)
        ->set('newChannelName', 'New Channel Name')
        ->set('newChannelDescription', 'A test channel')
        ->set('newChannelVisibility', 'public')
        ->call('createChannel');

    $this->assertDatabaseHas('video_channels', [
        'name' => 'New Channel Name',
        'description' => 'A test channel',
        'visibility' => 'public',
        'user_id' => $this->user->id,
    ]);
});

it('validates channel name is required', function () {
    Livewire::test('media.channel-list')
        ->set('newChannelName', '')
        ->call('createChannel')
        ->assertHasErrors(['newChannelName' => 'required']);
});

it('toggles channel active state', function () {
    $channel = VideoChannel::factory()->create([
        'user_id' => $this->user->id,
        'is_active' => true,
    ]);

    Livewire::test('media.channel-list')
        ->call('toggleActive', $channel->id);

    expect($channel->fresh()->is_active)->toBeFalse();
});

it('deletes a channel', function () {
    $channel = VideoChannel::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Channel To Delete',
    ]);

    Livewire::test('media.channel-list')
        ->call('deleteChannel', $channel->id);

    $this->assertDatabaseMissing('video_channels', ['id' => $channel->id]);
});

it('shows video count per channel', function () {
    $channel = VideoChannel::factory()->create([
        'user_id' => $this->user->id,
        'name' => 'Full Channel',
    ]);

    $video1 = VideoRecording::factory()->ready()->create();
    $video2 = VideoRecording::factory()->ready()->create();

    \App\Models\VideoChannelItem::factory()->create([
        'video_channel_id' => $channel->id,
        'video_recording_id' => $video1->id,
    ]);
    \App\Models\VideoChannelItem::factory()->create([
        'video_channel_id' => $channel->id,
        'video_recording_id' => $video2->id,
    ]);

    Livewire::test('media.channel-list')
        ->assertSee('2');
});
