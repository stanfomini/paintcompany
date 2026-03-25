<?php

use App\Events\CallConnected;
use App\Events\CallEnded;
use App\Events\IncomingCall;
use App\Events\VoicemailReceived;
use App\Jobs\ProcessCallRecordingJob;
use App\Jobs\TranscribeVoicemailJob;
use App\Models\CallLog;
use App\Models\User;
use App\Services\CallRoutingService;
use App\Services\TwilioService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    // Mock TwilioService to validate signatures as valid by default
    $this->twilioMock = $this->partialMock(TwilioService::class, function ($mock) {
        $mock->shouldReceive('validateWebhookSignature')->andReturn(true)->byDefault();
        $mock->shouldReceive('formatE164')->andReturn(null)->byDefault();
    });
});

describe('incoming call webhook', function () {
    it('rejects requests with invalid twilio signature', function () {
        $this->twilioMock->shouldReceive('validateWebhookSignature')->andReturn(false);

        $response = $this->post('/webhooks/twilio/voice', [
            'From' => '+13035551234',
            'To' => '+13035559999',
            'CallSid' => 'CA'.fake()->sha1(),
        ]);

        $response->assertStatus(403);
    });

    it('creates a call log and returns twiml for incoming call', function () {
        Event::fake([IncomingCall::class]);
        $this->mock(CallRoutingService::class, function ($mock) {
            $mock->shouldReceive('findCallerIdentity')->andReturn(['user' => null, 'customer' => null]);
            $mock->shouldReceive('resolveCallGroup')->andReturn(null);
        });

        $callSid = 'CA'.fake()->sha1();

        $response = $this->post('/webhooks/twilio/voice', [
            'From' => '+13035551234',
            'To' => '+13035559999',
            'CallSid' => $callSid,
        ]);

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'text/xml; charset=UTF-8');

        $this->assertDatabaseHas('call_logs', [
            'twilio_call_sid' => $callSid,
            'direction' => 'inbound',
            'from_number' => '+13035551234',
            'to_number' => '+13035559999',
            'status' => 'ringing',
        ]);

        Event::assertDispatched(IncomingCall::class);
    });

    it('returns hangup twiml when CallSid is empty', function () {
        $this->mock(CallRoutingService::class);

        $response = $this->post('/webhooks/twilio/voice', [
            'From' => '+13035551234',
            'To' => '+13035559999',
            'CallSid' => '',
        ]);

        $response->assertStatus(200);
        expect($response->getContent())->toContain('Hangup');
    });

    it('routes call to voicemail when no call group exists', function () {
        Event::fake([IncomingCall::class]);
        $this->mock(CallRoutingService::class, function ($mock) {
            $mock->shouldReceive('findCallerIdentity')->andReturn(['user' => null, 'customer' => null]);
            $mock->shouldReceive('resolveCallGroup')->andReturn(null);
        });

        $response = $this->post('/webhooks/twilio/voice', [
            'From' => '+13035551234',
            'To' => '+13035559999',
            'CallSid' => 'CA'.fake()->sha1(),
        ]);

        $response->assertStatus(200);
        // When no call group, controller calls generateVoicemailTwiml
        expect($response->getContent())->toContain('Record');
    });
});

describe('call status webhook', function () {
    it('rejects requests with invalid signature', function () {
        $this->twilioMock->shouldReceive('validateWebhookSignature')->andReturn(false);

        $response = $this->post('/webhooks/twilio/voice/status', [
            'CallSid' => 'CAtest123',
            'CallStatus' => 'completed',
        ]);

        $response->assertStatus(403);
    });

    it('updates call log status and duration', function () {
        Event::fake([CallEnded::class]);
        $callLog = CallLog::factory()->create([
            'twilio_call_sid' => 'CAstatus123',
            'status' => 'ringing',
        ]);

        $response = $this->post('/webhooks/twilio/voice/status', [
            'CallSid' => 'CAstatus123',
            'CallStatus' => 'completed',
            'CallDuration' => '45',
        ]);

        $response->assertStatus(200);

        $callLog->refresh();
        expect($callLog->status)->toBe('completed')
            ->and($callLog->duration_seconds)->toBe(45)
            ->and($callLog->ended_at)->not->toBeNull();

        Event::assertDispatched(CallEnded::class);
    });

    it('returns 200 when call log not found', function () {
        $response = $this->post('/webhooks/twilio/voice/status', [
            'CallSid' => 'CAnonexistent',
            'CallStatus' => 'completed',
        ]);

        $response->assertStatus(200);
    });

    it('does not set ended_at for in-progress status', function () {
        Event::fake();
        $callLog = CallLog::factory()->create([
            'twilio_call_sid' => 'CAinprogress',
            'status' => 'ringing',
            'ended_at' => null,
        ]);

        $this->post('/webhooks/twilio/voice/status', [
            'CallSid' => 'CAinprogress',
            'CallStatus' => 'in-progress',
        ]);

        $callLog->refresh();
        expect($callLog->status)->toBe('in-progress')
            ->and($callLog->ended_at)->toBeNull();
    });
});

