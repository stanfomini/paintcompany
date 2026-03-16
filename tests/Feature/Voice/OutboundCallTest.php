<?php

use App\Events\CallConnected;
use App\Events\CallEnded;
use App\Models\CallLog;
use App\Models\CompanySetting;
use App\Models\User;
use App\Services\TwilioService;
use App\Services\TwilioVoiceService;
use Illuminate\Support\Facades\Event;
use Twilio\Rest\Api\V2010\Account\CallContext;
use Twilio\Rest\Api\V2010\Account\CallInstance;
use Twilio\Rest\Api\V2010\Account\CallList;
use Twilio\Rest\Client;

beforeEach(function () {
    CompanySetting::clearInstanceCache();
    config([
        'services.twilio.enabled' => true,
        'services.twilio.sid' => 'test-sid',
        'services.twilio.token' => 'test-token',
        'services.twilio.from' => '+15551234567',
        'services.twilio.voice_enabled' => true,
    ]);

    $this->twilioMock = $this->partialMock(TwilioService::class, function ($mock) {
        $mock->shouldReceive('validateWebhookSignature')->andReturn(true)->byDefault();
        $mock->shouldReceive('formatE164')->andReturnUsing(fn ($phone) => preg_match('/^\+1\d{10}$/', $phone) ? $phone : (strlen(preg_replace('/\D/', '', $phone)) === 10 ? '+1'.preg_replace('/\D/', '', $phone) : null))->byDefault();
    });

    $this->internalUser = User::factory()->internal()->create([
        'forwarding_number' => '+13035551111',
    ]);
    $this->internalUser->givePermissionTo('phone.make_calls');
});

afterEach(function () {
    CompanySetting::clearInstanceCache();
});

// ===================================================================
// CallController::initiate() tests
// ===================================================================

