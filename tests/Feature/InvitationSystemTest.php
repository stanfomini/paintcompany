<?php

use App\Models\ChatThread;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Invitation;
use App\Models\User;
use App\Services\InvitationService;
use Illuminate\Support\Facades\Notification;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('InvitationService permissions', function () {
    it('allows internal users to invite internal users', function () {
        $service = app(InvitationService::class);
        $user = User::factory()->internal()->withFullAccess()->create();

        expect($service->canInvite($user, 'internal'))->toBeTrue();
    });

    it('allows internal users to invite agents', function () {
        $service = app(InvitationService::class);
        $user = User::factory()->internal()->withFullAccess()->create();

        expect($service->canInvite($user, 'agent'))->toBeTrue();
    });

    it('allows internal users to invite customers', function () {
        $service = app(InvitationService::class);
        $user = User::factory()->internal()->withFullAccess()->create();

        expect($service->canInvite($user, 'customer'))->toBeTrue();
    });

    it('allows agents to invite customers only', function () {
        $service = app(InvitationService::class);
        $user = User::factory()->agent()->create();

        expect($service->canInvite($user, 'customer'))->toBeTrue();
        expect($service->canInvite($user, 'agent'))->toBeFalse();
        expect($service->canInvite($user, 'internal'))->toBeFalse();
    });

    it('allows customers to invite other customers only', function () {
        $service = app(InvitationService::class);
        $user = User::factory()->customer()->create();

        expect($service->canInvite($user, 'customer'))->toBeTrue();
        expect($service->canInvite($user, 'agent'))->toBeFalse();
        expect($service->canInvite($user, 'internal'))->toBeFalse();
    });

    it('returns correct invitable types for internal users', function () {
        $service = app(InvitationService::class);
        $user = User::factory()->internal()->withFullAccess()->create();

        expect($service->getInvitableTypes($user))->toBe(['internal', 'agent', 'customer', 'crew']);
    });

    it('returns correct invitable types for agents', function () {
        $service = app(InvitationService::class);
        $user = User::factory()->agent()->create();

        expect($service->getInvitableTypes($user))->toBe(['customer']);
    });

    it('returns customer array for customers', function () {
        $service = app(InvitationService::class);
        $user = User::factory()->customer()->create();

        expect($service->getInvitableTypes($user))->toBe(['customer']);
    });
});

describe('Invitation model', function () {
    it('generates a token on creation', function () {
        $inviter = User::factory()->internal()->withFullAccess()->create();

        $invitation = Invitation::create([
            'email' => 'test@example.com',
            'invited_user_type' => 'customer',
            'inviter_id' => $inviter->id,
        ]);

        expect($invitation->token)->toHaveLength(64);
    });

    it('sets default expiration to 7 days', function () {
        $inviter = User::factory()->internal()->withFullAccess()->create();

        $invitation = Invitation::create([
            'email' => 'test@example.com',
            'invited_user_type' => 'customer',
            'inviter_id' => $inviter->id,
        ]);

        expect(round(now()->diffInDays($invitation->expires_at)))->toBe(7.0);
    });

    it('correctly identifies pending invitations', function () {
        $invitation = Invitation::factory()->create();

        expect($invitation->isPending())->toBeTrue();
        expect($invitation->isExpired())->toBeFalse();
        expect($invitation->isAccepted())->toBeFalse();
    });

    it('correctly identifies expired invitations', function () {
        $invitation = Invitation::factory()->expired()->create();

        expect($invitation->isPending())->toBeFalse();
        expect($invitation->isExpired())->toBeTrue();
    });

    it('correctly identifies accepted invitations', function () {
        $invitation = Invitation::factory()->accepted()->create();

        expect($invitation->isPending())->toBeFalse();
        expect($invitation->isAccepted())->toBeTrue();
    });

    it('has inviter relationship', function () {
        $inviter = User::factory()->internal()->withFullAccess()->create();
        $invitation = Invitation::factory()->create(['inviter_id' => $inviter->id]);

        expect($invitation->inviter->id)->toBe($inviter->id);
    });
});

