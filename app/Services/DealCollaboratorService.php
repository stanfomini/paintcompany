<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\DealCollaborator;
use App\Models\Invitation;
use App\Models\User;
use App\Notifications\CollaboratorAccessGranted;
use App\Notifications\InvitationNotification;
use InvalidArgumentException;

class DealCollaboratorService
{
    /**
     * Invite a collaborator to a deal by email.
     *
     * Returns a DealCollaborator if the user already exists as a customer,
     * or an Invitation if they need to register first.
     */
    public function inviteCollaborator(Deal $deal, User $inviter, string $email): DealCollaborator|Invitation
    {
        $customer = Customer::where('user_id', $inviter->id)->firstOrFail();

        if ($deal->customer_id !== $customer->id) {
            throw new InvalidArgumentException('You do not own this deal.');
        }

        if ($email === $inviter->email) {
            throw new InvalidArgumentException('You cannot invite yourself.');
        }

        $existingCollaborator = DealCollaborator::where('deal_id', $deal->id)
            ->whereHas('customer', fn ($q) => $q->where('email', $email))
            ->active()
            ->exists();

        if ($existingCollaborator) {
            throw new InvalidArgumentException('This person already has active access to this deal.');
        }

        $existingUser = User::where('email', $email)->first();

        if ($existingUser && $existingUser->type !== 'customer') {
            throw new InvalidArgumentException("This email belongs to a {$existingUser->type} account and cannot be added as a collaborator.");
        }

        if ($existingUser) {
            return $this->addExistingCustomer($deal, $inviter, $existingUser, $email);
        }

        return $this->inviteNewUser($deal, $inviter, $email);
    }

    /**
     * Revoke a collaborator's access to a deal.
     */
    public function revokeAccess(DealCollaborator $collaborator): void
    {
        $collaborator->update(['revoked_at' => now()]);

        $user = $collaborator->customer->user ?? null;

        if ($user && $collaborator->deal->chatThread) {
            $collaborator->deal->chatThread->removeParticipant($user);
        }
    }

    /**
     * Extend a collaborator's access by a given number of days.
     */
    public function extendAccess(DealCollaborator $collaborator, int $days = 30): void
    {
        $collaborator->update(['expires_at' => now()->addDays($days)]);
    }

    /**
     * Add an existing customer user as a collaborator.
     */
    private function addExistingCustomer(Deal $deal, User $inviter, User $existingUser, string $email): DealCollaborator
    {
        $customerRecord = Customer::where('user_id', $existingUser->id)->first()
            ?? Customer::where('email', $email)->first();

        if (! $customerRecord) {
            $customerRecord = Customer::create([
                'user_id' => $existingUser->id,
                'first_name' => $existingUser->name,
                'last_name' => '',
                'email' => $email,
            ]);
        }

        $collaborator = DealCollaborator::create([
            'deal_id' => $deal->id,
            'customer_id' => $customerRecord->id,
            'invited_by' => $inviter->id,
            'expires_at' => now()->addDays(30),
        ]);

        $thread = $deal->getOrCreateChatThread();
        $thread->addParticipant($existingUser);

        $existingUser->notify(new CollaboratorAccessGranted($deal, $inviter));

        return $collaborator;
    }

    /**
     * Invite a new user who doesn't have an account yet.
     */
    private function inviteNewUser(Deal $deal, User $inviter, string $email): Invitation
    {
        $invitation = Invitation::create([
            'email' => $email,
            'invited_user_type' => 'customer',
            'inviter_id' => $inviter->id,
            'deal_id' => $deal->id,
        ]);

        $invitation->notify(new InvitationNotification($invitation));

        return $invitation;
    }
}
