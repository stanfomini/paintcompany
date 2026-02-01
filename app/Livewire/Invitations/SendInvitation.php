<?php

namespace App\Livewire\Invitations;

use App\Services\InvitationService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class SendInvitation extends Component
{
    public string $email = '';

    public string $invitedUserType = '';

    public bool $showModal = false;

    public array $invitableTypes = [];

    public function mount(): void
    {
        $invitationService = app(InvitationService::class);
        $user = Auth::user();

        if ($user) {
            $this->invitableTypes = $invitationService->getInvitableTypes($user);
            $this->invitedUserType = $this->invitableTypes[0] ?? '';
        }
    }

    public function openModal(): void
    {
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->reset(['email', 'invitedUserType']);
        $this->invitedUserType = $this->invitableTypes[0] ?? '';
    }

    public function sendInvitation(): void
    {
        $this->validate([
            'email' => ['required', 'email', 'max:255'],
            'invitedUserType' => ['required', 'in:internal,agent,customer'],
        ]);

        $invitationService = app(InvitationService::class);
        $user = Auth::user();

        try {
            $invitationService->sendInvitation($user, $this->email, $this->invitedUserType);

            $this->dispatch('invitation-sent');
            session()->flash('success', "Invitation sent to {$this->email}");
            $this->closeModal();
        } catch (\InvalidArgumentException $e) {
            $this->addError('email', $e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.invitations.send-invitation');
    }
}
