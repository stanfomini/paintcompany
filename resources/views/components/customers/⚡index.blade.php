<?php

use App\Models\Customer;
use App\Services\CustomerDeletionService;
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

    public ?int $deletingCustomerId = null;

    public bool $showDeleteConfirm = false;

    public array $deletionSummary = [];

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

    public function viewCustomer(int $id): mixed
    {
        return $this->redirect(route('customers.show', $id), navigate: true);
    }

    public function confirmDelete(int $id): void
    {
        if (! auth()->user()?->can('people.delete')) {
            abort(403);
        }

        $customer = Customer::withTrashed()->findOrFail($id);
        $this->deletingCustomerId = $id;
        $this->deletionSummary = app(CustomerDeletionService::class)->getDeletionSummary($customer);
        $this->showDeleteConfirm = true;
    }

    public function deleteCustomer(): void
    {
        if (! auth()->user()?->can('people.delete')) {
            abort(403);
        }

        $customer = Customer::withTrashed()->findOrFail($this->deletingCustomerId);

        app(CustomerDeletionService::class)->forceDelete($customer);

        $this->showDeleteConfirm = false;
        $this->deletingCustomerId = null;
        $this->deletionSummary = [];

        session()->flash('success', __('Customer permanently deleted.'));
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
            <flux:heading size="xl">{{ __('Customers') }}</flux:heading>
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
                    placeholder="{{ __('Search customers...') }}"
                    icon="magnifying-glass"
                />
            </div>
            <div class="sm:w-48">
                <flux:select wire:model.live="statusFilter">
                    <option value="all">{{ __('All Statuses') }}</option>
                    <option value="active">{{ __('Active') }}</option>
                    <option value="inactive">{{ __('Inactive') }}</option>
                    <option value="archived">{{ __('Archived') }}</option>
                </flux:select>
            </div>
        </div>

        @if ($this->customers->count() > 0)
            <div class="overflow-x-auto border border-zinc-200 dark:border-zinc-700 rounded-lg">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Name') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Email') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Phone') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Status') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Actions') }}
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
                                        <flux:badge size="sm" color="zinc">{{ __('Archived') }}</flux:badge>
                                    @elseif ($customer->status === 'active')
                                        <flux:badge size="sm" color="green">{{ __('Active') }}</flux:badge>
                                    @else
                                        <flux:badge size="sm" color="yellow">{{ __('Inactive') }}</flux:badge>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right">
                                    <flux:dropdown>
                                        <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" />
                                        <flux:menu>
                                            <flux:menu.item icon="eye" wire:click="viewCustomer({{ $customer->id }})">{{ __('View') }}</flux:menu.item>
                                            <flux:menu.item icon="pencil" :href="route('customers.show', $customer)" wire:navigate>{{ __('Edit') }}</flux:menu.item>
                                            @can('people.delete')
                                                <flux:menu.item icon="trash" variant="danger" wire:click="confirmDelete({{ $customer->id }})">{{ __('Delete') }}</flux:menu.item>
                                            @endcan
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
                <flux:heading size="sm" class="mt-4">{{ __('No customers found') }}</flux:heading>
                <flux:text class="mt-2 text-zinc-500">
                    @if ($search || $statusFilter !== 'all')
                        {{ __('Try adjusting your search or filter criteria.') }}
                    @else
                        {{ __('Get started by adding your first customer.') }}
                    @endif
                </flux:text>
            </div>
        @endif
    </div>

    {{-- Delete confirmation modal --}}
    <flux:modal wire:model="showDeleteConfirm" class="max-w-md">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Permanently Delete Customer') }}</flux:heading>
            <flux:text class="text-zinc-600 dark:text-zinc-400">
                {{ __('This action cannot be undone. The following data will be permanently deleted:') }}
            </flux:text>
            <div class="bg-red-50 dark:bg-red-950 border border-red-200 dark:border-red-800 rounded-lg p-4 space-y-1">
                @if (($deletionSummary['deals'] ?? 0) > 0)
                    <flux:text class="text-red-700 dark:text-red-300">{{ $deletionSummary['deals'] }} {{ Str::plural('deal', $deletionSummary['deals']) }}</flux:text>
                @endif
                @if (($deletionSummary['estimates'] ?? 0) > 0)
                    <flux:text class="text-red-700 dark:text-red-300">{{ $deletionSummary['estimates'] }} {{ Str::plural('estimate', $deletionSummary['estimates']) }}</flux:text>
                @endif
                @if (($deletionSummary['properties'] ?? 0) > 0)
                    <flux:text class="text-red-700 dark:text-red-300">{{ $deletionSummary['properties'] }} {{ Str::plural('property', $deletionSummary['properties']) }}</flux:text>
                @endif
                @if (($deletionSummary['jobs'] ?? 0) > 0)
                    <flux:text class="text-red-700 dark:text-red-300">{{ $deletionSummary['jobs'] }} {{ Str::plural('job', $deletionSummary['jobs']) }}</flux:text>
                @endif
                @if (($deletionSummary['deals'] ?? 0) === 0 && ($deletionSummary['estimates'] ?? 0) === 0 && ($deletionSummary['properties'] ?? 0) === 0 && ($deletionSummary['jobs'] ?? 0) === 0)
                    <flux:text class="text-red-700 dark:text-red-300">{{ __('No related records found.') }}</flux:text>
                @endif
            </div>
            <div class="flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="$set('showDeleteConfirm', false)">{{ __('Cancel') }}</flux:button>
                <flux:button variant="danger" wire:click="deleteCustomer">{{ __('Permanently Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
