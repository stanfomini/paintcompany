<?php

use App\Jobs\Python\ProcessVideoRecordingJob;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\Job;
use App\Models\User;
use App\Models\VideoRecording;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

// ── Model & Factory Tests ──────────────────────────────────────────────

it('auto-generates share token on creation', function () {
    $recording = VideoRecording::factory()->create();

    expect($recording->share_token)->not->toBeNull()
        ->and(strlen($recording->share_token))->toBe(32);
});

it('has correct status helpers', function () {
    $uploading = VideoRecording::factory()->create(['status' => 'uploading']);
    $processing = VideoRecording::factory()->processing()->create();
    $ready = VideoRecording::factory()->ready()->create();
    $failed = VideoRecording::factory()->failed()->create();

    expect($uploading->isProcessing())->toBeTrue()
        ->and($uploading->isReady())->toBeFalse()
        ->and($processing->isProcessing())->toBeTrue()
        ->and($ready->isReady())->toBeTrue()
        ->and($ready->isProcessing())->toBeFalse()
        ->and($failed->isFailed())->toBeTrue();
});

it('formats duration correctly', function () {
    $recording = VideoRecording::factory()->ready()->create(['duration_seconds' => 95]);

    expect($recording->formatted_duration)->toBe('1:35');
});

it('returns null formatted duration when no duration', function () {
    $recording = VideoRecording::factory()->create(['duration_seconds' => null]);

    expect($recording->formatted_duration)->toBeNull();
});

it('belongs to a user', function () {
    $user = User::factory()->internal()->create();
    $recording = VideoRecording::factory()->create(['user_id' => $user->id]);

    expect($recording->user->id)->toBe($user->id);
});

it('can be polymorphically attached to an estimate', function () {
    $estimate = Estimate::factory()->forDeal(
        Deal::factory()->forCustomer(Customer::factory()->create())->create()
    )->create();

    $recording = VideoRecording::factory()->forEstimate($estimate)->create();

    expect($recording->recordable)->toBeInstanceOf(Estimate::class)
        ->and($recording->recordable->id)->toBe($estimate->id)
        ->and($estimate->videoRecordings)->toHaveCount(1);
});

// ── Upload Controller Tests ────────────────────────────────────────────

it('requires authentication to upload a recording', function () {
    $this->postJson(route('video-recordings.store'), [])
        ->assertUnauthorized();
});

it('validates upload request', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $this->actingAs($user)
        ->postJson(route('video-recordings.store'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['file']);
});

it('allows authenticated users to upload a video recording', function () {
    Storage::fake('video-recordings');
    Queue::fake();

    $user = User::factory()->internal()->withFullAccess()->create();
    $file = UploadedFile::fake()->create('walkaround.webm', 5000, 'video/webm');

    $this->actingAs($user)
        ->postJson(route('video-recordings.store'), [
            'file' => $file,
            'title' => 'Living Room Walkaround',
        ])
        ->assertCreated()
        ->assertJsonStructure(['id', 'status', 'share_token']);

    $this->assertDatabaseHas('video_recordings', [
        'user_id' => $user->id,
        'title' => 'Living Room Walkaround',
        'status' => 'uploading',
    ]);

    Queue::assertPushed(ProcessVideoRecordingJob::class);
});

it('allows customer users to upload recordings', function () {
    Storage::fake('video-recordings');
    Queue::fake();

    $user = User::factory()->customer()->create();
    $file = UploadedFile::fake()->create('walkaround.webm', 5000, 'video/webm');

    $this->actingAs($user, 'customer')
        ->postJson(route('video-recordings.store'), [
            'file' => $file,
        ])
        ->assertCreated();
});

it('stores recording with polymorphic recordable', function () {
    Storage::fake('video-recordings');
    Queue::fake();

    $user = User::factory()->internal()->withFullAccess()->create();
    $estimate = Estimate::factory()->forDeal(
        Deal::factory()->forCustomer(Customer::factory()->create())->create()
    )->create();

    $file = UploadedFile::fake()->create('walkaround.webm', 5000, 'video/webm');

    $this->actingAs($user)
        ->postJson(route('video-recordings.store'), [
            'file' => $file,
            'recordable_type' => Estimate::class,
            'recordable_id' => $estimate->id,
        ])
        ->assertCreated();

    $this->assertDatabaseHas('video_recordings', [
        'recordable_type' => Estimate::class,
        'recordable_id' => $estimate->id,
    ]);
});

// ── Chunked Upload Tests ──────────────────────────────────────────────