describe('recording webhook', function () {
    it('rejects requests with invalid signature', function () {
        $this->twilioMock->shouldReceive('validateWebhookSignature')->andReturn(false);

        $response = $this->post('/webhooks/twilio/voice/recording', [
            'CallSid' => 'CAtest',
            'RecordingSid' => 'REtest',
            'RecordingUrl' => 'https://api.twilio.com/recordings/REtest',
        ]);

        $response->assertStatus(403);
    });

    it('updates call log with recording info and dispatches jobs', function () {
        Queue::fake([TranscribeVoicemailJob::class, ProcessCallRecordingJob::class]);
        Event::fake([VoicemailReceived::class]);

        $callLog = CallLog::factory()->create([
            'twilio_call_sid' => 'CArec123',
            'status' => 'voicemail',
        ]);

        $recordingUrl = 'https://api.twilio.com/recordings/REabc123';

        $response = $this->post('/webhooks/twilio/voice/recording', [
            'CallSid' => 'CArec123',
            'RecordingSid' => 'REabc123',
            'RecordingUrl' => $recordingUrl,
            'RecordingDuration' => '30',
        ]);

        $response->assertStatus(200);

        $callLog->refresh();
        expect($callLog->recording_sid)->toBe('REabc123')
            ->and($callLog->recording_url)->toBe($recordingUrl.'.mp3')
            ->and($callLog->recording_duration_seconds)->toBe(30);

        // Voicemail status triggers transcription job
        Queue::assertPushed(TranscribeVoicemailJob::class, function ($job) use ($callLog) {
            return $job->callLogId === $callLog->id;
        });

        // Recording job is always dispatched
        Queue::assertPushed(ProcessCallRecordingJob::class, function ($job) use ($callLog) {
            return $job->callLogId === $callLog->id;
        });

        Event::assertDispatched(VoicemailReceived::class);
    });

    it('dispatches only recording job for non-voicemail calls', function () {
        Queue::fake([TranscribeVoicemailJob::class, ProcessCallRecordingJob::class]);
        Event::fake([VoicemailReceived::class]);

        $callLog = CallLog::factory()->create([
            'twilio_call_sid' => 'CArec456',
            'status' => 'completed',
        ]);

        $this->post('/webhooks/twilio/voice/recording', [
            'CallSid' => 'CArec456',
            'RecordingSid' => 'REdef456',
            'RecordingUrl' => 'https://api.twilio.com/recordings/REdef456',
        ]);

        Queue::assertNotPushed(TranscribeVoicemailJob::class);
        Queue::assertPushed(ProcessCallRecordingJob::class);
        Event::assertNotDispatched(VoicemailReceived::class);
    });

    it('returns 200 when call log not found', function () {
        Queue::fake();

        $response = $this->post('/webhooks/twilio/voice/recording', [
            'CallSid' => 'CAnotfound',
            'RecordingSid' => 'REnotfound',
            'RecordingUrl' => 'https://api.twilio.com/recordings/REnotfound',
        ]);

        $response->assertStatus(200);
        Queue::assertNothingPushed();
    });
});

