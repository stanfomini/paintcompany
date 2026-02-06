<?php

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\User;
use Livewire\Livewire;

describe('ThreadList component', function () {
    it('renders the thread list component for internal users', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('chat.thread-list')
            ->assertStatus(200)
            ->assertSee('Messages');
    });

    it('prevents access for unauthenticated users', function () {
        Livewire::test('chat.thread-list')
            ->assertForbidden();
    });

    it('displays threads the user is a participant in', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();

        $thread = ChatThread::factory()
            ->withSubject('Test Thread Subject')
            ->betweenUsers($user, $otherUser)
            ->create();

        Livewire::actingAs($user)
            ->test('chat.thread-list')
            ->assertSee('Test Thread Subject');
    });

    it('does not display threads the user is not a participant in', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser1 = User::factory()->internal()->withFullAccess()->create();
        $otherUser2 = User::factory()->internal()->withFullAccess()->create();

        $thread = ChatThread::factory()
            ->withSubject('Other Thread')
            ->betweenUsers($otherUser1, $otherUser2)
            ->create();

        Livewire::actingAs($user)
            ->test('chat.thread-list')
            ->assertDontSee('Other Thread');
    });

    it('can search threads by display name', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create(['name' => 'John Smith']);

        $thread = ChatThread::factory()
            ->direct()
            ->withSubject('Project Discussion')
            ->betweenUsers($user, $otherUser)
            ->create();

        Livewire::actingAs($user)
            ->test('chat.thread-list')
            ->set('search', 'Project')
            ->assertSee('Project Discussion');
    });

    it('can filter by unread threads', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();

        // Create thread with unread message
        $thread = ChatThread::factory()
            ->withSubject('Unread Thread')
            ->betweenUsers($user, $otherUser)
            ->create();

        ChatMessage::factory()
            ->forThread($thread)
            ->fromUser($otherUser)
            ->create();

        Livewire::actingAs($user)
            ->test('chat.thread-list')
            ->set('filter', 'unread')
            ->assertSee('Unread Thread');
    });

    it('can filter by archived threads', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();

        $archivedThread = ChatThread::factory()
            ->withSubject('Archived Thread')
            ->archived()
            ->betweenUsers($user, $otherUser)
            ->create();

        $activeThread = ChatThread::factory()
            ->withSubject('Active Thread')
            ->betweenUsers($user, $otherUser)
            ->create();

        Livewire::actingAs($user)
            ->test('chat.thread-list')
            ->set('filter', 'archived')
            ->assertSee('Archived Thread')
            ->assertDontSee('Active Thread');
    });

    it('can archive a thread', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();

        $thread = ChatThread::factory()
            ->betweenUsers($user, $otherUser)
            ->create();

        expect($thread->is_archived)->toBeFalse();

        Livewire::actingAs($user)
            ->test('chat.thread-list')
            ->call('archiveThread', $thread->id)
            ->assertDispatched('thread-archived');

        expect($thread->fresh()->is_archived)->toBeTrue();
    });

    it('can unarchive a thread', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();

        $thread = ChatThread::factory()
            ->archived()
            ->betweenUsers($user, $otherUser)
            ->create();

        expect($thread->is_archived)->toBeTrue();

        Livewire::actingAs($user)
            ->test('chat.thread-list')
            ->call('unarchiveThread', $thread->id)
            ->assertDispatched('thread-unarchived');

        expect($thread->fresh()->is_archived)->toBeFalse();
    });

    it('prevents archiving threads the user is not a participant of', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser1 = User::factory()->internal()->withFullAccess()->create();
        $otherUser2 = User::factory()->internal()->withFullAccess()->create();

        $thread = ChatThread::factory()
            ->betweenUsers($otherUser1, $otherUser2)
            ->create();

        Livewire::actingAs($user)
            ->test('chat.thread-list')
            ->call('archiveThread', $thread->id)
            ->assertForbidden();
    });

    it('shows empty state when no threads exist', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('chat.thread-list')
            ->assertSee('No conversations found');
    });

    it('shows last message preview', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();

        $thread = ChatThread::factory()
            ->withSubject('Test Thread')
            ->betweenUsers($user, $otherUser)
            ->create();

        ChatMessage::factory()
            ->forThread($thread)
            ->fromUser($otherUser)
            ->withContent('Hello, this is a test message!')
            ->create();

        Livewire::actingAs($user)
            ->test('chat.thread-list')
            ->assertSee('Hello, this is a test message!');
    });

    it('shows unread count badge', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();

        $thread = ChatThread::factory()
            ->betweenUsers($user, $otherUser)
            ->create();

        // Create multiple unread messages
        ChatMessage::factory()
            ->count(3)
            ->forThread($thread)
            ->fromUser($otherUser)
            ->create();

        Livewire::actingAs($user)
            ->test('chat.thread-list')
            ->assertSeeHtml('3');
    });

    it('has correct default filter value', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('chat.thread-list')
            ->assertSet('filter', 'all')
            ->assertSet('search', '');
    });

    it('refreshes threads on message-sent event', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('chat.thread-list')
            ->dispatch('message-sent')
            ->assertStatus(200);
    });
});
