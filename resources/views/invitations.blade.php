<x-layouts::app :title="__('Invitations')">
    <div class="max-w-4xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <flux:heading size="xl">Invitations</flux:heading>
            <livewire:invitations.send-invitation />
        </div>

        <livewire:invitations.invitation-list />
    </div>
</x-layouts::app>
