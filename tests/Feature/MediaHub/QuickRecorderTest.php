<?php

use App\Models\User;
use App\Models\VideoChannel;
use App\Models\VideoChannelItem;
use App\Models\VideoRecording;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('renders quick recorder for users with media.record permission', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('media.record');

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertSeeLivewire('media.quick-recorder');
});

it('hides quick recorder without media.record permission', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user)
        ->get('/dashboard')
        ->assertOk()
        ->assertDontSeeLivewire('media.quick-recorder');
});

it('can add recording to a channel', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('media.record');

    $recording = VideoRecording::factory()->ready()->create(['user_id' => $user->id]);
    $channel = VideoChannel::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test('media.quick-recorder')
        ->set('lastRecordingId', $recording->id)
        ->call('addToChannel', $channel->id);

    expect(VideoChannelItem::where('video_channel_id', $channel->id)
        ->where('video_recording_id', $recording->id)
        ->exists()
    )->toBeTrue();
});

it('does not duplicate when adding to same channel twice', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('media.record');

    $recording = VideoRecording::factory()->ready()->create(['user_id' => $user->id]);
    $channel = VideoChannel::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test('media.quick-recorder')
        ->set('lastRecordingId', $recording->id)
        ->call('addToChannel', $channel->id)
        ->call('addToChannel', $channel->id);

    expect(VideoChannelItem::where('video_channel_id', $channel->id)
        ->where('video_recording_id', $recording->id)
        ->count()
    )->toBe(1);
});

it('can dismiss post-save actions', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('media.record');

    Livewire::actingAs($user)
        ->test('media.quick-recorder')
        ->set('lastRecordingId', 123)
        ->set('lastShareToken', 'abc123')
        ->set('showPostActions', true)
        ->call('dismissPostActions')
        ->assertSet('showPostActions', false)
        ->assertSet('lastRecordingId', null)
        ->assertSet('lastShareToken', null);
});

it('shows channels list for authenticated user', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('media.record');

    VideoChannel::factory()->create(['user_id' => $user->id, 'name' => 'My Channel']);

    // Channel shows when post-actions are visible
    Livewire::actingAs($user)
        ->test('media.quick-recorder')
        ->set('lastRecordingId', 1)
        ->set('lastShareToken', 'test-token')
        ->set('showPostActions', true)
        ->assertSee('My Channel');
});

it('renders the component without errors', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('media.record');

    Livewire::actingAs($user)
        ->test('media.quick-recorder')
        ->assertOk()
        ->assertSet('showPostActions', false)
        ->assertSet('lastRecordingId', null);
});
