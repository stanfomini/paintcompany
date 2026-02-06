<?php

use App\Events\MessageSent;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\User;
use App\Services\AudioMessageService;
use App\Services\ChatService;
use App\Services\PythonBridgeService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses()->group('chat', 'audio');

beforeEach(function () {
    Storage::fake('public');
});

describe('ChatMessage audio type', function () {
    it('has TYPE_AUDIO constant', function () {
        expect(ChatMessage::TYPE_AUDIO)->toBe('audio');
    });

    it('can check if message is audio type', function () {
        $message = ChatMessage::factory()->make(['type' => ChatMessage::TYPE_AUDIO]);

        expect($message->isAudio())->toBeTrue();
    });

    it('returns false for isAudio on text messages', function () {
        $message = ChatMessage::factory()->make(['type' => ChatMessage::TYPE_TEXT]);

        expect($message->isAudio())->toBeFalse();
    });
});

describe('AudioMessageService', function () {
    it('processes base64 audio and stores file', function () {
        $this->mock(PythonBridgeService::class)
            ->shouldReceive('isEnabled')
            ->andReturn(true)
            ->shouldReceive('transcribeAudioFile')
            ->andReturn([
                'success' => true,
                'text' => 'Hello, this is a test message',
                'duration' => 5.5,
            ]);

        $service = app(AudioMessageService::class);

        // Create fake base64 audio data
        $fakeAudio = base64_encode('fake audio data');

        $result = $service->processBase64Audio($fakeAudio, 'audio/webm');

        expect($result)->toHaveKeys(['audio_url', 'audio_path', 'audio_duration', 'transcription', 'transcription_status'])
            ->and($result['transcription'])->toBe('Hello, this is a test message')
            ->and($result['transcription_status'])->toBe('completed')
            ->and($result['audio_duration'])->toBe(5.5);

        // Verify file was stored
        Storage::disk('public')->assertExists($result['audio_path']);
    });

    it('handles transcription failure gracefully', function () {
        $this->mock(PythonBridgeService::class)
            ->shouldReceive('isEnabled')
            ->andReturn(true)
            ->shouldReceive('transcribeAudioFile')
            ->andReturn([
                'success' => false,
                'error' => 'Transcription failed',
            ]);

        $service = app(AudioMessageService::class);

        $fakeAudio = base64_encode('fake audio data');
        $result = $service->processBase64Audio($fakeAudio, 'audio/webm');

        expect($result['transcription_status'])->toBe('failed')
            ->and($result['transcription'])->toBeNull();

        // File should still be stored even if transcription fails
        Storage::disk('public')->assertExists($result['audio_path']);
    });

    it('handles disabled python service', function () {
        $this->mock(PythonBridgeService::class)
            ->shouldReceive('isEnabled')
            ->andReturn(false);

        $service = app(AudioMessageService::class);

        $fakeAudio = base64_encode('fake audio data');
        $result = $service->processBase64Audio($fakeAudio, 'audio/webm');

        expect($result['transcription_status'])->toBe('failed')
            ->and($result['transcription'])->toBeNull();
    });

    it('validates audio files', function () {
        $service = app(AudioMessageService::class);

        // Valid audio file
        $validAudio = UploadedFile::fake()->create('test.webm', 100, 'audio/webm');
        $result = $service->validateAudio($validAudio);
        expect($result['valid'])->toBeTrue();

        // Invalid mime type
        $invalidAudio = UploadedFile::fake()->create('test.txt', 100, 'text/plain');
        $result = $service->validateAudio($invalidAudio);
        expect($result['valid'])->toBeFalse()
            ->and($result['error'])->toContain('Unsupported audio format');
    });

    it('rejects files that are too large', function () {
        $service = app(AudioMessageService::class);

        // File larger than 25MB
        $largeAudio = UploadedFile::fake()->create('large.webm', 30 * 1024, 'audio/webm');
        $result = $service->validateAudio($largeAudio);

        expect($result['valid'])->toBeFalse()
            ->and($result['error'])->toContain('too large');
    });

    it('throws exception for empty base64 data', function () {
        $service = app(AudioMessageService::class);

        // Empty string after decoding should throw exception
        expect(fn () => $service->processBase64Audio('', 'audio/webm'))
            ->toThrow(\InvalidArgumentException::class);
    });

    it('determines correct file extension from mime type', function () {
        $this->mock(PythonBridgeService::class)
            ->shouldReceive('isEnabled')
            ->andReturn(false);

        $service = app(AudioMessageService::class);

        $fakeAudio = base64_encode('fake audio data');

        $result = $service->processBase64Audio($fakeAudio, 'audio/mp3');
        expect($result['audio_path'])->toContain('.mp3');

        $result = $service->processBase64Audio($fakeAudio, 'audio/wav');
        expect($result['audio_path'])->toContain('.wav');

        $result = $service->processBase64Audio($fakeAudio, 'audio/ogg');
        expect($result['audio_path'])->toContain('.ogg');
    });
});

