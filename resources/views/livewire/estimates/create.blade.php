<div>
    {{-- Breadcrumbs --}}
    <div class="mb-6">
        <flux:breadcrumbs>
            <flux:breadcrumbs.item href="{{ route('deals.index') }}">Deals</flux:breadcrumbs.item>
            <flux:breadcrumbs.item href="{{ route('deals.show', $deal) }}">{{ $deal->title }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>Create Estimate</flux:breadcrumbs.item>
        </flux:breadcrumbs>
    </div>

    {{-- Header --}}
    <div class="mb-8">
        <flux:heading size="xl">Create New Estimate</flux:heading>
        <flux:text class="mt-2 text-zinc-600 dark:text-zinc-400">
            Choose an estimating method for {{ $deal->customer?->full_name ?? 'this deal' }}
        </flux:text>
    </div>

    {{-- Deal Info Card --}}
    <div class="mb-8 rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
        <div class="flex items-start gap-4">
            <div class="flex h-12 w-12 items-center justify-center rounded-lg bg-blue-100 dark:bg-blue-900/30">
                <flux:icon name="building-office" class="h-6 w-6 text-blue-600 dark:text-blue-400" />
            </div>
            <div class="flex-1">
                <h3 class="font-semibold text-zinc-900 dark:text-white">{{ $deal->title }}</h3>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                    @if ($deal->customer)
                        {{ $deal->customer->full_name }}
                        @if ($deal->customer->email)
                            &bull; {{ $deal->customer->email }}
                        @endif
                    @else
                        No customer assigned
                    @endif
                </p>
                @if ($deal->property)
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-500">
                        <flux:icon name="map-pin" class="inline h-4 w-4" />
                        {{ $deal->property->full_address }}
                    </p>
                @endif
            </div>
            <div>
                <flux:badge color="blue">{{ ucfirst($deal->stage) }}</flux:badge>
            </div>
        </div>
    </div>

    {{-- Mode Selector --}}
    <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
        <h3 class="mb-4 font-semibold text-zinc-900 dark:text-white">Select Estimating Method</h3>

        <livewire:estimates.mode-selector :show-descriptions="true" />

        <p class="mt-4 text-sm text-zinc-500 dark:text-zinc-400">
            Click a method above to continue. You can switch methods later if needed.
        </p>
    </div>

    {{-- Back Button --}}
    <div class="mt-6">
        <flux:button
            variant="ghost"
            href="{{ route('deals.show', $deal) }}"
            wire:navigate
        >
            <flux:icon name="arrow-left" class="mr-2 h-4 w-4" />
            Back to Deal
        </flux:button>
    </div>
</div>
