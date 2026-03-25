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
    });

    $this->callInstanceMock = Mockery::mock(CallInstance::class);

    $this->internalUser = User::factory()->internal()->create([
        'forwarding_number' => '+13035551111',
        'extension' => '100',
    ]);
    $this->internalUser->givePermissionTo(['phone.make_calls', 'phone.transfer_calls']);

    $this->targetUser = User::factory()->internal()->create([
        'forwarding_number' => '+13035552222',
        'extension' => '101',
        'name' => 'Jane Target',
    ]);
});

afterEach(function () {
    CompanySetting::clearInstanceCache();
});

// ===================================================================
// TwilioVoiceService::transferCall() unit tests
// ===================================================================

describe('TwilioVoiceService::transferCall', function () {
    it('throws when target extension does not exist', function () {
        $service = app(TwilioVoiceService::class);

        expect(fn () => $service->transferCall('CAsid123', '999'))
            ->toThrow(\InvalidArgumentException::class, 'No internal user found with extension 999');
    });

    it('throws when target user has no forwarding number', function () {
        User::factory()->internal()->create([
            'extension' => '102',
            'forwarding_number' => null,
        ]);

        $callLog = CallLog::factory()->create([
            'twilio_call_sid' => 'CAtransfer'.str_repeat('a', 24),
            'status' => 'in-progress',
        ]);

        $service = app(TwilioVoiceService::class);

        expect(fn () => $service->transferCall($callLog->twilio_call_sid, '102'))
            ->toThrow(\InvalidArgumentException::class, 'does not have a forwarding number');
    });

    it('throws when call log not found for given SID', function () {
        $service = app(TwilioVoiceService::class);

        expect(fn () => $service->transferCall('CAnonexistent', '101'))
            ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    });

    it('does not find non-internal users by extension', function () {
        User::factory()->create([
            'type' => 'agent',
            'extension' => '200',
            'forwarding_number' => '+13035553333',
        ]);

        $service = app(TwilioVoiceService::class);

        expect(fn () => $service->transferCall('CAsid123', '200'))
            ->toThrow(\InvalidArgumentException::class, 'No internal user found');
    });

    it('updates twilio call with twiml containing target number and whisper url', function () {
        $callLog = CallLog::factory()->create([
            'twilio_call_sid' => 'CAtransfer'.str_repeat('b', 24),
            'status' => 'in-progress',
            'answered_by_user_id' => $this->internalUser->id,
        ]);

        $callContext = Mockery::mock(CallContext::class);
        $callContext->shouldReceive('update')
            ->once()
            ->withArgs(function ($params) use ($callLog) {
                return isset($params['twiml'])
                    && str_contains($params['twiml'], '+13035552222')
                    && str_contains($params['twiml'], '<Dial')
                    && str_contains($params['twiml'], 'callerId')
                    && str_contains($params['twiml'], 'whisper')
                    && str_contains($params['twiml'], $callLog->ulid);
            })
            ->andReturn($this->callInstanceMock);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('calls')
            ->once()
            ->with('CAtransfer'.str_repeat('b', 24))
            ->andReturn($callContext);

        $this->twilioMock->shouldReceive('getClient')->andReturn($client);

        $service = app(TwilioVoiceService::class);
        $result = $service->transferCall($callLog->twilio_call_sid, '101');

        expect($result)->toBeInstanceOf(CallLog::class);
    });

    it('updates call log with new answered_by_user_id', function () {
        $callLog = CallLog::factory()->create([
            'twilio_call_sid' => 'CAtransfer'.str_repeat('c', 24),
            'status' => 'in-progress',
            'answered_by_user_id' => $this->internalUser->id,
        ]);

        $callContext = Mockery::mock(CallContext::class);
        $callContext->shouldReceive('update')->once()->andReturn($this->callInstanceMock);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('calls')->andReturn($callContext);

        $this->twilioMock->shouldReceive('getClient')->andReturn($client);

        $service = app(TwilioVoiceService::class);
        $service->transferCall($callLog->twilio_call_sid, '101');

        $callLog->refresh();
        expect($callLog->answered_by_user_id)->toBe($this->targetUser->id);
    });

    it('stores transfer metadata in call log', function () {
        $callLog = CallLog::factory()->create([
            'twilio_call_sid' => 'CAtransfer'.str_repeat('d', 24),
            'status' => 'in-progress',
            'answered_by_user_id' => $this->internalUser->id,
            'metadata' => ['existing_key' => 'value'],
        ]);

        $callContext = Mockery::mock(CallContext::class);
        $callContext->shouldReceive('update')->once()->andReturn($this->callInstanceMock);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('calls')->andReturn($callContext);

        $this->twilioMock->shouldReceive('getClient')->andReturn($client);

        $service = app(TwilioVoiceService::class);
        $service->transferCall($callLog->twilio_call_sid, '101');

        $callLog->refresh();
        $metadata = $callLog->metadata;

        expect($metadata['transferred'])->toBeTrue()
            ->and($metadata['transferred_from_user_id'])->toBe($this->internalUser->id)
            ->and($metadata['transferred_to_user_id'])->toBe($this->targetUser->id)
            ->and($metadata['transferred_at'])->not->toBeNull()
            ->and($metadata['existing_key'])->toBe('value');
    });

    it('returns updated call log', function () {
        $callLog = CallLog::factory()->create([
            'twilio_call_sid' => 'CAtransfer'.str_repeat('e', 24),
            'status' => 'in-progress',
            'answered_by_user_id' => $this->internalUser->id,
        ]);

        $callContext = Mockery::mock(CallContext::class);
        $callContext->shouldReceive('update')->once()->andReturn($this->callInstanceMock);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('calls')->andReturn($callContext);

        $this->twilioMock->shouldReceive('getClient')->andReturn($client);

        $service = app(TwilioVoiceService::class);
        $result = $service->transferCall($callLog->twilio_call_sid, '101');

        expect($result->id)->toBe($callLog->id)
            ->and($result->answered_by_user_id)->toBe($this->targetUser->id);
    });
});

