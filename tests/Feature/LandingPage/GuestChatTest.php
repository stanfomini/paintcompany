<?php

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\CompanySetting;
use App\Models\GuestChatSession;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\NewSupportChatNotification;
use App\Services\ChatService;
use App\Services\InvitationService;
use App\Services\ReferralService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->internalUser = User::factory()->internal()->create();
});

it('allows guest to start a chat session with email', function () {
    Notification::fake();

    Livewire::test('public.guest-chat')
        ->set('email', 'visitor@example.com')
        ->set('name', 'Jane Doe')
        ->call('startChat')
        ->assertSet('sessionId', fn ($value) => $value !== null);

    $session = GuestChatSession::where('email', 'visitor@example.com')->first();
    expect($session)->not->toBeNull()
        ->and($session->name)->toBe('Jane Doe')
        ->and($session->status)->toBe('active')
        ->and($session->uuid)->not->toBeEmpty();
});

it('creates a support chat thread when starting chat', function () {
    Notification::fake();

    Livewire::test('public.guest-chat')
        ->set('email', 'visitor@example.com')
        ->set('name', 'Test User')
        ->call('startChat');

    $session = GuestChatSession::where('email', 'visitor@example.com')->first();
    expect($session->thread)->not->toBeNull()
        ->and($session->thread->type)->toBe(ChatThread::TYPE_SUPPORT)
        ->and($session->thread->subject)->toBe('Support: Test User');

    // Internal users should be participants
    expect($session->thread->hasParticipant($this->internalUser))->toBeTrue();
});

it('allows guest to send a message', function () {
    Notification::fake();

    $component = Livewire::test('public.guest-chat')
        ->set('email', 'visitor@example.com')
        ->set('name', 'Sender')
        ->call('startChat');

    $component->set('newMessage', 'Hello, I need help with painting!')
        ->call('sendMessage')
        ->assertSet('newMessage', '');

    $session = GuestChatSession::where('email', 'visitor@example.com')->first();
    $messages = ChatMessage::where('thread_id', $session->chat_thread_id)->get();

    expect($messages)->toHaveCount(1)
        ->and($messages->first()->content)->toBe('Hello, I need help with painting!')
        ->and($messages->first()->user_id)->toBeNull()
        ->and($messages->first()->metadata['guest_email'])->toBe('visitor@example.com');
});

it('notifies internal users when new support chat starts', function () {
    Notification::fake();

    Livewire::test('public.guest-chat')
        ->set('email', 'visitor@example.com')
        ->set('name', 'Notified User')
        ->call('startChat');

    Notification::assertSentTo($this->internalUser, NewSupportChatNotification::class);
});

it('links guest session to user when account is created with same email', function () {
    Notification::fake();

    // Start a guest chat session
    $thread = ChatThread::factory()->support()->create(['last_message_at' => now()]);
    $thread->addParticipant($this->internalUser);

    $session = GuestChatSession::factory()->create([
        'email' => 'guest@example.com',
        'chat_thread_id' => $thread->id,
        'status' => 'active',
    ]);

    // Simulate creating a lead via the referral service (which creates customer)
    $referralService = app(ReferralService::class);
    $result = $referralService->createFromLeadForm([
        'first_name' => 'Guest',
        'last_name' => 'User',
        'email' => 'guest@example.com',
        'phone' => '555-0100',
    ]);

    $session->refresh();
    expect($session->status)->toBe('converted');
});

