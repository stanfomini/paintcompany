<?php

use App\Models\Property;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public function boot(): void
    {
        if (! auth()->user()?->can('properties.view')) {
            abort(403);
        }
    }

    public Property $property;

    public function mount(Property $property): void
    {
        $this->property = $property->load(['customer', 'photos', 'deals']);
    }

    #[On('deal-created')]
    public function refreshDeals(): void
    {
        $this->property = $this->property->fresh(['customer', 'photos', 'deals']);
    }
}; ?>

<div>
    <div class="max-w-4xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-4">
                <flux:button variant="ghost" :href="route('properties.index')" icon="arrow-left" wire:navigate>
                    Back
                </flux:button>
                <flux:heading size="xl">{{ $property->display_name }}</flux:heading>
            </div>
            @can('properties.edit')
                @livewire('properties.edit-modal', ['propertyId' => $property->id])
            @endcan
        </div>

        @if (session('success'))
            <flux:callout variant="success" class="mb-4">
                {{ session('success') }}
            </flux:callout>
        @endif

        <div class="grid gap-6 md:grid-cols-2">
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                <flux:heading size="sm" class="mb-4">Property Information</flux:heading>
                <dl class="space-y-3">
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Address</dt>
                        <dd class="mt-1">
                            <flux:text>{{ $property->full_address ?? '-' }}</flux:text>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Type</dt>
                        <dd class="mt-1">
                            @if ($property->type === 'residential')
                                <flux:badge size="sm" color="blue">Residential</flux:badge>
                            @elseif ($property->type === 'commercial')
                                <flux:badge size="sm" color="purple">Commercial</flux:badge>
                            @else
                                <flux:badge size="sm" color="zinc">Industrial</flux:badge>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Square Footage</dt>
                        <dd class="mt-1">
                            <flux:text>{{ $property->square_footage ? number_format($property->square_footage) . ' sq ft' : '-' }}</flux:text>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Year Built</dt>
                        <dd class="mt-1">
                            <flux:text>{{ $property->year_built ?? '-' }}</flux:text>
                        </dd>
                    </div>
                </dl>
            </div>

            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                <flux:heading size="sm" class="mb-4">Customer Information</flux:heading>
                <dl class="space-y-3">
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Customer</dt>
                        <dd class="mt-1">
                            @if ($property->customer)
                                <flux:button variant="ghost" size="sm" :href="route('customers.show', $property->customer)" wire:navigate>
                                    {{ $property->customer->full_name }}
                                </flux:button>
                            @else
                                <flux:text>-</flux:text>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Customer Email</dt>
                        <dd class="mt-1">
                            <flux:text>{{ $property->customer?->email ?? '-' }}</flux:text>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Customer Phone</dt>
                        <dd class="mt-1">
                            <flux:text>{{ $property->customer?->phone ?? '-' }}</flux:text>
                        </dd>
                    </div>
                </dl>
            </div>
        </div>

        @if ($property->notes)
            <div class="mt-6 border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                <flux:heading size="sm" class="mb-4">Notes</flux:heading>
                <flux:text>{{ $property->notes }}</flux:text>
            </div>
        @endif

        <div class="mt-6 border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
            <flux:heading size="sm" class="mb-4">Photo Gallery</flux:heading>
            @livewire('properties.photo-gallery', ['property' => $property])
        </div>

        {{-- Deals Section --}}
        <div class="mt-6 border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
            <div class="flex items-center justify-between mb-4">
                <flux:heading size="sm">Deals</flux:heading>
                @if ($property->customer)
                    @livewire('deals.create-modal', ['customerId' => $property->customer_id, 'propertyId' => $property->id])
                @endif
            </div>

            @if ($property->deals->count() > 0)
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                        <thead>
                            <tr>
                                <th scope="col" class="py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    Title
                                </th>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    Stage
                                </th>
                                <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    Est. Value
                                </th>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    Created
                                </th>
                                <th scope="col" class="py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($property->deals->sortByDesc('created_at') as $deal)
                                <tr wire:key="deal-{{ $deal->id }}">
                                    <td class="py-4 whitespace-nowrap">
                                        <a href="{{ route('deals.show', $deal) }}" class="text-blue-600 hover:underline font-medium" wire:navigate>
                                            {{ $deal->title }}
                                        </a>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap">
                                        @php
                                            $stageColor = match ($deal->stage) {
                                                'lead' => 'zinc',
                                                'quoted' => 'blue',
                                                'scheduled' => 'purple',
                                                'in_progress' => 'yellow',
                                                'completed' => 'green',
                                                'paid' => 'emerald',
                                                'cancelled' => 'red',
                                                default => 'zinc',
                                            };
                                        @endphp
                                        <flux:badge size="sm" color="{{ $stageColor }}">{{ $deal->stage_label }}</flux:badge>
                                    </td>
                                    <td class="px-4 py-4 text-right whitespace-nowrap">
                                        <flux:text class="font-medium">{{ $deal->estimated_value ? '$' . number_format($deal->estimated_value, 2) : '-' }}</flux:text>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap">
                                        <flux:text class="text-sm text-zinc-500">{{ $deal->created_at->format('M j, Y') }}</flux:text>
                                    </td>
                                    <td class="py-4 text-right whitespace-nowrap">
                                        <flux:button :href="route('deals.show', $deal)" size="sm" variant="ghost" icon="eye" wire:navigate />
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="text-center py-8">
                    <flux:icon name="briefcase" class="w-12 h-12 mx-auto text-zinc-300 dark:text-zinc-600 mb-3" />
                    <flux:text class="text-zinc-500 dark:text-zinc-400">No deals yet</flux:text>
                    <flux:text class="text-sm text-zinc-400 dark:text-zinc-500 mt-1">Create a deal to start tracking work for this property.</flux:text>
                </div>
            @endif
        </div>
    </div>
</div>
