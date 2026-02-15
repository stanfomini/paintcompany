<?php

use App\Models\ChatThread;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\User;
use Livewire\Livewire;

it('shows context card for internal user on deal thread', function () {
    $internalUser = User::factory()->internal()->withFullAccess()->create();
    $customer = Customer::factory()->create();
    $deal = Deal::factory()->forCustomer($customer)->create();
    $thread = ChatThread::factory()->forDeal($deal)->create();
    $thread->addParticipant($internalUser);

    Livewire::actingAs($internalUser)
        ->test('chat.thread-view', ['thread' => $thread])
        ->assertSee($customer->full_name);
});

it('shows context card for internal user on direct thread', function () {
    $internalUser = User::factory()->internal()->withFullAccess()->create();
    $otherUser = User::factory()->customer()->create();
    $otherUser->givePermissionTo('chat.access');

    $thread = ChatThread::factory()
        ->direct()
        ->betweenUsers($internalUser, $otherUser)
        ->create();

    Livewire::actingAs($internalUser)
        ->test('chat.thread-view', ['thread' => $thread])
        ->assertSee($otherUser->name);
});

it('returns null context for non-internal users', function () {
    $internalUser = User::factory()->internal()->withFullAccess()->create();
    $customerUser = User::factory()->customer()->create();
    $customerUser->givePermissionTo('chat.access');

    $thread = ChatThread::factory()
        ->direct()
        ->betweenUsers($internalUser, $customerUser)
        ->create();

    $component = Livewire::actingAs($customerUser)
        ->test('chat.thread-view', ['thread' => $thread]);

    expect($component->get('threadContext'))->toBeNull();
});

it('returns null context in embedded mode', function () {
    $internalUser = User::factory()->internal()->withFullAccess()->create();
    $otherUser = User::factory()->customer()->create();
    $otherUser->givePermissionTo('chat.access');

    $thread = ChatThread::factory()
        ->direct()
        ->betweenUsers($internalUser, $otherUser)
        ->create();

    $component = Livewire::actingAs($internalUser)
        ->test('chat.thread-view', ['thread' => $thread, 'embedded' => true]);

    expect($component->get('threadContext'))->toBeNull();
});

it('includes deal context with correct data', function () {
    $internalUser = User::factory()->internal()->withFullAccess()->create();
    $customer = Customer::factory()->create();
    $deal = Deal::factory()->forCustomer($customer)->create();
    $estimate = Estimate::factory()->create(['deal_id' => $deal->id]);
    $thread = ChatThread::factory()->forDeal($deal)->create();
    $thread->addParticipant($internalUser);

    $component = Livewire::actingAs($internalUser)
        ->test('chat.thread-view', ['thread' => $thread]);

    $context = $component->get('threadContext');

    expect($context['type'])->toBe('deal')
        ->and($context['customer_name'])->toBe($customer->full_name)
        ->and($context['deal_url'])->toContain('/deals/')
        ->and($context['estimates'])->toHaveCount(1);
});

it('includes direct thread context with user type badge', function () {
    $internalUser = User::factory()->internal()->withFullAccess()->create();
    $otherUser = User::factory()->customer()->create();
    $otherUser->givePermissionTo('chat.access');

    $thread = ChatThread::factory()
        ->direct()
        ->betweenUsers($internalUser, $otherUser)
        ->create();

    $component = Livewire::actingAs($internalUser)
        ->test('chat.thread-view', ['thread' => $thread]);

    $context = $component->get('threadContext');

    expect($context['type'])->toBe('direct')
        ->and($context['name'])->toBe($otherUser->name)
        ->and($context['type_badge'])->toBe('Customer')
        ->and($context['email'])->toBe($otherUser->email);
});
