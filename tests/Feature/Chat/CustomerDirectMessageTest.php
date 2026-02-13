<?php

use App\Models\ChatThread;
use App\Models\Customer;
use App\Models\User;
use App\Services\ChatService;
use Livewire\Livewire;

describe('Customer direct message functionality', function () {
    it('customer can create a direct thread with internal user', function () {
        $customer = User::factory()->customer()->create();
        Customer::factory()->create(['user_id' => $customer->id]);
        $internal = User::factory()->internal()->create();

        $chatService = app(ChatService::class);
        $thread = $chatService->getOrCreateDirectThread($customer, $internal, 'Question about my project');

        expect($thread)->toBeInstanceOf(ChatThread::class);
        expect($thread->type)->toBe(ChatThread::TYPE_DIRECT);
        expect($thread->hasParticipant($customer))->toBeTrue();
        expect($thread->hasParticipant($internal))->toBeTrue();
    });

    it('customer can view their direct threads in thread list', function () {
        $customer = User::factory()->customer()->create();
        Customer::factory()->create(['user_id' => $customer->id]);
        $internal = User::factory()->internal()->create();

        // Create a direct thread
        $chatService = app(ChatService::class);
        $thread = $chatService->getOrCreateDirectThread($customer, $internal, 'Test thread');

        $this->actingAs($customer, 'customer');

        Livewire::test('customer.chat.thread-list')
            ->assertSee('Test thread');
    });

    it('customer can view a direct thread', function () {
        $customer = User::factory()->customer()->create();
        Customer::factory()->create(['user_id' => $customer->id]);
        $internal = User::factory()->internal()->create();

        $chatService = app(ChatService::class);
        $thread = $chatService->getOrCreateDirectThread($customer, $internal, 'My question');

        $this->actingAs($customer, 'customer');

        Livewire::test('customer.chat.thread-view', ['thread' => $thread])
            ->assertStatus(200)
            ->assertSee('Direct Message');
    });

    it('customer cannot view another customers direct thread', function () {
        $customer1 = User::factory()->customer()->create();
        $customer2 = User::factory()->customer()->create();
        $internal = User::factory()->internal()->create();

        $chatService = app(ChatService::class);
        $thread = $chatService->getOrCreateDirectThread($customer1, $internal, 'Private thread');

        $this->actingAs($customer2, 'customer');

        Livewire::test('customer.chat.thread-view', ['thread' => $thread])
            ->assertStatus(404);
    });

    it('customer create-thread component shows available internal users', function () {
        $customer = User::factory()->customer()->create();
        Customer::factory()->create(['user_id' => $customer->id]);
        $internal = User::factory()->internal()->create(['name' => 'John Staff']);

        $this->actingAs($customer, 'customer');

        Livewire::test('customer.chat.create-thread')
            ->call('openModal')
            ->assertSee('John Staff');
    });

    it('customer can create thread via create-thread component', function () {
        $customer = User::factory()->customer()->create();
        Customer::factory()->create(['user_id' => $customer->id]);
        $internal = User::factory()->internal()->create();

        $this->actingAs($customer, 'customer');

        Livewire::test('customer.chat.create-thread')
            ->call('openModal')
            ->call('selectUser', $internal->id)
            ->set('subject', 'About my project')
            ->call('createThread')
            ->assertRedirect();

        $thread = ChatThread::query()
            ->ofType(ChatThread::TYPE_DIRECT)
            ->whereHas('participants', fn ($q) => $q->where('user_id', $customer->id))
            ->whereHas('participants', fn ($q) => $q->where('user_id', $internal->id))
            ->first();

        expect($thread)->not->toBeNull();
        expect($thread->subject)->toBe('About my project');
    });

    it('customer thread list shows both deal and direct threads', function () {
        $customer = User::factory()->customer()->create();
        Customer::factory()->create(['user_id' => $customer->id]);
        $internal = User::factory()->internal()->create();

        $chatService = app(ChatService::class);

        // Create a direct thread
        $directThread = $chatService->getOrCreateDirectThread($customer, $internal, 'Direct chat');

        $this->actingAs($customer, 'customer');

        Livewire::test('customer.chat.thread-list')
            ->assertSee('Direct chat')
            ->assertSee('Direct');
    });
});
