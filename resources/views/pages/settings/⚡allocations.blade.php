<?php

use App\Models\CompanyAllocation;
use App\Models\CompanySetting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public function boot(): void
    {
        if (! auth()->user()?->can('settings.allocations')) {
            abort(403);
        }
    }

    // Form state for adding/editing allocations
    public bool $showModal = false;
    public ?int $editingId = null;
    public string $name = '';
    public string $type = 'percent_of_revenue';
    public string $value = '';
    public string $description = '';
    public bool $is_active = true;
    public array $conditions = [];
    public string $conditions_logic = 'all';

    public function mount(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }
    }

    #[Computed]
    public function allocations(): \Illuminate\Database\Eloquent\Collection
    {
        return CompanySetting::getInstance()
            ->allocations()
            ->ordered()
            ->get();
    }

    #[Computed]
    public function types(): array
    {
        return CompanyAllocation::getTypes();
    }

    #[Computed]
    public function conditionFields(): array
    {
        return [
            // ── Job Details ──
            'scope_type' => ['label' => 'Scope Type', 'group' => 'Job Details', 'operators' => ['equals', 'not_equals'], 'input' => 'select', 'values' => ['interior' => 'Interior', 'exterior' => 'Exterior']],
            'has_scope' => ['label' => 'Has Scope', 'group' => 'Job Details', 'operators' => ['equals'], 'input' => 'select', 'values' => ['interior' => 'Interior', 'exterior' => 'Exterior', 'both' => 'Both']],
            'job_price' => ['label' => 'Job Price ($)', 'group' => 'Job Details', 'operators' => ['greater_than', 'less_than', 'equals'], 'input' => 'number', 'values' => []],
            'job_stage' => ['label' => 'Job Stage', 'group' => 'Job Details', 'operators' => ['equals', 'in'], 'input' => 'select', 'values' => ['estimate_accepted' => 'Estimate Accepted', 'scheduling_colors' => 'Scheduling Colors', 'job_prep' => 'Job Prep', 'in_progress' => 'In Progress', 'completed' => 'Completed']],
            'total_sqft' => ['label' => 'Total Sqft', 'group' => 'Job Details', 'operators' => ['greater_than', 'less_than'], 'input' => 'number', 'values' => []],
            'total_labor_hours' => ['label' => 'Total Labor Hours', 'group' => 'Job Details', 'operators' => ['greater_than', 'less_than'], 'input' => 'number', 'values' => []],
            'area_count' => ['label' => 'Area Count', 'group' => 'Job Details', 'operators' => ['greater_than', 'less_than', 'equals'], 'input' => 'number', 'values' => []],
            'crew_size' => ['label' => 'Crew Size', 'group' => 'Job Details', 'operators' => ['greater_than', 'less_than', 'equals'], 'input' => 'number', 'values' => []],
            'estimator_mode' => ['label' => 'Estimator Mode', 'group' => 'Job Details', 'operators' => ['equals'], 'input' => 'select', 'values' => ['simple' => 'Simple', 'template' => 'Template', 'detailed' => 'Detailed']],

            // ── Paint & Materials ──
            'uses_premium_paint' => ['label' => 'Uses Premium Paint', 'group' => 'Paint & Materials', 'operators' => ['equals'], 'input' => 'boolean', 'values' => ['1' => 'Yes', '0' => 'No']],
            'max_coats' => ['label' => 'Max Coats', 'group' => 'Paint & Materials', 'operators' => ['greater_than', 'less_than', 'equals'], 'input' => 'number', 'values' => []],
            'total_material_cost' => ['label' => 'Total Material Cost ($)', 'group' => 'Paint & Materials', 'operators' => ['greater_than', 'less_than'], 'input' => 'number', 'values' => []],
            'has_spray_application' => ['label' => 'Has Spray Application', 'group' => 'Paint & Materials', 'operators' => ['equals'], 'input' => 'boolean', 'values' => ['1' => 'Yes', '0' => 'No']],
            'substrate_categories' => ['label' => 'Substrate Category', 'group' => 'Paint & Materials', 'operators' => ['equals', 'in'], 'input' => 'select', 'values' => ['standard' => 'Standard', 'wood' => 'Wood', 'masonry' => 'Masonry', 'metal' => 'Metal']],
            'has_complex_substrate' => ['label' => 'Complex Substrate', 'group' => 'Paint & Materials', 'operators' => ['equals'], 'input' => 'boolean', 'values' => ['1' => 'Yes', '0' => 'No']],
            'requires_prime' => ['label' => 'Requires Primer', 'group' => 'Paint & Materials', 'operators' => ['equals'], 'input' => 'boolean', 'values' => ['1' => 'Yes', '0' => 'No']],

            // ── Customer & Sales ──
            'lead_source' => ['label' => 'Lead Source', 'group' => 'Customer & Sales', 'operators' => ['equals', 'in'], 'input' => 'select', 'values' => ['referral' => 'Referral', 'google_ads' => 'Google Ads', 'home_advisor' => 'HomeAdvisor', 'thumbtack' => 'Thumbtack', 'yelp' => 'Yelp', 'nextdoor' => 'Nextdoor', 'facebook' => 'Facebook', 'website' => 'Website', 'yard_sign' => 'Yard Sign', 'door_knock' => 'Door Knock', 'repeat' => 'Repeat', 'other' => 'Other']],
            'has_agent_referral' => ['label' => 'Agent Referral', 'group' => 'Customer & Sales', 'operators' => ['equals'], 'input' => 'boolean', 'values' => ['1' => 'Yes', '0' => 'No']],
            'is_repeat_customer' => ['label' => 'Repeat Customer', 'group' => 'Customer & Sales', 'operators' => ['equals'], 'input' => 'boolean', 'values' => ['1' => 'Yes', '0' => 'No']],
            'customer_source' => ['label' => 'Customer Source', 'group' => 'Customer & Sales', 'operators' => ['equals'], 'input' => 'select', 'values' => ['referral' => 'Referral', 'online' => 'Online', 'agent' => 'Agent', 'walk_in' => 'Walk-in', 'other' => 'Other']],

            // ── Payments ──
            'payment_methods' => ['label' => 'Payment Method', 'group' => 'Payments', 'operators' => ['equals', 'in'], 'input' => 'select', 'values' => ['check' => 'Check', 'credit_card' => 'Credit Card', 'cash' => 'Cash', 'ach' => 'ACH', 'stripe' => 'Stripe']],
            'deposit_percent' => ['label' => 'Deposit Percent', 'group' => 'Payments', 'operators' => ['greater_than', 'less_than', 'equals'], 'input' => 'number', 'values' => []],

            // ── Timing ──
            'month' => ['label' => 'Month (1-12)', 'group' => 'Timing', 'operators' => ['equals', 'greater_than', 'less_than'], 'input' => 'number', 'values' => []],
            'season' => ['label' => 'Season', 'group' => 'Timing', 'operators' => ['equals'], 'input' => 'select', 'values' => ['winter' => 'Winter', 'spring' => 'Spring', 'summer' => 'Summer', 'fall' => 'Fall']],

            // ── Complexity ──
            'has_subcontractor' => ['label' => 'Has Subcontractor', 'group' => 'Complexity', 'operators' => ['equals'], 'input' => 'boolean', 'values' => ['1' => 'Yes', '0' => 'No']],
            'has_nested_areas' => ['label' => 'Has Nested Areas', 'group' => 'Complexity', 'operators' => ['equals'], 'input' => 'boolean', 'values' => ['1' => 'Yes', '0' => 'No']],
            'has_options_selected' => ['label' => 'Has Options Selected', 'group' => 'Complexity', 'operators' => ['equals'], 'input' => 'boolean', 'values' => ['1' => 'Yes', '0' => 'No']],
        ];
    }

    public function openAddModal(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function openEditModal(int $id): void
    {
        $allocation = CompanyAllocation::findOrFail($id);

        $this->editingId = $allocation->id;
        $this->name = $allocation->name;
        $this->type = $allocation->type;
        $this->value = (string) $allocation->value;
        $this->description = $allocation->description ?? '';
        $this->is_active = $allocation->is_active;
        $this->conditions = $allocation->conditions ?? [];
        $this->conditions_logic = $allocation->conditions_logic ?? 'all';
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    public function addCondition(): void
    {
        $this->conditions[] = ['field' => '', 'operator' => 'equals', 'value' => ''];
    }

    public function removeCondition(int $index): void
    {
        unset($this->conditions[$index]);
        $this->conditions = array_values($this->conditions);
    }

    public function save(): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $rules = [
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', 'in:percent_of_revenue,fixed_per_job,per_labor_hour'],
            'value' => ['required', 'numeric', 'min:0'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],
        ];

        // Add max value based on type
        if ($this->type === 'percent_of_revenue') {
            $rules['value'][] = 'max:100';
        } elseif ($this->type === 'fixed_per_job') {
            $rules['value'][] = 'max:100000';
        } else {
            $rules['value'][] = 'max:1000';
        }

        $validated = $this->validate($rules);

        $settings = CompanySetting::getInstance();

        if ($this->editingId) {
            // Update existing
            $allocation = CompanyAllocation::findOrFail($this->editingId);

            $cleanedConditions = ! empty($this->conditions)
                ? array_values(array_filter($this->conditions, fn ($c) => ! empty($c['field'])))
                : null;

            // System allocations can only update value, is_active, and conditions
            if ($allocation->is_system) {
                $allocation->update([
                    'value' => $validated['value'],
                    'is_active' => $validated['is_active'],
                    'conditions' => $cleanedConditions ?: null,
                    'conditions_logic' => $this->conditions_logic,
                ]);
            } else {
                $allocation->update([
                    'name' => $validated['name'],
                    'slug' => Str::slug($validated['name'], '_'),
                    'type' => $validated['type'],
                    'value' => $validated['value'],
                    'description' => $validated['description'] ?: null,
                    'is_active' => $validated['is_active'],
                    'conditions' => $cleanedConditions ?: null,
                    'conditions_logic' => $this->conditions_logic,
                ]);
            }
        } else {
            // Create new
            $maxOrder = $settings->allocations()->max('sort_order') ?? 0;

            $newConditions = ! empty($this->conditions)
                ? array_values(array_filter($this->conditions, fn ($c) => ! empty($c['field'])))
                : null;

            $settings->allocations()->create([
                'name' => $validated['name'],
                'slug' => Str::slug($validated['name'], '_'),
                'type' => $validated['type'],
                'value' => $validated['value'],
                'description' => $validated['description'] ?: null,
                'is_active' => $validated['is_active'],
                'is_system' => false,
                'is_informational' => false,
                'sort_order' => $maxOrder + 1,
                'conditions' => $newConditions ?: null,
                'conditions_logic' => $this->conditions_logic,
            ]);
        }

        $this->closeModal();
        unset($this->allocations);
        $this->dispatch('allocations-updated');
    }

    public function toggleActive(int $id): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $allocation = CompanyAllocation::findOrFail($id);
        $allocation->update(['is_active' => !$allocation->is_active]);
        unset($this->allocations);
    }

    public function delete(int $id): void
    {
        if (Auth::user()->type !== 'internal') {
            abort(403);
        }

        $allocation = CompanyAllocation::findOrFail($id);

        if ($allocation->is_system) {
            session()->flash('error', 'System allocations cannot be deleted.');
            return;
        }

        $allocation->delete();
        unset($this->allocations);
        $this->dispatch('allocations-updated');
    }

    protected function resetForm(): void
    {
        $this->editingId = null;
        $this->name = '';
        $this->type = 'percent_of_revenue';
        $this->value = '';
        $this->description = '';
        $this->is_active = true;
        $this->conditions = [];
        $this->conditions_logic = 'all';
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Business Allocations') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Business Allocations')" :subheading="__('Configure costs that are deducted from gross profit to calculate net profit')">
        <div class="my-6 w-full space-y-6">

            {{-- Info Callout --}}
            <flux:callout variant="info" icon="information-circle">
                <flux:callout.heading>How Allocations Work</flux:callout.heading>
                <flux:callout.text>
                    Allocations represent business costs that come out of each job's gross profit.
                    They can be a percentage of revenue, a fixed amount per job, or a rate per labor hour.
                    System allocations can be edited but not deleted.
                </flux:callout.text>
            </flux:callout>

            {{-- Allocations List --}}
            <div class="space-y-3">
                @foreach ($this->allocations as $allocation)
                    <div class="flex items-center justify-between p-4 bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg {{ !$allocation->is_active ? 'opacity-50' : '' }}">
                        <div class="flex-1">
                            <div class="flex items-center gap-2">
                                <span class="font-medium">{{ $allocation->name }}</span>
                                @if ($allocation->is_system)
                                    <span class="px-1.5 py-0.5 text-xs bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300 rounded">System</span>
                                @endif
                                @if ($allocation->is_informational)
                                    <span class="px-1.5 py-0.5 text-xs bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-400 rounded">Info Only</span>
                                @endif
                                @if (!$allocation->is_active)
                                    <span class="px-1.5 py-0.5 text-xs bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300 rounded">Inactive</span>
                                @endif
                            </div>
                            <div class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">
                                {{ $allocation->formatted_value }}
                                <span class="text-zinc-400 mx-1">|</span>
                                {{ $allocation->type_label }}
                            </div>
                            @if ($allocation->description)
                                <div class="text-xs text-zinc-400 mt-1">{{ $allocation->description }}</div>
                            @endif
                            @if ($allocation->hasConditions())
                                <div class="text-xs text-indigo-600 dark:text-indigo-400 mt-1">
                                    {{ $allocation->getConditionsSummary() }}
                                </div>
                            @endif
                        </div>
                        <div class="flex items-center gap-2">
                            <flux:button size="sm" variant="ghost" icon="pencil" wire:click="openEditModal({{ $allocation->id }})" />
                            @if (!$allocation->is_system)
                                <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $allocation->id }})" wire:confirm="Are you sure you want to delete this allocation?" />
                            @endif
                            <flux:switch
                                wire:click="toggleActive({{ $allocation->id }})"
                                :checked="$allocation->is_active"
                                size="sm"
                            />
                        </div>
                    </div>
                @endforeach

                @if ($this->allocations->isEmpty())
                    <div class="text-center py-8 text-zinc-500">
                        No allocations configured. Add one to get started.
                    </div>
                @endif
            </div>

            {{-- Add Button --}}
            <flux:button variant="primary" icon="plus" wire:click="openAddModal">
                Add Allocation
            </flux:button>

            {{-- Summary --}}
            @php
                $activeAllocations = $this->allocations->where('is_active', true)->where('is_informational', false);
                $totalPercent = $activeAllocations->where('type', 'percent_of_revenue')->sum('value');
                $totalFixed = $activeAllocations->where('type', 'fixed_per_job')->sum('value');
                $totalPerHour = $activeAllocations->where('type', 'per_labor_hour')->sum('value');
            @endphp
            <div class="p-4 bg-zinc-50 dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-700 rounded-lg">
                <div class="text-sm font-medium mb-2">Active Allocation Summary</div>
                <div class="grid grid-cols-3 gap-4 text-sm">
                    <div>
                        <div class="text-zinc-500">% of Revenue</div>
                        <div class="font-bold">{{ number_format($totalPercent, 2) }}%</div>
                    </div>
                    <div>
                        <div class="text-zinc-500">Fixed/Job</div>
                        <div class="font-bold">${{ number_format($totalFixed, 2) }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-500">Per Labor Hour</div>
                        <div class="font-bold">${{ number_format($totalPerHour, 2) }}/hr</div>
                    </div>
                </div>
            </div>

            <x-action-message on="allocations-updated">
                {{ __('Saved.') }}
            </x-action-message>
        </div>
    </x-pages::settings.layout>

    {{-- Add/Edit Modal --}}
    <flux:modal wire:model="showModal" variant="flyout" class="w-[400px]">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingId ? 'Edit Allocation' : 'Add Allocation' }}</flux:heading>
                <flux:text class="mt-2">
                    @if ($editingId)
                        @php $editing = \App\Models\CompanyAllocation::find($editingId); @endphp
                        @if ($editing?->is_system)
                            System allocations can only have their value and status edited.
                        @else
                            Update the allocation details below.
                        @endif
                    @else
                        Configure a new business cost allocation.
                    @endif
                </flux:text>
            </div>

            @php $isSystemEdit = $editingId && \App\Models\CompanyAllocation::find($editingId)?->is_system; @endphp

            <flux:input
                wire:model="name"
                label="Name"
                placeholder="e.g., Marketing, Growth Loan"
                :disabled="$isSystemEdit"
                required
            />

            <flux:select wire:model.live="type" label="Type" :disabled="$isSystemEdit">
                @foreach ($this->types as $typeValue => $typeLabel)
                    <flux:select.option value="{{ $typeValue }}">{{ $typeLabel }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:input
                wire:model="value"
                label="Value"
                type="number"
                step="0.01"
                min="0"
                :max="$type === 'percent_of_revenue' ? '100' : ($type === 'fixed_per_job' ? '100000' : '1000')"
                required
            >
                <x-slot name="iconTrailing">
                    @if ($type === 'percent_of_revenue')
                        <span class="text-zinc-400">%</span>
                    @elseif ($type === 'per_labor_hour')
                        <span class="text-zinc-400">/hr</span>
                    @else
                        <span class="text-zinc-400">$</span>
                    @endif
                </x-slot>
            </flux:input>

            <flux:textarea
                wire:model="description"
                label="Description (optional)"
                placeholder="What is this allocation for?"
                rows="2"
                :disabled="$isSystemEdit"
            />

            {{-- Conditions Rule Builder --}}
            <div class="space-y-3 border-t border-zinc-200 dark:border-zinc-700 pt-4">
                <div class="flex items-center justify-between">
                    <flux:heading size="sm">Conditions</flux:heading>
                    @if (count($conditions) > 1)
                        <flux:select wire:model.live="conditions_logic" class="!w-24">
                            <flux:select.option value="all">AND</flux:select.option>
                            <flux:select.option value="any">OR</flux:select.option>
                        </flux:select>
                    @endif
                </div>

                @if (empty($conditions))
                    <p class="text-xs text-zinc-500">Always applies. Add conditions to restrict when this allocation is used.</p>
                @endif

                @foreach ($conditions as $i => $condition)
                    <div wire:key="condition-{{ $i }}" class="flex items-start gap-2 rounded-lg border border-zinc-200 dark:border-zinc-700 p-3">
                        <div class="flex-1 space-y-2">
                            @php
                                $groups = collect($this->conditionFields)->groupBy('group');
                            @endphp
                            <select wire:model.live="conditions.{{ $i }}.field" class="w-full rounded-lg border-zinc-200 dark:border-zinc-700 dark:bg-zinc-800 text-sm">
                                <option value="">Select field...</option>
                                @foreach ($groups as $groupName => $fields)
                                    <optgroup label="{{ $groupName }}">
                                        @foreach ($fields as $fieldKey => $fieldConfig)
                                            <option value="{{ $fieldKey }}">{{ $fieldConfig['label'] }}</option>
                                        @endforeach
                                    </optgroup>
                                @endforeach
                            </select>

                            @if (!empty($conditions[$i]['field']) && isset($this->conditionFields[$conditions[$i]['field']]))
                                @php $fieldConfig = $this->conditionFields[$conditions[$i]['field']]; @endphp
                                <div class="flex gap-2">
                                    <flux:select wire:model.live="conditions.{{ $i }}.operator" class="!w-32">
                                        @foreach ($fieldConfig['operators'] as $op)
                                            <flux:select.option value="{{ $op }}">{{ str_replace('_', ' ', ucfirst($op)) }}</flux:select.option>
                                        @endforeach
                                    </flux:select>

                                    @if (($fieldConfig['input'] ?? 'select') === 'boolean')
                                        <flux:select wire:model="conditions.{{ $i }}.value" class="flex-1">
                                            <flux:select.option value="">Select...</flux:select.option>
                                            <flux:select.option value="1">Yes</flux:select.option>
                                            <flux:select.option value="0">No</flux:select.option>
                                        </flux:select>
                                    @elseif (($fieldConfig['input'] ?? 'select') === 'number')
                                        <flux:input wire:model="conditions.{{ $i }}.value" type="number" step="0.01" placeholder="Value" class="flex-1" />
                                    @elseif (!empty($fieldConfig['values']))
                                        <flux:select wire:model="conditions.{{ $i }}.value" class="flex-1">
                                            <flux:select.option value="">Select...</flux:select.option>
                                            @foreach ($fieldConfig['values'] as $val => $label)
                                                <flux:select.option value="{{ $val }}">{{ $label }}</flux:select.option>
                                            @endforeach
                                        </flux:select>
                                    @else
                                        <flux:input wire:model="conditions.{{ $i }}.value" type="text" placeholder="Value" class="flex-1" />
                                    @endif
                                </div>
                            @endif
                        </div>
                        <flux:button variant="ghost" size="sm" icon="x-mark" wire:click="removeCondition({{ $i }})" class="mt-1 shrink-0" />
                    </div>
                @endforeach

                <flux:button variant="ghost" size="sm" icon="plus" wire:click="addCondition">
                    Add Condition
                </flux:button>
            </div>

            <flux:switch wire:model="is_active" label="Active" description="Inactive allocations are not deducted from profit" />

            <div class="flex gap-2">
                <flux:button type="button" variant="ghost" wire:click="closeModal" class="flex-1">Cancel</flux:button>
                <flux:button type="submit" variant="primary" class="flex-1">
                    {{ $editingId ? 'Update' : 'Add' }} Allocation
                </flux:button>
            </div>
        </form>
    </flux:modal>
</section>
