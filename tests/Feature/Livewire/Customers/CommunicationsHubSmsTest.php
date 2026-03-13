<?php

use App\Models\ChatThread;
use App\Models\Customer;
use App\Models\User;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Communications Hub - SMS Tab', function () {
    it('shows the sms tab', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();

        Livewire::actingAs($user)
            ->test('customers.communications-hub', ['customer' => $customer])
            ->assertSee('SMS')
            ->assertStatus(200);
    });

    it('shows empty state when no sms threads exist', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();

        Livewire::actingAs($user)
            ->test('customers.communications-hub', ['customer' => $customer])
            ->call('setTab', 'sms')
            ->assertSee('No SMS conversations with this customer.');
    });

    it('shows send sms button in empty state when customer has phone', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create(['phone' => '303-555-1234']);

        Livewire::actingAs($user)
            ->test('customers.communications-hub', ['customer' => $customer])
            ->call('setTab', 'sms')
            ->assertSee('Send SMS');
    });

    it('displays sms threads in the sms tab', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create(['phone' => '303-555-1234']);

        $thread = ChatThread::factory()
            ->smsInbound()
            ->forCustomer($customer)
            ->withSubject('SMS from +13035551234')
            ->create(['last_message_at' => now()]);

        Livewire::actingAs($user)
            ->test('customers.communications-hub', ['customer' => $customer])
            ->call('setTab', 'sms')
            ->assertSee('SMS from +13035551234');
    });

    it('includes source key for sms threads in all items', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create(['phone' => '303-555-1234']);

        ChatThread::factory()
            ->smsInbound()
            ->forCustomer($customer)
            ->withSubject('SMS from +13035551234')
            ->create(['last_message_at' => now()]);

        $component = Livewire::actingAs($user)
            ->test('customers.communications-hub', ['customer' => $customer]);

        // Access the computed property directly
        $allItems = $component->instance()->allItems;
        $smsItem = $allItems->firstWhere('source', 'sms_inbound');

        expect($smsItem)->not->toBeNull();
        expect($smsItem['source'])->toBe('sms_inbound');
    });

    it('shows send sms button in header when customer has phone', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create(['phone' => '303-555-1234']);

        Livewire::actingAs($user)
            ->test('customers.communications-hub', ['customer' => $customer])
            ->assertSee('Send SMS');
    });

    it('does not show send sms button in header when customer has no phone', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create(['phone' => null]);

        // Switch to a tab without "Send SMS" in the empty state
        Livewire::actingAs($user)
            ->test('customers.communications-hub', ['customer' => $customer])
            ->call('setTab', 'messenger')
            ->assertDontSee('Send SMS');
    });
});