it('can start a chunked upload session', function () {
    Storage::fake('video-recordings');

    $user = User::factory()->internal()->withFullAccess()->create();

    $this->actingAs($user)
        ->postJson(route('video-recordings.start'), [
            'recordable_type' => Estimate::class,
            'recordable_id' => 1,
            'mime_type' => 'video/webm',
            'title' => 'Test Chunked Upload',
        ])
        ->assertCreated()
        ->assertJsonStructure(['id', 'status', 'share_token']);

    $this->assertDatabaseHas('video_recordings', [
        'user_id' => $user->id,
        'status' => 'uploading',
        'title' => 'Test Chunked Upload',
        'mime_type' => 'video/webm',
    ]);
});

it('validates chunked upload start request', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    $this->actingAs($user)
        ->postJson(route('video-recordings.start'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['mime_type']);
});

it('requires authentication to start a chunked upload', function () {
    $this->postJson(route('video-recordings.start'), [
        'mime_type' => 'video/webm',
    ])->assertUnauthorized();
});

it('can upload chunks to a recording', function () {
    Storage::fake('video-recordings');

    $user = User::factory()->internal()->withFullAccess()->create();

    // Start session
    $startResponse = $this->actingAs($user)
        ->postJson(route('video-recordings.start'), [
            'mime_type' => 'video/webm',
        ])
        ->assertCreated();

    $recordingId = $startResponse->json('id');
    $recording = VideoRecording::find($recordingId);

    // Upload chunk 0
    $chunk0 = UploadedFile::fake()->create('chunk_0.webm', 200, 'video/webm');

    $this->actingAs($user)
        ->postJson(route('video-recordings.chunk', $recording), [
            'chunk' => $chunk0,
            'chunk_index' => 0,
        ])
        ->assertSuccessful()
        ->assertJsonPath('received', true)
        ->assertJsonPath('chunk_index', 0);

    // Upload chunk 1
    $chunk1 = UploadedFile::fake()->create('chunk_1.webm', 200, 'video/webm');

    $this->actingAs($user)
        ->postJson(route('video-recordings.chunk', $recording), [
            'chunk' => $chunk1,
            'chunk_index' => 1,
        ])
        ->assertSuccessful()
        ->assertJsonPath('chunk_index', 1);
});

it('rejects chunks from non-owner', function () {
    Storage::fake('video-recordings');

    $owner = User::factory()->internal()->withFullAccess()->create();
    $other = User::factory()->internal()->create();

    $recording = VideoRecording::factory()->create([
        'user_id' => $owner->id,
        'status' => 'uploading',
        'original_path' => 'originals/test.webm',
    ]);
    Storage::disk('video-recordings')->put($recording->original_path, '');

    $chunk = UploadedFile::fake()->create('chunk.webm', 200, 'video/webm');

    $this->actingAs($other)
        ->postJson(route('video-recordings.chunk', $recording), [
            'chunk' => $chunk,
            'chunk_index' => 0,
        ])
        ->assertForbidden();
});

it('rejects chunks for non-uploading recordings', function () {
    Storage::fake('video-recordings');

    $user = User::factory()->internal()->withFullAccess()->create();

    $recording = VideoRecording::factory()->processing()->create([
        'user_id' => $user->id,
    ]);

    $chunk = UploadedFile::fake()->create('chunk.webm', 200, 'video/webm');

    $this->actingAs($user)
        ->postJson(route('video-recordings.chunk', $recording), [
            'chunk' => $chunk,
            'chunk_index' => 0,
        ])
        ->assertUnprocessable();
});

it('can finalize a chunked upload', function () {
    Storage::fake('video-recordings');
    Queue::fake();

    $user = User::factory()->internal()->withFullAccess()->create();

    // Start session
    $startResponse = $this->actingAs($user)
        ->postJson(route('video-recordings.start'), [
            'mime_type' => 'video/webm',
        ])
        ->assertCreated();

    $recordingId = $startResponse->json('id');
    $recording = VideoRecording::find($recordingId);

    // Upload a chunk with actual content so file has data
    $chunk = UploadedFile::fake()->createWithContent('chunk.webm', str_repeat('x', 1024));

    $this->actingAs($user)
        ->postJson(route('video-recordings.chunk', $recording), [
            'chunk' => $chunk,
            'chunk_index' => 0,
        ])
        ->assertSuccessful();

    // Finalize
    $this->actingAs($user)
        ->postJson(route('video-recordings.finalize', $recording))
        ->assertSuccessful()
        ->assertJsonPath('status', 'processing')
        ->assertJsonStructure(['id', 'status', 'share_token']);

    expect($recording->fresh()->status)->toBe('processing');
});

