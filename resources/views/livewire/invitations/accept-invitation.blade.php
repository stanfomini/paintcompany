<div class="min-h-screen flex items-center justify-center p-6">
    <div class="w-full max-w-md">
        <div class="text-center mb-8">
            <flux:heading size="2xl" class="mb-2">{{ config('app.name', 'Apex Negotiations') }}</flux:heading>
            <flux:text class="text-zinc-600 dark:text-zinc-400">
                Accept Your Invitation
            </flux:text>
        </div>

        <flux:card class="p-6">
            @if ($isValid)
                <div class="mb-6">
                    <flux:heading size="lg" class="text-center">Create Your Account</flux:heading>
                    <flux:text class="text-center text-zinc-600 dark:text-zinc-400 mt-2">
                        You've been invited to join as a
                        <span class="font-medium">
                            {{ $invitation->invited_user_type === 'internal' ? 'team member' : $invitation->invited_user_type }}
                        </span>.
                    </flux:text>
                </div>

                <form wire:submit="acceptInvitation" class="space-y-4">
                    <div>
                        <flux:text size="sm" class="font-medium text-zinc-500 mb-1">Email</flux:text>
                        <flux:text class="font-medium">{{ $invitation->email }}</flux:text>
                    </div>

                    <flux:field>
                        <flux:input
                            wire:model="name"
                            type="text"
                            label="Full Name"
                            placeholder="Your full name"
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
                            label="Password"
                            placeholder="Create a password"
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
                            label="Confirm Password"
                            placeholder="Confirm your password"
                            required
                        />
                        @error('passwordConfirmation')
                            <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text>
                        @enderror
                    </flux:field>

                    <flux:button type="submit" variant="primary" class="w-full justify-center">
                        <span wire:loading.remove wire:target="acceptInvitation">Create Account</span>
                        <span wire:loading wire:target="acceptInvitation">Creating Account...</span>
                    </flux:button>
                </form>
            @else
                <div class="text-center py-6">
                    <flux:icon name="exclamation-triangle" class="w-12 h-12 mx-auto text-amber-500 mb-4" />
                    <flux:heading size="lg" class="mb-2">Invalid Invitation</flux:heading>
                    <flux:text class="text-zinc-600 dark:text-zinc-400 mb-6">
                        {{ $errorMessage }}
                    </flux:text>
                    <a href="{{ route('welcome') }}">
                        <flux:button variant="primary">
                            Return to Home
                        </flux:button>
                    </a>
                </div>
            @endif
        </flux:card>

        <flux:text size="sm" class="text-center mt-6 text-zinc-500">
            &copy; {{ date('Y') }} {{ config('app.name') }}. All rights reserved.
        </flux:text>
    </div>
</div>
