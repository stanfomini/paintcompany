<x-layouts.agent title="Dashboard">
    <div class="space-y-6">
        <div>
            <flux:heading size="xl">{{ __('Welcome back, :name!', ['name' => auth('agent')->user()->name]) }}</flux:heading>
            <flux:text class="mt-2 text-zinc-600 dark:text-zinc-400">
                {{ __('Here\'s an overview of your referred customers and their projects.') }}
            </flux:text>
        </div>

        <!-- Stats Grid -->
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <flux:card>
                <flux:heading size="sm" class="text-zinc-500">{{ __('Active Projects') }}</flux:heading>
                <flux:text size="2xl" class="mt-2 font-semibold">0</flux:text>
            </flux:card>

            <flux:card>
                <flux:heading size="sm" class="text-zinc-500">{{ __('Pending Estimates') }}</flux:heading>
                <flux:text size="2xl" class="mt-2 font-semibold">0</flux:text>
            </flux:card>

            <flux:card>
                <flux:heading size="sm" class="text-zinc-500">{{ __('Completed Projects') }}</flux:heading>
                <flux:text size="2xl" class="mt-2 font-semibold">0</flux:text>
            </flux:card>

            <flux:card>
                <flux:heading size="sm" class="text-zinc-500">{{ __('Total Referrals') }}</flux:heading>
                <flux:text size="2xl" class="mt-2 font-semibold">0</flux:text>
            </flux:card>
        </div>

        <!-- Recent Activity -->
        <flux:card>
            <flux:heading size="lg">{{ __('Recent Activity') }}</flux:heading>
            <flux:text class="mt-4 text-zinc-500">
                {{ __('No recent activity to display.') }}
            </flux:text>
        </flux:card>
    </div>
</x-layouts.agent>
