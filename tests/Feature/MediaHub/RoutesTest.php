<?php

use App\Models\TrainingPath;
use App\Models\User;
use App\Models\VideoChannel;
use App\Models\VideoEmail;
use App\Models\VideoRecording;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

// --- Internal /media routes require auth ---

it('redirects unauthenticated users from /media to login', function () {
    $this->get('/media')
        ->assertRedirect('/login');
});

it('forbids internal users without media.view permission from /media', function () {
    $user = User::factory()->internal()->create();

    $this->actingAs($user)
        ->get('/media')
        ->assertForbidden();
});

it('allows internal users with media.view permission to access /media', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('media.view');

    $this->actingAs($user)
        ->get('/media')
        ->assertSuccessful();
});

it('allows owner role to access /media', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $this->actingAs($user)
        ->get('/media')
        ->assertSuccessful();
});

// --- Channel management permission ---

it('forbids users without media.manage_channels from channel management', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('media.view');

    $channel = VideoChannel::factory()->create();

    $this->actingAs($user)
        ->get("/media/channels/{$channel->id}")
        ->assertForbidden();
});

it('allows users with media.manage_channels to access channel management', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo(['media.view', 'media.manage_channels']);

    $channel = VideoChannel::factory()->create();

    $this->actingAs($user)
        ->get("/media/channels/{$channel->id}")
        ->assertSuccessful();
});

// --- Training management permission ---

it('forbids users without media.manage_training from training management', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('media.view');

    $path = TrainingPath::factory()->create();

    $this->actingAs($user)
        ->get("/media/training/{$path->id}")
        ->assertForbidden();
});

it('allows users with media.manage_training to access training management', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo(['media.view', 'media.manage_training']);

    $path = TrainingPath::factory()->create();

    $this->actingAs($user)
        ->get("/media/training/{$path->id}")
        ->assertSuccessful();
});

// --- Public /watch routes ---

it('allows unauthenticated access to /watch', function () {
    $this->get('/watch')
        ->assertSuccessful();
});

it('shows a public active channel at /watch/{slug}', function () {
    $channel = VideoChannel::factory()->public()->create();

    $this->get("/watch/{$channel->slug}")
        ->assertSuccessful();
});

it('returns 404 for inactive channel at /watch/{slug}', function () {
    $channel = VideoChannel::factory()->public()->inactive()->create();

    $this->get("/watch/{$channel->slug}")
        ->assertNotFound();
});

it('returns 404 for private channel at /watch/{slug}', function () {
    $channel = VideoChannel::factory()->private()->create();

    $this->get("/watch/{$channel->slug}")
        ->assertNotFound();
});

it('returns 404 for nonexistent channel slug', function () {
    $this->get('/watch/nonexistent-channel')
        ->assertNotFound();
});

it('shows a video player at /watch/{slug}/{recording}', function () {
    $channel = VideoChannel::factory()->public()->create();
    $video = VideoRecording::factory()->ready()->create();

    $this->get("/watch/{$channel->slug}/{$video->id}")
        ->assertSuccessful();
});

// --- API: Video view tracking ---

it('accepts valid video view tracking data', function () {
    $video = VideoRecording::factory()->ready()->create();

    $this->postJson('/api/video-views', [
        'video_id' => $video->id,
        'duration' => 60,
        'percentage' => 50,
        'source' => 'link',
        'session_id' => 'abc123',
    ])->assertSuccessful()
        ->assertJson(['ok' => true]);

    $this->assertDatabaseHas('video_views', [
        'video_recording_id' => $video->id,
        'watch_duration_seconds' => 60,
        'watch_percentage' => 50,
        'source' => 'link',
    ]);
});

it('validates video view tracking data', function () {
    $this->postJson('/api/video-views', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['video_id', 'duration', 'percentage', 'source', 'session_id']);
});

// --- API: Email tracking ---

it('returns tracking pixel and marks email as opened', function () {
    $email = VideoEmail::factory()->create();

    $response = $this->get("/api/video-emails/{$email->id}/pixel");

    $response->assertSuccessful();
    $response->assertHeader('Content-Type', 'image/gif');

    $email->refresh();
    expect($email->opened_at)->not->toBeNull();
});

it('redirects on email click and marks as clicked', function () {
    $video = VideoRecording::factory()->ready()->create();
    $email = VideoEmail::factory()->create([
        'video_recording_id' => $video->id,
    ]);

    $response = $this->get("/api/video-emails/{$email->id}/click");

    $response->assertRedirect();

    $email->refresh();
    expect($email->clicked_at)->not->toBeNull();
});

// --- Sidebar visibility ---

it('shows Media Hub sidebar link for users with media.view permission', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $this->actingAs($user)
        ->get('/media')
        ->assertSee('Media Hub');
});

// --- Role-based permission assignments ---

it('gives admin role media.view, media.record, and media.send_emails permissions', function () {
    $user = User::factory()->internal()->create();
    $user->assignRole('admin');

    expect($user->hasPermissionTo('media.view'))->toBeTrue()
        ->and($user->hasPermissionTo('media.record'))->toBeTrue()
        ->and($user->hasPermissionTo('media.send_emails'))->toBeTrue()
        ->and($user->hasPermissionTo('media.manage_channels'))->toBeFalse()
        ->and($user->hasPermissionTo('media.manage_training'))->toBeFalse();
});

it('gives manager role media.view, media.record, and media.send_emails permissions', function () {
    $user = User::factory()->internal()->create();
    $user->assignRole('manager');

    expect($user->hasPermissionTo('media.view'))->toBeTrue()
        ->and($user->hasPermissionTo('media.record'))->toBeTrue()
        ->and($user->hasPermissionTo('media.send_emails'))->toBeTrue()
        ->and($user->hasPermissionTo('media.manage_channels'))->toBeFalse();
});

it('gives owner role all media permissions', function () {
    $user = User::factory()->internal()->create();
    $user->assignRole('owner');

    expect($user->hasPermissionTo('media.view'))->toBeTrue()
        ->and($user->hasPermissionTo('media.record'))->toBeTrue()
        ->and($user->hasPermissionTo('media.manage_channels'))->toBeTrue()
        ->and($user->hasPermissionTo('media.manage_training'))->toBeTrue()
        ->and($user->hasPermissionTo('media.send_emails'))->toBeTrue();
});
