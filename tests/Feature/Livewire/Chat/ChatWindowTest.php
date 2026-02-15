<?php

use App\Models\ChatThread;
use App\Models\User;
use Livewire\Livewire;

it('renders chat window for a participant', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $other = User::factory()->internal()->withFullAccess()->create();

    $thread = ChatThread::factory()
        ->direct()
        ->betweenUsers($user, $other)
        ->create(['subject' => 'Test Conversation']);

    Livewire::actingAs($user)
        ->test('chat.chat-window', ['threadId' => $thread->id])
        ->assertStatus(200)
        ->assertSee('Test Conversation');
});

it('denies access to non-participants', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $other = User::factory()->internal()->withFullAccess()->create();
    $outsider = User::factory()->internal()->withFullAccess()->create();

    $thread = ChatThread::factory()
        ->direct()
        ->betweenUsers($user, $other)
        ->create();

    Livewire::actingAs($outsider)
        ->test('chat.chat-window', ['threadId' => $thread->id])
        ->assertForbidden();
});

it('denies access to users without chat.access permission', function () {
    $user = User::factory()->customer()->create();
    $other = User::factory()->internal()->withFullAccess()->create();

    $thread = ChatThread::factory()
        ->direct()
        ->betweenUsers($user, $other)
        ->create();

    Livewire::actingAs($user, 'customer')
        ->test('chat.chat-window', ['threadId' => $thread->id])
        ->assertForbidden();
});

it('dispatches close-chat-window event', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $other = User::factory()->internal()->withFullAccess()->create();

    $thread = ChatThread::factory()
        ->direct()
        ->betweenUsers($user, $other)
        ->create();

    Livewire::actingAs($user)
        ->test('chat.chat-window', ['threadId' => $thread->id])
        ->call('close')
        ->assertDispatched('close-chat-window', threadId: $thread->id);
});

it('dispatches minimize-chat-window event', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $other = User::factory()->internal()->withFullAccess()->create();

    $thread = ChatThread::factory()
        ->direct()
        ->betweenUsers($user, $other)
        ->create();

    Livewire::actingAs($user)
        ->test('chat.chat-window', ['threadId' => $thread->id])
        ->call('minimize')
        ->assertDispatched('minimize-chat-window', threadId: $thread->id);
});

it('shows display name in header', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $other = User::factory()->internal()->withFullAccess()->create(['name' => 'John Smith']);

    $thread = ChatThread::factory()
        ->direct()
        ->betweenUsers($user, $other)
        ->create(['subject' => null]);

    $component = Livewire::actingAs($user)
        ->test('chat.chat-window', ['threadId' => $thread->id]);

    expect($component->get('displayName'))->toBe('John Smith');
});