describe('ChatService sendAudioMessage', function () {
    it('creates an audio message', function () {
        Event::fake([MessageSent::class]);

        $user = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($user);

        $service = app(ChatService::class);

        $audioData = [
            'audio_url' => 'http://localhost/storage/chat-audio/test.webm',
            'audio_path' => 'chat-audio/test.webm',
            'audio_duration' => 10.5,
            'transcription' => 'Hello, this is a test',
            'transcription_status' => 'completed',
        ];

        $message = $service->sendAudioMessage($thread, $user, $audioData);

        expect($message->type)->toBe(ChatMessage::TYPE_AUDIO)
            ->and($message->content)->toBe('Hello, this is a test')
            ->and($message->metadata['audio_url'])->toBe('http://localhost/storage/chat-audio/test.webm')
            ->and($message->metadata['audio_duration'])->toBe(10.5)
            ->and($message->metadata['transcription'])->toBe('Hello, this is a test')
            ->and($message->metadata['transcription_status'])->toBe('completed');

        Event::assertDispatched(MessageSent::class);
    });

    it('respects internal message flag', function () {
        Event::fake([MessageSent::class]);

        $internalUser = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($internalUser);

        $service = app(ChatService::class);

        $audioData = [
            'audio_url' => 'http://localhost/storage/chat-audio/test.webm',
            'audio_path' => 'chat-audio/test.webm',
            'audio_duration' => 5.0,
            'transcription' => 'Internal note',
            'transcription_status' => 'completed',
        ];

        $message = $service->sendAudioMessage($thread, $internalUser, $audioData, ['is_internal' => true]);

        expect($message->is_internal)->toBeTrue();
    });

    it('prevents non-internal users from sending internal messages', function () {
        Event::fake([MessageSent::class]);

        $customerUser = User::factory()->customer()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($customerUser);

        $service = app(ChatService::class);

        $audioData = [
            'audio_url' => 'http://localhost/storage/chat-audio/test.webm',
            'audio_path' => 'chat-audio/test.webm',
            'audio_duration' => 5.0,
            'transcription' => 'Should not be internal',
            'transcription_status' => 'completed',
        ];

        $message = $service->sendAudioMessage($thread, $customerUser, $audioData, ['is_internal' => true]);

        // Customer cannot send internal messages
        expect($message->is_internal)->toBeFalse();
    });

    it('adds user as participant if not already', function () {
        Event::fake([MessageSent::class]);

        $user = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()->create();
        // Not adding user as participant initially

        $service = app(ChatService::class);

        $audioData = [
            'audio_url' => 'http://localhost/storage/chat-audio/test.webm',
            'audio_path' => 'chat-audio/test.webm',
            'audio_duration' => 5.0,
            'transcription' => 'Test',
            'transcription_status' => 'completed',
        ];

        $service->sendAudioMessage($thread, $user, $audioData);

        expect($thread->hasParticipant($user))->toBeTrue();
    });
});

describe('MessageComposer audio integration', function () {
    it('can render with audio recording state', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($user);

        Livewire::actingAs($user)
            ->test('chat.message-composer', ['thread' => $thread])
            ->assertSet('isRecording', false)
            ->assertSet('isProcessingAudio', false);
    });

    it('processes recorded audio and sends message', function () {
        Event::fake([MessageSent::class]);

        $this->mock(PythonBridgeService::class)
            ->shouldReceive('isEnabled')
            ->andReturn(true)
            ->shouldReceive('transcribeAudioFile')
            ->andReturn([
                'success' => true,
                'text' => 'Hello world',
                'duration' => 3.0,
            ]);

        $user = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($user);

        $fakeAudio = base64_encode('fake audio content');

        Livewire::actingAs($user)
            ->test('chat.message-composer', ['thread' => $thread])
            ->call('processRecordedAudio', $fakeAudio)
            ->assertDispatched('message-sent');

        // Verify message was created
        $message = $thread->messages()->where('type', ChatMessage::TYPE_AUDIO)->first();
        expect($message)->not->toBeNull()
            ->and($message->metadata['transcription'])->toBe('Hello world')
            ->and($message->metadata['audio_duration'])->toEqual(3.0);
    });

    it('handles audio processing errors gracefully', function () {
        $this->mock(AudioMessageService::class)
            ->shouldReceive('processBase64Audio')
            ->andThrow(new \Exception('Processing failed'));

        $user = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($user);

        Livewire::actingAs($user)
            ->test('chat.message-composer', ['thread' => $thread])
            ->call('processRecordedAudio', base64_encode('fake'))
            ->assertHasErrors(['audio']);
    });
});

describe('PythonBridgeService transcribeAudioFile', function () {
    it('returns error when file does not exist', function () {
        config(['python.enabled' => true]);

        $service = new PythonBridgeService;
        $result = $service->transcribeAudioFile('/nonexistent/path/audio.webm');

        expect($result['success'])->toBeFalse()
            ->and($result['error'])->toBe('Audio file not found');
    });
});
