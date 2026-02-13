<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\DealCollaborator;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\CollaboratorAccessGranted;
use App\Services\DealCollaboratorService;
use App\Services\InvitationService;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

// ---------------------------------------------------------------------------
// DealCollaborator Model Tests
// ---------------------------------------------------------------------------

describe('DealCollaborator Model', function () {
    it('isActive returns true for valid collaboration', function () {
        $collab = DealCollaborator::factory()->create();

        expect($collab->isActive())->toBeTrue();
    });

    it('isActive returns false when expired', function () {
        $collab = DealCollaborator::factory()->expired()->create();

        expect($collab->isActive())->toBeFalse();
    });

    it('isActive returns false when revoked', function () {
        $collab = DealCollaborator::factory()->revoked()->create();

        expect($collab->isActive())->toBeFalse();
    });

    it('daysRemaining returns correct days', function () {
        $collab = DealCollaborator::factory()->create([
            'expires_at' => now()->addDays(15)->startOfDay(),
        ]);

        // daysRemaining uses diffInDays which truncates, so allow for rounding
        expect($collab->daysRemaining())->toBeGreaterThanOrEqual(14);
        expect($collab->daysRemaining())->toBeLessThanOrEqual(15);
    });

    it('daysRemaining returns 0 when expired', function () {
        $collab = DealCollaborator::factory()->expired()->create();

        expect($collab->daysRemaining())->toBe(0);
    });

    it('daysRemaining returns 0 when revoked', function () {
        $collab = DealCollaborator::factory()->revoked()->create([
            'expires_at' => now()->addDays(10),
        ]);

        expect($collab->daysRemaining())->toBe(0);
    });

    it('active scope filters correctly', function () {
        DealCollaborator::factory()->create(); // active
        DealCollaborator::factory()->expired()->create(); // expired
        DealCollaborator::factory()->revoked()->create(); // revoked

        expect(DealCollaborator::active()->count())->toBe(1);
    });

    it('forCustomer scope filters by customer', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);

        DealCollaborator::factory()->forCustomer($customer)->create();
        DealCollaborator::factory()->create(); // different customer

        expect(DealCollaborator::forCustomer($customer)->count())->toBe(1);
    });

    it('has deal relationship', function () {
        $deal = Deal::factory()->create();
        $collab = DealCollaborator::factory()->forDeal($deal)->create();

        expect($collab->deal->id)->toBe($deal->id);
    });

    it('has customer relationship', function () {
        $customerUser = User::factory()->customer()->create();
        $customer = Customer::factory()->create(['user_id' => $customerUser->id]);
        $collab = DealCollaborator::factory()->forCustomer($customer)->create();

        expect($collab->customer->id)->toBe($customer->id);
    });

    it('has invitedBy relationship', function () {
        $inviter = User::factory()->customer()->create();
        $collab = DealCollaborator::factory()->create(['invited_by' => $inviter->id]);

        expect($collab->invitedBy->id)->toBe($inviter->id);
    });
});

// ---------------------------------------------------------------------------
// Deal Model - Collaborator Access Tests
// ---------------------------------------------------------------------------

