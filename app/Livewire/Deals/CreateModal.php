<?php

namespace App\Livewire\Deals;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Property;
use App\Models\User;
use Livewire\Component;

class CreateModal extends Component
{
    public ?int $customerId = null;

    public ?int $propertyId = null;

    public string $title = '';

    public string $description = '';

    public string $stage = 'lead';

    public string $estimatedValue = '';

    public string $expectedStartDate = '';

    public string $expectedEndDate = '';

    public ?int $assignedTo = null;

    public bool $showModal = false;

    public string $customerSearch = '';

    // Initial values to pre-populate when opening the modal
    public ?int $initialCustomerId = null;

    public ?int $initialPropertyId = null;

    public function mount(?int $customerId = null, ?int $propertyId = null): void
    {
        $this->initialCustomerId = $customerId;
        $this->initialPropertyId = $propertyId;
    }

    public function openModal(): void
    {
        // Pre-populate with initial values if provided
        if ($this->initialCustomerId) {
            $this->customerId = $this->initialCustomerId;
        }
        if ($this->initialPropertyId) {
            $this->propertyId = $this->initialPropertyId;
        }
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->reset([
            'customerId',
            'propertyId',
            'title',
            'description',
            'stage',
            'estimatedValue',
            'expectedStartDate',
            'expectedEndDate',
            'assignedTo',
            'customerSearch',
        ]);
        $this->resetValidation();
    }

    public function updatedCustomerId(): void
    {
        $this->propertyId = null;
    }

    public function save(): void
    {
        $this->validate([
            'customerId' => ['required', 'exists:customers,id'],
            'propertyId' => ['nullable', 'exists:properties,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'stage' => ['required', 'string', 'in:'.implode(',', array_keys(Deal::STAGES))],
            'estimatedValue' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'expectedStartDate' => ['nullable', 'date'],
            'expectedEndDate' => ['nullable', 'date', 'after_or_equal:expectedStartDate'],
            'assignedTo' => ['nullable', 'exists:users,id'],
        ]);

        Deal::create([
            'customer_id' => $this->customerId,
            'property_id' => $this->propertyId ?: null,
            'title' => $this->title,
            'description' => $this->description ?: null,
            'stage' => $this->stage,
            'estimated_value' => $this->estimatedValue !== '' ? $this->estimatedValue : null,
            'expected_start_date' => $this->expectedStartDate ?: null,
            'expected_end_date' => $this->expectedEndDate ?: null,
            'assigned_to' => $this->assignedTo,
            'created_by' => auth()->id(),
        ]);

        $this->dispatch('deal-created');
        session()->flash('success', 'Deal created successfully.');
        $this->closeModal();
    }

    public function render()
    {
        $customers = Customer::query()
            ->when($this->customerSearch, fn ($query) => $query->search($this->customerSearch))
            ->orderBy('first_name')
            ->limit(50)
            ->get();

        $properties = $this->customerId
            ? Property::where('customer_id', $this->customerId)->orderBy('name')->get()
            : collect();

        $internalUsers = User::where('type', 'internal')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return view('livewire.deals.create-modal', [
            'customers' => $customers,
            'properties' => $properties,
            'internalUsers' => $internalUsers,
            'stages' => Deal::STAGES,
        ]);
    }
}
