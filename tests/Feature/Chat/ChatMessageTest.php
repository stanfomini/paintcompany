<?php

use App\Models\ChatMessage;
use App\Models\ChatMessageRead;
use App\Models\ChatThread;
use App\Models\User;
use Illuminate\Support\Facades\Event;

describe('ChatMessage visibleTo scope', function () {
    it('returns all messages for internal users', function () {
        Event::fake();

        $internal = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()->create();

        ChatMessage::factory()->forThread($thread)->fromUser($internal)->count(3)->create();
        ChatMessage::factory()->forThread($thread)->fromUser($internal)->internal()->count(2)->create();

        $messages = ChatMessage::visibleTo($internal)->get();

        expect($messages)->toHaveCount(5);
    });

    it('filters out internal messages for agent users', function () {
        Event::fake();

        $internal = User::factory()->internal()->withFullAccess()->create();
        $agent = User::factory()->agent()->create();
        $thread = ChatThread::factory()->create();

        ChatMessage::factory()->forThread($thread)->fromUser($internal)->count(3)->create();
        ChatMessage::factory()->forThread($thread)->fromUser($internal)->internal()->count(2)->create();

        $messages = ChatMessage::visibleTo($agent)->get();

        expect($messages)->toHaveCount(3);
        expect($messages->where('is_internal', true)->count())->toBe(0);
    });

    it('filters out internal messages for customer users', function () {
        Event::fake();

        $internal = User::factory()->internal()->withFullAccess()->create();
        $customer = User::factory()->customer()->create();
        $thread = ChatThread::factory()->create();

        ChatMessage::factory()->forThread($thread)->fromUser($internal)->count(4)->create();
        ChatMessage::factory()->forThread($thread)->fromUser($internal)->internal()->count(3)->create();

        $messages = ChatMessage::visibleTo($customer)->get();

        expect($messages)->toHaveCount(4);
        expect($messages->where('is_internal', true)->count())->toBe(0);
    });
});

describe('ChatMessage isReadBy', function () {
    it('returns true when message has been read by user', function () {
        Event::fake();

        $user = User::factory()->internal()->withFullAccess()->create();
        $reader = User::factory()->agent()->create();
        $thread = ChatThread::factory()->create();
        $message = ChatMessage::factory()->forThread($thread)->fromUser($user)->create();

        $message->markAsReadBy($reader);

        expect($message->isReadBy($reader))->toBeTrue();
    });

    it('returns false when message has not been read by user', function () {
        Event::fake();

        $user = User::factory()->internal()->withFullAccess()->create();
        $reader = User::factory()->agent()->create();
        $thread = ChatThread::factory()->create();
        $message = ChatMessage::factory()->forThread($thread)->fromUser($user)->create();

        expect($message->isReadBy($reader))->toBeFalse();
    });

    it('returns false for different users who have not read', function () {
        Event::fake();

        $user = User::factory()->internal()->withFullAccess()->create();
        $reader1 = User::factory()->agent()->create();
        $reader2 = User::factory()->customer()->create();
        $thread = ChatThread::factory()->create();
        $message = ChatMessage::factory()->forThread($thread)->fromUser($user)->create();

        $message->markAsReadBy($reader1);

        expect($message->isReadBy($reader1))->toBeTrue();
        expect($message->isReadBy($reader2))->toBeFalse();
    });
});

describe('ChatMessage markAsReadBy', function () {
    it('creates read receipt for user', function () {
        Event::fake();

        $user = User::factory()->internal()->withFullAccess()->create();
        $reader = User::factory()->agent()->create();
        $thread = ChatThread::factory()->create();
        $message = ChatMessage::factory()->forThread($thread)->fromUser($user)->create();

        $readReceipt = $message->markAsReadBy($reader);

        expect($readReceipt)->toBeInstanceOf(ChatMessageRead::class);
        expect($readReceipt->user_id)->toBe($reader->id);
        expect($readReceipt->message_id)->toBe($message->id);
        expect($readReceipt->read_at)->not->toBeNull();
    });

    it('does not create duplicate read receipt', function () {
        Event::fake();

        $user = User::factory()->internal()->withFullAccess()->create();
        $reader = User::factory()->agent()->create();
        $thread = ChatThread::factory()->create();
        $message = ChatMessage::factory()->forThread($thread)->fromUser($user)->create();

        $message->markAsReadBy($reader);
        $message->markAsReadBy($reader);

        expect(ChatMessageRead::where('message_id', $message->id)->where('user_id', $reader->id)->count())->toBe(1);
    });

    it('returns existing read receipt when already read', function () {
        Event::fake();

        $user = User::factory()->internal()->withFullAccess()->create();
        $reader = User::factory()->agent()->create();
        $thread = ChatThread::factory()->create();
        $message = ChatMessage::factory()->forThread($thread)->fromUser($user)->create();

        $first = $message->markAsReadBy($reader);
        $second = $message->markAsReadBy($reader);

        expect($first->id)->toBe($second->id);
    });
});