describe('Deal Model Collaborator Access', function () {
    it('customerCanAccess returns true for owner', function () {
        $ownerUser = User::factory()->customer()->create();
        $owner = Customer::factory()->create(['user_id' => $ownerUser->id]);
        $deal = Deal::factory()->forCustomer($owner)->create();

        expect($deal->customerCanAccess($owner))->toBeTrue();
    });

    it('customerCanAccess returns true for active collaborator', function () {
        $ownerUser = User::factory()->customer()->create();
        $owner = Customer::factory()->create(['user_id' => $ownerUser->id]);
        $deal = Deal::factory()->forCustomer($owner)->create();

        $collabUser = User::factory()->customer()->create();
        $collabCustomer = Customer::factory()->create(['user_id' => $collabUser->id]);

        DealCollaborator::factory()->forDeal($deal)->forCustomer($collabCustomer)->create();

        expect($deal->customerCanAccess($collabCustomer))->toBeTrue();
    });

    it('customerCanAccess returns false for expired collaborator', function () {
        $ownerUser = User::factory()->customer()->create();
        $owner = Customer::factory()->create(['user_id' => $ownerUser->id]);
        $deal = Deal::factory()->forCustomer($owner)->create();

        $collabUser = User::factory()->customer()->create();
        $collabCustomer = Customer::factory()->create(['user_id' => $collabUser->id]);

        DealCollaborator::factory()->forDeal($deal)->forCustomer($collabCustomer)->expired()->create();

        expect($deal->customerCanAccess($collabCustomer))->toBeFalse();
    });

    it('customerCanAccess returns false for revoked collaborator', function () {
        $ownerUser = User::factory()->customer()->create();
        $owner = Customer::factory()->create(['user_id' => $ownerUser->id]);
        $deal = Deal::factory()->forCustomer($owner)->create();

        $collabUser = User::factory()->customer()->create();
        $collabCustomer = Customer::factory()->create(['user_id' => $collabUser->id]);

        DealCollaborator::factory()->forDeal($deal)->forCustomer($collabCustomer)->revoked()->create();

        expect($deal->customerCanAccess($collabCustomer))->toBeFalse();
    });

    it('customerCanAccess returns false for unrelated customer', function () {
        $ownerUser = User::factory()->customer()->create();
        $owner = Customer::factory()->create(['user_id' => $ownerUser->id]);
        $deal = Deal::factory()->forCustomer($owner)->create();

        $unrelatedUser = User::factory()->customer()->create();
        $unrelatedCustomer = Customer::factory()->create(['user_id' => $unrelatedUser->id]);

        expect($deal->customerCanAccess($unrelatedCustomer))->toBeFalse();
    });

    it('isOwnedBy returns true only for owner', function () {
        $ownerUser = User::factory()->customer()->create();
        $owner = Customer::factory()->create(['user_id' => $ownerUser->id]);
        $deal = Deal::factory()->forCustomer($owner)->create();

        $otherUser = User::factory()->customer()->create();
        $otherCustomer = Customer::factory()->create(['user_id' => $otherUser->id]);

        expect($deal->isOwnedBy($owner))->toBeTrue();
        expect($deal->isOwnedBy($otherCustomer))->toBeFalse();
    });

    it('accessibleByCustomer scope includes owned deals', function () {
        $ownerUser = User::factory()->customer()->create();
        $owner = Customer::factory()->create(['user_id' => $ownerUser->id]);

        $deal = Deal::factory()->forCustomer($owner)->create();

        $results = Deal::accessibleByCustomer($owner)->get();

        expect($results)->toHaveCount(1);
        expect($results->first()->id)->toBe($deal->id);
    });

    it('accessibleByCustomer scope includes collaborated deals', function () {
        $ownerUser = User::factory()->customer()->create();
        $owner = Customer::factory()->create(['user_id' => $ownerUser->id]);
        $deal = Deal::factory()->forCustomer($owner)->create();

        $collabUser = User::factory()->customer()->create();
        $collabCustomer = Customer::factory()->create(['user_id' => $collabUser->id]);

        DealCollaborator::factory()->forDeal($deal)->forCustomer($collabCustomer)->create();

        $results = Deal::accessibleByCustomer($collabCustomer)->get();

        expect($results)->toHaveCount(1);
        expect($results->first()->id)->toBe($deal->id);
    });

    it('accessibleByCustomer scope excludes expired collaborations', function () {
        $ownerUser = User::factory()->customer()->create();
        $owner = Customer::factory()->create(['user_id' => $ownerUser->id]);
        $deal = Deal::factory()->forCustomer($owner)->create();

        $collabUser = User::factory()->customer()->create();
        $collabCustomer = Customer::factory()->create(['user_id' => $collabUser->id]);

        DealCollaborator::factory()->forDeal($deal)->forCustomer($collabCustomer)->expired()->create();

        $results = Deal::accessibleByCustomer($collabCustomer)->get();

        expect($results)->toHaveCount(0);
    });

    it('accessibleByCustomer scope excludes revoked collaborations', function () {
        $ownerUser = User::factory()->customer()->create();
        $owner = Customer::factory()->create(['user_id' => $ownerUser->id]);
        $deal = Deal::factory()->forCustomer($owner)->create();

        $collabUser = User::factory()->customer()->create();
        $collabCustomer = Customer::factory()->create(['user_id' => $collabUser->id]);

        DealCollaborator::factory()->forDeal($deal)->forCustomer($collabCustomer)->revoked()->create();

        $results = Deal::accessibleByCustomer($collabCustomer)->get();

        expect($results)->toHaveCount(0);
    });

    it('collaborators relationship returns all collaborators', function () {
        $deal = Deal::factory()->create();
        DealCollaborator::factory()->forDeal($deal)->count(3)->create();

        expect($deal->collaborators)->toHaveCount(3);
    });

    it('activeCollaborators relationship excludes expired and revoked', function () {
        $deal = Deal::factory()->create();
        DealCollaborator::factory()->forDeal($deal)->create(); // active
        DealCollaborator::factory()->forDeal($deal)->expired()->create(); // expired
        DealCollaborator::factory()->forDeal($deal)->revoked()->create(); // revoked

        expect($deal->activeCollaborators)->toHaveCount(1);
    });
});

