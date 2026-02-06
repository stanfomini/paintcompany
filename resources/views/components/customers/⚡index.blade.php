<?php

use App\Models\Customer;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public function boot(): void
    {
        if (! auth()->user()?->can('people.view')) {
            abort(403);
        }
    }

    public string $search = '';

    public string $statusFilter = 'all';

    public int $perPage = 15;

    #[On('customer-created')]
    #[On('customer-updated')]
    public function refreshList(): void
    {
        // Re-render is triggered automatically
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function customers()
    {
        return Customer::query()
            ->search($this->search)
            ->when($this->statusFilter !== 'all', function ($query) {
                if ($this->statusFilter === 'archived') {
                    return $query->onlyTrashed();
                }

                return $query->where('status', $this->statusFilter);
            })
            ->latest()
            ->paginate($this->perPage);
    }
}; ?>

<div>
    <div class="max-w-7xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <flux:heading size="xl">Customers</flux:heading>
            @livewire('customers.create-modal')
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
                    placeholder="Search customers..."
                    icon="magnifying-glass"
                />
            </div>
            <div class="sm:w-48">
                <flux:select wire:model.live="statusFilter">
                    <option value="all">All Statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                    <option value="archived">Archived</option>
                </flux:select>
            </div>
        </div>

        @if ($this->customers->count() > 0)
            <div class="overflow-x-auto border border-zinc-200 dark:border-zinc-700 rounded-lg">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                Name
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                Email
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                Phone
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                Status
                            </th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                Actions
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-zinc-900 divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->customers as $customer)
                            <tr wire:key="customer-{{ $customer->id }}">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text class="font-medium">{{ $customer->full_name }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text>{{ $customer->email }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text>{{ $customer->phone ?? '-' }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @if ($customer->trashed())
                                        <flux:badge size="sm" color="zinc">Archived</flux:badge>
                                    @elseif ($customer->status === 'active')
                                        <flux:badge size="sm" color="green">Active</flux:badge>
                                    @else
                                        <flux:badge size="sm" color="yellow">Inactive</flux:badge>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right">
                                    <flux:dropdown>
                                        <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" />
                                        <flux:menu>
                                            <flux:menu.item icon="eye">View</flux:menu.item>
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
                {{ $this->customers->links() }}
            </div>
        @else
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
                <flux:icon name="users" class="mx-auto h-12 w-12 text-zinc-400" />
                <flux:heading size="sm" class="mt-4">No customers found</flux:heading>
                <flux:text class="mt-2 text-zinc-500">
                    @if ($search || $statusFilter !== 'all')
                        Try adjusting your search or filter criteria.
                    @else
                        Get started by adding your first customer.
                    @endif
                </flux:text>
            </div>
        @endif
    </div>
</div>
