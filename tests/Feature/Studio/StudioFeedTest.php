<?php

use App\Models\Deal;
use App\Models\User;
use App\Models\VideoProject;
use App\Models\VideoRecording;
use Livewire\Livewire;

it('renders the studio feed for internal users', function () {
    $user = User::factory()->internal()->create();
    $this->actingAs($user)->get('/studio')->assertOk()
        ->assertSeeLivewire('studio.studio-feed');
});

it('shows deals with media', function () {
    $user = User::factory()->internal()->create();
    $deal = Deal::factory()->create();
    Livewire::actingAs($user)
        ->test('studio.studio-feed')
        ->assertSee($deal->title);
});

it('shows my video projects', function () {
    $user = User::factory()->internal()->create();
    $project = VideoProject::factory()->create(['user_id' => $user->id, 'title' => 'My Test Video']);
    Livewire::actingAs($user)
        ->test('studio.studio-feed')
        ->set('activeTab', 'my_videos')
        ->assertSee('My Test Video');
});

it('shows media hub videos', function () {
    $user = User::factory()->internal()->create();
    $video = VideoRecording::factory()->ready()->create([
        'user_id' => $user->id,
        'available_in_hub' => true,
        'title' => 'Hub Video',
    ]);
    Livewire::actingAs($user)
        ->test('studio.studio-feed')
        ->set('activeTab', 'media_hub')
        ->assertSee('Hub Video');
});

it('filters deals by search', function () {
    $user = User::factory()->internal()->create();
    Deal::factory()->create(['title' => 'Johnson Kitchen']);
    Deal::factory()->create(['title' => 'Smith Exterior']);
    Livewire::actingAs($user)
        ->test('studio.studio-feed')
        ->set('search', 'Johnson')
        ->assertSee('Johnson Kitchen')
        ->assertDontSee('Smith Exterior');
});

it('creates a blank project', function () {
    $user = User::factory()->internal()->create();
    Livewire::actingAs($user)
        ->test('studio.studio-feed')
        ->call('createBlank');
    $project = VideoProject::where('user_id', $user->id)->latest()->first();
    expect($project)->not->toBeNull();
    expect($project->creation_mode)->toBe('quick');
    expect($project->aspect_ratio)->toBe('9:16');
});