describe('ChatMessage helper methods', function () {
    it('isEdited returns true for edited messages', function () {
        Event::fake();

        $user = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()->create();
        $message = ChatMessage::factory()->forThread($thread)->fromUser($user)->edited()->create();

        expect($message->isEdited())->toBeTrue();
    });

    it('isEdited returns false for non-edited messages', function () {
        Event::fake();

        $user = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()->create();
        $message = ChatMessage::factory()->forThread($thread)->fromUser($user)->create();

        expect($message->isEdited())->toBeFalse();
    });

    it('isReply returns true for reply messages', function () {
        Event::fake();

        $user = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()->create();
        $parentMessage = ChatMessage::factory()->forThread($thread)->fromUser($user)->create();
        $replyMessage = ChatMessage::factory()->replyTo($parentMessage)->fromUser($user)->create();

        expect($replyMessage->isReply())->toBeTrue();
    });

    it('isReply returns false for top-level messages', function () {
        Event::fake();

        $user = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()->create();
        $message = ChatMessage::factory()->forThread($thread)->fromUser($user)->create();

        expect($message->isReply())->toBeFalse();
    });

    it('isSystem returns true for system messages', function () {
        Event::fake();

        $user = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()->create();
        $message = ChatMessage::factory()->forThread($thread)->fromUser($user)->system()->create();

        expect($message->isSystem())->toBeTrue();
    });

    it('isSystem returns false for regular messages', function () {
        Event::fake();

        $user = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()->create();
        $message = ChatMessage::factory()->forThread($thread)->fromUser($user)->create();

        expect($message->isSystem())->toBeFalse();
    });

    it('markAsEdited sets edited_at timestamp', function () {
        Event::fake();

        $user = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()->create();
        $message = ChatMessage::factory()->forThread($thread)->fromUser($user)->create();

        expect($message->edited_at)->toBeNull();

        $message->markAsEdited();
        $message->refresh();

        expect($message->edited_at)->not->toBeNull();
    });
});

describe('ChatMessage scopes', function () {
    it('public scope returns only non-internal messages', function () {
        Event::fake();

        $user = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()->create();

        ChatMessage::factory()->forThread($thread)->fromUser($user)->count(3)->create();
        ChatMessage::factory()->forThread($thread)->fromUser($user)->internal()->count(2)->create();

        $messages = ChatMessage::public()->get();

        expect($messages)->toHaveCount(3);
        expect($messages->where('is_internal', true)->count())->toBe(0);
    });

    it('internal scope returns only internal messages', function () {
        Event::fake();

        $user = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()->create();

        ChatMessage::factory()->forThread($thread)->fromUser($user)->count(3)->create();
        ChatMessage::factory()->forThread($thread)->fromUser($user)->internal()->count(2)->create();

        $messages = ChatMessage::internal()->get();

        expect($messages)->toHaveCount(2);
        expect($messages->where('is_internal', false)->count())->toBe(0);
    });

    it('ofType scope filters by message type', function () {
        Event::fake();

        $user = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()->create();

        ChatMessage::factory()->forThread($thread)->fromUser($user)->text()->count(2)->create();
        ChatMessage::factory()->forThread($thread)->fromUser($user)->file()->count(1)->create();
        ChatMessage::factory()->forThread($thread)->fromUser($user)->system()->count(1)->create();

        $textMessages = ChatMessage::ofType(ChatMessage::TYPE_TEXT)->get();
        $fileMessages = ChatMessage::ofType(ChatMessage::TYPE_FILE)->get();
        $systemMessages = ChatMessage::ofType(ChatMessage::TYPE_SYSTEM)->get();

        expect($textMessages)->toHaveCount(2);
        expect($fileMessages)->toHaveCount(1);
        expect($systemMessages)->toHaveCount(1);
    });

    it('notSystem scope excludes system messages', function () {
        Event::fake();

        $user = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()->create();

        ChatMessage::factory()->forThread($thread)->fromUser($user)->text()->count(3)->create();
        ChatMessage::factory()->forThread($thread)->fromUser($user)->system()->count(2)->create();

        $messages = ChatMessage::notSystem()->get();

        expect($messages)->toHaveCount(3);
        expect($messages->where('type', ChatMessage::TYPE_SYSTEM)->count())->toBe(0);
    });
});

