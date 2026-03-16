<?php

use App\Events\CallConnected;
use App\Events\CallEnded;
use App\Events\IncomingCall;
use App\Events\VoicemailReceived;
use App\Jobs\ProcessCallRecordingJob;
use App\Jobs\TranscribeVoicemailJob;
use App\Models\CallLog;
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
            ->and($callLog->recording_url)->toBe($recordingUrl)
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
