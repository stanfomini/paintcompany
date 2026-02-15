<?php

use App\Models\Activity;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\User;
use App\Notifications\MeetingProposed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

it('implements ShouldQueue', function () {
    expect(new MeetingProposed(
        Activity::factory()->make(),
        User::factory()->make(),
    ))->toBeInstanceOf(ShouldQueue::class);
});

it('uses mail database and broadcast channels', function () {
    $notification = new MeetingProposed(
        Activity::factory()->make(),
        User::factory()->make(),
    );

    expect($notification->via(new stdClass))->toBe(['mail', 'database', 'broadcast']);
});

it('has correct button text in email', function () {
    $activity = Activity::factory()->scheduledMeeting()->create();
    $proposer = User::factory()->internal()->create();
    $notifiable = User::factory()->internal()->create();

    $notification = new MeetingProposed($activity, $proposer);
    $mail = $notification->toMail($notifiable);

    expect($mail)->toBeInstanceOf(MailMessage::class);

    $rendered = $mail->render()->toHtml();
    expect($rendered)->toContain('Log In &amp; Confirm Meeting');
    expect($rendered)->toContain('Log in to your account to confirm or decline.');
});

it('gives customer the customer estimate URL', function () {
    $customer = User::factory()->customer()->create();
    $estimate = Estimate::factory()->create();
    $activity = Activity::factory()->scheduledMeeting()->create([
        'estimate_id' => $estimate->id,
    ]);
    $proposer = User::factory()->internal()->create();

    $notification = new MeetingProposed($activity, $proposer);
    $mail = $notification->toMail($customer);

    $rendered = $mail->render()->toHtml();
    expect($rendered)->toContain(route('customer.estimates.show', $estimate->id));
});

it('gives agent the agent estimate URL', function () {
    $agent = User::factory()->agent()->create();
    $estimate = Estimate::factory()->create();
    $activity = Activity::factory()->scheduledMeeting()->create([
        'estimate_id' => $estimate->id,
    ]);
    $proposer = User::factory()->internal()->create();

    $notification = new MeetingProposed($activity, $proposer);
    $mail = $notification->toMail($agent);

    $rendered = $mail->render()->toHtml();
    expect($rendered)->toContain(route('agent.estimates.show', $estimate->id));
});

it('gives internal user the internal estimate URL', function () {
    $internal = User::factory()->internal()->create();
    $estimate = Estimate::factory()->create();
    $activity = Activity::factory()->scheduledMeeting()->create([
        'estimate_id' => $estimate->id,
    ]);
    $proposer = User::factory()->internal()->create();

    $notification = new MeetingProposed($activity, $proposer);
    $mail = $notification->toMail($internal);

    $rendered = $mail->render()->toHtml();
    expect($rendered)->toContain(route('estimates.show', $estimate->id));
});

it('falls back to customer dashboard when no estimate_id', function () {
    $customer = User::factory()->customer()->create();
    $activity = Activity::factory()->scheduledMeeting()->create([
        'estimate_id' => null,
    ]);
    $proposer = User::factory()->internal()->create();

    $notification = new MeetingProposed($activity, $proposer);
    $mail = $notification->toMail($customer);

    $rendered = $mail->render()->toHtml();
    expect($rendered)->toContain(route('customer.dashboard'));
});

it('falls back to internal dashboard when no estimate_id for internal user', function () {
    $internal = User::factory()->internal()->create();
    $activity = Activity::factory()->scheduledMeeting()->create([
        'estimate_id' => null,
    ]);
    $proposer = User::factory()->internal()->create();

    $notification = new MeetingProposed($activity, $proposer);
    $mail = $notification->toMail($internal);

    $rendered = $mail->render()->toHtml();
    expect($rendered)->toContain(route('dashboard'));
});

it('includes deal_id in array data when subject is a deal', function () {
    $deal = Deal::factory()->create();
    $activity = Activity::factory()->scheduledMeeting()->forDeal($deal)->create();
    $proposer = User::factory()->internal()->create();
    $notifiable = User::factory()->internal()->create();

    $notification = new MeetingProposed($activity, $proposer);
    $data = $notification->toArray($notifiable);

    expect($data['deal_id'])->toBe($deal->id);
    expect($data['activity_id'])->toBe($activity->id);
    expect($data['title'])->toBe($activity->title);
});

it('has null deal_id when subject is not a deal', function () {
    $activity = Activity::factory()->scheduledMeeting()->create([
        'subject_type' => Customer::class,
        'subject_id' => Customer::factory()->create()->id,
    ]);
    $proposer = User::factory()->internal()->create();
    $notifiable = User::factory()->internal()->create();

    $notification = new MeetingProposed($activity, $proposer);
    $data = $notification->toArray($notifiable);

    expect($data['deal_id'])->toBeNull();
});
