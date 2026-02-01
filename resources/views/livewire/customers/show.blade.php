<div>
    <div class="max-w-4xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-4">
                <flux:button variant="ghost" :href="route('customers.index')" icon="arrow-left" wire:navigate>
                    Back
                </flux:button>
                <flux:heading size="xl">{{ $customer->full_name }}</flux:heading>
            </div>
            @livewire('customers.edit-modal', ['customerId' => $customer->id])
        </div>

        @if (session('success'))
            <flux:callout variant="success" class="mb-4">
                {{ session('success') }}
            </flux:callout>
        @endif

        <div class="grid gap-6 md:grid-cols-2">
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                <flux:heading size="sm" class="mb-4">Contact Information</flux:heading>
                <dl class="space-y-3">
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Email</dt>
                        <dd class="mt-1">
                            <flux:text>{{ $customer->email ?? '-' }}</flux:text>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Phone</dt>
                        <dd class="mt-1">
                            <flux:text>{{ $customer->phone ?? '-' }}</flux:text>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Address</dt>
                        <dd class="mt-1">
                            <flux:text>{{ $customer->full_address ?? '-' }}</flux:text>
                        </dd>
                    </div>
                </dl>
            </div>

            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                <flux:heading size="sm" class="mb-4">Status & Details</flux:heading>
                <dl class="space-y-3">
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Status</dt>
                        <dd class="mt-1">
                            @if ($customer->status === 'active')
                                <flux:badge size="sm" color="green">Active</flux:badge>
                            @else
                                <flux:badge size="sm" color="yellow">Inactive</flux:badge>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Created By</dt>
                        <dd class="mt-1">
                            <flux:text>{{ $customer->createdBy?->name ?? '-' }}</flux:text>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Referred By</dt>
                        <dd class="mt-1">
                            <flux:text>{{ $customer->referredBy?->name ?? '-' }}</flux:text>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Portal Access</dt>
                        <dd class="mt-1">
                            @if ($customer->hasPortalAccess())
                                <flux:badge size="sm" color="green">Enabled</flux:badge>
                            @else
                                <flux:badge size="sm" color="zinc">Not Enabled</flux:badge>
                            @endif
                        </dd>
                    </div>
                </dl>
            </div>
        </div>

        @if ($customer->notes)
            <div class="mt-6 border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                <flux:heading size="sm" class="mb-4">Notes</flux:heading>
                <flux:text>{{ $customer->notes }}</flux:text>
            </div>
        @endif
    </div>
</div>
