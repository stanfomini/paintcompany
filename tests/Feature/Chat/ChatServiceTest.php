<?php

use App\Events\MessageRead;
use App\Events\MessageSent;
use App\Events\NewThreadNotification;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\User;
use App\Services\ChatService;
use Illuminate\Support\Facades\Event;

describe('ChatService canChatWith permission matrix', function () {
    it('allows internal users to chat with internal users', function () {
        $service = app(ChatService::class);
        $internal1 = User::factory()->internal()->withFullAccess()->create();
        $internal2 = User::factory()->internal()->withFullAccess()->create();

        expect($service->canChatWith($internal1, $internal2))->toBeTrue();
    });

    it('allows internal users to chat with agents', function () {
        $service = app(ChatService::class);
        $internal = User::factory()->internal()->withFullAccess()->create();
        $agent = User::factory()->agent()->create();

        expect($service->canChatWith($internal, $agent))->toBeTrue();
    });

    it('allows internal users to chat with customers', function () {
        $service = app(ChatService::class);
        $internal = User::factory()->internal()->withFullAccess()->create();
        $customer = User::factory()->customer()->create();

        expect($service->canChatWith($internal, $customer))->toBeTrue();
    });

    it('allows agents to chat with internal users', function () {
        $service = app(ChatService::class);
        $agent = User::factory()->agent()->create();
        $internal = User::factory()->internal()->withFullAccess()->create();

        expect($service->canChatWith($agent, $internal))->toBeTrue();
    });

    it('allows agents to chat with their referred customers', function () {
        $service = app(ChatService::class);
        $agent = User::factory()->agent()->create();
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        Deal::factory()->forCustomer($customer)->referredByAgent($agent)->create();

        expect($service->canChatWith($agent, $customerUser))->toBeTrue();
    });

    it('prevents agents from chatting with non-referred customers', function () {
        $service = app(ChatService::class);
        $agent = User::factory()->agent()->create();
        $customerUser = User::factory()->customer()->create();
        Customer::factory()->create(['user_id' => $customerUser->id]);

        expect($service->canChatWith($agent, $customerUser))->toBeFalse();
    });

    it('prevents agents from chatting with other agents', function () {
        $service = app(ChatService::class);
        $agent1 = User::factory()->agent()->create();
        $agent2 = User::factory()->agent()->create();

        expect($service->canChatWith($agent1, $agent2))->toBeFalse();
    });

    it('allows customers to chat with internal users', function () {
        $service = app(ChatService::class);
        $customer = User::factory()->customer()->create();
        $internal = User::factory()->internal()->withFullAccess()->create();

        expect($service->canChatWith($customer, $internal))->toBeTrue();
    });

    it('prevents customers from chatting with agents', function () {
        $service = app(ChatService::class);
        $customer = User::factory()->customer()->create();
        $agent = User::factory()->agent()->create();

        expect($service->canChatWith($customer, $agent))->toBeFalse();
    });

    it('prevents customers from chatting with other customers', function () {
        $service = app(ChatService::class);
        $customer1 = User::factory()->customer()->create();
        $customer2 = User::factory()->customer()->create();

        expect($service->canChatWith($customer1, $customer2))->toBeFalse();
    });
});

describe('ChatService getOrCreateDealThread', function () {
    it('creates thread for deal without existing thread', function () {
        Event::fake([NewThreadNotification::class]);

        $service = app(ChatService::class);
        $internal = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create(['title' => 'Test Deal']);

        $thread = $service->getOrCreateDealThread($deal, $internal);

        expect($thread)->toBeInstanceOf(ChatThread::class);
        expect($thread->type)->toBe(ChatThread::TYPE_DEAL);
        expect($thread->deal_id)->toBe($deal->id);
        expect($thread->subject)->toBe('Test Deal');
        expect($thread->hasParticipant($internal))->toBeTrue();

        Event::assertDispatched(NewThreadNotification::class);
    });

    it('returns existing thread for deal with existing thread', function () {
        Event::fake();

        $service = app(ChatService::class);
        $internal = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();
        $existingThread = ChatThread::factory()->forDeal($deal)->create();
        $existingThread->addParticipant($internal);

        $thread = $service->getOrCreateDealThread($deal, $internal);

        expect($thread->id)->toBe($existingThread->id);
        Event::assertNotDispatched(NewThreadNotification::class);
    });

    it('adds initiator as participant if not already a participant', function () {
        Event::fake();

        $service = app(ChatService::class);
        $user1 = User::factory()->internal()->withFullAccess()->create();
        $user2 = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        $thread = $service->getOrCreateDealThread($deal, $user1);

        expect($thread->hasParticipant($user1))->toBeTrue();

        $thread = $service->getOrCreateDealThread($deal, $user2);

        expect($thread->hasParticipant($user2))->toBeTrue();
    });
});

