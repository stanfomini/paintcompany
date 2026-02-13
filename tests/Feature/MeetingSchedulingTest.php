<?php

use App\Models\Activity;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\User;
use App\Models\VideoCall;
use App\Notifications\MeetingCancelled;
use App\Notifications\MeetingConfirmed;
use App\Notifications\MeetingProposed;
use App\Notifications\MeetingReminder;
use App\Services\MeetingService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ─── Proposal Tests ───────────────────────────────────────────────

describe('Meeting Proposal', function () {
    it('allows an internal user to propose an in-person meeting', function () {
        Notification::fake();

        $internal = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();
        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'created_by' => $internal->id,
            'assigned_to' => $internal->id,
        ]);

        $service = app(MeetingService::class);
        $meeting = $service->proposeMeeting(
            deal: $deal,
            proposer: $internal,
            title: 'Estimate Walk-Through',
            dateTime: Carbon::now()->addDay(),
            meetingType: 'in_person',
        );

        expect($meeting)->toBeInstanceOf(Activity::class)
            ->and($meeting->type)->toBe('meeting')
            ->and($meeting->meeting_type)->toBe('in_person')
            ->and($meeting->meeting_status)->toBe('proposed')
            ->and($meeting->proposed_by)->toBe($internal->id)
            ->and($meeting->team_confirmed_at)->not->toBeNull();
    });

    it('creates a VideoCall record for virtual meetings', function () {
        Notification::fake();

        $internal = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create([
            'created_by' => $internal->id,
            'assigned_to' => $internal->id,
        ]);

        $service = app(MeetingService::class);
        $meeting = $service->proposeMeeting(
            deal: $deal,
            proposer: $internal,
            title: 'Virtual Consultation',
            dateTime: Carbon::now()->addDay(),
            meetingType: 'virtual',
        );

        expect($meeting->video_call_id)->not->toBeNull();

        $videoCall = VideoCall::find($meeting->video_call_id);
        expect($videoCall)->not->toBeNull()
            ->and($videoCall->callable_type)->toBe(Deal::class)
            ->and($videoCall->callable_id)->toBe($deal->id)
            ->and($videoCall->room_id)->toStartWith('meeting-');
    });

    it('auto-confirms for the proposer (internal = team)', function () {
        Notification::fake();

        $internal = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();
        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'assigned_to' => $internal->id,
        ]);

        $service = app(MeetingService::class);
        $meeting = $service->proposeMeeting(
            deal: $deal,
            proposer: $internal,
            title: 'Walk-through',
            dateTime: Carbon::now()->addDay(),
            meetingType: 'in_person',
        );

        expect($meeting->team_confirmed_at)->not->toBeNull()
            ->and($meeting->customer_confirmed_at)->toBeNull();
    });

    it('auto-confirms for agent proposer', function () {
        Notification::fake();

        $agent = User::factory()->agent()->create();
        $customer = Customer::factory()->create();
        $internal = User::factory()->internal()->create();
        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'agent_id' => $agent->id,
            'assigned_to' => $internal->id,
        ]);

        $service = app(MeetingService::class);
        $meeting = $service->proposeMeeting(
            deal: $deal,
            proposer: $agent,
            title: 'Site visit',
            dateTime: Carbon::now()->addDay(),
            meetingType: 'in_person',
        );

        expect($meeting->agent_confirmed_at)->not->toBeNull()
            ->and($meeting->customer_confirmed_at)->toBeNull()
            ->and($meeting->team_confirmed_at)->toBeNull();
    });

    it('auto-confirms for customer proposer', function () {
        Notification::fake();

        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $internal = User::factory()->internal()->create();
        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'assigned_to' => $internal->id,
        ]);

        $service = app(MeetingService::class);
        $meeting = $service->proposeMeeting(
            deal: $deal,
            proposer: $customerUser,
            title: 'Color consultation',
            dateTime: Carbon::now()->addDay(),
            meetingType: 'in_person',
        );

        expect($meeting->customer_confirmed_at)->not->toBeNull()
            ->and($meeting->team_confirmed_at)->toBeNull();
    });

    it('assigns deal to team member when proposed with team member and deal has no assigned_to', function () {
        Notification::fake();

        $teamMember = User::factory()->internal()->create();
        $agent = User::factory()->agent()->create();
        $deal = Deal::factory()->create([
            'agent_id' => $agent->id,
            'assigned_to' => null,
        ]);

        $service = app(MeetingService::class);
        $service->proposeMeeting(
            deal: $deal,
            proposer: $agent,
            title: 'Walkthrough',
            dateTime: Carbon::now()->addDay(),
            meetingType: 'in_person',
            teamMemberId: $teamMember->id,
        );

        expect($deal->fresh()->assigned_to)->toBe($teamMember->id);
    });

    it('links meeting to estimate when estimateId provided', function () {
        Notification::fake();

        $internal = User::factory()->internal()->create();
        $deal = Deal::factory()->create(['assigned_to' => $internal->id]);
        $estimate = Estimate::factory()->create(['deal_id' => $deal->id]);

        $service = app(MeetingService::class);
        $meeting = $service->proposeMeeting(
            deal: $deal,
            proposer: $internal,
            title: 'Review estimate',
            dateTime: Carbon::now()->addDay(),
            meetingType: 'in_person',
            estimateId: $estimate->id,
        );

        expect($meeting->estimate_id)->toBe($estimate->id);
    });

    it('sends MeetingProposed notification to parties needing confirmation', function () {
        Notification::fake();

        $internal = User::factory()->internal()->create();
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'assigned_to' => $internal->id,
        ]);

        $service = app(MeetingService::class);
        $service->proposeMeeting(
            deal: $deal,
            proposer: $internal,
            title: 'Estimate walk-through',
            dateTime: Carbon::now()->addDay(),
            meetingType: 'in_person',
        );

        Notification::assertSentTo($customerUser, MeetingProposed::class);
        Notification::assertNotSentTo($internal, MeetingProposed::class);
    });

    it('auto-confirms meeting when only one party exists', function () {
        Notification::fake();

        $internal = User::factory()->internal()->create();
        $deal = Deal::factory()->create([
            'customer_id' => null,
            'agent_id' => null,
            'assigned_to' => $internal->id,
        ]);

        $service = app(MeetingService::class);
        $meeting = $service->proposeMeeting(
            deal: $deal,
            proposer: $internal,
            title: 'Internal planning',
            dateTime: Carbon::now()->addDay(),
            meetingType: 'in_person',
        );

        expect($meeting->meeting_status)->toBe('confirmed');
    });
});