describe('dial status webhook', function () {
    it('rejects requests with invalid signature', function () {
        $this->twilioMock->shouldReceive('validateWebhookSignature')->andReturn(false);

        $callLog = CallLog::factory()->create();

        $response = $this->post("/webhooks/twilio/voice/dial-status/{$callLog->ulid}", [
            'DialCallStatus' => 'completed',
        ]);

        $response->assertStatus(403);
    });

    it('updates call log when dial is answered', function () {
        Event::fake([CallConnected::class]);
        $callLog = CallLog::factory()->create([
            'status' => 'ringing',
            'answered_at' => null,
        ]);

        $response = $this->post("/webhooks/twilio/voice/dial-status/{$callLog->ulid}", [
            'DialCallStatus' => 'completed',
        ]);

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'text/xml; charset=UTF-8');

        $callLog->refresh();
        expect($callLog->status)->toBe('in-progress')
            ->and($callLog->answered_at)->not->toBeNull();
    });

    it('returns voicemail twiml on no-answer', function () {
        Event::fake();
        $callLog = CallLog::factory()->create([
            'status' => 'ringing',
        ]);

        $response = $this->post("/webhooks/twilio/voice/dial-status/{$callLog->ulid}", [
            'DialCallStatus' => 'no-answer',
        ]);

        $response->assertStatus(200);

        $callLog->refresh();
        expect($callLog->status)->toBe('voicemail');
        expect($response->getContent())->toContain('Record');
    });

    it('returns hangup twiml for unknown call log', function () {
        $response = $this->post('/webhooks/twilio/voice/dial-status/nonexistentulid', [
            'DialCallStatus' => 'no-answer',
        ]);

        $response->assertStatus(200);
        expect($response->getContent())->toContain('Hangup');
    });

    it('broadcasts CallConnected when inbound call is answered and user resolved via Twilio API', function () {
        Event::fake([CallConnected::class]);

        $user = User::factory()->internal()->create([
            'forwarding_number' => '+13035551111',
            'extension' => '100',
        ]);

        $callGroup = \App\Models\CallGroup::factory()->create();
        \App\Models\CallGroupMember::create([
            'call_group_id' => $callGroup->id,
            'user_id' => $user->id,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $callLog = CallLog::factory()->create([
            'status' => 'ringing',
            'answered_at' => null,
            'direction' => 'inbound',
            'call_group_id' => $callGroup->id,
        ]);

        // Mock Twilio client to return child call with the user's forwarding number
        $childCallInstance = Mockery::mock(\Twilio\Rest\Api\V2010\Account\CallInstance::class);
        $childCallInstance->to = '+13035551111';

        $childCallContext = Mockery::mock(\Twilio\Rest\Api\V2010\Account\CallContext::class);
        $childCallContext->shouldReceive('fetch')->once()->andReturn($childCallInstance);

        $client = Mockery::mock(\Twilio\Rest\Client::class);
        $client->shouldReceive('calls')
            ->once()
            ->with('CAchild123abc')
            ->andReturn($childCallContext);

        $this->twilioMock->shouldReceive('getClient')->andReturn($client);
        $this->twilioMock->shouldReceive('formatE164')
            ->with('+13035551111')
            ->andReturn('+13035551111');

        $response = $this->post("/webhooks/twilio/voice/dial-status/{$callLog->ulid}", [
            'DialCallStatus' => 'completed',
            'DialCallSid' => 'CAchild123abc',
        ]);

        $response->assertStatus(200);

        $callLog->refresh();
        expect($callLog->status)->toBe('in-progress')
            ->and($callLog->answered_by_user_id)->toBe($user->id)
            ->and($callLog->answered_at)->not->toBeNull();

        Event::assertDispatched(CallConnected::class, function ($event) use ($callLog, $user) {
            return $event->callLog->id === $callLog->id
                && $event->answeredByUserId === $user->id;
        });
    });

    it('falls back to single call group member when Twilio API fails', function () {
        Event::fake([CallConnected::class]);

        $user = User::factory()->internal()->create([
            'forwarding_number' => '+13035552222',
            'extension' => '101',
        ]);

        $callGroup = \App\Models\CallGroup::factory()->create();
        \App\Models\CallGroupMember::create([
            'call_group_id' => $callGroup->id,
            'user_id' => $user->id,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $callLog = CallLog::factory()->create([
            'status' => 'ringing',
            'answered_at' => null,
            'direction' => 'inbound',
            'call_group_id' => $callGroup->id,
        ]);

        // Mock Twilio client to throw exception (API failure)
        $client = Mockery::mock(\Twilio\Rest\Client::class);
        $client->shouldReceive('calls')
            ->once()
            ->andThrow(new \Exception('Twilio API error'));

        $this->twilioMock->shouldReceive('getClient')->andReturn($client);

        $response = $this->post("/webhooks/twilio/voice/dial-status/{$callLog->ulid}", [
            'DialCallStatus' => 'completed',
            'DialCallSid' => 'CAchildfail',
        ]);

        $response->assertStatus(200);

        $callLog->refresh();
        expect($callLog->answered_by_user_id)->toBe($user->id);

        Event::assertDispatched(CallConnected::class, function ($event) use ($user) {
            return $event->answeredByUserId === $user->id;
        });
    });

    it('tries call group fallback when Twilio API returns unrecognized number', function () {
        Event::fake([CallConnected::class]);

        $user = User::factory()->internal()->create([
            'forwarding_number' => '+13035553333',
            'extension' => '102',
        ]);

        $callGroup = \App\Models\CallGroup::factory()->create();
        \App\Models\CallGroupMember::create([
            'call_group_id' => $callGroup->id,
            'user_id' => $user->id,
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $callLog = CallLog::factory()->create([
            'status' => 'ringing',
            'answered_at' => null,
            'direction' => 'inbound',
            'call_group_id' => $callGroup->id,
        ]);

        // Mock Twilio client to return a number that doesn't match any user
        $childCallInstance = Mockery::mock(\Twilio\Rest\Api\V2010\Account\CallInstance::class);
        $childCallInstance->to = '+19995550000';

        $childCallContext = Mockery::mock(\Twilio\Rest\Api\V2010\Account\CallContext::class);
        $childCallContext->shouldReceive('fetch')->once()->andReturn($childCallInstance);

        $client = Mockery::mock(\Twilio\Rest\Client::class);
        $client->shouldReceive('calls')
            ->once()
            ->with('CAchildunknown')
            ->andReturn($childCallContext);

        $this->twilioMock->shouldReceive('getClient')->andReturn($client);
        $this->twilioMock->shouldReceive('formatE164')
            ->with('+19995550000')
            ->andReturn('+19995550000');

        $response = $this->post("/webhooks/twilio/voice/dial-status/{$callLog->ulid}", [
            'DialCallStatus' => 'completed',
            'DialCallSid' => 'CAchildunknown',
        ]);

        $response->assertStatus(200);

        $callLog->refresh();
        // Should fall back to single-member group
        expect($callLog->answered_by_user_id)->toBe($user->id);

        Event::assertDispatched(CallConnected::class);
    });

    it('does not broadcast CallConnected when user cannot be resolved in multi-member group', function () {
        Event::fake([CallConnected::class]);

        $user1 = User::factory()->internal()->create(['forwarding_number' => '+13035554444']);
        $user2 = User::factory()->internal()->create(['forwarding_number' => '+13035555555']);

        $callGroup = \App\Models\CallGroup::factory()->create();
        \App\Models\CallGroupMember::create([
            'call_group_id' => $callGroup->id,
            'user_id' => $user1->id,
            'is_active' => true,
            'sort_order' => 0,
        ]);
        \App\Models\CallGroupMember::create([
            'call_group_id' => $callGroup->id,
            'user_id' => $user2->id,
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $callLog = CallLog::factory()->create([
            'status' => 'ringing',
            'answered_at' => null,
            'direction' => 'inbound',
            'call_group_id' => $callGroup->id,
        ]);

        // Mock Twilio client to throw (simulating API failure)
        $client = Mockery::mock(\Twilio\Rest\Client::class);
        $client->shouldReceive('calls')
            ->once()
            ->andThrow(new \Exception('Twilio API error'));

        $this->twilioMock->shouldReceive('getClient')->andReturn($client);

        $response = $this->post("/webhooks/twilio/voice/dial-status/{$callLog->ulid}", [
            'DialCallStatus' => 'completed',
            'DialCallSid' => 'CAchildmulti',
        ]);

        $response->assertStatus(200);

        $callLog->refresh();
        expect($callLog->status)->toBe('in-progress')
            ->and($callLog->answered_by_user_id)->toBeNull();

        Event::assertNotDispatched(CallConnected::class);
    });

    it('resolves user via call group member forwarding number override', function () {
        Event::fake([CallConnected::class]);

        $user = User::factory()->internal()->create([
            'forwarding_number' => '+13035556666',
            'extension' => '103',
        ]);

        $callGroup = \App\Models\CallGroup::factory()->create();
        // Member has a different forwarding number override
        \App\Models\CallGroupMember::create([
            'call_group_id' => $callGroup->id,
            'user_id' => $user->id,
            'forwarding_number' => '+13035557777',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $callLog = CallLog::factory()->create([
            'status' => 'ringing',
            'answered_at' => null,
            'direction' => 'inbound',
            'call_group_id' => $callGroup->id,
        ]);

        // Twilio child call returns the override number, not the user's default
        $childCallInstance = Mockery::mock(\Twilio\Rest\Api\V2010\Account\CallInstance::class);
        $childCallInstance->to = '+13035557777';

        $childCallContext = Mockery::mock(\Twilio\Rest\Api\V2010\Account\CallContext::class);
        $childCallContext->shouldReceive('fetch')->once()->andReturn($childCallInstance);

        $client = Mockery::mock(\Twilio\Rest\Client::class);
        $client->shouldReceive('calls')
            ->once()
            ->with('CAchildoverride')
            ->andReturn($childCallContext);

        $this->twilioMock->shouldReceive('getClient')->andReturn($client);
        $this->twilioMock->shouldReceive('formatE164')
            ->with('+13035557777')
            ->andReturn('+13035557777');

        $response = $this->post("/webhooks/twilio/voice/dial-status/{$callLog->ulid}", [
            'DialCallStatus' => 'completed',
            'DialCallSid' => 'CAchildoverride',
        ]);

        $response->assertStatus(200);

        $callLog->refresh();
        expect($callLog->answered_by_user_id)->toBe($user->id);

        Event::assertDispatched(CallConnected::class, function ($event) use ($user) {
            return $event->answeredByUserId === $user->id;
        });
    });
});

describe('voicemail complete webhook', function () {
    it('rejects requests with invalid signature', function () {
        $this->twilioMock->shouldReceive('validateWebhookSignature')->andReturn(false);

        $callLog = CallLog::factory()->create();

        $response = $this->post("/webhooks/twilio/voice/voicemail/{$callLog->ulid}");

        $response->assertStatus(403);
    });

    it('updates call log status to voicemail and sets ended_at', function () {
        $callLog = CallLog::factory()->create([
            'status' => 'ringing',
            'ended_at' => null,
        ]);

        $response = $this->post("/webhooks/twilio/voice/voicemail/{$callLog->ulid}");

        $response->assertStatus(200);
        expect($response->getContent())->toContain('Hangup');

        $callLog->refresh();
        expect($callLog->status)->toBe('voicemail')
            ->and($callLog->ended_at)->not->toBeNull();
    });
});

describe('transcription complete webhook', function () {
    it('saves twilio transcription text to call log', function () {
        $callLog = CallLog::factory()->create([
            'voicemail_transcription' => null,
        ]);

        $response = $this->post("/webhooks/twilio/voice/transcription/{$callLog->ulid}", [
            'TranscriptionText' => 'Hi, this is a test voicemail message.',
        ]);

        $response->assertStatus(200);

        $callLog->refresh();
        expect($callLog->voicemail_transcription)->toBe('Hi, this is a test voicemail message.');
    });

    it('does not overwrite with empty transcription', function () {
        $callLog = CallLog::factory()->create([
            'voicemail_transcription' => 'Existing transcription',
        ]);

        $this->post("/webhooks/twilio/voice/transcription/{$callLog->ulid}", [
            'TranscriptionText' => '',
        ]);

        $callLog->refresh();
        expect($callLog->voicemail_transcription)->toBe('Existing transcription');
    });
});

describe('voicemail fallback webhook', function () {
    it('sets status to voicemail and returns voicemail twiml', function () {
        $callLog = CallLog::factory()->create([
            'status' => 'ringing',
        ]);

        $response = $this->post("/webhooks/twilio/voice/voicemail-fallback/{$callLog->ulid}");

        $response->assertStatus(200);

        $callLog->refresh();
        expect($callLog->status)->toBe('voicemail');
        expect($response->getContent())->toContain('Record');
    });
});
