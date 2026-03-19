<?php

use App\Jobs\ProcessMissedCallJob;
use App\Models\Activity;
use App\Models\CallGroup;
use App\Models\CallLog;
use App\Models\CallLogUser;
use App\Models\Customer;
use App\Models\User;
use App\Notifications\MissedCallNotification;
use App\Notifications\VoicemailReceivedNotification;
use App\Services\MissedCallVoicemailService;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

// ── Group 1: Model Scopes & Helpers ──────────────────────────────────

it('scopes voicemails to voicemail status', function () {
    CallLog::factory()->voicemail()->create();
    CallLog::factory()->completed()->create();
    CallLog::factory()->missed()->create();

    $voicemails = CallLog::voicemails()->get();

    expect($voicemails)->toHaveCount(1)
        ->and($voicemails->first()->status)->toBe('voicemail');
});

it('scopes company-wide voicemails correctly', function () {
    $user = User::factory()->internal()->create();
    CallLog::factory()->voicemail()->companyWide()->create();
    CallLog::factory()->voicemail()->forUser($user)->create();

    $companyWide = CallLog::companyWide()->get();

    expect($companyWide)->toHaveCount(1)
        ->and($companyWide->first()->voicemail_for_user_id)->toBeNull();
});

it('scopes for specific user voicemails', function () {
    $userA = User::factory()->internal()->create();
    $userB = User::factory()->internal()->create();
    CallLog::factory()->voicemail()->forUser($userA)->create();
    CallLog::factory()->voicemail()->forUser($userB)->create();
    CallLog::factory()->voicemail()->companyWide()->create();

    $forUserA = CallLog::forUser($userA->id)->get();

    expect($forUserA)->toHaveCount(1)
        ->and($forUserA->first()->voicemail_for_user_id)->toBe($userA->id);
});

it('scopes unlistened by user', function () {
    $userA = User::factory()->internal()->create();
    $userB = User::factory()->internal()->create();

    $listenedLog = CallLog::factory()->voicemail()->create();
    $unlistenedLog = CallLog::factory()->voicemail()->create();

    $listenedLog->markListenedBy($userA);

    $unlistenedByA = CallLog::unlistenedBy($userA->id)->get();
    $unlistenedByB = CallLog::unlistenedBy($userB->id)->get();

    expect($unlistenedByA)->toHaveCount(1)
        ->and($unlistenedByA->first()->id)->toBe($unlistenedLog->id)
        ->and($unlistenedByB)->toHaveCount(2);
});

it('scopes unhandled correctly', function () {
    $user = User::factory()->internal()->create();

    $handledLog = CallLog::factory()->voicemail()->create();
    $unhandledLog = CallLog::factory()->voicemail()->create();

    $handledLog->markHandledBy($user, 'called_back');

    $unhandled = CallLog::unhandled()->get();

    expect($unhandled)->toHaveCount(1)
        ->and($unhandled->first()->id)->toBe($unhandledLog->id);
});

it('scopes visible to user with no special permissions', function () {
    $user = User::factory()->internal()->create();
    $otherUser = User::factory()->internal()->create();

    $ownVoicemail = CallLog::factory()->voicemail()->forUser($user)->create();
    CallLog::factory()->voicemail()->forUser($otherUser)->create();
    CallLog::factory()->voicemail()->companyWide()->create();

    $visible = CallLog::visibleTo($user)->get();

    expect($visible)->toHaveCount(1)
        ->and($visible->first()->id)->toBe($ownVoicemail->id);
});

it('scopes visible to user with view_company_voicemails permission', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('phone.view_company_voicemails');
    $otherUser = User::factory()->internal()->create();

    $ownVoicemail = CallLog::factory()->voicemail()->forUser($user)->create();
    CallLog::factory()->voicemail()->forUser($otherUser)->create();
    $companyVoicemail = CallLog::factory()->voicemail()->companyWide()->create();

    $visible = CallLog::visibleTo($user)->get();

    expect($visible)->toHaveCount(2)
        ->and($visible->pluck('id')->sort()->values()->toArray())
        ->toBe(collect([$ownVoicemail->id, $companyVoicemail->id])->sort()->values()->toArray());
});

