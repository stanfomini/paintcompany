<x-layouts.customer title="Dashboard">
    <div class="space-y-6">
        <div>
            <flux:heading size="xl">{{ __('Welcome, :name!', ['name' => auth('customer')->user()->name]) }}</flux:heading>
            <flux:text class="mt-2 text-zinc-600 dark:text-zinc-400">
                {{ __('Track your painting projects and communicate with our team.') }}
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
                <flux:heading size="sm" class="text-zinc-500">{{ __('Unpaid Invoices') }}</flux:heading>
                <flux:text size="2xl" class="mt-2 font-semibold">$0.00</flux:text>
            </flux:card>

            <flux:card>
                <flux:heading size="sm" class="text-zinc-500">{{ __('Unread Messages') }}</flux:heading>
                <flux:text size="2xl" class="mt-2 font-semibold">0</flux:text>
            </flux:card>
        </div>

        <!-- Current Projects -->
        <flux:card>
            <flux:heading size="lg">{{ __('Your Projects') }}</flux:heading>
            <flux:text class="mt-4 text-zinc-500">
                {{ __('No projects to display yet. Once you approve an estimate, your project will appear here.') }}
            </flux:text>
        </flux:card>

        <!-- Contact Support -->
        <flux:card>
            <div class="flex items-center justify-between">
                <div>
                    <flux:heading size="lg">{{ __('Need Help?') }}</flux:heading>
                    <flux:text class="mt-1 text-zinc-500">
                        {{ __('Our team is here to assist you with any questions.') }}
                    </flux:text>
                </div>
                <flux:button variant="primary" icon="chat-bubble-left-right">
                    {{ __('Contact Us') }}
                </flux:button>
            </div>
        </flux:card>
    </div>
</x-layouts.customer>
