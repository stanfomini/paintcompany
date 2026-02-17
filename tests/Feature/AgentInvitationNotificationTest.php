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
    it('sends invitations to valid emails', function () {
        Notification::fake();

        $inviter = User::factory()->internal()->withFullAccess()->create();

        $this->artisan('invitations:send-agents', [
            '--emails' => 'agent1@test.com,agent2@test.com',
            '--inviter' => $inviter->id,
        ])->assertSuccessful();

        expect(Invitation::where('invited_user_type', 'agent')->count())->toBe(2);
    });

    it('supports dry-run mode without sending', function () {
        Notification::fake();

        $inviter = User::factory()->internal()->withFullAccess()->create();

        $this->artisan('invitations:send-agents', [
            '--emails' => 'dryrun@test.com',
            '--inviter' => $inviter->id,
            '--dry-run' => true,
        ])->assertSuccessful();

        expect(Invitation::where('email', 'dryrun@test.com')->exists())->toBeFalse();
    });

    it('requires a valid internal inviter', function () {
        $agent = User::factory()->agent()->create();

        $this->artisan('invitations:send-agents', [
            '--emails' => 'test@test.com',
            '--inviter' => $agent->id,
        ])->assertFailed();
    });

    it('skips already existing users', function () {
        Notification::fake();

        $inviter = User::factory()->internal()->withFullAccess()->create();
        User::factory()->agent()->create(['email' => 'existing@test.com']);

        $this->artisan('invitations:send-agents', [
            '--emails' => 'existing@test.com,new@test.com',
            '--inviter' => $inviter->id,
        ])->assertSuccessful();

        expect(Invitation::where('invited_user_type', 'agent')->count())->toBe(1);
        expect(Invitation::where('email', 'new@test.com')->exists())->toBeTrue();
    });

    it('filters invalid email addresses', function () {
        $inviter = User::factory()->internal()->withFullAccess()->create();

        $this->artisan('invitations:send-agents', [
            '--emails' => 'not-an-email,also-bad',
            '--inviter' => $inviter->id,
        ])->assertFailed();
    });
});