it('dispatches processing job on finalize', function () {
    Storage::fake('video-recordings');
    Queue::fake();

    $user = User::factory()->internal()->withFullAccess()->create();

    $startResponse = $this->actingAs($user)
        ->postJson(route('video-recordings.start'), [
            'mime_type' => 'video/webm',
        ])
        ->assertCreated();

    $recordingId = $startResponse->json('id');
    $recording = VideoRecording::find($recordingId);

    // Upload a chunk with actual content
    $chunk = UploadedFile::fake()->createWithContent('chunk.webm', str_repeat('x', 1024));
    $this->actingAs($user)
        ->postJson(route('video-recordings.chunk', $recording), [
            'chunk' => $chunk,
            'chunk_index' => 0,
        ]);

    // Finalize
    $this->actingAs($user)
        ->postJson(route('video-recordings.finalize', $recording));

    Queue::assertPushed(ProcessVideoRecordingJob::class, function ($job) use ($recording) {
        return $job->recording->id === $recording->id;
    });
});

it('rejects finalize for empty recordings', function () {
    Storage::fake('video-recordings');

    $user = User::factory()->internal()->withFullAccess()->create();

    // Start session (creates empty file)
    $startResponse = $this->actingAs($user)
        ->postJson(route('video-recordings.start'), [
            'mime_type' => 'video/webm',
        ])
        ->assertCreated();

    $recordingId = $startResponse->json('id');
    $recording = VideoRecording::find($recordingId);

    // Try to finalize without uploading any chunks
    $this->actingAs($user)
        ->postJson(route('video-recordings.finalize', $recording))
        ->assertUnprocessable();
});

it('rejects finalize from non-owner', function () {
    Storage::fake('video-recordings');

    $owner = User::factory()->internal()->withFullAccess()->create();
    $other = User::factory()->internal()->create();

    $recording = VideoRecording::factory()->create([
        'user_id' => $owner->id,
        'status' => 'uploading',
        'original_path' => 'originals/test.webm',
    ]);
    Storage::disk('video-recordings')->put($recording->original_path, 'data');

    $this->actingAs($other)
        ->postJson(route('video-recordings.finalize', $recording))
        ->assertForbidden();
});

it('allows customer users to use chunked upload', function () {
    Storage::fake('video-recordings');
    Queue::fake();

    $user = User::factory()->customer()->create();

    $this->actingAs($user, 'customer')
        ->postJson(route('video-recordings.start'), [
            'mime_type' => 'video/webm',
        ])
        ->assertCreated();
});

it('can start chunked upload with visibility parameter', function () {
    Storage::fake('video-recordings');

    $user = User::factory()->internal()->withFullAccess()->create();

    $this->actingAs($user)
        ->postJson(route('video-recordings.start'), [
            'mime_type' => 'video/webm',
            'visibility' => 'public',
        ])
        ->assertCreated();

    $recording = VideoRecording::latest()->first();
    expect($recording->visibility)->toBe('public');
});

it('defaults visibility to unlisted when not specified', function () {
    Storage::fake('video-recordings');

    $user = User::factory()->internal()->withFullAccess()->create();

    $this->actingAs($user)
        ->postJson(route('video-recordings.start'), [
            'mime_type' => 'video/webm',
        ])
        ->assertCreated();

    $recording = VideoRecording::latest()->first();
    expect($recording->visibility)->toBe('unlisted');
});

// ── Mime Type Extension Mapping Tests ────────────────────────────────────

it('can start chunked upload with mp4 mime type', function () {
    Storage::fake('video-recordings');

    $user = User::factory()->internal()->withFullAccess()->create();

    $this->actingAs($user)
        ->postJson(route('video-recordings.start'), [
            'mime_type' => 'video/mp4',
            'title' => 'MP4 Upload',
        ])
        ->assertCreated();

    $recording = VideoRecording::latest()->first();
    expect($recording->original_path)->toEndWith('.mp4');
});

it('can start chunked upload with quicktime mime type', function () {
    Storage::fake('video-recordings');

    $user = User::factory()->internal()->withFullAccess()->create();

    $this->actingAs($user)
        ->postJson(route('video-recordings.start'), [
            'mime_type' => 'video/quicktime',
            'title' => 'MOV Upload',
        ])
        ->assertCreated();

    $recording = VideoRecording::latest()->first();
    expect($recording->original_path)->toEndWith('.mov');
});

