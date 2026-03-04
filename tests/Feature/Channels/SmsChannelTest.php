<?php

use App\Channels\SmsChannel;
use App\Channels\SmsMessage;
use App\Models\User;
use App\Services\TwilioService;
use Illuminate\Notifications\Notification;

it('sends sms when twilio is enabled and phone exists', function () {
    $user = User::factory()->customer()->create();
    $user->customerProfile()->update(['phone' => '5551234567']);

    $notification = new class extends Notification
    {
        public function toSms($notifiable): SmsMessage
        {
            return new SmsMessage(body: 'Test message');
        }
    };

    $twilioMock = Mockery::mock(TwilioService::class);
    $twilioMock->shouldReceive('isEnabled')->andReturn(true);
    $twilioMock->shouldReceive('formatE164')->with('5551234567')->andReturn('+15551234567');
    $twilioMock->shouldReceive('prependConsentIfNeeded')
        ->with($user->id, 'Test message')
        ->andReturn('Test message');
    $twilioMock->shouldReceive('sendSms')
        ->once()
        ->with('+15551234567', 'Test message')
        ->andReturn(['success' => true, 'sid' => 'SM123', 'error' => null]);

    $this->app->instance(TwilioService::class, $twilioMock);

    $channel = new SmsChannel($twilioMock);
    $channel->send($user, $notification);
});

it('skips sending when twilio is disabled', function () {
    $user = User::factory()->customer()->create();
    $user->customerProfile()->update(['phone' => '5551234567']);

    $notification = new class extends Notification
    {
        public function toSms($notifiable): SmsMessage
        {
            return new SmsMessage(body: 'Test message');
        }
    };

    $twilioMock = Mockery::mock(TwilioService::class);
    $twilioMock->shouldReceive('isEnabled')->andReturn(false);
    $twilioMock->shouldNotReceive('sendSms');

    $channel = new SmsChannel($twilioMock);
    $channel->send($user, $notification);
});

it('skips sending when phone is empty', function () {
    $user = User::factory()->customer()->create();
    // No phone set on customer profile

    $notification = new class extends Notification
    {
        public function toSms($notifiable): SmsMessage
        {
            return new SmsMessage(body: 'Test message');
        }
    };

    $twilioMock = Mockery::mock(TwilioService::class);
    $twilioMock->shouldReceive('isEnabled')->andReturn(true);
    // routeNotificationForTwilio will return null because phone is null
    // formatE164 should not be called on null
    $twilioMock->shouldNotReceive('sendSms');

    // Bind the mock so routeNotificationForTwilio uses it
    $this->app->instance(TwilioService::class, $twilioMock);

    $channel = new SmsChannel($twilioMock);
    $channel->send($user, $notification);
});

it('prepends consent message via twilio service', function () {
    $user = User::factory()->customer()->create();
    $user->customerProfile()->update(['phone' => '5551234567']);

    $notification = new class extends Notification
    {
        public function toSms($notifiable): SmsMessage
        {
            return new SmsMessage(body: 'Your estimate is ready!');
        }
    };

    $consentBody = "Apex alerts. Reply STOP to opt out. Msg&data rates may apply.\n\nYour estimate is ready!";

    $twilioMock = Mockery::mock(TwilioService::class);
    $twilioMock->shouldReceive('isEnabled')->andReturn(true);
    $twilioMock->shouldReceive('formatE164')->with('5551234567')->andReturn('+15551234567');
    $twilioMock->shouldReceive('prependConsentIfNeeded')
        ->once()
        ->with($user->id, 'Your estimate is ready!')
        ->andReturn($consentBody);
    $twilioMock->shouldReceive('sendSms')
        ->once()
        ->with('+15551234567', $consentBody)
        ->andReturn(['success' => true, 'sid' => 'SM456', 'error' => null]);

    $this->app->instance(TwilioService::class, $twilioMock);

    $channel = new SmsChannel($twilioMock);
    $channel->send($user, $notification);
});

it('includes deep link in sms body', function () {
    $user = User::factory()->customer()->create();
    $user->customerProfile()->update(['phone' => '5551234567']);

    $notification = new class extends Notification
    {
        public function toSms($notifiable): SmsMessage
        {
            return new SmsMessage(body: 'Check this out', deepLink: 'https://example.com/view');
        }
    };

    $expectedBody = "Check this out\nhttps://example.com/view";

    $twilioMock = Mockery::mock(TwilioService::class);
    $twilioMock->shouldReceive('isEnabled')->andReturn(true);
    $twilioMock->shouldReceive('formatE164')->with('5551234567')->andReturn('+15551234567');
    $twilioMock->shouldReceive('prependConsentIfNeeded')
        ->once()
        ->with($user->id, $expectedBody)
        ->andReturn($expectedBody);
    $twilioMock->shouldReceive('sendSms')
        ->once()
        ->with('+15551234567', $expectedBody)
        ->andReturn(['success' => true, 'sid' => 'SM789', 'error' => null]);

    $this->app->instance(TwilioService::class, $twilioMock);

    $channel = new SmsChannel($twilioMock);
    $channel->send($user, $notification);
});