it('scopes visible to user with view_all_voicemails permission', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('phone.view_all_voicemails');
    $otherUser = User::factory()->internal()->create();

    CallLog::factory()->voicemail()->forUser($user)->create();
    CallLog::factory()->voicemail()->forUser($otherUser)->create();
    CallLog::factory()->voicemail()->companyWide()->create();

    $visible = CallLog::visibleTo($user)->get();

    expect($visible)->toHaveCount(3);
});

it('marks call log as listened by user', function () {
    $user = User::factory()->internal()->create();
    $callLog = CallLog::factory()->voicemail()->create();

    $callLog->markListenedBy($user);

    $pivot = CallLogUser::where('call_log_id', $callLog->id)
        ->where('user_id', $user->id)
        ->first();

    expect($pivot)->not->toBeNull()
        ->and($pivot->listened_at)->not->toBeNull();
});

it('marks call log as handled by user', function () {
    $user = User::factory()->internal()->create();
    $callLog = CallLog::factory()->voicemail()->create();

    $callLog->markHandledBy($user, 'called_back');

    $pivot = CallLogUser::where('call_log_id', $callLog->id)
        ->where('user_id', $user->id)
        ->first();

    expect($pivot)->not->toBeNull()
        ->and($pivot->handled_at)->not->toBeNull()
        ->and($pivot->handled_action)->toBe('called_back');
});

it('identifies missed calls correctly', function () {
    $missedCall = CallLog::factory()->missed()->create();
    $completedCall = CallLog::factory()->completed()->create();
    $voicemailCall = CallLog::factory()->voicemail()->create();

    expect($missedCall->isMissedCall())->toBeTrue()
        ->and($completedCall->isMissedCall())->toBeFalse()
        ->and($voicemailCall->isMissedCall())->toBeFalse();
});

it('identifies company-wide vs personal voicemails', function () {
    $user = User::factory()->internal()->create();

    $companyVoicemail = CallLog::factory()->voicemail()->companyWide()->create();
    $personalVoicemail = CallLog::factory()->voicemail()->forUser($user)->create();

    expect($companyVoicemail->isCompanyWide())->toBeTrue()
        ->and($companyVoicemail->isForUser())->toBeFalse()
        ->and($personalVoicemail->isCompanyWide())->toBeFalse()
        ->and($personalVoicemail->isForUser())->toBeTrue();
});

it('checks isListenedBy returns true after marking listened', function () {
    $user = User::factory()->internal()->create();
    $callLog = CallLog::factory()->voicemail()->create();

    expect($callLog->isListenedBy($user))->toBeFalse();

    $callLog->markListenedBy($user);

    expect($callLog->isListenedBy($user))->toBeTrue();
});

it('checks isHandled returns true after marking handled', function () {
    $user = User::factory()->internal()->create();
    $callLog = CallLog::factory()->voicemail()->create();

    expect($callLog->isHandled())->toBeFalse();

    $callLog->markHandledBy($user, 'not_needed');

    expect($callLog->isHandled())->toBeTrue();
});

// ── Group 2: MissedCallVoicemailService ──────────────────────────────

it('counts unhandled voicemails for user with permissions', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('phone.view_company_voicemails');
    $handler = User::factory()->internal()->create();

    // Personal voicemail for user (unhandled) — counted
    CallLog::factory()->voicemail()->forUser($user)->create();

    // Company-wide missed call (unhandled) — counted (user has company perm)
    CallLog::factory()->missed()->companyWide()->create();

    // Handled voicemail for user — NOT counted
    $handledLog = CallLog::factory()->voicemail()->forUser($user)->create();
    $handledLog->markHandledBy($handler, 'called_back');

    // Completed call (not missed) — NOT counted
    CallLog::factory()->completed()->forUser($user)->create();

    $service = app(MissedCallVoicemailService::class);
    $count = $service->getUnhandledCountForUser($user);

    expect($count)->toBe(2);
});

it('returns zero for user with no visible voicemails', function () {
    $user = User::factory()->internal()->create();
    $otherUser = User::factory()->internal()->create();

    // Voicemails for other users
    CallLog::factory()->voicemail()->forUser($otherUser)->create();
    CallLog::factory()->voicemail()->companyWide()->create();

    $service = app(MissedCallVoicemailService::class);
    $count = $service->getUnhandledCountForUser($user);

    expect($count)->toBe(0);
});

