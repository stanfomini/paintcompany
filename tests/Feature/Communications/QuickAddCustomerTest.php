<?php

use App\Models\Customer;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\CustomerPreviewNotification;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function () {
    $this->actingAs(User::factory()->internal()->create());
});

it('does not send invitation email for customers', function () {
    Livewire::test('communications.quick-add-customer')
        ->set('first_name', 'No')
        ->set('last_name', 'Invite')
        ->set('email', 'noinvite@customer.com')
        ->set('phone', '3035551234')
        ->set('estimate_preference', 'virtual')
        ->call('submitQuickAdd');

    expect(Invitation::where('email', 'noinvite@customer.com')->count())->toBe(0);
});

it('sends CustomerPreviewNotification email', function () {
    Notification::fake();

    Livewire::test('communications.quick-add-customer')
        ->set('first_name', 'Preview')
        ->set('last_name', 'Customer')
        ->set('email', 'preview@customer.com')
        ->set('phone', '3035555678')
        ->set('estimate_preference', 'virtual')
        ->call('submitQuickAdd');

    $customer = Customer::where('email', 'preview@customer.com')->first();

    Notification::assertSentTo(
        $customer,
        CustomerPreviewNotification::class,
    );
});

it('shows success modal after creation', function () {
    Livewire::test('communications.quick-add-customer')
        ->set('first_name', 'Success')
        ->set('last_name', 'Test')
        ->set('email', 'success@customer.com')
        ->set('estimate_preference', 'virtual')
        ->call('submitQuickAdd')
        ->assertSet('showSuccessModal', true);
});
