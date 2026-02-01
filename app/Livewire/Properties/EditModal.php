<?php

namespace App\Livewire\Properties;

use App\Models\Customer;
use App\Models\Property;
use Illuminate\Support\Collection;
use Livewire\Component;

class EditModal extends Component
{
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

    public function getCustomersProperty(): Collection
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
        session()->flash('success', 'Property updated successfully.');
        $this->closeModal();
    }

    public function render()
    {
        return view('livewire.properties.edit-modal');
    }
}