it('gets visible call logs with voicemail filter', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('phone.view_all_voicemails');

    CallLog::factory()->voicemail()->create();
    CallLog::factory()->missed()->create();
    CallLog::factory()->completed()->create();

    $service = app(MissedCallVoicemailService::class);
    $results = $service->getVisibleCallLogs($user, 'voicemail');

    expect($results->total())->toBe(1)
        ->and($results->first()->status)->toBe('voicemail');
});

it('gets visible call logs with missed filter', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('phone.view_all_voicemails');

    CallLog::factory()->voicemail()->create();
    CallLog::factory()->missed()->create();
    CallLog::factory()->completed()->create();

    $service = app(MissedCallVoicemailService::class);
    $results = $service->getVisibleCallLogs($user, 'missed');

    expect($results->total())->toBe(1)
        ->and($results->first()->status)->toBe('no-answer');
});

it('gets visible call logs with unhandled filter', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('phone.view_all_voicemails');
    $handler = User::factory()->internal()->create();

    $unhandledVoicemail = CallLog::factory()->voicemail()->create();
    $handledVoicemail = CallLog::factory()->voicemail()->create();
    $handledVoicemail->markHandledBy($handler, 'called_back');
    CallLog::factory()->completed()->create();

    $service = app(MissedCallVoicemailService::class);
    $results = $service->getVisibleCallLogs($user, 'unhandled');

    expect($results->total())->toBe(1)
        ->and($results->first()->id)->toBe($unhandledVoicemail->id);
});

it('marks listened through service', function () {
    $user = User::factory()->internal()->create();
    $callLog = CallLog::factory()->voicemail()->create();

    $service = app(MissedCallVoicemailService::class);
    $service->markListened($callLog, $user);

    expect($callLog->isListenedBy($user))->toBeTrue();
});

it('marks handled through service', function () {
    $user = User::factory()->internal()->create();
    $callLog = CallLog::factory()->voicemail()->create();

    $service = app(MissedCallVoicemailService::class);
    $service->markHandled($callLog, $user, 'left_message');

    $pivot = CallLogUser::where('call_log_id', $callLog->id)
        ->where('user_id', $user->id)
        ->first();

    expect($pivot->handled_at)->not->toBeNull()
        ->and($pivot->handled_action)->toBe('left_message');
});

it('assigns voicemail to user', function () {
    $user = User::factory()->internal()->create();
    $callLog = CallLog::factory()->voicemail()->companyWide()->create();

    expect($callLog->voicemail_for_user_id)->toBeNull();

    $service = app(MissedCallVoicemailService::class);
    $service->assignTo($callLog, $user);

    $callLog->refresh();
    expect($callLog->voicemail_for_user_id)->toBe($user->id);
});

// ── Group 3: ProcessMissedCallJob ────────────────────────────────────

it('sends voicemail notification to personal voicemail user', function () {
    Notification::fake();

    $user = User::factory()->internal()->create();
    $callLog = CallLog::factory()->voicemail()->forUser($user)->create();

    (new ProcessMissedCallJob($callLog->id))->handle();

    Notification::assertSentTo($user, VoicemailReceivedNotification::class);
});

it('sends missed call notification for no-answer calls', function () {
    Notification::fake();

    $user = User::factory()->internal()->create();
    $callLog = CallLog::factory()->missed()->forUser($user)->create();

    (new ProcessMissedCallJob($callLog->id))->handle();

    Notification::assertSentTo($user, MissedCallNotification::class);
});

it('creates follow-up activity for known customer', function () {
    Notification::fake();

    $user = User::factory()->internal()->create();
    $customer = Customer::factory()->create();
    $callLog = CallLog::factory()->voicemail()->forUser($user)->create([
        'customer_id' => $customer->id,
    ]);

    (new ProcessMissedCallJob($callLog->id))->handle();

    $callLog->refresh();
    expect($callLog->follow_up_activity_id)->not->toBeNull();

    $activity = Activity::find($callLog->follow_up_activity_id);
    expect($activity)->not->toBeNull()
        ->and($activity->type)->toBe('call')
        ->and($activity->priority)->toBe('high')
        ->and($activity->subject_type)->toBe(Customer::class)
        ->and($activity->subject_id)->toBe($customer->id)
        ->and($activity->assigned_to)->toBe($user->id);
});

