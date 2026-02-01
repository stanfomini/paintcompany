<?php

namespace App\Livewire\Deals;

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Property;
use App\Models\User;
use Livewire\Component;

class EditModal extends Component
{
    public ?int $dealId = null;

    public ?int $customerId = null;

    public ?int $propertyId = null;

    public string $title = '';

    public string $description = '';

    public string $stage = 'lead';

    public string $estimatedValue = '';

    public string $actualValue = '';

    public string $expectedStartDate = '';

    public string $expectedEndDate = '';

    public string $actualStartDate = '';

    public string $actualEndDate = '';

    public ?int $assignedTo = null;

    public bool $showModal = false;

    public function mount(?int $dealId = null): void
    {
        if ($dealId) {
            $this->loadDeal($dealId);
        }
    }

    public function loadDeal(int $dealId): void
    {
        $this->dealId = $dealId;
        $deal = Deal::findOrFail($dealId);

        $this->customerId = $deal->customer_id;
        $this->propertyId = $deal->property_id;
        $this->title = $deal->title;
        $this->description = $deal->description ?? '';
        $this->stage = $deal->stage;
        $this->estimatedValue = $deal->estimated_value ?? '';
        $this->actualValue = $deal->actual_value ?? '';
        $this->expectedStartDate = $deal->expected_start_date?->format('Y-m-d') ?? '';
        $this->expectedEndDate = $deal->expected_end_date?->format('Y-m-d') ?? '';
        $this->actualStartDate = $deal->actual_start_date?->format('Y-m-d') ?? '';
        $this->actualEndDate = $deal->actual_end_date?->format('Y-m-d') ?? '';
        $this->assignedTo = $deal->assigned_to;
    }

    public function openModal(?int $dealId = null): void
    {
        if ($dealId) {
            $this->loadDeal($dealId);
        }
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->reset([
            'dealId',
            'customerId',
            'propertyId',
            'title',
            'description',
            'stage',
            'estimatedValue',
            'actualValue',
            'expectedStartDate',
            'expectedEndDate',
            'actualStartDate',
            'actualEndDate',
            'assignedTo',
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
            'dealId' => ['required', 'exists:deals,id'],
            'customerId' => ['required', 'exists:customers,id'],
            'propertyId' => ['nullable', 'exists:properties,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'stage' => ['required', 'string', 'in:'.implode(',', array_keys(Deal::STAGES))],
            'estimatedValue' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'actualValue' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'expectedStartDate' => ['nullable', 'date'],
            'expectedEndDate' => ['nullable', 'date', 'after_or_equal:expectedStartDate'],
            'actualStartDate' => ['nullable', 'date'],
            'actualEndDate' => ['nullable', 'date', 'after_or_equal:actualStartDate'],
            'assignedTo' => ['nullable', 'exists:users,id'],
        ]);

        $deal = Deal::findOrFail($this->dealId);

        $deal->update([
            'customer_id' => $this->customerId,
            'property_id' => $this->propertyId ?: null,
            'title' => $this->title,
            'description' => $this->description ?: null,
            'stage' => $this->stage,
            'estimated_value' => $this->estimatedValue !== '' ? $this->estimatedValue : null,
            'actual_value' => $this->actualValue !== '' ? $this->actualValue : null,
            'expected_start_date' => $this->expectedStartDate ?: null,
            'expected_end_date' => $this->expectedEndDate ?: null,
            'actual_start_date' => $this->actualStartDate ?: null,
            'actual_end_date' => $this->actualEndDate ?: null,
            'assigned_to' => $this->assignedTo,
        ]);

        $this->dispatch('deal-updated');
        session()->flash('success', 'Deal updated successfully.');
        $this->closeModal();
    }

    public function render()
    {
        $customers = Customer::query()
            ->orderBy('first_name')
            ->get();

        $properties = $this->customerId
            ? Property::where('customer_id', $this->customerId)->orderBy('name')->get()
            : collect();

        $internalUsers = User::where('type', 'internal')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return view('livewire.deals.edit-modal', [
            'customers' => $customers,
            'properties' => $properties,
            'internalUsers' => $internalUsers,
            'stages' => Deal::STAGES,
        ]);
    }
}
