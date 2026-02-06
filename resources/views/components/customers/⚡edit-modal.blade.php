<?php

use App\Models\Customer;
use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    #[Computed]
    public function agents()
    {
        return User::where('type', 'agent')->where('is_active', true)->orderBy('name')->get();
    }
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
}; ?>

<div>
    <flux:button wire:click="openModal({{ $customerId ?? 'null' }})" icon="pencil" variant="ghost">
        Edit
    </flux:button>

    <flux:modal wire:model="showModal" variant="flyout" class="max-w-lg">
        <form wire:submit="save">
            <flux:heading size="lg" class="mb-6">Edit Customer</flux:heading>

            <div class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:field>
                        <flux:label>First Name</flux:label>
                        <flux:input wire:model="firstName" placeholder="John" />
                        <flux:error name="firstName" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Last Name</flux:label>
                        <flux:input wire:model="lastName" placeholder="Doe" />
                        <flux:error name="lastName" />
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>Email</flux:label>
                    <flux:input wire:model="email" type="email" placeholder="john@example.com" />
                    <flux:error name="email" />
                </flux:field>

                <flux:field>
                    <flux:label>Phone</flux:label>
                    <flux:input wire:model="phone" placeholder="(555) 123-4567" />
                    <flux:error name="phone" />
                </flux:field>

                <flux:field>
                    <flux:label>Address</flux:label>
                    <flux:input wire:model="address" placeholder="123 Main St" />
                    <flux:error name="address" />
                </flux:field>

                <div class="grid gap-4 sm:grid-cols-3">
                    <flux:field>
                        <flux:label>City</flux:label>
                        <flux:input wire:model="city" placeholder="Los Angeles" />
                        <flux:error name="city" />
                    </flux:field>

                    <flux:field>
                        <flux:label>State</flux:label>
                        <flux:input wire:model="state" placeholder="CA" />
                        <flux:error name="state" />
                    </flux:field>

                    <flux:field>
                        <flux:label>ZIP</flux:label>
                        <flux:input wire:model="zip" placeholder="90001" />
                        <flux:error name="zip" />
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>Notes</flux:label>
                    <flux:textarea wire:model="notes" rows="3" placeholder="Any additional notes..." />
                    <flux:error name="notes" />
                </flux:field>

                @if ($this->agents->count() > 0)
                    <flux:field>
                        <flux:label>Referring Agent</flux:label>
                        <flux:select wire:model="referredBy">
                            <option value="">No referring agent</option>
                            @foreach ($this->agents as $agent)
                                <option value="{{ $agent->id }}">{{ $agent->name }}</option>
                            @endforeach
                        </flux:select>
                        <flux:error name="referredBy" />
                    </flux:field>
                @endif
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="closeModal" type="button">
                    Cancel
                </flux:button>
                <flux:button type="submit" variant="primary">
                    Save Changes
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
