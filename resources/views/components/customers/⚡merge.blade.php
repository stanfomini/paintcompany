<?php

use App\Models\Customer;
use App\Services\CustomerMergeService;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public Customer $customer;

    public Customer $duplicate;

    public array $fieldSelections = [];

    public bool $showConfirmation = false;

    public function boot(): void
    {
        if (! auth()->user()?->can('people.view')) {
            abort(403);
        }
    }

    public function mount(Customer $customer, Customer $duplicate): void
    {
        foreach ($this->mergeableFields() as $field => $label) {
            $primaryVal = $this->customer->{$field} ?? '';
            $duplicateVal = $this->duplicate->{$field} ?? '';

            if ($primaryVal !== '' && $primaryVal !== null) {
                $this->fieldSelections[$field] = 'primary';
            } elseif ($duplicateVal !== '' && $duplicateVal !== null) {
                $this->fieldSelections[$field] = 'duplicate';
            } else {
                $this->fieldSelections[$field] = 'primary';
            }
        }
    }

    public function mergeableFields(): array
    {
        return [
            'first_name' => 'First Name',
            'last_name' => 'Last Name',
            'email' => 'Email',
            'phone' => 'Phone',
            'address' => 'Address',
            'city' => 'City',
            'state' => 'State',
            'zip' => 'ZIP',
            'notes' => 'Notes',
            'source' => 'Source',
        ];
    }

    #[Computed]
    public function mergeSummary(): array
    {
        return app(CustomerMergeService::class)->getMergeSummary($this->customer, $this->duplicate);
    }

    public function confirmMerge(): void
    {
        $this->showConfirmation = true;
    }

    public function executeMerge(): void
    {
        $resolvedFields = [];

        foreach ($this->mergeableFields() as $field => $label) {
            if (($this->fieldSelections[$field] ?? 'primary') === 'duplicate') {
                $resolvedFields[$field] = $this->duplicate->{$field};
            } else {
                $resolvedFields[$field] = $this->customer->{$field};
            }
        }

        app(CustomerMergeService::class)->merge(
            $this->customer,
            $this->duplicate,
            $resolvedFields,
            auth()->user()
        );

        session()->flash('notify', __('Customers merged successfully.'));

        $this->redirect(route('customers.show', $this->customer), navigate: true);
    }
};
?>

<div class="max-w-4xl mx-auto space-y-6">
    {{-- Header --}}
    <div class="flex items-center gap-3 mb-4">
        <flux:button variant="ghost" :href="route('customers.show', $customer)" icon="arrow-left" wire:navigate size="sm" />
        <flux:heading size="xl">{{ __('Merge Customers') }}</flux:heading>
    </div>

    {{-- Side-by-side table --}}
    <div class="rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800 overflow-hidden">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-900">
                    <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400 w-1/5">{{ __('Field') }}</th>
                    <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400 w-2/5">
                        {{ $customer->full_name }}
                        <flux:badge size="sm" color="green" class="ml-1">{{ __('Primary') }}</flux:badge>
                    </th>
                    <th class="px-4 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400 w-2/5">
                        {{ $duplicate->full_name }}
                        <flux:badge size="sm" color="zinc" class="ml-1">{{ __('Duplicate') }}</flux:badge>
                    </th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach ($this->mergeableFields() as $field => $label)
                    @php
                        $primaryVal = $customer->{$field} ?? '';
                        $duplicateVal = $duplicate->{$field} ?? '';
                        $conflict = $primaryVal !== $duplicateVal && $primaryVal !== '' && $duplicateVal !== '';
                    @endphp
                    <tr class="{{ $conflict ? 'bg-amber-50/50 dark:bg-amber-900/10' : '' }}">
                        <td class="px-4 py-3 font-medium text-zinc-700 dark:text-zinc-300">{{ __($label) }}</td>
                        <td class="px-4 py-3">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" wire:model="fieldSelections.{{ $field }}" value="primary"
                                    class="text-blue-600 focus:ring-blue-500" />
                                <span class="{{ $primaryVal ? 'text-zinc-900 dark:text-white' : 'text-zinc-400 italic' }}">
                                    {{ $primaryVal ?: __('(empty)') }}
                                </span>
                            </label>
                        </td>
                        <td class="px-4 py-3">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="radio" wire:model="fieldSelections.{{ $field }}" value="duplicate"
                                    class="text-blue-600 focus:ring-blue-500" />
                                <span class="{{ $duplicateVal ? 'text-zinc-900 dark:text-white' : 'text-zinc-400 italic' }}">
                                    {{ $duplicateVal ?: __('(empty)') }}
                                </span>
                            </label>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    {{-- Merge summary --}}
    <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
        <flux:heading size="sm" class="mb-2">{{ __('Records to reassign') }}</flux:heading>
        <div class="flex gap-6 text-sm text-zinc-600 dark:text-zinc-400">
            <span>{{ trans_choice(':count deal|:count deals', $this->mergeSummary['deals'], ['count' => $this->mergeSummary['deals']]) }}</span>
            <span>{{ trans_choice(':count property|:count properties', $this->mergeSummary['properties'], ['count' => $this->mergeSummary['properties']]) }}</span>
            <span>{{ trans_choice(':count conversation|:count conversations', $this->mergeSummary['conversations'], ['count' => $this->mergeSummary['conversations']]) }}</span>
            <span>{{ trans_choice(':count activity|:count activities', $this->mergeSummary['activities'], ['count' => $this->mergeSummary['activities']]) }}</span>
        </div>
        <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1">
            {{ __('These records will be moved from the duplicate to the primary customer. The duplicate will be soft-deleted.') }}
        </p>
    </div>

    {{-- Action buttons --}}
    <div class="flex justify-end gap-3">
        <flux:button variant="ghost" :href="route('customers.show', $customer)" wire:navigate>
            {{ __('Cancel') }}
        </flux:button>

        @if (! $showConfirmation)
            <flux:button variant="danger" wire:click="confirmMerge">
                {{ __('Merge Customers') }}
            </flux:button>
        @else
            <div class="flex items-center gap-3 rounded-lg border border-red-300 bg-red-50 px-4 py-2 dark:border-red-700 dark:bg-red-900/20">
                <span class="text-sm text-red-700 dark:text-red-300">{{ __('This cannot be undone. Confirm?') }}</span>
                <flux:button variant="danger" wire:click="executeMerge">
                    {{ __('Yes, Merge') }}
                </flux:button>
                <flux:button variant="ghost" wire:click="$set('showConfirmation', false)" size="sm">
                    {{ __('Cancel') }}
                </flux:button>
            </div>
        @endif
    </div>
</div>
