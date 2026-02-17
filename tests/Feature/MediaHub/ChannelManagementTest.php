<?php

use App\Models\User;
use App\Models\VideoChannel;
use App\Models\VideoChannelItem;
use App\Models\VideoRecording;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

// --- Channel Editor ---

it('creates a channel with a unique slug', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('media.channel-editor')
        ->set('name', 'Our Best Work')
        ->set('description', 'Showcase of our projects')
        ->set('visibility', 'public')
        ->call('save')
        ->assertDispatched('channel-saved');

    $this->assertDatabaseHas('video_channels', [
        'name' => 'Our Best Work',
        'slug' => 'our-best-work',
        'visibility' => 'public',
        'user_id' => $user->id,
    ]);
});

it('generates a duplicate slug with -2 appended', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    VideoChannel::factory()->create(['name' => 'Test Channel', 'slug' => 'test-channel']);

    Livewire::actingAs($user)
        ->test('media.channel-editor')
        ->set('name', 'Test Channel')
        ->call('save')
        ->assertDispatched('channel-saved');

    $this->assertDatabaseHas('video_channels', [
        'name' => 'Test Channel',
        'slug' => 'test-channel-2',
    ]);
});

it('edits an existing channel', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $channel = VideoChannel::factory()->create(['user_id' => $user->id, 'name' => 'Old Name']);

    Livewire::actingAs($user)
        ->test('media.channel-editor', ['channelId' => $channel->id])
        ->assertSet('name', 'Old Name')
        ->set('name', 'New Name')
        ->set('description', 'Updated description')
        ->call('save')
        ->assertDispatched('channel-saved');

    $channel->refresh();
    expect($channel->name)->toBe('New Name')
        ->and($channel->description)->toBe('Updated description');
});

it('validates required name on channel creation', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('media.channel-editor')
        ->set('name', '')
        ->call('save')
        ->assertHasErrors(['name' => 'required']);
});

// --- Channel List ---

it('toggles channel active status', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $channel = VideoChannel::factory()->create(['user_id' => $user->id, 'is_active' => true]);

    Livewire::actingAs($user)
        ->test('media.channel-list')
        ->call('toggleActive', $channel->id);

    $channel->refresh();
    expect($channel->is_active)->toBeFalse();
});

it('deletes a channel', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $channel = VideoChannel::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test('media.channel-list')
        ->call('deleteChannel', $channel->id);

    $this->assertDatabaseMissing('video_channels', ['id' => $channel->id]);
});

// --- Channel Manage Page ---

it('adds a video to a channel via the picker', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $channel = VideoChannel::factory()->create(['user_id' => $user->id]);
    $video = VideoRecording::factory()->ready()->create();

    Livewire::actingAs($user)
        ->test('media.channel-video-picker', ['channelId' => $channel->id])
        ->call('addVideo', $video->id)
        ->assertDispatched('video-added');

    $this->assertDatabaseHas('video_channel_items', [
        'video_channel_id' => $channel->id,
        'video_recording_id' => $video->id,
    ]);
});

it('removes a video from a channel', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $channel = VideoChannel::factory()->create(['user_id' => $user->id]);
    $video = VideoRecording::factory()->ready()->create();
    $item = VideoChannelItem::factory()->create([
        'video_channel_id' => $channel->id,
        'video_recording_id' => $video->id,
    ]);

    Livewire::actingAs($user)
        ->test('media.channel-manage', ['channel' => $channel])
        ->call('removeVideo', $item->id);

    $this->assertDatabaseMissing('video_channel_items', ['id' => $item->id]);
});

it('reorders videos by updating sort_order', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $channel = VideoChannel::factory()->create(['user_id' => $user->id]);

    $item1 = VideoChannelItem::factory()->create(['video_channel_id' => $channel->id, 'sort_order' => 0]);
    $item2 = VideoChannelItem::factory()->create(['video_channel_id' => $channel->id, 'sort_order' => 1]);
    $item3 = VideoChannelItem::factory()->create(['video_channel_id' => $channel->id, 'sort_order' => 2]);

    Livewire::actingAs($user)
        ->test('media.channel-manage', ['channel' => $channel])
        ->call('reorderVideos', [$item3->id, $item1->id, $item2->id]);

    expect(VideoChannelItem::find($item3->id)->sort_order)->toBe(0)
        ->and(VideoChannelItem::find($item1->id)->sort_order)->toBe(1)
        ->and(VideoChannelItem::find($item2->id)->sort_order)->toBe(2);
});

it('video picker excludes already-added videos', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $channel = VideoChannel::factory()->create(['user_id' => $user->id]);

    $addedVideo = VideoRecording::factory()->ready()->create(['title' => 'Already Added']);
    $availableVideo = VideoRecording::factory()->ready()->create(['title' => 'Available Video']);

    VideoChannelItem::factory()->create([
        'video_channel_id' => $channel->id,
        'video_recording_id' => $addedVideo->id,
    ]);

    Livewire::actingAs($user)
        ->test('media.channel-video-picker', ['channelId' => $channel->id])
        ->assertSee('Available Video')
        ->assertDontSee('Already Added');
});

it('toggles published status on a channel item', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $channel = VideoChannel::factory()->create(['user_id' => $user->id]);
    $item = VideoChannelItem::factory()->create([
        'video_channel_id' => $channel->id,
        'published_at' => null,
    ]);

    Livewire::actingAs($user)
        ->test('media.channel-manage', ['channel' => $channel])
        ->call('togglePublished', $item->id);

    $item->refresh();
    expect($item->published_at)->not->toBeNull();
});

// --- Permission enforcement ---

it('requires media.manage_channels permission for channel list', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('media.view');

    Livewire::actingAs($user)
        ->test('media.channel-list')
        ->assertForbidden();
});

it('allows users with media.manage_channels to access channel list', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo(['media.view', 'media.manage_channels']);

    Livewire::actingAs($user)
        ->test('media.channel-list')
        ->assertSuccessful();
});
