<?php

use App\Events\IncomingCall;
use App\Models\CallGroup;
use App\Models\CallGroupMember;
use App\Models\CallLog;
use App\Models\CompanySetting;
use App\Models\User;
use App\Services\CallRoutingService;
use App\Services\TwilioService;
use App\Services\TwilioVoiceService;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->twilioMock = $this->partialMock(TwilioService::class, function ($mock) {
        $mock->shouldReceive('validateWebhookSignature')->andReturn(true)->byDefault();
        $mock->shouldReceive('formatE164')->andReturn(null)->byDefault();
    });

    CompanySetting::clearInstanceCache();
});

afterEach(function () {
    CompanySetting::clearInstanceCache();
});

// ---------------------------------------------------------------------------
// generateIvrMenuTwiml tests
// ---------------------------------------------------------------------------

describe('generateIvrMenuTwiml', function () {
    it('renders menu options from options array', function () {
        $settings = CompanySetting::getInstance();
        $settings->update([
            'ivr_enabled' => true,
            'ivr_menu' => [
                'options' => [
                    ['digit' => '1', 'label' => 'Estimates', 'call_group_id' => 1],
                    ['digit' => '2', 'label' => 'Scheduling', 'call_group_id' => 2],
                    ['digit' => '0', 'label' => 'Speak with someone', 'call_group_id' => 3],
                ],
                'extension_prompt' => false,
                'timeout_action' => 'ring_default',
            ],
        ]);
        CompanySetting::clearInstanceCache();

        $callLog = CallLog::factory()->create();
        $service = app(TwilioVoiceService::class);
        $twiml = $service->generateIvrMenuTwiml($callLog);

        $xml = simplexml_load_string($twiml);
        expect($xml)->not->toBeFalse()
            ->and($twiml)->toContain('<Gather')
            ->and($twiml)->toContain('Press 1 for Estimates')
            ->and($twiml)->toContain('Press 2 for Scheduling')
            ->and($twiml)->toContain('Press 0 for Speak with someone')
            ->and($twiml)->toContain('numDigits="1"')
            ->and($twiml)->not->toContain('extension');
    });

    it('includes extension prompt and uses finishOnKey when extension_prompt is true', function () {
        $settings = CompanySetting::getInstance();
        $settings->update([
            'ivr_enabled' => true,
            'ivr_menu' => [
                'options' => [
                    ['digit' => '1', 'label' => 'Sales', 'call_group_id' => 1],
                ],
                'extension_prompt' => true,
                'timeout_action' => 'ring_default',
            ],
        ]);
        CompanySetting::clearInstanceCache();

        $callLog = CallLog::factory()->create();
        $service = app(TwilioVoiceService::class);
        $twiml = $service->generateIvrMenuTwiml($callLog);

        expect($twiml)->toContain('extension at any time')
            ->and($twiml)->toContain('finishOnKey="#"')
            ->and($twiml)->not->toContain('numDigits');
    });

    it('renders empty menu gracefully when no options configured', function () {
        $settings = CompanySetting::getInstance();
        $settings->update([
            'ivr_enabled' => true,
            'ivr_menu' => [
                'options' => [],
                'extension_prompt' => false,
                'timeout_action' => 'voicemail',
            ],
        ]);
        CompanySetting::clearInstanceCache();

        $callLog = CallLog::factory()->create();
        $service = app(TwilioVoiceService::class);
        $twiml = $service->generateIvrMenuTwiml($callLog);

        $xml = simplexml_load_string($twiml);
        expect($xml)->not->toBeFalse()
            ->and($twiml)->toContain('<Gather')
            ->and($twiml)->toContain('Thank you for calling.');
    });
});

// ---------------------------------------------------------------------------
// resolveFromExtension tests
// ---------------------------------------------------------------------------

