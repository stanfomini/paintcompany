<?php

use App\Livewire\Agent\Chat\MessageComposer;
use App\Livewire\Agent\Chat\ThreadList;
use App\Livewire\Agent\Chat\ThreadView;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Agent Chat Thread List', function () {
    it('redirects guests to agent login page', function () {
        $this->get(route('agent.chat.index'))
            ->assertRedirect(route('agent.login'));
    });

    it('renders thread list for authenticated agent', function () {
        $agent = User::factory()->agent()->create();

        $this->actingAs($agent, 'agent')
            ->get(route('agent.chat.index'))
            ->assertSuccessful()
            ->assertSeeLivewire(ThreadList::class);
    });

    it('shows threads where agent is a participant', function () {
        $agent = User::factory()->agent()->create();
        $internal = User::factory()->internal()->create();

        // Create a thread where agent is a participant
        $thread = ChatThread::factory()
            ->withSubject('Agent Thread')
            ->withParticipants([$agent, $internal])
            ->create();

        // Create a message in the thread
        ChatMessage::factory()
            ->forThread($thread)
            ->fromUser($internal)
            ->withContent('Hello Agent!')
            ->create();

        Livewire::actingAs($agent, 'agent')
            ->test(ThreadList::class)
            ->assertSee('Agent Thread');
    });

    it('does not show threads where agent is not a participant', function () {
        $agent = User::factory()->agent()->create();
        $otherAgent = User::factory()->agent()->create();
        $internal = User::factory()->internal()->create();

        // Create a thread where agent is NOT a participant
        $thread = ChatThread::factory()
            ->withSubject('Other Thread')
            ->withParticipants([$otherAgent, $internal])
            ->create();

        Livewire::actingAs($agent, 'agent')
            ->test(ThreadList::class)
            ->assertDontSee('Other Thread');
    });

    it('shows unread count for threads', function () {
        $agent = User::factory()->agent()->create();
        $internal = User::factory()->internal()->create();

        $thread = ChatThread::factory()
            ->withSubject('Unread Thread')
            ->withParticipants([$agent, $internal])
            ->create();

        // Create unread messages
        ChatMessage::factory()
            ->count(3)
            ->forThread($thread)
            ->fromUser($internal)
            ->create();

        $component = Livewire::actingAs($agent, 'agent')
            ->test(ThreadList::class);

        // Access computed property via the component instance
        $threads = $component->instance()->threads;
        expect($threads->first()->unread_count)->toBe(3);
    });

    it('filters threads by unread', function () {
        $agent = User::factory()->agent()->create();
        $internal = User::factory()->internal()->create();

        // Thread with unread messages
        $unreadThread = ChatThread::factory()
            ->withSubject('Unread Thread')
            ->withParticipants([$agent, $internal])
            ->create();

        ChatMessage::factory()
            ->forThread($unreadThread)
            ->fromUser($internal)
            ->create();

        // Thread with read messages
        $readThread = ChatThread::factory()
            ->withSubject('Read Thread')
            ->withParticipants([$agent, $internal])
            ->create();

        // Mark read thread as read
        $participant = $readThread->getParticipant($agent);
        $participant->markAsRead();

        Livewire::actingAs($agent, 'agent')
            ->test(ThreadList::class)
            ->set('filter', 'unread')
            ->assertSee('Unread Thread');
    });

    it('forbids internal users from accessing agent chat', function () {
        $internal = User::factory()->internal()->create();

        $this->actingAs($internal, 'agent')
            ->get(route('agent.chat.index'))
            ->assertForbidden();
    });

    it('forbids customer users from accessing agent chat', function () {
        $customer = User::factory()->customer()->create();

        $this->actingAs($customer, 'agent')
            ->get(route('agent.chat.index'))
            ->assertForbidden();
    });
});

