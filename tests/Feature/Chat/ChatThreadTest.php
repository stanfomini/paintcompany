<?php

use App\Models\ChatMessage;
use App\Models\ChatParticipant;
use App\Models\ChatThread;
use App\Models\Deal;
use App\Models\User;
use Illuminate\Support\Facades\Event;

describe('ChatThread hasParticipant', function () {
    it('returns true when user is active participant', function () {
        $user = User::factory()->internal()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($user);

        expect($thread->hasParticipant($user))->toBeTrue();
    });

    it('returns false when user is not a participant', function () {
        $user = User::factory()->internal()->create();
        $thread = ChatThread::factory()->create();

        expect($thread->hasParticipant($user))->toBeFalse();
    });

    it('returns false when user has left the thread', function () {
        $user = User::factory()->internal()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($user);
        $thread->removeParticipant($user);

        expect($thread->hasParticipant($user))->toBeFalse();
    });
});

describe('ChatThread addParticipant', function () {
    it('creates participant record for new user', function () {
        $user = User::factory()->internal()->create();
        $thread = ChatThread::factory()->create();

        $participant = $thread->addParticipant($user);

        expect($participant)->toBeInstanceOf(ChatParticipant::class);
        expect($participant->user_id)->toBe($user->id);
        expect($participant->thread_id)->toBe($thread->id);
        expect($participant->joined_at)->not->toBeNull();
        expect($participant->left_at)->toBeNull();
    });

    it('reactivates participant who previously left', function () {
        $user = User::factory()->internal()->create();
        $thread = ChatThread::factory()->create();

        $thread->addParticipant($user);
        $thread->removeParticipant($user);

        expect($thread->hasParticipant($user))->toBeFalse();

        $thread->addParticipant($user);

        expect($thread->hasParticipant($user))->toBeTrue();
    });

    it('updates existing record instead of creating duplicate', function () {
        $user = User::factory()->internal()->create();
        $thread = ChatThread::factory()->create();

        $thread->addParticipant($user);
        $thread->addParticipant($user);

        expect(ChatParticipant::where('thread_id', $thread->id)->where('user_id', $user->id)->count())->toBe(1);
    });
});

describe('ChatThread removeParticipant', function () {
    it('sets left_at timestamp', function () {
        $user = User::factory()->internal()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($user);

        $result = $thread->removeParticipant($user);

        expect($result)->toBeTrue();

        $participant = ChatParticipant::where('thread_id', $thread->id)
            ->where('user_id', $user->id)
            ->first();

        expect($participant->left_at)->not->toBeNull();
    });

    it('returns false when user is not a participant', function () {
        $user = User::factory()->internal()->create();
        $thread = ChatThread::factory()->create();

        $result = $thread->removeParticipant($user);

        expect($result)->toBeFalse();
    });
});

