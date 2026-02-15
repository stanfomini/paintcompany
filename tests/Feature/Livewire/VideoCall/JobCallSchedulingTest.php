<?php

use App\Models\Activity;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Job;
use App\Models\User;
use App\Models\VideoCall;
use Livewire\Livewire;

beforeEach(function () {
    $this->internalUser = User::factory()->internal()->withFullAccess()->create();
    $this->customerUser = User::factory()->customer()->create();
    $this->customer = Customer::factory()->create(['user_id' => $this->customerUser->id]);
    $this->deal = Deal::factory()->create([
        'customer_id' => $this->customer->id,
        'assigned_to' => $this->internalUser->id,
    ]);
    $this->job = Job::factory()->create(['deal_id' => $this->deal->id]);
});

it('renders with Schedule Call and Start Now buttons', function () {
    Livewire::actingAs($this->internalUser)
        ->test('video-call.job-call', ['job' => $this->job])
        ->assertSee('Schedule Call')
        ->assertSee('Start Now')
        ->assertDontSee('Return to Call');
});

it('opens schedule form and shows the modal', function () {
    Livewire::actingAs($this->internalUser)
        ->test('video-call.job-call', ['job' => $this->job])
        ->assertSet('showScheduleForm', false)
        ->call('openScheduleForm')
        ->assertSet('showScheduleForm', true)
        ->assertSee('Schedule a New Meeting');
});

it('closing schedule form hides the modal and resets state', function () {
    Livewire::actingAs($this->internalUser)
        ->test('video-call.job-call', ['job' => $this->job])
        ->call('openScheduleForm')
        ->assertSet('showScheduleForm', true)
        ->call('closeScheduleForm')
        ->assertSet('showScheduleForm', false)
        ->assertSet('meetingTitle', '')
        ->assertSet('meetingDateTime', '')
        ->assertSet('meetingType', 'virtual')
        ->assertSet('meetingDescription', '');
});

it('schedule form defaults title to job title', function () {
    Livewire::actingAs($this->internalUser)
        ->test('video-call.job-call', ['job' => $this->job])
        ->call('openScheduleForm')
        ->assertSet('meetingTitle', 'Video Call - '.$this->job->title);
});

it('internal user can schedule a virtual meeting', function () {
    Livewire::actingAs($this->internalUser)
        ->test('video-call.job-call', ['job' => $this->job])
        ->call('openScheduleForm')
        ->set('meetingTitle', 'Test Virtual Meeting')
        ->set('meetingDateTime', now()->addDay()->format('Y-m-d\TH:i'))
        ->set('meetingType', 'virtual')
        ->set('meetingDescription', 'A test meeting')
        ->call('scheduleMeeting');

    $activity = Activity::where('subject_type', Deal::class)
        ->where('subject_id', $this->deal->id)
        ->where('type', 'meeting')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->title)->toBe('Test Virtual Meeting')
        ->and($activity->meeting_type)->toBe('virtual')
        ->and($activity->meeting_status)->toBeIn(['proposed', 'confirmed'])
        ->and($activity->proposed_by)->toBe($this->internalUser->id);
});

it('customer can schedule a meeting', function () {
    Livewire::actingAs($this->customerUser, 'customer')
        ->test('video-call.job-call', ['job' => $this->job])
        ->call('openScheduleForm')
        ->set('meetingTitle', 'Customer Meeting')
        ->set('meetingDateTime', now()->addDay()->format('Y-m-d\TH:i'))
        ->set('meetingType', 'virtual')
        ->call('scheduleMeeting');

    $activity = Activity::where('subject_type', Deal::class)
        ->where('subject_id', $this->deal->id)
        ->where('type', 'meeting')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->proposed_by)->toBe($this->customerUser->id);
});

it('crew member can schedule a meeting', function () {
    $crewUser = User::factory()->crew()->create();

    Livewire::actingAs($crewUser, 'crew')
        ->test('video-call.job-call', ['job' => $this->job])
        ->call('openScheduleForm')
        ->set('meetingTitle', 'Crew Meeting')
        ->set('meetingDateTime', now()->addDay()->format('Y-m-d\TH:i'))
        ->set('meetingType', 'in_person')
        ->call('scheduleMeeting');

    $activity = Activity::where('subject_type', Deal::class)
        ->where('subject_id', $this->deal->id)
        ->where('type', 'meeting')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->proposed_by)->toBe($crewUser->id);
});