describe('Agent Chat Thread View', function () {
    it('renders thread view for authorized agent', function () {
        $agent = User::factory()->agent()->create();
        $internal = User::factory()->internal()->create();

        $thread = ChatThread::factory()
            ->withSubject('Test Thread')
            ->withParticipants([$agent, $internal])
            ->create();

        $this->actingAs($agent, 'agent')
            ->get(route('agent.chat.show', $thread))
            ->assertSuccessful()
            ->assertSeeLivewire(ThreadView::class);
    });

    it('forbids agent from accessing threads they are not part of', function () {
        $agent = User::factory()->agent()->create();
        $otherAgent = User::factory()->agent()->create();
        $internal = User::factory()->internal()->create();

        // Thread where agent is NOT a participant
        $thread = ChatThread::factory()
            ->withSubject('Other Thread')
            ->withParticipants([$otherAgent, $internal])
            ->create();

        $this->actingAs($agent, 'agent')
            ->get(route('agent.chat.show', $thread))
            ->assertForbidden();
    });

    it('shows messages in the thread', function () {
        $agent = User::factory()->agent()->create();
        $internal = User::factory()->internal()->create();

        $thread = ChatThread::factory()
            ->withSubject('Test Thread')
            ->withParticipants([$agent, $internal])
            ->create();

        ChatMessage::factory()
            ->forThread($thread)
            ->fromUser($internal)
            ->withContent('Hello from internal!')
            ->create();

        ChatMessage::factory()
            ->forThread($thread)
            ->fromUser($agent)
            ->withContent('Hello from agent!')
            ->create();

        Livewire::actingAs($agent, 'agent')
            ->test(ThreadView::class, ['thread' => $thread])
            ->assertSee('Hello from internal!')
            ->assertSee('Hello from agent!');
    });

    it('does not show internal messages to agent', function () {
        $agent = User::factory()->agent()->create();
        $internal = User::factory()->internal()->create();

        $thread = ChatThread::factory()
            ->withSubject('Test Thread')
            ->withParticipants([$agent, $internal])
            ->create();

        // Public message
        ChatMessage::factory()
            ->forThread($thread)
            ->fromUser($internal)
            ->withContent('Public message')
            ->create();

        // Internal message (should not be visible to agent)
        ChatMessage::factory()
            ->forThread($thread)
            ->fromUser($internal)
            ->internal()
            ->withContent('Internal staff note')
            ->create();

        Livewire::actingAs($agent, 'agent')
            ->test(ThreadView::class, ['thread' => $thread])
            ->assertSee('Public message')
            ->assertDontSee('Internal staff note');
    });

    it('marks thread as read on view', function () {
        $agent = User::factory()->agent()->create();
        $internal = User::factory()->internal()->create();

        $thread = ChatThread::factory()
            ->withSubject('Test Thread')
            ->withParticipants([$agent, $internal])
            ->create();

        ChatMessage::factory()
            ->forThread($thread)
            ->fromUser($internal)
            ->create();

        // Before viewing
        expect($thread->getUnreadCountFor($agent))->toBe(1);

        Livewire::actingAs($agent, 'agent')
            ->test(ThreadView::class, ['thread' => $thread]);

        // After viewing - should be marked as read
        $thread->refresh();
        expect($thread->getUnreadCountFor($agent))->toBe(0);
    });

    it('shows participant avatars', function () {
        $agent = User::factory()->agent()->create(['name' => 'Test Agent']);
        $internal = User::factory()->internal()->create(['name' => 'Staff Member']);

        $thread = ChatThread::factory()
            ->withSubject('Test Thread')
            ->withParticipants([$agent, $internal])
            ->create();

        Livewire::actingAs($agent, 'agent')
            ->test(ThreadView::class, ['thread' => $thread])
            ->assertSee('2 participants');
    });

    it('allows agent to delete their own messages', function () {
        $agent = User::factory()->agent()->create();
        $internal = User::factory()->internal()->create();

        $thread = ChatThread::factory()
            ->withSubject('Test Thread')
            ->withParticipants([$agent, $internal])
            ->create();

        $message = ChatMessage::factory()
            ->forThread($thread)
            ->fromUser($agent)
            ->withContent('My message to delete')
            ->create();

        Livewire::actingAs($agent, 'agent')
            ->test(ThreadView::class, ['thread' => $thread])
            ->call('deleteMessage', $message->id)
            ->assertDispatched('message-deleted');

        expect(ChatMessage::find($message->id))->toBeNull();
    });

    it('forbids agent from deleting other users messages', function () {
        $agent = User::factory()->agent()->create();
        $internal = User::factory()->internal()->create();

        $thread = ChatThread::factory()
            ->withSubject('Test Thread')
            ->withParticipants([$agent, $internal])
            ->create();

        $message = ChatMessage::factory()
            ->forThread($thread)
            ->fromUser($internal)
            ->withContent('Internal message')
            ->create();

        Livewire::actingAs($agent, 'agent')
            ->test(ThreadView::class, ['thread' => $thread])
            ->call('deleteMessage', $message->id)
            ->assertForbidden();

        expect(ChatMessage::find($message->id))->not->toBeNull();
    });
});

