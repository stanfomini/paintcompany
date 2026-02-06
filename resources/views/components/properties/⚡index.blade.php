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

        if (! $user || ! $user->isInternal()) {
            abort(403, 'Only internal users can access properties.');
        }

        return Property::query()
            ->with(['customer', 'photos'])
            ->search($this->search)
            ->when($this->typeFilter !== 'all', function ($query) {
                return $query->ofType($this->typeFilter);
            })
            ->when($this->customerFilter !== '', function ($query) {
                return $query->where('customer_id', $this->customerFilter);
            })
            ->latest()
            ->paginate($this->perPage);
    }
}; ?>

<div>
    <div class="max-w-7xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <flux:heading size="xl">Properties</flux:heading>
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
                    placeholder="Search by address, city, state, zip..."
                    icon="magnifying-glass"
                />
            </div>
            <div class="sm:w-48">
                <flux:select wire:model.live="typeFilter">
                    <option value="all">All Types</option>
                    <option value="residential">Residential</option>
                    <option value="commercial">Commercial</option>
                    <option value="industrial">Industrial</option>
                </flux:select>
            </div>
            <div class="sm:w-48">
                <flux:select wire:model.live="customerFilter">
                    <option value="">All Customers</option>
                    @foreach ($this->customers as $customer)
                        <option value="{{ $customer->id }}">{{ $customer->full_name }}</option>
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
                                Address
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                City/State/Zip
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                Customer
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                Type
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                Photos
                            </th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                Actions
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
                                        <flux:badge size="sm" color="blue">Residential</flux:badge>
                                    @elseif ($property->type === 'commercial')
                                        <flux:badge size="sm" color="purple">Commercial</flux:badge>
                                    @elseif ($property->type === 'industrial')
                                        <flux:badge size="sm" color="orange">Industrial</flux:badge>
                                    @else
                                        <flux:badge size="sm" color="zinc">{{ ucfirst($property->type) }}</flux:badge>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text>{{ $property->photos->count() }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right">
                                    <flux:dropdown>
                                        <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" />
                                        <flux:menu>
                                            <flux:menu.item icon="eye" :href="route('properties.show', $property)" wire:navigate>View</flux:menu.item>
                                            <flux:menu.item icon="pencil">Edit</flux:menu.item>
                                            <flux:menu.item icon="trash" variant="danger">Delete</flux:menu.item>
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
                <flux:heading size="sm" class="mt-4">No properties found</flux:heading>
                <flux:text class="mt-2 text-zinc-500">
                    @if ($search || $typeFilter !== 'all' || $customerFilter !== '')
                        Try adjusting your search or filter criteria.
                    @else
                        Get started by adding your first property.
                    @endif
                </flux:text>
            </div>
        @endif
    </div>
</div>
