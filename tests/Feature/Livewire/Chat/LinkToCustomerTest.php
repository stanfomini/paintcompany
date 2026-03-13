<?php

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\Customer;
use App\Models\SmsSession;
use App\Models\User;
use Livewire\Livewire;

describe('Link to Customer - Thread View', function () {
    it('shows the link to customer button for internal users on unlinked SMS threads', function () {
        $internalUser = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()
            ->smsInbound()
            ->withSubject('+13035551234')
            ->withParticipants([$internalUser])
            ->create();

        Livewire::actingAs($internalUser)
            ->test('chat.thread-view', ['thread' => $thread])
            ->assertSee('Link to Customer');
    });

    it('hides the link button when thread already has a customer', function () {
        $internalUser = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create();
        $thread = ChatThread::factory()
            ->smsInbound()
            ->forCustomer($customer)
            ->withSubject('+13035551234')
            ->withParticipants([$internalUser])
            ->create();

        Livewire::actingAs($internalUser)
            ->test('chat.thread-view', ['thread' => $thread])
            ->assertDontSee('Link to Customer');
    });

    it('hides the link button for non-internal users', function () {
        $customerUser = User::factory()->customer()->create();
        $customerUser->givePermissionTo('chat.access');
        $internalUser = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()
            ->smsInbound()
            ->withSubject('+13035551234')
            ->withParticipants([$customerUser, $internalUser])
            ->create();

        Livewire::actingAs($customerUser)
            ->test('chat.thread-view', ['thread' => $thread])
            ->assertDontSee('Link to Customer');
    });

    it('hides the link button on non-SMS threads', function () {
        $internalUser = User::factory()->internal()->withFullAccess()->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()
            ->direct()
            ->betweenUsers($internalUser, $otherUser)
            ->create();

        Livewire::actingAs($internalUser)
            ->test('chat.thread-view', ['thread' => $thread])
            ->assertDontSee('Link to Customer');
    });

    it('opens the link customer modal', function () {
        $internalUser = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()
            ->smsInbound()
            ->withSubject('+13035551234')
            ->withParticipants([$internalUser])
            ->create();

        Livewire::actingAs($internalUser)
            ->test('chat.thread-view', ['thread' => $thread])
            ->call('openLinkCustomerModal')
            ->assertSet('showLinkCustomerModal', true)
            ->assertSet('customerSearchQuery', '');
    });

    it('searches customers when query is at least 2 characters', function () {
        $internalUser = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()
            ->smsInbound()
            ->withSubject('+13035551234')
            ->withParticipants([$internalUser])
            ->create();

        Customer::factory()->create(['first_name' => 'Xylophane', 'last_name' => 'Zwickau']);
        Customer::factory()->create(['first_name' => 'Bob', 'last_name' => 'Smith']);

        $component = Livewire::actingAs($internalUser)
            ->test('chat.thread-view', ['thread' => $thread])
            ->set('customerSearchQuery', 'Xylophane');

        expect($component->get('customerSearchResults'))->toHaveCount(1);
        expect($component->get('customerSearchResults')->first()->first_name)->toBe('Xylophane');
    });

    it('returns empty results when query is less than 2 characters', function () {
        $internalUser = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()
            ->smsInbound()
            ->withSubject('+13035551234')
            ->withParticipants([$internalUser])
            ->create();

        Customer::factory()->create(['first_name' => 'Alice', 'last_name' => 'Johnson']);

        $component = Livewire::actingAs($internalUser)
            ->test('chat.thread-view', ['thread' => $thread])
            ->set('customerSearchQuery', 'A');

        expect($component->get('customerSearchResults'))->toHaveCount(0);
    });

    it('links a customer to the thread', function () {
        $internalUser = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()
            ->smsInbound()
            ->withSubject('+13035551234')
            ->withParticipants([$internalUser])
            ->create();

        $customer = Customer::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);

        Livewire::actingAs($internalUser)
            ->test('chat.thread-view', ['thread' => $thread])
            ->call('linkToCustomer', $customer->id)
            ->assertSet('showLinkCustomerModal', false)
            ->assertSet('customerSearchQuery', '');

        $thread->refresh();
        expect($thread->customer_id)->toBe($customer->id);
    });

    it('creates a system message when linking a customer', function () {
        $internalUser = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()
            ->smsInbound()
            ->withSubject('+13035551234')
            ->withParticipants([$internalUser])
            ->create();

        $customer = Customer::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);

        Livewire::actingAs($internalUser)
            ->test('chat.thread-view', ['thread' => $thread])
            ->call('linkToCustomer', $customer->id);

        $systemMessage = ChatMessage::where('thread_id', $thread->id)
            ->where('type', ChatMessage::TYPE_SYSTEM)
            ->first();

        expect($systemMessage)->not->toBeNull();
        expect($systemMessage->content)->toContain('Linked to customer: Jane Doe');
    });

    it('updates SmsSession customer_id when linking', function () {
        $internalUser = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()
            ->smsInbound()
            ->withSubject('+13035551234')
            ->withParticipants([$internalUser])
            ->create();

        $session = SmsSession::create([
            'thread_id' => $thread->id,
            'phone_number' => '+13035551234',
            'customer_id' => null,
            'expires_at' => now()->addHours(24),
        ]);

        $customer = Customer::factory()->create(['first_name' => 'Jane', 'last_name' => 'Doe']);

        Livewire::actingAs($internalUser)
            ->test('chat.thread-view', ['thread' => $thread])
            ->call('linkToCustomer', $customer->id);

        $session->refresh();
        expect($session->customer_id)->toBe($customer->id);
    });

    it('prevents non-internal users from linking customers', function () {
        $customerUser = User::factory()->customer()->create();
        $customerUser->givePermissionTo('chat.access');
        $internalUser = User::factory()->internal()->withFullAccess()->create();

        $thread = ChatThread::factory()
            ->smsInbound()
            ->withSubject('+13035551234')
            ->withParticipants([$customerUser, $internalUser])
            ->create();

        $customer = Customer::factory()->create();

        Livewire::actingAs($customerUser)
            ->test('chat.thread-view', ['thread' => $thread])
            ->call('linkToCustomer', $customer->id)
            ->assertForbidden();
    });

    it('updates last_message_at when linking', function () {
        $internalUser = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()
            ->smsInbound()
            ->withSubject('+13035551234')
            ->withParticipants([$internalUser])
            ->create(['last_message_at' => now()->subDay()]);

        $customer = Customer::factory()->create();

        Livewire::actingAs($internalUser)
            ->test('chat.thread-view', ['thread' => $thread])
            ->call('linkToCustomer', $customer->id);

        $thread->refresh();
        expect($thread->last_message_at->isToday())->toBeTrue();
    });
});