describe('initiate outbound call endpoint', function () {
    it('returns 422 when to field is missing', function () {
        $this->actingAs($this->internalUser);

        $response = $this->postJson('/calls/initiate', []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('to');
    });

    it('returns 422 when user has no forwarding number', function () {
        $user = User::factory()->internal()->create(['forwarding_number' => null]);
        $user->givePermissionTo('phone.make_calls');
        $this->actingAs($user);

        $response = $this->postJson('/calls/initiate', ['to' => '+13035552222']);

        $response->assertStatus(422)
            ->assertJson(['error' => 'You must configure a forwarding number before making calls.']);
    });

    it('returns 422 when voice calling is disabled', function () {
        config(['services.twilio.voice_enabled' => false]);
        $this->actingAs($this->internalUser);

        $response = $this->postJson('/calls/initiate', ['to' => '+13035552222']);

        $response->assertStatus(422)
            ->assertJson(['error' => 'Voice calling is not enabled.']);
    });

    it('returns 403 when user lacks phone.make_calls permission', function () {
        $user = User::factory()->internal()->create(['forwarding_number' => '+13035551111']);
        $this->actingAs($user);

        $response = $this->postJson('/calls/initiate', ['to' => '+13035552222']);

        $response->assertForbidden();
    });

    it('returns 401 for unauthenticated user', function () {
        $response = $this->postJson('/calls/initiate', ['to' => '+13035552222']);

        $response->assertUnauthorized();
    });

    it('creates call log and returns json on success', function () {
        $this->actingAs($this->internalUser);

        // Mock the Twilio client calls->create
        $callInstance = Mockery::mock(CallInstance::class);
        $callInstance->sid = 'CA'.str_repeat('a', 32);

        $callList = Mockery::mock(CallList::class);
        $callList->shouldReceive('create')
            ->once()
            ->withArgs(function ($to, $from, $options) {
                return $to === '+13035551111'
                    && $from === '+15551234567'
                    && isset($options['url'])
                    && isset($options['statusCallback'])
                    && isset($options['statusCallbackEvent']);
            })
            ->andReturn($callInstance);

        $client = Mockery::mock(Client::class);
        $client->calls = $callList;

        $this->twilioMock->shouldReceive('getClient')->andReturn($client);

        $response = $this->postJson('/calls/initiate', ['to' => '+13035552222']);

        $response->assertSuccessful()
            ->assertJsonStructure(['call_log_id', 'ulid', 'status']);

        $this->assertDatabaseHas('call_logs', [
            'direction' => 'outbound',
            'from_number' => '+15551234567',
            'to_number' => '+13035552222',
            'caller_user_id' => $this->internalUser->id,
            'status' => 'ringing',
            'twilio_call_sid' => 'CA'.str_repeat('a', 32),
        ]);
    });

    it('returns 422 for invalid phone number format', function () {
        $this->actingAs($this->internalUser);

        $this->twilioMock->shouldReceive('formatE164')->with('invalid')->andReturn(null);

        $response = $this->postJson('/calls/initiate', ['to' => 'invalid']);

        $response->assertStatus(422)
            ->assertJson(['error' => 'Invalid phone number format.']);
    });

    it('includes recording option when call recording is enabled', function () {
        $this->actingAs($this->internalUser);

        $settings = CompanySetting::getInstance();
        $settings->update(['call_recording_enabled' => true]);
        CompanySetting::clearInstanceCache();

        $callInstance = Mockery::mock(CallInstance::class);
        $callInstance->sid = 'CArec'.str_repeat('b', 29);

        $callList = Mockery::mock(CallList::class);
        $callList->shouldReceive('create')
            ->once()
            ->withArgs(function ($to, $from, $options) {
                return isset($options['record'])
                    && $options['record'] === 'record-from-answer-dual';
            })
            ->andReturn($callInstance);

        $client = Mockery::mock(Client::class);
        $client->calls = $callList;

        $this->twilioMock->shouldReceive('getClient')->andReturn($client);

        $response = $this->postJson('/calls/initiate', ['to' => '+13035552222']);

        $response->assertSuccessful();
    });
});

// ===================================================================
// outbound-connect webhook tests
// ===================================================================

describe('outbound-connect webhook', function () {
    it('rejects requests with invalid twilio signature', function () {
        $this->twilioMock->shouldReceive('validateWebhookSignature')->andReturn(false);

        $callLog = CallLog::factory()->outbound()->create();

        $response = $this->post("/webhooks/twilio/voice/outbound-connect/{$callLog->ulid}");

        $response->assertStatus(403);
    });

    it('returns hangup twiml for unknown call log ulid', function () {
        $response = $this->post('/webhooks/twilio/voice/outbound-connect/nonexistentulid');

        $response->assertStatus(200);
        expect($response->getContent())->toContain('Hangup');
    });

    it('updates call log to in-progress and sets answered_at', function () {
        Event::fake([CallConnected::class]);

        $user = User::factory()->internal()->create();
        $callLog = CallLog::factory()->outbound()->create([
            'status' => 'ringing',
            'answered_at' => null,
            'caller_user_id' => $user->id,
            'answered_by_user_id' => null,
        ]);

        $response = $this->post("/webhooks/twilio/voice/outbound-connect/{$callLog->ulid}");

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'text/xml; charset=UTF-8');

        $callLog->refresh();
        expect($callLog->status)->toBe('in-progress')
            ->and($callLog->answered_at)->not->toBeNull()
            ->and($callLog->answered_by_user_id)->toBe($user->id);
    });

    it('generates twiml that dials the target number', function () {
        Event::fake([CallConnected::class]);

        $user = User::factory()->internal()->create();
        $callLog = CallLog::factory()->outbound()->create([
            'status' => 'ringing',
            'to_number' => '+13035559999',
            'from_number' => '+15551234567',
            'caller_user_id' => $user->id,
            'answered_at' => null,
        ]);

        $response = $this->post("/webhooks/twilio/voice/outbound-connect/{$callLog->ulid}");

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'text/xml; charset=UTF-8');

        $content = $response->getContent();
        expect($content)->toContain('<Dial')
            ->and($content)->toContain('+13035559999')
            ->and($content)->toContain('callerId')
            ->and($content)->toContain('+15551234567');
    });

    it('includes dial-status action url in twiml', function () {
        Event::fake([CallConnected::class]);

        $user = User::factory()->internal()->create();
        $callLog = CallLog::factory()->outbound()->create([
            'status' => 'ringing',
            'caller_user_id' => $user->id,
            'answered_at' => null,
        ]);

        $response = $this->post("/webhooks/twilio/voice/outbound-connect/{$callLog->ulid}");

        $response->assertStatus(200);

        $content = $response->getContent();
        expect($content)->toContain('dial-status')
            ->and($content)->toContain($callLog->ulid);
    });

    it('broadcasts CallConnected event', function () {
        Event::fake([CallConnected::class]);

        $user = User::factory()->internal()->create();
        $callLog = CallLog::factory()->outbound()->create([
            'status' => 'ringing',
            'caller_user_id' => $user->id,
            'answered_at' => null,
        ]);

        $this->post("/webhooks/twilio/voice/outbound-connect/{$callLog->ulid}");

        Event::assertDispatched(CallConnected::class, function ($event) use ($callLog, $user) {
            return $event->callLog->id === $callLog->id
                && $event->answeredByUserId === $user->id;
        });
    });
});

