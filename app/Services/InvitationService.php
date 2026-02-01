<?php

namespace App\Services;

use App\Models\Invitation;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Support\Facades\URL;

class InvitationService
{
    /**
     * Permission matrix: who can invite whom
     * Internal users can invite: internal, agent, customer
     * Agents can invite: customer only
     * Customers cannot invite anyone
     */
    private const INVITATION_PERMISSIONS = [
        'internal' => ['internal', 'agent', 'customer'],
        'agent' => ['customer'],
        'customer' => [],
    ];

    /**
     * Get the user types that the given user can invite.
     */
    public function getInvitableTypes(User $user): array
    {
        return self::INVITATION_PERMISSIONS[$user->type] ?? [];
    }

    /**
     * Check if a user can invite a specific user type.
     */
    public function canInvite(User $inviter, string $invitedUserType): bool
    {
        $allowedTypes = $this->getInvitableTypes($inviter);

        return in_array($invitedUserType, $allowedTypes, true);
    }

    /**
     * Check if a user can send invitations at all.
     */
    public function canSendInvitations(User $user): bool
    {
        return count($this->getInvitableTypes($user)) > 0;
    }

    /**
     * Create and send an invitation.
     *
     * @throws \InvalidArgumentException
     */
    public function sendInvitation(User $inviter, string $email, string $invitedUserType): Invitation
    {
        if (! $this->canInvite($inviter, $invitedUserType)) {
            throw new \InvalidArgumentException(
                "User type '{$inviter->type}' cannot invite '{$invitedUserType}' users."
            );
        }

        // Check if there's already a pending invitation for this email and type
        $existingInvitation = Invitation::query()
            ->forEmail($email)
            ->where('invited_user_type', $invitedUserType)
            ->pending()
            ->first();

        if ($existingInvitation) {
            // Resend the existing invitation to the invitee's email
            $existingInvitation->notify(new InvitationNotification($existingInvitation));

            return $existingInvitation;
        }

        // Check if user already exists with this email and type
        $existingUser = User::where('email', $email)
            ->where('type', $invitedUserType)
            ->first();

        if ($existingUser) {
            throw new \InvalidArgumentException(
                "A user with this email already exists as a {$invitedUserType}."
            );
        }

        // Create the invitation
        $invitation = Invitation::create([
            'email' => $email,
            'invited_user_type' => $invitedUserType,
            'inviter_id' => $inviter->id,
        ]);

        // Send the notification
        $invitation->notify(new InvitationNotification($invitation));

        return $invitation;
    }

    /**
     * Generate a signed URL for accepting an invitation.
     */
    public function generateAcceptUrl(Invitation $invitation): string
    {
        return URL::signedRoute(
            'invitation.accept',
            ['token' => $invitation->token],
            $invitation->expires_at
        );
    }

    /**
     * Find an invitation by token.
     */
    public function findByToken(string $token): ?Invitation
    {
        return Invitation::where('token', $token)->first();
    }

    /**
     * Validate an invitation token.
     */
    public function validateInvitation(string $token): ?Invitation
    {
        $invitation = $this->findByToken($token);

        if (! $invitation) {
            return null;
        }

        if ($invitation->isExpired() || $invitation->isAccepted()) {
            return null;
        }

        return $invitation;
    }

    /**
     * Accept an invitation and create the user.
     */
    public function acceptInvitation(Invitation $invitation, array $userData): User
    {
        if ($invitation->isExpired()) {
            throw new \InvalidArgumentException('This invitation has expired.');
        }

        if ($invitation->isAccepted()) {
            throw new \InvalidArgumentException('This invitation has already been accepted.');
        }

        // Create the user
        $user = User::create([
            'name' => $userData['name'],
            'email' => $invitation->email,
            'password' => bcrypt($userData['password']),
            'type' => $invitation->invited_user_type,
            'is_active' => true,
        ]);

        // Mark invitation as accepted
        $invitation->markAsAccepted();

        return $user;
    }

    /**
     * Get pending invitations sent by a user.
     */
    public function getPendingInvitations(User $user): \Illuminate\Database\Eloquent\Collection
    {
        return Invitation::query()
            ->where('inviter_id', $user->id)
            ->pending()
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Cancel/delete an invitation.
     */
    public function cancelInvitation(Invitation $invitation, User $user): bool
    {
        // Only the inviter or an internal user can cancel
        if ($invitation->inviter_id !== $user->id && ! $user->isInternal()) {
            return false;
        }

        return $invitation->delete();
    }
}
