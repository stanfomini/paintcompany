<?php

use App\Listeners\NotifyOnCustomerLogin;
use App\Models\Customer;
use App\Models\User;
use App\Notifications\CustomerPortalLogin;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    Cache::flush();
});

it('sends notification when customer logs in', function () {
    $assignee = User::factory()->internal()->create();
    $customerUser = User::factory()->customer()->create();
    Customer::factory()->assignedTo($assignee)->create(['user_id' => $customerUser->id]);

    $listener = new NotifyOnCustomerLogin;
    $listener->handle(new Login('customer', $customerUser, false));

    Notification::assertSentTo($assignee, CustomerPortalLogin::class);
});

it('does not send notification for internal user login', function () {
    $user = User::factory()->internal()->create();

    $listener = new NotifyOnCustomerLogin;
    $listener->handle(new Login('web', $user, false));

    Notification::assertNothingSent();
});

it('does not send notification for agent user login', function () {
    $user = User::factory()->agent()->create();

    $listener = new NotifyOnCustomerLogin;
    $listener->handle(new Login('agent', $user, false));

    Notification::assertNothingSent();
});

it('does not send notification for crew user login', function () {
    $user = User::factory()->crew()->create();

    $listener = new NotifyOnCustomerLogin;
    $listener->handle(new Login('crew', $user, false));

    Notification::assertNothingSent();
});

it('throttles notification for 8 hours', function () {
    $assignee = User::factory()->internal()->create();
    $customerUser = User::factory()->customer()->create();
    Customer::factory()->assignedTo($assignee)->create(['user_id' => $customerUser->id]);

    $listener = new NotifyOnCustomerLogin;
    $listener->handle(new Login('customer', $customerUser, false));
    $listener->handle(new Login('customer', $customerUser, false));
    $listener->handle(new Login('customer', $customerUser, false));

    Notification::assertSentToTimes($assignee, CustomerPortalLogin::class, 1);
});

it('re-sends notification after throttle expires', function () {
    $assignee = User::factory()->internal()->create();
    $customerUser = User::factory()->customer()->create();
    Customer::factory()->assignedTo($assignee)->create(['user_id' => $customerUser->id]);

    $listener = new NotifyOnCustomerLogin;
    $listener->handle(new Login('customer', $customerUser, false));

    Cache::forget("customer_login_notify:{$customerUser->id}");

    $listener->handle(new Login('customer', $customerUser, false));

    Notification::assertSentToTimes($assignee, CustomerPortalLogin::class, 2);
});

it('does not send notification when customer has no assignee', function () {
    $customerUser = User::factory()->customer()->create();
    Customer::factory()->create(['user_id' => $customerUser->id, 'assigned_to' => null]);

    $listener = new NotifyOnCustomerLogin;
    $listener->handle(new Login('customer', $customerUser, false));

    Notification::assertNothingSent();
});

it('includes correct data in notification', function () {
    $assignee = User::factory()->internal()->create();
    $customerUser = User::factory()->customer()->create();
    $customer = Customer::factory()->assignedTo($assignee)->create(['user_id' => $customerUser->id]);

    $notification = new CustomerPortalLogin($customerUser);
    $data = $notification->toArray($assignee);

    expect($data)->toHaveKey('type', 'customer_portal_login')
        ->toHaveKey('customer_user_id', $customerUser->id)
        ->toHaveKey('customer_id', $customer->id);
});

it('uses mail database and broadcast channels', function () {
    $customerUser = User::factory()->customer()->create();
    $notification = new CustomerPortalLogin($customerUser);

    expect($notification->via(new \stdClass))->toBe(['mail', 'database', 'broadcast']);
});