it('can start chunked upload with matroska mime type', function () {
    Storage::fake('video-recordings');

    $user = User::factory()->internal()->withFullAccess()->create();

    $this->actingAs($user)
        ->postJson(route('video-recordings.start'), [
            'mime_type' => 'video/x-matroska',
            'title' => 'MKV Upload',
        ])
        ->assertCreated();

    $recording = VideoRecording::latest()->first();
    expect($recording->original_path)->toEndWith('.mkv');
});

// ── Status Endpoint Tests ──────────────────────────────────────────────

it('returns recording status and urls', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $recording = VideoRecording::factory()->ready()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->getJson(route('video-recordings.status', $recording))
        ->assertSuccessful()
        ->assertJsonStructure([
            'id', 'status', 'title', 'video_url', 'thumbnail_url',
            'share_url', 'duration_seconds', 'formatted_duration',
        ]);
});

// ── Title Update Tests ─────────────────────────────────────────────────

it('allows owner to update recording title', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $recording = VideoRecording::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->patchJson(route('video-recordings.update-title', $recording), [
            'title' => 'Updated Title',
        ])
        ->assertSuccessful()
        ->assertJsonPath('title', 'Updated Title');

    expect($recording->fresh()->title)->toBe('Updated Title');
});

it('prevents non-owner from updating recording title', function () {
    $owner = User::factory()->internal()->withFullAccess()->create();
    $other = User::factory()->internal()->create();
    $recording = VideoRecording::factory()->create(['user_id' => $owner->id]);

    $this->actingAs($other)
        ->patchJson(route('video-recordings.update-title', $recording), [
            'title' => 'Hacked Title',
        ])
        ->assertForbidden();
});

// ── Delete Tests ───────────────────────────────────────────────────────

it('allows owner to delete a recording', function () {
    Storage::fake('video-recordings');

    $user = User::factory()->internal()->withFullAccess()->create();
    $recording = VideoRecording::factory()->ready()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->deleteJson(route('video-recordings.destroy', $recording))
        ->assertSuccessful()
        ->assertJsonPath('deleted', true);

    $this->assertDatabaseMissing('video_recordings', ['id' => $recording->id]);
});

it('prevents non-owner from deleting a recording', function () {
    $owner = User::factory()->internal()->withFullAccess()->create();
    $other = User::factory()->internal()->create();
    $recording = VideoRecording::factory()->create(['user_id' => $owner->id]);

    $this->actingAs($other)
        ->deleteJson(route('video-recordings.destroy', $recording))
        ->assertForbidden();
});

// ── Public Playback Tests ──────────────────────────────────────────────

it('renders public playback page for ready recordings', function () {
    $recording = VideoRecording::factory()->ready()->create([
        'title' => 'Kitchen Walkaround',
    ]);

    $this->get(route('video-recording.public', $recording->share_token))
        ->assertSuccessful()
        ->assertSee('Kitchen Walkaround');
});

it('returns 404 for non-existent share token', function () {
    $this->get(route('video-recording.public', 'nonexistent-token-12345678'))
        ->assertNotFound();
});

it('shows processing page for non-ready recording share token', function () {
    $recording = VideoRecording::factory()->processing()->create();

    $this->get(route('video-recording.public', $recording->share_token))
        ->assertSuccessful()
        ->assertSee('Your video is being processed');
});

it('shows error page for failed recording share token', function () {
    $recording = VideoRecording::factory()->failed()->create();

    $this->get(route('video-recording.public', $recording->share_token))
        ->assertSuccessful()
        ->assertSee('Video Unavailable');
});

// ── Walkarounds Livewire Component Tests ───────────────────────────────

it('renders the walkarounds component on estimate show page', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $estimate = Estimate::factory()->forDeal(
        Deal::factory()->forCustomer(Customer::factory()->create())->create()
    )->create();

    Livewire::actingAs($user)
        ->test('estimates.video-walkarounds', ['recordable' => $estimate])
        ->assertStatus(200)
        ->assertSee('Video Walkarounds');
});

it('displays existing recordings in the walkarounds component', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $estimate = Estimate::factory()->forDeal(
        Deal::factory()->forCustomer(Customer::factory()->create())->create()
    )->create();

    VideoRecording::factory()->ready()->forEstimate($estimate)->create([
        'user_id' => $user->id,
        'title' => 'Master Bedroom Tour',
    ]);

    Livewire::actingAs($user)
        ->test('estimates.video-walkarounds', ['recordable' => $estimate])
        ->assertSee('Master Bedroom Tour');
});