it('validates required fields when scheduling', function () {
    Livewire::actingAs($this->internalUser)
        ->test('video-call.job-call', ['job' => $this->job])
        ->call('openScheduleForm')
        ->set('meetingTitle', '')
        ->set('meetingDateTime', '')
        ->call('scheduleMeeting')
        ->assertHasErrors(['meetingTitle', 'meetingDateTime']);
});

it('validates meeting type must be valid', function () {
    Livewire::actingAs($this->internalUser)
        ->test('video-call.job-call', ['job' => $this->job])
        ->call('openScheduleForm')
        ->set('meetingTitle', 'Test')
        ->set('meetingDateTime', now()->addDay()->format('Y-m-d\TH:i'))
        ->set('meetingType', 'invalid')
        ->call('scheduleMeeting')
        ->assertHasErrors(['meetingType']);
});

it('validates date must be in the future', function () {
    Livewire::actingAs($this->internalUser)
        ->test('video-call.job-call', ['job' => $this->job])
        ->call('openScheduleForm')
        ->set('meetingTitle', 'Test')
        ->set('meetingDateTime', now()->subDay()->format('Y-m-d\TH:i'))
        ->call('scheduleMeeting')
        ->assertHasErrors(['meetingDateTime']);
});

it('creates Activity with correct fields for a scheduled meeting', function () {
    $futureDate = now()->addDay();

    Livewire::actingAs($this->internalUser)
        ->test('video-call.job-call', ['job' => $this->job])
        ->call('openScheduleForm')
        ->set('meetingTitle', 'Correct Fields Test')
        ->set('meetingDateTime', $futureDate->format('Y-m-d\TH:i'))
        ->set('meetingType', 'virtual')
        ->set('meetingDescription', 'Testing all fields')
        ->call('scheduleMeeting');

    $activity = Activity::where('title', 'Correct Fields Test')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->type)->toBe('meeting')
        ->and($activity->meeting_type)->toBe('virtual')
        ->and($activity->meeting_status)->toBeIn(['proposed', 'confirmed'])
        ->and($activity->subject_type)->toBe(Deal::class)
        ->and($activity->subject_id)->toBe($this->deal->id)
        ->and($activity->proposed_by)->toBe($this->internalUser->id)
        ->and($activity->description)->toBe('Testing all fields');
});

it('virtual meeting creates VideoCall with pending status', function () {
    Livewire::actingAs($this->internalUser)
        ->test('video-call.job-call', ['job' => $this->job])
        ->call('openScheduleForm')
        ->set('meetingTitle', 'Virtual Call Test')
        ->set('meetingDateTime', now()->addDay()->format('Y-m-d\TH:i'))
        ->set('meetingType', 'virtual')
        ->call('scheduleMeeting');

    $activity = Activity::where('title', 'Virtual Call Test')->first();

    expect($activity->video_call_id)->not->toBeNull();

    $videoCall = VideoCall::find($activity->video_call_id);

    expect($videoCall)->not->toBeNull()
        ->and($videoCall->status)->toBe('pending')
        ->and($videoCall->room_id)->toStartWith('meeting-');
});

it('in-person meeting does NOT create VideoCall', function () {
    $videoCallCountBefore = VideoCall::count();

    Livewire::actingAs($this->internalUser)
        ->test('video-call.job-call', ['job' => $this->job])
        ->call('openScheduleForm')
        ->set('meetingTitle', 'In Person Test')
        ->set('meetingDateTime', now()->addDay()->format('Y-m-d\TH:i'))
        ->set('meetingType', 'in_person')
        ->call('scheduleMeeting');

    $activity = Activity::where('title', 'In Person Test')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->video_call_id)->toBeNull()
        ->and(VideoCall::count())->toBe($videoCallCountBefore);
});

it('confirm meeting updates status via MeetingService', function () {
    // Create a proposed meeting where customer needs to confirm
    $activity = Activity::factory()
        ->scheduledMeeting('virtual')
        ->forDeal($this->deal)
        ->byUser($this->internalUser)
        ->proposedBy($this->internalUser)
        ->create([
            'team_confirmed_at' => now(),
            'customer_confirmed_at' => null,
        ]);

    Livewire::actingAs($this->customerUser, 'customer')
        ->test('video-call.job-call', ['job' => $this->job])
        ->call('confirmMeeting', $activity->id);

    $activity->refresh();

    expect($activity->customer_confirmed_at)->not->toBeNull()
        ->and($activity->meeting_status)->toBe('confirmed');
});

