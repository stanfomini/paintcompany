<?php

use App\Models\AgentListing;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    use AuthorizesRequests;

    #[Computed]
    public function statuses(): array
    {
        return [
            'active' => 'Active',
            'pending' => 'Pending',
            'sold' => 'Sold',
            'inactive' => 'Inactive',
        ];
    }

    public ?AgentListing $listing = null;

    public string $address = '';

    public string $city = '';

    public string $state = '';

    public string $zip = '';

    public string $status = 'active';

    public ?string $listingPrice = null;

    public string $description = '';

    public string $notes = '';

    public ?string $openHouseDate = null;

    public bool $showModal = false;

    #[On('edit-listing')]
    public function openModal(int $listingId): void
    {
        $this->listing = AgentListing::findOrFail($listingId);

        $this->authorize('update', $this->listing);

        $this->address = $this->listing->address;
        $this->city = $this->listing->city;
        $this->state = $this->listing->state;
        $this->zip = $this->listing->zip;
        $this->status = $this->listing->status;
        $this->listingPrice = $this->listing->listing_price;
        $this->description = $this->listing->description ?? '';
        $this->notes = $this->listing->notes ?? '';
        $this->openHouseDate = $this->listing->open_house_date?->format('Y-m-d\TH:i');

        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->listing = null;
        $this->reset([
            'address',
            'city',
            'state',
            'zip',
            'status',
            'listingPrice',
            'description',
            'notes',
            'openHouseDate',
        ]);
        $this->resetValidation();
    }

    public function save(): void
    {
        if (! $this->listing) {
            return;
        }

        $this->authorize('update', $this->listing);

        $this->validate([
            'address' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['required', 'string', 'max:2'],
            'zip' => ['required', 'string', 'max:10'],
            'status' => ['required', 'in:active,pending,sold,inactive'],
            'listingPrice' => ['nullable', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'openHouseDate' => ['nullable', 'date'],
        ]);

        $this->listing->update([
            'address' => $this->address,
            'city' => $this->city,
            'state' => strtoupper($this->state),
            'zip' => $this->zip,
            'status' => $this->status,
            'listing_price' => $this->listingPrice ?: null,
            'description' => $this->description ?: null,
            'notes' => $this->notes ?: null,
            'open_house_date' => $this->openHouseDate ?: null,
        ]);

        $this->dispatch('listing-updated');
        session()->flash('success', 'Listing updated successfully.');
        $this->closeModal();
    }
}; ?>

<div>
    <flux:modal wire:model="showModal" variant="flyout" class="max-w-lg">
        <form wire:submit="save">
            <flux:heading size="lg" class="mb-6">{{ __('Edit Listing') }}</flux:heading>

            <div class="space-y-4">
                <flux:field>
                    <flux:label>{{ __('Address') }}</flux:label>
                    <flux:input wire:model="address" placeholder="123 Main St" />
                    <flux:error name="address" />
                </flux:field>

                <div class="grid gap-4 sm:grid-cols-3">
                    <flux:field>
                        <flux:label>{{ __('City') }}</flux:label>
                        <flux:input wire:model="city" placeholder="Los Angeles" />
                        <flux:error name="city" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('State') }}</flux:label>
                        <flux:input wire:model="state" maxlength="2" placeholder="CA" />
                        <flux:error name="state" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('ZIP') }}</flux:label>
                        <flux:input wire:model="zip" placeholder="90001" />
                        <flux:error name="zip" />
                    </flux:field>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:field>
                        <flux:label>{{ __('Listing Price') }}</flux:label>
                        <flux:input wire:model="listingPrice" type="number" step="0.01" placeholder="350000" />
                        <flux:error name="listingPrice" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Status') }}</flux:label>
                        <flux:select wire:model="status">
                            @foreach ($this->statuses as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </flux:select>
                        <flux:error name="status" />
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>{{ __('Open House Date/Time') }}</flux:label>
                    <flux:input wire:model="openHouseDate" type="datetime-local" />
                    <flux:error name="openHouseDate" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Description') }}</flux:label>
                    <flux:textarea wire:model="description" rows="3" placeholder="Property description..." />
                    <flux:error name="description" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Notes') }}</flux:label>
                    <flux:textarea wire:model="notes" rows="2" placeholder="Internal notes..." />
                    <flux:error name="notes" />
                </flux:field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="closeModal" type="button">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary">
                    {{ __('Update Listing') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
