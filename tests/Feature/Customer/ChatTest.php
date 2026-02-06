<?php

use App\Models\ChatMessage;
use App\Models\ChatThread;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\User;
use Livewire\Livewire;

describe('Customer Chat ThreadList', function () {
    it('renders the thread list for authenticated customer', function () {
        $customerUser = User::factory()->customer()->create();
        Customer::factory()->create(['user_id' => $customerUser->id]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.chat.thread-list')
            ->assertStatus(200)
            ->assertSee('Messages');
    });

    it('shows empty state when no threads exist', function () {
        $customerUser = User::factory()->customer()->create();
        Customer::factory()->create(['user_id' => $customerUser->id]);

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.chat.thread-list')
            ->assertSee('No Messages')
            ->assertSee('You don\'t have any active conversations yet');
    });

    it('displays deal threads where customer is a participant', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->forCustomer($customer)->create(['title' => 'Kitchen Repaint Project']);

        $thread = ChatThread::factory()
            ->forDeal($deal)
            ->withParticipants([$customerUser])
            ->create();

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.chat.thread-list')
            ->assertSee('Kitchen Repaint Project');
    });

    it('does not display direct threads', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $internalUser = User::factory()->internal()->withFullAccess()->create();

        // Direct thread between customer and internal user
        $thread = ChatThread::factory()
            ->direct()
            ->withSubject('Direct Chat')
            ->betweenUsers($customerUser, $internalUser)
            ->create();

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.chat.thread-list')
            ->assertDontSee('Direct Chat');
    });

    it('does not display threads where customer is not a participant', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $otherCustomer = Customer::factory()->create();
        $deal = Deal::factory()->forCustomer($otherCustomer)->create(['title' => 'Other Project']);

        $otherUser = User::factory()->internal()->withFullAccess()->create();
        $thread = ChatThread::factory()
            ->forDeal($deal)
            ->withParticipants([$otherUser])
            ->create();

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.chat.thread-list')
            ->assertDontSee('Other Project');
    });

    it('shows last message preview', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->forCustomer($customer)->create(['title' => 'Bathroom Remodel']);
        $internalUser = User::factory()->internal()->withFullAccess()->create(['name' => 'John Staff']);

        $thread = ChatThread::factory()
            ->forDeal($deal)
            ->withParticipants([$customerUser, $internalUser])
            ->create();

        ChatMessage::factory()
            ->forThread($thread)
            ->fromUser($internalUser)
            ->withContent('Your estimate is ready for review!')
            ->create();

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.chat.thread-list')
            ->assertSee('John Staff')
            ->assertSee('Your estimate is ready for review!');
    });

    it('shows unread count badge', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->forCustomer($customer)->create();
        $internalUser = User::factory()->internal()->withFullAccess()->create();

        $thread = ChatThread::factory()
            ->forDeal($deal)
            ->withParticipants([$customerUser, $internalUser])
            ->create();

        // Create 2 unread messages from internal user
        ChatMessage::factory()
            ->count(2)
            ->forThread($thread)
            ->fromUser($internalUser)
            ->create();

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.chat.thread-list')
            ->assertSeeHtml('2');
    });
});

describe('Customer Chat ThreadView', function () {
    it('renders thread view for authorized customer', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->forCustomer($customer)->create(['title' => 'Living Room Painting']);

        $thread = ChatThread::factory()
            ->forDeal($deal)
            ->withParticipants([$customerUser])
            ->create();

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.chat.thread-view', ['thread' => $thread])
            ->assertStatus(200)
            ->assertSee('Living Room Painting');
    });

    it('cannot access thread they are not a participant of', function () {
        $customerUser = User::factory()->customer()->create();
        Customer::factory()->create(['user_id' => $customerUser->id]);

        $otherCustomer = Customer::factory()->create();
        $deal = Deal::factory()->forCustomer($otherCustomer)->create();
        $otherUser = User::factory()->internal()->withFullAccess()->create();

        $thread = ChatThread::factory()
            ->forDeal($deal)
            ->withParticipants([$otherUser])
            ->create();

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.chat.thread-view', ['thread' => $thread])
            ->assertNotFound();
    });

    it('cannot access non-deal threads', function () {
        $customerUser = User::factory()->customer()->create();
        Customer::factory()->create(['user_id' => $customerUser->id]);
        $internalUser = User::factory()->internal()->withFullAccess()->create();

        $thread = ChatThread::factory()
            ->direct()
            ->betweenUsers($customerUser, $internalUser)
            ->create();

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.chat.thread-view', ['thread' => $thread])
            ->assertNotFound();
    });

    it('cannot access support threads', function () {
        $customerUser = User::factory()->customer()->create();
        Customer::factory()->create(['user_id' => $customerUser->id]);

        $thread = ChatThread::factory()
            ->support()
            ->withParticipants([$customerUser])
            ->create();

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.chat.thread-view', ['thread' => $thread])
            ->assertNotFound();
    });

    it('displays messages in the thread', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->forCustomer($customer)->create();
        $internalUser = User::factory()->internal()->withFullAccess()->create(['name' => 'Team Member']);

        $thread = ChatThread::factory()
            ->forDeal($deal)
            ->withParticipants([$customerUser, $internalUser])
            ->create();

        ChatMessage::factory()
            ->forThread($thread)
            ->fromUser($internalUser)
            ->withContent('Hello! Your project is progressing well.')
            ->create();

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.chat.thread-view', ['thread' => $thread])
            ->assertSee('Hello! Your project is progressing well.')
            ->assertSee('Team Member');
    });

    it('cannot see internal messages', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->forCustomer($customer)->create();
        $internalUser = User::factory()->internal()->withFullAccess()->create();

        $thread = ChatThread::factory()
            ->forDeal($deal)
            ->withParticipants([$customerUser, $internalUser])
            ->create();

        // Create public message
        ChatMessage::factory()
            ->forThread($thread)
            ->fromUser($internalUser)
            ->withContent('This is a public message')
            ->public()
            ->create();

        // Create internal message (should not be visible to customer)
        ChatMessage::factory()
            ->forThread($thread)
            ->fromUser($internalUser)
            ->withContent('This is an internal staff note')
            ->internal()
            ->create();

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.chat.thread-view', ['thread' => $thread])
            ->assertSee('This is a public message')
            ->assertDontSee('This is an internal staff note');
    });

    it('shows own messages with different styling', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->forCustomer($customer)->create();

        $thread = ChatThread::factory()
            ->forDeal($deal)
            ->withParticipants([$customerUser])
            ->create();

        ChatMessage::factory()
            ->forThread($thread)
            ->fromUser($customerUser)
            ->withContent('My own message')
            ->create();

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.chat.thread-view', ['thread' => $thread])
            ->assertSee('My own message');
    });

    it('marks messages as read when viewing thread', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->forCustomer($customer)->create();
        $internalUser = User::factory()->internal()->withFullAccess()->create();

        $thread = ChatThread::factory()
            ->forDeal($deal)
            ->withParticipants([$customerUser, $internalUser])
            ->create();

        ChatMessage::factory()
            ->forThread($thread)
            ->fromUser($internalUser)
            ->create();

        // Get the participant to check last_read_at before viewing
        $participant = $thread->getParticipant($customerUser);
        expect($participant->last_read_at)->toBeNull();

        // View the thread which should mark messages as read
        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.chat.thread-view', ['thread' => $thread])
            ->assertStatus(200);

        // Check that last_read_at has been updated
        $participant->refresh();
        expect($participant->last_read_at)->not->toBeNull();
    });

    it('shows empty state when no messages', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->forCustomer($customer)->create();

        $thread = ChatThread::factory()
            ->forDeal($deal)
            ->withParticipants([$customerUser])
            ->create();

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.chat.thread-view', ['thread' => $thread])
            ->assertSee('No messages yet');
    });
});

