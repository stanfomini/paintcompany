<?php

use App\Channels\SmsChannel;
use App\Models\EmployeeContract;
use App\Models\Estimate;
use App\Models\Job;
use App\Models\User;
use App\Notifications\ContractSentForSigning;
use App\Notifications\CustomerConfirmedColors;
use App\Notifications\CustomerProposedDate;
use App\Notifications\EstimateAccepted;
use App\Notifications\EstimateRejected;
use App\Notifications\JobCompleted;
use App\Notifications\JobCreated;
use App\Services\TwilioService;

beforeEach(function () {
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

describe('EstimateAccepted SMS', function () {
    it('includes sms channel when user wants sms', function () {
        $user = User::factory()->internal()->create(['phone' => '5551234567']);
        $user->update([
            'notification_preferences' => [
                'estimates' => ['email' => true, 'push' => true, 'sms' => true],
            ],
        ]);

        $estimate = Estimate::factory()->create();
        $notification = new EstimateAccepted($estimate);
        $channels = $notification->via($user);

        expect($channels)->toContain(SmsChannel::class);
    });

    it('generates correct sms body', function () {
        $user = User::factory()->internal()->create();
        $estimate = Estimate::factory()->create([
            'estimate_number' => 'EST-2026-00100',
        ]);

        $notification = new EstimateAccepted($estimate);
        $smsMessage = $notification->toSms($user);

        expect($smsMessage->body)->toContain('EST-2026-00100')
            ->and($smsMessage->body)->toContain('accepted');
    });
});

describe('EstimateRejected SMS', function () {
    it('includes sms channel when user wants sms', function () {
        $user = User::factory()->internal()->create(['phone' => '5551234567']);
        $user->update([
            'notification_preferences' => [
                'estimates' => ['email' => true, 'push' => true, 'sms' => true],
            ],
        ]);

        $estimate = Estimate::factory()->create();
        $notification = new EstimateRejected($estimate, 'Too expensive');
        $channels = $notification->via($user);

        expect($channels)->toContain(SmsChannel::class);
    });

    it('generates correct sms body', function () {
        $user = User::factory()->internal()->create();
        $estimate = Estimate::factory()->create([
            'estimate_number' => 'EST-2026-00101',
        ]);

        $notification = new EstimateRejected($estimate);
        $smsMessage = $notification->toSms($user);

        expect($smsMessage->body)->toContain('EST-2026-00101')
            ->and($smsMessage->body)->toContain('rejected');
    });
});

describe('JobCreated SMS', function () {
    it('includes sms channel when user wants sms', function () {
        $user = User::factory()->internal()->create(['phone' => '5551234567']);
        $user->update([
            'notification_preferences' => [
                'jobs' => ['email' => true, 'push' => true, 'sms' => true],
            ],
        ]);

        $job = Job::factory()->create();
        $notification = new JobCreated($job);
        $channels = $notification->via($user);

        expect($channels)->toContain(SmsChannel::class);
    });

    it('generates correct sms body', function () {
        $user = User::factory()->customer()->create();
        $job = Job::factory()->create(['job_number' => 'JOB-2026-00050']);

        $notification = new JobCreated($job);
        $smsMessage = $notification->toSms($user);

        expect($smsMessage->body)->toContain('JOB-2026-00050')
            ->and($smsMessage->body)->toContain('created');
    });
});

describe('JobCompleted SMS', function () {
    it('includes sms channel when user wants sms', function () {
        $user = User::factory()->customer()->create(['phone' => '5551234567']);
        $user->update([
            'notification_preferences' => [
                'jobs' => ['email' => true, 'push' => true, 'sms' => true],
            ],
        ]);

        $job = Job::factory()->create();
        $notification = new JobCompleted($job);
        $channels = $notification->via($user);

        expect($channels)->toContain(SmsChannel::class);
    });

    it('generates correct sms body', function () {
        $user = User::factory()->customer()->create();
        $job = Job::factory()->create(['job_number' => 'JOB-2026-00051']);

        $notification = new JobCompleted($job);
        $smsMessage = $notification->toSms($user);

        expect($smsMessage->body)->toContain('JOB-2026-00051')
            ->and($smsMessage->body)->toContain('complete');
    });
});

describe('ContractSentForSigning SMS', function () {
    it('includes sms channel when user wants sms', function () {
        $user = User::factory()->internal()->create(['phone' => '5551234567']);
        $user->update([
            'notification_preferences' => [
                'documents' => ['email' => true, 'push' => true, 'sms' => true],
            ],
        ]);

        $contract = EmployeeContract::factory()->create();
        $notification = new ContractSentForSigning($contract);
        $channels = $notification->via($user);

        expect($channels)->toContain(SmsChannel::class);
    });

    it('generates correct sms body', function () {
        $user = User::factory()->internal()->create();
        $contract = EmployeeContract::factory()->create(['title' => 'Employment Agreement']);

        $notification = new ContractSentForSigning($contract);
        $smsMessage = $notification->toSms($user);

        expect($smsMessage->body)->toContain('Employment Agreement')
            ->and($smsMessage->body)->toContain('contract');
    });
});

describe('CustomerConfirmedColors SMS', function () {
    it('includes sms channel for internal users who want sms', function () {
        $user = User::factory()->internal()->create(['phone' => '5551234567']);
        $user->update([
            'notification_preferences' => [
                'jobs' => ['email' => true, 'push' => true, 'sms' => true],
            ],
        ]);

        $job = Job::factory()->create();
        $notification = new CustomerConfirmedColors($job);
        $channels = $notification->via($user);

        expect($channels)->toContain(SmsChannel::class);
    });

    it('excludes sms for non-internal users', function () {
        $user = User::factory()->customer()->create(['phone' => '5551234567']);

        $job = Job::factory()->create();
        $notification = new CustomerConfirmedColors($job);
        $channels = $notification->via($user);

        expect($channels)->toBe([]);
    });

    it('generates correct sms body', function () {
        $user = User::factory()->internal()->create();
        $job = Job::factory()->create(['job_number' => 'JOB-2026-00060']);

        $notification = new CustomerConfirmedColors($job);
        $smsMessage = $notification->toSms($user);

        expect($smsMessage->body)->toContain('JOB-2026-00060')
            ->and($smsMessage->body)->toContain('colors');
    });
});

describe('CustomerProposedDate SMS', function () {
    it('includes sms channel for internal users who want sms', function () {
        $user = User::factory()->internal()->create(['phone' => '5551234567']);
        $user->update([
            'notification_preferences' => [
                'jobs' => ['email' => true, 'push' => true, 'sms' => true],
            ],
        ]);

        $job = Job::factory()->create();
        $notification = new CustomerProposedDate($job, 'Mar 20, 2026');
        $channels = $notification->via($user);

        expect($channels)->toContain(SmsChannel::class);
    });

    it('generates correct sms body', function () {
        $user = User::factory()->internal()->create();
        $job = Job::factory()->create(['job_number' => 'JOB-2026-00070']);

        $notification = new CustomerProposedDate($job, 'Mar 20, 2026');
        $smsMessage = $notification->toSms($user);

        expect($smsMessage->body)->toContain('JOB-2026-00070')
            ->and($smsMessage->body)->toContain('Mar 20, 2026');
    });
});

describe('Appointments notification preference', function () {
    it('includes appointments in default preferences', function () {
        $defaults = User::defaultNotificationPreferences();

        expect($defaults)->toHaveKey('appointments')
            ->and($defaults['appointments'])->toBe(['email' => true, 'push' => true, 'sms' => true]);
    });

    it('wantsSms returns true for appointments category by default', function () {
        $user = User::factory()->customer()->create(['phone' => '5551234567']);

        expect($user->wantsSms('appointments'))->toBeTrue();
    });
});
