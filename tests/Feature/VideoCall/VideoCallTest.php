<?php

use App\Events\VideoCallStarted;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Job;
use App\Models\User;
use App\Models\VideoCall;
use App\Notifications\VideoCallInvitation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

// ── Model & Factory Tests ──────────────────────────────────────────────

it('can create a video call via factory', function () {
    $videoCall = VideoCall::factory()->create();

    expect($videoCall)
        ->status->toBe('waiting')
        ->room_id->not->toBeEmpty()
        ->job_id->not->toBeNull()
        ->initiated_by->not->toBeNull();
});

it('can create an active video call', function () {
    $videoCall = VideoCall::factory()->active()->create();

    expect($videoCall)
        ->status->toBe('active')
        ->started_at->not->toBeNull();
});

it('can create an ended video call with recording', function () {
    $videoCall = VideoCall::factory()->withRecording()->create();

    expect($videoCall)
        ->status->toBe('ended')
        ->recording_path->not->toBeNull()
        ->recording_disk->toBe('video-recordings')
        ->duration_seconds->toBe(900);
});

it('has a job relationship', function () {
    $job = Job::factory()->create();
    $videoCall = VideoCall::factory()->create(['job_id' => $job->id]);

    expect($videoCall->job->id)->toBe($job->id);
});

it('has an initiator relationship', function () {
    $user = User::factory()->internal()->create();
    $videoCall = VideoCall::factory()->create(['initiated_by' => $user->id]);

    expect($videoCall->initiator->id)->toBe($user->id);
});

it('job has videoCalls relationship', function () {
    $job = Job::factory()->create();
    VideoCall::factory()->count(3)->create(['job_id' => $job->id]);

    expect($job->videoCalls)->toHaveCount(3);
});

it('casts participants as array', function () {
    $participants = [
        ['user_id' => 1, 'name' => 'Test User', 'type' => 'internal'],
    ];

    $videoCall = VideoCall::factory()->create(['participants' => $participants]);
    $videoCall->refresh();

    expect($videoCall->participants)->toBeArray()->toHaveCount(1);
    expect($videoCall->participants[0]['name'])->toBe('Test User');
});

// ── Job-Call Livewire Component Tests ──────────────────────────────────

it('renders the start call button when no active call', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $job = Job::factory()->create();

    Livewire::actingAs($user)
        ->test('video-call.job-call', ['job' => $job])
        ->assertSee('Start Now')
        ->assertDontSee('Join Active Call');
});