// ─── Confirmation Tests ───────────────────────────────────────────

describe('Meeting Confirmation', function () {
    it('allows each party to confirm', function () {
        Notification::fake();

        $internal = User::factory()->internal()->create();
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'assigned_to' => $internal->id,
        ]);

        $service = app(MeetingService::class);
        $meeting = $service->proposeMeeting(
            deal: $deal,
            proposer: $internal,
            title: 'Walk-through',
            dateTime: Carbon::now()->addDay(),
            meetingType: 'in_person',
        );

        // Internal proposer is already confirmed
        expect($meeting->team_confirmed_at)->not->toBeNull()
            ->and($meeting->meeting_status)->toBe('proposed');

        // Customer confirms
        $meeting = $service->confirmMeeting($meeting, $customerUser);

        expect($meeting->customer_confirmed_at)->not->toBeNull()
            ->and($meeting->meeting_status)->toBe('confirmed');
    });

    it('marks meeting as confirmed when all required parties confirm', function () {
        Notification::fake();

        $internal = User::factory()->internal()->create();
        $agent = User::factory()->agent()->create();
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'agent_id' => $agent->id,
            'assigned_to' => $internal->id,
        ]);

        $service = app(MeetingService::class);
        $meeting = $service->proposeMeeting(
            deal: $deal,
            proposer: $internal,
            title: 'Three-party meeting',
            dateTime: Carbon::now()->addDay(),
            meetingType: 'in_person',
        );

        expect($meeting->meeting_status)->toBe('proposed');

        // Agent confirms
        $meeting = $service->confirmMeeting($meeting, $agent);
        expect($meeting->agent_confirmed_at)->not->toBeNull()
            ->and($meeting->meeting_status)->toBe('proposed');

        // Customer confirms - now all parties done
        $meeting = $service->confirmMeeting($meeting, $customerUser);
        expect($meeting->meeting_status)->toBe('confirmed');

        Notification::assertSentTo($internal, MeetingConfirmed::class);
        Notification::assertSentTo($agent, MeetingConfirmed::class);
        Notification::assertSentTo($customerUser, MeetingConfirmed::class);
    });

    it('does not confirm for unknown party', function () {
        Notification::fake();

        $internal = User::factory()->internal()->create();
        $randomUser = User::factory()->create(['type' => 'crew']);
        $deal = Deal::factory()->create(['assigned_to' => $internal->id]);

        $meeting = Activity::factory()->scheduledMeeting()->forDeal($deal)->proposedBy($internal)->create([
            'user_id' => $internal->id,
        ]);

        $service = app(MeetingService::class);
        $result = $service->confirmMeeting($meeting, $randomUser);

        expect($result->team_confirmed_at)->toBeNull()
            ->and($result->customer_confirmed_at)->toBeNull()
            ->and($result->agent_confirmed_at)->toBeNull();
    });
});

