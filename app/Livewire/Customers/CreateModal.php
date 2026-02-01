<?php

namespace App\Livewire\Customers;

use App\Models\Customer;
use App\Models\User;
use Livewire\Component;

class CreateModal extends Component
{
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

    public function openModal(): void
    {
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->reset([
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
            'firstName' => ['required', 'string', 'max:255'],
            'lastName' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:customers,email'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:50'],
            'zip' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'referredBy' => ['nullable', 'exists:users,id'],
        ]);

        Customer::create([
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'email' => $this->email,
            'phone' => $this->phone ?: null,
            'address' => $this->address ?: null,
            'city' => $this->city ?: null,
            'state' => $this->state ?: null,
            'zip' => $this->zip ?: null,
            'notes' => $this->notes ?: null,
            'created_by' => auth()->id(),
            'referred_by' => $this->referredBy,
            'status' => 'active',
        ]);

        $this->dispatch('customer-created');
        session()->flash('success', 'Customer created successfully.');
        $this->closeModal();
    }

    public function render()
    {
        $agents = User::query()
            ->where('type', 'agent')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        return view('livewire.customers.create-modal', [
            'agents' => $agents,
        ]);
    }
}
