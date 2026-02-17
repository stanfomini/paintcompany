<?php

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

describe('MessageComposer component', function () {
    it('renders the message composer component', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();

        $thread = ChatThread::factory()
            ->betweenUsers($user, $otherUser)
            ->create();

        Livewire::actingAs($user)
            ->test('chat.message-composer', ['thread' => $thread])
            ->assertStatus(200)
            ->assertSee('Type a message');
    });

    it('can send a text message', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();

        $thread = ChatThread::factory()
            ->betweenUsers($user, $otherUser)
            ->create();

        Livewire::actingAs($user)
            ->test('chat.message-composer', ['thread' => $thread])
            ->set('content', 'Hello, this is a test message!')
            ->call('send')
            ->assertDispatched('message-sent')
            ->assertSet('content', '');

        expect($thread->messages()->where('content', 'Hello, this is a test message!')->exists())->toBeTrue();
    });

    it('validates message content is required', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();

        $thread = ChatThread::factory()
            ->betweenUsers($user, $otherUser)
            ->create();

        Livewire::actingAs($user)
            ->test('chat.message-composer', ['thread' => $thread])
            ->set('content', '')
            ->call('send')
            ->assertHasErrors(['content' => 'required']);
    });

    it('validates message content max length', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();

        $thread = ChatThread::factory()
            ->betweenUsers($user, $otherUser)
            ->create();

        Livewire::actingAs($user)
            ->test('chat.message-composer', ['thread' => $thread])
            ->set('content', str_repeat('a', 10001))
            ->call('send')
            ->assertHasErrors(['content' => 'max']);
    });

    it('internal users can send internal messages', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();

        $thread = ChatThread::factory()
            ->betweenUsers($user, $otherUser)
            ->create();

        Livewire::actingAs($user)
            ->test('chat.message-composer', ['thread' => $thread])
            ->set('content', 'Internal note for staff')
            ->set('isInternal', true)
            ->call('send')
            ->assertDispatched('message-sent');

        $message = $thread->messages()->where('content', 'Internal note for staff')->first();
        expect($message->is_internal)->toBeTrue();
    });

    it('non-internal users cannot send internal messages', function () {
        $customerUser = User::factory()->customer()->create();
        $internalUser = User::factory()->internal()->withFullAccess()->create();

        $thread = ChatThread::factory()
            ->betweenUsers($customerUser, $internalUser)
            ->create();

        Livewire::actingAs($customerUser)
            ->test('chat.message-composer', ['thread' => $thread])
            ->set('content', 'Trying to send internal')
            ->set('isInternal', true)
            ->call('send')
            ->assertDispatched('message-sent');

        // Message should NOT be internal even though they tried
        $message = $thread->messages()->where('content', 'Trying to send internal')->first();
        expect($message->is_internal)->toBeFalse();
    });

    it('shows internal toggle only for internal users', function () {
        $internalUser = User::factory()->internal()->withFullAccess()->create();
        $customerUser = User::factory()->customer()->create();

        $thread1 = ChatThread::factory()
            ->betweenUsers($internalUser, User::factory()->create())
            ->create();

        $thread2 = ChatThread::factory()
            ->betweenUsers($customerUser, User::factory()->internal()->withFullAccess()->create())
            ->create();

        // Internal user sees the toggle
        Livewire::actingAs($internalUser)
            ->test('chat.message-composer', ['thread' => $thread1])
            ->assertSee('Send as internal note');

        // Customer does not see the toggle
        Livewire::actingAs($customerUser)
            ->test('chat.message-composer', ['thread' => $thread2])
            ->assertDontSee('Send as internal note');
    });

    it('can send a reply to a message', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();

        $thread = ChatThread::factory()
            ->betweenUsers($user, $otherUser)
            ->create();

        $parentMessage = ChatMessage::factory()
            ->forThread($thread)
            ->fromUser($otherUser)
            ->create();

        Livewire::actingAs($user)
            ->test('chat.message-composer', ['thread' => $thread])
            ->dispatch('set-reply-to', messageId: $parentMessage->id)
            ->assertSet('replyToMessageId', $parentMessage->id)
            ->set('content', 'This is my reply')
            ->call('send')
            ->assertDispatched('message-sent')
            ->assertSet('replyToMessageId', null);

        $reply = $thread->messages()->where('content', 'This is my reply')->first();
        expect($reply->parent_id)->toBe($parentMessage->id);
    });

    it('can clear reply', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();

        $thread = ChatThread::factory()
            ->betweenUsers($user, $otherUser)
            ->create();

        $parentMessage = ChatMessage::factory()
            ->forThread($thread)
            ->fromUser($otherUser)
            ->create();

        Livewire::actingAs($user)
            ->test('chat.message-composer', ['thread' => $thread])
            ->dispatch('set-reply-to', messageId: $parentMessage->id)
            ->call('clearReply')
            ->assertSet('replyToMessageId', null)
            ->assertDispatched('cancel-reply');
    });

    it('can upload and send attachments', function () {
        Storage::fake();

        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();

        $thread = ChatThread::factory()
            ->betweenUsers($user, $otherUser)
            ->create();

        $file = UploadedFile::fake()->image('photo.jpg');

        Livewire::actingAs($user)
            ->test('chat.message-composer', ['thread' => $thread])
            ->set('content', 'Check out this photo')
            ->set('attachments', [$file])
            ->call('send')
            ->assertDispatched('message-sent');

        $message = $thread->messages()->where('content', 'Check out this photo')->first();
        expect($message)->not->toBeNull();
        expect($message->attachments)->toHaveCount(1);
    });

    it('can remove attachments before sending', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();

        $thread = ChatThread::factory()
            ->betweenUsers($user, $otherUser)
            ->create();

        $file1 = UploadedFile::fake()->image('photo1.jpg');
        $file2 = UploadedFile::fake()->image('photo2.jpg');

        Livewire::actingAs($user)
            ->test('chat.message-composer', ['thread' => $thread])
            ->set('attachments', [$file1, $file2])
            ->call('removeAttachment', 0)
            ->assertCount('attachments', 1);
    });

    it('resets form after sending', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();

        $thread = ChatThread::factory()
            ->betweenUsers($user, $otherUser)
            ->create();

        Livewire::actingAs($user)
            ->test('chat.message-composer', ['thread' => $thread])
            ->set('content', 'Test message')
            ->set('isInternal', true)
            ->call('send')
            ->assertSet('content', '')
            ->assertSet('isInternal', false)
            ->assertSet('replyToMessageId', null)
            ->assertSet('attachments', []);
    });

    it('shows reply indicator when replying', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create(['name' => 'John Smith']);

        $thread = ChatThread::factory()
            ->betweenUsers($user, $otherUser)
            ->create();

        $parentMessage = ChatMessage::factory()
            ->forThread($thread)
            ->fromUser($otherUser)
            ->withContent('Original message content')
            ->create();

        Livewire::actingAs($user)
            ->test('chat.message-composer', ['thread' => $thread])
            ->dispatch('set-reply-to', messageId: $parentMessage->id)
            ->assertSee('Replying to John Smith')
            ->assertSee('Original message content');
    });
});
