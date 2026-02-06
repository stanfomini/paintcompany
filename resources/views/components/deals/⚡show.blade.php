<?php

use App\Models\Deal;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public function boot(): void
    {
        if (! auth()->user()?->can('deals.view')) {
            abort(403);
        }
    }

    public Deal $deal;

    public function mount(Deal $deal): void
    {
        $this->deal = $deal->load([
            'customer',
            'property',
            'assignedTo',
            'createdBy',
            'notes.user',
            'estimates',
        ]);
    }

    #[On('deal-updated')]
    #[On('estimate-created')]
    public function refreshDeal(): void
    {
        $this->deal = $this->deal->fresh([
            'customer',
            'property',
            'assignedTo',
            'createdBy',
            'notes.user',
            'estimates',
        ]);
    }

    #[On('note-created')]
    #[On('note-updated')]
    #[On('note-deleted')]
    public function refreshNotes(): void
    {
        $this->deal = $this->deal->fresh(['notes.user']);
    }

    public function getStageColorProperty(): string
    {
        return match ($this->deal->stage) {
            'lead' => 'zinc',
            'quoted' => 'blue',
            'scheduled' => 'purple',
            'in_progress' => 'yellow',
            'completed' => 'green',
            'paid' => 'emerald',
            'signed_accepted' => 'teal',
            'cancelled' => 'red',
            default => 'zinc',
        };
    }
}; ?>

