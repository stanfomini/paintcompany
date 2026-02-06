<?php

use App\Models\CompanyAllocation;
use App\Models\CompanySetting;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    // Form state for adding/editing allocations
    public bool $showModal = false;
    public ?int $editingId = null;
    public string $name = '';
    public string $type = 'percent_of_revenue';
    public string $value = '';
    public string $description = '';
    public bool $is_active = true;

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
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
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

            // System allocations can only update value and is_active
            if ($allocation->is_system) {
                $allocation->update([
                    'value' => $validated['value'],
                    'is_active' => $validated['is_active'],
                ]);
            } else {
                $allocation->update([
                    'name' => $validated['name'],
                    'slug' => Str::slug($validated['name'], '_'),
                    'type' => $validated['type'],
                    'value' => $validated['value'],
                    'description' => $validated['description'] ?: null,
                    'is_active' => $validated['is_active'],
                ]);
            }
        } else {
            // Create new
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