it('detects business hours correctly', function () {
    CompanySetting::clearInstanceCache();
    $settings = CompanySetting::getInstance();
    $settings->update([
        'business_hours_start' => '09:00',
        'business_hours_end' => '17:00',
        'business_days' => [1, 2, 3, 4, 5],
        'timezone' => 'UTC',
    ]);
    CompanySetting::clearInstanceCache();

    // Wednesday 2026-02-25 at 12:00 UTC (within business hours)
    $this->travelTo(\Carbon\Carbon::create(2026, 2, 25, 12, 0, 0, 'UTC'));
    $settings = CompanySetting::getInstance();
    expect($settings->isWithinBusinessHours())->toBeTrue();

    CompanySetting::clearInstanceCache();

    // Wednesday 2026-02-25 at 20:00 UTC (outside business hours)
    $this->travelTo(\Carbon\Carbon::create(2026, 2, 25, 20, 0, 0, 'UTC'));
    $settings = CompanySetting::getInstance();
    expect($settings->isWithinBusinessHours())->toBeFalse();

    CompanySetting::clearInstanceCache();

    // Saturday 2026-02-28 at 12:00 UTC (weekend)
    $this->travelTo(\Carbon\Carbon::create(2026, 2, 28, 12, 0, 0, 'UTC'));
    $settings = CompanySetting::getInstance();
    expect($settings->isWithinBusinessHours())->toBeFalse();
});

it('persists session via cookie UUID', function () {
    Notification::fake();

    // Start a chat session
    $component = Livewire::test('public.guest-chat')
        ->set('email', 'cookie@example.com')
        ->call('startChat');

    $session = GuestChatSession::where('email', 'cookie@example.com')->first();
    expect($session)->not->toBeNull()
        ->and($session->uuid)->not->toBeEmpty();

    // Simulate a new request with the UUID cookie
    $newComponent = Livewire::withCookies(['guest_chat_uuid' => $session->uuid])
        ->test('public.guest-chat');

    $newComponent->assertSet('sessionId', $session->id);
});

it('auto-unarchives support thread when guest sends a new message', function () {
    Notification::fake();
    Event::fake();

    $service = app(ChatService::class);

    // Create a support thread
    $thread = ChatThread::factory()->support()->create(['last_message_at' => now()]);
    $participant = $thread->addParticipant($this->internalUser);

    // Internal user archives the thread
    $participant->archive();
    expect($participant->fresh()->isArchived())->toBeTrue();

    // Guest session sends a new message
    $session = GuestChatSession::factory()->create([
        'email' => 'guest@example.com',
        'chat_thread_id' => $thread->id,
        'status' => 'active',
    ]);

    $service->sendGuestMessage($thread, 'Hello again!', [
        'guest_email' => 'guest@example.com',
    ]);

    // Thread should be auto-unarchived for the internal user
    expect($participant->fresh()->isArchived())->toBeFalse();

    // Message should exist
    expect(ChatMessage::where('thread_id', $thread->id)->count())->toBe(1);
});

it('per-user archiving only affects the archiving user', function () {
    Event::fake();

    $service = app(ChatService::class);
    $user2 = User::factory()->internal()->create();

    $thread = ChatThread::factory()->support()->create(['last_message_at' => now()]);
    $thread->addParticipant($this->internalUser);
    $thread->addParticipant($user2);

    // User 1 archives the thread
    $service->archiveThread($thread, $this->internalUser);

    // User 1 shouldn't see it
    $user1Threads = $service->getThreadsForUser($this->internalUser);
    expect($user1Threads)->toHaveCount(0);

    // User 2 should still see it
    $user2Threads = $service->getThreadsForUser($user2);
    expect($user2Threads)->toHaveCount(1);
});

it('backfills guest session user_id when invitation is accepted', function () {
    Notification::fake();

    // Create a support thread + guest session
    $thread = ChatThread::factory()->support()->create(['last_message_at' => now()]);
    $thread->addParticipant($this->internalUser);

    $session = GuestChatSession::factory()->create([
        'email' => 'newcustomer@example.com',
        'chat_thread_id' => $thread->id,
        'status' => 'active',
    ]);

    // Create an invitation for the same email
    $invitation = Invitation::factory()->forCustomer()->create([
        'email' => 'newcustomer@example.com',
        'inviter_id' => $this->internalUser->id,
    ]);

    // Accept the invitation (creates user + links customer)
    $invitationService = app(InvitationService::class);
    $user = $invitationService->acceptInvitation($invitation, [
        'name' => 'New Customer',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
    ]);

    $session->refresh();
    expect($session->user_id)->toBe($user->id)
        ->and($session->status)->toBe('converted');

    // User should be a participant in the support thread
    expect($thread->hasParticipant($user))->toBeTrue();
});
