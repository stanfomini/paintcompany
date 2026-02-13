<?php

use App\Models\Property;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

new #[Layout('components.layouts.agent', ['title' => 'Listing Details'])] class extends Component {
    use AuthorizesRequests;

    public Property $property;

    public bool $showDeleteModal = false;

    public function mount(Property $property): void
    {
        $this->authorize('view', $property);

        $this->property = $property;
    }

    #[Computed]
    public function listing(): Property
    {
        return $this->property;
    }

    #[Computed]
    public function referredCustomersCount(): int
    {
        return $this->property->referredCustomers()->count();
    }

    #[On('listing-updated')]
    public function refreshListing(): void
    {
        $this->property->refresh();
    }

    public function confirmDelete(): void
    {
        $this->showDeleteModal = true;
    }

    public function cancelDelete(): void
    {
        $this->showDeleteModal = false;
    }

    public function delete(): void
    {
        $this->authorize('delete', $this->property);

        $this->property->delete();

        session()->flash('success', __('Listing archived successfully.'));

        $this->redirect(route('agent.listings.index'), navigate: true);
    }
}; ?>

<div>
    <div class="max-w-4xl mx-auto">
        <div class="mb-6">
            <flux:button variant="ghost" href="{{ route('agent.listings.index') }}" icon="arrow-left">
                {{ __('Back to Listings') }}
            </flux:button>
        </div>

        @if (session('success'))
            <flux:callout variant="success" class="mb-4">
                {{ session('success') }}
            </flux:callout>
        @endif

        <div class="flex items-center justify-between mb-6">
            <div>
                <flux:heading size="xl">{{ $this->listing->address }}</flux:heading>
                <flux:text class="text-zinc-500">{{ $this->listing->city }}, {{ $this->listing->state }} {{ $this->listing->zip }}</flux:text>
            </div>
            <div class="flex items-center gap-2">
                <flux:button variant="primary" icon="pencil" wire:click="$dispatch('edit-listing', { listingId: {{ $this->listing->id }} })">
                    {{ __('Edit') }}
                </flux:button>
                <flux:button variant="danger" icon="trash" wire:click="confirmDelete">
                    {{ __('Archive') }}
                </flux:button>
            </div>
        </div>

        <!-- Stats Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                <flux:text class="text-zinc-500 text-sm">{{ __('Status') }}</flux:text>
                <div class="mt-1">
                    @switch($this->listing->listing_status)
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
                </div>
            </div>
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                <flux:text class="text-zinc-500 text-sm">{{ __('Listing Price') }}</flux:text>
                <flux:text class="text-lg font-semibold">
                    @if ($this->listing->listing_price)
                        ${{ number_format($this->listing->listing_price, 0) }}
                    @else
                        {{ __('Not set') }}
                    @endif
                </flux:text>
            </div>
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                <flux:text class="text-zinc-500 text-sm">{{ __('Customers Referred') }}</flux:text>
                <flux:text class="text-lg font-semibold">{{ $this->referredCustomersCount }}</flux:text>
            </div>
        </div>

        <!-- Details Card -->
        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 mb-6">
            <flux:heading size="lg" class="mb-4">{{ __('Listing Details') }}</flux:heading>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <flux:text class="text-zinc-500 text-sm">{{ __('Full Address') }}</flux:text>
                    <flux:text class="font-medium">{{ $this->listing->full_address }}</flux:text>
                </div>
                <div>
                    <flux:text class="text-zinc-500 text-sm">{{ __('Open House Date') }}</flux:text>
                    @if ($this->listing->open_house_date)
                        <div class="flex items-center gap-2">
                            <flux:text class="font-medium">{{ $this->listing->open_house_date->format('M j, Y g:i A') }}</flux:text>
                            @if ($this->listing->hasUpcomingOpenHouse())
                                <flux:badge size="sm" color="blue">{{ __('Upcoming') }}</flux:badge>
                            @endif
                        </div>
                    @else
                        <flux:text class="text-zinc-500">{{ __('Not scheduled') }}</flux:text>
                    @endif
                </div>
            </div>

            @if ($this->listing->description)
                <div class="mt-6">
                    <flux:text class="text-zinc-500 text-sm">{{ __('Description') }}</flux:text>
                    <flux:text class="whitespace-pre-wrap">{{ $this->listing->description }}</flux:text>
                </div>
            @endif

            @if ($this->listing->notes)
                <div class="mt-6">
                    <flux:text class="text-zinc-500 text-sm">{{ __('Notes') }}</flux:text>
                    <flux:text class="whitespace-pre-wrap">{{ $this->listing->notes }}</flux:text>
                </div>
            @endif
        </div>

        <!-- Photos Section -->
        @livewire('agent.listings.photo-gallery', ['listing' => $this->listing], key('photo-gallery-'.$this->listing->id))

        <!-- Created/Updated Info -->
        <div class="text-sm text-zinc-500">
            <flux:text>{{ __('Created') }}: {{ $this->listing->created_at->format('M j, Y g:i A') }}</flux:text>
            @if ($this->listing->updated_at->ne($this->listing->created_at))
                <flux:text class="ml-4">{{ __('Updated') }}: {{ $this->listing->updated_at->format('M j, Y g:i A') }}</flux:text>
            @endif
        </div>
    </div>

    <!-- Delete Confirmation Modal -->
    <flux:modal wire:model="showDeleteModal" class="max-w-md">
        <div class="text-center">
            <flux:icon name="exclamation-triangle" class="mx-auto h-12 w-12 text-red-500" />
            <flux:heading size="lg" class="mt-4">{{ __('Archive Listing') }}</flux:heading>
            <flux:text class="mt-2 text-zinc-500">
                {{ __('Are you sure you want to archive this listing? This action can be undone by an administrator.') }}
            </flux:text>
            <div class="mt-6 flex justify-center gap-3">
                <flux:button variant="ghost" wire:click="cancelDelete">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button variant="danger" wire:click="delete">
                    {{ __('Archive') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

    @livewire('agent.listings.edit-modal')
</div>
