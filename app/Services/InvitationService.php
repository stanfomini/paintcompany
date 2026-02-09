<?php

namespace App\Services;

use App\Models\CrewProfile;
use App\Models\Customer;
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
        'internal' => ['internal', 'agent', 'customer', 'crew'],
        'agent' => ['customer'],
        'customer' => [],
        'crew' => [],
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
    public function sendInvitation(User $inviter, string $email, string $invitedUserType, ?int $serviceCategoryId = null): Invitation
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
        $invitationData = [
            'email' => $email,
            'invited_user_type' => $invitedUserType,
            'inviter_id' => $inviter->id,
        ];

        // Attach service category for crew invitations
        if ($invitedUserType === 'crew' && $serviceCategoryId) {
            $invitationData['service_category_id'] = $serviceCategoryId;
        }

        $invitation = Invitation::create($invitationData);

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

        // Link existing Customer record to this user (for customer invitations)
        if ($invitation->invited_user_type === 'customer') {
            $this->linkCustomerToUser($user);
        }

        // Create crew profile with service category (for crew invitations)
        if ($invitation->invited_user_type === 'crew') {
            $this->createCrewProfile($user, $invitation);
        }

        // Mark invitation as accepted
        $invitation->markAsAccepted();

        return $user;
    }

    /**
     * Link an existing Customer record to the newly created User and add
     * the user as a participant in any existing deal chat threads.
     */
    protected function linkCustomerToUser(User $user): void
    {
        $customer = Customer::where('email', $user->email)
            ->whereNull('user_id')
            ->first();

        if (! $customer) {
            return;
        }

        $customer->update(['user_id' => $user->id]);

        // Add user as participant in any existing deal chat threads
        foreach ($customer->deals()->with('chatThread')->get() as $deal) {
            if ($deal->chatThread) {
                $deal->chatThread->addParticipant($user);
            }
        }
    }

    /**
     * Create a crew profile for the user, setting service_category_id from the invitation.
     */
    protected function createCrewProfile(User $user, Invitation $invitation): void
    {
        CrewProfile::create([
            'user_id' => $user->id,
            'service_category_id' => $invitation->service_category_id,
        ]);
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
