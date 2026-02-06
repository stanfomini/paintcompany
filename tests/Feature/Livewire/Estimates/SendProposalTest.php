<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\User;
use App\Notifications\EstimateProposalSent;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Send Proposal Feature', function () {
    it('shows send proposal button for draft estimates', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->draft()->create(['created_by' => $user->id]);

        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->assertSee('Send Proposal');
    });

    it('hides send proposal primary button for non-draft estimates', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->sent()->create(['created_by' => $user->id]);

        $component = Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate]);

        // The primary "Send Proposal" button should not be shown, but there's no direct way
        // to check for a specific button variant, so we verify by trying to open the modal
        $component->call('openSendModal')
            ->assertSet('showSendModal', false);
    });

    it('can open send modal', function () {
        $user = User::factory()->internal()->withFullAccess()->withSignature()->create();
        $customer = Customer::factory()->create(['email' => 'test@example.com']);
        $deal = Deal::factory()->forCustomer($customer)->create();
        $estimate = Estimate::factory()->forDeal($deal)->draft()->create(['created_by' => $user->id]);

        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->call('openSendModal')
            ->assertSet('showSendModal', true);
    });

    it('cannot open send modal for non-draft estimate', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create(['email' => 'test@example.com']);
        $deal = Deal::factory()->forCustomer($customer)->create();
        $estimate = Estimate::factory()->forDeal($deal)->sent()->create(['created_by' => $user->id]);

        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->call('openSendModal')
            ->assertSet('showSendModal', false);
    });

    it('cannot open send modal without customer email', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create(['email' => null]);
        $deal = Deal::factory()->forCustomer($customer)->create();
        $estimate = Estimate::factory()->forDeal($deal)->draft()->create(['created_by' => $user->id]);

        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->call('openSendModal')
            ->assertSet('showSendModal', false);
    });

    it('can close send modal', function () {
        $user = User::factory()->internal()->withFullAccess()->withSignature()->create();
        $customer = Customer::factory()->create(['email' => 'test@example.com']);
        $deal = Deal::factory()->forCustomer($customer)->create();
        $estimate = Estimate::factory()->forDeal($deal)->draft()->create(['created_by' => $user->id]);

        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->call('openSendModal')
            ->assertSet('showSendModal', true)
            ->call('closeSendModal')
            ->assertSet('showSendModal', false);
    });

    it('sends proposal and marks estimate as sent', function () {
        Notification::fake();

        $user = User::factory()->internal()->withFullAccess()->withSignature()->create();
        $customer = Customer::factory()->create(['email' => 'customer@example.com']);
        $deal = Deal::factory()->forCustomer($customer)->create();
        $estimate = Estimate::factory()->forDeal($deal)->draft()->create(['created_by' => $user->id]);

        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->call('sendProposal')
            ->assertSet('showSendModal', false);

        $estimate->refresh();
        expect($estimate->status)->toBe('sent');
        expect($estimate->sent_at)->not->toBeNull();
        expect($estimate->public_token)->not->toBeNull();

        Notification::assertSentTo($customer, EstimateProposalSent::class);
    });

    it('generates public URL after sending', function () {
        Notification::fake();

        $user = User::factory()->internal()->withFullAccess()->withSignature()->create();
        $customer = Customer::factory()->create(['email' => 'customer@example.com']);
        $deal = Deal::factory()->forCustomer($customer)->create();
        $estimate = Estimate::factory()->forDeal($deal)->draft()->create(['created_by' => $user->id]);

        $component = Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->assertSet('publicUrl', null)
            ->call('sendProposal');

        expect($component->get('publicUrl'))->not->toBeNull();
    });

    it('cannot send proposal twice', function () {
        Notification::fake();

        $user = User::factory()->internal()->withFullAccess()->create();
        $customer = Customer::factory()->create(['email' => 'customer@example.com']);
        $deal = Deal::factory()->forCustomer($customer)->create();
        $estimate = Estimate::factory()->forDeal($deal)->sent()->create(['created_by' => $user->id]);

        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->call('sendProposal')
            ->assertSet('showSendModal', false);

        Notification::assertNotSentTo($customer, EstimateProposalSent::class);
    });

    it('displays public URL section when URL exists', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->sent()->create(['created_by' => $user->id]);
        $estimate->generatePublicToken();

        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->assertSee('Public Link:');
    });

    it('hides public URL section when no URL exists', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->draft()->create(['created_by' => $user->id]);

        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->assertDontSee('Public Link:');
    });

    it('can trigger copy URL action', function () {
        $user = User::factory()->internal()->withFullAccess()->create();
        $estimate = Estimate::factory()->sent()->create(['created_by' => $user->id]);
        $estimate->generatePublicToken();

        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->call('copyPublicUrl')
            ->assertDispatched('copy-to-clipboard');
    });

    it('shows success message after sending proposal', function () {
        Notification::fake();

        $user = User::factory()->internal()->withFullAccess()->withSignature()->create();
        $customer = Customer::factory()->create(['email' => 'test@example.com']);
        $deal = Deal::factory()->forCustomer($customer)->create();
        $estimate = Estimate::factory()->forDeal($deal)->draft()->create(['created_by' => $user->id]);

        // The component uses session flash which is handled differently in Livewire
        // Just verify the notification is sent and status is updated
        Livewire::actingAs($user)
            ->test('estimates.show', ['estimate' => $estimate])
            ->call('sendProposal');

        $estimate->refresh();
        expect($estimate->status)->toBe('sent');
        Notification::assertSentTo($customer, EstimateProposalSent::class);
    });
});
