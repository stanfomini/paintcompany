<?php

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\User;
use Livewire\Livewire;

describe('FloatingChat component', function () {
    it('renders for internal users with chat access', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('chat.floating-chat')
            ->assertStatus(200);
    });

    it('prevents access for unauthenticated users', function () {
        Livewire::test('chat.floating-chat')
            ->assertForbidden();
    });

    it('shows unread count badge when there are unread messages', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();

        $thread = ChatThread::factory()
            ->withSubject('Test Thread')
            ->betweenUsers($user, $otherUser)
            ->create();

        ChatMessage::factory()
            ->forThread($thread)
            ->fromUser($otherUser)
            ->create();

        Livewire::actingAs($user)
            ->test('chat.floating-chat')
            ->assertSee('1');
    });

    it('starts with panel closed', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('chat.floating-chat')
            ->assertSet('open', false)
            ->assertDontSee('View all messages');
    });

    it('toggles panel open and closed', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('chat.floating-chat')
            ->assertSet('open', false)
            ->call('toggleOpen')
            ->assertSet('open', true)
            ->assertSee('View all messages')
            ->call('toggleOpen')
            ->assertSet('open', false)
            ->assertDontSee('View all messages');
    });

    it('closes panel via close method', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('chat.floating-chat')
            ->call('toggleOpen')
            ->assertSet('open', true)
            ->call('close')
            ->assertSet('open', false);
    });

    it('shows threads when panel is open', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();

        $thread = ChatThread::factory()
            ->withSubject('Project Discussion')
            ->betweenUsers($user, $otherUser)
            ->create(['last_message_at' => now()]);

        ChatMessage::factory()
            ->forThread($thread)
            ->fromUser($otherUser)
            ->create(['content' => 'Hello there, how are you doing today?']);

        Livewire::actingAs($user)
            ->test('chat.floating-chat')
            ->call('toggleOpen')
            ->assertSee('Project Discussion')
            ->assertSeeHtml(e(\Illuminate\Support\Str::limit('Hello there, how are you doing today?', 40)));
    });

    it('limits threads to 5', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();

        // Create 7 threads
        for ($i = 1; $i <= 7; $i++) {
            ChatThread::factory()
                ->withSubject("Thread {$i}")
                ->betweenUsers($user, $otherUser)
                ->create(['last_message_at' => now()->subMinutes(7 - $i)]);
        }

        $component = Livewire::actingAs($user)
            ->test('chat.floating-chat');

        expect($component->get('threads'))->toHaveCount(5);
    });

    it('prioritizes threads with unread messages', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();

        // Create a thread with no unread messages (older)
        $readThread = ChatThread::factory()
            ->withSubject('Read Thread')
            ->betweenUsers($user, $otherUser)
            ->create(['last_message_at' => now()->subHour()]);

        // Create a thread with unread messages (newer)
        $unreadThread = ChatThread::factory()
            ->withSubject('Unread Thread')
            ->betweenUsers($user, $otherUser)
            ->create(['last_message_at' => now()]);

        ChatMessage::factory()
            ->forThread($unreadThread)
            ->fromUser($otherUser)
            ->create();

        $component = Livewire::actingAs($user)
            ->test('chat.floating-chat');

        $threads = $component->get('threads');

        // Unread thread should appear first
        expect($threads->first()->subject)->toBe('Unread Thread');
    });

    it('does not show threads from other users', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser1 = User::factory()->internal()->withFullAccess()->create();
        $otherUser2 = User::factory()->internal()->withFullAccess()->create();

        ChatThread::factory()
            ->withSubject('Other Conversation')
            ->betweenUsers($otherUser1, $otherUser2)
            ->create();

        Livewire::actingAs($user)
            ->test('chat.floating-chat')
            ->call('toggleOpen')
            ->assertDontSee('Other Conversation');
    });

    it('shows empty state when no conversations exist', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('chat.floating-chat')
            ->call('toggleOpen')
            ->assertSee('No conversations yet');
    });

    it('refreshes on message-sent event', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('chat.floating-chat')
            ->dispatch('message-sent')
            ->assertStatus(200);
    });
});
