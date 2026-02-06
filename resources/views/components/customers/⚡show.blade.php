<?php

use App\Events\NewLeadCreated;
use App\Models\Activity;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Property;
use App\Services\InvitationService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public function boot(): void
    {
        if (! auth()->user()?->can('people.view')) {
            abort(403);
        }
    }

    public Customer $customer;

    // Quick add property form
    public bool $showAddProperty = false;

    public string $propertyAddress = '';

    public string $propertyCity = '';

    public string $propertyState = '';

    public string $propertyZip = '';

    public string $propertyType = 'residential';

    // Quick add deal form
    public bool $showAddDeal = false;

    public string $dealTitle = '';

    public ?int $dealPropertyId = null;

    // Quick add activity form
    public bool $showAddActivity = false;

    public string $activityTitle = '';

    public string $activityType = 'task';

    public ?string $activityDueAt = null;

    public string $activityDescription = '';

    public function mount(Customer $customer): void
    {
        $this->customer = $customer->load([
            'createdBy',
            'referredBy',
            'properties',
            'deals.property',
            'deals.estimates',
            'activities.user',
        ]);
    }

    #[Computed]
    public function recentActivities()
    {
        return $this->customer->activities()
            ->with('user')
            ->latest()
            ->limit(10)
            ->get();
    }

    #[Computed]
    public function estimates()
    {
        return $this->customer->estimates()
            ->with('deal')
            ->latest()
            ->get();
    }

    #[Computed]
    public function activityTypes(): array
    {
        return [
            'task' => 'Task',
            'note' => 'Note',
            'call' => 'Phone Call',
            'email' => 'Email',
            'meeting' => 'Meeting',
            'other' => 'Other',
        ];
    }

    #[On('customer-updated')]
    #[On('property-created')]
    #[On('deal-created')]
    #[On('activity-created')]
    public function refreshCustomer(): void
    {
        $this->customer = $this->customer->fresh([
            'createdBy',
            'referredBy',
            'properties',
            'deals.property',
            'deals.estimates',
            'activities.user',
        ]);
    }

    public function toggleStatus(): void
    {
        $this->customer->update([
            'status' => $this->customer->status === 'active' ? 'inactive' : 'active',
        ]);
        $this->refreshCustomer();
        session()->flash('success', 'Customer status updated.');
    }

    public function sendPortalInvite(): void
    {
        try {
            $invitationService = app(InvitationService::class);
            $invitationService->sendInvitation(
                inviter: auth()->user(),
                email: $this->customer->email,
                invitedUserType: 'customer'
            );
            session()->flash('success', 'Portal invitation sent to '.$this->customer->email);
        } catch (\Exception $e) {
            session()->flash('error', $e->getMessage());
        }
    }

    // Quick Add Property
    public function openAddProperty(): void
    {
        $this->showAddProperty = true;
    }

    public function closeAddProperty(): void
    {
        $this->showAddProperty = false;
        $this->reset(['propertyAddress', 'propertyCity', 'propertyState', 'propertyZip', 'propertyType']);
        $this->resetValidation();
    }

    public function saveProperty(): void
    {
        $this->validate([
            'propertyAddress' => ['required', 'string', 'max:255'],
            'propertyCity' => ['required', 'string', 'max:100'],
            'propertyState' => ['nullable', 'string', 'max:50'],
            'propertyZip' => ['nullable', 'string', 'max:20'],
            'propertyType' => ['required', 'in:residential,commercial,industrial'],
        ]);

        Property::create([
            'customer_id' => $this->customer->id,
            'address' => $this->propertyAddress,
            'city' => $this->propertyCity,
            'state' => $this->propertyState ?: '',
            'zip' => $this->propertyZip ?: '',
            'type' => $this->propertyType,
        ]);

        $this->dispatch('property-created');
        session()->flash('success', 'Property added successfully.');
        $this->closeAddProperty();
    }

    // Quick Add Deal
    public function openAddDeal(): void
    {
        $this->dealTitle = $this->customer->full_name.' - New Project';
        $this->showAddDeal = true;
    }

    public function closeAddDeal(): void
    {
        $this->showAddDeal = false;
        $this->reset(['dealTitle', 'dealPropertyId']);
        $this->resetValidation();
    }

    public function saveDeal(): void
    {
        $this->validate([
            'dealTitle' => ['required', 'string', 'max:255'],
            'dealPropertyId' => ['nullable', 'exists:properties,id'],
        ]);

        $deal = Deal::create([
            'customer_id' => $this->customer->id,
            'property_id' => $this->dealPropertyId,
            'created_by' => auth()->id(),
            'assigned_to' => auth()->id(),
            'title' => $this->dealTitle,
            'stage' => 'lead',
            'sort_order' => Deal::where('stage', 'lead')->max('sort_order') + 1,
        ]);

        NewLeadCreated::dispatch($deal);

        $this->dispatch('deal-created');
        session()->flash('success', 'Deal created successfully.');
        $this->closeAddDeal();
    }

    // Quick Add Activity
    public function openAddActivity(): void
    {
        $this->showAddActivity = true;
    }

    public function closeAddActivity(): void
    {
        $this->showAddActivity = false;
        $this->reset(['activityTitle', 'activityType', 'activityDueAt', 'activityDescription']);
        $this->activityType = 'task';
        $this->resetValidation();
    }

    public function saveActivity(): void
    {
        $this->validate([
            'activityTitle' => ['required', 'string', 'max:255'],
            'activityType' => ['required', 'in:task,note,call,email,meeting,other'],
            'activityDueAt' => ['nullable', 'date'],
            'activityDescription' => ['nullable', 'string', 'max:5000'],
        ]);

        Activity::create([
            'user_id' => auth()->id(),
            'assigned_to' => auth()->id(),
            'subject_type' => Customer::class,
            'subject_id' => $this->customer->id,
            'type' => $this->activityType,
            'title' => $this->activityTitle,
            'description' => $this->activityDescription ?: null,
            'due_at' => $this->activityDueAt ?: null,
            'is_completed' => false,
        ]);

        $this->dispatch('activity-created');
        session()->flash('success', 'Activity added successfully.');
        $this->closeAddActivity();
    }

    public function toggleActivityComplete(int $activityId): void
    {
        $activity = Activity::findOrFail($activityId);

        if ($activity->is_completed) {
            $activity->markAsIncomplete();
        } else {
            $activity->markAsCompleted();
        }

        $this->refreshCustomer();
    }
}; ?>

