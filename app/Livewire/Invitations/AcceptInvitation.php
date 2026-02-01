<?php

namespace App\Livewire\Invitations;

use App\Models\Invitation;
use App\Services\InvitationService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class AcceptInvitation extends Component
{
    public ?Invitation $invitation = null;

    public string $token = '';

    public string $name = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    public bool $isValid = false;

    public string $errorMessage = '';

    public function mount(string $token): void
    {
        $this->token = $token;

        $invitationService = app(InvitationService::class);
        $this->invitation = $invitationService->validateInvitation($token);

        if ($this->invitation) {
            $this->isValid = true;
        } else {
            $this->isValid = false;
            $invitation = $invitationService->findByToken($token);

            if (! $invitation) {
                $this->errorMessage = 'This invitation link is invalid.';
            } elseif ($invitation->isExpired()) {
                $this->errorMessage = 'This invitation has expired. Please request a new invitation.';
            } elseif ($invitation->isAccepted()) {
                $this->errorMessage = 'This invitation has already been used.';
            }
        }
    }

    public function acceptInvitation(): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8'],
            'passwordConfirmation' => ['required', 'same:password'],
        ], [
            'passwordConfirmation.same' => 'The password confirmation does not match.',
        ]);

        $invitationService = app(InvitationService::class);

        try {
            $user = $invitationService->acceptInvitation($this->invitation, [
                'name' => $this->name,
                'password' => $this->password,
            ]);

            // Log the user in based on their type
            $guard = match ($user->type) {
                'agent' => 'agent',
                'customer' => 'customer',
                default => 'web',
            };

            Auth::guard($guard)->login($user);

            // Redirect to the appropriate dashboard
            $route = match ($user->type) {
                'agent' => 'agent.dashboard',
                'customer' => 'customer.dashboard',
                default => 'dashboard',
            };

            $this->redirect(route($route), navigate: true);
        } catch (\InvalidArgumentException $e) {
            $this->addError('name', $e->getMessage());
        }
    }

    public function render()
    {
        return view('livewire.invitations.accept-invitation')
            ->layout('components.layouts.guest');
    }
}