it('skips follow-up activity for unknown callers', function () {
    Notification::fake();

    $user = User::factory()->internal()->create();
    $callLog = CallLog::factory()->voicemail()->forUser($user)->create([
        'customer_id' => null,
    ]);

    $activityCountBefore = Activity::count();

    (new ProcessMissedCallJob($callLog->id))->handle();

    expect(Activity::count())->toBe($activityCountBefore);
    $callLog->refresh();
    expect($callLog->follow_up_activity_id)->toBeNull();
});

it('deduplicates by checking follow_up_activity_id', function () {
    Notification::fake();

    $user = User::factory()->internal()->create();
    $customer = Customer::factory()->create();
    $activity = Activity::factory()->create();
    $callLog = CallLog::factory()->voicemail()->forUser($user)->create([
        'customer_id' => $customer->id,
        'follow_up_activity_id' => $activity->id,
    ]);

    $activityCountBefore = Activity::count();

    (new ProcessMissedCallJob($callLog->id))->handle();

    // Should not send notifications or create new activity
    Notification::assertNothingSent();
    expect(Activity::count())->toBe($activityCountBefore);
});

it('sends to company voicemail recipients when no personal user', function () {
    Notification::fake();

    $recipientA = User::factory()->internal()->create();
    $recipientA->givePermissionTo('phone.view_company_voicemails');

    $recipientB = User::factory()->internal()->create();
    $recipientB->givePermissionTo('phone.receive_calls');

    // User without relevant permission — should NOT receive
    User::factory()->internal()->create();

    $callLog = CallLog::factory()->voicemail()->companyWide()->create();

    (new ProcessMissedCallJob($callLog->id))->handle();

    Notification::assertSentTo($recipientA, VoicemailReceivedNotification::class);
    Notification::assertSentTo($recipientB, VoicemailReceivedNotification::class);
});

it('does nothing for nonexistent call log', function () {
    Notification::fake();

    (new ProcessMissedCallJob(999999))->handle();

    Notification::assertNothingSent();
});

// ── Group 4: Webhook Integration ─────────────────────────────────────

it('dispatches ProcessMissedCallJob on no-answer status', function () {
    Queue::fake([ProcessMissedCallJob::class]);

    $twilioMock = Mockery::mock(\App\Services\TwilioService::class)->makePartial();
    $twilioMock->shouldReceive('validateWebhookSignature')->andReturn(true);
    $twilioMock->shouldReceive('formatE164')->andReturn(null);
    $this->app->instance(\App\Services\TwilioService::class, $twilioMock);

    $callLog = CallLog::factory()->create([
        'twilio_call_sid' => 'CAstatus_missed_test',
        'status' => 'ringing',
    ]);

    $this->post('/webhooks/twilio/voice/status', [
        'CallSid' => 'CAstatus_missed_test',
        'CallStatus' => 'no-answer',
    ]);

    Queue::assertPushed(ProcessMissedCallJob::class, function ($job) use ($callLog) {
        return $job->callLogId === $callLog->id;
    });
});

it('dispatches ProcessMissedCallJob on voicemail recording', function () {
    Queue::fake([ProcessMissedCallJob::class, \App\Jobs\TranscribeVoicemailJob::class, \App\Jobs\ProcessCallRecordingJob::class]);

    $twilioMock = Mockery::mock(\App\Services\TwilioService::class)->makePartial();
    $twilioMock->shouldReceive('validateWebhookSignature')->andReturn(true);
    $twilioMock->shouldReceive('formatE164')->andReturn(null);
    $this->app->instance(\App\Services\TwilioService::class, $twilioMock);

    $callLog = CallLog::factory()->create([
        'twilio_call_sid' => 'CArec_vm_test',
        'status' => 'voicemail',
    ]);

    $this->post('/webhooks/twilio/voice/recording', [
        'CallSid' => 'CArec_vm_test',
        'RecordingSid' => 'REvm_test_123',
        'RecordingUrl' => 'https://api.twilio.com/recordings/REvm_test_123',
        'RecordingDuration' => '15',
    ]);

    Queue::assertPushed(ProcessMissedCallJob::class, function ($job) use ($callLog) {
        return $job->callLogId === $callLog->id;
    });
});