// ─── Decline & Cancel Tests ──────────────────────────────────────

describe('Meeting Decline and Cancel', function () {
    it('allows a party to decline a meeting', function () {
        Notification::fake();

        $internal = User::factory()->internal()->create();
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'assigned_to' => $internal->id,
        ]);

        $meeting = Activity::factory()->scheduledMeeting()->forDeal($deal)->proposedBy($internal)->create([
            'user_id' => $internal->id,
        ]);

        $service = app(MeetingService::class);
        $result = $service->declineMeeting($meeting, $customerUser, 'Not available');

        expect($result->meeting_status)->toBe('declined');

        Notification::assertSentTo($internal, MeetingCancelled::class);
        Notification::assertSentTo($customerUser, MeetingCancelled::class);
    });

    it('allows cancelling a meeting', function () {
        Notification::fake();

        $internal = User::factory()->internal()->create();
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'assigned_to' => $internal->id,
        ]);

        $meeting = Activity::factory()->scheduledMeeting()->forDeal($deal)->proposedBy($internal)->create([
            'user_id' => $internal->id,
            'meeting_status' => 'confirmed',
        ]);

        $service = app(MeetingService::class);
        $result = $service->cancelMeeting($meeting, $internal);

        expect($result->meeting_status)->toBe('cancelled');

        Notification::assertSentTo($internal, MeetingCancelled::class);
        Notification::assertSentTo($customerUser, MeetingCancelled::class);
    });
});

// ─── Reschedule Tests ────────────────────────────────────────────

describe('Meeting Reschedule', function () {
    it('resets confirmations and re-proposes when rescheduled', function () {
        Notification::fake();

        $internal = User::factory()->internal()->create();
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'assigned_to' => $internal->id,
        ]);

        $meeting = Activity::factory()->confirmedMeeting()->forDeal($deal)->proposedBy($internal)->create([
            'user_id' => $internal->id,
        ]);

        $newTime = Carbon::now()->addWeek();
        $service = app(MeetingService::class);
        $result = $service->rescheduleMeeting($meeting, $newTime, $internal);

        expect($result->due_at->toDateString())->toBe($newTime->toDateString())
            ->and($result->meeting_status)->toBe('proposed')
            ->and($result->customer_confirmed_at)->toBeNull()
            ->and($result->team_confirmed_at)->not->toBeNull()
            ->and($result->reminder_sent_at)->toBeNull();
    });
});

// ─── Reminder Command Tests ──────────────────────────────────────

describe('Meeting Reminder Command', function () {
    it('sends reminders for confirmed meetings due in the next hour', function () {
        Notification::fake();

        $internal = User::factory()->internal()->create();
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'assigned_to' => $internal->id,
        ]);

        Activity::factory()->confirmedMeeting()->forDeal($deal)->create([
            'user_id' => $internal->id,
            'due_at' => now()->addMinutes(30),
            'reminder_sent_at' => null,
        ]);

        $this->artisan('meetings:send-reminders')
            ->assertExitCode(0);

        Notification::assertSentTo($internal, MeetingReminder::class);
        Notification::assertSentTo($customerUser, MeetingReminder::class);
    });

    it('does not send reminders for already-reminded meetings', function () {
        Notification::fake();

        $internal = User::factory()->internal()->create();
        $deal = Deal::factory()->create(['assigned_to' => $internal->id, 'customer_id' => null]);

        Activity::factory()->confirmedMeeting()->forDeal($deal)->create([
            'user_id' => $internal->id,
            'due_at' => now()->addMinutes(30),
            'reminder_sent_at' => now()->subMinutes(5),
        ]);

        $this->artisan('meetings:send-reminders')
            ->assertExitCode(0);

        Notification::assertNotSentTo($internal, MeetingReminder::class);
    });

    it('does not send reminders for unconfirmed meetings', function () {
        Notification::fake();

        $internal = User::factory()->internal()->create();
        $deal = Deal::factory()->create(['assigned_to' => $internal->id, 'customer_id' => null]);

        Activity::factory()->scheduledMeeting()->forDeal($deal)->create([
            'user_id' => $internal->id,
            'due_at' => now()->addMinutes(30),
            'meeting_status' => 'proposed',
        ]);

        $this->artisan('meetings:send-reminders')
            ->assertExitCode(0);

        Notification::assertNotSentTo($internal, MeetingReminder::class);
    });

    it('does not send reminders for meetings more than 65 minutes away', function () {
        Notification::fake();

        $internal = User::factory()->internal()->create();
        $deal = Deal::factory()->create(['assigned_to' => $internal->id, 'customer_id' => null]);

        Activity::factory()->confirmedMeeting()->forDeal($deal)->create([
            'user_id' => $internal->id,
            'due_at' => now()->addMinutes(90),
        ]);

        $this->artisan('meetings:send-reminders')
            ->assertExitCode(0);

        Notification::assertNotSentTo($internal, MeetingReminder::class);
    });

    it('sets reminder_sent_at after sending', function () {
        Notification::fake();

        $internal = User::factory()->internal()->create();
        $deal = Deal::factory()->create(['assigned_to' => $internal->id, 'customer_id' => null]);

        $meeting = Activity::factory()->confirmedMeeting()->forDeal($deal)->create([
            'user_id' => $internal->id,
            'due_at' => now()->addMinutes(30),
            'reminder_sent_at' => null,
        ]);

        $this->artisan('meetings:send-reminders');

        expect($meeting->fresh()->reminder_sent_at)->not->toBeNull();
    });
});