describe('InvitationService sending', function () {
    it('creates invitation successfully', function () {
        Notification::fake();

        $service = app(InvitationService::class);
        $inviter = User::factory()->internal()->withFullAccess()->create();

        $invitation = $service->sendInvitation($inviter, 'new@example.com', 'customer');

        expect($invitation)->toBeInstanceOf(Invitation::class);
        expect($invitation->email)->toBe('new@example.com');
        expect($invitation->invited_user_type)->toBe('customer');
        expect($invitation->inviter_id)->toBe($inviter->id);
    });

    it('throws exception when user cannot invite type', function () {
        $service = app(InvitationService::class);
        $agent = User::factory()->agent()->create();

        expect(fn () => $service->sendInvitation($agent, 'test@example.com', 'internal'))
            ->toThrow(\InvalidArgumentException::class);
    });

    it('throws exception when user already exists with same type', function () {
        $service = app(InvitationService::class);
        $inviter = User::factory()->internal()->withFullAccess()->create();
        User::factory()->customer()->create(['email' => 'existing@example.com']);

        expect(fn () => $service->sendInvitation($inviter, 'existing@example.com', 'customer'))
            ->toThrow(\InvalidArgumentException::class);
    });

    it('returns existing pending invitation instead of creating new one', function () {
        Notification::fake();

        $service = app(InvitationService::class);
        $inviter = User::factory()->internal()->withFullAccess()->create();

        $first = $service->sendInvitation($inviter, 'test@example.com', 'customer');
        $second = $service->sendInvitation($inviter, 'test@example.com', 'customer');

        expect($first->id)->toBe($second->id);
    });
});

describe('InvitationService acceptance', function () {
    it('accepts invitation and creates user', function () {
        $service = app(InvitationService::class);
        $invitation = Invitation::factory()->forCustomer()->create();

        $user = $service->acceptInvitation($invitation, [
            'name' => 'New User',
            'password' => 'password123',
        ]);

        expect($user)->toBeInstanceOf(User::class);
        expect($user->email)->toBe($invitation->email);
        expect($user->type)->toBe('customer');
        expect($user->name)->toBe('New User');
        expect($invitation->fresh()->isAccepted())->toBeTrue();
    });

    it('throws exception when accepting expired invitation', function () {
        $service = app(InvitationService::class);
        $invitation = Invitation::factory()->expired()->create();

        expect(fn () => $service->acceptInvitation($invitation, [
            'name' => 'Test User',
            'password' => 'password123',
        ]))->toThrow(\InvalidArgumentException::class);
    });

    it('throws exception when accepting already accepted invitation', function () {
        $service = app(InvitationService::class);
        $invitation = Invitation::factory()->accepted()->create();

        expect(fn () => $service->acceptInvitation($invitation, [
            'name' => 'Test User',
            'password' => 'password123',
        ]))->toThrow(\InvalidArgumentException::class);
    });

    it('links existing customer record to user on acceptance', function () {
        $service = app(InvitationService::class);
        $email = 'customer-link@example.com';

        // Create a customer record first (as happens during normal flow)
        $customer = Customer::factory()->create(['email' => $email]);
        expect($customer->user_id)->toBeNull();

        // Create invitation with same email
        $invitation = Invitation::factory()->forCustomer()->create(['email' => $email]);

        // Accept invitation
        $user = $service->acceptInvitation($invitation, [
            'name' => 'Linked Customer',
            'password' => 'password123',
        ]);

        // Customer record should now be linked
        $customer->refresh();
        expect($customer->user_id)->toBe($user->id);
        expect($customer->hasPortalAccess())->toBeTrue();
    });

    it('adds user as participant in existing deal chat threads on acceptance', function () {
        $service = app(InvitationService::class);
        $email = 'chat-link@example.com';

        // Create customer with deal and chat thread
        $customer = Customer::factory()->create(['email' => $email]);
        $deal = Deal::factory()->forCustomer($customer)->create();
        $thread = ChatThread::factory()->forDeal($deal)->create();

        // Add some existing participants
        $internalUser = User::factory()->internal()->withFullAccess()->create();
        $thread->addParticipant($internalUser);

        // Accept invitation
        $invitation = Invitation::factory()->forCustomer()->create(['email' => $email]);
        $user = $service->acceptInvitation($invitation, [
            'name' => 'Chat Customer',
            'password' => 'password123',
        ]);

        // User should be a participant in the deal's chat thread
        expect($thread->hasParticipant($user))->toBeTrue();
    });

    it('does not link customer when accepting non-customer invitation', function () {
        $service = app(InvitationService::class);
        $email = 'agent-invite@example.com';

        // Create a customer record with same email
        $customer = Customer::factory()->create(['email' => $email]);

        // Create agent invitation
        $invitation = Invitation::factory()->forAgent()->create(['email' => $email]);

        $user = $service->acceptInvitation($invitation, [
            'name' => 'New Agent',
            'password' => 'password123',
        ]);

        // Customer record should NOT be linked (this was an agent invite)
        $customer->refresh();
        expect($customer->user_id)->toBeNull();
    });

    it('sets needs_onboarding to true for crew invitations', function () {
        $service = app(InvitationService::class);
        $invitation = Invitation::factory()->forCrew()->create();

        $user = $service->acceptInvitation($invitation, [
            'name' => 'New Crew Member',
            'password' => 'password123',
        ]);

        expect($user->type)->toBe('crew');
        expect($user->needs_onboarding)->toBeTrue();
    });

    it('sets needs_onboarding to true for customer invitations', function () {
        $service = app(InvitationService::class);
        $invitation = Invitation::factory()->forCustomer()->create();

        $user = $service->acceptInvitation($invitation, [
            'name' => 'New Customer',
            'password' => 'password123',
        ]);

        expect($user->type)->toBe('customer');
        expect($user->needs_onboarding)->toBeTrue();
    });

    it('sets needs_onboarding to true for agent invitations', function () {
        $service = app(InvitationService::class);
        $invitation = Invitation::factory()->forAgent()->create();

        $user = $service->acceptInvitation($invitation, [
            'name' => 'New Agent',
            'password' => 'password123',
        ]);

        expect($user->type)->toBe('agent');
        expect($user->needs_onboarding)->toBeTrue();
    });
});

