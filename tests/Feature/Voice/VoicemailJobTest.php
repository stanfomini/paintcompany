<?php

use App\Jobs\ProcessCallRecordingJob;
use App\Jobs\TranscribeVoicemailJob;
use App\Models\CallLog;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\Customer;
use App\Models\User;
use App\Services\PythonBridgeService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

describe('TranscribeVoicemailJob', function () {
    it('calls python bridge to transcribe and saves result', function () {
        $callLog = CallLog::factory()->create([
            'recording_url' => 'https://api.twilio.com/recordings/REtest123',
            'voicemail_transcription' => null,
        ]);

        $this->mock(PythonBridgeService::class, function ($mock) {
            $mock->shouldReceive('transcribeAudio')
                ->with('https://api.twilio.com/recordings/REtest123')
                ->once()
                ->andReturn([
                    'success' => true,
                    'text' => 'Hello, please call me back about the painting estimate.',
                ]);
        });

        (new TranscribeVoicemailJob($callLog->id))->handle(app(PythonBridgeService::class));

        $callLog->refresh();
        expect($callLog->voicemail_transcription)
            ->toBe('Hello, please call me back about the painting estimate.');
    });

    it('handles missing call log gracefully', function () {
        $mock = $this->mock(PythonBridgeService::class);

        // Should not throw, just return early
        (new TranscribeVoicemailJob(99999))->handle($mock);

        $mock->shouldNotHaveReceived('transcribeAudio');
    });

    it('skips transcription when no recording url', function () {
        $callLog = CallLog::factory()->create([
            'recording_url' => null,
            'voicemail_transcription' => null,
        ]);

        $mock = $this->mock(PythonBridgeService::class);

        (new TranscribeVoicemailJob($callLog->id))->handle($mock);

        $mock->shouldNotHaveReceived('transcribeAudio');
    });

    it('throws exception on transcription failure for retry', function () {
        $callLog = CallLog::factory()->create([
            'recording_url' => 'https://api.twilio.com/recordings/REfail',
            'voicemail_transcription' => null,
        ]);

        $this->mock(PythonBridgeService::class, function ($mock) {
            $mock->shouldReceive('transcribeAudio')
                ->andReturn([
                    'success' => false,
                    'error' => 'Service unavailable',
                ]);
        });

        expect(fn () => (new TranscribeVoicemailJob($callLog->id))->handle(app(PythonBridgeService::class)))
            ->toThrow(RuntimeException::class, 'Service unavailable');
    });

    it('creates chat message for customer with existing thread', function () {
        $internalUser = User::factory()->internal()->create();
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

        $thread = ChatThread::create([
            'type' => ChatThread::TYPE_SUPPORT,
            'customer_id' => $customer->id,
            'last_message_at' => now(),
        ]);
        $thread->addParticipant($internalUser);

        $callLog = CallLog::factory()->create([
            'recording_url' => 'https://api.twilio.com/recordings/REchat',
            'voicemail_transcription' => null,
            'customer_id' => $customer->id,
            'from_number' => '+13035551234',
        ]);

        $this->mock(PythonBridgeService::class, function ($mock) {
            $mock->shouldReceive('transcribeAudio')
                ->andReturn([
                    'success' => true,
                    'text' => 'Please call me back.',
                ]);
        });

        (new TranscribeVoicemailJob($callLog->id))->handle(app(PythonBridgeService::class));

        // Verify chat message was created in thread
        $message = ChatMessage::where('thread_id', $thread->id)
            ->where('content', 'LIKE', '%Voicemail from +13035551234%')
            ->first();

        expect($message)->not->toBeNull()
            ->and($message->content)->toContain('Please call me back.')
            ->and($message->metadata['type'])->toBe('voicemail')
            ->and($message->metadata['call_log_id'])->toBe($callLog->id);
    });

    it('does not create chat message when customer has no thread', function () {
        $internalUser = User::factory()->internal()->create();
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

        $callLog = CallLog::factory()->create([
            'recording_url' => 'https://api.twilio.com/recordings/REnothread',
            'voicemail_transcription' => null,
            'customer_id' => $customer->id,
        ]);

        $this->mock(PythonBridgeService::class, function ($mock) {
            $mock->shouldReceive('transcribeAudio')
                ->andReturn([
                    'success' => true,
                    'text' => 'Some message.',
                ]);
        });

        (new TranscribeVoicemailJob($callLog->id))->handle(app(PythonBridgeService::class));

        // Transcription should still be saved
        $callLog->refresh();
        expect($callLog->voicemail_transcription)->toBe('Some message.');

        // No chat message since no thread exists
        expect(ChatMessage::count())->toBe(0);
    });
});

describe('ProcessCallRecordingJob', function () {
    it('downloads recording and stores locally', function () {
        Storage::fake('local');

        $callLog = CallLog::factory()->create([
            'recording_url' => 'https://api.twilio.com/recordings/REdl123',
        ]);

        Http::fake([
            'api.twilio.com/recordings/REdl123.mp3' => Http::response('fake-mp3-content', 200),
        ]);

        (new ProcessCallRecordingJob($callLog->id))->handle();

        $callLog->refresh();
        $expectedPath = 'call-recordings/'.now()->format('Y/m').'/'.$callLog->ulid.'.mp3';

        Storage::assertExists($expectedPath);
        expect($callLog->metadata['local_recording_path'])->toBe($expectedPath);
    });

    it('handles missing call log gracefully', function () {
        Http::fake();

        // Should not throw
        (new ProcessCallRecordingJob(99999))->handle();

        Http::assertNothingSent();
    });

    it('skips when no recording url', function () {
        $callLog = CallLog::factory()->create([
            'recording_url' => null,
        ]);

        Http::fake();

        (new ProcessCallRecordingJob($callLog->id))->handle();

        Http::assertNothingSent();
    });

    it('throws exception on download failure for retry', function () {
        $callLog = CallLog::factory()->create([
            'recording_url' => 'https://api.twilio.com/recordings/REfail',
        ]);

        Http::fake([
            'api.twilio.com/recordings/REfail.mp3' => Http::response('Not Found', 404),
        ]);

        expect(fn () => (new ProcessCallRecordingJob($callLog->id))->handle())
            ->toThrow(RuntimeException::class);
    });

    it('preserves existing metadata when adding local path', function () {
        Storage::fake('local');

        $callLog = CallLog::factory()->create([
            'recording_url' => 'https://api.twilio.com/recordings/REmeta',
            'metadata' => ['source' => 'twilio', 'existing_key' => 'existing_value'],
        ]);

        Http::fake([
            'api.twilio.com/recordings/REmeta.mp3' => Http::response('mp3data', 200),
        ]);

        (new ProcessCallRecordingJob($callLog->id))->handle();

        $callLog->refresh();
        expect($callLog->metadata['source'])->toBe('twilio')
            ->and($callLog->metadata['existing_key'])->toBe('existing_value')
            ->and($callLog->metadata['local_recording_path'])->not->toBeNull();
    });
});