// ─── Model Helper Tests ──────────────────────────────────────────

describe('Activity Model Meeting Helpers', function () {
    it('identifies meetings correctly', function () {
        $meeting = Activity::factory()->scheduledMeeting()->make();
        $task = Activity::factory()->task()->make();

        expect($meeting->isMeeting())->toBeTrue()
            ->and($task->isMeeting())->toBeFalse();
    });

    it('identifies virtual meetings', function () {
        $virtual = Activity::factory()->scheduledMeeting('virtual')->make();
        $inPerson = Activity::factory()->scheduledMeeting('in_person')->make();

        expect($virtual->isVirtualMeeting())->toBeTrue()
            ->and($inPerson->isVirtualMeeting())->toBeFalse();
    });

    it('checks full confirmation with all parties', function () {
        $internal = User::factory()->internal()->create();
        $agent = User::factory()->agent()->create();
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'agent_id' => $agent->id,
            'assigned_to' => $internal->id,
        ]);

        $meeting = Activity::factory()->scheduledMeeting()->forDeal($deal)->create([
            'user_id' => $internal->id,
            'team_confirmed_at' => now(),
            'customer_confirmed_at' => now(),
            'agent_confirmed_at' => null,
        ]);

        expect($meeting->isFullyConfirmed($deal))->toBeFalse();

        $meeting->update(['agent_confirmed_at' => now()]);
        expect($meeting->fresh()->isFullyConfirmed($deal))->toBeTrue();
    });

    it('does not require agent confirmation when deal has no agent', function () {
        $internal = User::factory()->internal()->create();
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create([
            'customer_id' => $customer->id,
            'agent_id' => null,
            'assigned_to' => $internal->id,
        ]);

        $meeting = Activity::factory()->scheduledMeeting()->forDeal($deal)->create([
            'user_id' => $internal->id,
            'team_confirmed_at' => now(),
            'customer_confirmed_at' => now(),
            'agent_confirmed_at' => null,
        ]);

        expect($meeting->isFullyConfirmed($deal))->toBeTrue();
    });

    it('checks needsConfirmationFrom correctly', function () {
        $meeting = Activity::factory()->scheduledMeeting()->make([
            'team_confirmed_at' => now(),
            'customer_confirmed_at' => null,
        ]);

        expect($meeting->needsConfirmationFrom('team'))->toBeFalse()
            ->and($meeting->needsConfirmationFrom('customer'))->toBeTrue();
    });

    it('scopes upcoming meetings', function () {
        $deal = Deal::factory()->create();

        $upcoming = Activity::factory()->scheduledMeeting()->forDeal($deal)->create([
            'due_at' => now()->addDay(),
            'meeting_status' => 'confirmed',
        ]);

        $past = Activity::factory()->scheduledMeeting()->forDeal($deal)->create([
            'due_at' => now()->subDay(),
            'meeting_status' => 'confirmed',
        ]);

        $cancelled = Activity::factory()->scheduledMeeting()->forDeal($deal)->create([
            'due_at' => now()->addDay(),
            'meeting_status' => 'cancelled',
        ]);

        $results = Activity::upcomingMeetings()->pluck('id');

        expect($results)->toContain($upcoming->id)
            ->and($results)->not->toContain($past->id)
            ->and($results)->not->toContain($cancelled->id);
    });
});

// ─── Livewire UI Tests ───────────────────────────────────────────