// ---------------------------------------------------------------------------
// Customer Model - Collaboration Relationships
// ---------------------------------------------------------------------------

describe('Customer Model Collaborations', function () {
    it('customer has collaborations relationship', function () {
        $collabUser = User::factory()->customer()->create();
        $collabCustomer = Customer::factory()->create(['user_id' => $collabUser->id]);

        DealCollaborator::factory()->forCustomer($collabCustomer)->count(2)->create();

        expect($collabCustomer->collaborations)->toHaveCount(2);
    });

    it('customer activeCollaborations excludes expired', function () {
        $collabUser = User::factory()->customer()->create();
        $collabCustomer = Customer::factory()->create(['user_id' => $collabUser->id]);

        DealCollaborator::factory()->forCustomer($collabCustomer)->create(); // active
        DealCollaborator::factory()->forCustomer($collabCustomer)->expired()->create(); // expired

        expect($collabCustomer->activeCollaborations)->toHaveCount(1);
    });
});

// ---------------------------------------------------------------------------
// DealCollaboratorService Tests
// ---------------------------------------------------------------------------

describe('DealCollaboratorService', function () {
    it('owner can invite existing customer as collaborator', function () {
        Notification::fake();

        $ownerUser = User::factory()->customer()->create();
        $ownerCustomer = Customer::factory()->create(['user_id' => $ownerUser->id]);
        $deal = Deal::factory()->forCustomer($ownerCustomer)->create();

        $collabUser = User::factory()->customer()->create();
        $collabCustomer = Customer::factory()->create([
            'user_id' => $collabUser->id,
            'email' => $collabUser->email,
        ]);

        $result = app(DealCollaboratorService::class)->inviteCollaborator($deal, $ownerUser, $collabUser->email);

        expect($result)->toBeInstanceOf(DealCollaborator::class);
        expect($result->deal_id)->toBe($deal->id);
        expect($result->customer_id)->toBe($collabCustomer->id);
        expect($result->invited_by)->toBe($ownerUser->id);
        expect($result->expires_at)->not->toBeNull();

        Notification::assertSentTo($collabUser, CollaboratorAccessGranted::class);
    });

    it('owner can invite new user as collaborator via invitation', function () {
        Notification::fake();

        $ownerUser = User::factory()->customer()->create();
        $ownerCustomer = Customer::factory()->create(['user_id' => $ownerUser->id]);
        $deal = Deal::factory()->forCustomer($ownerCustomer)->create();

        $result = app(DealCollaboratorService::class)->inviteCollaborator($deal, $ownerUser, 'newperson@example.com');

        expect($result)->toBeInstanceOf(Invitation::class);
        expect($result->email)->toBe('newperson@example.com');
        expect($result->deal_id)->toBe($deal->id);
        expect($result->invited_user_type)->toBe('customer');
        expect($result->inviter_id)->toBe($ownerUser->id);
    });

    it('cannot self-invite', function () {
        $ownerUser = User::factory()->customer()->create();
        $ownerCustomer = Customer::factory()->create(['user_id' => $ownerUser->id]);
        $deal = Deal::factory()->forCustomer($ownerCustomer)->create();

        expect(fn () => app(DealCollaboratorService::class)->inviteCollaborator($deal, $ownerUser, $ownerUser->email))
            ->toThrow(InvalidArgumentException::class, 'You cannot invite yourself.');
    });

    it('cannot invite non-customer user type', function () {
        $ownerUser = User::factory()->customer()->create();
        $ownerCustomer = Customer::factory()->create(['user_id' => $ownerUser->id]);
        $deal = Deal::factory()->forCustomer($ownerCustomer)->create();

        $internalUser = User::factory()->internal()->create();

        expect(fn () => app(DealCollaboratorService::class)->inviteCollaborator($deal, $ownerUser, $internalUser->email))
            ->toThrow(InvalidArgumentException::class, 'cannot be added as a collaborator');
    });

    it('cannot invite agent user type', function () {
        $ownerUser = User::factory()->customer()->create();
        $ownerCustomer = Customer::factory()->create(['user_id' => $ownerUser->id]);
        $deal = Deal::factory()->forCustomer($ownerCustomer)->create();

        $agentUser = User::factory()->agent()->create();

        expect(fn () => app(DealCollaboratorService::class)->inviteCollaborator($deal, $ownerUser, $agentUser->email))
            ->toThrow(InvalidArgumentException::class, 'cannot be added as a collaborator');
    });

    it('cannot duplicate active collaboration', function () {
        Notification::fake();

        $ownerUser = User::factory()->customer()->create();
        $ownerCustomer = Customer::factory()->create(['user_id' => $ownerUser->id]);
        $deal = Deal::factory()->forCustomer($ownerCustomer)->create();

        $collabUser = User::factory()->customer()->create();
        $collabCustomer = Customer::factory()->create([
            'user_id' => $collabUser->id,
            'email' => $collabUser->email,
        ]);

        // First invite succeeds
        app(DealCollaboratorService::class)->inviteCollaborator($deal, $ownerUser, $collabUser->email);

        // Second invite throws
        expect(fn () => app(DealCollaboratorService::class)->inviteCollaborator($deal, $ownerUser, $collabUser->email))
            ->toThrow(InvalidArgumentException::class, 'already has active access');
    });

    it('non-owner cannot invite collaborator', function () {
        $ownerUser = User::factory()->customer()->create();
        $ownerCustomer = Customer::factory()->create(['user_id' => $ownerUser->id]);
        $deal = Deal::factory()->forCustomer($ownerCustomer)->create();

        $otherUser = User::factory()->customer()->create();
        Customer::factory()->create(['user_id' => $otherUser->id]);

        expect(fn () => app(DealCollaboratorService::class)->inviteCollaborator($deal, $otherUser, 'someone@example.com'))
            ->toThrow(InvalidArgumentException::class, 'You do not own this deal.');
    });

    it('revokeAccess sets revoked_at', function () {
        $collab = DealCollaborator::factory()->create();

        app(DealCollaboratorService::class)->revokeAccess($collab);

        $collab->refresh();
        expect($collab->revoked_at)->not->toBeNull();
        expect($collab->isActive())->toBeFalse();
    });

    it('extendAccess updates expires_at', function () {
        $collab = DealCollaborator::factory()->create([
            'expires_at' => now()->addDays(5),
        ]);

        app(DealCollaboratorService::class)->extendAccess($collab);

        $collab->refresh();
        expect($collab->expires_at->isFuture())->toBeTrue();
        expect((int) now()->diffInDays($collab->expires_at, absolute: false))->toBeGreaterThanOrEqual(29);
        expect($collab->isActive())->toBeTrue();
    });

    it('extendAccess accepts custom days parameter', function () {
        $collab = DealCollaborator::factory()->create([
            'expires_at' => now()->addDays(5),
        ]);

        app(DealCollaboratorService::class)->extendAccess($collab, 60);

        $collab->refresh();
        expect($collab->expires_at->isFuture())->toBeTrue();
        expect((int) now()->diffInDays($collab->expires_at, absolute: false))->toBeGreaterThanOrEqual(59);
    });
});