it('decline meeting updates status to declined', function () {
    $activity = Activity::factory()
        ->scheduledMeeting('virtual')
        ->forDeal($this->deal)
        ->byUser($this->internalUser)
        ->proposedBy($this->internalUser)
        ->create([
            'team_confirmed_at' => now(),
        ]);

    Livewire::actingAs($this->customerUser, 'customer')
        ->test('video-call.job-call', ['job' => $this->job])
        ->call('declineMeeting', $activity->id);

    expect($activity->fresh()->meeting_status)->toBe('declined');
});

it('cancel meeting updates status to cancelled', function () {
    $activity = Activity::factory()
        ->scheduledMeeting('virtual')
        ->forDeal($this->deal)
        ->byUser($this->internalUser)
        ->proposedBy($this->internalUser)
        ->create([
            'team_confirmed_at' => now(),
        ]);

    Livewire::actingAs($this->internalUser)
        ->test('video-call.job-call', ['job' => $this->job])
        ->call('cancelMeeting', $activity->id);

    expect($activity->fresh()->meeting_status)->toBe('cancelled');
});

it('complete meeting marks activity as completed', function () {
    $activity = Activity::factory()
        ->confirmedMeeting('virtual')
        ->forDeal($this->deal)
        ->byUser($this->internalUser)
        ->create();

    Livewire::actingAs($this->internalUser)
        ->test('video-call.job-call', ['job' => $this->job])
        ->call('completeMeeting', $activity->id);

    $activity->refresh();
    expect($activity->is_completed)->toBeTrue()
        ->and($activity->completed_at)->not->toBeNull();
});

it('completing a meeting also ends its video call', function () {
    $videoCall = VideoCall::factory()->active()->create([
        'job_id' => $this->job->id,
        'initiated_by' => $this->internalUser->id,
    ]);

    $activity = Activity::factory()
        ->confirmedMeeting('virtual')
        ->forDeal($this->deal)
        ->byUser($this->internalUser)
        ->create(['video_call_id' => $videoCall->id]);

    Livewire::actingAs($this->internalUser)
        ->test('video-call.job-call', ['job' => $this->job])
        ->call('completeMeeting', $activity->id);

    expect($videoCall->fresh()->status)->toBe('ended');
});

it('completed meetings do not appear in meetings list', function () {
    $activity = Activity::factory()
        ->confirmedMeeting('virtual')
        ->forDeal($this->deal)
        ->byUser($this->internalUser)
        ->create(['title' => 'Done Meeting']);

    $activity->markAsCompleted();

    Livewire::actingAs($this->internalUser)
        ->test('video-call.job-call', ['job' => $this->job])
        ->call('openScheduleForm')
        ->assertDontSee('Done Meeting');
});

it('join scheduled call activates pending VideoCall and shows panel', function () {
    $videoCall = VideoCall::factory()->create([
        'job_id' => $this->job->id,
        'initiated_by' => $this->internalUser->id,
        'status' => 'pending',
    ]);

    $activity = Activity::factory()
        ->confirmedMeeting('virtual')
        ->forDeal($this->deal)
        ->byUser($this->internalUser)
        ->create([
            'video_call_id' => $videoCall->id,
        ]);

    Livewire::actingAs($this->internalUser)
        ->test('video-call.job-call', ['job' => $this->job])
        ->call('joinScheduledCall', $activity->id)
        ->assertSet('showCallPanel', true)
        ->assertSet('activeCallId', $videoCall->id)
        ->assertSet('activeRoomId', $videoCall->room_id);

    expect($videoCall->fresh()->status)->toBe('active')
        ->and($videoCall->fresh()->started_at)->not->toBeNull();
});

it('Start Now still creates instant call', function () {
    Livewire::actingAs($this->internalUser)
        ->test('video-call.job-call', ['job' => $this->job])
        ->call('startCall')
        ->assertSet('showCallPanel', true);

    $videoCall = VideoCall::where('job_id', $this->job->id)
        ->where('status', 'active')
        ->first();

    expect($videoCall)->not->toBeNull()
        ->and($videoCall->initiated_by)->toBe($this->internalUser->id)
        ->and($videoCall->started_at)->not->toBeNull();
});

