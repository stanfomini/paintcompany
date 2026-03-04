<?php

use App\Models\Customer;
use App\Models\Property;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public ?int $propertyId = null;

    public string $customerId = '';

    public string $name = '';

    public string $address = '';

    public string $city = '';

    public string $state = '';

    public string $zip = '';

    public string $type = 'residential';

    public string $squareFootage = '';

    public string $yearBuilt = '';

    public string $notes = '';

    public bool $showModal = false;

    public function mount(?int $propertyId = null): void
    {
        if ($propertyId) {
            $this->loadProperty($propertyId);
        }
    }

    public function loadProperty(int $propertyId): void
    {
        $this->propertyId = $propertyId;
        $property = Property::findOrFail($propertyId);

        $this->customerId = (string) $property->customer_id;
        $this->name = $property->name ?? '';
        $this->address = $property->address ?? '';
        $this->city = $property->city ?? '';
        $this->state = $property->state ?? '';
        $this->zip = $property->zip ?? '';
        $this->type = $property->type ?? 'residential';
        $this->squareFootage = $property->square_footage ? (string) $property->square_footage : '';
        $this->yearBuilt = $property->year_built ? (string) $property->year_built : '';
        $this->notes = $property->notes ?? '';
    }

    public function openModal(?int $propertyId = null): void
    {
        if ($propertyId) {
            $this->loadProperty($propertyId);
        }
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->reset([
            'propertyId',
            'customerId',
            'name',
            'address',
            'city',
            'state',
            'zip',
            'type',
            'squareFootage',
            'yearBuilt',
            'notes',
        ]);
        $this->resetValidation();
    }

    #[Computed]
    public function customers(): Collection
    {
        return Customer::query()
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name']);
    }

    public function save(): void
    {
        $this->validate([
            'propertyId' => ['required', 'exists:properties,id'],
            'customerId' => ['required', 'exists:customers,id'],
            'address' => ['required', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['required', 'string', 'max:50'],
            'zip' => ['required', 'string', 'max:20'],
            'type' => ['required', 'string', 'in:residential,commercial,industrial'],
            'squareFootage' => ['nullable', 'integer', 'min:1'],
            'yearBuilt' => ['nullable', 'integer', 'min:1800', 'max:'.date('Y')],
            'notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $property = Property::findOrFail($this->propertyId);

        $property->update([
            'customer_id' => $this->customerId,
            'name' => $this->name ?: null,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'zip' => $this->zip,
            'type' => $this->type,
            'square_footage' => $this->squareFootage ?: null,
            'year_built' => $this->yearBuilt ?: null,
            'notes' => $this->notes ?: null,
        ]);

        $this->dispatch('property-updated');
        session()->flash('success', __('Property updated successfully.'));
        $this->closeModal();
    }
}; ?>

<div>
    <flux:button wire:click="openModal({{ $propertyId ?? 'null' }})" icon="pencil" variant="ghost">
        {{ __('Edit') }}
    </flux:button>

    <flux:modal wire:model="showModal" variant="flyout" class="max-w-lg">
        <form wire:submit="save">
            <flux:heading size="lg" class="mb-6">{{ __('Edit Property') }}</flux:heading>

            <div class="space-y-4">
                <flux:field>
                    <flux:label>{{ __('Customer') }}</flux:label>
                    <flux:select wire:model="customerId">
                        <option value="">{{ __('Select a customer...') }}</option>
                        @foreach ($this->customers as $customer)
                            <option value="{{ $customer->id }}">{{ $customer->first_name }} {{ $customer->last_name }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="customerId" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Property Name (optional)') }}</flux:label>
                    <flux:input wire:model="name" :placeholder="__('e.g., Main Office Building')" />
                    <flux:error name="name" />
                </flux:field>

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
                        <flux:input wire:model="state" placeholder="CA" />
                        <flux:error name="state" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('ZIP') }}</flux:label>
                        <flux:input wire:model="zip" placeholder="90001" />
                        <flux:error name="zip" />
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>{{ __('Property Type') }}</flux:label>
                    <flux:select wire:model="type">
                        <option value="residential">{{ __('Residential') }}</option>
                        <option value="commercial">{{ __('Commercial') }}</option>
                        <option value="industrial">{{ __('Industrial') }}</option>
                    </flux:select>
                    <flux:error name="type" />
                </flux:field>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:field>
                        <flux:label>{{ __('Square Footage') }}</flux:label>
                        <flux:input wire:model="squareFootage" type="number" placeholder="2500" />
                        <flux:error name="squareFootage" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Year Built') }}</flux:label>
                        <flux:input wire:model="yearBuilt" type="number" placeholder="1990" />
                        <flux:error name="yearBuilt" />
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>{{ __('Notes') }}</flux:label>
                    <flux:textarea wire:model="notes" rows="3" :placeholder="__('Any additional notes...')" />
                    <flux:error name="notes" />
                </flux:field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="closeModal" type="button">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary">
                    {{ __('Save Changes') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
