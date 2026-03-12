<?php

use App\Models\User;
use App\Services\TwilioService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    $this->service = new TwilioService;
});

describe('isEnabled', function () {
    it('returns false when twilio is not enabled', function () {
        Config::set('services.twilio.enabled', false);
        Config::set('services.twilio.sid', '');
        Config::set('services.twilio.token', '');
        Config::set('services.twilio.from', '');

        expect($this->service->isEnabled())->toBeFalse();
    });

    it('returns false when sid is missing', function () {
        Config::set('services.twilio.enabled', true);
        Config::set('services.twilio.sid', '');
        Config::set('services.twilio.token', 'test-token');
        Config::set('services.twilio.from', '+15551234567');

        expect($this->service->isEnabled())->toBeFalse();
    });

    it('returns false when token is missing', function () {
        Config::set('services.twilio.enabled', true);
        Config::set('services.twilio.sid', 'test-sid');
        Config::set('services.twilio.token', '');
        Config::set('services.twilio.from', '+15551234567');

        expect($this->service->isEnabled())->toBeFalse();
    });

    it('returns false when from number is missing', function () {
        Config::set('services.twilio.enabled', true);
        Config::set('services.twilio.sid', 'test-sid');
        Config::set('services.twilio.token', 'test-token');
        Config::set('services.twilio.from', '');

        expect($this->service->isEnabled())->toBeFalse();
    });

    it('returns true when twilio is properly configured', function () {
        Config::set('services.twilio.enabled', true);
        Config::set('services.twilio.sid', 'test-sid');
        Config::set('services.twilio.token', 'test-token');
        Config::set('services.twilio.from', '+15551234567');

        expect($this->service->isEnabled())->toBeTrue();
    });
});

describe('formatE164', function () {
    it('formats 10-digit US numbers to E164', function () {
        expect($this->service->formatE164('5551234567'))->toBe('+15551234567');
    });

    it('formats 11-digit US numbers with country code to E164', function () {
        expect($this->service->formatE164('15551234567'))->toBe('+15551234567');
    });

    it('formats numbers with + prefix to E164', function () {
        expect($this->service->formatE164('+15551234567'))->toBe('+15551234567');
    });

    it('formats numbers with non-digit characters', function () {
        expect($this->service->formatE164('(555) 123-4567'))->toBe('+15551234567');
    });

    it('formats numbers with dots and spaces', function () {
        expect($this->service->formatE164('555.123.4567'))->toBe('+15551234567');
    });

    it('returns null for invalid phone numbers', function () {
        expect($this->service->formatE164('123'))->toBeNull();
    });

    it('returns null for empty string', function () {
        expect($this->service->formatE164(''))->toBeNull();
    });

    it('returns null for too-short number', function () {
        expect($this->service->formatE164('12345'))->toBeNull();
    });
});

describe('sendSms', function () {
    it('returns error when twilio is not enabled', function () {
        Config::set('services.twilio.enabled', false);

        $result = $this->service->sendSms('+15551234567', 'Test message');

        expect($result)->toMatchArray([
            'success' => false,
            'sid' => null,
            'error' => 'Twilio is not enabled',
        ]);
    });

    it('returns error for invalid phone number', function () {
        Config::set('services.twilio.enabled', true);
        Config::set('services.twilio.sid', 'test-sid');
        Config::set('services.twilio.token', 'test-token');
        Config::set('services.twilio.from', '+15559999999');

        $result = $this->service->sendSms('123', 'Test message');

        expect($result)->toMatchArray([
            'success' => false,
            'sid' => null,
            'error' => 'Invalid phone number',
        ]);
    });

    it('rate limits SMS per phone per hour', function () {
        Config::set('services.twilio.enabled', true);
        Config::set('services.twilio.sid', 'test-sid');
        Config::set('services.twilio.token', 'test-token');
        Config::set('services.twilio.from', '+15559999999');

        // Simulate that 5 messages have already been sent
        Cache::put('sms_rate:+15551234567', 5, now()->addHour());

        $result = $this->service->sendSms('+15551234567', 'Test message');

        expect($result)->toMatchArray([
            'success' => false,
            'sid' => null,
            'error' => 'Rate limit exceeded',
        ]);
    });

    it('enforces daily SMS limit', function () {
        Config::set('services.twilio.enabled', true);
        Config::set('services.twilio.sid', 'test-sid');
        Config::set('services.twilio.token', 'test-token');
        Config::set('services.twilio.from', '+15559999999');
        Config::set('services.twilio.daily_limit', 500);

        // Simulate daily limit reached
        $dailyKey = 'sms_daily_count:'.now()->format('Y-m-d');
        Cache::put($dailyKey, 500, now()->endOfDay());

        $result = $this->service->sendSms('+15551234567', 'Test message');

        expect($result)->toMatchArray([
            'success' => false,
            'sid' => null,
            'error' => 'Daily SMS limit exceeded',
        ]);
    });
});

describe('prependConsentIfNeeded', function () {
    it('prepends consent message on first SMS to user', function () {
        Config::set('app.name', 'Apex');
        $user = User::factory()->customer()->create();

        $result = $this->service->prependConsentIfNeeded($user->id, 'Hello there');

        expect($result)->toBe("Apex alerts. Reply STOP to opt out. Msg&data rates may apply.\n\nHello there");
        expect($user->fresh()->sms_consented_at)->not->toBeNull();
    });

    it('does not prepend consent on second SMS to same user', function () {
        Config::set('app.name', 'Apex');
        $user = User::factory()->customer()->create();

        // First call sets the consent
        $this->service->prependConsentIfNeeded($user->id, 'First message');

        // Second call should skip consent
        $result = $this->service->prependConsentIfNeeded($user->id, 'Second message');

        expect($result)->toBe('Second message');
    });

    it('skips consent message for returning users', function () {
        $user = User::factory()->customer()->create([
            'sms_consented_at' => now()->subMonth(),
        ]);

        $result = $this->service->prependConsentIfNeeded($user->id, 'Hello again');

        expect($result)->toBe('Hello again');
    });

    it('returns body unchanged for non-existent user', function () {
        $result = $this->service->prependConsentIfNeeded(99999, 'Hello');

        expect($result)->toBe('Hello');
    });
});