describe('ChatService getOrCreateDirectThread', function () {
    it('creates new direct thread between two users', function () {
        Event::fake([NewThreadNotification::class]);

        $service = app(ChatService::class);
        $user1 = User::factory()->internal()->withFullAccess()->create();
        $user2 = User::factory()->agent()->create();

        $thread = $service->getOrCreateDirectThread($user1, $user2, 'Test Subject');

        expect($thread)->toBeInstanceOf(ChatThread::class);
        expect($thread->type)->toBe(ChatThread::TYPE_DIRECT);
        expect($thread->subject)->toBe('Test Subject');
        expect($thread->hasParticipant($user1))->toBeTrue();
        expect($thread->hasParticipant($user2))->toBeTrue();

        Event::assertDispatched(NewThreadNotification::class);
    });

    it('returns existing direct thread between two users', function () {
        Event::fake();

        $service = app(ChatService::class);
        $user1 = User::factory()->internal()->withFullAccess()->create();
        $user2 = User::factory()->agent()->create();

        $firstThread = $service->getOrCreateDirectThread($user1, $user2, 'First Subject');
        $secondThread = $service->getOrCreateDirectThread($user1, $user2, 'Second Subject');

        expect($secondThread->id)->toBe($firstThread->id);
        expect($secondThread->subject)->toBe('First Subject');
    });

    it('creates new thread when users have left previous thread', function () {
        Event::fake();

        $service = app(ChatService::class);
        $user1 = User::factory()->internal()->withFullAccess()->create();
        $user2 = User::factory()->agent()->create();

        $firstThread = $service->getOrCreateDirectThread($user1, $user2);
        $firstThread->removeParticipant($user1);

        $secondThread = $service->getOrCreateDirectThread($user1, $user2);

        expect($secondThread->id)->not->toBe($firstThread->id);
    });
});

describe('ChatService sendMessage', function () {
    it('creates message and broadcasts', function () {
        Event::fake([MessageSent::class]);

        $service = app(ChatService::class);
        $user = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($user);

        $message = $service->sendMessage($thread, $user, 'Hello World');

        expect($message)->toBeInstanceOf(ChatMessage::class);
        expect($message->content)->toBe('Hello World');
        expect($message->user_id)->toBe($user->id);
        expect($message->thread_id)->toBe($thread->id);
        expect($message->type)->toBe(ChatMessage::TYPE_TEXT);
        expect($message->is_internal)->toBeFalse();

        Event::assertDispatched(MessageSent::class);
    });

    it('updates thread last_message_at timestamp', function () {
        Event::fake();

        $service = app(ChatService::class);
        $user = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()->create(['last_message_at' => now()->subDay()]);
        $thread->addParticipant($user);

        $originalTime = $thread->last_message_at;
        $service->sendMessage($thread, $user, 'Test message');
        $thread->refresh();

        expect($thread->last_message_at->gt($originalTime))->toBeTrue();
    });

    it('adds sender as participant if not already', function () {
        Event::fake();

        $service = app(ChatService::class);
        $user = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()->create();

        expect($thread->hasParticipant($user))->toBeFalse();

        $service->sendMessage($thread, $user, 'Test message');

        expect($thread->hasParticipant($user))->toBeTrue();
    });

    it('marks thread as read for sender', function () {
        Event::fake();

        $service = app(ChatService::class);
        $user = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()->create();

        $service->sendMessage($thread, $user, 'Test message');

        $participant = $thread->getParticipant($user);
        expect($participant->last_read_at)->not->toBeNull();
    });
});

