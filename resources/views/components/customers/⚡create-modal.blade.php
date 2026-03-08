<?php

use App\Events\NewLeadCreated;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
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

    public string $estimatePreference = '';

    public string $projectDescription = '';

    public string $leadSource = '';

    public bool $showModal = false;

    public bool $sendInvite = true;

    public bool $createProperty = true;

    public bool $createDeal = true;

    public ?int $assignedTo = null;

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
            'estimatePreference',
            'projectDescription',
            'leadSource',
            'sendInvite',
            'createProperty',
            'createDeal',
            'assignedTo',
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
        session()->flash('success', __('Linked to existing customer and created new lead.'));
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

    #[Computed]
    public function leadSources(): array
    {
        return \App\Models\LeadSource::getOptions();
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
            'estimatePreference' => ['nullable', 'string', 'in:virtual,in_person'],
            'projectDescription' => ['nullable', 'string', 'max:5000'],
            'leadSource' => ['nullable', 'string', 'in:'.implode(',', array_keys(\App\Models\LeadSource::getOptions()))],
            'assignedTo' => ['nullable', 'exists:users,id'],
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
            'assigned_to' => $this->assignedTo ?: auth()->id(),
            'referred_by' => $this->referredBy,
            'estimate_preference' => $this->estimatePreference ?: null,
            'status' => 'active',
        ]);

        app(\App\Services\CustomerDuplicateService::class)->checkAndFlagDuplicates($customer);

        if ($this->leadSource) {
            $customer->update(['source' => $this->leadSource]);
        }

        $property = null;

        // Auto-create property if address is provided
        if ($this->createProperty && $this->hasAddress) {
            $property = $this->createPropertyFromAddress($customer);
        }

        // Auto-create lead deal + draft estimate
        $deal = null;
        if ($this->createDeal) {
            $deal = $this->createLeadDeal($customer, $property);
            $this->createDraftEstimate($deal);

            // Dispatch event for real-time notification
            NewLeadCreated::dispatch($deal);
        }

        // Send portal invitation
        if ($this->sendInvite) {
            $this->sendPortalInvitation($customer);
        }

        $this->dispatch('customer-created');

        $message = __('Customer created successfully.');
        if ($property) {
            $message .= ' ' . __('Property created.');
        }
        if ($deal) {
            $message .= ' ' . __('Lead deal and draft estimate created.');
        }
        if ($this->sendInvite) {
            $message .= ' ' . __('Invitation sent.');
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
            'assigned_to' => $customer->assigned_to ?? auth()->id(),
            'title' => "{$customer->full_name} - New Lead",
            'description' => $this->projectDescription ?: null,
            'stage' => 'lead',
            'lead_source' => $this->leadSource ?: null,
            'sort_order' => Deal::where('stage', 'lead')->max('sort_order') + 1,
        ]);
    }

    protected function createDraftEstimate(Deal $deal): Estimate
    {
        return Estimate::create([
            'deal_id' => $deal->id,
            'created_by' => auth()->id(),
            'title' => $deal->title,
            'status' => 'draft',
            'estimator_mode' => Estimate::MODE_TEMPLATE,
            'subtotal' => 0,
            'tax_amount' => 0,
            'total' => 0,
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
        {{ __('Add Customer') }}
    </flux:button>

    <flux:modal wire:model="showModal" variant="flyout" class="max-w-lg">
        <form wire:submit="save">
            <flux:heading size="lg" class="mb-6">{{ __('Add New Customer') }}</flux:heading>

            {{-- Duplicate Warning --}}
            @if ($showDuplicateWarning && $existingCustomer)
                <flux:callout variant="warning" class="mb-4">
                    <div class="space-y-2">
                        <p class="font-medium">{{ __('A customer with this email already exists:') }}</p>
                        <p>{{ $existingCustomer->full_name }} ({{ $existingCustomer->email }})</p>
                        <p class="text-sm">
                            {{ __('Status:') }}
                            @if ($existingCustomer->trashed())
                                <span class="text-red-600">{{ __('Deleted') }}</span>
                            @elseif ($existingCustomer->status === 'inactive')
                                <span class="text-yellow-600">{{ __('Inactive') }}</span>
                            @else
                                <span class="text-green-600">{{ __('Active') }}</span>
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
                                    {{ __('Reactivate & Create Lead') }}
                                @else
                                    {{ __('Use Existing Customer') }}
                                @endif
                            </flux:button>
                            <flux:button
                                type="button"
                                variant="ghost"
                                size="sm"
                                wire:click="$set('duplicateAction', 'confirmed')"
                            >
                                {{ __('Create New Anyway') }}
                            </flux:button>
                        </div>
                    </div>
                </flux:callout>
            @endif

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
                    <flux:input wire:model.blur="email" type="email" placeholder="john@example.com" />
                    <flux:error name="email" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Phone') }}</flux:label>
                    <flux:input wire:model="phone" placeholder="(555) 123-4567" />
                    <flux:error name="phone" />
                </flux:field>

                <flux:separator />

                <flux:heading size="sm">{{ __('Project Details') }}</flux:heading>

                <flux:field>
                    <flux:label>{{ __('Estimate Preference') }}</flux:label>
                    <div class="flex gap-4">
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

                <flux:field>
                    <flux:label>{{ __('Project Description') }}</flux:label>
                    <flux:textarea wire:model="projectDescription" rows="3" :placeholder="__('Tell us about their project...')" />
                    <flux:error name="projectDescription" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Lead Source') }}</flux:label>
                    <flux:select wire:model="leadSource">
                        <option value="">{{ __('Select source...') }}</option>
                        @foreach ($this->leadSources as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="leadSource" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Assign To') }}</flux:label>
                    <flux:select wire:model="assignedTo">
                        <option value="">{{ __('Myself') }}</option>
                        @foreach ($this->agents as $member)
                            @if ($member->id !== auth()->id())
                                <option value="{{ $member->id }}">{{ $member->name }}</option>
                            @endif
                        @endforeach
                    </flux:select>
                    <flux:error name="assignedTo" />
                </flux:field>

                <flux:separator />

                <flux:heading size="sm">{{ __('Property Address') }}</flux:heading>
                <flux:text size="sm" class="text-zinc-500">{{ __('If provided, a property will be automatically created.') }}</flux:text>

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

                <flux:separator />

                <flux:heading size="sm">{{ __('Auto-Actions') }}</flux:heading>

                <div class="space-y-3">
                    <flux:checkbox
                        wire:model="createDeal"
                        :label="__('Create lead deal')"
                        :description="__('Automatically create a new deal in the Lead stage')"
                    />

                    <flux:checkbox
                        wire:model="createProperty"
                        :label="__('Create property from address')"
                        :description="__('Only if address and city are provided')"
                        :disabled="!$this->hasAddress"
                    />

                    <flux:checkbox
                        wire:model="sendInvite"
                        :label="__('Send portal invitation')"
                        :description="__('Email customer an invitation to access their portal')"
                    />
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="closeModal" type="button">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button
                    type="submit"
                    variant="primary"
                    :disabled="$showDuplicateWarning && $duplicateAction !== 'confirmed'"
                >
                    {{ __('Create Customer') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</div>
