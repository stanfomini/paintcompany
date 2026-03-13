<?php

use App\Jobs\SendDirectSmsJob;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Send SMS Modal', function () {
    it('renders the modal component', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create(['phone' => '303-555-1234']);

        Livewire::actingAs($user)
            ->test('customers.send-sms-modal', ['customer' => $customer])
            ->assertStatus(200)
            ->assertSet('showModal', false)
            ->assertSet('messageBody', '');
    });

    it('opens the modal when event is dispatched', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create(['phone' => '303-555-1234']);

        Livewire::actingAs($user)
            ->test('customers.send-sms-modal', ['customer' => $customer])
            ->dispatch('open-send-sms-modal')
            ->assertSet('showModal', true);
    });

    it('does not open modal when customer has no phone', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create(['phone' => null]);

        Livewire::actingAs($user)
            ->test('customers.send-sms-modal', ['customer' => $customer])
            ->dispatch('open-send-sms-modal')
            ->assertSet('showModal', false);
    });

    it('validates message body is required', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create(['phone' => '303-555-1234']);

        Livewire::actingAs($user)
            ->test('customers.send-sms-modal', ['customer' => $customer])
            ->dispatch('open-send-sms-modal')
            ->set('messageBody', '')
            ->call('send')
            ->assertHasErrors(['messageBody' => 'required']);
    });

    it('validates message body max length', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create(['phone' => '303-555-1234']);

        Livewire::actingAs($user)
            ->test('customers.send-sms-modal', ['customer' => $customer])
            ->dispatch('open-send-sms-modal')
            ->set('messageBody', str_repeat('a', 1601))
            ->call('send')
            ->assertHasErrors(['messageBody' => 'max']);
    });

    it('dispatches SendDirectSmsJob on send', function () {
        Queue::fake();

        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create(['phone' => '303-555-1234']);

        Livewire::actingAs($user)
            ->test('customers.send-sms-modal', ['customer' => $customer])
            ->dispatch('open-send-sms-modal')
            ->set('messageBody', 'Hello, this is a test SMS message.')
            ->call('send')
            ->assertHasNoErrors()
            ->assertSet('showModal', false)
            ->assertSet('messageBody', '');

        Queue::assertPushed(SendDirectSmsJob::class, function ($job) use ($customer) {
            return $job->phone === '303-555-1234'
                && $job->message === 'Hello, this is a test SMS message.'
                && $job->customer->id === $customer->id;
        });
    });

    it('closes modal and resets state on cancel', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create(['phone' => '303-555-1234']);

        Livewire::actingAs($user)
            ->test('customers.send-sms-modal', ['customer' => $customer])
            ->dispatch('open-send-sms-modal')
            ->set('messageBody', 'Some message')
            ->call('closeModal')
            ->assertSet('showModal', false)
            ->assertSet('messageBody', '');
    });

    it('denies access to unauthorized users', function () {
        Queue::fake();

        $user = User::factory()->internal()->create();
        $customer = Customer::factory()->create(['phone' => '303-555-1234']);

        Livewire::actingAs($user)
            ->test('customers.send-sms-modal', ['customer' => $customer])
            ->dispatch('open-send-sms-modal')
            ->set('messageBody', 'Hello!')
            ->call('send')
            ->assertForbidden();

        Queue::assertNothingPushed();
    });
});