describe('Auto-link SMS threads on Customer phone sync', function () {
    it('auto-links unlinked SMS threads when a customer is created with matching phone', function () {
        $thread = ChatThread::factory()
            ->smsInbound()
            ->withSubject('+13035551234')
            ->create(['customer_id' => null]);

        $customer = Customer::factory()->create(['phone' => '(303) 555-1234']);

        $thread->refresh();
        expect($thread->customer_id)->toBe($customer->id);
    });

    it('auto-links SmsSession records when a customer is created with matching phone', function () {
        $thread = ChatThread::factory()
            ->smsInbound()
            ->withSubject('+13035551234')
            ->create(['customer_id' => null]);

        $session = SmsSession::create([
            'thread_id' => $thread->id,
            'phone_number' => '+13035551234',
            'customer_id' => null,
            'expires_at' => now()->addHours(24),
        ]);

        $customer = Customer::factory()->create(['phone' => '(303) 555-1234']);

        $session->refresh();
        expect($session->customer_id)->toBe($customer->id);
    });

    it('auto-links when a customer phone is updated to match an SMS thread', function () {
        $thread = ChatThread::factory()
            ->smsInbound()
            ->withSubject('+17205559876')
            ->create(['customer_id' => null]);

        $customer = Customer::factory()->create(['phone' => '(303) 555-0000']);

        // Thread should not be linked yet
        $thread->refresh();
        expect($thread->customer_id)->toBeNull();

        // Update phone to match
        $customer->update(['phone' => '(720) 555-9876']);

        $thread->refresh();
        expect($thread->customer_id)->toBe($customer->id);
    });

    it('does not link threads that already have a customer', function () {
        $existingCustomer = Customer::factory()->create();
        $thread = ChatThread::factory()
            ->smsInbound()
            ->forCustomer($existingCustomer)
            ->withSubject('+13035551234')
            ->create();

        $newCustomer = Customer::factory()->create(['phone' => '(303) 555-1234']);

        $thread->refresh();
        // Should remain linked to the existing customer, not the new one
        expect($thread->customer_id)->toBe($existingCustomer->id);
    });

    it('does not auto-link when phone is too short', function () {
        $thread = ChatThread::factory()
            ->smsInbound()
            ->withSubject('+11234')
            ->create(['customer_id' => null]);

        $customer = Customer::factory()->create(['phone' => '1234']);

        $thread->refresh();
        expect($thread->customer_id)->toBeNull();
    });

    it('does not auto-link non-SMS threads', function () {
        $thread = ChatThread::factory()
            ->websiteLead()
            ->withSubject('+13035551234')
            ->create(['customer_id' => null]);

        $customer = Customer::factory()->create(['phone' => '(303) 555-1234']);

        $thread->refresh();
        expect($thread->customer_id)->toBeNull();
    });
});