describe('ChatService sendMessage with is_internal flag', function () {
    it('allows internal users to send internal messages', function () {
        Event::fake();

        $service = app(ChatService::class);
        $internal = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($internal);

        $message = $service->sendMessage($thread, $internal, 'Internal note', ['is_internal' => true]);

        expect($message->is_internal)->toBeTrue();
    });

    it('ignores is_internal flag for agent users', function () {
        Event::fake();

        $service = app(ChatService::class);
        $agent = User::factory()->agent()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($agent);

        $message = $service->sendMessage($thread, $agent, 'Trying to be internal', ['is_internal' => true]);

        expect($message->is_internal)->toBeFalse();
    });

    it('ignores is_internal flag for customer users', function () {
        Event::fake();

        $service = app(ChatService::class);
        $customer = User::factory()->customer()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($customer);

        $message = $service->sendMessage($thread, $customer, 'Trying to be internal', ['is_internal' => true]);

        expect($message->is_internal)->toBeFalse();
    });
});

describe('ChatService markThreadAsRead', function () {
    it('updates participant last_read_at timestamp', function () {
        Event::fake([MessageRead::class]);

        $service = app(ChatService::class);
        $user = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($user);

        $service->markThreadAsRead($thread, $user);

        $participant = $thread->getParticipant($user);
        expect($participant->last_read_at)->not->toBeNull();

        Event::assertDispatched(MessageRead::class);
    });

    it('does nothing for non-participant', function () {
        Event::fake([MessageRead::class]);

        $service = app(ChatService::class);
        $user = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()->create();

        $service->markThreadAsRead($thread, $user);

        Event::assertNotDispatched(MessageRead::class);
    });
});

describe('ChatService getThreadsForUser', function () {
    it('returns threads where user is active participant', function () {
        Event::fake();

        $service = app(ChatService::class);
        $user = User::factory()->internal()->withFullAccess()->create();

        $thread1 = ChatThread::factory()->create();
        $thread1->addParticipant($user);

        $thread2 = ChatThread::factory()->create();
        $thread2->addParticipant($user);

        $thread3 = ChatThread::factory()->create();

        $threads = $service->getThreadsForUser($user);

        expect($threads)->toHaveCount(2);
        expect($threads->pluck('id')->toArray())->toContain($thread1->id, $thread2->id);
        expect($threads->pluck('id')->toArray())->not->toContain($thread3->id);
    });

    it('excludes threads where user has left', function () {
        Event::fake();

        $service = app(ChatService::class);
        $user = User::factory()->internal()->withFullAccess()->create();

        $thread = ChatThread::factory()->create();
        $thread->addParticipant($user);
        $thread->removeParticipant($user);

        $threads = $service->getThreadsForUser($user);

        expect($threads)->toHaveCount(0);
    });

    it('excludes archived threads', function () {
        Event::fake();

        $service = app(ChatService::class);
        $user = User::factory()->internal()->withFullAccess()->create();

        $activeThread = ChatThread::factory()->create();
        $activeThread->addParticipant($user);

        $archivedThread = ChatThread::factory()->archived()->create();
        $archivedThread->addParticipant($user);

        $threads = $service->getThreadsForUser($user);

        expect($threads)->toHaveCount(1);
        expect($threads->first()->id)->toBe($activeThread->id);
    });

    it('includes unread count for each thread', function () {
        Event::fake();

        $service = app(ChatService::class);
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();

        $thread = ChatThread::factory()->create();
        $thread->addParticipant($user);
        $thread->addParticipant($otherUser);

        ChatMessage::factory()->forThread($thread)->fromUser($otherUser)->count(3)->create();

        $threads = $service->getThreadsForUser($user);

        expect($threads->first()->unread_count)->toBe(3);
    });

    it('includes display name for each thread', function () {
        Event::fake();

        $service = app(ChatService::class);
        $user = User::factory()->internal()->withFullAccess()->create();

        $thread = ChatThread::factory()->withSubject('Test Subject')->create();
        $thread->addParticipant($user);

        $threads = $service->getThreadsForUser($user);

        expect($threads->first()->display_name)->toBe('Test Subject');
    });
});