describe('ChatThread getUnreadCountFor', function () {
    it('returns correct count for unread messages', function () {
        Event::fake();

        $user = User::factory()->internal()->create();
        $otherUser = User::factory()->internal()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($user);
        $thread->addParticipant($otherUser);

        ChatMessage::factory()->forThread($thread)->fromUser($otherUser)->count(3)->create();

        expect($thread->getUnreadCountFor($user))->toBe(3);
    });

    it('returns zero when all messages are read', function () {
        Event::fake();

        $user = User::factory()->internal()->create();
        $otherUser = User::factory()->internal()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($user);
        $thread->addParticipant($otherUser);

        ChatMessage::factory()->forThread($thread)->fromUser($otherUser)->count(3)->create();

        $participant = $thread->getParticipant($user);
        $participant->markAsRead();

        expect($thread->getUnreadCountFor($user))->toBe(0);
    });

    it('does not count own messages', function () {
        Event::fake();

        $user = User::factory()->internal()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($user);

        ChatMessage::factory()->forThread($thread)->fromUser($user)->count(5)->create();

        expect($thread->getUnreadCountFor($user))->toBe(0);
    });

    it('returns zero for non-participant', function () {
        Event::fake();

        $user = User::factory()->internal()->create();
        $otherUser = User::factory()->internal()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($otherUser);

        ChatMessage::factory()->forThread($thread)->fromUser($otherUser)->count(3)->create();

        expect($thread->getUnreadCountFor($user))->toBe(0);
    });

    it('excludes internal messages for non-internal users', function () {
        Event::fake();

        $customer = User::factory()->customer()->create();
        $internal = User::factory()->internal()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($customer);
        $thread->addParticipant($internal);

        ChatMessage::factory()->forThread($thread)->fromUser($internal)->count(2)->create();
        ChatMessage::factory()->forThread($thread)->fromUser($internal)->internal()->count(3)->create();

        expect($thread->getUnreadCountFor($customer))->toBe(2);
    });

    it('includes internal messages for internal users', function () {
        Event::fake();

        $internal1 = User::factory()->internal()->create();
        $internal2 = User::factory()->internal()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($internal1);
        $thread->addParticipant($internal2);

        ChatMessage::factory()->forThread($thread)->fromUser($internal2)->count(2)->create();
        ChatMessage::factory()->forThread($thread)->fromUser($internal2)->internal()->count(3)->create();

        expect($thread->getUnreadCountFor($internal1))->toBe(5);
    });
});

describe('ChatThread forUser scope', function () {
    it('filters threads where user is active participant', function () {
        $user = User::factory()->internal()->create();
        $otherUser = User::factory()->internal()->create();

        $thread1 = ChatThread::factory()->create();
        $thread1->addParticipant($user);

        $thread2 = ChatThread::factory()->create();
        $thread2->addParticipant($otherUser);

        $threads = ChatThread::forUser($user)->get();

        expect($threads)->toHaveCount(1);
        expect($threads->first()->id)->toBe($thread1->id);
    });

    it('excludes threads where user has left', function () {
        $user = User::factory()->internal()->create();

        $thread = ChatThread::factory()->create();
        $thread->addParticipant($user);
        $thread->removeParticipant($user);

        $threads = ChatThread::forUser($user)->get();

        expect($threads)->toHaveCount(0);
    });
});

describe('ChatThread getDisplayNameFor', function () {
    it('returns subject when set', function () {
        $user = User::factory()->internal()->create();
        $thread = ChatThread::factory()->withSubject('Custom Subject')->create();
        $thread->addParticipant($user);

        expect($thread->getDisplayNameFor($user))->toBe('Custom Subject');
    });

    it('returns deal title for deal threads', function () {
        $user = User::factory()->internal()->create();
        $deal = Deal::factory()->create(['title' => 'Deal Title']);
        $thread = ChatThread::factory()->forDeal($deal)->create(['subject' => null]);
        $thread->addParticipant($user);

        expect($thread->getDisplayNameFor($user))->toBe('Deal Title');
    });

    it('returns other participant name for direct threads without subject', function () {
        $user = User::factory()->internal()->create(['name' => 'Current User']);
        $otherUser = User::factory()->agent()->create(['name' => 'Other User']);
        $thread = ChatThread::factory()->direct()->create(['subject' => null]);
        $thread->addParticipant($user);
        $thread->addParticipant($otherUser);

        expect($thread->getDisplayNameFor($user))->toBe('Other User');
    });

    it('returns multiple participant names for group threads', function () {
        $user = User::factory()->internal()->create(['name' => 'Current User']);
        $user2 = User::factory()->agent()->create(['name' => 'User Two']);
        $user3 = User::factory()->customer()->create(['name' => 'User Three']);
        $thread = ChatThread::factory()->direct()->create(['subject' => null]);
        $thread->addParticipant($user);
        $thread->addParticipant($user2);
        $thread->addParticipant($user3);

        $displayName = $thread->getDisplayNameFor($user);

        expect($displayName)->toContain('User Two');
        expect($displayName)->toContain('User Three');
    });

    it('returns Chat as fallback when no participants', function () {
        $user = User::factory()->internal()->create();
        $thread = ChatThread::factory()->direct()->create(['subject' => null]);
        $thread->addParticipant($user);

        expect($thread->getDisplayNameFor($user))->toBe('Chat');
    });
});