// ===================================================================
// CallController::transfer() endpoint tests
// ===================================================================

describe('transfer endpoint', function () {
    it('returns 422 when extension field is missing', function () {
        $this->actingAs($this->internalUser);

        $callLog = CallLog::factory()->create(['status' => 'in-progress']);

        $response = $this->postJson("/calls/{$callLog->id}/transfer", []);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors('extension');
    });

    it('returns 403 when user lacks phone.transfer_calls permission', function () {
        $user = User::factory()->internal()->create(['forwarding_number' => '+13035551111']);
        $user->givePermissionTo('phone.make_calls');
        // Notably NOT giving phone.transfer_calls
        $this->actingAs($user);

        $callLog = CallLog::factory()->create([
            'status' => 'in-progress',
            'twilio_call_sid' => 'CAnoperm'.str_repeat('a', 26),
        ]);

        $response = $this->postJson("/calls/{$callLog->id}/transfer", ['extension' => '101']);

        $response->assertStatus(403)
            ->assertJson(['error' => 'You do not have permission to transfer calls.']);
    });

    it('returns 422 when call is not in-progress', function () {
        $this->actingAs($this->internalUser);

        $callLog = CallLog::factory()->create([
            'status' => 'completed',
            'twilio_call_sid' => 'CAcompleted'.str_repeat('f', 23),
        ]);

        $response = $this->postJson("/calls/{$callLog->id}/transfer", ['extension' => '101']);

        $response->assertStatus(422)
            ->assertJson(['error' => 'Call is not currently active.']);
    });

    it('returns 422 when call has empty twilio_call_sid', function () {
        $this->actingAs($this->internalUser);

        $callLog = CallLog::factory()->create([
            'status' => 'in-progress',
            'twilio_call_sid' => '',
        ]);

        $response = $this->postJson("/calls/{$callLog->id}/transfer", ['extension' => '101']);

        $response->assertStatus(422)
            ->assertJson(['error' => 'Call is not currently active.']);
    });

    it('returns 422 when target extension is invalid', function () {
        $this->actingAs($this->internalUser);

        $callLog = CallLog::factory()->create([
            'status' => 'in-progress',
            'twilio_call_sid' => 'CAbadext'.str_repeat('g', 25),
            'answered_by_user_id' => $this->internalUser->id,
        ]);

        $response = $this->postJson("/calls/{$callLog->id}/transfer", ['extension' => '999']);

        $response->assertStatus(422)
            ->assertJson(['error' => 'No internal user found with extension 999.']);
    });

    it('returns 401 for unauthenticated user', function () {
        $callLog = CallLog::factory()->create();

        $response = $this->postJson("/calls/{$callLog->id}/transfer", ['extension' => '101']);

        $response->assertUnauthorized();
    });

    it('transfers call and returns success json', function () {
        Event::fake([CallEnded::class, CallConnected::class]);
        $this->actingAs($this->internalUser);

        $callLog = CallLog::factory()->create([
            'status' => 'in-progress',
            'twilio_call_sid' => 'CAsuccess'.str_repeat('h', 25),
            'answered_by_user_id' => $this->internalUser->id,
        ]);

        $callContext = Mockery::mock(CallContext::class);
        $callContext->shouldReceive('update')->once()->andReturn($this->callInstanceMock);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('calls')
            ->with('CAsuccess'.str_repeat('h', 25))
            ->andReturn($callContext);

        $this->twilioMock->shouldReceive('getClient')->andReturn($client);

        $response = $this->postJson("/calls/{$callLog->id}/transfer", ['extension' => '101']);

        $response->assertSuccessful()
            ->assertJson([
                'status' => 'transferring',
                'transferred_to' => 'Jane Target',
            ]);
    });

    it('broadcasts CallEnded to original user on transfer', function () {
        Event::fake([CallEnded::class, CallConnected::class]);
        $this->actingAs($this->internalUser);

        $callLog = CallLog::factory()->create([
            'status' => 'in-progress',
            'twilio_call_sid' => 'CAevt1'.str_repeat('i', 28),
            'answered_by_user_id' => $this->internalUser->id,
        ]);

        $callContext = Mockery::mock(CallContext::class);
        $callContext->shouldReceive('update')->once()->andReturn($this->callInstanceMock);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('calls')->andReturn($callContext);

        $this->twilioMock->shouldReceive('getClient')->andReturn($client);

        $this->postJson("/calls/{$callLog->id}/transfer", ['extension' => '101']);

        Event::assertDispatched(CallEnded::class, function ($event) use ($callLog) {
            return $event->callLog->id === $callLog->id
                && $event->userId === $this->internalUser->id;
        });
    });

    it('broadcasts CallConnected to target user on transfer', function () {
        Event::fake([CallEnded::class, CallConnected::class]);
        $this->actingAs($this->internalUser);

        $callLog = CallLog::factory()->create([
            'status' => 'in-progress',
            'twilio_call_sid' => 'CAevt2'.str_repeat('j', 28),
            'answered_by_user_id' => $this->internalUser->id,
        ]);

        $callContext = Mockery::mock(CallContext::class);
        $callContext->shouldReceive('update')->once()->andReturn($this->callInstanceMock);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('calls')->andReturn($callContext);

        $this->twilioMock->shouldReceive('getClient')->andReturn($client);

        $this->postJson("/calls/{$callLog->id}/transfer", ['extension' => '101']);

        Event::assertDispatched(CallConnected::class, function ($event) {
            return $event->answeredByUserId === $this->targetUser->id;
        });
    });

    it('updates call log answered_by after transfer', function () {
        Event::fake([CallEnded::class, CallConnected::class]);
        $this->actingAs($this->internalUser);

        $callLog = CallLog::factory()->create([
            'status' => 'in-progress',
            'twilio_call_sid' => 'CAdb'.str_repeat('k', 30),
            'answered_by_user_id' => $this->internalUser->id,
        ]);

        $callContext = Mockery::mock(CallContext::class);
        $callContext->shouldReceive('update')->once()->andReturn($this->callInstanceMock);

        $client = Mockery::mock(Client::class);
        $client->shouldReceive('calls')->andReturn($callContext);

        $this->twilioMock->shouldReceive('getClient')->andReturn($client);

        $this->postJson("/calls/{$callLog->id}/transfer", ['extension' => '101']);

        $callLog->refresh();
        expect($callLog->answered_by_user_id)->toBe($this->targetUser->id)
            ->and($callLog->metadata['transferred'])->toBeTrue();
    });
});
