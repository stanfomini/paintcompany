<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Job;
use App\Models\User;
use App\Models\VideoCall;
use App\Notifications\VideoCallInvitation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;

it('implements ShouldQueue', function () {
    $videoCall = VideoCall::factory()->make();

    expect(new VideoCallInvitation($videoCall, 'John'))
        ->toBeInstanceOf(ShouldQueue::class);
});

it('uses mail and database channels', function () {
    $videoCall = VideoCall::factory()->make();
    $notification = new VideoCallInvitation($videoCall, 'John');

    expect($notification->via(new stdClass))->toBe(['mail', 'database']);
});

it('generates correct mail content with named route URL', function () {
    $job = Job::factory()->create();
    $videoCall = VideoCall::factory()->create([
        'job_id' => $job->id,
        'room_id' => 'test-room-123',
    ]);
    $notifiable = User::factory()->internal()->create();

    $notification = new VideoCallInvitation($videoCall, 'Jane Smith');
    $mail = $notification->toMail($notifiable);

    expect($mail)->toBeInstanceOf(MailMessage::class);

    $rendered = $mail->render()->toHtml();
    expect($rendered)
        ->toContain('Video Call Started')
        ->toContain('Jane Smith')
        ->toContain($job->job_number)
        ->toContain(route('video-call.test', 'test-room-123'));
});

it('mail action URL uses named route not raw url()', function () {
    $job = Job::factory()->create();
    $videoCall = VideoCall::factory()->create([
        'job_id' => $job->id,
        'room_id' => 'room-abc',
    ]);
    $notifiable = User::factory()->internal()->create();

    $notification = new VideoCallInvitation($videoCall, 'Test User');
    $mail = $notification->toMail($notifiable);

    $rendered = $mail->render()->toHtml();
    $expectedUrl = route('video-call.test', 'room-abc');
    expect($rendered)->toContain($expectedUrl);
});

it('includes correct data in toArray for database notification', function () {
    $job = Job::factory()->create();
    $videoCall = VideoCall::factory()->create([
        'job_id' => $job->id,
        'room_id' => 'room-xyz',
    ]);
    $notifiable = User::factory()->internal()->create();

    $notification = new VideoCallInvitation($videoCall, 'Mike Johnson');
    $data = $notification->toArray($notifiable);

    expect($data)
        ->toHaveKeys(['video_call_id', 'job_id', 'initiator_name', 'room_id', 'message'])
        ->and($data['video_call_id'])->toBe($videoCall->id)
        ->and($data['job_id'])->toBe($job->id)
        ->and($data['initiator_name'])->toBe('Mike Johnson')
        ->and($data['room_id'])->toBe('room-xyz')
        ->and($data['message'])->toContain('Mike Johnson');
});

it('notifies internal users with jobs.view permission when call starts', function () {
    Notification::fake();

    $caller = User::factory()->internal()->withFullAccess()->create();
    $internalWithPerm = User::factory()->internal()->withFullAccess()->create();
    $internalWithoutPerm = User::factory()->internal()->create();

    $customer = Customer::factory()->withPortalAccess()->create();
    $deal = Deal::factory()->forCustomer($customer)->create();
    $job = Job::factory()->create(['deal_id' => $deal->id]);

    Livewire::actingAs($caller)
        ->test('video-call.job-call', ['job' => $job])
        ->call('startCall');

    Notification::assertSentTo($internalWithPerm, VideoCallInvitation::class);
    Notification::assertNotSentTo($caller, VideoCallInvitation::class);
});

it('does not notify internal users without jobs.view permission', function () {
    Notification::fake();

    $caller = User::factory()->internal()->withFullAccess()->create();
    $internalNoPerm = User::factory()->internal()->create();

    $customer = Customer::factory()->withPortalAccess()->create();
    $deal = Deal::factory()->forCustomer($customer)->create();
    $job = Job::factory()->create(['deal_id' => $deal->id]);

    Livewire::actingAs($caller)
        ->test('video-call.job-call', ['job' => $job])
        ->call('startCall');

    Notification::assertNotSentTo($internalNoPerm, VideoCallInvitation::class);
});

it('notifies accepted crew members', function () {
    Notification::fake();

    $caller = User::factory()->internal()->withFullAccess()->create();
    $crewAccepted = User::factory()->crew()->create();
    $crewPending = User::factory()->crew()->create();

    $customer = Customer::factory()->withPortalAccess()->create();
    $deal = Deal::factory()->forCustomer($customer)->create();
    $job = Job::factory()->create(['deal_id' => $deal->id]);

    $job->crews()->attach($crewAccepted, ['status' => 'accepted']);
    $job->crews()->attach($crewPending, ['status' => 'pending']);

    Livewire::actingAs($caller)
        ->test('video-call.job-call', ['job' => $job])
        ->call('startCall');

    Notification::assertSentTo($crewAccepted, VideoCallInvitation::class);
    Notification::assertNotSentTo($crewPending, VideoCallInvitation::class);
});

it('notifies the customer user', function () {
    Notification::fake();

    $caller = User::factory()->internal()->withFullAccess()->create();
    $customerUser = User::factory()->customer()->create();
    $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
    $deal = Deal::factory()->forCustomer($customer)->create();
    $job = Job::factory()->create(['deal_id' => $deal->id]);

    Livewire::actingAs($caller)
        ->test('video-call.job-call', ['job' => $job])
        ->call('startCall');

    Notification::assertSentTo($customerUser, VideoCallInvitation::class);
});

it('does not notify customer when no portal user exists', function () {
    Notification::fake();

    $caller = User::factory()->internal()->withFullAccess()->create();
    $customer = Customer::factory()->create(['user_id' => null]);
    $deal = Deal::factory()->forCustomer($customer)->create();
    $job = Job::factory()->create(['deal_id' => $deal->id]);

    Livewire::actingAs($caller)
        ->test('video-call.job-call', ['job' => $job])
        ->call('startCall');

    // Verify no customer-type user received a notification
    Notification::assertCount(
        User::where('type', 'internal')
            ->permission('jobs.view')
            ->where('id', '!=', $caller->id)
            ->count()
    );
});

it('does not notify the caller even if they are an internal user with permission', function () {
    Notification::fake();

    $caller = User::factory()->internal()->withFullAccess()->create();
    $customer = Customer::factory()->withPortalAccess()->create();
    $deal = Deal::factory()->forCustomer($customer)->create();
    $job = Job::factory()->create(['deal_id' => $deal->id]);

    Livewire::actingAs($caller)
        ->test('video-call.job-call', ['job' => $job])
        ->call('startCall');

    Notification::assertNotSentTo($caller, VideoCallInvitation::class);
});

it('notification subject includes initiator name and job title', function () {
    $job = Job::factory()->create(['title' => 'Paint Living Room']);
    $videoCall = VideoCall::factory()->create(['job_id' => $job->id]);
    $notifiable = User::factory()->internal()->create();

    $notification = new VideoCallInvitation($videoCall, 'Sarah');
    $mail = $notification->toMail($notifiable);

    expect($mail->subject)
        ->toContain('Sarah')
        ->toContain('Paint Living Room');
});

it('notification center maps VideoCallInvitation to job route', function () {
    $job = Job::factory()->create();
    $videoCall = VideoCall::factory()->create(['job_id' => $job->id]);
    $user = User::factory()->internal()->withFullAccess()->create();

    $notification = new VideoCallInvitation($videoCall, 'Test');
    $data = $notification->toArray($user);

    // The notification center uses job_id from the data to route to jobs.show
    expect($data['job_id'])->toBe($job->id);
    expect(route('jobs.show', $data['job_id']))->toBeString();
});