// ===================================================================
// CallController::hangup() tests
// ===================================================================

describe('hangup endpoint', function () {
    it('returns 403 when user lacks permission', function () {
        $user = User::factory()->internal()->create();
        $this->actingAs($user);

        $callLog = CallLog::factory()->create();

        $response = $this->postJson("/calls/{$callLog->id}/hangup");

        $response->assertForbidden();
    });

    it('updates call log to completed and sets ended_at', function () {
        Event::fake([CallEnded::class]);
        $this->actingAs($this->internalUser);

        $callLog = CallLog::factory()->outbound()->create([
            'status' => 'in-progress',
            'caller_user_id' => $this->internalUser->id,
            'twilio_call_sid' => 'pending-test123',
            'ended_at' => null,
        ]);

        $response = $this->postJson("/calls/{$callLog->id}/hangup");

        $response->assertSuccessful()
            ->assertJson(['status' => 'completed']);

        $callLog->refresh();
        expect($callLog->status)->toBe('completed')
            ->and($callLog->ended_at)->not->toBeNull();
    });

    it('calls twilio api to end the call when sid is real', function () {
        Event::fake([CallEnded::class]);
        $this->actingAs($this->internalUser);

        $callLog = CallLog::factory()->outbound()->create([
            'status' => 'in-progress',
            'twilio_call_sid' => 'CAreal'.str_repeat('x', 28),
            'ended_at' => null,
        ]);

        $callContext = Mockery::mock(CallContext::class);
        $callContext->shouldReceive('update')
            ->once()
            ->with(['status' => 'completed'])
            ->andReturn(null);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('calls')
            ->once()
            ->with('CAreal'.str_repeat('x', 28))
            ->andReturn($callContext);

        $this->twilioMock->shouldReceive('getClient')->andReturn($client);

        $response = $this->postJson("/calls/{$callLog->id}/hangup");

        $response->assertSuccessful();
    });

    it('does not call twilio api when sid is pending', function () {
        Event::fake([CallEnded::class]);
        $this->actingAs($this->internalUser);

        $callLog = CallLog::factory()->outbound()->create([
            'status' => 'ringing',
            'twilio_call_sid' => 'pending-abc123',
            'ended_at' => null,
        ]);

        // Should NOT receive getClient since we skip pending SIDs
        $this->twilioMock->shouldNotReceive('getClient');

        $response = $this->postJson("/calls/{$callLog->id}/hangup");

        $response->assertSuccessful()
            ->assertJson(['status' => 'completed']);
    });

    it('broadcasts CallEnded event', function () {
        Event::fake([CallEnded::class]);
        $this->actingAs($this->internalUser);

        $callLog = CallLog::factory()->outbound()->create([
            'status' => 'in-progress',
            'caller_user_id' => $this->internalUser->id,
            'twilio_call_sid' => 'pending-test',
            'ended_at' => null,
        ]);

        $this->postJson("/calls/{$callLog->id}/hangup");

        Event::assertDispatched(CallEnded::class, function ($event) use ($callLog) {
            return $event->callLog->id === $callLog->id
                && $event->userId === $this->internalUser->id;
        });
    });

    it('handles twilio api error gracefully and still completes', function () {
        Event::fake([CallEnded::class]);
        $this->actingAs($this->internalUser);

        $callLog = CallLog::factory()->outbound()->create([
            'status' => 'in-progress',
            'twilio_call_sid' => 'CAfailed'.str_repeat('y', 26),
            'ended_at' => null,
        ]);

        $callContext = Mockery::mock(CallContext::class);
        $callContext->shouldReceive('update')
            ->once()
            ->andThrow(new \Exception('Call already ended'));

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('calls')
            ->once()
            ->andReturn($callContext);

        $this->twilioMock->shouldReceive('getClient')->andReturn($client);

        $response = $this->postJson("/calls/{$callLog->id}/hangup");

        $response->assertSuccessful()
            ->assertJson(['status' => 'completed']);

        $callLog->refresh();
        expect($callLog->status)->toBe('completed')
            ->and($callLog->ended_at)->not->toBeNull();
    });
});

