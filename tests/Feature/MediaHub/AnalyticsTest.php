<?php

use App\Models\CompanySetting;
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

// --- Analytics Overview ---

it('requires media.manage permission for analytics overview', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('media.view');

    Livewire::actingAs($user)
        ->test('media.analytics-overview')
        ->assertForbidden();
});

it('renders analytics overview for authorized users', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo(['media.view', 'media.manage']);

    Livewire::actingAs($user)
        ->test('media.analytics-overview')
        ->assertSuccessful();
});

it('shows view counts correctly on analytics overview', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $recording = VideoRecording::factory()->ready()->create();
    VideoView::factory()->count(7)->create(['video_recording_id' => $recording->id]);

    Livewire::actingAs($user)
        ->test('media.analytics-overview')
        ->assertSee('7');
});

// --- Share Page ---

it('shows video player on share page', function () {
    $recording = VideoRecording::factory()->ready()->create();

    $this->get('/v/'.$recording->share_token)
        ->assertOk()
        ->assertSee($recording->title)
        ->assertSee('data-track-id="'.$recording->id.'"', escape: false);
});

it('includes video tracker JS on share page', function () {
    $recording = VideoRecording::factory()->ready()->create();

    $this->get('/v/'.$recording->share_token)
        ->assertOk()
        ->assertSee('sendBeacon', escape: false)
        ->assertSee('VideoTracker', escape: false)
        ->assertSee('/api/video-views', escape: false);
});

it('handles email ref tracking and marks VideoEmail as clicked', function () {
    $recording = VideoRecording::factory()->ready()->create();
    $email = VideoEmail::factory()->create([
        'video_recording_id' => $recording->id,
    ]);

    expect($email->clicked_at)->toBeNull();

    $this->get('/v/'.$recording->share_token.'?ref=email&eid='.$email->id)
        ->assertOk();

    $email->refresh();
    expect($email->clicked_at)->not->toBeNull();
});

it('shows company branding on share page', function () {
    CompanySetting::create([
        'company_name' => 'Test Painting Co',
    ]);

    $recording = VideoRecording::factory()->ready()->create();

    $this->get('/v/'.$recording->share_token)
        ->assertOk()
        ->assertSee('Test Painting Co');
});

it('returns 404 for invalid share token', function () {
    $this->get('/v/nonexistent-token-12345')
        ->assertNotFound();
});

it('returns 404 for non-ready video share token', function () {
    $recording = VideoRecording::factory()->processing()->create();

    $this->get('/v/'.$recording->share_token)
        ->assertNotFound();
});

it('shows video description on share page when present', function () {
    $recording = VideoRecording::factory()->ready()->withDescription()->create();

    $this->get('/v/'.$recording->share_token)
        ->assertOk()
        ->assertSee($recording->description);
});

it('sets track source to email when ref=email query param is present', function () {
    $recording = VideoRecording::factory()->ready()->create();

    $this->get('/v/'.$recording->share_token.'?ref=email')
        ->assertOk()
        ->assertSee('data-track-source="email"', escape: false);
});

it('sets track source to link by default on share page', function () {
    $recording = VideoRecording::factory()->ready()->create();

    $this->get('/v/'.$recording->share_token)
        ->assertOk()
        ->assertSee('data-track-source="link"', escape: false);
});

it('does not mark email as clicked without ref=email param', function () {
    $recording = VideoRecording::factory()->ready()->create();
    $email = VideoEmail::factory()->create([
        'video_recording_id' => $recording->id,
    ]);

    $this->get('/v/'.$recording->share_token.'?eid='.$email->id)
        ->assertOk();

    $email->refresh();
    expect($email->clicked_at)->toBeNull();
});