it('meetings computed shows upcoming proposed and confirmed meetings', function () {
    // Proposed meeting - should show
    Activity::factory()
        ->scheduledMeeting('virtual')
        ->forDeal($this->deal)
        ->byUser($this->internalUser)
        ->create([
            'title' => 'Upcoming Proposed',
            'due_at' => now()->addDay(),
            'meeting_status' => 'proposed',
        ]);

    // Confirmed meeting - should show
    Activity::factory()
        ->confirmedMeeting('virtual')
        ->forDeal($this->deal)
        ->byUser($this->internalUser)
        ->create([
            'title' => 'Upcoming Confirmed',
            'due_at' => now()->addDays(2),
        ]);

    // Declined meeting - should NOT show
    Activity::factory()
        ->scheduledMeeting('virtual')
        ->forDeal($this->deal)
        ->byUser($this->internalUser)
        ->create([
            'title' => 'Declined Meeting',
            'due_at' => now()->addDays(3),
            'meeting_status' => 'declined',
        ]);

    // Completed meeting (past) - should NOT show
    $oldMeeting = Activity::factory()
        ->scheduledMeeting('virtual')
        ->forDeal($this->deal)
        ->byUser($this->internalUser)
        ->create([
            'title' => 'Old Meeting',
            'due_at' => now()->subDays(3),
            'meeting_status' => 'confirmed',
        ]);
    $oldMeeting->markAsCompleted();

    Livewire::actingAs($this->internalUser)
        ->test('video-call.job-call', ['job' => $this->job])
        ->call('openScheduleForm')
        ->assertSee('Upcoming Proposed')
        ->assertSee('Upcoming Confirmed')
        ->assertDontSee('Declined Meeting')
        ->assertDontSee('Old Meeting');
});

it('schedule form closes after successful scheduling', function () {
    Livewire::actingAs($this->internalUser)
        ->test('video-call.job-call', ['job' => $this->job])
        ->call('openScheduleForm')
        ->assertSet('showScheduleForm', true)
        ->set('meetingTitle', 'Close After Schedule')
        ->set('meetingDateTime', now()->addDay()->format('Y-m-d\TH:i'))
        ->set('meetingType', 'virtual')
        ->call('scheduleMeeting')
        ->assertSet('showScheduleForm', false);
});

it('description is optional when scheduling', function () {
    Livewire::actingAs($this->internalUser)
        ->test('video-call.job-call', ['job' => $this->job])
        ->call('openScheduleForm')
        ->set('meetingTitle', 'No Description')
        ->set('meetingDateTime', now()->addDay()->format('Y-m-d\TH:i'))
        ->set('meetingType', 'in_person')
        ->set('meetingDescription', '')
        ->call('scheduleMeeting')
        ->assertHasNoErrors();

    expect(Activity::where('title', 'No Description')->exists())->toBeTrue();
});

it('customer-proposed meeting appears in internal user planner via CommandCenter', function () {
    // Customer proposes a meeting
    Livewire::actingAs($this->customerUser, 'customer')
        ->test('video-call.job-call', ['job' => $this->job])
        ->call('openScheduleForm')
        ->set('meetingTitle', 'Customer Wants to Discuss Colors')
        ->set('meetingDateTime', now()->addDay()->format('Y-m-d\TH:i'))
        ->set('meetingType', 'virtual')
        ->call('scheduleMeeting');

    $activity = Activity::where('title', 'Customer Wants to Discuss Colors')
        ->where('type', 'meeting')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->meeting_status)->toBeIn(['proposed', 'confirmed']);

    // Internal user should see this meeting in their "mine" scope
    // because it needs team confirmation
    $service = app(\App\Services\CommandCenterService::class);
    $result = $service->getActivityStream($this->internalUser, [
        'scope' => 'mine',
        'status' => 'all',
    ]);

    $meetingIds = $result->pluck('id')->toArray();
    expect($meetingIds)->toContain($activity->id);
});

it('customer-proposed meeting appears on internal user calendar', function () {
    $futureDate = now()->addDay();

    Livewire::actingAs($this->customerUser, 'customer')
        ->test('video-call.job-call', ['job' => $this->job])
        ->call('openScheduleForm')
        ->set('meetingTitle', 'Calendar Test Meeting')
        ->set('meetingDateTime', $futureDate->format('Y-m-d\TH:i'))
        ->set('meetingType', 'virtual')
        ->call('scheduleMeeting');

    $calendarService = app(\App\Services\UnifiedCalendarService::class);
    $events = $calendarService->getEventsForDate($this->internalUser, $futureDate);

    $meetingTitles = $events->pluck('title')->toArray();
    expect($meetingTitles)->toContain('Calendar Test Meeting');
});

it('validates description max length', function () {
    Livewire::actingAs($this->internalUser)
        ->test('video-call.job-call', ['job' => $this->job])
        ->call('openScheduleForm')
        ->set('meetingTitle', 'Max Length Test')
        ->set('meetingDateTime', now()->addDay()->format('Y-m-d\TH:i'))
        ->set('meetingDescription', str_repeat('a', 1001))
        ->call('scheduleMeeting')
        ->assertHasErrors(['meetingDescription']);
});