it('can update a recording title from walkarounds component', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $estimate = Estimate::factory()->forDeal(
        Deal::factory()->forCustomer(Customer::factory()->create())->create()
    )->create();

    $recording = VideoRecording::factory()->ready()->forEstimate($estimate)->create([
        'user_id' => $user->id,
        'title' => 'Old Title',
    ]);

    Livewire::actingAs($user)
        ->test('estimates.video-walkarounds', ['recordable' => $estimate])
        ->call('updateTitle', $recording->id, 'New Title');

    expect($recording->fresh()->title)->toBe('New Title');
});

it('can delete a recording from walkarounds component', function () {
    Storage::fake('video-recordings');

    $user = User::factory()->internal()->withFullAccess()->create();
    $estimate = Estimate::factory()->forDeal(
        Deal::factory()->forCustomer(Customer::factory()->create())->create()
    )->create();

    $recording = VideoRecording::factory()->ready()->forEstimate($estimate)->create([
        'user_id' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('estimates.video-walkarounds', ['recordable' => $estimate])
        ->call('deleteRecording', $recording->id);

    $this->assertDatabaseMissing('video_recordings', ['id' => $recording->id]);
});

it('prevents non-owner from deleting recording via walkarounds component', function () {
    $owner = User::factory()->internal()->withFullAccess()->create();
    $other = User::factory()->internal()->withFullAccess()->create();
    $estimate = Estimate::factory()->forDeal(
        Deal::factory()->forCustomer(Customer::factory()->create())->create()
    )->create();

    $recording = VideoRecording::factory()->ready()->forEstimate($estimate)->create([
        'user_id' => $owner->id,
    ]);

    Livewire::actingAs($other)
        ->test('estimates.video-walkarounds', ['recordable' => $estimate])
        ->call('deleteRecording', $recording->id);

    // Recording should still exist
    $this->assertDatabaseHas('video_recordings', ['id' => $recording->id]);
});

// ── Job Walkarounds Tests ──────────────────────────────────────────────

it('renders the walkarounds component with a job recordable', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $job = Job::factory()->create();

    Livewire::actingAs($user)
        ->test('estimates.video-walkarounds', ['recordable' => $job])
        ->assertStatus(200)
        ->assertSee('Video Walkarounds');
});

it('displays job recordings in the walkarounds component', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $job = Job::factory()->create();

    VideoRecording::factory()->ready()->create([
        'user_id' => $user->id,
        'title' => 'Exterior Walkaround',
        'recordable_type' => Job::class,
        'recordable_id' => $job->id,
    ]);

    Livewire::actingAs($user)
        ->test('estimates.video-walkarounds', ['recordable' => $job])
        ->assertSee('Exterior Walkaround');
});

// ── Proposal Visibility Toggle Tests ────────────────────────────────────

it('can toggle show_on_proposal for own recording', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $estimate = Estimate::factory()->forDeal(
        Deal::factory()->forCustomer(Customer::factory()->create())->create()
    )->create();

    $recording = VideoRecording::factory()->ready()->forEstimate($estimate)->create([
        'user_id' => $user->id,
        'show_on_proposal' => false,
    ]);

    Livewire::actingAs($user)
        ->test('estimates.video-walkarounds', ['recordable' => $estimate])
        ->call('toggleProposalVisibility', $recording->id);

    expect($recording->fresh()->show_on_proposal)->toBeTrue();

    // Toggle back off
    Livewire::actingAs($user)
        ->test('estimates.video-walkarounds', ['recordable' => $estimate])
        ->call('toggleProposalVisibility', $recording->id);

    expect($recording->fresh()->show_on_proposal)->toBeFalse();
});

it('cannot toggle show_on_proposal for other users recording', function () {
    $owner = User::factory()->internal()->withFullAccess()->create();
    $other = User::factory()->internal()->withFullAccess()->create();
    $estimate = Estimate::factory()->forDeal(
        Deal::factory()->forCustomer(Customer::factory()->create())->create()
    )->create();

    $recording = VideoRecording::factory()->ready()->forEstimate($estimate)->create([
        'user_id' => $owner->id,
        'show_on_proposal' => false,
    ]);

    Livewire::actingAs($other)
        ->test('estimates.video-walkarounds', ['recordable' => $estimate])
        ->call('toggleProposalVisibility', $recording->id);

    expect($recording->fresh()->show_on_proposal)->toBeFalse();
});

