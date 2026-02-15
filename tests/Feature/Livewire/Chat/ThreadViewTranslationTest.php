<?php

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->internalUser = User::factory()->internal()->withFullAccess()->create();
    $this->otherUser = User::factory()->internal()->withFullAccess()->create();

    $this->thread = ChatThread::factory()
        ->betweenUsers($this->internalUser, $this->otherUser)
        ->create();
});

it('shows "See translation" for messages with different detected language', function () {
    ChatMessage::factory()
        ->forThread($this->thread)
        ->fromUser($this->otherUser)
        ->spanishWithTranslation()
        ->create();

    // English viewer (default locale) should see toggle for Spanish message
    app()->setLocale('en');

    Livewire::actingAs($this->internalUser)
        ->test('chat.thread-view', ['thread' => $this->thread])
        ->assertSee('See translation');
});

it('does not show "See translation" for same-language messages', function () {
    ChatMessage::factory()
        ->forThread($this->thread)
        ->fromUser($this->otherUser)
        ->englishWithTranslation()
        ->create();

    // English viewer should NOT see toggle for English message
    app()->setLocale('en');

    Livewire::actingAs($this->internalUser)
        ->test('chat.thread-view', ['thread' => $this->thread])
        ->assertDontSee('See translation');
});

it('shows "Translate" button for messages without detected language', function () {
    ChatMessage::factory()
        ->forThread($this->thread)
        ->fromUser($this->otherUser)
        ->withContent('A message that has not been translated yet')
        ->create();

    Livewire::actingAs($this->internalUser)
        ->test('chat.thread-view', ['thread' => $this->thread])
        ->assertSee('Translate');
});

it('can trigger on-demand translate for old messages', function () {
    $message = ChatMessage::factory()
        ->forThread($this->thread)
        ->fromUser($this->otherUser)
        ->withContent('Necesito más pintura para terminar la cocina completa')
        ->create();

    Livewire::actingAs($this->internalUser)
        ->test('chat.thread-view', ['thread' => $this->thread])
        ->call('translateMessage', $message->id)
        ->assertSuccessful();

    // Should have completed translation inline (GoogleTranslate called directly)
    $message->refresh();
    expect($message->metadata['detected_lang'])->not->toBeNull();
    expect($message->metadata)->toHaveKey('translations');
});

it('shows "Translate" action for messages without detected_lang', function () {
    ChatMessage::factory()
        ->forThread($this->thread)
        ->fromUser($this->otherUser)
        ->withContent('An older message without translation')
        ->create();

    Livewire::actingAs($this->internalUser)
        ->test('chat.thread-view', ['thread' => $this->thread])
        ->assertSee('Translate');
});

it('does not show "Translate" action for already-translated messages', function () {
    ChatMessage::factory()
        ->forThread($this->thread)
        ->fromUser($this->otherUser)
        ->spanishWithTranslation()
        ->create();

    app()->setLocale('en');

    $component = Livewire::actingAs($this->internalUser)
        ->test('chat.thread-view', ['thread' => $this->thread]);

    // Should show "See translation" but not "Translate" in the menu
    $component->assertSee('See translation');
});

it('prevents translating messages from other threads', function () {
    $otherThread = ChatThread::factory()
        ->betweenUsers($this->internalUser, $this->otherUser)
        ->create();

    $message = ChatMessage::factory()
        ->forThread($otherThread)
        ->fromUser($this->otherUser)
        ->withContent('Message in a different thread')
        ->create();

    Livewire::actingAs($this->internalUser)
        ->test('chat.thread-view', ['thread' => $this->thread])
        ->call('translateMessage', $message->id)
        ->assertForbidden();
});

it('shows translation for audio message transcriptions', function () {
    ChatMessage::factory()
        ->forThread($this->thread)
        ->fromUser($this->otherUser)
        ->state(fn () => [
            'type' => ChatMessage::TYPE_AUDIO,
            'content' => 'Necesito más pintura',
            'metadata' => [
                'audio_url' => 'https://example.com/audio.webm',
                'audio_path' => 'chat-audio/test.webm',
                'audio_duration' => 5.0,
                'transcription' => 'Necesito más pintura',
                'transcription_status' => 'completed',
                'detected_lang' => 'es',
                'translations' => [
                    'en' => 'I need more paint',
                ],
            ],
        ])
        ->create();

    app()->setLocale('en');

    Livewire::actingAs($this->internalUser)
        ->test('chat.thread-view', ['thread' => $this->thread])
        ->assertSee('See translation');
});