describe('ChatMessage relationships', function () {
    it('belongs to a thread', function () {
        Event::fake();

        $user = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()->create();
        $message = ChatMessage::factory()->forThread($thread)->fromUser($user)->create();

        expect($message->thread->id)->toBe($thread->id);
    });

    it('belongs to a user', function () {
        Event::fake();

        $user = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()->create();
        $message = ChatMessage::factory()->forThread($thread)->fromUser($user)->create();

        expect($message->user->id)->toBe($user->id);
    });

    it('belongs to a parent message for replies', function () {
        Event::fake();

        $user = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()->create();
        $parentMessage = ChatMessage::factory()->forThread($thread)->fromUser($user)->create();
        $replyMessage = ChatMessage::factory()->replyTo($parentMessage)->fromUser($user)->create();

        expect($replyMessage->parent->id)->toBe($parentMessage->id);
    });

    it('has many replies', function () {
        Event::fake();

        $user = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()->create();
        $parentMessage = ChatMessage::factory()->forThread($thread)->fromUser($user)->create();

        ChatMessage::factory()->replyTo($parentMessage)->fromUser($user)->count(3)->create();

        expect($parentMessage->replies)->toHaveCount(3);
    });

    it('has many read receipts', function () {
        Event::fake();

        $user = User::factory()->internal()->withFullAccess()->create();
        $reader1 = User::factory()->agent()->create();
        $reader2 = User::factory()->customer()->create();
        $thread = ChatThread::factory()->create();
        $message = ChatMessage::factory()->forThread($thread)->fromUser($user)->create();

        $message->markAsReadBy($reader1);
        $message->markAsReadBy($reader2);

        expect($message->reads)->toHaveCount(2);
    });
});

describe('ChatMessage soft deletes', function () {
    it('can be soft deleted', function () {
        Event::fake();

        $user = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()->create();
        $message = ChatMessage::factory()->forThread($thread)->fromUser($user)->create();

        $message->delete();

        expect(ChatMessage::find($message->id))->toBeNull();
        expect(ChatMessage::withTrashed()->find($message->id))->not->toBeNull();
    });

    it('can be restored', function () {
        Event::fake();

        $user = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()->create();
        $message = ChatMessage::factory()->forThread($thread)->fromUser($user)->create();

        $message->delete();
        $message->restore();

        expect(ChatMessage::find($message->id))->not->toBeNull();
    });
});

describe('ChatMessage casts', function () {
    it('casts metadata to array', function () {
        Event::fake();

        $user = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()->create();
        $message = ChatMessage::factory()->forThread($thread)->fromUser($user)->withMetadata([
            'key' => 'value',
            'nested' => ['data' => true],
        ])->create();

        expect($message->metadata)->toBeArray();
        expect($message->metadata['key'])->toBe('value');
        expect($message->metadata['nested']['data'])->toBeTrue();
    });

    it('casts is_internal to boolean', function () {
        Event::fake();

        $user = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()->create();
        $message = ChatMessage::factory()->forThread($thread)->fromUser($user)->internal()->withFullAccess()->create();

        expect($message->is_internal)->toBeBool();
        expect($message->is_internal)->toBeTrue();
    });

    it('casts edited_at to datetime', function () {
        Event::fake();

        $user = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()->create();
        $message = ChatMessage::factory()->forThread($thread)->fromUser($user)->edited()->create();

        expect($message->edited_at)->toBeInstanceOf(\DateTimeInterface::class);
    });
});
