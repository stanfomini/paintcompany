<div>
    @if (count($invitableTypes) > 0)
        <flux:button wire:click="openModal" variant="ghost" size="sm" class="w-full justify-start">
            <flux:icon name="envelope" class="mr-2" />
            Send Invitation
        </flux:button>

        <flux:modal wire:model="showModal" class="max-w-md">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">Send Invitation</flux:heading>
                    <flux:text class="mt-1 text-zinc-600 dark:text-zinc-400">
                        Invite someone to join the platform.
                    </flux:text>
                </div>

                <form wire:submit="sendInvitation" class="space-y-4">
                    <flux:field>
                        <flux:input
                            wire:model="email"
                            type="email"
                            label="Email Address"
                            placeholder="email@example.com"
                            required
                        />
                        @error('email')
                            <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text>
                        @enderror
                    </flux:field>

                    <flux:field>
                        <flux:select wire:model="invitedUserType" label="User Type">
                            @foreach ($invitableTypes as $type)
                                <option value="{{ $type }}">
                                    {{ ucfirst($type === 'internal' ? 'Team Member' : $type) }}
                                </option>
                            @endforeach
                        </flux:select>
                        @error('invitedUserType')
                            <flux:text class="text-red-500 text-sm mt-1">{{ $message }}</flux:text>
                        @enderror
                    </flux:field>

                    <div class="flex justify-end gap-3 pt-4">
                        <flux:button type="button" wire:click="closeModal" variant="ghost">
                            Cancel
                        </flux:button>
                        <flux:button type="submit" variant="primary">
                            <span wire:loading.remove wire:target="sendInvitation">Send Invitation</span>
                            <span wire:loading wire:target="sendInvitation">Sending...</span>
                        </flux:button>
                    </div>
                </form>
            </div>
        </flux:modal>
    @endif
</div>
