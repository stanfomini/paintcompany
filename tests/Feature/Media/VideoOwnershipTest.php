<?php

use App\Models\User;
use App\Models\VideoChannel;
use App\Models\VideoRecording;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
});

// --- Ownership Preservation on User Deletion ---

it('preserves videos when user is deleted by setting user_id to null', function () {
    // nullOnDelete FK only works on MySQL; SQLite migration path cannot re-create FKs
    if (DB::getDriverName() === 'sqlite') {
        $this->markTestSkipped('nullOnDelete FK behavior requires MySQL.');
    }

    $user = User::factory()->internal()->create();
    $video = VideoRecording::factory()->ready()->create(['user_id' => $user->id]);

    $user->delete();

    $video->refresh();
    expect($video->exists)->toBeTrue();
    expect($video->user_id)->toBeNull();
});

it('preserves channels when user is deleted by setting user_id to null', function () {
    // nullOnDelete FK only works on MySQL; SQLite migration path cannot re-create FKs
    if (DB::getDriverName() === 'sqlite') {
        $this->markTestSkipped('nullOnDelete FK behavior requires MySQL.');
    }

    $user = User::factory()->internal()->create();
    $channel = VideoChannel::factory()->create(['user_id' => $user->id]);

    $user->delete();

    $channel->refresh();
    expect($channel->exists)->toBeTrue();
    expect($channel->user_id)->toBeNull();
});

it('has nullable user_id column on video_recordings table', function () {
    $video = VideoRecording::factory()->ready()->create(['user_id' => null]);

    expect($video->exists)->toBeTrue();
    expect($video->user_id)->toBeNull();
});

it('has nullable user_id column on video_channels table', function () {
    $channel = VideoChannel::factory()->create(['user_id' => null]);

    expect($channel->exists)->toBeTrue();
    expect($channel->user_id)->toBeNull();
});

// --- VideoRecordingPolicy Tests ---

it('allows media.manage user to view any video via policy', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();
    $other = User::factory()->internal()->create();
    $video = VideoRecording::factory()->ready()->create(['user_id' => $other->id]);

    expect($admin->can('view', $video))->toBeTrue();
    expect($admin->can('update', $video))->toBeTrue();
    expect($admin->can('delete', $video))->toBeTrue();
});

it('allows owner to manage their own video', function () {
    $user = User::factory()->internal()->create();
    $video = VideoRecording::factory()->ready()->create(['user_id' => $user->id]);

    expect($user->can('view', $video))->toBeTrue();
    expect($user->can('update', $video))->toBeTrue();
    expect($user->can('delete', $video))->toBeTrue();
});

it('prevents non-owner without media.manage from managing video', function () {
    $user = User::factory()->internal()->create();
    $other = User::factory()->internal()->create();
    $video = VideoRecording::factory()->ready()->create(['user_id' => $other->id]);

    expect($user->can('view', $video))->toBeFalse();
    expect($user->can('update', $video))->toBeFalse();
    expect($user->can('delete', $video))->toBeFalse();
});

// --- VideoChannelPolicy Tests ---

it('allows media.manage user to view any channel via policy', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();
    $other = User::factory()->internal()->create();
    $channel = VideoChannel::factory()->create(['user_id' => $other->id]);

    expect($admin->can('view', $channel))->toBeTrue();
    expect($admin->can('update', $channel))->toBeTrue();
    expect($admin->can('delete', $channel))->toBeTrue();
});

it('allows owner to manage their own channel', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('media.manage_channels');
    $channel = VideoChannel::factory()->create(['user_id' => $user->id]);

    expect($user->can('view', $channel))->toBeTrue();
    expect($user->can('update', $channel))->toBeTrue();
    expect($user->can('delete', $channel))->toBeTrue();
});

it('prevents non-owner without media.manage from managing channel', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('media.manage_channels');
    $other = User::factory()->internal()->create();
    $channel = VideoChannel::factory()->create(['user_id' => $other->id]);

    expect($user->can('view', $channel))->toBeFalse();
    expect($user->can('update', $channel))->toBeFalse();
    expect($user->can('delete', $channel))->toBeFalse();
});

// --- Media Hub Tab Visibility ---

it('shows All Videos tab only for media.manage users', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($admin)
        ->test('media.hub')
        ->assertSee('All Videos');
});

it('hides All Videos tab from users without media.manage', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('media.view');

    Livewire::actingAs($user)
        ->test('media.hub')
        ->assertDontSee('All Videos');
});

// --- Company Videos Component Access ---

it('blocks company-videos component for non-admin users', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('media.view');

    Livewire::actingAs($user)
        ->test('media.company-videos')
        ->assertForbidden();
});

it('shows all company videos for media.manage users', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();
    $other = User::factory()->internal()->create();

    VideoRecording::factory()->ready()->create([
        'user_id' => $admin->id,
        'title' => 'Admin Video',
        'visibility' => 'public',
        'recordable_type' => null,
    ]);
    VideoRecording::factory()->ready()->create([
        'user_id' => $other->id,
        'title' => 'Other User Video',
        'visibility' => 'public',
        'recordable_type' => null,
    ]);

    Livewire::actingAs($admin)
        ->test('media.company-videos')
        ->assertSee('Admin Video')
        ->assertSee('Other User Video');
});

// --- Channel List Ownership Scoping ---

it('shows all channels for media.manage users in channel list', function () {
    $admin = User::factory()->internal()->withFullAccess()->create();
    $other = User::factory()->internal()->create();

    VideoChannel::factory()->create(['user_id' => $admin->id, 'name' => 'Admin Channel']);
    VideoChannel::factory()->create(['user_id' => $other->id, 'name' => 'Other Channel']);

    Livewire::actingAs($admin)
        ->test('media.channel-list')
        ->assertSee('Admin Channel')
        ->assertSee('Other Channel');
});

it('shows only own channels for non-admin users with manage_channels', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('media.manage_channels');

    $other = User::factory()->internal()->create();

    VideoChannel::factory()->create(['user_id' => $user->id, 'name' => 'My Channel']);
    VideoChannel::factory()->create(['user_id' => $other->id, 'name' => 'Not My Channel']);

    Livewire::actingAs($user)
        ->test('media.channel-list')
        ->assertSee('My Channel')
        ->assertDontSee('Not My Channel');
});