describe('resolveFromExtension', function () {
    it('finds an internal user by extension with forwarding number', function () {
        $user = User::factory()->internal()->create([
            'extension' => '101',
            'forwarding_number' => '+13035551234',
        ]);

        $service = app(CallRoutingService::class);
        $result = $service->resolveFromExtension('101');

        expect($result)->not->toBeNull()
            ->and($result->id)->toBe($user->id);
    });

    it('returns null for non-existent extension', function () {
        $service = app(CallRoutingService::class);
        $result = $service->resolveFromExtension('999');

        expect($result)->toBeNull();
    });

    it('returns null when user has no forwarding number', function () {
        User::factory()->internal()->create([
            'extension' => '102',
            'forwarding_number' => null,
        ]);

        $service = app(CallRoutingService::class);
        $result = $service->resolveFromExtension('102');

        expect($result)->toBeNull();
    });

    it('returns null for non-internal user types', function () {
        User::factory()->customer()->create([
            'extension' => '103',
            'forwarding_number' => '+13035559876',
        ]);

        $service = app(CallRoutingService::class);
        $result = $service->resolveFromExtension('103');

        expect($result)->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// resolveIvrOption tests
// ---------------------------------------------------------------------------

describe('resolveIvrOption', function () {
    it('resolves a digit to the correct call group', function () {
        $group = CallGroup::factory()->create(['is_active' => true]);

        $ivrMenu = [
            'options' => [
                ['digit' => '1', 'label' => 'Estimates', 'call_group_id' => $group->id],
                ['digit' => '2', 'label' => 'Scheduling', 'call_group_id' => 999],
            ],
        ];

        $service = app(CallRoutingService::class);
        $result = $service->resolveIvrOption('1', $ivrMenu);

        expect($result)->not->toBeNull()
            ->and($result->id)->toBe($group->id);
    });

    it('returns null for unmatched digit', function () {
        $ivrMenu = [
            'options' => [
                ['digit' => '1', 'label' => 'Sales', 'call_group_id' => 1],
            ],
        ];

        $service = app(CallRoutingService::class);
        $result = $service->resolveIvrOption('9', $ivrMenu);

        expect($result)->toBeNull();
    });

    it('returns null when options array is empty', function () {
        $service = app(CallRoutingService::class);
        $result = $service->resolveIvrOption('1', ['options' => []]);

        expect($result)->toBeNull();
    });

    it('returns null when call_group_id does not exist', function () {
        $ivrMenu = [
            'options' => [
                ['digit' => '1', 'label' => 'Nonexistent', 'call_group_id' => 99999],
            ],
        ];

        $service = app(CallRoutingService::class);
        $result = $service->resolveIvrOption('1', $ivrMenu);

        expect($result)->toBeNull();
    });
});

// ---------------------------------------------------------------------------
// ivrSelect webhook — single digit (IVR menu option)
// ---------------------------------------------------------------------------

describe('ivrSelect webhook — single digit', function () {
    it('routes to correct call group and broadcasts IncomingCall', function () {
        Event::fake([IncomingCall::class]);

        $group = CallGroup::factory()->create(['is_active' => true]);
        $member = User::factory()->internal()->create(['forwarding_number' => '+13035550001']);
        CallGroupMember::create([
            'call_group_id' => $group->id,
            'user_id' => $member->id,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $settings = CompanySetting::getInstance();
        $settings->update([
            'ivr_enabled' => true,
            'ivr_menu' => [
                'options' => [
                    ['digit' => '1', 'label' => 'Estimates', 'call_group_id' => $group->id],
                ],
                'extension_prompt' => false,
                'timeout_action' => 'ring_default',
            ],
        ]);
        CompanySetting::clearInstanceCache();

        $callLog = CallLog::factory()->create(['status' => 'ringing']);

        $response = $this->post("/webhooks/twilio/voice/ivr-select/{$callLog->ulid}", [
            'Digits' => '1',
        ]);

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'text/xml; charset=UTF-8');

        // Should contain a Dial with the member's number
        expect($response->getContent())->toContain('<Dial')
            ->and($response->getContent())->toContain('+13035550001');

        // Call log metadata should record the IVR option
        $callLog->refresh();
        expect($callLog->metadata['ivr_option'])->toBe('1')
            ->and($callLog->metadata['call_group_id'])->toBe($group->id);

        Event::assertDispatched(IncomingCall::class, function ($event) use ($member) {
            return in_array($member->id, $event->targetUserIds);
        });
    });

    it('replays IVR menu for invalid digit', function () {
        Event::fake([IncomingCall::class]);

        $settings = CompanySetting::getInstance();
        $settings->update([
            'ivr_enabled' => true,
            'ivr_menu' => [
                'options' => [
                    ['digit' => '1', 'label' => 'Sales', 'call_group_id' => 1],
                ],
                'extension_prompt' => false,
                'timeout_action' => 'ring_default',
            ],
        ]);
        CompanySetting::clearInstanceCache();

        $callLog = CallLog::factory()->create(['status' => 'ringing']);

        $response = $this->post("/webhooks/twilio/voice/ivr-select/{$callLog->ulid}", [
            'Digits' => '9',
        ]);

        $response->assertStatus(200);
        // Should replay IVR menu (contains a Gather)
        expect($response->getContent())->toContain('<Gather');
    });
});

// ---------------------------------------------------------------------------
// ivrSelect webhook — multi-digit (extension dialing)
// ---------------------------------------------------------------------------

describe('ivrSelect webhook — extension dialing', function () {
    it('dials forwarding number for valid extension', function () {
        Event::fake([IncomingCall::class]);

        $user = User::factory()->internal()->create([
            'extension' => '101',
            'forwarding_number' => '+13035559999',
        ]);

        $settings = CompanySetting::getInstance();
        $settings->update([
            'ivr_enabled' => true,
            'ivr_menu' => [
                'options' => [],
                'extension_prompt' => true,
                'timeout_action' => 'ring_default',
            ],
        ]);
        CompanySetting::clearInstanceCache();

        $callLog = CallLog::factory()->create(['status' => 'ringing']);

        $response = $this->post("/webhooks/twilio/voice/ivr-select/{$callLog->ulid}", [
            'Digits' => '101',
        ]);

        $response->assertStatus(200);
        expect($response->getContent())->toContain('<Dial')
            ->and($response->getContent())->toContain('+13035559999');

        // Metadata should record extension info
        $callLog->refresh();
        expect($callLog->metadata['extension_dialed'])->toBe('101')
            ->and($callLog->metadata['extension_user_id'])->toBe($user->id);

        Event::assertDispatched(IncomingCall::class, function ($event) use ($user) {
            return $event->targetUserIds === [$user->id];
        });
    });

    it('replays IVR menu for unknown extension', function () {
        Event::fake([IncomingCall::class]);

        $settings = CompanySetting::getInstance();
        $settings->update([
            'ivr_enabled' => true,
            'ivr_menu' => [
                'options' => [
                    ['digit' => '1', 'label' => 'Sales', 'call_group_id' => 1],
                ],
                'extension_prompt' => true,
                'timeout_action' => 'ring_default',
            ],
        ]);
        CompanySetting::clearInstanceCache();

        $callLog = CallLog::factory()->create(['status' => 'ringing']);

        $response = $this->post("/webhooks/twilio/voice/ivr-select/{$callLog->ulid}", [
            'Digits' => '999',
        ]);

        $response->assertStatus(200);
        // Should replay IVR menu
        expect($response->getContent())->toContain('<Gather');
    });

    it('handles 4-digit extensions', function () {
        Event::fake([IncomingCall::class]);

        $user = User::factory()->internal()->create([
            'extension' => '1001',
            'forwarding_number' => '+13035558888',
        ]);

        $settings = CompanySetting::getInstance();
        $settings->update([
            'ivr_enabled' => true,
            'ivr_menu' => [
                'options' => [],
                'extension_prompt' => true,
                'timeout_action' => 'ring_default',
            ],
        ]);
        CompanySetting::clearInstanceCache();

        $callLog = CallLog::factory()->create(['status' => 'ringing']);

        $response = $this->post("/webhooks/twilio/voice/ivr-select/{$callLog->ulid}", [
            'Digits' => '1001',
        ]);

        $response->assertStatus(200);
        expect($response->getContent())->toContain('+13035558888');
    });
});

// ---------------------------------------------------------------------------
// ivrSelect webhook — timeout (no digits)
// ---------------------------------------------------------------------------

describe('ivrSelect webhook — timeout', function () {
    it('routes to default call group on timeout with ring_default action', function () {
        Event::fake([IncomingCall::class]);

        $defaultGroup = CallGroup::factory()->create(['is_active' => true]);
        $member = User::factory()->internal()->create(['forwarding_number' => '+13035550002']);
        CallGroupMember::create([
            'call_group_id' => $defaultGroup->id,
            'user_id' => $member->id,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $settings = CompanySetting::getInstance();
        $settings->update([
            'default_call_group_id' => $defaultGroup->id,
            'ivr_enabled' => true,
            'ivr_menu' => [
                'options' => [
                    ['digit' => '1', 'label' => 'Sales', 'call_group_id' => $defaultGroup->id],
                ],
                'extension_prompt' => false,
                'timeout_action' => 'ring_default',
            ],
        ]);
        CompanySetting::clearInstanceCache();

        $callLog = CallLog::factory()->create(['status' => 'ringing']);

        $response = $this->post("/webhooks/twilio/voice/ivr-select/{$callLog->ulid}", [
            'Digits' => '',
        ]);

        $response->assertStatus(200);
        expect($response->getContent())->toContain('<Dial')
            ->and($response->getContent())->toContain('+13035550002');

        Event::assertDispatched(IncomingCall::class);
    });

    it('goes to voicemail on timeout with voicemail action', function () {
        Event::fake([IncomingCall::class]);

        $settings = CompanySetting::getInstance();
        $settings->update([
            'ivr_enabled' => true,
            'ivr_menu' => [
                'options' => [],
                'extension_prompt' => false,
                'timeout_action' => 'voicemail',
            ],
        ]);
        CompanySetting::clearInstanceCache();

        $callLog = CallLog::factory()->create(['status' => 'ringing']);

        $response = $this->post("/webhooks/twilio/voice/ivr-select/{$callLog->ulid}", [
            'Digits' => '',
        ]);

        $response->assertStatus(200);
        expect($response->getContent())->toContain('Record');

        $callLog->refresh();
        expect($callLog->status)->toBe('voicemail');
    });

    it('goes to voicemail on timeout when no default group exists', function () {
        $settings = CompanySetting::getInstance();
        $settings->update([
            'default_call_group_id' => null,
            'ivr_enabled' => true,
            'ivr_menu' => [
                'options' => [],
                'extension_prompt' => false,
                'timeout_action' => 'ring_default',
            ],
        ]);
        CompanySetting::clearInstanceCache();

        $callLog = CallLog::factory()->create(['status' => 'ringing']);

        $response = $this->post("/webhooks/twilio/voice/ivr-select/{$callLog->ulid}", [
            'Digits' => '',
        ]);

        $response->assertStatus(200);
        expect($response->getContent())->toContain('Record');

        $callLog->refresh();
        expect($callLog->status)->toBe('voicemail');
    });
});

// ---------------------------------------------------------------------------
// ivrSelect webhook — edge cases
// ---------------------------------------------------------------------------

describe('ivrSelect webhook — edge cases', function () {
    it('returns hangup for unknown call log ulid', function () {
        $response = $this->post('/webhooks/twilio/voice/ivr-select/nonexistentulid', [
            'Digits' => '1',
        ]);

        $response->assertStatus(200);
        expect($response->getContent())->toContain('Hangup');
    });

    it('rejects invalid twilio signature', function () {
        $this->twilioMock->shouldReceive('validateWebhookSignature')->andReturn(false);

        $callLog = CallLog::factory()->create();

        $response = $this->post("/webhooks/twilio/voice/ivr-select/{$callLog->ulid}", [
            'Digits' => '1',
        ]);

        $response->assertForbidden();
    });

    it('replays IVR menu for unexpected digit length (2 digits)', function () {
        $settings = CompanySetting::getInstance();
        $settings->update([
            'ivr_enabled' => true,
            'ivr_menu' => [
                'options' => [
                    ['digit' => '1', 'label' => 'Sales', 'call_group_id' => 1],
                ],
                'extension_prompt' => true,
                'timeout_action' => 'ring_default',
            ],
        ]);
        CompanySetting::clearInstanceCache();

        $callLog = CallLog::factory()->create(['status' => 'ringing']);

        $response = $this->post("/webhooks/twilio/voice/ivr-select/{$callLog->ulid}", [
            'Digits' => '12',
        ]);

        $response->assertStatus(200);
        expect($response->getContent())->toContain('<Gather');
    });

    it('replays IVR menu for too many digits (5+)', function () {
        $settings = CompanySetting::getInstance();
        $settings->update([
            'ivr_enabled' => true,
            'ivr_menu' => [
                'options' => [],
                'extension_prompt' => true,
                'timeout_action' => 'ring_default',
            ],
        ]);
        CompanySetting::clearInstanceCache();

        $callLog = CallLog::factory()->create(['status' => 'ringing']);

        $response = $this->post("/webhooks/twilio/voice/ivr-select/{$callLog->ulid}", [
            'Digits' => '12345',
        ]);

        $response->assertStatus(200);
        expect($response->getContent())->toContain('<Gather');
    });
});

// ---------------------------------------------------------------------------
// incomingCall routes to IVR when enabled
// ---------------------------------------------------------------------------

describe('incomingCall IVR routing', function () {
    it('routes to IVR menu when ivr_enabled is true', function () {
        Event::fake([IncomingCall::class]);

        $this->mock(CallRoutingService::class, function ($mock) {
            $mock->shouldReceive('findCallerIdentity')->andReturn(['user' => null, 'customer' => null]);
            // resolveCallGroup should NOT be called when IVR is enabled
            $mock->shouldNotReceive('resolveCallGroup');
        });

        $settings = CompanySetting::getInstance();
        $settings->update([
            'ivr_enabled' => true,
            'ivr_menu' => [
                'options' => [
                    ['digit' => '1', 'label' => 'Sales', 'call_group_id' => 1],
                ],
                'extension_prompt' => false,
                'timeout_action' => 'ring_default',
            ],
        ]);
        CompanySetting::clearInstanceCache();

        $callSid = 'CA'.fake()->sha1();

        $response = $this->post('/webhooks/twilio/voice', [
            'From' => '+13035551234',
            'To' => '+13035559999',
            'CallSid' => $callSid,
        ]);

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'text/xml; charset=UTF-8');

        // Should return IVR menu TwiML with Gather
        expect($response->getContent())->toContain('<Gather')
            ->and($response->getContent())->toContain('Press 1 for Sales');

        // Call log should still be created
        $this->assertDatabaseHas('call_logs', [
            'twilio_call_sid' => $callSid,
            'direction' => 'inbound',
            'status' => 'ringing',
        ]);

        Event::assertDispatched(IncomingCall::class);
    });

    it('skips IVR and routes directly when ivr_enabled is false', function () {
        Event::fake([IncomingCall::class]);

        $this->mock(CallRoutingService::class, function ($mock) {
            $mock->shouldReceive('findCallerIdentity')->andReturn(['user' => null, 'customer' => null]);
            $mock->shouldReceive('resolveCallGroup')->andReturn(null);
        });

        $settings = CompanySetting::getInstance();
        $settings->update(['ivr_enabled' => false]);
        CompanySetting::clearInstanceCache();

        $response = $this->post('/webhooks/twilio/voice', [
            'From' => '+13035551234',
            'To' => '+13035559999',
            'CallSid' => 'CA'.fake()->sha1(),
        ]);

        $response->assertStatus(200);

        // Should NOT contain IVR Gather, should go to voicemail (no call group)
        expect($response->getContent())->toContain('Record')
            ->and($response->getContent())->not->toContain('Press 1');
    });
});
