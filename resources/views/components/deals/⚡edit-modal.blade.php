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
        return Customer::query()->orderBy('first_name')->orderBy('last_name')->get();
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
        return User::where('type', 'internal')->where('is_active', true)->orderBy('name')->get();
    }
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
        session()->flash('success', __('Deal updated successfully.'));
        $this->closeModal();
    }
}; ?>

<div>
    <flux:button wire:click="openModal({{ $dealId ?? 'null' }})" icon="pencil" variant="ghost">
        {{ __('Edit') }}
    </flux:button>

    <flux:modal wire:model="showModal" variant="flyout" class="max-w-lg">
        <form wire:submit="save">
            <flux:heading size="lg" class="mb-6">{{ __('Edit Deal') }}</flux:heading>

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

                <flux:separator />

                <flux:heading size="sm">{{ __('Actual Values (for Completed/Paid Deals)') }}</flux:heading>

                <flux:field>
                    <flux:label>{{ __('Actual Value') }}</flux:label>
                    <flux:input wire:model="actualValue" type="number" step="0.01" min="0" placeholder="0.00" icon="currency-dollar" />
                    <flux:error name="actualValue" />
                </flux:field>

                <div class="grid gap-4 sm:grid-cols-2">
                    <flux:field>
                        <flux:label>{{ __('Actual Start Date') }}</flux:label>
                        <flux:input wire:model="actualStartDate" type="date" />
                        <flux:error name="actualStartDate" />
                    </flux:field>

                    <flux:field>
                        <flux:label>{{ __('Actual End Date') }}</flux:label>
                        <flux:input wire:model="actualEndDate" type="date" />
                        <flux:error name="actualEndDate" />
                    </flux:field>
                </div>

                <flux:separator />

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
                    {{ __('Save Changes') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