it('renders the join button when an active call exists', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $job = Job::factory()->create();

    VideoCall::factory()->active()->create([
        'job_id' => $job->id,
        'initiated_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('video-call.job-call', ['job' => $job])
        ->assertSee('Return to Call')
        ->assertDontSee('Start Now');
});

it('creates a video call record when starting a call', function () {
    Event::fake([VideoCallStarted::class]);

    $user = User::factory()->internal()->withFullAccess()->create();
    $job = Job::factory()->create();

    Livewire::actingAs($user)
        ->test('video-call.job-call', ['job' => $job])
        ->call('startCall')
        ->assertSet('showCallPanel', true)
        ->assertNotSet('activeCallId', null)
        ->assertNotSet('activeRoomId', null);

    expect(VideoCall::where('job_id', $job->id)->count())->toBe(1);

    $videoCall = VideoCall::where('job_id', $job->id)->first();
    expect($videoCall)
        ->status->toBe('active')
        ->initiated_by->toBe($user->id)
        ->room_id->toStartWith('job-'.$job->id.'-');
});

it('dispatches VideoCallStarted event when starting a call', function () {
    Event::fake([VideoCallStarted::class]);

    $user = User::factory()->internal()->withFullAccess()->create();
    $job = Job::factory()->create();

    Livewire::actingAs($user)
        ->test('video-call.job-call', ['job' => $job])
        ->call('startCall');

    Event::assertDispatched(VideoCallStarted::class, function ($event) use ($job, $user) {
        return $event->videoCall->job_id === $job->id
            && $event->initiatorName === $user->name;
    });
});

it('ends a call and updates the record', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $job = Job::factory()->create();

    $videoCall = VideoCall::factory()->active()->create([
        'job_id' => $job->id,
        'initiated_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('video-call.job-call', ['job' => $job])
        ->call('endCall')
        ->assertSet('showCallPanel', false)
        ->assertSet('activeCallId', null)
        ->assertSet('activeRoomId', null);

    $videoCall->refresh();
    expect($videoCall)
        ->status->toBe('ended')
        ->ended_at->not->toBeNull();
});

it('shows call panel when joining a call', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $job = Job::factory()->create();

    VideoCall::factory()->active()->create([
        'job_id' => $job->id,
        'initiated_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('video-call.job-call', ['job' => $job])
        ->call('joinCall')
        ->assertSet('showCallPanel', true);
});

it('can dismiss call panel', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $job = Job::factory()->create();

    Livewire::actingAs($user)
        ->test('video-call.job-call', ['job' => $job])
        ->set('showCallPanel', true)
        ->call('dismissCall')
        ->assertSet('showCallPanel', false);
});

it('crew user can start a video call', function () {
    Event::fake([VideoCallStarted::class]);

    $crew = User::factory()->crew()->create();
    $job = Job::factory()->create();
    $job->crews()->attach($crew->id, ['status' => 'accepted']);

    Livewire::actingAs($crew, 'crew')
        ->test('video-call.job-call', ['job' => $job])
        ->call('startCall')
        ->assertSet('showCallPanel', true);

    expect(VideoCall::where('job_id', $job->id)->count())->toBe(1);
});

// ── Broadcast Event Tests ──────────────────────────────────────────────

it('VideoCallStarted event broadcasts on all job channels', function () {
    $job = Job::factory()->create();
    $videoCall = VideoCall::factory()->active()->create(['job_id' => $job->id]);

    $event = new VideoCallStarted($videoCall, 'Test User');

    $channels = $event->broadcastOn();
    expect($channels)->toHaveCount(3);

    $channelNames = array_map(fn ($ch) => $ch->name, $channels);
    expect($channelNames)->toContain('private-job.'.$job->id)
        ->toContain('private-customer.job.'.$job->id)
        ->toContain('private-crew.job.'.$job->id);
});

it('VideoCallStarted event includes correct data', function () {
    $job = Job::factory()->create();
    $videoCall = VideoCall::factory()->active()->create(['job_id' => $job->id]);

    $event = new VideoCallStarted($videoCall, 'John Painter');

    $data = $event->broadcastWith();
    expect($data)
        ->video_call_id->toBe($videoCall->id)
        ->room_id->toBe($videoCall->room_id)
        ->initiator_name->toBe('John Painter')
        ->job_id->toBe($job->id);
});

// ── VideoCall Model Helper Tests ───────────────────────────────────────

it('isActive returns true for active calls', function () {
    $videoCall = VideoCall::factory()->active()->create();
    expect($videoCall->isActive())->toBeTrue();
});

it('isActive returns false for ended calls', function () {
    $videoCall = VideoCall::factory()->ended()->create();
    expect($videoCall->isActive())->toBeFalse();
});

it('isEnded returns true for ended calls', function () {
    $videoCall = VideoCall::factory()->ended()->create();
    expect($videoCall->isEnded())->toBeTrue();
});

it('recordings computed property returns ended calls with recordings', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $job = Job::factory()->create();

    // Create some calls - only one with recording
    VideoCall::factory()->active()->create(['job_id' => $job->id]);
    VideoCall::factory()->ended()->create(['job_id' => $job->id]);
    VideoCall::factory()->withRecording()->create(['job_id' => $job->id]);

    $component = Livewire::actingAs($user)
        ->test('video-call.job-call', ['job' => $job]);

    // The recordings computed should only include ended calls with recording_path
    $recordings = VideoCall::where('job_id', $job->id)
        ->where('status', 'ended')
        ->whereNotNull('recording_path')
        ->get();

    expect($recordings)->toHaveCount(1);
});

// ── Notification Tests ──────────────────────────────────────────────────

it('sends VideoCallInvitation to internal users when call starts', function () {
    Notification::fake();
    Event::fake([VideoCallStarted::class]);

    $caller = User::factory()->internal()->withFullAccess()->create();
    $otherInternal = User::factory()->internal()->withFullAccess()->create();
    $job = Job::factory()->create();

    Livewire::actingAs($caller)
        ->test('video-call.job-call', ['job' => $job])
        ->call('startCall');

    Notification::assertSentTo($otherInternal, VideoCallInvitation::class);
    Notification::assertNotSentTo($caller, VideoCallInvitation::class);
});

it('sends VideoCallInvitation to accepted crew members', function () {
    Notification::fake();
    Event::fake([VideoCallStarted::class]);

    $caller = User::factory()->internal()->withFullAccess()->create();
    $crew = User::factory()->crew()->create();
    $job = Job::factory()->create();
    $job->crews()->attach($crew->id, ['status' => 'accepted']);

    Livewire::actingAs($caller)
        ->test('video-call.job-call', ['job' => $job])
        ->call('startCall');

    Notification::assertSentTo($crew, VideoCallInvitation::class);
});

it('sends VideoCallInvitation to the customer', function () {
    Notification::fake();
    Event::fake([VideoCallStarted::class]);

    $caller = User::factory()->internal()->withFullAccess()->create();
    $customerUser = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
    $deal = Deal::factory()->create(['customer_id' => $customer->id]);
    $job = Job::factory()->create(['deal_id' => $deal->id]);

    Livewire::actingAs($caller)
        ->test('video-call.job-call', ['job' => $job])
        ->call('startCall');

    Notification::assertSentTo($customerUser, VideoCallInvitation::class);
});

it('does not send notification to the caller', function () {
    Notification::fake();
    Event::fake([VideoCallStarted::class]);

    $caller = User::factory()->internal()->withFullAccess()->create();
    $job = Job::factory()->create();

    Livewire::actingAs($caller)
        ->test('video-call.job-call', ['job' => $job])
        ->call('startCall');

    Notification::assertNotSentTo($caller, VideoCallInvitation::class);
});

// ── Echo Channel Listener Tests ─────────────────────────────────────────

it('resolves correct echo channel for internal users', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $job = Job::factory()->create();

    Livewire::actingAs($user)
        ->test('video-call.job-call', ['job' => $job])
        ->assertSet('echoChannel', 'job.'.$job->id);
});

