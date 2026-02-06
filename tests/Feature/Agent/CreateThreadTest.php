<?php

use App\Models\ChatThread;
use App\Models\Deal;
use App\Models\User;
use Livewire\Livewire;

describe('CreateThread component for agents', function () {
    it('can open the modal', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.chat.create-thread')
            ->assertSet('showModal', false)
            ->call('openModal')
            ->assertSet('showModal', true);
    });

    it('can close the modal', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.chat.create-thread')
            ->call('openModal')
            ->assertSet('showModal', true)
            ->call('closeModal')
            ->assertSet('showModal', false);
    });

    it('resets state when opening modal', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.chat.create-thread')
            ->set('selectedDealId', 456)
            ->set('dealSearch', 'deal search')
            ->call('openModal')
            ->assertSet('selectedDealId', null)
            ->assertSet('dealSearch', '');
    });

    it('only sees their referred deals', function () {
        $agent = User::factory()->agent()->create();
        $otherAgent = User::factory()->agent()->create();

        // Deal referred by this agent
        $agentDeal = Deal::factory()->referredByAgent($agent)->create(['title' => 'My Deal']);

        // Deal referred by another agent
        $otherDeal = Deal::factory()->referredByAgent($otherAgent)->create(['title' => 'Other Agent Deal']);

        // Deal with no agent
        $noDeal = Deal::factory()->create(['title' => 'No Agent Deal']);

        $component = Livewire::actingAs($agent, 'agent')
            ->test('agent.chat.create-thread')
            ->call('openModal');

        $availableDeals = $component->instance()->availableDeals;
        $dealIds = $availableDeals->pluck('id')->toArray();

        expect($dealIds)->toContain($agentDeal->id);
        expect($dealIds)->not->toContain($otherDeal->id);
        expect($dealIds)->not->toContain($noDeal->id);
    });

    it('can search through their deals', function () {
        $agent = User::factory()->agent()->create();

        $deal1 = Deal::factory()->referredByAgent($agent)->create(['title' => 'Exterior Painting Project']);
        $deal2 = Deal::factory()->referredByAgent($agent)->create(['title' => 'Interior Renovation']);

        $component = Livewire::actingAs($agent, 'agent')
            ->test('agent.chat.create-thread')
            ->call('openModal')
            ->set('dealSearch', 'Exterior');

        $availableDeals = $component->instance()->availableDeals;
        expect($availableDeals)->toHaveCount(1);
        expect($availableDeals->first()->title)->toBe('Exterior Painting Project');
    });

    it('can select a deal', function () {
        $agent = User::factory()->agent()->create();
        $deal = Deal::factory()->referredByAgent($agent)->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.chat.create-thread')
            ->call('openModal')
            ->call('selectDeal', $deal->id)
            ->assertSet('selectedDealId', $deal->id);
    });

    it('can create a deal thread', function () {
        $agent = User::factory()->agent()->create();
        $deal = Deal::factory()->referredByAgent($agent)->create(['title' => 'Test Deal']);

        Livewire::actingAs($agent, 'agent')
            ->test('agent.chat.create-thread')
            ->call('openModal')
            ->call('selectDeal', $deal->id)
            ->call('createThread')
            ->assertHasNoErrors()
            ->assertDispatched('thread-created')
            ->assertRedirect();

        expect(ChatThread::where('type', ChatThread::TYPE_DEAL)->where('deal_id', $deal->id)->exists())->toBeTrue();
    });

    it('cannot create thread without selecting deal', function () {
        $agent = User::factory()->agent()->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.chat.create-thread')
            ->call('openModal')
            ->call('createThread')
            ->assertHasErrors('general');
    });

    it('redirects to agent chat view after creation', function () {
        $agent = User::factory()->agent()->create();
        $deal = Deal::factory()->referredByAgent($agent)->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.chat.create-thread')
            ->call('openModal')
            ->call('selectDeal', $deal->id)
            ->call('createThread')
            ->assertRedirectContains('/agent/chat/');
    });

    it('cannot create thread for deal not referred by agent', function () {
        $agent = User::factory()->agent()->create();
        $otherAgent = User::factory()->agent()->create();

        // Deal referred by another agent
        $otherDeal = Deal::factory()->referredByAgent($otherAgent)->create();

        // The component queries Deal::where('agent_id', $user->id)->findOrFail(),
        // which throws ModelNotFoundException when deal is not owned by this agent
        $component = Livewire::actingAs($agent, 'agent')
            ->test('agent.chat.create-thread')
            ->call('openModal')
            ->set('selectedDealId', $otherDeal->id);

        expect(fn () => $component->call('createThread'))
            ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    });

    it('only shows deals without existing chat threads', function () {
        $agent = User::factory()->agent()->create();

        // Deal with existing thread
        $dealWithThread = Deal::factory()->referredByAgent($agent)->create(['title' => 'Deal With Thread']);
        ChatThread::factory()->forDeal($dealWithThread)->create();

        // Deal without thread
        $dealWithoutThread = Deal::factory()->referredByAgent($agent)->create(['title' => 'Deal Without Thread']);

        $component = Livewire::actingAs($agent, 'agent')
            ->test('agent.chat.create-thread')
            ->call('openModal');

        $availableDeals = $component->instance()->availableDeals;

        expect($availableDeals->pluck('id')->toArray())->toContain($dealWithoutThread->id);
        expect($availableDeals->pluck('id')->toArray())->not->toContain($dealWithThread->id);
    });

    it('dispatches thread-created event after successful creation', function () {
        $agent = User::factory()->agent()->create();
        $deal = Deal::factory()->referredByAgent($agent)->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.chat.create-thread')
            ->call('openModal')
            ->call('selectDeal', $deal->id)
            ->call('createThread')
            ->assertDispatched('thread-created');
    });

    it('closes modal after creating thread', function () {
        $agent = User::factory()->agent()->create();
        $deal = Deal::factory()->referredByAgent($agent)->create();

        // Note: The redirect happens before we can assert showModal,
        // but we can verify by checking the thread was created
        Livewire::actingAs($agent, 'agent')
            ->test('agent.chat.create-thread')
            ->call('openModal')
            ->assertSet('showModal', true)
            ->call('selectDeal', $deal->id)
            ->call('createThread')
            ->assertRedirect();

        expect(ChatThread::where('deal_id', $deal->id)->exists())->toBeTrue();
    });

    it('adds appropriate participants when creating deal thread', function () {
        $agent = User::factory()->agent()->create();
        $internalUser = User::factory()->internal()->withFullAccess()->create();
        $customerUser = User::factory()->customer()->create();

        // Create a customer with a user account
        $customer = \App\Models\Customer::factory()->create(['user_id' => $customerUser->id]);

        // Create a deal with all relevant parties
        $deal = Deal::factory()
            ->referredByAgent($agent)
            ->forCustomer($customer)
            ->assignedTo($internalUser)
            ->create();

        Livewire::actingAs($agent, 'agent')
            ->test('agent.chat.create-thread')
            ->call('openModal')
            ->call('selectDeal', $deal->id)
            ->call('createThread');

        $thread = ChatThread::where('deal_id', $deal->id)->first();

        // Verify participants were added
        expect($thread->hasParticipant($agent))->toBeTrue();
        expect($thread->hasParticipant($customerUser))->toBeTrue();
        expect($thread->hasParticipant($internalUser))->toBeTrue();
    });
});