describe('Internal Estimate Show - Meetings', function () {
    it('shows meeting proposal form for estimates with deals', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create(['assigned_to' => $user->id]);
        $estimate = Estimate::factory()->create(['deal_id' => $deal->id]);

        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->assertSee(__('Meetings'))
            ->assertSee(__('Schedule a Meeting'));
    });

    it('can propose a meeting from estimate show', function () {
        Notification::fake();

        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create(['assigned_to' => $user->id]);
        $estimate = Estimate::factory()->create(['deal_id' => $deal->id]);

        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->set('meetingTitle', 'Walk-Through')
            ->set('meetingDateTime', now()->addDay()->format('Y-m-d\TH:i'))
            ->set('meetingType', 'in_person')
            ->call('proposeMeeting')
            ->assertHasNoErrors();

        expect(Activity::where('estimate_id', $estimate->id)->where('meeting_type', 'in_person')->exists())->toBeTrue();
    });

    it('validates required fields when proposing', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create(['assigned_to' => $user->id]);
        $estimate = Estimate::factory()->create(['deal_id' => $deal->id]);

        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->set('meetingTitle', '')
            ->set('meetingDateTime', '')
            ->call('proposeMeeting')
            ->assertHasErrors(['meetingTitle', 'meetingDateTime']);
    });

    it('can confirm a meeting', function () {
        Notification::fake();

        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create(['assigned_to' => $user->id, 'customer_id' => null]);
        $estimate = Estimate::factory()->create(['deal_id' => $deal->id]);

        $meeting = Activity::factory()->scheduledMeeting()->forDeal($deal)->create([
            'user_id' => $user->id,
            'estimate_id' => $estimate->id,
        ]);

        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->call('confirmMeeting', $meeting->id);

        expect($meeting->fresh()->team_confirmed_at)->not->toBeNull();
    });

    it('can decline a meeting', function () {
        Notification::fake();

        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create(['assigned_to' => $user->id, 'customer_id' => null]);
        $estimate = Estimate::factory()->create(['deal_id' => $deal->id]);

        $meeting = Activity::factory()->scheduledMeeting()->forDeal($deal)->create([
            'user_id' => $user->id,
            'estimate_id' => $estimate->id,
        ]);

        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->call('declineMeeting', $meeting->id);

        expect($meeting->fresh()->meeting_status)->toBe('declined');
    });

    it('can cancel a meeting', function () {
        Notification::fake();

        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create(['assigned_to' => $user->id, 'customer_id' => null]);
        $estimate = Estimate::factory()->create(['deal_id' => $deal->id]);

        $meeting = Activity::factory()->scheduledMeeting()->forDeal($deal)->create([
            'user_id' => $user->id,
            'estimate_id' => $estimate->id,
            'meeting_status' => 'confirmed',
        ]);

        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->call('cancelMeeting', $meeting->id);

        expect($meeting->fresh()->meeting_status)->toBe('cancelled');
    });
});

// ─── Calendar Feed Tests ─────────────────────────────────────────

describe('Calendar Feed Enhancement', function () {
    it('includes meeting type and status in calendar feed description', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create(['assigned_to' => $user->id]);

        Activity::factory()->scheduledMeeting('virtual')->forDeal($deal)->create([
            'user_id' => $user->id,
            'assigned_to' => $user->id,
            'title' => 'Virtual Consultation',
            'meeting_status' => 'confirmed',
        ]);

        $service = app(\App\Services\ActivityCalendarFeedService::class);
        $feed = $service->generateFeed($user);

        expect($feed)->toContain('Virtual Consultation')
            ->and($feed)->toContain('Meeting Type: Virtual')
            ->and($feed)->toContain('Status: Confirmed');
    });
});

// ─── MeetingService getUserParty Tests ───────────────────────────

describe('MeetingService getUserParty', function () {
    it('identifies customer party', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->create(['customer_id' => $customer->id]);

        $service = app(MeetingService::class);
        expect($service->getUserParty($customerUser, $deal))->toBe('customer');
    });

    it('identifies agent party', function () {
        $agent = User::factory()->agent()->create();
        $deal = Deal::factory()->create(['agent_id' => $agent->id]);

        $service = app(MeetingService::class);
        expect($service->getUserParty($agent, $deal))->toBe('agent');
    });

    it('identifies team party', function () {
        $internal = User::factory()->internal()->create();
        $deal = Deal::factory()->create();

        $service = app(MeetingService::class);
        expect($service->getUserParty($internal, $deal))->toBe('team');
    });

    it('returns unknown for unrelated user', function () {
        $crew = User::factory()->create(['type' => 'crew']);
        $deal = Deal::factory()->create();

        $service = app(MeetingService::class);
        expect($service->getUserParty($crew, $deal))->toBe('unknown');
    });
});
