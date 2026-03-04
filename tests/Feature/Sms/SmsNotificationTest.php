<?php

use App\Channels\SmsChannel;
use App\Models\Estimate;
use App\Models\Job;
use App\Models\Payment;
use App\Models\User;
use App\Notifications\CrewJobDispatched;
use App\Notifications\EstimateProposalSent;
use App\Notifications\PaymentReceived;
use App\Services\TwilioService;

beforeEach(function () {
    // Mock TwilioService globally so routeNotificationForTwilio works
    $this->mock(TwilioService::class, function ($mock) {
        $mock->shouldReceive('formatE164')
            ->andReturnUsing(fn ($phone) => $phone ? '+1'.$phone : null);
        $mock->shouldReceive('isEnabled')->andReturn(true);
        $mock->shouldReceive('prependConsentIfNeeded')
            ->andReturnUsing(fn ($userId, $body) => $body);
        $mock->shouldReceive('sendSms')
            ->andReturn(['success' => true, 'sid' => 'SM123', 'error' => null]);
    });
});

describe('EstimateProposalSent SMS channel inclusion', function () {
    it('includes sms channel when user wants sms', function () {
        $user = User::factory()->customer()->create();
        $user->customerProfile()->update(['phone' => '5551234567']);
        $user->update([
            'notification_preferences' => [
                'estimates' => ['email' => true, 'push' => true, 'sms' => true],
            ],
        ]);

        $estimate = Estimate::factory()->sent()->create();
        $notification = new EstimateProposalSent($estimate);

        $channels = $notification->via($user);

        expect($channels)->toContain(SmsChannel::class);
    });

    it('excludes sms channel when user has no phone', function () {
        $user = User::factory()->customer()->create();
        // No phone set
        $user->update([
            'notification_preferences' => [
                'estimates' => ['email' => true, 'push' => true, 'sms' => true],
            ],
        ]);

        $estimate = Estimate::factory()->sent()->create();
        $notification = new EstimateProposalSent($estimate);

        $channels = $notification->via($user);

        expect($channels)->not->toContain(SmsChannel::class);
    });

    it('excludes sms channel when user opted out', function () {
        $user = User::factory()->customer()->create();
        $user->customerProfile()->update(['phone' => '5551234567']);
        $user->update([
            'sms_opted_out_at' => now(),
            'notification_preferences' => [
                'estimates' => ['email' => true, 'push' => true, 'sms' => true],
            ],
        ]);

        $estimate = Estimate::factory()->sent()->create();
        $notification = new EstimateProposalSent($estimate);

        $channels = $notification->via($user);

        expect($channels)->not->toContain(SmsChannel::class);
    });

    it('excludes sms channel when preference is off', function () {
        $user = User::factory()->customer()->create();
        $user->customerProfile()->update(['phone' => '5551234567']);
        $user->update([
            'notification_preferences' => [
                'estimates' => ['email' => true, 'push' => true, 'sms' => false],
            ],
        ]);

        $estimate = Estimate::factory()->sent()->create();
        $notification = new EstimateProposalSent($estimate);

        $channels = $notification->via($user);

        expect($channels)->not->toContain(SmsChannel::class);
    });
});

describe('SMS body generation', function () {
    it('generates correct sms body for EstimateProposalSent', function () {
        $user = User::factory()->customer()->create();
        $estimate = Estimate::factory()->sent()->create([
            'estimate_number' => 'EST-2026-00042',
        ]);

        $notification = new EstimateProposalSent($estimate);
        $smsMessage = $notification->toSms($user);

        expect($smsMessage->body)->toContain('EST-2026-00042')
            ->and($smsMessage->body)->toContain('ready to view');
    });

    it('generates correct sms body for CrewJobDispatched', function () {
        $user = User::factory()->crew()->create();
        $job = Job::factory()->create([
            'job_number' => 'JOB-2026-00077',
        ]);

        $notification = new CrewJobDispatched($job);
        $smsMessage = $notification->toSms($user);

        expect($smsMessage->body)->toContain('JOB-2026-00077')
            ->and($smsMessage->body)->toContain('dispatched');
    });

    it('generates correct sms body for PaymentReceived', function () {
        $user = User::factory()->customer()->create();
        $payment = Payment::factory()->create([
            'amount' => 1500.00,
        ]);
        $payment->load('invoice');

        $notification = new PaymentReceived($payment);
        $smsMessage = $notification->toSms($user);

        expect($smsMessage->body)->toContain('1,500.00')
            ->and($smsMessage->body)->toContain('Payment')
            ->and($smsMessage->body)->toContain($payment->invoice->invoice_number);
    });
});