// ── Group 5: Call History Component (Livewire) ───────────────────────

it('shows unhandled filter tab in call history', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('phone.view_call_history');

    Livewire::actingAs($user)
        ->test('communications.call-history')
        ->assertSee('Unhandled')
        ->assertStatus(200);
});

it('marks voicemail as listened via component', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('phone.view_call_history');

    $callLog = CallLog::factory()->voicemail()->withRecording()->create();

    Livewire::actingAs($user)
        ->test('communications.call-history')
        ->call('markListened', $callLog->id);

    expect($callLog->isListenedBy($user))->toBeTrue();
});

it('marks voicemail as handled via component', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('phone.view_call_history');

    $callLog = CallLog::factory()->voicemail()->create();

    Livewire::actingAs($user)
        ->test('communications.call-history')
        ->call('markHandled', $callLog->id, 'called_back');

    $pivot = CallLogUser::where('call_log_id', $callLog->id)
        ->where('user_id', $user->id)
        ->first();

    expect($pivot)->not->toBeNull()
        ->and($pivot->handled_action)->toBe('called_back')
        ->and($pivot->handled_at)->not->toBeNull();
});

it('filters unhandled in call history component', function () {
    $user = User::factory()->internal()->create();
    $user->givePermissionTo('phone.view_call_history');
    $user->givePermissionTo('phone.view_all_voicemails');
    $handler = User::factory()->internal()->create();

    $unhandled = CallLog::factory()->voicemail()->create([
        'from_number' => '+10005551111',
    ]);
    $handled = CallLog::factory()->voicemail()->create([
        'from_number' => '+10005552222',
    ]);
    $handled->markHandledBy($handler, 'called_back');

    Livewire::actingAs($user)
        ->test('communications.call-history')
        ->call('setFilter', 'unhandled')
        ->assertSee('+10005551111')
        ->assertDontSee('+10005552222');
});

it('has voicemailForUser relationship', function () {
    $user = User::factory()->internal()->create();
    $callLog = CallLog::factory()->voicemail()->forUser($user)->create();

    expect($callLog->voicemailForUser->id)->toBe($user->id);
});

it('has callGroup relationship', function () {
    $group = CallGroup::factory()->create();
    $callLog = CallLog::factory()->create(['call_group_id' => $group->id]);

    expect($callLog->callGroup->id)->toBe($group->id);
});

it('has userInteractions relationship', function () {
    $user = User::factory()->internal()->create();
    $callLog = CallLog::factory()->voicemail()->create();

    $callLog->markListenedBy($user);

    expect($callLog->userInteractions)->toHaveCount(1)
        ->and($callLog->userInteractions->first()->user_id)->toBe($user->id);
});

it('marks listened idempotently via updateOrCreate', function () {
    $user = User::factory()->internal()->create();
    $callLog = CallLog::factory()->voicemail()->create();

    $callLog->markListenedBy($user);
    $callLog->markListenedBy($user);

    $pivotCount = CallLogUser::where('call_log_id', $callLog->id)
        ->where('user_id', $user->id)
        ->count();

    expect($pivotCount)->toBe(1);
});

it('marks handled idempotently via updateOrCreate', function () {
    $user = User::factory()->internal()->create();
    $callLog = CallLog::factory()->voicemail()->create();

    $callLog->markHandledBy($user, 'called_back');
    $callLog->markHandledBy($user, 'left_message');

    $pivot = CallLogUser::where('call_log_id', $callLog->id)
        ->where('user_id', $user->id)
        ->first();

    expect($pivot)->not->toBeNull()
        ->and($pivot->handled_action)->toBe('left_message');

    $pivotCount = CallLogUser::where('call_log_id', $callLog->id)
        ->where('user_id', $user->id)
        ->count();

    expect($pivotCount)->toBe(1);
});