describe('ChatThread scopes', function () {
    it('active scope filters out archived threads', function () {
        ChatThread::factory()->create(['is_archived' => false]);
        ChatThread::factory()->archived()->create();

        $threads = ChatThread::active()->get();

        expect($threads)->toHaveCount(1);
        expect($threads->first()->is_archived)->toBeFalse();
    });

    it('archived scope returns only archived threads', function () {
        ChatThread::factory()->create(['is_archived' => false]);
        ChatThread::factory()->archived()->create();

        $threads = ChatThread::archived()->get();

        expect($threads)->toHaveCount(1);
        expect($threads->first()->is_archived)->toBeTrue();
    });

    it('ofType scope filters by thread type', function () {
        ChatThread::factory()->direct()->create();
        ChatThread::factory()->support()->create();

        $directThreads = ChatThread::ofType(ChatThread::TYPE_DIRECT)->get();
        $supportThreads = ChatThread::ofType(ChatThread::TYPE_SUPPORT)->get();

        expect($directThreads)->toHaveCount(1);
        expect($supportThreads)->toHaveCount(1);
    });

    it('latestActivity scope orders by last_message_at descending', function () {
        $oldest = ChatThread::factory()->create(['last_message_at' => now()->subDays(3)]);
        $newest = ChatThread::factory()->create(['last_message_at' => now()]);
        $middle = ChatThread::factory()->create(['last_message_at' => now()->subDay()]);

        $threads = ChatThread::latestActivity()->get();

        expect($threads->first()->id)->toBe($newest->id);
        expect($threads->last()->id)->toBe($oldest->id);
    });
});

describe('ChatThread relationships', function () {
    it('belongs to a deal', function () {
        $deal = Deal::factory()->create();
        $thread = ChatThread::factory()->forDeal($deal)->create();

        expect($thread->deal->id)->toBe($deal->id);
    });

    it('has many participants', function () {
        $user1 = User::factory()->internal()->create();
        $user2 = User::factory()->agent()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($user1);
        $thread->addParticipant($user2);

        expect($thread->participants)->toHaveCount(2);
    });

    it('has many messages', function () {
        Event::fake();

        $user = User::factory()->internal()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($user);

        ChatMessage::factory()->forThread($thread)->fromUser($user)->count(3)->create();

        expect($thread->messages)->toHaveCount(3);
    });

    it('has latest message relationship', function () {
        Event::fake();

        $user = User::factory()->internal()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($user);

        ChatMessage::factory()->forThread($thread)->fromUser($user)->create(['content' => 'First']);
        ChatMessage::factory()->forThread($thread)->fromUser($user)->create(['content' => 'Last']);

        expect($thread->latestMessage->content)->toBe('Last');
    });

    it('has active participants excluding those who left', function () {
        $user1 = User::factory()->internal()->create();
        $user2 = User::factory()->agent()->create();
        $thread = ChatThread::factory()->create();
        $thread->addParticipant($user1);
        $thread->addParticipant($user2);
        $thread->removeParticipant($user2);

        expect($thread->activeParticipants)->toHaveCount(1);
        expect($thread->activeParticipants->first()->user_id)->toBe($user1->id);
    });
});

describe('ChatThread touchLastMessage', function () {
    it('updates last_message_at to current time', function () {
        $thread = ChatThread::factory()->create(['last_message_at' => now()->subWeek()]);
        $originalTime = $thread->last_message_at;

        $thread->touchLastMessage();
        $thread->refresh();

        expect($thread->last_message_at->gt($originalTime))->toBeTrue();
    });
});
