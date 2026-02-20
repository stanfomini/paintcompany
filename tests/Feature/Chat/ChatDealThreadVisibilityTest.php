<?php

use App\Models\ChatThread;
use App\Models\Deal;
use App\Models\User;
use App\Services\ChatService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    // Seed the permissions
    (new RolesAndPermissionsSeeder)->run();

    // Create a user with a role that has chat.access but NOT chat.view_deal_chats
    $this->restrictedUser = User::factory()->internal()->create();
    $this->restrictedUser->assignRole('door_to_door');

    // Create a user with full access (admin has chat.view_deal_chats)
    $this->fullUser = User::factory()->internal()->create();
    $this->fullUser->assignRole('admin');

    // Create user with no roles (backward compat)
    $this->noRoleUser = User::factory()->internal()->create();
    $this->noRoleUser->givePermissionTo('chat.access');

    // Create a deal thread
    $deal = Deal::factory()->create();
    $this->dealThread = ChatThread::create([
        'deal_id' => $deal->id,
        'type' => 'deal',
        'subject' => 'Test Deal Chat',
        'last_message_at' => now(),
    ]);

    // Create a direct thread
    $this->directThread = ChatThread::create([
        'type' => 'direct',
        'subject' => 'Direct Message',
        'last_message_at' => now(),
    ]);

    // Add all users as participants in both threads
    foreach ([$this->restrictedUser, $this->fullUser, $this->noRoleUser] as $user) {
        $this->dealThread->addParticipant($user);
        $this->directThread->addParticipant($user);
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('shows deal threads to users with chat.view_deal_chats permission', function () {
    Livewire::actingAs($this->fullUser)
        ->test('chat.thread-list')
        ->assertSee('Test Deal Chat');
});

it('hides deal threads from users without chat.view_deal_chats permission', function () {
    Livewire::actingAs($this->restrictedUser)
        ->test('chat.thread-list')
        ->assertDontSee('Test Deal Chat')
        ->assertSee('Direct Message');
});

it('still shows direct threads to users without deal chat permission', function () {
    Livewire::actingAs($this->restrictedUser)
        ->test('chat.thread-list')
        ->assertSee('Direct Message');
});

it('shows deal threads to users without any roles for backward compatibility', function () {
    Livewire::actingAs($this->noRoleUser)
        ->test('chat.thread-list')
        ->assertSee('Test Deal Chat')
        ->assertSee('Direct Message');
});

it('returns 403 when accessing deal thread directly without permission', function () {
    Livewire::actingAs($this->restrictedUser)
        ->test('chat.thread-view', ['thread' => $this->dealThread])
        ->assertForbidden();
});

it('excludes deal thread unread count for restricted users', function () {
    // Add an unread message to the deal thread
    $this->dealThread->messages()->create([
        'user_id' => $this->fullUser->id,
        'content' => 'Test message in deal',
        'type' => 'text',
    ]);

    $chatService = app(ChatService::class);
    $unreadCount = $chatService->getUnreadCountForUser($this->restrictedUser);

    // Should be 0 - the deal thread message should not count
    expect($unreadCount)->toBe(0);
});

it('includes deal thread unread count for users with permission', function () {
    // Add an unread message to the deal thread
    $this->dealThread->messages()->create([
        'user_id' => $this->restrictedUser->id,
        'content' => 'Test message in deal',
        'type' => 'text',
    ]);

    $chatService = app(ChatService::class);
    $unreadCount = $chatService->getUnreadCountForUser($this->fullUser);

    expect($unreadCount)->toBeGreaterThanOrEqual(1);
});

it('filters deal threads via scopeVisibleTo for restricted users', function () {
    $threads = ChatThread::query()
        ->forUser($this->restrictedUser)
        ->visibleTo($this->restrictedUser)
        ->get();

    expect($threads->pluck('type')->toArray())->not->toContain('deal');
    expect($threads->pluck('type')->toArray())->toContain('direct');
});

it('prevents viewing deal thread via policy for restricted users', function () {
    expect($this->restrictedUser->can('view', $this->dealThread))->toBeFalse();
    expect($this->fullUser->can('view', $this->dealThread))->toBeTrue();
    expect($this->noRoleUser->can('view', $this->dealThread))->toBeTrue();
});
