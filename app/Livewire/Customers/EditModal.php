<?php

namespace App\Livewire\Customers;

use App\Models\Customer;
use App\Models\User;
use Livewire\Component;

class EditModal extends Component
{
    public ?int $customerId = null;

    public string $firstName = '';

    public string $lastName = '';

    public string $email = '';

    public string $phone = '';

    public string $address = '';

    public string $city = '';

    public string $state = '';

    public string $zip = '';

    public string $notes = '';

    public ?int $referredBy = null;

    public bool $showModal = false;

    public function mount(?int $customerId = null): void
    {
        if ($customerId) {
            $this->loadCustomer($customerId);
        }
    }

    public function loadCustomer(int $customerId): void
    {
        $this->customerId = $customerId;
        $customer = Customer::findOrFail($customerId);

        $this->firstName = $customer->first_name;
        $this->lastName = $customer->last_name;
        $this->email = $customer->email;
        $this->phone = $customer->phone ?? '';
        $this->address = $customer->address ?? '';
        $this->city = $customer->city ?? '';
        $this->state = $customer->state ?? '';
        $this->zip = $customer->zip ?? '';
        $this->notes = $customer->notes ?? '';
        $this->referredBy = $customer->referred_by;
    }

    public function openModal(?int $customerId = null): void
    {
        if ($customerId) {
            $this->loadCustomer($customerId);
        }
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->reset([
            'customerId',
            'firstName',
            'lastName',
            'email',
            'phone',
            'address',
            'city',
            'state',
            'zip',
            'notes',
            'referredBy',
        ]);
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->validate([
            'customerId' => ['required', 'exists:customers,id'],
            'firstName' => ['required', 'string', 'max:255'],
            'lastName' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:customers,email,'.$this->customerId],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:50'],
            'zip' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'referredBy' => ['nullable', 'exists:users,id'],
        ]);

        $customer = Customer::findOrFail($this->customerId);

        $customer->update([
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'email' => $this->email,
            'phone' => $this->phone ?: null,
            'address' => $this->address ?: null,
            'city' => $this->city ?: null,
            'state' => $this->state ?: null,
            'zip' => $this->zip ?: null,
            'notes' => $this->notes ?: null,
            'referred_by' => $this->referredBy,
        ]);

        $this->dispatch('customer-updated');
        session()->flash('success', 'Customer updated successfully.');
        $this->closeModal();
    }

    public function render()
    {
        $agents = User::query()
            ->where('type', 'agent')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return view('livewire.customers.edit-modal', [
            'agents' => $agents,
        ]);
    }
}
