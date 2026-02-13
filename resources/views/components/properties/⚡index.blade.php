<?php

use App\Models\Customer;
use App\Models\Property;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public function boot(): void
    {
        if (! auth()->user()?->can('properties.view')) {
            abort(403);
        }
    }

    public string $search = '';

    public string $typeFilter = 'all';

    public string $customerFilter = '';

    public string $sourceFilter = 'all';

    public int $perPage = 15;

    #[On('property-created')]
    #[On('property-updated')]
    public function refreshList(): void
    {
        // Re-render is triggered automatically
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedCustomerFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSourceFilter(): void
    {
        $this->resetPage();
    }

    public function getCustomersProperty(): Collection
    {
        return Customer::query()
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name']);
    }

    #[Computed]
    public function properties()
    {
        $user = Auth::user();

        if (! $user || ! $user->can('properties.view')) {
            abort(403, 'Only internal users can access properties.');
        }

        return Property::query()
            ->with(['customer', 'photos', 'agent'])
            ->search($this->search)
            ->when($this->typeFilter !== 'all', function ($query) {
                return $query->ofType($this->typeFilter);
            })
            ->when($this->customerFilter !== '', function ($query) {
                return $query->where('customer_id', $this->customerFilter);
            })
            ->when($this->sourceFilter !== 'all', function ($query) {
                return $query->ofSource($this->sourceFilter);
            })
            ->latest()
            ->paginate($this->perPage);
    }
}; ?>

<div>
    <div class="max-w-7xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <flux:heading size="xl">{{ __('Properties') }}</flux:heading>
            @livewire('properties.create-modal')
        </div>

        @if (session('success'))
            <flux:callout variant="success" class="mb-4">
                {{ session('success') }}
            </flux:callout>
        @endif

        <div class="flex flex-col sm:flex-row gap-4 mb-6">
            <div class="flex-1">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    type="search"
                    :placeholder="__('Search by address, city, state, zip...')"
                    icon="magnifying-glass"
                />
            </div>
            <div class="sm:w-48">
                <flux:select wire:model.live="typeFilter">
                    <option value="all">{{ __('All Types') }}</option>
                    <option value="residential">{{ __('Residential') }}</option>
                    <option value="commercial">{{ __('Commercial') }}</option>
                    <option value="industrial">{{ __('Industrial') }}</option>
                </flux:select>
            </div>
            <div class="sm:w-48">
                <flux:select wire:model.live="customerFilter">
                    <option value="">{{ __('All Customers') }}</option>
                    @foreach ($this->customers as $customer)
                        <option value="{{ $customer->id }}">{{ $customer->full_name }}</option>
                    @endforeach
                </flux:select>
            </div>
            <div class="sm:w-48">
                <flux:select wire:model.live="sourceFilter">
                    <option value="all">{{ __('All Sources') }}</option>
                    @foreach (\App\Models\Property::SOURCES as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </flux:select>
            </div>
        </div>

        @if ($this->properties->count() > 0)
            <div class="overflow-x-auto border border-zinc-200 dark:border-zinc-700 rounded-lg">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Address') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('City/State/Zip') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Customer') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Type') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Source') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Agent') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Photos') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Actions') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-zinc-900 divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->properties as $property)
                            <tr wire:key="property-{{ $property->id }}">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text class="font-medium">{{ $property->address }}</flux:text>
                                    @if ($property->name)
                                        <flux:text class="text-sm text-zinc-500">{{ $property->name }}</flux:text>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text>{{ $property->city }}, {{ $property->state }} {{ $property->zip }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text>{{ $property->customer?->full_name ?? '-' }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @if ($property->type === 'residential')
                                        <flux:badge size="sm" color="blue">{{ __('Residential') }}</flux:badge>
                                    @elseif ($property->type === 'commercial')
                                        <flux:badge size="sm" color="purple">{{ __('Commercial') }}</flux:badge>
                                    @elseif ($property->type === 'industrial')
                                        <flux:badge size="sm" color="orange">{{ __('Industrial') }}</flux:badge>
                                    @else
                                        <flux:badge size="sm" color="zinc">{{ ucfirst($property->type) }}</flux:badge>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @if ($property->source)
                                        <flux:badge size="sm" color="zinc">{{ \App\Models\Property::SOURCES[$property->source] ?? ucfirst($property->source) }}</flux:badge>
                                    @else
                                        <flux:text class="text-zinc-400">-</flux:text>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text>{{ $property->agent?->name ?? '-' }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text>{{ $property->photos->count() }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right">
                                    <flux:dropdown>
                                        <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" />
                                        <flux:menu>
                                            <flux:menu.item icon="eye" :href="route('properties.show', $property)" wire:navigate>{{ __('View') }}</flux:menu.item>
                                            <flux:menu.item icon="pencil">{{ __('Edit') }}</flux:menu.item>
                                            <flux:menu.item icon="trash" variant="danger">{{ __('Delete') }}</flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $this->properties->links() }}
            </div>
        @else
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
                <flux:icon name="building-office" class="mx-auto h-12 w-12 text-zinc-400" />
                <flux:heading size="sm" class="mt-4">{{ __('No properties found') }}</flux:heading>
                <flux:text class="mt-2 text-zinc-500">
                    @if ($search || $typeFilter !== 'all' || $customerFilter !== '')
                        {{ __('Try adjusting your search or filter criteria.') }}
                    @else
                        {{ __('Get started by adding your first property.') }}
                    @endif
                </flux:text>
            </div>
        @endif
    </div>
</div>
