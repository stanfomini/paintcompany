<?php

use App\Channels\WebPushChannel;
use App\Models\ChatThread;
use App\Models\EmailAccount;
use App\Models\EmailLabel;
use App\Models\EmailMessage;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    (new RolesAndPermissionsSeeder)->run();
});

it('includes database notification count in badge count', function () {
    $user = User::factory()->internal()->create();
    $user->assignRole('admin');

    // Create some unread database notifications
    $user->notifications()->create([
        'id' => Str::uuid(),
        'type' => 'App\Notifications\TestNotification',
        'data' => ['message' => 'Test 1'],
    ]);
    $user->notifications()->create([
        'id' => Str::uuid(),
        'type' => 'App\Notifications\TestNotification',
        'data' => ['message' => 'Test 2'],
    ]);

    $channel = new WebPushChannel;
    $badgeCount = tap(new ReflectionMethod($channel, 'calculateBadgeCount'), fn ($m) => $m->setAccessible(true))->invoke($channel, $user);

    expect($badgeCount)->toBeGreaterThanOrEqual(2);
});

it('includes chat unread count in badge count', function () {
    $user = User::factory()->internal()->create();
    $user->assignRole('admin');

    $otherUser = User::factory()->internal()->create();

    // Create a thread with an unread message
    $thread = ChatThread::factory()->direct()->betweenUsers($user, $otherUser)->create([
        'last_message_at' => now(),
    ]);

    $thread->messages()->create([
        'user_id' => $otherUser->id,
        'content' => 'Hello!',
        'type' => 'text',
    ]);

    $channel = new WebPushChannel;
    $badgeCount = tap(new ReflectionMethod($channel, 'calculateBadgeCount'), fn ($m) => $m->setAccessible(true))->invoke($channel, $user);

    expect($badgeCount)->toBeGreaterThanOrEqual(1);
});

it('includes email unread count from all accounts in badge count', function () {
    $user = User::factory()->internal()->create();
    $user->assignRole('admin');

    // Personal email account
    $personalAccount = EmailAccount::factory()->create(['user_id' => $user->id]);
    $personalLabel = EmailLabel::factory()->system()->create([
        'email_account_id' => $personalAccount->id,
        'name' => 'Inbox',
        'slug' => 'inbox',
    ]);

    $personalMessage = EmailMessage::factory()->create([
        'email_account_id' => $personalAccount->id,
        'is_read' => false,
    ]);
    $personalMessage->labels()->attach($personalLabel->id);

    // Shared email account
    $sharedAccount = EmailAccount::factory()->shared()->create();
    $sharedLabel = EmailLabel::factory()->system()->create([
        'email_account_id' => $sharedAccount->id,
        'name' => 'Inbox',
        'slug' => 'inbox',
    ]);

    $user->sharedEmailAccounts()->attach($sharedAccount->id, [
        'can_send' => true,
        'receives_notifications' => true,
    ]);

    $sharedMessages = EmailMessage::factory()->count(2)->create([
        'email_account_id' => $sharedAccount->id,
        'is_read' => false,
    ]);
    foreach ($sharedMessages as $msg) {
        $msg->labels()->attach($sharedLabel->id);
    }

    $channel = new WebPushChannel;
    $badgeCount = tap(new ReflectionMethod($channel, 'calculateBadgeCount'), fn ($m) => $m->setAccessible(true))->invoke($channel, $user);

    // Should include 1 personal + 2 shared = 3 email unreads minimum
    expect($badgeCount)->toBeGreaterThanOrEqual(3);
});

it('combines all unread sources in badge count', function () {
    $user = User::factory()->internal()->create();
    $user->assignRole('admin');
    $otherUser = User::factory()->internal()->create();

    // 1 database notification
    $user->notifications()->create([
        'id' => Str::uuid(),
        'type' => 'App\Notifications\TestNotification',
        'data' => ['message' => 'Test'],
    ]);

    // 1 chat unread
    $thread = ChatThread::factory()->direct()->betweenUsers($user, $otherUser)->create([
        'last_message_at' => now(),
    ]);
    $thread->messages()->create([
        'user_id' => $otherUser->id,
        'content' => 'Hello!',
        'type' => 'text',
    ]);

    // 1 email unread
    $account = EmailAccount::factory()->create(['user_id' => $user->id]);
    $label = EmailLabel::factory()->system()->create([
        'email_account_id' => $account->id,
        'name' => 'Inbox',
        'slug' => 'inbox',
    ]);
    $emailMsg = EmailMessage::factory()->create([
        'email_account_id' => $account->id,
        'is_read' => false,
    ]);
    $emailMsg->labels()->attach($label->id);

    $channel = new WebPushChannel;
    $badgeCount = tap(new ReflectionMethod($channel, 'calculateBadgeCount'), fn ($m) => $m->setAccessible(true))->invoke($channel, $user);

    // At least 3: 1 notification + 1 chat + 1 email
    expect($badgeCount)->toBeGreaterThanOrEqual(3);
});
