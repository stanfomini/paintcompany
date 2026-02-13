<x-layouts.agent>
    <div class="max-w-4xl mx-auto p-6">
        <div class="flex items-center justify-between mb-6">
            <flux:heading size="xl">{{ __('Invite Customers') }}</flux:heading>
            <livewire:invitations.send-invitation />
        </div>

        <livewire:invitations.invitation-list />
    </div>
</x-layouts.agent>
