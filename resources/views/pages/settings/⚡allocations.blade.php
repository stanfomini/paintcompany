<?php

use App\Models\CompanyAllocation;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\Job;
use App\Models\Payment;
use App\Models\Substrate;
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

    // Form state
    public bool $showModal = false;
    public ?int $editingId = null;
    public string $name = '';
    public string $type = 'percent_of_revenue';
    public string $value = '';
    public string $description = '';
    public bool $is_active = true;
    public string $conditions_logic = 'all';

    // Unified rules: each rule is a condition + optional value override
    public array $allocation_rules = [];

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
            'scope_type' => ['label' => 'Scope Type', 'group' => 'Job Details', 'operators' => ['equals', 'not_equals'], 'input' => 'select', 'values' => [Estimate::PROJECT_TYPE_INTERIOR => 'Interior', Estimate::PROJECT_TYPE_EXTERIOR => 'Exterior', Estimate::PROJECT_TYPE_BOTH => 'Both']],
            'has_scope' => ['label' => 'Has Scope', 'group' => 'Job Details', 'operators' => ['equals'], 'input' => 'select', 'values' => ['interior' => 'Interior', 'exterior' => 'Exterior', 'both' => 'Both']],
            'job_price' => ['label' => 'Job Price ($)', 'group' => 'Job Details', 'operators' => ['greater_than', 'less_than', 'equals'], 'input' => 'number', 'values' => []],
            'job_stage' => ['label' => 'Job Stage', 'group' => 'Job Details', 'operators' => ['equals', 'in'], 'input' => 'select', 'values' => Job::STAGES],
            'total_sqft' => ['label' => 'Total Sqft', 'group' => 'Job Details', 'operators' => ['greater_than', 'less_than'], 'input' => 'number', 'values' => []],
            'total_labor_hours' => ['label' => 'Total Labor Hours', 'group' => 'Job Details', 'operators' => ['greater_than', 'less_than'], 'input' => 'number', 'values' => []],
            'area_count' => ['label' => 'Area Count', 'group' => 'Job Details', 'operators' => ['greater_than', 'less_than', 'equals'], 'input' => 'number', 'values' => []],
            'crew_size' => ['label' => 'Crew Size', 'group' => 'Job Details', 'operators' => ['greater_than', 'less_than', 'equals'], 'input' => 'number', 'values' => []],
            'estimator_mode' => ['label' => 'Estimator Mode', 'group' => 'Job Details', 'operators' => ['equals'], 'input' => 'select', 'values' => [Estimate::MODE_SIMPLE => 'Simple', Estimate::MODE_TEMPLATE => 'Template', Estimate::MODE_DETAILED => 'Detailed']],

            // ── Paint & Materials ──
            'uses_premium_paint' => ['label' => 'Uses Premium Paint', 'group' => 'Paint & Materials', 'operators' => ['equals'], 'input' => 'boolean', 'values' => ['1' => 'Yes', '0' => 'No']],
            'max_coats' => ['label' => 'Max Coats', 'group' => 'Paint & Materials', 'operators' => ['greater_than', 'less_than', 'equals'], 'input' => 'number', 'values' => []],
            'total_material_cost' => ['label' => 'Total Material Cost ($)', 'group' => 'Paint & Materials', 'operators' => ['greater_than', 'less_than'], 'input' => 'number', 'values' => []],
            'has_spray_application' => ['label' => 'Has Spray Application', 'group' => 'Paint & Materials', 'operators' => ['equals'], 'input' => 'boolean', 'values' => ['1' => 'Yes', '0' => 'No']],
            'substrate_categories' => ['label' => 'Substrate Category', 'group' => 'Paint & Materials', 'operators' => ['equals', 'in'], 'input' => 'select', 'values' => Substrate::getCategories()],
            'has_complex_substrate' => ['label' => 'Complex Substrate', 'group' => 'Paint & Materials', 'operators' => ['equals'], 'input' => 'boolean', 'values' => ['1' => 'Yes', '0' => 'No']],
            'requires_prime' => ['label' => 'Requires Primer', 'group' => 'Paint & Materials', 'operators' => ['equals'], 'input' => 'boolean', 'values' => ['1' => 'Yes', '0' => 'No']],

            // ── Customer & Sales ──
            'lead_source' => ['label' => 'Lead Source', 'group' => 'Customer & Sales', 'operators' => ['equals', 'in'], 'input' => 'select', 'values' => Deal::LEAD_SOURCES],
            'has_agent_referral' => ['label' => 'Agent Referral', 'group' => 'Customer & Sales', 'operators' => ['equals'], 'input' => 'boolean', 'values' => ['1' => 'Yes', '0' => 'No']],
            'is_repeat_customer' => ['label' => 'Repeat Customer', 'group' => 'Customer & Sales', 'operators' => ['equals'], 'input' => 'boolean', 'values' => ['1' => 'Yes', '0' => 'No']],
            'customer_source' => ['label' => 'Customer Source', 'group' => 'Customer & Sales', 'operators' => ['equals'], 'input' => 'select', 'values' => Customer::SOURCES],

            // ── Payments ──
            'payment_methods' => ['label' => 'Payment Method', 'group' => 'Payments', 'operators' => ['equals', 'in'], 'input' => 'select', 'values' => Payment::METHODS],
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
        $this->conditions_logic = $allocation->conditions_logic ?? 'all';

        // Merge value_tiers + conditions into unified rules list
        $rules = [];

        // Value tiers → rules with override
        foreach ($allocation->value_tiers ?? [] as $tier) {
            $firstCond = $tier['conditions'][0] ?? [];
            $rules[] = [
                'field' => $firstCond['field'] ?? '',
                'operator' => $firstCond['operator'] ?? 'equals',
                'condition_value' => $firstCond['value'] ?? '',
                'has_override' => true,
                'override_type' => $tier['type'] ?? 'percent_of_revenue',
                'override_value' => (string) ($tier['value'] ?? ''),
                'label' => $tier['label'] ?? '',
            ];
        }

        // Gate conditions → rules without override
        foreach ($allocation->conditions ?? [] as $cond) {
            $rules[] = [
                'field' => $cond['field'] ?? '',
                'operator' => $cond['operator'] ?? 'equals',
                'condition_value' => $cond['value'] ?? '',
                'has_override' => false,
                'override_type' => 'percent_of_revenue',
                'override_value' => '',
                'label' => '',
            ];
        }

        $this->allocation_rules = $rules;
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    public function addRule(): void
    {
        $this->allocation_rules[] = [
            'field' => '',
            'operator' => 'equals',
            'condition_value' => '',
            'has_override' => false,
            'override_type' => 'percent_of_revenue',
            'override_value' => '',
            'label' => '',
        ];
    }

    public function removeRule(int $index): void
    {
        unset($this->allocation_rules[$index]);
        $this->allocation_rules = array_values($this->allocation_rules);
    }

    public function updated(string $property, mixed $value): void
    {
        if (preg_match('/^allocation_rules\.(\d+)\.field$/', $property, $matches)) {
            $index = (int) $matches[1];
            if (isset($this->allocation_rules[$index])) {
                $this->allocation_rules[$index]['condition_value'] = '';
                $this->allocation_rules[$index]['operator'] = 'equals';
            }
        }
    }

    public function toggleRuleOverride(int $index): void
    {
        if (isset($this->allocation_rules[$index])) {
            $this->allocation_rules[$index]['has_override'] = !$this->allocation_rules[$index]['has_override'];
        }
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

        if ($this->type === 'percent_of_revenue') {
            $rules['value'][] = 'max:100';
        } elseif ($this->type === 'fixed_per_job') {
            $rules['value'][] = 'max:100000';
        } else {
            $rules['value'][] = 'max:1000';
        }

        $validated = $this->validate($rules);

        $settings = CompanySetting::getInstance();

        // Split unified rules into conditions (gates) and value_tiers (overrides)
        $gateConditions = [];
        $valueTiers = [];

        foreach ($this->allocation_rules as $rule) {
            if (empty($rule['field'])) {
                continue;
            }

            if (!empty($rule['has_override']) && !empty($rule['label'])) {
                $valueTiers[] = [
                    'label' => $rule['label'],
                    'type' => $rule['override_type'],
                    'value' => $rule['override_value'],
                    'conditions' => [
                        ['field' => $rule['field'], 'operator' => $rule['operator'], 'value' => $rule['condition_value']],
                    ],
                ];
            } elseif (empty($rule['has_override'])) {
                $gateConditions[] = [
                    'field' => $rule['field'],
                    'operator' => $rule['operator'],
                    'value' => $rule['condition_value'],
                ];
            }
        }

        $cleanedConditions = !empty($gateConditions) ? $gateConditions : null;
        $cleanedTiers = !empty($valueTiers) ? $valueTiers : null;

        if ($this->editingId) {
            $allocation = CompanyAllocation::findOrFail($this->editingId);

            if ($allocation->is_system) {
                $allocation->update([
                    'value' => $validated['value'],
                    'is_active' => $validated['is_active'],
                    'conditions' => $cleanedConditions,
                    'conditions_logic' => $this->conditions_logic,
                    'value_tiers' => $cleanedTiers,
                ]);
            } else {
                $allocation->update([
                    'name' => $validated['name'],
                    'slug' => Str::slug($validated['name'], '_'),
                    'type' => $validated['type'],
                    'value' => $validated['value'],
                    'description' => $validated['description'] ?: null,
                    'is_active' => $validated['is_active'],
                    'conditions' => $cleanedConditions,
                    'conditions_logic' => $this->conditions_logic,
                    'value_tiers' => $cleanedTiers,
                ]);
            }
        } else {
            $maxOrder = $settings->allocations()->max('sort_order') ?? 0;

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
                'conditions' => $cleanedConditions,
                'conditions_logic' => $this->conditions_logic,
                'value_tiers' => $cleanedTiers,
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
            session()->flash('error', __('System allocations cannot be deleted.'));
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
        $this->conditions_logic = 'all';
        $this->allocation_rules = [];
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Business Allocations') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Business Allocations')" :subheading="__('Configure costs that are deducted from gross profit to calculate net profit')">
        <div class="my-6 w-full space-y-6">

            {{-- Info Callout --}}
            <flux:callout variant="info" icon="information-circle">
                <flux:callout.heading>{{ __('How Allocations Work') }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __('Allocations represent business costs that come out of each job\'s gross profit. They can be a percentage of revenue, a fixed amount per job, or a rate per labor hour. System allocations can be edited but not deleted.') }}
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
                                    <span class="px-1.5 py-0.5 text-xs bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300 rounded">{{ __('System') }}</span>
                                @endif
                                @if ($allocation->is_informational)
                                    <span class="px-1.5 py-0.5 text-xs bg-zinc-100 text-zinc-600 dark:bg-zinc-700 dark:text-zinc-400 rounded">{{ __('Info Only') }}</span>
                                @endif
                                @if (!$allocation->is_active)
                                    <span class="px-1.5 py-0.5 text-xs bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300 rounded">{{ __('Inactive') }}</span>
                                @endif
                                @if ($allocation->hasTiers())
                                    <span class="px-1.5 py-0.5 text-xs bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300 rounded">{{ __('Conditional') }}</span>
                                @endif
                            </div>
                            <div class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">
                                {{ $allocation->formatted_value }}
                                <span class="text-zinc-400 mx-1">|</span>
                                {{ $allocation->type_label }}
                                @if ($allocation->hasTiers())
                                    <span class="text-zinc-400 mx-1">({{ __('fallback') }})</span>
                                @endif
                            </div>
                            @if ($allocation->description)
                                <div class="text-xs text-zinc-400 mt-1">{{ $allocation->description }}</div>
                            @endif
                            @if ($allocation->hasTiers())
                                <div class="text-xs text-purple-600 dark:text-purple-400 mt-1">
                                    {{ $allocation->getTiersSummary() }}
                                </div>
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
                                <flux:button size="sm" variant="ghost" icon="trash" wire:click="delete({{ $allocation->id }})" wire:confirm="{{ __('Are you sure you want to delete this allocation?') }}" />
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
                        {{ __('No allocations configured. Add one to get started.') }}
                    </div>
                @endif
            </div>

            {{-- Add Button --}}
            <flux:button variant="primary" icon="plus" wire:click="openAddModal">
                {{ __('Add Allocation') }}
            </flux:button>

            {{-- Summary --}}
            @php
                $activeAllocations = $this->allocations->where('is_active', true)->where('is_informational', false);
                $totalPercent = $activeAllocations->where('type', 'percent_of_revenue')->sum('value');
                $totalFixed = $activeAllocations->where('type', 'fixed_per_job')->sum('value');
                $totalPerHour = $activeAllocations->where('type', 'per_labor_hour')->sum('value');
            @endphp
            <div class="p-4 bg-zinc-50 dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-700 rounded-lg">
                <div class="text-sm font-medium mb-2">{{ __('Active Allocation Summary') }}</div>
                <div class="grid grid-cols-3 gap-4 text-sm">
                    <div>
                        <div class="text-zinc-500">{{ __('% of Revenue') }}</div>
                        <div class="font-bold">{{ number_format($totalPercent, 2) }}%</div>
                    </div>
                    <div>
                        <div class="text-zinc-500">{{ __('Fixed/Job') }}</div>
                        <div class="font-bold">${{ number_format($totalFixed, 2) }}</div>
                    </div>
                    <div>
                        <div class="text-zinc-500">{{ __('Per Labor Hour') }}</div>
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
    <flux:modal wire:model="showModal" variant="flyout" class="w-[520px]">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingId ? __('Edit Allocation') : __('Add Allocation') }}</flux:heading>
                <flux:text class="mt-2">
                    @if ($editingId)
                        @php $editing = \App\Models\CompanyAllocation::find($editingId); @endphp
                        @if ($editing?->is_system)
                            {{ __('System allocations can only have their value and status edited.') }}
                        @else
                            {{ __('Update the allocation details below.') }}
                        @endif
                    @else
                        {{ __('Configure a new business cost allocation.') }}
                    @endif
                </flux:text>
            </div>

            @php $isSystemEdit = $editingId && \App\Models\CompanyAllocation::find($editingId)?->is_system; @endphp

            <flux:input
                wire:model="name"
                :label="__('Name')"
                :placeholder="__('e.g., Marketing, Growth Loan')"
                :disabled="$isSystemEdit"
                required
            />

            <flux:textarea
                wire:model="description"
                :label="__('Description (optional)')"
                :placeholder="__('What is this allocation for?')"
                rows="2"
                :disabled="$isSystemEdit"
            />

            {{-- Unified Rules Builder --}}
            <div class="space-y-3 border-t border-zinc-200 dark:border-zinc-700 pt-4">
                <div class="flex items-center justify-between">
                    <flux:heading size="sm">{{ __('Rules') }}</flux:heading>
                    @php
                        $gateCount = collect($allocation_rules)->filter(fn ($r) => empty($r['has_override']) && !empty($r['field']))->count();
                    @endphp
                    @if ($gateCount > 1)
                        <div class="flex items-center gap-1.5">
                            <span class="text-xs text-zinc-500">{{ __('Match gates:') }}</span>
                            <flux:select wire:model.live="conditions_logic" class="!w-20">
                                <flux:select.option value="all">AND</flux:select.option>
                                <flux:select.option value="any">OR</flux:select.option>
                            </flux:select>
                        </div>
                    @endif
                </div>

                @if (empty($allocation_rules))
                    <div class="rounded-lg border border-dashed border-zinc-300 dark:border-zinc-600 p-4 text-center">
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('No rules added.') }}</p>
                        <p class="text-xs text-zinc-400 dark:text-zinc-500 mt-1">{{ __('The base value below will apply to all jobs. Add rules to gate this allocation or override the value conditionally.') }}</p>
                    </div>
                @endif

                @php $overrideCount = 0; @endphp
                @foreach ($allocation_rules as $ri => $rule)
                    @php
                        $isOverride = !empty($rule['has_override']);
                        if ($isOverride) { $overrideCount++; }
                        $fieldConfig = (!empty($rule['field']) && isset($this->conditionFields[$rule['field']])) ? $this->conditionFields[$rule['field']] : null;
                        $groups = collect($this->conditionFields)->groupBy('group', preserveKeys: true);
                    @endphp
                    <div wire:key="rule-{{ $ri }}" class="rounded-lg border p-3 space-y-2.5 {{ $isOverride ? 'border-purple-300 dark:border-purple-700 bg-purple-50/50 dark:bg-purple-950/20' : 'border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800/50' }}">
                        {{-- Condition row: label + field + remove --}}
                        <div class="flex items-start gap-2">
                            <span class="shrink-0 mt-2 w-14 text-xs font-bold tracking-wide {{ $isOverride ? 'text-purple-600 dark:text-purple-400' : 'text-zinc-500 dark:text-zinc-400' }}">
                                @if ($isOverride)
                                    {{ $overrideCount === 1 ? __('IF') : __('ELSE IF') }}
                                @else
                                    {{ __('REQUIRE') }}
                                @endif
                            </span>

                            <div class="flex-1 space-y-2">
                                {{-- Field selector (native select for optgroup support) --}}
                                <select
                                    wire:model.live="allocation_rules.{{ $ri }}.field"
                                    class="w-full rounded-lg border-zinc-300 bg-white text-sm py-1.5 focus:border-purple-500 focus:ring-1 focus:ring-purple-500 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200"
                                >
                                    <option value="">{{ __('Select condition field...') }}</option>
                                    @foreach ($groups as $groupName => $fields)
                                        <optgroup label="{{ $groupName }}">
                                            @foreach ($fields as $fieldKey => $fConfig)
                                                <option value="{{ $fieldKey }}">{{ $fConfig['label'] }}</option>
                                            @endforeach
                                        </optgroup>
                                    @endforeach
                                </select>

                                {{-- Operator + value (shown when field is selected) --}}
                                @if ($fieldConfig)
                                    <div class="flex gap-2">
                                        <flux:select wire:model.live="allocation_rules.{{ $ri }}.operator" class="!w-32 shrink-0">
                                            @foreach ($fieldConfig['operators'] as $op)
                                                <flux:select.option value="{{ $op }}">{{ str_replace('_', ' ', ucfirst($op)) }}</flux:select.option>
                                            @endforeach
                                        </flux:select>

                                        @if (($fieldConfig['input'] ?? 'select') === 'boolean')
                                            <flux:select wire:key="cv-{{ $ri }}-{{ $rule['field'] }}-bool" wire:model="allocation_rules.{{ $ri }}.condition_value" class="flex-1">
                                                <flux:select.option value="">{{ __('Select...') }}</flux:select.option>
                                                <flux:select.option value="1">{{ __('Yes') }}</flux:select.option>
                                                <flux:select.option value="0">{{ __('No') }}</flux:select.option>
                                            </flux:select>
                                        @elseif (($fieldConfig['input'] ?? 'select') === 'number')
                                            <flux:input wire:key="cv-{{ $ri }}-{{ $rule['field'] }}-num" wire:model="allocation_rules.{{ $ri }}.condition_value" type="number" step="0.01" :placeholder="__('Value')" class="flex-1" />
                                        @elseif (!empty($fieldConfig['values']))
                                            <flux:select wire:key="cv-{{ $ri }}-{{ $rule['field'] }}-sel" wire:model="allocation_rules.{{ $ri }}.condition_value" class="flex-1">
                                                <flux:select.option value="">{{ __('Select...') }}</flux:select.option>
                                                @foreach ($fieldConfig['values'] as $val => $valLabel)
                                                    <flux:select.option value="{{ $val }}">{{ $valLabel }}</flux:select.option>
                                                @endforeach
                                            </flux:select>
                                        @else
                                            <flux:input wire:key="cv-{{ $ri }}-{{ $rule['field'] }}-txt" wire:model="allocation_rules.{{ $ri }}.condition_value" type="text" :placeholder="__('Value')" class="flex-1" />
                                        @endif
                                    </div>
                                @endif
                            </div>

                            <flux:button variant="ghost" size="sm" icon="x-mark" wire:click="removeRule({{ $ri }})" class="shrink-0 mt-1 opacity-50 hover:opacity-100" />
                        </div>

                        {{-- Override section (THEN) or toggle link --}}
                        @if ($isOverride)
                            <div class="ml-16 border-l-2 border-purple-300 dark:border-purple-600 pl-3 space-y-2">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <button type="button" wire:click="toggleRuleOverride({{ $ri }})" class="text-xs font-bold text-purple-600 dark:text-purple-400 hover:text-purple-800 dark:hover:text-purple-300" title="{{ __('Click to remove value override') }}">
                                        {{ __('THEN') }} &rarr;
                                    </button>
                                    <flux:select wire:model.live="allocation_rules.{{ $ri }}.override_type" size="sm" class="!w-36">
                                        @foreach ($this->types as $typeValue => $typeLabel)
                                            <flux:select.option value="{{ $typeValue }}">{{ $typeLabel }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                    <flux:input wire:model="allocation_rules.{{ $ri }}.override_value" type="number" step="0.01" min="0" placeholder="0.00" size="sm" class="!w-24">
                                        <x-slot name="iconTrailing">
                                            @if (($rule['override_type'] ?? 'percent_of_revenue') === 'percent_of_revenue')
                                                <span class="text-zinc-400 text-xs">%</span>
                                            @elseif (($rule['override_type'] ?? '') === 'per_labor_hour')
                                                <span class="text-zinc-400 text-xs">/hr</span>
                                            @else
                                                <span class="text-zinc-400 text-xs">$</span>
                                            @endif
                                        </x-slot>
                                    </flux:input>
                                </div>
                                <div class="flex items-center gap-2">
                                    <span class="text-xs text-zinc-500 shrink-0">{{ __('Label:') }}</span>
                                    <flux:input wire:model="allocation_rules.{{ $ri }}.label" :placeholder="__('e.g., Stripe, Check, Exterior')" size="sm" class="flex-1" />
                                </div>
                            </div>
                        @else
                            <div class="ml-16">
                                <button type="button" wire:click="toggleRuleOverride({{ $ri }})" class="text-xs text-purple-500 hover:text-purple-700 dark:text-purple-400 dark:hover:text-purple-300">
                                    {{ __('+ Set value override') }}
                                </button>
                            </div>
                        @endif
                    </div>
                @endforeach

                {{-- OTHERWISE (base value) --}}
                <div class="rounded-lg border-2 border-dashed border-zinc-300 dark:border-zinc-600 bg-zinc-50 dark:bg-zinc-800/50 p-3">
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-bold text-zinc-500 dark:text-zinc-400 shrink-0 w-14">{{ __('OTHERWISE') }}</span>
                        <span class="text-zinc-400 shrink-0">&rarr;</span>
                        <flux:select wire:model.live="type" size="sm" class="!w-36" :disabled="$isSystemEdit">
                            @foreach ($this->types as $typeValue => $typeLabel)
                                <flux:select.option value="{{ $typeValue }}">{{ $typeLabel }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:input wire:model="value" type="number" step="0.01" min="0" placeholder="0.00" size="sm" class="!w-24" required>
                            <x-slot name="iconTrailing">
                                @if ($type === 'percent_of_revenue')
                                    <span class="text-zinc-400 text-xs">%</span>
                                @elseif ($type === 'per_labor_hour')
                                    <span class="text-zinc-400 text-xs">/hr</span>
                                @else
                                    <span class="text-zinc-400 text-xs">$</span>
                                @endif
                            </x-slot>
                        </flux:input>
                    </div>
                    <p class="text-xs text-zinc-400 dark:text-zinc-500 mt-1.5 ml-16">
                        @if (collect($allocation_rules)->where('has_override', true)->isNotEmpty())
                            {{ __('Fallback when no override rule matches.') }}
                        @else
                            {{ __('Base value applied to all jobs.') }}
                        @endif
                    </p>
                </div>

                <flux:button variant="ghost" size="sm" icon="plus" wire:click="addRule">
                    {{ __('Add Rule') }}
                </flux:button>
            </div>

            <flux:switch wire:model="is_active" :label="__('Active')" :description="__('Inactive allocations are not deducted from profit')" />

            <div class="flex gap-2">
                <flux:button type="button" variant="ghost" wire:click="closeModal" class="flex-1">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary" class="flex-1">
                    {{ $editingId ? __('Update Allocation') : __('Add Allocation') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
</section>
