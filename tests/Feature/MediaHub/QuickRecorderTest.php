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

it('can toggle panel visibility', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('media.record');

    Livewire::actingAs($user)
        ->test('media.quick-recorder')
        ->assertSet('showPanel', false)
        ->call('togglePanel')
        ->assertSet('showPanel', true)
        ->call('togglePanel')
        ->assertSet('showPanel', false);
});

it('can save recording title', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('media.record');

    $recording = VideoRecording::factory()->ready()->create(['user_id' => $user->id, 'title' => 'Old Title']);

    Livewire::actingAs($user)
        ->test('media.quick-recorder')
        ->set('recordingId', $recording->id)
        ->set('recordingTitle', 'My New Title')
        ->call('saveTitle');

    expect($recording->fresh()->title)->toBe('My New Title');
});

it('cannot save title for other users recording', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('media.record');

    $otherUser = User::factory()->internal()->create();
    $recording = VideoRecording::factory()->ready()->create(['user_id' => $otherUser->id, 'title' => 'Other Title']);

    Livewire::actingAs($user)
        ->test('media.quick-recorder')
        ->set('recordingId', $recording->id)
        ->set('recordingTitle', 'Hacked Title')
        ->call('saveTitle');

    expect($recording->fresh()->title)->toBe('Other Title');
});

it('can add recording to a channel', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('media.record');

    $recording = VideoRecording::factory()->ready()->create(['user_id' => $user->id]);
    $channel = VideoChannel::factory()->create(['user_id' => $user->id]);

    Livewire::actingAs($user)
        ->test('media.quick-recorder')
        ->set('recordingId', $recording->id)
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
        ->set('recordingId', $recording->id)
        ->call('addToChannel', $channel->id)
        ->call('addToChannel', $channel->id);

    expect(VideoChannelItem::where('video_channel_id', $channel->id)
        ->where('video_recording_id', $recording->id)
        ->count()
    )->toBe(1);
});

it('can reset recording state', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('media.record');

    Livewire::actingAs($user)
        ->test('media.quick-recorder')
        ->set('recordingId', 123)
        ->set('recordingTitle', 'Some Title')
        ->set('shareUrl', '/v/abc123')
        ->call('resetRecording')
        ->assertSet('recordingId', null)
        ->assertSet('recordingTitle', '')
        ->assertSet('shareUrl', null)
        ->assertSet('linkCopied', false);
});
