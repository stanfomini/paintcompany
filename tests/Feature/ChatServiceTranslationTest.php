<?php

use App\Events\MessageTranslated;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\User;
use App\Services\ChatService;
use Illuminate\Support\Facades\Event;
use Stichoza\GoogleTranslate\GoogleTranslate;

beforeEach(function () {
    Event::fake([MessageTranslated::class]);

    // Mock GoogleTranslate for all tests
    $this->mock(GoogleTranslate::class, function ($mock) {
        $mock->shouldReceive('setSource')->andReturnSelf();
        $mock->shouldReceive('setTarget')->andReturnSelf();
        $mock->shouldReceive('translate')->andReturn('Translated text');
        $mock->shouldReceive('getLastDetectedSource')->andReturn('es');
    });

    $this->internalUser = User::factory()->internal()->withFullAccess()->create();
    $this->otherUser = User::factory()->internal()->withFullAccess()->create();

    $this->thread = ChatThread::factory()
        ->betweenUsers($this->internalUser, $this->otherUser)
        ->create();

    $this->chatService = app(ChatService::class);
});

it('translates a text message inline when sending', function () {
    $message = $this->chatService->sendMessage(
        $this->thread,
        $this->internalUser,
        'Necesito más pintura para la cocina'
    );

    $message->refresh();

    expect($message->metadata['detected_lang'])->toBe('es');
    expect($message->metadata['translations']['en'])->toBe('Translated text');

    Event::assertDispatched(MessageTranslated::class);
});

it('translates an audio message inline when sending', function () {
    $message = $this->chatService->sendAudioMessage(
        $this->thread,
        $this->internalUser,
        [
            'audio_url' => 'https://example.com/audio.webm',
            'audio_path' => 'chat-audio/test.webm',
            'audio_duration' => 5.0,
            'transcription' => 'Necesito más pintura',
            'transcription_status' => 'completed',
        ]
    );

    $message->refresh();

    expect($message->metadata['detected_lang'])->toBe('es');
    expect($message->metadata['translations']['en'])->toBe('Translated text');
});

it('does not translate system messages', function () {
    $message = $this->chatService->sendSystemMessage(
        $this->thread,
        'User joined the conversation'
    );

    $message->refresh();

    expect($message->metadata)->toBeNull();

    Event::assertNotDispatched(MessageTranslated::class);
});

it('clears old translation and re-translates when editing a message', function () {
    $message = ChatMessage::factory()
        ->forThread($this->thread)
        ->fromUser($this->internalUser)
        ->spanishWithTranslation()
        ->create();

    $this->chatService->editMessage($message, $this->internalUser, 'Updated content here please');

    $message->refresh();

    // Should have fresh translation from inline call
    expect($message->metadata['detected_lang'])->toBe('es');
    expect($message->metadata['translations']['en'])->toBe('Translated text');
});

it('skips translation for short messages', function () {
    $message = $this->chatService->sendMessage(
        $this->thread,
        $this->internalUser,
        'Hi'
    );

    $message->refresh();

    expect($message->metadata)->toBeNull();

    Event::assertNotDispatched(MessageTranslated::class);
});
