<?php

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\User;
use Livewire\Livewire;

describe('ThreadView component', function () {
    it('renders the thread view component', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();

        $thread = ChatThread::factory()
            ->withSubject('Test Conversation')
            ->betweenUsers($user, $otherUser)
            ->create();

        Livewire::actingAs($user)
            ->test('chat.thread-view', ['thread' => $thread])
            ->assertStatus(200)
            ->assertSee('Test Conversation');
    });

    it('prevents access for non-participants', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser1 = User::factory()->internal()->withFullAccess()->create();
        $otherUser2 = User::factory()->internal()->withFullAccess()->create();

        $thread = ChatThread::factory()
            ->betweenUsers($otherUser1, $otherUser2)
            ->create();

        Livewire::actingAs($user)
            ->test('chat.thread-view', ['thread' => $thread])
            ->assertForbidden();
    });

    it('displays messages in the thread', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();

        $thread = ChatThread::factory()
            ->betweenUsers($user, $otherUser)
            ->create();

        ChatMessage::factory()
            ->forThread($thread)
            ->fromUser($otherUser)
            ->withContent('Hello, this is a test message!')
            ->create();

        Livewire::actingAs($user)
            ->test('chat.thread-view', ['thread' => $thread])
            ->assertSee('Hello, this is a test message!');
    });

    it('shows internal messages only to internal users', function () {
        $internalUser = User::factory()->internal()->withFullAccess()->create();
        $customerUser = User::factory()->customer()->create();

        $thread = ChatThread::factory()
            ->betweenUsers($internalUser, $customerUser)
            ->create();

        ChatMessage::factory()
            ->forThread($thread)
            ->fromUser($internalUser)
            ->internal()
            ->withContent('Internal staff note')
            ->create();

        ChatMessage::factory()
            ->forThread($thread)
            ->fromUser($internalUser)
            ->public()
            ->withContent('Public message')
            ->create();

        // Internal user sees both
        Livewire::actingAs($internalUser)
            ->test('chat.thread-view', ['thread' => $thread])
            ->assertSee('Internal staff note')
            ->assertSee('Public message');

        // Customer only sees public
        Livewire::actingAs($customerUser)
            ->test('chat.thread-view', ['thread' => $thread])
            ->assertDontSee('Internal staff note')
            ->assertSee('Public message');
    });

    it('can set a message to reply to', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();

        $thread = ChatThread::factory()
            ->betweenUsers($user, $otherUser)
            ->create();

        $message = ChatMessage::factory()
            ->forThread($thread)
            ->fromUser($otherUser)
            ->create();

        Livewire::actingAs($user)
            ->test('chat.thread-view', ['thread' => $thread])
            ->call('setReplyTo', $message->id)
            ->assertSet('replyingToMessageId', $message->id)
            ->assertDispatched('set-reply-to');
    });

    it('can cancel reply', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();

        $thread = ChatThread::factory()
            ->betweenUsers($user, $otherUser)
            ->create();

        $message = ChatMessage::factory()
            ->forThread($thread)
            ->fromUser($otherUser)
            ->create();

        Livewire::actingAs($user)
            ->test('chat.thread-view', ['thread' => $thread])
            ->call('setReplyTo', $message->id)
            ->call('cancelReply')
            ->assertSet('replyingToMessageId', null)
            ->assertDispatched('cancel-reply');
    });

    it('can delete own messages', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();

        $thread = ChatThread::factory()
            ->betweenUsers($user, $otherUser)
            ->create();

        $message = ChatMessage::factory()
            ->forThread($thread)
            ->fromUser($user)
            ->create();

        Livewire::actingAs($user)
            ->test('chat.thread-view', ['thread' => $thread])
            ->call('deleteMessage', $message->id)
            ->assertDispatched('message-deleted');

        expect($message->fresh()->deleted_at)->not->toBeNull();
    });

    it('internal users can delete any message', function () {
        $internalUser = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();

        $thread = ChatThread::factory()
            ->betweenUsers($internalUser, $otherUser)
            ->create();

        $message = ChatMessage::factory()
            ->forThread($thread)
            ->fromUser($otherUser)
            ->create();

        Livewire::actingAs($internalUser)
            ->test('chat.thread-view', ['thread' => $thread])
            ->call('deleteMessage', $message->id)
            ->assertDispatched('message-deleted');

        expect($message->fresh()->deleted_at)->not->toBeNull();
    });

    it('non-internal users cannot delete others messages', function () {
        $customerUser = User::factory()->customer()->create();
        $internalUser = User::factory()->internal()->withFullAccess()->create();

        $thread = ChatThread::factory()
            ->betweenUsers($customerUser, $internalUser)
            ->create();

        $message = ChatMessage::factory()
            ->forThread($thread)
            ->fromUser($internalUser)
            ->create();

        Livewire::actingAs($customerUser)
            ->test('chat.thread-view', ['thread' => $thread])
            ->call('deleteMessage', $message->id)
            ->assertForbidden();
    });

    it('shows empty state when no messages exist', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();

        $thread = ChatThread::factory()
            ->betweenUsers($user, $otherUser)
            ->create();

        Livewire::actingAs($user)
            ->test('chat.thread-view', ['thread' => $thread])
            ->assertSee('No messages yet')
            ->assertSee('Start the conversation');
    });

    it('shows participant count', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();

        $thread = ChatThread::factory()
            ->betweenUsers($user, $otherUser)
            ->create();

        Livewire::actingAs($user)
            ->test('chat.thread-view', ['thread' => $thread])
            ->assertSee('2 participants');
    });

    it('shows reply reference for message replies', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();

        $thread = ChatThread::factory()
            ->betweenUsers($user, $otherUser)
            ->create();

        $originalMessage = ChatMessage::factory()
            ->forThread($thread)
            ->fromUser($otherUser)
            ->withContent('Original message content')
            ->create();

        $replyMessage = ChatMessage::factory()
            ->forThread($thread)
            ->fromUser($user)
            ->withContent('This is my reply')
            ->replyTo($originalMessage)
            ->create();

        Livewire::actingAs($user)
            ->test('chat.thread-view', ['thread' => $thread])
            ->assertSee('Original message content')
            ->assertSee('This is my reply');
    });

    it('refreshes messages on message-sent event', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();

        $thread = ChatThread::factory()
            ->betweenUsers($user, $otherUser)
            ->create();

        Livewire::actingAs($user)
            ->test('chat.thread-view', ['thread' => $thread])
            ->dispatch('message-sent')
            ->assertStatus(200);
    });
});