describe('Agent Chat Message Composer', function () {
    it('allows agent to send messages', function () {
        $agent = User::factory()->agent()->create();
        $internal = User::factory()->internal()->create();

        $thread = ChatThread::factory()
            ->withSubject('Test Thread')
            ->withParticipants([$agent, $internal])
            ->create();

        Livewire::actingAs($agent, 'agent')
            ->test(MessageComposer::class, ['thread' => $thread])
            ->set('content', 'Hello from agent!')
            ->call('send')
            ->assertDispatched('message-sent');

        $this->assertDatabaseHas('chat_messages', [
            'thread_id' => $thread->id,
            'user_id' => $agent->id,
            'content' => 'Hello from agent!',
            'is_internal' => false,
        ]);
    });

    it('validates message content is required', function () {
        $agent = User::factory()->agent()->create();
        $internal = User::factory()->internal()->create();

        $thread = ChatThread::factory()
            ->withSubject('Test Thread')
            ->withParticipants([$agent, $internal])
            ->create();

        Livewire::actingAs($agent, 'agent')
            ->test(MessageComposer::class, ['thread' => $thread])
            ->set('content', '')
            ->call('send')
            ->assertHasErrors(['content' => 'required']);
    });

    it('validates message content max length', function () {
        $agent = User::factory()->agent()->create();
        $internal = User::factory()->internal()->create();

        $thread = ChatThread::factory()
            ->withSubject('Test Thread')
            ->withParticipants([$agent, $internal])
            ->create();

        Livewire::actingAs($agent, 'agent')
            ->test(MessageComposer::class, ['thread' => $thread])
            ->set('content', str_repeat('a', 10001))
            ->call('send')
            ->assertHasErrors(['content' => 'max']);
    });

    it('does not have internal message toggle for agents', function () {
        $agent = User::factory()->agent()->create();
        $internal = User::factory()->internal()->create();

        $thread = ChatThread::factory()
            ->withSubject('Test Thread')
            ->withParticipants([$agent, $internal])
            ->create();

        // Agent sends a message - it should always be non-internal
        Livewire::actingAs($agent, 'agent')
            ->test(MessageComposer::class, ['thread' => $thread])
            ->set('content', 'Agent message')
            ->call('send');

        $message = ChatMessage::where('content', 'Agent message')->first();

        expect($message->is_internal)->toBeFalse();
    });

    it('clears content after sending', function () {
        $agent = User::factory()->agent()->create();
        $internal = User::factory()->internal()->create();

        $thread = ChatThread::factory()
            ->withSubject('Test Thread')
            ->withParticipants([$agent, $internal])
            ->create();

        Livewire::actingAs($agent, 'agent')
            ->test(MessageComposer::class, ['thread' => $thread])
            ->set('content', 'Hello!')
            ->call('send')
            ->assertSet('content', '');
    });

    it('supports replying to messages', function () {
        $agent = User::factory()->agent()->create();
        $internal = User::factory()->internal()->create();

        $thread = ChatThread::factory()
            ->withSubject('Test Thread')
            ->withParticipants([$agent, $internal])
            ->create();

        $parentMessage = ChatMessage::factory()
            ->forThread($thread)
            ->fromUser($internal)
            ->withContent('Original message')
            ->create();

        Livewire::actingAs($agent, 'agent')
            ->test(MessageComposer::class, ['thread' => $thread])
            ->call('setReplyTo', $parentMessage->id)
            ->assertSet('replyToMessageId', $parentMessage->id)
            ->set('content', 'My reply')
            ->call('send');

        $reply = ChatMessage::where('content', 'My reply')->first();

        expect($reply->parent_id)->toBe($parentMessage->id);
    });

    it('can clear reply state', function () {
        $agent = User::factory()->agent()->create();
        $internal = User::factory()->internal()->create();

        $thread = ChatThread::factory()
            ->withSubject('Test Thread')
            ->withParticipants([$agent, $internal])
            ->create();

        $parentMessage = ChatMessage::factory()
            ->forThread($thread)
            ->fromUser($internal)
            ->create();

        Livewire::actingAs($agent, 'agent')
            ->test(MessageComposer::class, ['thread' => $thread])
            ->call('setReplyTo', $parentMessage->id)
            ->assertSet('replyToMessageId', $parentMessage->id)
            ->call('clearReply')
            ->assertSet('replyToMessageId', null)
            ->assertDispatched('cancel-reply');
    });
});

describe('Agent Chat Authorization', function () {
    it('agent cannot see internal messages in thread list preview', function () {
        $agent = User::factory()->agent()->create();
        $internal = User::factory()->internal()->create();

        $thread = ChatThread::factory()
            ->withSubject('Test Thread')
            ->withParticipants([$agent, $internal])
            ->create();

        // Create an internal message as the latest
        ChatMessage::factory()
            ->forThread($thread)
            ->fromUser($internal)
            ->internal()
            ->withContent('Secret internal note')
            ->create();

        $thread->refresh();
        $thread->touchLastMessage();

        $component = Livewire::actingAs($agent, 'agent')
            ->test(ThreadList::class);

        // The thread should appear but the internal message content should not be shown
        // Access computed property via the component instance
        $threads = $component->instance()->threads;
        expect($threads)->toHaveCount(1);

        // The unread count should not include internal messages
        $unreadCount = $thread->getUnreadCountFor($agent);
        expect($unreadCount)->toBe(0); // Internal messages don't count as unread for agents
    });

    it('agent messages are never marked as internal', function () {
        $agent = User::factory()->agent()->create();
        $internal = User::factory()->internal()->create();

        $thread = ChatThread::factory()
            ->withSubject('Test Thread')
            ->withParticipants([$agent, $internal])
            ->create();

        // Even if an agent somehow tries to send an internal message (which shouldn't happen),
        // it should be saved as non-internal
        Livewire::actingAs($agent, 'agent')
            ->test(MessageComposer::class, ['thread' => $thread])
            ->set('content', 'Agent message')
            ->call('send');

        $message = ChatMessage::where('user_id', $agent->id)->first();

        expect($message->is_internal)->toBeFalse();
    });
});
