<?php

use App\Models\Property;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.agent', ['title' => 'My Listings'])] class extends Component {
    use AuthorizesRequests;
    use WithPagination;

    public string $search = '';

    public string $statusFilter = 'all';

    public int $perPage = 15;

    public function mount(): void
    {
        $this->authorize('viewAny', Property::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    #[On('listing-created')]
    #[On('listing-updated')]
    public function refreshList(): void
    {
        // Component will re-render automatically
    }

    public function delete(int $id): void
    {
        $listing = Property::findOrFail($id);

        $this->authorize('delete', $listing);

        $listing->delete();

        session()->flash('success', 'Listing archived successfully.');
    }

    #[Computed]
    public function listings()
    {
        $agent = auth('agent')->user();

        return Property::query()
            ->forAgent($agent)
            ->search($this->search)
            ->when($this->statusFilter !== 'all', function ($query) {
                return $query->ofListingStatus($this->statusFilter);
            })
            ->latest()
            ->paginate($this->perPage);
    }

    #[Computed]
    public function statuses(): array
    {
        return Property::LISTING_STATUSES;
    }
}; ?>

<div>
    <div class="max-w-7xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <flux:heading size="xl">{{ __('My Listings') }}</flux:heading>
            @livewire('agent.listings.create-modal')
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
                    <option value="all">{{ __('All Statuses') }}</option>
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
                                {{ __('Address') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('City/State') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Status') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Price') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Open House') }}
                            </th>
                            <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                {{ __('Actions') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-zinc-900 divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach ($this->listings as $listing)
                            <tr wire:key="listing-{{ $listing->id }}">
                                <td class="px-6 py-4">
                                    <a href="{{ route('agent.listings.show', $listing) }}" class="text-blue-600 dark:text-blue-400 hover:underline font-medium">
                                        {{ $listing->address }}
                                    </a>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <flux:text>{{ $listing->city }}, {{ $listing->state }} {{ $listing->zip }}</flux:text>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    @switch($listing->listing_status)
                                        @case('active')
                                            <flux:badge size="sm" color="green">{{ __('Active') }}</flux:badge>
                                            @break
                                        @case('pending')
                                            <flux:badge size="sm" color="yellow">{{ __('Pending') }}</flux:badge>
                                            @break
                                        @case('sold')
                                            <flux:badge size="sm" color="blue">{{ __('Sold') }}</flux:badge>
                                            @break
                                        @case('inactive')
                                            <flux:badge size="sm" color="zinc">{{ __('Inactive') }}</flux:badge>
                                            @break
                                    @endswitch
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
                                            <flux:badge size="sm" color="blue">{{ __('Upcoming') }}</flux:badge>
                                        @endif
                                    @else
                                        <flux:text class="text-zinc-500">-</flux:text>
                                    @endif
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right">
                                    <flux:dropdown>
                                        <flux:button variant="ghost" size="sm" icon="ellipsis-horizontal" />
                                        <flux:menu>
                                            <flux:menu.item icon="eye" href="{{ route('agent.listings.show', $listing) }}">{{ __('View') }}</flux:menu.item>
                                            <flux:menu.item icon="pencil" wire:click="$dispatch('edit-listing', { listingId: {{ $listing->id }} })">{{ __('Edit') }}</flux:menu.item>
                                            <flux:menu.item icon="trash" variant="danger" wire:click="delete({{ $listing->id }})" wire:confirm="{{ __('Are you sure you want to archive this listing?') }}">{{ __('Archive') }}</flux:menu.item>
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
                <flux:heading size="sm" class="mt-4">{{ __('No listings found') }}</flux:heading>
                <flux:text class="mt-2 text-zinc-500">
                    @if ($search || $statusFilter !== 'all')
                        {{ __('Try adjusting your search or filter criteria.') }}
                    @else
                        {{ __('Get started by adding your first property listing.') }}
                    @endif
                </flux:text>
            </div>
        @endif
    </div>

    @livewire('agent.listings.edit-modal')
</div>
