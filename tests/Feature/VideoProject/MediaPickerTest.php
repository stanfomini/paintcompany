<?php

use App\Models\User;
use App\Models\VideoRecording;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders media picker with tabs', function () {
    $user = User::factory()->internal()->create();

    Livewire::actingAs($user)
        ->test('studio.media-picker')
        ->assertSuccessful()
        ->assertSee(__('Starred'))
        ->assertSee(__('My Videos'));
});

it('switches between tabs', function () {
    $user = User::factory()->internal()->create();

    Livewire::actingAs($user)
        ->test('studio.media-picker')
        ->assertSet('activeTab', 'starred')
        ->set('activeTab', 'my_videos')
        ->assertSet('activeTab', 'my_videos');
});

it('shows starred videos in starred tab', function () {
    $user = User::factory()->internal()->create();
    $starred = VideoRecording::factory()->ready()->create(['title' => 'My Starred Video']);
    $starred->toggleStar($user);
    VideoRecording::factory()->ready()->create(['title' => 'Not Starred']);

    Livewire::actingAs($user)
        ->test('studio.media-picker')
        ->assertSet('activeTab', 'starred')
        ->assertSee('My Starred Video')
        ->assertDontSee('Not Starred');
});

it('shows user videos in my videos tab', function () {
    $user = User::factory()->internal()->create();
    VideoRecording::factory()->ready()->create(['user_id' => $user->id, 'title' => 'My Video']);
    VideoRecording::factory()->ready()->create(['title' => 'Other Video']);

    Livewire::actingAs($user)
        ->test('studio.media-picker')
        ->set('activeTab', 'my_videos')
        ->assertSee('My Video');
});

it('dispatches media-selected event on selection', function () {
    $user = User::factory()->internal()->create();
    $recording = VideoRecording::factory()->ready()->create();

    Livewire::actingAs($user)
        ->test('studio.media-picker')
        ->call('selectMedia', 'App\\Models\\VideoRecording', $recording->id)
        ->assertDispatched('media-selected');
});

it('resets search when switching tabs', function () {
    $user = User::factory()->internal()->create();

    Livewire::actingAs($user)
        ->test('studio.media-picker')
        ->set('activeTab', 'my_videos')
        ->set('search', 'something')
        ->assertSet('search', 'something')
        ->set('activeTab', 'photos')
        ->assertSet('search', '');
});