<div>
    <div class="max-w-4xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-4">
                <flux:button variant="ghost" :href="route('deals.index')" icon="arrow-left" wire:navigate>
                    Back
                </flux:button>
                <flux:heading size="xl">{{ $deal->title }}</flux:heading>
                <flux:badge size="sm" color="{{ $this->stageColor }}">{{ $deal->stage_label }}</flux:badge>
            </div>
            @livewire('deals.edit-modal', ['dealId' => $deal->id])
        </div>

        @if (session('success'))
            <flux:callout variant="success" class="mb-4">
                {{ session('success') }}
            </flux:callout>
        @endif

        <div class="grid gap-6 md:grid-cols-2">
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                <flux:heading size="sm" class="mb-4">Deal Information</flux:heading>
                <dl class="space-y-3">
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Customer</dt>
                        <dd class="mt-1">
                            <a href="{{ route('customers.show', $deal->customer) }}" class="text-blue-600 hover:underline" wire:navigate>
                                {{ $deal->customer->full_name }}
                            </a>
                        </dd>
                    </div>
                    @if ($deal->property)
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Property</dt>
                            <dd class="mt-1">
                                <a href="{{ route('properties.show', $deal->property) }}" class="text-blue-600 hover:underline" wire:navigate>
                                    {{ $deal->property->display_name }}
                                </a>
                            </dd>
                        </div>
                    @endif
                    @if ($deal->description)
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Description</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $deal->description }}</flux:text>
                            </dd>
                        </div>
                    @endif
                </dl>
            </div>

            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                <flux:heading size="sm" class="mb-4">Financial Details</flux:heading>
                <dl class="space-y-3">
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Estimated Value</dt>
                        <dd class="mt-1">
                            <flux:text>{{ $deal->estimated_value ? '$' . number_format($deal->estimated_value, 2) : '-' }}</flux:text>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Actual Value</dt>
                        <dd class="mt-1">
                            <flux:text>{{ $deal->actual_value ? '$' . number_format($deal->actual_value, 2) : '-' }}</flux:text>
                        </dd>
                    </div>
                </dl>
            </div>

            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                <flux:heading size="sm" class="mb-4">Timeline</flux:heading>
                <dl class="space-y-3">
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Expected Start</dt>
                        <dd class="mt-1">
                            <flux:text>{{ $deal->expected_start_date?->format('M j, Y') ?? '-' }}</flux:text>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Expected End</dt>
                        <dd class="mt-1">
                            <flux:text>{{ $deal->expected_end_date?->format('M j, Y') ?? '-' }}</flux:text>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Actual Start</dt>
                        <dd class="mt-1">
                            <flux:text>{{ $deal->actual_start_date?->format('M j, Y') ?? '-' }}</flux:text>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Actual End</dt>
                        <dd class="mt-1">
                            <flux:text>{{ $deal->actual_end_date?->format('M j, Y') ?? '-' }}</flux:text>
                        </dd>
                    </div>
                </dl>
            </div>

            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                <flux:heading size="sm" class="mb-4">Assignment & Tracking</flux:heading>
                <dl class="space-y-3">
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Assigned To</dt>
                        <dd class="mt-1">
                            <flux:text>{{ $deal->assignedTo?->name ?? 'Unassigned' }}</flux:text>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Created By</dt>
                        <dd class="mt-1">
                            <flux:text>{{ $deal->createdBy?->name ?? '-' }}</flux:text>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Created</dt>
                        <dd class="mt-1">
                            <flux:text>{{ $deal->created_at->format('M j, Y g:i A') }}</flux:text>
                        </dd>
                    </div>
                </dl>
            </div>
        </div>

        {{-- Activity Timeline Placeholder --}}
        <div class="mt-6 border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
            <flux:heading size="sm" class="mb-4">Activity Timeline</flux:heading>
            <flux:text class="text-zinc-500">Activity timeline coming soon...</flux:text>
        </div>

        {{-- Estimates Section --}}
        <div class="mt-6 border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
            <div class="flex items-center justify-between mb-4">
                <flux:heading size="sm">Estimates</flux:heading>
                <flux:button :href="route('estimates.create', $deal)" icon="plus" size="sm" wire:navigate>
                    Create Estimate
                </flux:button>
            </div>

            @if ($deal->estimates->count() > 0)
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                        <thead>
                            <tr>
                                <th scope="col" class="py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    Number
                                </th>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    Title
                                </th>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    Mode
                                </th>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    Status
                                </th>
                                <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    Total
                                </th>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    Created
                                </th>
                                <th scope="col" class="py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    Actions
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($deal->estimates->sortByDesc('created_at') as $estimate)
                                <tr wire:key="estimate-{{ $estimate->id }}">
                                    <td class="py-4 whitespace-nowrap">
                                        <a href="{{ route('estimates.show', $estimate) }}" class="text-blue-600 hover:underline font-medium" wire:navigate>
                                            {{ $estimate->estimate_number }}
                                        </a>
                                    </td>
                                    <td class="px-4 py-4">
                                        <flux:text>{{ $estimate->title }}</flux:text>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap">
                                        @php
                                            $modeColor = match($estimate->estimator_mode) {
                                                'simple' => 'emerald',
                                                'template' => 'blue',
                                                'detailed' => 'purple',
                                                default => 'zinc',
                                            };
                                            $modeLabel = match($estimate->estimator_mode) {
                                                'simple' => 'Quick',
                                                'template' => 'Template',
                                                'detailed' => 'Detailed',
                                                default => 'Standard',
                                            };
                                        @endphp
                                        <flux:badge size="sm" color="{{ $modeColor }}">{{ $modeLabel }}</flux:badge>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap">
                                        @php
                                            $statusColor = match ($estimate->status) {
                                                'draft' => 'zinc',
                                                'sent' => 'blue',
                                                'accepted' => 'green',
                                                'rejected' => 'red',
                                                'expired' => 'yellow',
                                                default => 'zinc',
                                            };
                                        @endphp
                                        <flux:badge size="sm" color="{{ $statusColor }}">{{ $estimate->status_label }}</flux:badge>
                                    </td>
                                    <td class="px-4 py-4 text-right whitespace-nowrap">
                                        <flux:text class="font-medium">${{ number_format($estimate->total, 2) }}</flux:text>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap">
                                        <flux:text class="text-sm text-zinc-500">{{ $estimate->created_at->format('M j, Y') }}</flux:text>
                                    </td>
                                    <td class="py-4 text-right whitespace-nowrap">
                                        <div class="flex items-center justify-end gap-2">
                                            <flux:button :href="route('estimates.show', $estimate)" size="sm" variant="ghost" icon="eye" wire:navigate />
                                            @if ($estimate->isEditable())
                                                @php
                                                    $editRoute = match($estimate->estimator_mode) {
                                                        'simple' => route('estimates.edit.simple', $estimate),
                                                        'template' => route('estimates.edit.template', $estimate),
                                                        default => route('estimates.edit', $estimate),
                                                    };
                                                @endphp
                                                <flux:button :href="$editRoute" size="sm" variant="ghost" icon="pencil" wire:navigate />
                                            @endif
                                            <flux:button :href="route('estimates.pdf', $estimate)" size="sm" variant="ghost" icon="document-arrow-down" target="_blank" />
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="text-center py-8">
                    <flux:icon name="document-text" class="w-12 h-12 mx-auto text-zinc-300 dark:text-zinc-600 mb-3" />
                    <flux:text class="text-zinc-500 dark:text-zinc-400">No estimates yet</flux:text>
                    <flux:text class="text-sm text-zinc-400 dark:text-zinc-500 mt-1">Create an estimate to get started with pricing this project.</flux:text>
                </div>
            @endif
        </div>

        {{-- Notes Section --}}
        <div class="mt-6 border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
            <flux:heading size="sm" class="mb-4">Notes</flux:heading>
            @livewire('deals.note-form', ['dealId' => $deal->id])
        </div>
    </div>
</div>
