<?php

use App\Models\ChatThread;
use App\Models\User;
use Livewire\Livewire;

it('renders for internal users with chat access', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('chat.chat-dock')
        ->assertStatus(200);
});

it('denies access to users without chat.access permission', function () {
    $user = User::factory()->customer()->create();

    Livewire::actingAs($user, 'customer')
        ->test('chat.chat-dock')
        ->assertForbidden();
});

it('can open a thread', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $other = User::factory()->internal()->withFullAccess()->create();

    $thread = ChatThread::factory()
        ->direct()
        ->betweenUsers($user, $other)
        ->create();

    Livewire::actingAs($user)
        ->test('chat.chat-dock')
        ->call('openThread', $thread->id)
        ->assertSet('openThreadIds', [$thread->id])
        ->assertSet('pickerOpen', false);
});

it('can close a thread', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $other = User::factory()->internal()->withFullAccess()->create();

    $thread = ChatThread::factory()
        ->direct()
        ->betweenUsers($user, $other)
        ->create();

    Livewire::actingAs($user)
        ->test('chat.chat-dock')
        ->call('openThread', $thread->id)
        ->assertSet('openThreadIds', [$thread->id])
        ->call('closeThread', $thread->id)
        ->assertSet('openThreadIds', []);
});

it('can minimize a thread', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $other = User::factory()->internal()->withFullAccess()->create();

    $thread = ChatThread::factory()
        ->direct()
        ->betweenUsers($user, $other)
        ->create();

    Livewire::actingAs($user)
        ->test('chat.chat-dock')
        ->call('openThread', $thread->id)
        ->call('minimizeThread', $thread->id)
        ->assertSet('openThreadIds', [])
        ->assertSet('minimizedIds', [$thread->id]);
});

it('can restore a minimized thread', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $other = User::factory()->internal()->withFullAccess()->create();

    $thread = ChatThread::factory()
        ->direct()
        ->betweenUsers($user, $other)
        ->create();

    Livewire::actingAs($user)
        ->test('chat.chat-dock')
        ->call('openThread', $thread->id)
        ->call('minimizeThread', $thread->id)
        ->assertSet('minimizedIds', [$thread->id])
        ->call('restoreThread', $thread->id)
        ->assertSet('openThreadIds', [$thread->id])
        ->assertSet('minimizedIds', []);
});

it('auto-minimizes oldest window when exceeding max of 4', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $other = User::factory()->internal()->withFullAccess()->create();

    $threads = collect();
    for ($i = 0; $i < 5; $i++) {
        $threads->push(
            ChatThread::factory()
                ->direct()
                ->betweenUsers($user, $other)
                ->create()
        );
    }

    $component = Livewire::actingAs($user)
        ->test('chat.chat-dock');

    // Open 4 threads
    foreach ($threads->take(4) as $thread) {
        $component->call('openThread', $thread->id);
    }

    expect($component->get('openThreadIds'))->toHaveCount(4);
    expect($component->get('minimizedIds'))->toHaveCount(0);

    // Open 5th thread - should auto-minimize the 1st
    $component->call('openThread', $threads[4]->id);

    expect($component->get('openThreadIds'))->toHaveCount(4);
    expect($component->get('minimizedIds'))->toHaveCount(1);
    expect($component->get('minimizedIds'))->toContain($threads[0]->id);
    expect($component->get('openThreadIds'))->toContain($threads[4]->id);
});

it('does not open duplicate threads', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $other = User::factory()->internal()->withFullAccess()->create();

    $thread = ChatThread::factory()
        ->direct()
        ->betweenUsers($user, $other)
        ->create();

    Livewire::actingAs($user)
        ->test('chat.chat-dock')
        ->call('openThread', $thread->id)
        ->call('openThread', $thread->id)
        ->assertSet('openThreadIds', [$thread->id]);
});

it('rejects unauthorized thread IDs', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $other = User::factory()->internal()->withFullAccess()->create();

    $thread = ChatThread::factory()
        ->direct()
        ->betweenUsers(
            User::factory()->internal()->withFullAccess()->create(),
            $other,
        )
        ->create();

    Livewire::actingAs($user)
        ->test('chat.chat-dock')
        ->call('openThread', $thread->id)
        ->assertSet('openThreadIds', []);
});

