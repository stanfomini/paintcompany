<?php

use App\Models\User;
use App\Models\VideoEmail;
use App\Models\VideoRecording;
use App\Models\VideoView;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

it('requires media.manage permission', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('media.view');

    Livewire::actingAs($user)
        ->test('media.analytics-overview')
        ->assertForbidden();
});

it('allows users with media.manage permission', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo(['media.view', 'media.manage']);

    Livewire::actingAs($user)
        ->test('media.analytics-overview')
        ->assertSuccessful();
});

it('displays total views across all videos', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $video = VideoRecording::factory()->ready()->create();
    VideoView::factory()->count(5)->create(['video_recording_id' => $video->id]);

    Livewire::actingAs($user)
        ->test('media.analytics-overview')
        ->assertSee('5');
});

it('displays views from last 7 days', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $video = VideoRecording::factory()->ready()->create();

    VideoView::factory()->count(3)->create([
        'video_recording_id' => $video->id,
        'created_at' => now()->subDays(2),
    ]);
    VideoView::factory()->count(2)->create([
        'video_recording_id' => $video->id,
        'created_at' => now()->subDays(15),
    ]);

    Livewire::actingAs($user)
        ->test('media.analytics-overview')
        ->assertSee('3'); // last 7 days
});

it('displays average watch percentage', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $video = VideoRecording::factory()->ready()->create();

    VideoView::factory()->fullyWatched()->create(['video_recording_id' => $video->id]);
    VideoView::factory()->halfWatched()->create(['video_recording_id' => $video->id]);

    Livewire::actingAs($user)
        ->test('media.analytics-overview')
        ->assertSee('75'); // (100 + 50) / 2
});

it('displays top videos by view count', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $popularVideo = VideoRecording::factory()->ready()->create(['title' => 'Popular Video']);
    VideoView::factory()->count(10)->create(['video_recording_id' => $popularVideo->id]);

    $lessPopularVideo = VideoRecording::factory()->ready()->create(['title' => 'Less Popular']);
    VideoView::factory()->count(2)->create(['video_recording_id' => $lessPopularVideo->id]);

    Livewire::actingAs($user)
        ->test('media.analytics-overview')
        ->assertSee('Popular Video')
        ->assertSee('Less Popular')
        ->assertSeeInOrder(['Popular Video', 'Less Popular']);
});

it('displays recent views with viewer info', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $viewer = User::factory()->create(['name' => 'John Doe']);
    $video = VideoRecording::factory()->ready()->create(['title' => 'Test Video']);

    VideoView::factory()->create([
        'video_recording_id' => $video->id,
        'viewer_id' => $viewer->id,
        'watch_percentage' => 85,
        'source' => 'email',
    ]);

    Livewire::actingAs($user)
        ->test('media.analytics-overview')
        ->assertSee('Test Video')
        ->assertSee('John Doe')
        ->assertSee('85%')
        ->assertSee('Email');
});

it('displays anonymous viewer by email or ip', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $video = VideoRecording::factory()->ready()->create();

    VideoView::factory()->anonymous()->create([
        'video_recording_id' => $video->id,
        'viewer_email' => 'visitor@example.com',
    ]);

    Livewire::actingAs($user)
        ->test('media.analytics-overview')
        ->assertSee('visitor@example.com');
});

it('shows unwatched ready videos as opportunities', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $unwatched = VideoRecording::factory()->ready()->create(['title' => 'Unwatched Video']);
    $watched = VideoRecording::factory()->ready()->create(['title' => 'Watched Video']);
    VideoView::factory()->create(['video_recording_id' => $watched->id]);

    Livewire::actingAs($user)
        ->test('media.analytics-overview')
        ->assertSee('Unwatched Video')
        ->assertSee('Opportunity to Promote');
});

it('hides unwatched section when all videos have views', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $video = VideoRecording::factory()->ready()->create();
    VideoView::factory()->create(['video_recording_id' => $video->id]);

    Livewire::actingAs($user)
        ->test('media.analytics-overview')
        ->assertDontSee('Opportunity to Promote');
});

it('displays email stats when emails exist', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    VideoEmail::factory()->count(5)->create();
    VideoEmail::factory()->opened()->count(3)->create();
    VideoEmail::factory()->clicked()->count(2)->create();

    Livewire::actingAs($user)
        ->test('media.analytics-overview')
        ->assertSee('Email Performance')
        ->assertSee('10'); // total: 5 + 3 + 2
});

it('hides email stats when no emails sent', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('media.analytics-overview')
        ->assertDontSee('Email Performance');
});

it('shows no views message when no data exists', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('media.analytics-overview')
        ->assertSee('No views recorded yet.');
});