// ===================================================================
// TwilioVoiceService::initiateOutboundCall() unit tests
// ===================================================================

describe('TwilioVoiceService::initiateOutboundCall', function () {
    it('throws when user has no forwarding number', function () {
        $user = User::factory()->internal()->create(['forwarding_number' => null]);

        $service = app(TwilioVoiceService::class);

        expect(fn () => $service->initiateOutboundCall($user, '+13035552222'))
            ->toThrow(\InvalidArgumentException::class, 'forwarding number');
    });

    it('throws when phone number format is invalid', function () {
        $user = User::factory()->internal()->create(['forwarding_number' => '+13035551111']);

        $this->twilioMock->shouldReceive('formatE164')->with('bad')->andReturn(null);

        $service = app(TwilioVoiceService::class);

        expect(fn () => $service->initiateOutboundCall($user, 'bad'))
            ->toThrow(\InvalidArgumentException::class, 'Invalid phone number');
    });

    it('creates outbound call log with correct data', function () {
        $user = User::factory()->internal()->create(['forwarding_number' => '+13035551111']);

        $callInstance = Mockery::mock(CallInstance::class);
        $callInstance->sid = 'CAnew'.str_repeat('c', 29);

        $callList = Mockery::mock(CallList::class);
        $callList->shouldReceive('create')->once()->andReturn($callInstance);

        $client = Mockery::mock(Client::class);
        $client->calls = $callList;

        $this->twilioMock->shouldReceive('getClient')->andReturn($client);

        $service = app(TwilioVoiceService::class);
        $callLog = $service->initiateOutboundCall($user, '+13035552222');

        expect($callLog->direction)->toBe('outbound')
            ->and($callLog->from_number)->toBe('+15551234567')
            ->and($callLog->to_number)->toBe('+13035552222')
            ->and($callLog->caller_user_id)->toBe($user->id)
            ->and($callLog->status)->toBe('ringing')
            ->and($callLog->started_at)->not->toBeNull()
            ->and($callLog->twilio_call_sid)->toBe('CAnew'.str_repeat('c', 29));
    });

    it('passes user forwarding number as the call target', function () {
        $user = User::factory()->internal()->create(['forwarding_number' => '+13035551111']);

        $callInstance = Mockery::mock(CallInstance::class);
        $callInstance->sid = 'CAfwd'.str_repeat('d', 29);

        $callList = Mockery::mock(CallList::class);
        $callList->shouldReceive('create')
            ->once()
            ->withArgs(function ($to, $from) {
                return $to === '+13035551111' && $from === '+15551234567';
            })
            ->andReturn($callInstance);

        $client = Mockery::mock(Client::class);
        $client->calls = $callList;

        $this->twilioMock->shouldReceive('getClient')->andReturn($client);

        $service = app(TwilioVoiceService::class);
        $service->initiateOutboundCall($user, '+13035552222');
    });
});
