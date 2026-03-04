<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Property;
use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    #[Computed]
    public function customers()
    {
        return Customer::query()->select('id', 'first_name', 'last_name', 'user_id')->orderBy('first_name')->orderBy('last_name')->get();
    }

    #[Computed]
    public function properties()
    {
        if (! $this->customerId) {
            return collect();
        }

        return Property::where('customer_id', $this->customerId)->orderBy('address')->get();
    }

    #[Computed]
    public function stages(): array
    {
        return Deal::STAGES;
    }

    #[Computed]
    public function internalUsers()
    {
        return User::query()->select('id', 'name')->where('type', 'internal')->where('is_active', true)->orderBy('name')->get();
    }
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
        session()->flash('success', __('Deal created successfully.'));
        $this->closeModal();
    }
}; ?>

<div>
    <flux:button wire:click="openModal" icon="plus">
        {{ __('Add Deal') }}
    </flux:button>

    <flux:modal wire:model="showModal" variant="flyout" class="max-w-lg">
        <form wire:submit="save">
            <flux:heading size="lg" class="mb-6">{{ __('Add New Deal') }}</flux:heading>

            <div class="space-y-4">
                <flux:field>
                    <flux:label>{{ __('Customer') }}</flux:label>
                    <flux:select wire:model.live="customerId" placeholder="{{ __('Select a customer...') }}">
                        <flux:select.option value="">{{ __('Select a customer...') }}</flux:select.option>
                        @foreach ($this->customers as $customer)
                            <flux:select.option value="{{ $customer->id }}">
                                {{ $customer->full_name }} ({{ $customer->email }})
                            </flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="customerId" />
                </flux:field>

                @if ($customerId && $this->properties->isNotEmpty())
                    <flux:field>
                        <flux:label>{{ __('Property (Optional)') }}</flux:label>
                        <flux:select wire:model="propertyId" placeholder="{{ __('Select a property...') }}">
                            <flux:select.option value="">{{ __('No specific property') }}</flux:select.option>
                            @foreach ($this->properties as $property)
                                <flux:select.option value="{{ $property->id }}">
                                    {{ $property->display_name }}
                                </flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="propertyId" />
                    </flux:field>
                @endif

                <flux:field>
                    <flux:label>{{ __('Title') }}</flux:label>
                    <flux:input wire:model="title" placeholder="{{ __('e.g., Exterior Painting - Main House') }}" />
                    <flux:error name="title" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Description (Optional)') }}</flux:label>
                    <flux:textarea wire:model="description" rows="3" placeholder="{{ __('Details about this deal...') }}" />
                    <flux:error name="description" />
                </flux:field>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:field>
                        <flux:label>{{ __('Stage') }}</flux:label>
                        <flux:select wire:model="stage">
                            @foreach ($this->stages as $value => $label)
                                <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:error name="stage" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Estimated Value') }}</flux:label>
                        <flux:input wire:model="estimatedValue" type="number" step="0.01" min="0" placeholder="0.00" icon="currency-dollar" />
                        <flux:error name="estimatedValue" />
                    </flux:field>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:field>
                        <flux:label>{{ __('Expected Start Date') }}</flux:label>
                        <flux:input wire:model="expectedStartDate" type="date" />
                        <flux:error name="expectedStartDate" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Expected End Date') }}</flux:label>
                        <flux:input wire:model="expectedEndDate" type="date" />
                        <flux:error name="expectedEndDate" />
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>{{ __('Assigned To (Optional)') }}</flux:label>
                    <flux:select wire:model="assignedTo" placeholder="{{ __('Select team member...') }}">
                        <flux:select.option value="">{{ __('Unassigned') }}</flux:select.option>
                        @foreach ($this->internalUsers as $user)
                            <flux:select.option value="{{ $user->id }}">{{ $user->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:error name="assignedTo" />
                </flux:field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="closeModal" type="button">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary">
                    {{ __('Create Deal') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