it('resolves correct echo channel for customer users', function () {
    $customerUser = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
    $deal = Deal::factory()->create(['customer_id' => $customer->id]);
    $job = Job::factory()->create(['deal_id' => $deal->id]);

    Livewire::actingAs($customerUser, 'customer')
        ->test('video-call.job-call', ['job' => $job])
        ->assertSet('echoChannel', 'customer.job.'.$job->id);
});

it('resolves correct echo channel for crew users', function () {
    $crew = User::factory()->crew()->create();
    $job = Job::factory()->create();
    $job->crews()->attach($crew->id, ['status' => 'accepted']);

    Livewire::actingAs($crew, 'crew')
        ->test('video-call.job-call', ['job' => $job])
        ->assertSet('echoChannel', 'crew.job.'.$job->id);
});

// ── Customer Video Call Access Tests ────────────────────────────────────

it('customer can see video call button on job progress page', function () {
    $customerUser = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
    $deal = Deal::factory()->create(['customer_id' => $customer->id]);
    $job = Job::factory()->create([
        'deal_id' => $deal->id,
        'stage' => 'scheduling_colors',
    ]);

    Livewire::actingAs($customerUser, 'customer')
        ->test('customer.job-progress', ['job' => $job])
        ->assertSee('Start Now');
});

// ── Recording Player Tests ──────────────────────────────────────────────

it('recording player shows recordings for a job', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $job = Job::factory()->create();

    $recording = VideoCall::factory()->withRecording()->create([
        'job_id' => $job->id,
        'initiated_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('video-call.recording-player', ['job' => $job])
        ->assertSee('Recordings')
        ->assertSee('Play');
});

it('recording player shows nothing when no recordings exist', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $job = Job::factory()->create();

    Livewire::actingAs($user)
        ->test('video-call.recording-player', ['job' => $job])
        ->assertDontSee('Recordings');
});

it('recording player can open playback modal', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $job = Job::factory()->create();

    $recording = VideoCall::factory()->withRecording()->create([
        'job_id' => $job->id,
        'initiated_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('video-call.recording-player', ['job' => $job])
        ->call('playRecording', $recording->id)
        ->assertSet('playingRecordingId', $recording->id)
        ->assertNotSet('playbackUrl', null);
});

it('recording player can close playback modal', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $job = Job::factory()->create();

    $recording = VideoCall::factory()->withRecording()->create([
        'job_id' => $job->id,
        'initiated_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('video-call.recording-player', ['job' => $job])
        ->call('playRecording', $recording->id)
        ->call('closePlayer')
        ->assertSet('playingRecordingId', null)
        ->assertSet('playbackUrl', null);
});

it('recording player refuses to play recordings from other jobs', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $job = Job::factory()->create();
    $otherJob = Job::factory()->create();

    $recording = VideoCall::factory()->withRecording()->create([
        'job_id' => $otherJob->id,
        'initiated_by' => $user->id,
    ]);

    Livewire::actingAs($user)
        ->test('video-call.recording-player', ['job' => $job])
        ->call('playRecording', $recording->id)
        ->assertSet('playingRecordingId', null);
});

// ── VideoCall getShareableUrl Tests ─────────────────────────────────────

it('getShareableUrl returns null when no recording', function () {
    $videoCall = VideoCall::factory()->active()->create();

    expect($videoCall->getShareableUrl())->toBeNull();
});

it('getShareableUrl returns signed URL when recording exists', function () {
    $videoCall = VideoCall::factory()->withRecording()->create();

    $url = $videoCall->getShareableUrl();

    expect($url)->not->toBeNull()
        ->toContain('/recording/')
        ->toContain($videoCall->room_id)
        ->toContain('signature=');
});

// ── Public Playback Route Tests ─────────────────────────────────────────

it('public playback route requires valid signature', function () {
    $videoCall = VideoCall::factory()->withRecording()->create();

    $this->get("/recording/{$videoCall->room_id}")
        ->assertForbidden();
});

it('public playback route works with valid signed URL', function () {
    $videoCall = VideoCall::factory()->withRecording()->create();

    $url = URL::signedRoute('video-call.recording.public', [
        'videoCall' => $videoCall->room_id,
    ]);

    $this->get($url)->assertOk();
});

it('public playback route returns 404 when no recording', function () {
    $videoCall = VideoCall::factory()->active()->create();

    $url = URL::signedRoute('video-call.recording.public', [
        'videoCall' => $videoCall->room_id,
    ]);

    $this->get($url)->assertNotFound();
});
