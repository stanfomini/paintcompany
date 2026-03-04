<?php

use App\Events\MessageSent;
use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

describe('MessageSent broadcasting', function () {

    it('implements ShouldBroadcast', function () {
        $message = ChatMessage::factory()->create();

        $event = new MessageSent($message);

        expect($event)->toBeInstanceOf(ShouldBroadcast::class);
    });

    it('broadcasts on thread channel and participant user channels', function () {
        $sender = User::factory()->internal()->create();
        $recipient1 = User::factory()->internal()->create();
        $recipient2 = User::factory()->customer()->create();

        $thread = ChatThread::factory()->create();
        $thread->addParticipant($sender);
        $thread->addParticipant($recipient1);
        $thread->addParticipant($recipient2);

        $message = ChatMessage::factory()->forThread($thread)->fromUser($sender)->create();

        $event = new MessageSent($message);
        $channels = $event->broadcastOn();

        // Thread channel + 2 recipient user channels (sender excluded)
        expect($channels)->toHaveCount(3);
        expect($channels[0]->name)->toBe('private-chat.thread.'.$thread->id);

        $userChannelNames = collect($channels)->slice(1)->pluck('name')->sort()->values()->all();
        expect($userChannelNames)->toContain('private-chat.user.'.$recipient1->id);
        expect($userChannelNames)->toContain('private-chat.user.'.$recipient2->id);
    });

    it('excludes sender from user channels', function () {
        $sender = User::factory()->internal()->create();

        $thread = ChatThread::factory()->create();
        $thread->addParticipant($sender);

        $message = ChatMessage::factory()->forThread($thread)->fromUser($sender)->create();

        $event = new MessageSent($message);
        $channels = $event->broadcastOn();

        // Only thread channel since sender is the only participant
        expect($channels)->toHaveCount(1);
        expect($channels[0]->name)->toBe('private-chat.thread.'.$thread->id);
    });

    it('includes thread channel for thread-view components', function () {
        $sender = User::factory()->internal()->create();
        $recipient = User::factory()->customer()->create();

        $thread = ChatThread::factory()->create();
        $thread->addParticipant($sender);
        $thread->addParticipant($recipient);

        $message = ChatMessage::factory()->forThread($thread)->fromUser($sender)->create();

        $event = new MessageSent($message);
        $channels = $event->broadcastOn();

        expect($channels[0]->name)->toBe('private-chat.thread.'.$thread->id);
    });

    it('all channels are PrivateChannel instances', function () {
        $sender = User::factory()->internal()->create();
        $recipient = User::factory()->customer()->create();

        $thread = ChatThread::factory()->create();
        $thread->addParticipant($sender);
        $thread->addParticipant($recipient);

        $message = ChatMessage::factory()->forThread($thread)->fromUser($sender)->create();

        $event = new MessageSent($message);

        foreach ($event->broadcastOn() as $channel) {
            expect($channel)->toBeInstanceOf(PrivateChannel::class);
        }
    });

    it('broadcasts as message.sent', function () {
        $message = ChatMessage::factory()->create();

        $event = new MessageSent($message);

        expect($event->broadcastAs())->toBe('message.sent');
    });

    it('broadcastWith contains message data', function () {
        $sender = User::factory()->internal()->create();
        $message = ChatMessage::factory()->fromUser($sender)->create();

        $event = new MessageSent($message);
        $payload = $event->broadcastWith();

        expect($payload)->toHaveKey('message');
        expect($payload['message'])->toHaveKeys(['id', 'thread_id', 'user_id', 'content', 'type', 'created_at', 'user']);
        expect($payload['message']['id'])->toBe($message->id);
        expect($payload['message']['user']['id'])->toBe($sender->id);
    });
});