describe('ChatService getMessagesForThread', function () {
    it('returns messages visible to user', function () {
        Event::fake();

        $service = app(ChatService::class);
        $internal = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($internal);

        ChatMessage::factory()->forThread($thread)->fromUser($internal)->count(5)->create();

        $messages = $service->getMessagesForThread($thread, $internal);

        expect($messages)->toHaveCount(5);
    });

    it('filters internal messages for non-internal users', function () {
        Event::fake();

        $service = app(ChatService::class);
        $internal = User::factory()->internal()->withFullAccess()->create();
        $customer = User::factory()->customer()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($internal);
        $thread->addParticipant($customer);

        ChatMessage::factory()->forThread($thread)->fromUser($internal)->count(3)->create();
        ChatMessage::factory()->forThread($thread)->fromUser($internal)->internal()->count(2)->create();

        $customerMessages = $service->getMessagesForThread($thread, $customer);
        $internalMessages = $service->getMessagesForThread($thread, $internal);

        expect($customerMessages)->toHaveCount(3);
        expect($internalMessages)->toHaveCount(5);
    });

    it('respects limit parameter', function () {
        Event::fake();

        $service = app(ChatService::class);
        $user = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($user);

        ChatMessage::factory()->forThread($thread)->fromUser($user)->count(10)->create();

        $messages = $service->getMessagesForThread($thread, $user, 5);

        expect($messages)->toHaveCount(5);
    });

    it('respects beforeId parameter for pagination', function () {
        Event::fake();

        $service = app(ChatService::class);
        $user = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($user);

        $messages = ChatMessage::factory()->forThread($thread)->fromUser($user)->count(5)->create();
        $middleMessage = $messages->sortByDesc('id')->values()->get(2);

        $olderMessages = $service->getMessagesForThread($thread, $user, 50, $middleMessage->id);

        foreach ($olderMessages as $message) {
            expect($message->id)->toBeLessThan($middleMessage->id);
        }
    });
});

describe('ChatService addParticipant and removeParticipant', function () {
    it('adds participant and sends system message', function () {
        Event::fake();

        $service = app(ChatService::class);
        $internal = User::factory()->internal()->withFullAccess()->create(['name' => 'Admin User']);
        $newUser = User::factory()->agent()->create(['name' => 'New Agent']);
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($internal);

        $service->addParticipant($thread, $newUser, $internal);

        expect($thread->hasParticipant($newUser))->toBeTrue();

        $systemMessage = $thread->messages()->where('type', ChatMessage::TYPE_SYSTEM)->first();
        expect($systemMessage->content)->toContain('Admin User added New Agent');
    });

    it('removes participant and sends system message', function () {
        Event::fake();

        $service = app(ChatService::class);
        $internal = User::factory()->internal()->withFullAccess()->create(['name' => 'Admin User']);
        $existingUser = User::factory()->agent()->create(['name' => 'Existing Agent']);
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($internal);
        $thread->addParticipant($existingUser);

        $service->removeParticipant($thread, $existingUser, $internal);

        expect($thread->hasParticipant($existingUser))->toBeFalse();

        $systemMessage = $thread->messages()->where('type', ChatMessage::TYPE_SYSTEM)->first();
        expect($systemMessage->content)->toContain('Admin User removed Existing Agent');
    });

    it('does not add already existing participant', function () {
        Event::fake();

        $service = app(ChatService::class);
        $internal = User::factory()->internal()->withFullAccess()->create();
        $existingUser = User::factory()->agent()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($internal);
        $thread->addParticipant($existingUser);

        $service->addParticipant($thread, $existingUser, $internal);

        expect($thread->messages()->where('type', ChatMessage::TYPE_SYSTEM)->count())->toBe(0);
    });

    it('does not remove non-participant', function () {
        Event::fake();

        $service = app(ChatService::class);
        $internal = User::factory()->internal()->withFullAccess()->create();
        $nonParticipant = User::factory()->agent()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($internal);

        $service->removeParticipant($thread, $nonParticipant, $internal);

        expect($thread->messages()->where('type', ChatMessage::TYPE_SYSTEM)->count())->toBe(0);
    });
});
