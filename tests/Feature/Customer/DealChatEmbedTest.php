<?php

use App\Models\ChatMessage;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->customerUser = User::factory()->customer()->create();
    $this->customer = Customer::factory()->create(['user_id' => $this->customerUser->id]);
    $this->deal = Deal::factory()->forCustomer($this->customer)->create();
});

describe('Deal Chat Embed', function () {
    it('renders for authenticated customer', function () {
        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.deal-chat-embed', ['dealId' => $this->deal->id])
            ->assertStatus(200)
            ->assertSee('Project Chat');
    });

    it('shows empty state when no thread exists', function () {
        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.deal-chat-embed', ['dealId' => $this->deal->id])
            ->assertSee('Say hello');
    });

    it('sends a message and creates thread', function () {
        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.deal-chat-embed', ['dealId' => $this->deal->id])
            ->set('messageContent', 'Hello, I want my kitchen painted!')
            ->call('sendMessage');

        $thread = $this->deal->fresh()->chatThread;
        expect($thread)->not->toBeNull();
        expect($thread->messages()->count())->toBeGreaterThanOrEqual(1);

        $lastMessage = $thread->messages()
            ->where('type', '!=', ChatMessage::TYPE_SYSTEM)
            ->latest()
            ->first();
        expect($lastMessage->content)->toBe('Hello, I want my kitchen painted!');
        expect($lastMessage->user_id)->toBe($this->customerUser->id);
    });

    it('does not send empty messages', function () {
        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.deal-chat-embed', ['dealId' => $this->deal->id])
            ->set('messageContent', '   ')
            ->call('sendMessage');

        expect($this->deal->fresh()->chatThread)->toBeNull();
    });

    it('denies access to deals not owned by the customer', function () {
        $otherCustomer = Customer::factory()->create();
        $otherDeal = Deal::factory()->forCustomer($otherCustomer)->create();

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.deal-chat-embed', ['dealId' => $otherDeal->id])
            ->assertForbidden();
    });

    it('renders existing messages from thread', function () {
        $thread = $this->deal->getOrCreateChatThread();

        $thread->messages()->create([
            'user_id' => $this->customerUser->id,
            'content' => 'I love blue paint',
            'type' => 'text',
        ]);

        Livewire::actingAs($this->customerUser, 'customer')
            ->test('customer.deal-chat-embed', ['dealId' => $this->deal->id])
            ->assertSee('I love blue paint');
    });
});