<div>
    <div class="max-w-6xl mx-auto">
        {{-- Header --}}
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-4">
                <flux:button variant="ghost" :href="route('customers.index')" icon="arrow-left" wire:navigate>
                    Back
                </flux:button>
                <div>
                    <flux:heading size="xl">{{ $customer->full_name }}</flux:heading>
                    <div class="flex items-center gap-2 mt-1">
                        <flux:text size="sm" class="text-zinc-500">{{ $customer->email }}</flux:text>
                        @if ($customer->phone)
                            <span class="text-zinc-300">|</span>
                            <flux:text size="sm" class="text-zinc-500">{{ $customer->phone }}</flux:text>
                        @endif
                    </div>
                </div>
            </div>
            <div class="flex items-center gap-2">
                @if ($customer->status === 'active')
                    <flux:badge size="sm" color="green">Active</flux:badge>
                @else
                    <flux:badge size="sm" color="yellow">Inactive</flux:badge>
                @endif
                @livewire('customers.edit-modal', ['customerId' => $customer->id])
            </div>
        </div>

        @if (session('success'))
            <flux:callout variant="success" class="mb-4">
                {{ session('success') }}
            </flux:callout>
        @endif

        @if (session('error'))
            <flux:callout variant="danger" class="mb-4">
                {{ session('error') }}
            </flux:callout>
        @endif

        {{-- Quick Actions --}}
        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 mb-6 bg-zinc-50 dark:bg-zinc-900/50">
            <div class="flex flex-wrap items-center gap-2">
                <flux:text size="sm" class="font-medium text-zinc-600 dark:text-zinc-400 mr-2">Quick Actions:</flux:text>
                <flux:button size="sm" icon="plus" wire:click="openAddDeal">
                    New Deal
                </flux:button>
                <flux:button size="sm" icon="home" wire:click="openAddProperty">
                    Add Property
                </flux:button>
                <flux:button size="sm" icon="check-circle" wire:click="openAddActivity">
                    Add Activity
                </flux:button>
                @if (!$customer->hasPortalAccess())
                    <flux:button size="sm" icon="envelope" wire:click="sendPortalInvite" wire:confirm="Send portal invitation to {{ $customer->email }}?">
                        Send Portal Invite
                    </flux:button>
                @endif
                <flux:button size="sm" variant="ghost" wire:click="toggleStatus" wire:confirm="Change customer status to {{ $customer->status === 'active' ? 'inactive' : 'active' }}?">
                    {{ $customer->status === 'active' ? 'Deactivate' : 'Reactivate' }}
                </flux:button>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-3">
            {{-- Left Column: Properties & Contact Info --}}
            <div class="space-y-6">
                {{-- Contact Information --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                    <flux:heading size="sm" class="mb-4">Contact Information</flux:heading>
                    <dl class="space-y-3">
                        <div>
                            <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Email</dt>
                            <dd><flux:text>{{ $customer->email }}</flux:text></dd>
                        </div>
                        @if ($customer->phone)
                            <div>
                                <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Phone</dt>
                                <dd><flux:text>{{ $customer->phone }}</flux:text></dd>
                            </div>
                        @endif
                        @if ($customer->full_address)
                            <div>
                                <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Address</dt>
                                <dd><flux:text>{{ $customer->full_address }}</flux:text></dd>
                            </div>
                        @endif
                        <div>
                            <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Portal Access</dt>
                            <dd>
                                @if ($customer->hasPortalAccess())
                                    <flux:badge size="sm" color="green">Enabled</flux:badge>
                                @else
                                    <flux:badge size="sm" color="zinc">Not Enabled</flux:badge>
                                @endif
                            </dd>
                        </div>
                        @if ($customer->createdBy)
                            <div>
                                <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Created By</dt>
                                <dd><flux:text>{{ $customer->createdBy->name }}</flux:text></dd>
                            </div>
                        @endif
                        @if ($customer->referredBy)
                            <div>
                                <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Referred By</dt>
                                <dd><flux:text>{{ $customer->referredBy->name }}</flux:text></dd>
                            </div>
                        @endif
                    </dl>
                </div>

                {{-- Properties --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                    <div class="flex items-center justify-between mb-4">
                        <flux:heading size="sm">Properties ({{ $customer->properties->count() }})</flux:heading>
                        <flux:button size="xs" icon="plus" wire:click="openAddProperty">Add</flux:button>
                    </div>
                    @if ($customer->properties->count() > 0)
                        <div class="space-y-2">
                            @foreach ($customer->properties as $property)
                                <a href="{{ route('properties.show', $property) }}" wire:navigate class="block p-2 -mx-2 rounded hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors">
                                    <div class="flex items-start justify-between">
                                        <div>
                                            <flux:text class="font-medium">{{ $property->address }}</flux:text>
                                            <flux:text size="sm" class="text-zinc-500">{{ $property->city }}, {{ $property->state }}</flux:text>
                                        </div>
                                        <flux:badge size="sm" color="zinc">{{ ucfirst($property->type) }}</flux:badge>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    @else
                        <flux:text size="sm" class="text-zinc-500">No properties yet.</flux:text>
                    @endif
                </div>

                {{-- Notes --}}
                @if ($customer->notes)
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                        <flux:heading size="sm" class="mb-2">Notes</flux:heading>
                        <flux:text class="whitespace-pre-wrap">{{ $customer->notes }}</flux:text>
                    </div>
                @endif
            </div>

            {{-- Middle Column: Deals & Estimates --}}
            <div class="space-y-6">
                {{-- Deals --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                    <div class="flex items-center justify-between mb-4">
                        <flux:heading size="sm">Deals ({{ $customer->deals->count() }})</flux:heading>
                        <flux:button size="xs" icon="plus" wire:click="openAddDeal">Add</flux:button>
                    </div>
                    @if ($customer->deals->count() > 0)
                        <div class="space-y-2">
                            @foreach ($customer->deals as $deal)
                                <a href="{{ route('deals.show', $deal) }}" wire:navigate class="block p-2 -mx-2 rounded hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors">
                                    <div class="flex items-start justify-between">
                                        <div>
                                            <flux:text class="font-medium">{{ $deal->title }}</flux:text>
                                            @if ($deal->property)
                                                <flux:text size="sm" class="text-zinc-500">{{ $deal->property->address }}</flux:text>
                                            @endif
                                        </div>
                                        <div class="text-right">
                                            @php
                                                $stageColors = [
                                                    'lead' => 'blue',
                                                    'quoted' => 'yellow',
                                                    'scheduled' => 'purple',
                                                    'in_progress' => 'orange',
                                                    'completed' => 'green',
                                                    'paid' => 'green',
                                                    'cancelled' => 'red',
                                                ];
                                            @endphp
                                            <flux:badge size="sm" :color="$stageColors[$deal->stage] ?? 'zinc'">
                                                {{ $deal->stage_label }}
                                            </flux:badge>
                                            @if ($deal->estimated_value)
                                                <flux:text size="sm" class="text-zinc-500 mt-1">
                                                    ${{ number_format($deal->estimated_value, 0) }}
                                                </flux:text>
                                            @endif
                                        </div>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    @else
                        <flux:text size="sm" class="text-zinc-500">No deals yet.</flux:text>
                    @endif
                </div>

                {{-- Estimates --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                    <flux:heading size="sm" class="mb-4">Estimates ({{ $this->estimates->count() }})</flux:heading>
                    @if ($this->estimates->count() > 0)
                        <div class="space-y-2">
                            @foreach ($this->estimates as $estimate)
                                <a href="{{ route('estimates.show', $estimate) }}" wire:navigate class="block p-2 -mx-2 rounded hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors">
                                    <div class="flex items-start justify-between">
                                        <div>
                                            <flux:text class="font-medium">{{ $estimate->estimate_number }}</flux:text>
                                            <flux:text size="sm" class="text-zinc-500">{{ $estimate->title ?? $estimate->deal->title }}</flux:text>
                                        </div>
                                        <div class="text-right">
                                            @php
                                                $statusColors = [
                                                    'draft' => 'zinc',
                                                    'sent' => 'blue',
                                                    'accepted' => 'green',
                                                    'rejected' => 'red',
                                                ];
                                            @endphp
                                            <flux:badge size="sm" :color="$statusColors[$estimate->status] ?? 'zinc'">
                                                {{ ucfirst($estimate->status) }}
                                            </flux:badge>
                                            <flux:text size="sm" class="text-zinc-500 mt-1">
                                                ${{ number_format($estimate->total, 0) }}
                                            </flux:text>
                                        </div>
                                    </div>
                                </a>
                            @endforeach
                        </div>
                    @else
                        <flux:text size="sm" class="text-zinc-500">No estimates yet.</flux:text>
                    @endif
                </div>
            </div>

            {{-- Right Column: Activity Timeline --}}
            <div class="space-y-6">
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                    <div class="flex items-center justify-between mb-4">
                        <flux:heading size="sm">Activity</flux:heading>
                        <flux:button size="xs" icon="plus" wire:click="openAddActivity">Add</flux:button>
                    </div>
                    @if ($this->recentActivities->count() > 0)
                        <div class="space-y-3">
                            @foreach ($this->recentActivities as $activity)
                                <div class="flex gap-3 {{ $activity->is_completed ? 'opacity-60' : '' }}">
                                    <div class="shrink-0 mt-0.5">
                                        @if ($activity->type === 'task')
                                            <button
                                                wire:click="toggleActivityComplete({{ $activity->id }})"
                                                class="w-5 h-5 rounded border-2 flex items-center justify-center {{ $activity->is_completed ? 'bg-green-500 border-green-500 text-white' : 'border-zinc-300 dark:border-zinc-600 hover:border-green-500' }}"
                                            >
                                                @if ($activity->is_completed)
                                                    <flux:icon name="check" class="w-3 h-3" />
                                                @endif
                                            </button>
                                        @else
                                            @php
                                                $typeIcons = [
                                                    'note' => 'document-text',
                                                    'call' => 'phone',
                                                    'email' => 'envelope',
                                                    'meeting' => 'calendar',
                                                    'other' => 'ellipsis-horizontal',
                                                ];
                                            @endphp
                                            <div class="w-5 h-5 rounded-full bg-zinc-200 dark:bg-zinc-700 flex items-center justify-center">
                                                <flux:icon :name="$typeIcons[$activity->type] ?? 'chat-bubble-left'" class="w-3 h-3 text-zinc-500" />
                                            </div>
                                        @endif
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <flux:text class="{{ $activity->is_completed ? 'line-through' : '' }} {{ $activity->isOverdue() ? 'text-red-600' : '' }}">
                                            {{ $activity->title }}
                                        </flux:text>
                                        <div class="flex items-center gap-2 mt-0.5">
                                            <flux:text size="xs" class="text-zinc-500">
                                                {{ $activity->type_label }}
                                            </flux:text>
                                            @if ($activity->due_at)
                                                <flux:text size="xs" class="{{ $activity->isOverdue() ? 'text-red-500' : 'text-zinc-500' }}">
                                                    Due {{ $activity->due_at->diffForHumans() }}
                                                </flux:text>
                                            @endif
                                            <flux:text size="xs" class="text-zinc-400">
                                                {{ $activity->user?->name }}
                                            </flux:text>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <flux:text size="sm" class="text-zinc-500">No activities yet.</flux:text>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- Add Property Modal --}}
    <flux:modal wire:model="showAddProperty" variant="flyout" class="max-w-md">
        <form wire:submit="saveProperty">
            <flux:heading size="lg" class="mb-4">Add Property</flux:heading>

            <div class="space-y-4">
                <flux:field>
                    <flux:label>Address</flux:label>
                    <flux:input wire:model="propertyAddress" placeholder="123 Main St" />
                    <flux:error name="propertyAddress" />
                </flux:field>

                <div class="grid gap-4 sm:grid-cols-3">
                    <flux:field>
                        <flux:label>City</flux:label>
                        <flux:input wire:model="propertyCity" placeholder="Phoenix" />
                        <flux:error name="propertyCity" />
                    </flux:field>

                    <flux:field>
                        <flux:label>State</flux:label>
                        <flux:input wire:model="propertyState" placeholder="AZ" />
                        <flux:error name="propertyState" />
                    </flux:field>

                    <flux:field>
                        <flux:label>ZIP</flux:label>
                        <flux:input wire:model="propertyZip" placeholder="85001" />
                        <flux:error name="propertyZip" />
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>Type</flux:label>
                    <flux:select wire:model="propertyType">
                        <option value="residential">Residential</option>
                        <option value="commercial">Commercial</option>
                        <option value="industrial">Industrial</option>
                    </flux:select>
                    <flux:error name="propertyType" />
                </flux:field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="closeAddProperty" type="button">Cancel</flux:button>
                <flux:button type="submit" variant="primary">Add Property</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Add Deal Modal --}}
    <flux:modal wire:model="showAddDeal" variant="flyout" class="max-w-md">
        <form wire:submit="saveDeal">
            <flux:heading size="lg" class="mb-4">New Deal</flux:heading>

            <div class="space-y-4">
                <flux:field>
                    <flux:label>Title</flux:label>
                    <flux:input wire:model="dealTitle" placeholder="Project name" />
                    <flux:error name="dealTitle" />
                </flux:field>

                @if ($customer->properties->count() > 0)
                    <flux:field>
                        <flux:label>Property (Optional)</flux:label>
                        <flux:select wire:model="dealPropertyId">
                            <option value="">Select a property...</option>
                            @foreach ($customer->properties as $property)
                                <option value="{{ $property->id }}">{{ $property->address }}, {{ $property->city }}</option>
                            @endforeach
                        </flux:select>
                        <flux:error name="dealPropertyId" />
                    </flux:field>
                @endif
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="closeAddDeal" type="button">Cancel</flux:button>
                <flux:button type="submit" variant="primary">Create Deal</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Add Activity Modal --}}
    <flux:modal wire:model="showAddActivity" variant="flyout" class="max-w-md">
        <form wire:submit="saveActivity">
            <flux:heading size="lg" class="mb-4">Add Activity</flux:heading>

            <div class="space-y-4">
                <flux:field>
                    <flux:label>Title</flux:label>
                    <flux:input wire:model="activityTitle" placeholder="Follow up call" />
                    <flux:error name="activityTitle" />
                </flux:field>

                <flux:field>
                    <flux:label>Type</flux:label>
                    <flux:select wire:model="activityType">
                        @foreach ($this->activityTypes as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="activityType" />
                </flux:field>

                <flux:field>
                    <flux:label>Due Date (Optional)</flux:label>
                    <flux:input wire:model="activityDueAt" type="date" />
                    <flux:error name="activityDueAt" />
                </flux:field>

                <flux:field>
                    <flux:label>Description (Optional)</flux:label>
                    <flux:textarea wire:model="activityDescription" rows="3" placeholder="Add notes..." />
                    <flux:error name="activityDescription" />
                </flux:field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="closeAddActivity" type="button">Cancel</flux:button>
                <flux:button type="submit" variant="primary">Add Activity</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
