<?php

use App\Models\Invitation;
use App\Services\InvitationService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.guest')] class extends Component {
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
                $this->errorMessage = __('This invitation link is invalid.');
            } elseif ($invitation->isExpired()) {
                $this->errorMessage = __('This invitation has expired. Please request a new invitation.');
            } elseif ($invitation->isAccepted()) {
                $this->errorMessage = __('This invitation has already been used.');
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
            'passwordConfirmation.same' => __('The password confirmation does not match.'),
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
                'crew' => 'crew',
                default => 'web',
            };

            Auth::guard($guard)->login($user);

            // Redirect to the appropriate dashboard
            $route = match ($user->type) {
                'agent' => 'agent.dashboard',
                'customer' => 'customer.dashboard',
                'crew' => 'crew.dashboard',
                default => 'dashboard',
            };

            $this->redirect(route($route), navigate: true);
        } catch (\InvalidArgumentException $e) {
            $this->addError('name', $e->getMessage());
        }
    }
}; ?>

<div class="min-h-screen flex items-center justify-center p-6">
    <div class="w-full max-w-md">
        <div class="text-center mb-8">
            <flux:heading size="2xl" class="mb-2">{{ config('app.name', 'Apex Negotiations') }}</flux:heading>
            <flux:text class="text-zinc-600 dark:text-zinc-400">
                {{ __('Accept Your Invitation') }}
            </flux:text>
        </div>

        <flux:card class="p-6">
            @if ($isValid)
                <div class="mb-6">
                    <flux:heading size="lg" class="text-center">{{ __('Create Your Account') }}</flux:heading>
                    <flux:text class="text-center text-zinc-600 dark:text-zinc-400 mt-2">
                        {{ __('You\'ve been invited to join as a') }}
                        <span class="font-medium">
                            {{ $invitation->invited_user_type === 'internal' ? __('team member') : __($invitation->invited_user_type) }}
                        </span>.
                    </flux:text>
                </div>

                <form wire:submit="acceptInvitation" class="space-y-4">
                    <div>
                        <flux:text size="sm" class="font-medium text-zinc-500 mb-1">{{ __('Email') }}</flux:text>
                        <flux:text class="font-medium">{{ $invitation->email }}</flux:text>
                    </div>

                    <flux:field>
                        <flux:input
                            wire:model="name"
                            type="text"
                            label="{{ __('Full Name') }}"
                            placeholder="{{ __('Your full name') }}"
                            required
                            autofocus
                        />
                        @error('name')
                            <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text>
                        @enderror
                    </flux:field>

                    <flux:field>
                        <flux:input
                            wire:model="password"
                            type="password"
                            label="{{ __('Password') }}"
                            placeholder="{{ __('Create a password') }}"
                            required
                        />
                        @error('password')
                            <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text>
                        @enderror
                    </flux:field>

                    <flux:field>
                        <flux:input
                            wire:model="passwordConfirmation"
                            type="password"
                            label="{{ __('Confirm Password') }}"
                            placeholder="{{ __('Confirm your password') }}"
                            required
                        />
                        @error('passwordConfirmation')
                            <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text>
                        @enderror
                    </flux:field>

                    <flux:button type="submit" variant="primary" class="w-full justify-center">
                        <span wire:loading.remove wire:target="acceptInvitation">{{ __('Create Account') }}</span>
                        <span wire:loading wire:target="acceptInvitation">{{ __('Creating Account...') }}</span>
                    </flux:button>
                </form>
            @else
                <div class="text-center py-6">
                    <flux:icon name="exclamation-triangle" class="w-12 h-12 mx-auto text-amber-500 mb-4" />
                    <flux:heading size="lg" class="mb-2">{{ __('Invalid Invitation') }}</flux:heading>
                    <flux:text class="text-zinc-600 dark:text-zinc-400 mb-6">
                        {{ $errorMessage }}
                    </flux:text>
                    <a href="{{ route('welcome') }}">
                        <flux:button variant="primary">
                            {{ __('Return to Home') }}
                        </flux:button>
                    </a>
                </div>
            @endif
        </flux:card>

        <flux:text size="sm" class="text-center mt-6 text-zinc-500">
            &copy; {{ date('Y') }} {{ config('app.name') }}. {{ __('All rights reserved.') }}
        </flux:text>
    </div>
</div>
