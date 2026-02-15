<?php

use App\Models\User;
use App\Notifications\NewAccountCreated;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
});

it('sends notification when customer account is created', function () {
    $admin = User::factory()->internal()->create();

    $customer = User::factory()->customer()->create();

    Notification::assertSentTo($admin, NewAccountCreated::class, function ($notification) use ($customer, $admin) {
        $data = $notification->toArray($admin);

        return $data['type'] === 'new_account_created'
            && $data['user_id'] === $customer->id
            && $data['account_type'] === 'customer';
    });
});

it('sends notification when crew account is created', function () {
    $admin = User::factory()->internal()->create();

    $crew = User::factory()->crew()->create();

    Notification::assertSentTo($admin, NewAccountCreated::class, function ($notification) use ($crew, $admin) {
        $data = $notification->toArray($admin);

        return $data['account_type'] === 'crew'
            && $data['user_name'] === $crew->name;
    });
});

it('sends notification when agent account is created', function () {
    $admin = User::factory()->internal()->create();

    $agent = User::factory()->agent()->create();

    Notification::assertSentTo($admin, NewAccountCreated::class, function ($notification) use ($agent, $admin) {
        $data = $notification->toArray($admin);

        return $data['account_type'] === 'agent'
            && $data['user_name'] === $agent->name;
    });
});

it('does not send notification for internal users', function () {
    $admin = User::factory()->internal()->create();

    User::factory()->internal()->create();

    Notification::assertNotSentTo($admin, NewAccountCreated::class);
});

it('uses mail database and broadcast channels', function () {
    $notification = new NewAccountCreated(User::factory()->make(['type' => 'customer']));

    expect($notification->via(new stdClass))->toBe(['mail', 'database', 'broadcast']);
});

it('includes user details in notification data', function () {
    $admin = User::factory()->internal()->create();

    $customer = User::factory()->customer()->create([
        'name' => 'Jane Doe',
        'email' => 'jane@example.com',
    ]);

    Notification::assertSentTo($admin, NewAccountCreated::class, function ($notification) use ($customer, $admin) {
        $data = $notification->toArray($admin);

        return $data['user_name'] === 'Jane Doe'
            && $data['user_email'] === 'jane@example.com'
            && $data['account_type'] === 'customer'
            && $data['user_id'] === $customer->id;
    });
});
