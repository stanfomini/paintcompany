<?php

use App\Models\Invitation;
use App\Models\User;
use App\Notifications\AgentInvitationNotification;
use App\Notifications\InvitationNotification;
use App\Services\InvitationService;
use Illuminate\Support\Facades\Notification;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

describe('Agent invitation notification', function () {
    it('sends AgentInvitationNotification when inviting an agent', function () {
        Notification::fake();

        $inviter = User::factory()->internal()->withFullAccess()->create();
        $service = app(InvitationService::class);

        $invitation = $service->sendInvitation($inviter, 'agent@test.com', 'agent');

        Notification::assertSentTo(
            $invitation,
            AgentInvitationNotification::class,
        );

        Notification::assertNotSentTo(
            $invitation,
            InvitationNotification::class,
        );
    });

    it('sends generic InvitationNotification when inviting a customer', function () {
        Notification::fake();

        $inviter = User::factory()->internal()->withFullAccess()->create();
        $service = app(InvitationService::class);

        $invitation = $service->sendInvitation($inviter, 'customer@test.com', 'customer');

        Notification::assertSentTo(
            $invitation,
            InvitationNotification::class,
        );

        Notification::assertNotSentTo(
            $invitation,
            AgentInvitationNotification::class,
        );
    });

    it('renders the agent invitation email with correct content', function () {
        $inviter = User::factory()->internal()->withFullAccess()->create(['name' => 'John Smith']);

        $invitation = Invitation::create([
            'email' => 'testagent@example.com',
            'invited_user_type' => 'agent',
            'inviter_id' => $inviter->id,
        ]);

        $notification = new AgentInvitationNotification($invitation);
        $mail = $notification->toMail($invitation);

        expect($mail->subject)->toBe('Free Virtual Painting Estimates for Every Home You List');

        $rendered = (string) $mail->render();

        expect($rendered)->toContain('John Smith')
            ->toContain('Upload your listing photos or blueprints')
            ->toContain('Create Your Free Account')
            ->toContain('(757) 343-3032')
            ->toContain('production-rate pricing');
    });

    it('sends AgentInvitationNotification when resending to existing pending agent', function () {
        Notification::fake();

        $inviter = User::factory()->internal()->withFullAccess()->create();
        $service = app(InvitationService::class);

        // First send
        $invitation = $service->sendInvitation($inviter, 'resend-agent@test.com', 'agent');
        Notification::assertSentToTimes($invitation, AgentInvitationNotification::class, 1);

        // Resend to same email
        $resent = $service->sendInvitation($inviter, 'resend-agent@test.com', 'agent');
        Notification::assertSentToTimes($resent, AgentInvitationNotification::class, 2);

        expect($resent->id)->toBe($invitation->id);
    });
});

describe('SendAgentInvitations command', function () {
    it('sends invitations from the hardcoded email list', function () {
        Notification::fake();

        $inviter = User::factory()->internal()->withFullAccess()->create();

        $this->artisan('invitations:send-agents', [
            '--inviter' => $inviter->id,
        ])->assertSuccessful();

        expect(Invitation::where('invited_user_type', 'agent')->count())->toBe(15);
    });

    it('supports dry-run mode without sending', function () {
        Notification::fake();

        $inviter = User::factory()->internal()->withFullAccess()->create();

        $this->artisan('invitations:send-agents', [
            '--inviter' => $inviter->id,
            '--dry-run' => true,
        ])->assertSuccessful();

        expect(Invitation::count())->toBe(0);
    });

    it('requires a valid internal inviter', function () {
        $agent = User::factory()->agent()->create();

        $this->artisan('invitations:send-agents', [
            '--inviter' => $agent->id,
        ])->assertFailed();
    });

    it('defaults to first internal user when no inviter specified', function () {
        Notification::fake();

        $inviter = User::factory()->internal()->withFullAccess()->create();

        $this->artisan('invitations:send-agents')->assertSuccessful();

        $invitation = Invitation::first();
        expect($invitation)->not->toBeNull();
        expect($invitation->inviter_id)->toBe($inviter->id);
    });
});
