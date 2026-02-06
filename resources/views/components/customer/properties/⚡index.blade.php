<?php

use App\Models\Customer;
use App\Models\Property;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('components.layouts.customer', ['title' => 'My Properties'])] class extends Component {
    use WithPagination;

    public string $search = '';

    public int $perPage = 15;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function properties()
    {
        $customer = Customer::query()
            ->where('user_id', auth('customer')->id())
            ->first();

        if (! $customer) {
            return collect();
        }

        return Property::query()
            ->where('customer_id', $customer->id)
            ->withCount('photos', 'deals')
            ->when($this->search, fn ($q) => $q->search($this->search))
            ->latest()
            ->paginate($this->perPage);
    }
}; ?>

<div>
    <div class="max-w-7xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <flux:heading size="xl">{{ __('My Properties') }}</flux:heading>
        </div>

        <div class="flex flex-col sm:flex-row gap-4 mb-6">
            <div class="flex-1">
                <flux:input
                    wire:model.live.debounce.300ms="search"
                    type="search"
                    placeholder="{{ __('Search by address, city, or zip...') }}"
                    icon="magnifying-glass"
                />
            </div>
        </div>

        @if ($this->properties instanceof \Illuminate\Pagination\LengthAwarePaginator && $this->properties->count() > 0)
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                @foreach ($this->properties as $property)
                    <a wire:key="property-{{ $property->id }}" href="{{ route('customer.properties.show', $property) }}" class="block">
                        <flux:card class="h-full hover:border-zinc-300 dark:hover:border-zinc-600 transition-colors">
                            <div class="flex items-start justify-between mb-3">
                                <div class="flex-1">
                                    <flux:heading size="sm" class="font-medium truncate">{{ $property->display_name }}</flux:heading>
                                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400 truncate">
                                        {{ $property->full_address }}
                                    </flux:text>
                                </div>
                            </div>

                            <div class="flex items-center gap-4 text-sm text-zinc-500 dark:text-zinc-400 mb-3">
                                @if ($property->type)
                                    <div class="flex items-center gap-1">
                                        <flux:icon name="home" class="h-4 w-4" />
                                        <span class="capitalize">{{ $property->type }}</span>
                                    </div>
                                @endif
                                @if ($property->square_footage)
                                    <div class="flex items-center gap-1">
                                        <flux:icon name="square-2-stack" class="h-4 w-4" />
                                        <span>{{ number_format($property->square_footage) }} sq ft</span>
                                    </div>
                                @endif
                            </div>

                            <div class="flex items-center justify-between pt-3 border-t border-zinc-200 dark:border-zinc-700">
                                <div class="flex items-center gap-4">
                                    <div class="flex items-center gap-1 text-zinc-500">
                                        <flux:icon name="photo" class="h-4 w-4" />
                                        <flux:text class="text-sm">{{ $property->photos_count }} {{ __('photos') }}</flux:text>
                                    </div>
                                    <div class="flex items-center gap-1 text-zinc-500">
                                        <flux:icon name="briefcase" class="h-4 w-4" />
                                        <flux:text class="text-sm">{{ $property->deals_count }} {{ __('projects') }}</flux:text>
                                    </div>
                                </div>
                            </div>
                        </flux:card>
                    </a>
                @endforeach
            </div>

            <div class="mt-6">
                {{ $this->properties->links() }}
            </div>
        @else
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
                <flux:icon name="home" class="mx-auto h-12 w-12 text-zinc-400" />
                <flux:heading size="sm" class="mt-4">{{ __('No properties found') }}</flux:heading>
                <flux:text class="mt-2 text-zinc-500">
                    @if ($search)
                        {{ __('Try adjusting your search criteria.') }}
                    @else
                        {{ __('Your properties will appear here once added.') }}
                    @endif
                </flux:text>
            </div>
        @endif
    </div>
</div>
