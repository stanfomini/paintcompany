<?php

use App\Models\Activity;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\User;
use App\Models\VideoCall;
use App\Notifications\MeetingConfirmed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

it('implements ShouldQueue', function () {
    expect(new MeetingConfirmed(
        Activity::factory()->make(),
    ))->toBeInstanceOf(ShouldQueue::class);
});

it('uses mail database and broadcast channels', function () {
    $notification = new MeetingConfirmed(
        Activity::factory()->make(),
    );

    expect($notification->via(new stdClass))->toBe(['mail', 'database', 'broadcast']);
});

it('shows join video call button for virtual meetings with video call', function () {
    $videoCall = VideoCall::factory()->create();
    $activity = Activity::factory()->scheduledMeeting('virtual')->create([
        'meeting_status' => 'confirmed',
        'video_call_id' => $videoCall->id,
    ]);
    $notifiable = User::factory()->internal()->create();

    $notification = new MeetingConfirmed($activity);
    $mail = $notification->toMail($notifiable);

    expect($mail)->toBeInstanceOf(MailMessage::class);

    $rendered = $mail->render()->toHtml();
    expect($rendered)->toContain('Join Video Call');
    expect($rendered)->toContain(route('video-call.test', $videoCall->room_id));
});

it('shows view meeting details button for in-person meetings', function () {
    $estimate = Estimate::factory()->create();
    $activity = Activity::factory()->scheduledMeeting('in_person')->create([
        'meeting_status' => 'confirmed',
        'estimate_id' => $estimate->id,
    ]);
    $notifiable = User::factory()->internal()->create();

    $notification = new MeetingConfirmed($activity);
    $mail = $notification->toMail($notifiable);

    $rendered = $mail->render()->toHtml();
    expect($rendered)->toContain('View Meeting Details');
    expect($rendered)->toContain(route('estimates.show', $estimate->id));
});

it('gives customer the customer estimate URL', function () {
    $customer = User::factory()->customer()->create();
    $estimate = Estimate::factory()->create();
    $activity = Activity::factory()->scheduledMeeting('in_person')->create([
        'meeting_status' => 'confirmed',
        'estimate_id' => $estimate->id,
    ]);

    $notification = new MeetingConfirmed($activity);
    $mail = $notification->toMail($customer);

    $rendered = $mail->render()->toHtml();
    expect($rendered)->toContain(route('customer.estimates.show', $estimate->id));
});

it('gives agent the agent estimate URL', function () {
    $agent = User::factory()->agent()->create();
    $estimate = Estimate::factory()->create();
    $activity = Activity::factory()->scheduledMeeting('in_person')->create([
        'meeting_status' => 'confirmed',
        'estimate_id' => $estimate->id,
    ]);

    $notification = new MeetingConfirmed($activity);
    $mail = $notification->toMail($agent);

    $rendered = $mail->render()->toHtml();
    expect($rendered)->toContain(route('agent.estimates.show', $estimate->id));
});

it('falls back to customer dashboard when no estimate_id', function () {
    $customer = User::factory()->customer()->create();
    $activity = Activity::factory()->scheduledMeeting('in_person')->create([
        'meeting_status' => 'confirmed',
        'estimate_id' => null,
    ]);

    $notification = new MeetingConfirmed($activity);
    $mail = $notification->toMail($customer);

    $rendered = $mail->render()->toHtml();
    expect($rendered)->toContain(route('customer.dashboard'));
});

it('falls back to internal dashboard when no estimate_id for internal user', function () {
    $internal = User::factory()->internal()->create();
    $activity = Activity::factory()->scheduledMeeting('in_person')->create([
        'meeting_status' => 'confirmed',
        'estimate_id' => null,
    ]);

    $notification = new MeetingConfirmed($activity);
    $mail = $notification->toMail($internal);

    $rendered = $mail->render()->toHtml();
    expect($rendered)->toContain(route('dashboard'));
});

it('includes deal_id in array data when subject is a deal', function () {
    $deal = Deal::factory()->create();
    $activity = Activity::factory()->confirmedMeeting()->forDeal($deal)->create();
    $notifiable = User::factory()->internal()->create();

    $notification = new MeetingConfirmed($activity);
    $data = $notification->toArray($notifiable);

    expect($data['deal_id'])->toBe($deal->id);
    expect($data['activity_id'])->toBe($activity->id);
    expect($data['title'])->toBe($activity->title);
});

it('has null deal_id when subject is not a deal', function () {
    $activity = Activity::factory()->confirmedMeeting()->create([
        'subject_type' => Customer::class,
        'subject_id' => Customer::factory()->create()->id,
    ]);
    $notifiable = User::factory()->internal()->create();

    $notification = new MeetingConfirmed($activity);
    $data = $notification->toArray($notifiable);

    expect($data['deal_id'])->toBeNull();
});

it('includes video call URL as text in virtual meeting email', function () {
    $videoCall = VideoCall::factory()->create();
    $activity = Activity::factory()->scheduledMeeting('virtual')->create([
        'meeting_status' => 'confirmed',
        'video_call_id' => $videoCall->id,
    ]);
    $notifiable = User::factory()->internal()->create();

    $notification = new MeetingConfirmed($activity);
    $mail = $notification->toMail($notifiable);

    $rendered = $mail->render()->toHtml();
    $expectedUrl = route('video-call.test', $videoCall->room_id);
    expect($rendered)->toContain($expectedUrl);
});
