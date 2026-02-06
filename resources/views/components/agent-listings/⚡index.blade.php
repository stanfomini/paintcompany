<?php

use App\Models\AgentListing;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public function boot(): void
    {
        if (! auth()->user()?->can('agent_listings.view')) {
            abort(403);
        }
    }

    public string $search = '';

    public string $statusFilter = 'all';

    public ?int $agentFilter = null;

    public int $perPage = 15;

    #[On('listing-created')]
    #[On('listing-updated')]
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

    public function updatedAgentFilter(): void
    {
        $this->resetPage();
    }

    public function delete(int $id): void
    {
        $listing = AgentListing::findOrFail($id);
        $listing->delete();

        session()->flash('success', 'Listing archived successfully.');
    }

    #[Computed]
    public function listings()
    {
        return AgentListing::query()
            ->with('agent')
            ->search($this->search)
            ->when($this->statusFilter !== 'all', function ($query) {
                return $query->ofStatus($this->statusFilter);
            })
            ->when($this->agentFilter, function ($query) {
                return $query->where('agent_id', $this->agentFilter);
            })
            ->latest()
            ->paginate($this->perPage);
    }

    #[Computed]
    public function statuses()
    {
        return AgentListing::STATUSES;
    }
}; ?>

<div>
    <div class="max-w-7xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <flux:heading size="xl">Agent Listings</flux:heading>
            @livewire('agent-listings.create-modal')
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
                <flux:select wire:model.live="statusFilter">
                    <option value="all">All Statuses</option>
                    @foreach ($this->statuses as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </flux:select>
            </div>
        </div>

        @if ($this->listings->count() > 0)
            <div class="overflow-x-auto border border-zinc-200 dark:border-zinc-700 rounded-lg">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                Address
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                Agent
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                Price
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                Open House
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
                        @foreach ($this->listings as $listing)
                            <tr wire:key="listing-{{ $listing->id }}">
                                <td class="px-6 py-4">
                                    <flux:text class="font-medium">{{ $listing->address }}</flux:text>
                                    <flux:text class="text-sm text-zinc-500">{{ $listing->city }}, {{ $listing->state }} {{ $listing->zip }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text>{{ $listing->agent->name }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text>
                                        @if ($listing->listing_price)
                                            ${{ number_format($listing->listing_price, 0) }}
                                        @else
                                            -
                                        @endif
                                    </flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @if ($listing->open_house_date)
                                        <flux:text>{{ $listing->open_house_date->format('M j, Y g:i A') }}</flux:text>
                                        @if ($listing->hasUpcomingOpenHouse())
                                            <flux:badge size="sm" color="blue">Upcoming</flux:badge>
                                        @endif
                                    @else
                                        <flux:text class="text-zinc-500">-</flux:text>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @switch($listing->status)
                                        @case('active')
                                            <flux:badge size="sm" color="green">Active</flux:badge>
                                            @break
                                        @case('pending')
                                            <flux:badge size="sm" color="yellow">Pending</flux:badge>
                                            @break
                                        @case('sold')
                                            <flux:badge size="sm" color="blue">Sold</flux:badge>
                                            @break
                                        @case('inactive')
                                            <flux:badge size="sm" color="zinc">Inactive</flux:badge>
                                            @break
                                    @endswitch
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right">
                                    <flux:dropdown>
                                        <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" />
                                        <flux:menu>
                                            <flux:menu.item icon="pencil" wire:click="$dispatch('edit-listing', { listingId: {{ $listing->id }} })">Edit</flux:menu.item>
                                            <flux:menu.item icon="trash" variant="danger" wire:click="delete({{ $listing->id }})" wire:confirm="Are you sure you want to archive this listing?">Archive</flux:menu.item>
                                        </flux:menu>
                                    </flux:dropdown>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="mt-4">
                {{ $this->listings->links() }}
            </div>
        @else
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
                <flux:icon name="home" class="mx-auto h-12 w-12 text-zinc-400" />
                <flux:heading size="sm" class="mt-4">No agent listings found</flux:heading>
                <flux:text class="mt-2 text-zinc-500">
                    @if ($search || $statusFilter !== 'all')
                        Try adjusting your search or filter criteria.
                    @else
                        Get started by adding an agent's property listing.
                    @endif
                </flux:text>
            </div>
        @endif
    </div>

    @livewire('agent-listings.edit-modal')
</div>
