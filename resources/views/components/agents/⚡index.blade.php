<?php

use App\Models\User;
use Livewire\Attributes\Computed;
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

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function activate(User $agent): void
    {
        $agent->update(['is_active' => true]);
        session()->flash('success', 'Agent activated successfully.');
    }

    public function deactivate(User $agent): void
    {
        $agent->update(['is_active' => false]);
        session()->flash('success', 'Agent deactivated successfully.');
    }

    #[Computed]
    public function agents()
    {
        return User::query()
            ->where('type', 'agent')
            ->with(['agentProfile', 'agentListings', 'referredCustomers'])
            ->when($this->search, function ($query) {
                $query->where(function ($q) {
                    $q->where('name', 'like', "%{$this->search}%")
                        ->orWhere('email', 'like', "%{$this->search}%")
                        ->orWhereHas('agentProfile', function ($profileQuery) {
                            $profileQuery->where('company_name', 'like', "%{$this->search}%")
                                ->orWhere('license_number', 'like', "%{$this->search}%");
                        });
                });
            })
            ->when($this->statusFilter !== 'all', function ($query) {
                return $query->where('is_active', $this->statusFilter === 'active');
            })
            ->latest()
            ->paginate($this->perPage);
    }
}; ?>

<div>
    <div class="max-w-7xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <flux:heading size="xl">Agents</flux:heading>
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
                    placeholder="Search by name, company, or license..."
                    icon="magnifying-glass"
                />
            </div>
            <div class="sm:w-48">
                <flux:select wire:model.live="statusFilter">
                    <option value="all">All Statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </flux:select>
            </div>
        </div>

        @if ($this->agents->count() > 0)
            <div class="overflow-x-auto border border-zinc-200 dark:border-zinc-700 rounded-lg">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                Name
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                Company
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                License #
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                Listings
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                Customers Referred
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
                        @foreach ($this->agents as $agent)
                            <tr wire:key="agent-{{ $agent->id }}">
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text class="font-medium">{{ $agent->name }}</flux:text>
                                    <flux:text class="text-sm text-zinc-500">{{ $agent->email }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text>{{ $agent->agentProfile?->company_name ?? '-' }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text>{{ $agent->agentProfile?->license_number ?? '-' }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text>{{ $agent->agentListings->count() }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text>{{ $agent->referredCustomers->count() }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @if ($agent->is_active)
                                        <flux:badge size="sm" color="green">Active</flux:badge>
                                    @else
                                        <flux:badge size="sm" color="yellow">Inactive</flux:badge>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right">
                                    <flux:dropdown>
                                        <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" />
                                        <flux:menu>
                                            <flux:menu.item icon="eye" :href="route('agents.show', $agent)" wire:navigate>
                                                View
                                            </flux:menu.item>
                                            @if ($agent->is_active)
                                                <flux:menu.item icon="x-circle" wire:click="deactivate({{ $agent->id }})" variant="danger">
                                                    Deactivate
                                                </flux:menu.item>
                                            @else
                                                <flux:menu.item icon="check-circle" wire:click="activate({{ $agent->id }})">
                                                    Activate
                                                </flux:menu.item>
                                            @endif
                                        </flux:menu>
                                    </flux:dropdown>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $this->agents->links() }}
            </div>
        @else
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
                <flux:icon name="user-group" class="mx-auto h-12 w-12 text-zinc-400" />
                <flux:heading size="sm" class="mt-4">No agents found</flux:heading>
                <flux:text class="mt-2 text-zinc-500">
                    @if ($search || $statusFilter !== 'all')
                        Try adjusting your search or filter criteria.
                    @else
                        There are no agents in the system yet.
                    @endif
                </flux:text>
            </div>
        @endif
    </div>
</div>
