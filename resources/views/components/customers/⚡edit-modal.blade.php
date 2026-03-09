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

    #[Computed]
    public function internalUsers()
    {
        return User::where('type', 'internal')->where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function customerSources(): array
    {
        return \App\Models\LeadSource::getOptions();
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

    public string $source = '';

    public string $estimatePreference = '';

    public ?int $assignedTo = null;

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

        $this->firstName = $customer->first_name ?? '';
        $this->lastName = $customer->last_name ?? '';
        $this->email = $customer->email ?? '';
        $this->phone = $customer->phone ?? '';
        $this->address = $customer->address ?? '';
        $this->city = $customer->city ?? '';
        $this->state = $customer->state ?? '';
        $this->zip = $customer->zip ?? '';
        $this->notes = $customer->notes ?? '';
        $this->referredBy = $customer->referred_by;
        $this->source = $customer->source ?? '';
        $this->estimatePreference = $customer->estimate_preference ?? '';
        $this->assignedTo = $customer->assigned_to;
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
            'source',
            'estimatePreference',
            'assignedTo',
        ]);
        $this->resetValidation();
    }

    public function save(): void
    {
        $this->validate([
            'customerId' => ['required', 'exists:customers,id'],
            'firstName' => ['required', 'string', 'max:255'],
            'lastName' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', 'unique:customers,email,'.$this->customerId],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:50'],
            'zip' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'referredBy' => ['nullable', 'exists:users,id'],
            'source' => ['nullable', 'string', 'in:'.implode(',', array_keys(\App\Models\LeadSource::getOptions()))],
            'estimatePreference' => ['nullable', 'string', 'in:virtual,in_person'],
            'assignedTo' => ['nullable', 'exists:users,id'],
        ]);

        $customer = Customer::findOrFail($this->customerId);

        $customer->update([
            'first_name' => $this->firstName,
            'last_name' => $this->lastName,
            'email' => $this->email ?: null,
            'phone' => $this->phone ?: null,
            'address' => $this->address ?: null,
            'city' => $this->city ?: null,
            'state' => $this->state ?: null,
            'zip' => $this->zip ?: null,
            'notes' => $this->notes ?: null,
            'referred_by' => $this->referredBy,
            'assigned_to' => $this->assignedTo,
            'source' => $this->source ?: null,
            'estimate_preference' => $this->estimatePreference ?: null,
        ]);

        $this->dispatch('customer-updated');
        session()->flash('success', __('Customer updated successfully.'));
        $this->closeModal();
    }
}; ?>

<div>
    <flux:button wire:click="openModal({{ $customerId ?? 'null' }})" icon="pencil" variant="ghost" size="sm">
        <span class="hidden sm:inline">{{ __('Edit') }}</span>
    </flux:button>

    <flux:modal wire:model="showModal" variant="flyout" class="max-w-lg">
        <form wire:submit="save">
            <flux:heading size="lg" class="mb-6">{{ __('Edit Customer') }}</flux:heading>

            <div class="space-y-4">
                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:field>
                        <flux:label>{{ __('First Name') }}</flux:label>
                        <flux:input wire:model="firstName" placeholder="John" />
                        <flux:error name="firstName" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Last Name') }}</flux:label>
                        <flux:input wire:model="lastName" placeholder="Doe" />
                        <flux:error name="lastName" />
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>{{ __('Email') }}</flux:label>
                    <flux:input wire:model="email" type="email" placeholder="john@example.com" />
                    <flux:error name="email" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Phone') }}</flux:label>
                    <flux:input wire:model="phone" placeholder="(555) 123-4567" />
                    <flux:error name="phone" />
                </flux:field>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:field>
                        <flux:label>{{ __('Source') }}</flux:label>
                        <flux:select wire:model="source">
                            <option value="">{{ __('Select source...') }}</option>
                            @foreach ($this->customerSources as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </flux:select>
                        <flux:error name="source" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Estimate Preference') }}</flux:label>
                        <div class="flex gap-4 pt-2">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" wire:model="estimatePreference" value="virtual" class="text-blue-600" />
                                <span class="text-sm">{{ __('Virtual') }}</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" wire:model="estimatePreference" value="in_person" class="text-blue-600" />
                                <span class="text-sm">{{ __('In-Person') }}</span>
                            </label>
                        </div>
                        <flux:error name="estimatePreference" />
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>{{ __('Address') }}</flux:label>
                    <flux:input wire:model="address" placeholder="123 Main St" />
                    <flux:error name="address" />
                </flux:field>

                <div class="grid gap-4 sm:grid-cols-3">
                    <flux:field>
                        <flux:label>{{ __('City') }}</flux:label>
                        <flux:input wire:model="city" placeholder="Denver" />
                        <flux:error name="city" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('State') }}</flux:label>
                        <flux:input wire:model="state" placeholder="CO" />
                        <flux:error name="state" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('ZIP') }}</flux:label>
                        <flux:input wire:model="zip" placeholder="80202" />
                        <flux:error name="zip" />
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>{{ __('Notes') }}</flux:label>
                    <flux:textarea wire:model="notes" rows="3" :placeholder="__('Any additional notes...')" />
                    <flux:error name="notes" />
                </flux:field>

                @if ($this->agents->count() > 0)
                    <flux:field>
                        <flux:label>{{ __('Referring Agent') }}</flux:label>
                        <flux:select wire:model="referredBy">
                            <option value="">{{ __('No referring agent') }}</option>
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
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary">
                    {{ __('Save Changes') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