it('rejects non-existent thread IDs', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('chat.chat-dock')
        ->call('openThread', 99999)
        ->assertSet('openThreadIds', []);
});

it('can toggle the thread picker', function () {
    $user = User::factory()->internal()->withFullAccess()->create();

    Livewire::actingAs($user)
        ->test('chat.chat-dock')
        ->assertSet('pickerOpen', false)
        ->call('togglePicker')
        ->assertSet('pickerOpen', true)
        ->call('togglePicker')
        ->assertSet('pickerOpen', false);
});

it('shows threads in the picker', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $other = User::factory()->internal()->withFullAccess()->create();

    $thread = ChatThread::factory()
        ->direct()
        ->withSubject('Project Discussion')
        ->betweenUsers($user, $other)
        ->create(['last_message_at' => now()]);

    $component = Livewire::actingAs($user)
        ->test('chat.chat-dock');

    expect($component->get('threads'))->toHaveCount(1);
});

it('close removes thread from both open and minimized', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $other = User::factory()->internal()->withFullAccess()->create();

    $thread1 = ChatThread::factory()
        ->direct()
        ->betweenUsers($user, $other)
        ->create();

    $thread2 = ChatThread::factory()
        ->direct()
        ->betweenUsers($user, $other)
        ->create();

    Livewire::actingAs($user)
        ->test('chat.chat-dock')
        ->call('openThread', $thread1->id)
        ->call('openThread', $thread2->id)
        ->call('minimizeThread', $thread2->id)
        ->assertSet('minimizedIds', [$thread2->id])
        ->call('closeThread', $thread2->id)
        ->assertSet('minimizedIds', []);
});

it('responds to open-chat-thread event', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $other = User::factory()->internal()->withFullAccess()->create();

    $thread = ChatThread::factory()
        ->direct()
        ->betweenUsers($user, $other)
        ->create();

    Livewire::actingAs($user)
        ->test('chat.chat-dock')
        ->dispatch('open-chat-thread', threadId: $thread->id)
        ->assertSet('openThreadIds', [$thread->id]);
});

it('can restore state from localStorage data', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $other = User::factory()->internal()->withFullAccess()->create();

    $thread1 = ChatThread::factory()
        ->direct()
        ->betweenUsers($user, $other)
        ->create();

    $thread2 = ChatThread::factory()
        ->direct()
        ->betweenUsers($user, $other)
        ->create();

    Livewire::actingAs($user)
        ->test('chat.chat-dock')
        ->call('restoreState', [$thread1->id], [$thread2->id])
        ->assertSet('openThreadIds', [$thread1->id])
        ->assertSet('minimizedIds', [$thread2->id]);
});

it('restoreState rejects invalid thread IDs', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $other = User::factory()->internal()->withFullAccess()->create();

    $validThread = ChatThread::factory()
        ->direct()
        ->betweenUsers($user, $other)
        ->create();

    // Thread user is NOT a participant of
    $invalidThread = ChatThread::factory()
        ->direct()
        ->betweenUsers(
            User::factory()->internal()->withFullAccess()->create(),
            $other,
        )
        ->create();

    Livewire::actingAs($user)
        ->test('chat.chat-dock')
        ->call('restoreState', [$validThread->id, $invalidThread->id, 99999], [])
        ->assertSet('openThreadIds', [$validThread->id])
        ->assertSet('minimizedIds', []);
});

it('opening a minimized thread restores it', function () {
    $user = User::factory()->internal()->withFullAccess()->create();
    $other = User::factory()->internal()->withFullAccess()->create();

    $thread = ChatThread::factory()
        ->direct()
        ->betweenUsers($user, $other)
        ->create();

    Livewire::actingAs($user)
        ->test('chat.chat-dock')
        ->call('openThread', $thread->id)
        ->call('minimizeThread', $thread->id)
        ->assertSet('minimizedIds', [$thread->id])
        ->call('openThread', $thread->id)
        ->assertSet('openThreadIds', [$thread->id])
        ->assertSet('minimizedIds', []);
});