// ---------------------------------------------------------------------------
// Invitation Acceptance - Collaborator Flow
// ---------------------------------------------------------------------------

describe('Invitation Acceptance for Collaborators', function () {
    it('new user accepting collaborator invitation gets deal collaborator record', function () {
        $ownerUser = User::factory()->customer()->create();
        $ownerCustomer = Customer::factory()->create(['user_id' => $ownerUser->id]);
        $deal = Deal::factory()->forCustomer($ownerCustomer)->create();

        $invitation = Invitation::create([
            'email' => 'newcollab@example.com',
            'invited_user_type' => 'customer',
            'inviter_id' => $ownerUser->id,
            'deal_id' => $deal->id,
        ]);

        $user = app(InvitationService::class)->acceptInvitation($invitation, [
            'name' => 'New Collab',
            'password' => 'password123',
        ]);

        expect($user)->toBeInstanceOf(User::class);
        expect($user->type)->toBe('customer');
        expect(DealCollaborator::where('deal_id', $deal->id)->where('invited_by', $ownerUser->id)->exists())->toBeTrue();
    });

    it('new user accepting collaborator invitation skips onboarding', function () {
        $ownerUser = User::factory()->customer()->create();
        $ownerCustomer = Customer::factory()->create(['user_id' => $ownerUser->id]);
        $deal = Deal::factory()->forCustomer($ownerCustomer)->create();

        $invitation = Invitation::create([
            'email' => 'newcollab2@example.com',
            'invited_user_type' => 'customer',
            'inviter_id' => $ownerUser->id,
            'deal_id' => $deal->id,
        ]);

        $user = app(InvitationService::class)->acceptInvitation($invitation, [
            'name' => 'Another Collab',
            'password' => 'password123',
        ]);

        expect($user->needs_onboarding)->toBeFalse();
    });

    it('regular customer invitation still requires onboarding', function () {
        $internalUser = User::factory()->internal()->create();

        $invitation = Invitation::create([
            'email' => 'regular@example.com',
            'invited_user_type' => 'customer',
            'inviter_id' => $internalUser->id,
        ]);

        $user = app(InvitationService::class)->acceptInvitation($invitation, [
            'name' => 'Regular Customer',
            'password' => 'password123',
        ]);

        expect($user->needs_onboarding)->toBeTrue();
        expect(DealCollaborator::count())->toBe(0);
    });

    it('invitation is marked as accepted after collaborator flow', function () {
        $ownerUser = User::factory()->customer()->create();
        $ownerCustomer = Customer::factory()->create(['user_id' => $ownerUser->id]);
        $deal = Deal::factory()->forCustomer($ownerCustomer)->create();

        $invitation = Invitation::create([
            'email' => 'collabaccept@example.com',
            'invited_user_type' => 'customer',
            'inviter_id' => $ownerUser->id,
            'deal_id' => $deal->id,
        ]);

        app(InvitationService::class)->acceptInvitation($invitation, [
            'name' => 'Collab Accept',
            'password' => 'password123',
        ]);

        $invitation->refresh();
        expect($invitation->isAccepted())->toBeTrue();
    });
});