describe('Customer Chat MessageComposer', function () {
    it('renders the message composer', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->forCustomer($customer)->create();

        $thread = ChatThread::factory()
            ->forDeal($deal)
            ->withParticipants([$customerUser])
            ->create();

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.chat.message-composer', ['thread' => $thread])
            ->assertStatus(200)
            ->assertSee('Send');
    });

    it('can send a message', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->forCustomer($customer)->create();

        $thread = ChatThread::factory()
            ->forDeal($deal)
            ->withParticipants([$customerUser])
            ->create();

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.chat.message-composer', ['thread' => $thread])
            ->set('message', 'Hello, I have a question about my project.')
            ->call('send')
            ->assertHasNoErrors()
            ->assertSet('message', '')
            ->assertDispatched('message-sent');

        expect($thread->messages()->count())->toBe(1);
        expect($thread->messages()->first()->content)->toBe('Hello, I have a question about my project.');
    });

    it('validates message is required', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->forCustomer($customer)->create();

        $thread = ChatThread::factory()
            ->forDeal($deal)
            ->withParticipants([$customerUser])
            ->create();

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.chat.message-composer', ['thread' => $thread])
            ->set('message', '')
            ->call('send')
            ->assertHasErrors(['message' => 'required']);
    });

    it('validates message max length', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->forCustomer($customer)->create();

        $thread = ChatThread::factory()
            ->forDeal($deal)
            ->withParticipants([$customerUser])
            ->create();

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.chat.message-composer', ['thread' => $thread])
            ->set('message', str_repeat('a', 5001))
            ->call('send')
            ->assertHasErrors(['message' => 'max']);
    });

    it('messages sent by customer are never internal', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->forCustomer($customer)->create();

        $thread = ChatThread::factory()
            ->forDeal($deal)
            ->withParticipants([$customerUser])
            ->create();

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.chat.message-composer', ['thread' => $thread])
            ->set('message', 'Customer message')
            ->call('send')
            ->assertHasNoErrors();

        $message = $thread->messages()->first();
        expect($message->is_internal)->toBeFalse();
    });

    it('cannot send message if not participant', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

        $otherUser = User::factory()->internal()->withFullAccess()->create();
        $otherCustomer = Customer::factory()->create();
        $deal = Deal::factory()->forCustomer($otherCustomer)->create();

        $thread = ChatThread::factory()
            ->forDeal($deal)
            ->withParticipants([$otherUser])
            ->create();

        Livewire::actingAs($customerUser, 'customer')
            ->test('customer.chat.message-composer', ['thread' => $thread])
            ->set('message', 'Trying to send a message')
            ->call('send')
            ->assertHasErrors(['message']);

        expect($thread->messages()->count())->toBe(0);
    });
});

describe('Customer Chat Routes', function () {
    it('can access chat index page', function () {
        $customerUser = User::factory()->customer()->create();
        Customer::factory()->create(['user_id' => $customerUser->id]);

        $this->actingAs($customerUser, 'customer')
            ->get(route('customer.chat.index'))
            ->assertOk()
            ->assertSeeLivewire('customer.chat.thread-list');
    });

    it('can access chat show page for authorized thread', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $deal = Deal::factory()->forCustomer($customer)->create();

        $thread = ChatThread::factory()
            ->forDeal($deal)
            ->withParticipants([$customerUser])
            ->create();

        $this->actingAs($customerUser, 'customer')
            ->get(route('customer.chat.show', $thread))
            ->assertOk()
            ->assertSeeLivewire('customer.chat.thread-view');
    });

    it('redirects to login if not authenticated', function () {
        $this->get(route('customer.chat.index'))
            ->assertRedirect(route('customer.login'));
    });
});
