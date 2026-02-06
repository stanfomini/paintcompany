<?php

use App\Models\ChatThread;
use App\Models\Deal;
use App\Models\User;
use Livewire\Livewire;

describe('CreateThread component for internal users', function () {
    it('can open the modal', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('chat.create-thread')
            ->assertSet('showModal', false)
            ->call('openModal')
            ->assertSet('showModal', true);
    });

    it('can close the modal', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('chat.create-thread')
            ->call('openModal')
            ->assertSet('showModal', true)
            ->call('closeModal')
            ->assertSet('showModal', false);
    });

    it('resets state when opening modal', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('chat.create-thread')
            ->set('selectedUserId', 123)
            ->set('selectedDealId', 456)
            ->set('subject', 'Test subject')
            ->set('userSearch', 'search term')
            ->set('dealSearch', 'deal search')
            ->set('type', 'deal')
            ->call('openModal')
            ->assertSet('selectedUserId', null)
            ->assertSet('selectedDealId', null)
            ->assertSet('subject', '')
            ->assertSet('userSearch', '')
            ->assertSet('dealSearch', '')
            ->assertSet('type', 'direct');
    });

    it('can search for users', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $targetUser = User::factory()->internal()->withFullAccess()->create(['name' => 'John Smith']);
        User::factory()->internal()->withFullAccess()->create(['name' => 'Jane Doe']);

        $component = Livewire::actingAs($user)
            ->test('chat.create-thread')
            ->call('openModal')
            ->set('userSearch', 'John');

        $availableUsers = $component->instance()->availableUsers;
        expect($availableUsers)->toHaveCount(1);
        expect($availableUsers->first()->name)->toBe('John Smith');
    });

    it('can select a user and create a direct thread', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $targetUser = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('chat.create-thread')
            ->call('openModal')
            ->call('selectUser', $targetUser->id)
            ->assertSet('selectedUserId', $targetUser->id)
            ->assertSet('type', 'direct')
            ->call('createThread')
            ->assertHasNoErrors()
            ->assertRedirect();

        expect(ChatThread::where('type', ChatThread::TYPE_DIRECT)->exists())->toBeTrue();
    });

    it('can search for deals', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal1 = Deal::factory()->create(['title' => 'Exterior Painting Project']);
        $deal2 = Deal::factory()->create(['title' => 'Interior Renovation']);

        $component = Livewire::actingAs($user)
            ->test('chat.create-thread')
            ->call('openModal')
            ->set('type', 'deal')
            ->set('dealSearch', 'Exterior');

        $availableDeals = $component->instance()->availableDeals;
        expect($availableDeals)->toHaveCount(1);
        expect($availableDeals->first()->title)->toBe('Exterior Painting Project');
    });

    it('can select a deal and create a deal thread', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create(['title' => 'Test Deal']);

        Livewire::actingAs($user)
            ->test('chat.create-thread')
            ->call('openModal')
            ->call('selectDeal', $deal->id)
            ->assertSet('selectedDealId', $deal->id)
            ->assertSet('type', 'deal')
            ->call('createThread')
            ->assertHasNoErrors()
            ->assertDispatched('thread-created')
            ->assertRedirect();

        expect(ChatThread::where('type', ChatThread::TYPE_DEAL)->where('deal_id', $deal->id)->exists())->toBeTrue();
    });

    it('cannot create thread without selecting user or deal', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('chat.create-thread')
            ->call('openModal')
            ->call('createThread')
            ->assertHasErrors('general');
    });

    it('redirects to thread view after creation', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $targetUser = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('chat.create-thread')
            ->call('openModal')
            ->call('selectUser', $targetUser->id)
            ->call('createThread')
            ->assertRedirectContains('/chat/');
    });

    it('only shows deals without existing chat threads', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        // Deal with existing thread
        $dealWithThread = Deal::factory()->create(['title' => 'Deal With Thread']);
        ChatThread::factory()->forDeal($dealWithThread)->create();

        // Deal without thread
        $dealWithoutThread = Deal::factory()->create(['title' => 'Deal Without Thread']);

        $component = Livewire::actingAs($user)
            ->test('chat.create-thread')
            ->call('openModal')
            ->set('type', 'deal');

        $availableDeals = $component->instance()->availableDeals;

        expect($availableDeals->pluck('id')->toArray())->toContain($dealWithoutThread->id);
        expect($availableDeals->pluck('id')->toArray())->not->toContain($dealWithThread->id);
    });

    it('does not show current user in available users', function () {
        $user = User::factory()->internal()->withFullAccess()->create(['name' => 'Current User']);
        User::factory()->internal()->withFullAccess()->create(['name' => 'Other User']);

        $component = Livewire::actingAs($user)
            ->test('chat.create-thread')
            ->call('openModal');

        $availableUsers = $component->instance()->availableUsers;

        expect($availableUsers->pluck('id')->toArray())->not->toContain($user->id);
    });

    it('only shows active users in available users', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $activeUser = User::factory()->internal()->withFullAccess()->create(['name' => 'Active User', 'is_active' => true]);
        $inactiveUser = User::factory()->internal()->withFullAccess()->create(['name' => 'Inactive User', 'is_active' => false]);

        $component = Livewire::actingAs($user)
            ->test('chat.create-thread')
            ->call('openModal');

        $availableUsers = $component->instance()->availableUsers;

        expect($availableUsers->pluck('id')->toArray())->toContain($activeUser->id);
        expect($availableUsers->pluck('id')->toArray())->not->toContain($inactiveUser->id);
    });

    it('internal user can chat with customers', function () {
        // Internal users can chat with anyone according to the permission matrix
        $internalUser = User::factory()->internal()->withFullAccess()->create();
        $customerUser = User::factory()->customer()->create();

        Livewire::actingAs($internalUser)
            ->test('chat.create-thread')
            ->call('openModal')
            ->set('selectedUserId', $customerUser->id)
            ->set('type', 'direct')
            ->call('createThread')
            ->assertHasNoErrors()
            ->assertRedirect();

        expect(ChatThread::where('type', ChatThread::TYPE_DIRECT)->exists())->toBeTrue();
    });

    it('can create thread with subject', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $targetUser = User::factory()->internal()->withFullAccess()->create();

        Livewire::actingAs($user)
            ->test('chat.create-thread')
            ->call('openModal')
            ->call('selectUser', $targetUser->id)
            ->set('subject', 'Project Discussion')
            ->call('createThread')
            ->assertHasNoErrors();

        expect(ChatThread::where('subject', 'Project Discussion')->exists())->toBeTrue();
    });

    it('internal users can see all deals', function () {
        $internalUser = User::factory()->internal()->withFullAccess()->create();
        $agent = User::factory()->agent()->create();

        // Deal referred by agent
        $agentDeal = Deal::factory()->referredByAgent($agent)->create(['title' => 'Agent Referred Deal']);

        // Deal without agent
        $noDeal = Deal::factory()->create(['title' => 'No Agent Deal']);

        $component = Livewire::actingAs($internalUser)
            ->test('chat.create-thread')
            ->call('openModal')
            ->set('type', 'deal');

        $availableDeals = $component->instance()->availableDeals;
        $dealIds = $availableDeals->pluck('id')->toArray();

        expect($dealIds)->toContain($agentDeal->id);
        expect($dealIds)->toContain($noDeal->id);
    });

    it('dispatches thread-created event after successful creation', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $deal = Deal::factory()->create();

        Livewire::actingAs($user)
            ->test('chat.create-thread')
            ->call('openModal')
            ->call('selectDeal', $deal->id)
            ->call('createThread')
            ->assertDispatched('thread-created');
    });
});