// ---------------------------------------------------------------------------
// Livewire Collaborators Component Tests
// ---------------------------------------------------------------------------

describe('Collaborators Livewire Component', function () {
    it('owner sees share project button', function () {
        $ownerUser = User::factory()->customer()->create();
        $ownerCustomer = Customer::factory()->create(['user_id' => $ownerUser->id]);
        $deal = Deal::factory()->forCustomer($ownerCustomer)->create();

        Livewire::actingAs($ownerUser, 'customer')
            ->test('customer.collaborators', ['dealId' => $deal->id])
            ->assertStatus(200)
            ->assertSee('Share Project');
    });

    it('collaborator does not see invite button', function () {
        $ownerUser = User::factory()->customer()->create();
        $ownerCustomer = Customer::factory()->create(['user_id' => $ownerUser->id]);
        $deal = Deal::factory()->forCustomer($ownerCustomer)->create();

        $collabUser = User::factory()->customer()->create();
        $collabCustomer = Customer::factory()->create(['user_id' => $collabUser->id]);
        DealCollaborator::factory()->forDeal($deal)->forCustomer($collabCustomer)->create();

        Livewire::actingAs($collabUser, 'customer')
            ->test('customer.collaborators', ['dealId' => $deal->id])
            ->assertStatus(200)
            ->assertSee('Shared Project')
            ->assertDontSee('Share Project');
    });

    it('collaborator sees shared project info with days remaining', function () {
        $ownerUser = User::factory()->customer()->create();
        $ownerCustomer = Customer::factory()->create(['user_id' => $ownerUser->id]);
        $deal = Deal::factory()->forCustomer($ownerCustomer)->create();

        $collabUser = User::factory()->customer()->create();
        $collabCustomer = Customer::factory()->create(['user_id' => $collabUser->id]);
        DealCollaborator::factory()->forDeal($deal)->forCustomer($collabCustomer)->create([
            'expires_at' => now()->addDays(20),
        ]);

        Livewire::actingAs($collabUser, 'customer')
            ->test('customer.collaborators', ['dealId' => $deal->id])
            ->assertSee('Shared Project')
            ->assertSee('days');
    });

    it('invite validates email format', function () {
        $ownerUser = User::factory()->customer()->create();
        $ownerCustomer = Customer::factory()->create(['user_id' => $ownerUser->id]);
        $deal = Deal::factory()->forCustomer($ownerCustomer)->create();

        Livewire::actingAs($ownerUser, 'customer')
            ->test('customer.collaborators', ['dealId' => $deal->id])
            ->set('inviteEmail', 'not-an-email')
            ->call('invite')
            ->assertHasErrors(['inviteEmail']);
    });

    it('invite validates email is required', function () {
        $ownerUser = User::factory()->customer()->create();
        $ownerCustomer = Customer::factory()->create(['user_id' => $ownerUser->id]);
        $deal = Deal::factory()->forCustomer($ownerCustomer)->create();

        Livewire::actingAs($ownerUser, 'customer')
            ->test('customer.collaborators', ['dealId' => $deal->id])
            ->set('inviteEmail', '')
            ->call('invite')
            ->assertHasErrors(['inviteEmail']);
    });

    it('owner can revoke collaborator', function () {
        $ownerUser = User::factory()->customer()->create();
        $ownerCustomer = Customer::factory()->create(['user_id' => $ownerUser->id]);
        $deal = Deal::factory()->forCustomer($ownerCustomer)->create();

        $collabUser = User::factory()->customer()->create();
        $collabCustomer = Customer::factory()->create(['user_id' => $collabUser->id]);
        $collab = DealCollaborator::factory()->forDeal($deal)->forCustomer($collabCustomer)->create();

        Livewire::actingAs($ownerUser, 'customer')
            ->test('customer.collaborators', ['dealId' => $deal->id])
            ->call('revoke', $collab->id);

        $collab->refresh();
        expect($collab->revoked_at)->not->toBeNull();
    });

    it('owner can extend collaborator access', function () {
        $ownerUser = User::factory()->customer()->create();
        $ownerCustomer = Customer::factory()->create(['user_id' => $ownerUser->id]);
        $deal = Deal::factory()->forCustomer($ownerCustomer)->create();

        $collabUser = User::factory()->customer()->create();
        $collabCustomer = Customer::factory()->create(['user_id' => $collabUser->id]);
        $collab = DealCollaborator::factory()->forDeal($deal)->forCustomer($collabCustomer)->create([
            'expires_at' => now()->addDays(5),
        ]);

        Livewire::actingAs($ownerUser, 'customer')
            ->test('customer.collaborators', ['dealId' => $deal->id])
            ->call('extend', $collab->id);

        $collab->refresh();
        expect($collab->expires_at->isFuture())->toBeTrue();
        expect((int) now()->diffInDays($collab->expires_at, absolute: false))->toBeGreaterThanOrEqual(29);
    });

    it('shows empty state when no collaborators', function () {
        $ownerUser = User::factory()->customer()->create();
        $ownerCustomer = Customer::factory()->create(['user_id' => $ownerUser->id]);
        $deal = Deal::factory()->forCustomer($ownerCustomer)->create();

        Livewire::actingAs($ownerUser, 'customer')
            ->test('customer.collaborators', ['dealId' => $deal->id])
            ->assertSee('No one else has access to this project');
    });
});

