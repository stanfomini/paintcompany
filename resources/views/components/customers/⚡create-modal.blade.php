<?php

use App\Events\NewLeadCreated;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Property;
use App\Models\User;
use App\Services\InvitationService;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
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

    public bool $sendInvite = true;

    public bool $createProperty = true;

    public bool $createDeal = true;

    // Duplicate detection state
    public bool $showDuplicateWarning = false;

    public ?Customer $existingCustomer = null;

    public string $duplicateAction = '';

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
            'sendInvite',
            'createProperty',
            'createDeal',
            'showDuplicateWarning',
            'existingCustomer',
            'duplicateAction',
        ]);
        $this->sendInvite = true;
        $this->createProperty = true;
        $this->createDeal = true;
        $this->resetValidation();
    }

    public function updatedEmail(): void
    {
        $this->checkForDuplicate();
    }

    public function checkForDuplicate(): void
    {
        if (empty($this->email)) {
            $this->showDuplicateWarning = false;
            $this->existingCustomer = null;

            return;
        }

        // Check for existing customer with this email (including soft-deleted)
        $existing = Customer::withTrashed()
            ->where('email', $this->email)
            ->first();

        if ($existing) {
            $this->existingCustomer = $existing;
            $this->showDuplicateWarning = true;
        } else {
            $this->showDuplicateWarning = false;
            $this->existingCustomer = null;
        }
    }

    public function linkToExisting(): void
    {
        if (! $this->existingCustomer) {
            return;
        }

        // If customer is inactive or soft-deleted, reactivate them
        if ($this->existingCustomer->trashed()) {
            $this->existingCustomer->restore();
        }

        if ($this->existingCustomer->status !== 'active') {
            $this->existingCustomer->update(['status' => 'active']);
        }

        // Create a new lead deal for the reactivated customer
        if ($this->createDeal) {
            $property = $this->existingCustomer->properties()->first();

            $deal = $this->createLeadDeal($this->existingCustomer, $property);

            // Dispatch event for real-time notification
            NewLeadCreated::dispatch($deal);
        }

        $this->dispatch('customer-created');
        session()->flash('success', 'Linked to existing customer and created new lead.');
        $this->closeModal();
    }

    #[Computed]
    public function hasAddress(): bool
    {
        return ! empty($this->address) && ! empty($this->city);
    }

    #[Computed]
    public function agents()
    {
        return User::where('type', 'internal')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    public function save(): void
    {
        // If duplicate warning is shown but user hasn't explicitly confirmed "Create New Anyway", block save
        if ($this->showDuplicateWarning && $this->duplicateAction !== 'confirmed') {
            return;
        }

        $rules = [
            'firstName' => ['required', 'string', 'max:255'],
            'lastName' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', 'max:50'],
            'zip' => ['nullable', 'string', 'max:20'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'referredBy' => ['nullable', 'exists:users,id'],
        ];

        // Only require unique email if not dealing with a duplicate action
        if (! $this->showDuplicateWarning) {
            $rules['email'][] = 'unique:customers,email';
        }

        $this->validate($rules);

        // Create the customer
        $customer = Customer::create([
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

        $property = null;

        // Auto-create property if address is provided
        if ($this->createProperty && $this->hasAddress) {
            $property = $this->createPropertyFromAddress($customer);
        }

        // Auto-create lead deal
        $deal = null;
        if ($this->createDeal) {
            $deal = $this->createLeadDeal($customer, $property);

            // Dispatch event for real-time notification
            NewLeadCreated::dispatch($deal);
        }

        // Send portal invitation
        if ($this->sendInvite) {
            $this->sendPortalInvitation($customer);
        }

        $this->dispatch('customer-created');

        $message = 'Customer created successfully.';
        if ($property) {
            $message .= ' Property created.';
        }
        if ($deal) {
            $message .= ' Lead deal created.';
        }
        if ($this->sendInvite) {
            $message .= ' Invitation sent.';
        }

        session()->flash('success', $message);
        $this->closeModal();
    }

    protected function createPropertyFromAddress(Customer $customer): Property
    {
        return Property::create([
            'customer_id' => $customer->id,
            'name' => null,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state ?: '',
            'zip' => $this->zip ?: '',
            'type' => 'residential',
        ]);
    }

    protected function createLeadDeal(Customer $customer, ?Property $property): Deal
    {
        return Deal::create([
            'customer_id' => $customer->id,
            'property_id' => $property?->id,
            'created_by' => auth()->id(),
            'assigned_to' => auth()->id(),
            'title' => "{$customer->full_name} - New Lead",
            'stage' => 'lead',
            'sort_order' => Deal::where('stage', 'lead')->max('sort_order') + 1,
        ]);
    }

    protected function sendPortalInvitation(Customer $customer): void
    {
        try {
            $invitationService = app(InvitationService::class);
            $invitationService->sendInvitation(
                inviter: auth()->user(),
                email: $customer->email,
                invitedUserType: 'customer'
            );
        } catch (\Exception $e) {
            // Log the error but don't fail the customer creation
            report($e);
        }
    }
}; ?>

<div>
    <flux:button wire:click="openModal" icon="plus">
        Add Customer
    </flux:button>

    <flux:modal wire:model="showModal" variant="flyout" class="max-w-lg">
        <form wire:submit="save">
            <flux:heading size="lg" class="mb-6">Add New Customer</flux:heading>

            {{-- Duplicate Warning --}}
            @if ($showDuplicateWarning && $existingCustomer)
                <flux:callout variant="warning" class="mb-4">
                    <div class="space-y-2">
                        <p class="font-medium">A customer with this email already exists:</p>
                        <p>{{ $existingCustomer->full_name }} ({{ $existingCustomer->email }})</p>
                        <p class="text-sm">
                            Status:
                            @if ($existingCustomer->trashed())
                                <span class="text-red-600">Deleted</span>
                            @elseif ($existingCustomer->status === 'inactive')
                                <span class="text-yellow-600">Inactive</span>
                            @else
                                <span class="text-green-600">Active</span>
                            @endif
                        </p>

                        <div class="flex gap-2 mt-3">
                            <flux:button
                                type="button"
                                variant="primary"
                                size="sm"
                                wire:click="linkToExisting"
                            >
                                @if ($existingCustomer->trashed() || $existingCustomer->status !== 'active')
                                    Reactivate & Create Lead
                                @else
                                    Use Existing Customer
                                @endif
                            </flux:button>
                            <flux:button
                                type="button"
                                variant="ghost"
                                size="sm"
                                wire:click="$set('duplicateAction', 'confirmed')"
                            >
                                Create New Anyway
                            </flux:button>
                        </div>
                    </div>
                </flux:callout>
            @endif

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
                    <flux:input wire:model.blur="email" type="email" placeholder="john@example.com" />
                    <flux:error name="email" />
                </flux:field>

                <flux:field>
                    <flux:label>Phone</flux:label>
                    <flux:input wire:model="phone" placeholder="(555) 123-4567" />
                    <flux:error name="phone" />
                </flux:field>

                <flux:separator />

                <flux:heading size="sm">Property Address</flux:heading>
                <flux:text size="sm" class="text-zinc-500">If provided, a property will be automatically created.</flux:text>

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

                <flux:separator />

                <flux:heading size="sm">Auto-Actions</flux:heading>

                <div class="space-y-3">
                    <flux:checkbox
                        wire:model="createDeal"
                        label="Create lead deal"
                        description="Automatically create a new deal in the Lead stage"
                    />

                    <flux:checkbox
                        wire:model="createProperty"
                        label="Create property from address"
                        description="Only if address and city are provided"
                        :disabled="!$this->hasAddress"
                    />

                    <flux:checkbox
                        wire:model="sendInvite"
                        label="Send portal invitation"
                        description="Email customer an invitation to access their portal"
                    />
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="closeModal" type="button">
                    Cancel
                </flux:button>
                <flux:button
                    type="submit"
                    variant="primary"
                    :disabled="$showDuplicateWarning && $duplicateAction !== 'confirmed'"
                >
                    Create Customer
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
