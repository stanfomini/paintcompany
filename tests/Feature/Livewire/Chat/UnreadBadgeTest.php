<?php

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\User;
use Livewire\Livewire;

it('renders for users with chat access', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('chat.unread-badge')
        ->assertStatus(200);
});

it('denies access to users without chat access permission', function () {
    $user = User::factory()->customer()->create();

    Livewire::actingAs($user, 'customer')
        ->test('chat.unread-badge')
        ->assertForbidden();
});

it('shows unread count when there are unread messages', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $sender = User::factory()->internal()->withFullAccess()->create();

    $thread = ChatThread::factory()
        ->direct()
        ->betweenUsers($user, $sender)
        ->create();

    ChatMessage::factory()
        ->forThread($thread)
        ->fromUser($sender)
        ->create();

    Livewire::actingAs($user)
        ->test('chat.unread-badge')
        ->assertSet('unreadCount', 1);
});

it('hides badge when no unread messages', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('chat.unread-badge')
        ->assertSet('unreadCount', 0);
});

it('refreshes count on event', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('chat.unread-badge')
        ->call('refreshCount')
        ->assertStatus(200);
});