// ---------------------------------------------------------------------------
// Customer Dashboard - Collaborator Deal Visibility
// ---------------------------------------------------------------------------

describe('Customer Dashboard Collaborator Visibility', function () {
    it('collaborator can see shared deal on dashboard', function () {
        $ownerUser = User::factory()->customer()->create();
        $ownerCustomer = Customer::factory()->create(['user_id' => $ownerUser->id]);
        $deal = Deal::factory()->forCustomer($ownerCustomer)->create([
            'title' => 'Shared Kitchen Project',
            'stage' => 'quoted',
        ]);

        $collabUser = User::factory()->customer()->create();
        $collabCustomer = Customer::factory()->create(['user_id' => $collabUser->id]);
        DealCollaborator::factory()->forDeal($deal)->forCustomer($collabCustomer)->create();

        // When a collaborator has access to a deal, the dashboard shows "Shared Project" callout
        Livewire::actingAs($collabUser, 'customer')
            ->test('customer.dashboard')
            ->assertSee('Shared Project')
            ->assertDontSee('No Active Projects');
    });

    it('collaborator cannot see unshared deal', function () {
        $ownerUser = User::factory()->customer()->create();
        $ownerCustomer = Customer::factory()->create(['user_id' => $ownerUser->id]);

        $deal1 = Deal::factory()->forCustomer($ownerCustomer)->create([
            'title' => 'Shared Project',
            'stage' => 'quoted',
        ]);
        Deal::factory()->forCustomer($ownerCustomer)->create([
            'title' => 'Private Project',
            'stage' => 'quoted',
        ]);

        $collabUser = User::factory()->customer()->create();
        $collabCustomer = Customer::factory()->create(['user_id' => $collabUser->id]);
        DealCollaborator::factory()->forDeal($deal1)->forCustomer($collabCustomer)->create();

        // Only 1 deal accessible (shared), so no project switcher displayed
        // Verify the accessibleByCustomer scope only returns 1 deal
        $accessibleDeals = Deal::accessibleByCustomer($collabCustomer)->get();
        expect($accessibleDeals)->toHaveCount(1);
        expect($accessibleDeals->first()->id)->toBe($deal1->id);
    });

    it('expired collaborator cannot see deal on dashboard', function () {
        $ownerUser = User::factory()->customer()->create();
        $ownerCustomer = Customer::factory()->create(['user_id' => $ownerUser->id]);
        Deal::factory()->forCustomer($ownerCustomer)->create([
            'title' => 'Expired Access Project',
            'stage' => 'quoted',
        ]);

        $collabUser = User::factory()->customer()->create();
        $collabCustomer = Customer::factory()->create(['user_id' => $collabUser->id]);
        DealCollaborator::factory()->forDeal(Deal::first())->forCustomer($collabCustomer)->expired()->create();

        Livewire::actingAs($collabUser, 'customer')
            ->test('customer.dashboard')
            ->assertDontSee('Shared Project')
            ->assertSee('No Active Projects');
    });

    it('revoked collaborator cannot see deal on dashboard', function () {
        $ownerUser = User::factory()->customer()->create();
        $ownerCustomer = Customer::factory()->create(['user_id' => $ownerUser->id]);
        Deal::factory()->forCustomer($ownerCustomer)->create([
            'title' => 'Revoked Access Project',
            'stage' => 'quoted',
        ]);

        $collabUser = User::factory()->customer()->create();
        $collabCustomer = Customer::factory()->create(['user_id' => $collabUser->id]);
        DealCollaborator::factory()->forDeal(Deal::first())->forCustomer($collabCustomer)->revoked()->create();

        Livewire::actingAs($collabUser, 'customer')
            ->test('customer.dashboard')
            ->assertDontSee('Shared Project')
            ->assertSee('No Active Projects');
    });
});
