<?php

namespace App\Livewire\Invitations;

use App\Models\Invitation;
use App\Services\InvitationService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class InvitationList extends Component
{
    public function cancelInvitation(int $invitationId): void
    {
        $invitation = Invitation::find($invitationId);
        $invitationService = app(InvitationService::class);

        if ($invitation && $invitationService->cancelInvitation($invitation, Auth::user())) {
            session()->flash('success', 'Invitation cancelled.');
            $this->dispatch('invitation-cancelled');
        }
    }

    public function resendInvitation(int $invitationId): void
    {
        $invitation = Invitation::find($invitationId);

        if ($invitation && $invitation->isPending()) {
            $invitation->notify(new \App\Notifications\InvitationNotification($invitation));
            session()->flash('success', "Invitation resent to {$invitation->email}");
        }
    }

    public function render()
    {
        $invitationService = app(InvitationService::class);

        return view('livewire.invitations.invitation-list', [
            'invitations' => $invitationService->getPendingInvitations(Auth::user()),
        ]);
    }
}