it('shows proposal videos on public estimate proposal page', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $estimate = Estimate::factory()->forDeal(
        Deal::factory()->forCustomer(Customer::factory()->create())->create()
    )->sent()->create([
        'public_token' => 'test-proposal-token-videos-visible',
        'public_token_expires_at' => now()->addDays(30),
    ]);

    VideoRecording::factory()->ready()->forEstimate($estimate)->create([
        'user_id' => $user->id,
        'title' => 'Front Yard Walkaround',
        'show_on_proposal' => true,
    ]);

    $this->get('/proposal/test-proposal-token-videos-visible')
        ->assertSuccessful()
        ->assertSee('Property Walkaround')
        ->assertSee('Front Yard Walkaround');
});

it('hides videos not marked for proposal', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $estimate = Estimate::factory()->forDeal(
        Deal::factory()->forCustomer(Customer::factory()->create())->create()
    )->sent()->create([
        'public_token' => 'test-proposal-token-videos-hidden',
        'public_token_expires_at' => now()->addDays(30),
    ]);

    VideoRecording::factory()->ready()->forEstimate($estimate)->create([
        'user_id' => $user->id,
        'title' => 'Hidden Video Tour',
        'show_on_proposal' => false,
    ]);

    $this->get('/proposal/test-proposal-token-videos-hidden')
        ->assertSuccessful()
        ->assertDontSee('Hidden Video Tour')
        ->assertDontSee('Property Walkaround');
});

it('only shows ready videos on proposal', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $estimate = Estimate::factory()->forDeal(
        Deal::factory()->forCustomer(Customer::factory()->create())->create()
    )->sent()->create([
        'public_token' => 'test-proposal-token-ready-only',
        'public_token_expires_at' => now()->addDays(30),
    ]);

    // Processing video marked for proposal — should NOT appear
    VideoRecording::factory()->processing()->forEstimate($estimate)->create([
        'user_id' => $user->id,
        'title' => 'Still Processing Video',
        'show_on_proposal' => true,
    ]);

    // Ready video marked for proposal — SHOULD appear
    VideoRecording::factory()->ready()->forEstimate($estimate)->create([
        'user_id' => $user->id,
        'title' => 'Ready Walkaround',
        'show_on_proposal' => true,
    ]);

    $this->get('/proposal/test-proposal-token-ready-only')
        ->assertSuccessful()
        ->assertDontSee('Still Processing Video')
        ->assertSee('Ready Walkaround');
});

it('job has videoRecordings relationship', function () {
    $job = Job::factory()->create();

    VideoRecording::factory()->ready()->create([
        'recordable_type' => Job::class,
        'recordable_id' => $job->id,
    ]);

    expect($job->videoRecordings)->toHaveCount(1)
        ->and($job->videoRecordings->first())->toBeInstanceOf(VideoRecording::class);
});

// ── Read-Only Mode Tests ────────────────────────────────────────────────

it('renders in readOnly mode and hides record button', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $job = Job::factory()->create();

    Livewire::actingAs($user)
        ->test('estimates.video-walkarounds', ['recordable' => $job, 'readOnly' => true])
        ->assertStatus(200)
        ->assertDontSee('Record Walkaround');
});

it('shows record button when not in readOnly mode', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $job = Job::factory()->create();

    Livewire::actingAs($user)
        ->test('estimates.video-walkarounds', ['recordable' => $job, 'readOnly' => false])
        ->assertStatus(200)
        ->assertSee('Record Walkaround');
});

it('prevents updating title in readOnly mode', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $job = Job::factory()->create();

    $recording = VideoRecording::factory()->ready()->create([
        'user_id' => $user->id,
        'title' => 'Original Title',
        'recordable_type' => Job::class,
        'recordable_id' => $job->id,
    ]);

    Livewire::actingAs($user)
        ->test('estimates.video-walkarounds', ['recordable' => $job, 'readOnly' => true])
        ->call('updateTitle', $recording->id, 'New Title');

    expect($recording->fresh()->title)->toBe('Original Title');
});

it('prevents deleting recording in readOnly mode', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $job = Job::factory()->create();

    $recording = VideoRecording::factory()->ready()->create([
        'user_id' => $user->id,
        'recordable_type' => Job::class,
        'recordable_id' => $job->id,
    ]);

    Livewire::actingAs($user)
        ->test('estimates.video-walkarounds', ['recordable' => $job, 'readOnly' => true])
        ->call('deleteRecording', $recording->id);

    expect(VideoRecording::find($recording->id))->not->toBeNull();
});
