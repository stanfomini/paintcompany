<?php

use App\Models\RemotionBuildRequest;
use App\Models\User;
use App\Models\VideoRecording;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('can create a remotion build request', function () {
    $user = User::factory()->create(['type' => 'internal']);

    Livewire::actingAs($user)
        ->test('studio.remotion-builder')
        ->set('prompt', 'Create a promotional video for house painting services')
        ->call('generate');

    expect(RemotionBuildRequest::count())->toBe(1);

    $build = RemotionBuildRequest::first();
    expect($build->user_id)->toBe($user->id);
    expect($build->prompt)->toContain('promotional video');
    // Status may be 'building' because Artisan::queue runs synchronously in tests
    expect($build->status)->toBeIn(['pending', 'building']);
});

it('validates prompt minimum length', function () {
    $user = User::factory()->create(['type' => 'internal']);

    Livewire::actingAs($user)
        ->test('studio.remotion-builder')
        ->set('prompt', 'short')
        ->call('generate')
        ->assertHasErrors(['prompt']);
});

it('validates prompt is required', function () {
    $user = User::factory()->create(['type' => 'internal']);

    Livewire::actingAs($user)
        ->test('studio.remotion-builder')
        ->set('prompt', '')
        ->call('generate')
        ->assertHasErrors(['prompt']);
});

it('validates prompt maximum length', function () {
    $user = User::factory()->create(['type' => 'internal']);

    Livewire::actingAs($user)
        ->test('studio.remotion-builder')
        ->set('prompt', str_repeat('a', 2001))
        ->call('generate')
        ->assertHasErrors(['prompt']);
});

it('can cancel a build', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $build = RemotionBuildRequest::create([
        'user_id' => $user->id,
        'prompt' => 'Test build for a promotional video',
        'status' => 'building',
        'started_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test('studio.remotion-builder')
        ->set('activeBuildId', $build->id)
        ->call('cancelBuild');

    expect($build->fresh()->status)->toBe('failed');
    expect($build->fresh()->error_message)->toBe('Cancelled by user');
});

it('prevents cancelling another users build', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $otherUser = User::factory()->create(['type' => 'internal']);
    $build = RemotionBuildRequest::create([
        'user_id' => $otherUser->id,
        'prompt' => 'Test build for another user',
        'status' => 'building',
        'started_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test('studio.remotion-builder')
        ->set('activeBuildId', $build->id)
        ->call('cancelBuild');

    expect($build->fresh()->status)->toBe('building');
});

it('shows recent builds', function () {
    $user = User::factory()->create(['type' => 'internal']);
    RemotionBuildRequest::factory()->completed()->create([
        'user_id' => $user->id,
        'prompt' => 'First video about painting',
    ]);
    RemotionBuildRequest::factory()->failed()->create([
        'user_id' => $user->id,
        'prompt' => 'Second video about colors',
    ]);

    Livewire::actingAs($user)
        ->test('studio.remotion-builder')
        ->assertSee('First video about painting')
        ->assertSee('Second video about colors');
});

it('does not show other users builds', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $otherUser = User::factory()->create(['type' => 'internal']);

    RemotionBuildRequest::factory()->completed()->create([
        'user_id' => $otherUser->id,
        'prompt' => 'Other users private video',
    ]);

    Livewire::actingAs($user)
        ->test('studio.remotion-builder')
        ->assertDontSee('Other users private video');
});

it('restores active build on mount', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $build = RemotionBuildRequest::create([
        'user_id' => $user->id,
        'prompt' => 'Video currently being built',
        'status' => 'building',
        'started_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test('studio.remotion-builder')
        ->assertSet('activeBuildId', $build->id)
        ->assertSee('Building...');
});

it('handles build callback with valid secret', function () {
    Storage::fake('s3');

    $user = User::factory()->create(['type' => 'internal']);
    $build = RemotionBuildRequest::create([
        'user_id' => $user->id,
        'prompt' => 'Test video build',
        'status' => 'building',
        'started_at' => now(),
    ]);

    $response = $this->postJson('/api/remotion/build-complete', [
        'request_id' => $build->id,
        'file' => UploadedFile::fake()->create('video.mp4', 1024, 'video/mp4'),
    ], [
        'X-Remotion-Build-Secret' => config('services.remotion.build_secret'),
    ]);

    $response->assertSuccessful();
    expect($build->fresh()->status)->toBe('completed');
    expect($build->fresh()->video_recording_id)->not->toBeNull();
    expect(VideoRecording::count())->toBe(1);
});

it('rejects callback with invalid secret', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $build = RemotionBuildRequest::create([
        'user_id' => $user->id,
        'prompt' => 'Test video build',
        'status' => 'building',
        'started_at' => now(),
    ]);

    $response = $this->postJson('/api/remotion/build-complete', [
        'request_id' => $build->id,
        'file' => UploadedFile::fake()->create('video.mp4', 1024, 'video/mp4'),
    ], [
        'X-Remotion-Build-Secret' => 'wrong-secret',
    ]);

    $response->assertForbidden();
});

it('handles error callback without file', function () {
    $user = User::factory()->create(['type' => 'internal']);
    $build = RemotionBuildRequest::create([
        'user_id' => $user->id,
        'prompt' => 'Test video build',
        'status' => 'building',
        'started_at' => now(),
    ]);

    $response = $this->postJson('/api/remotion/build-complete', [
        'request_id' => $build->id,
        'error' => 'Render timeout after 15 minutes',
    ], [
        'X-Remotion-Build-Secret' => config('services.remotion.build_secret'),
    ]);

    $response->assertSuccessful();
    expect($build->fresh()->status)->toBe('failed');
    expect($build->fresh()->error_message)->toBe('Render timeout after 15 minutes');
});

it('tracks build request lifecycle', function () {
    $build = RemotionBuildRequest::factory()->create(['status' => 'pending']);

    expect($build->isActive())->toBeTrue();

    $build->markBuilding();
    expect($build->status)->toBe('building');
    expect($build->started_at)->not->toBeNull();

    $recording = VideoRecording::factory()->ready()->create();
    $build->markCompleted($recording->id);
    expect($build->status)->toBe('completed');
    expect($build->video_recording_id)->toBe($recording->id);
    expect($build->completed_at)->not->toBeNull();
});

it('handles build failure', function () {
    $build = RemotionBuildRequest::factory()->building()->create();

    $build->markFailed('Render timeout');

    expect($build->status)->toBe('failed');
    expect($build->error_message)->toBe('Render timeout');
    expect($build->completed_at)->not->toBeNull();
});

it('clears prompt after successful generation', function () {
    $user = User::factory()->create(['type' => 'internal']);

    Livewire::actingAs($user)
        ->test('studio.remotion-builder')
        ->set('prompt', 'Create a beautiful painting timelapse video')
        ->call('generate')
        ->assertSet('prompt', '');
});