describe('InvitationService URL generation', function () {
    it('generates signed URL for invitation', function () {
        $service = app(InvitationService::class);
        $invitation = Invitation::factory()->create();

        $url = $service->generateAcceptUrl($invitation);

        expect($url)->toContain('/invitation/accept/');
        expect($url)->toContain($invitation->token);
        expect($url)->toContain('signature=');
    });
});

describe('Invitation routes', function () {
    it('shows invitation acceptance page with valid signed URL', function () {
        $invitation = Invitation::factory()->create();
        $service = app(InvitationService::class);
        $url = $service->generateAcceptUrl($invitation);

        $response = $this->get($url);

        $response->assertOk();
    });

    it('rejects unsigned invitation URL', function () {
        $invitation = Invitation::factory()->create();

        $response = $this->get("/invitation/accept/{$invitation->token}");

        $response->assertForbidden();
    });

    it('requires authentication to access invitations page', function () {
        $response = $this->get(route('invitations'));

        $response->assertRedirect(route('login'));
    });

    it('allows internal users to access invitations page', function () {
        $user = User::factory()->internal()->withFullAccess()->create();

        $response = $this->actingAs($user)
            ->get(route('invitations'));

        $response->assertOk();
    });
});

describe('Invitation factory', function () {
    it('creates valid invitation', function () {
        $invitation = Invitation::factory()->create();

        expect($invitation->email)->not->toBeEmpty();
        expect($invitation->token)->toHaveLength(64);
        expect($invitation->invited_user_type)->toBeIn(['internal', 'agent', 'customer', 'crew']);
    });

    it('creates invitation for specific type', function () {
        $invitation = Invitation::factory()->forAgent()->create();

        expect($invitation->invited_user_type)->toBe('agent');
    });

    it('creates invitation from agent', function () {
        $invitation = Invitation::factory()->fromAgent()->create();

        expect($invitation->inviter->type)->toBe('agent');
        expect($invitation->invited_user_type)->toBe('customer');
    });
});
