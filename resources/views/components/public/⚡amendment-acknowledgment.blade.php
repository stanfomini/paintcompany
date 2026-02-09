<?php

use App\Models\EstimateAmendment;
use Livewire\Component;

new class extends Component {
    public EstimateAmendment $amendment;
}; ?>

<div wire:key="amendment-{{ $amendment->id }}">
    <div class="border rounded-lg p-4 {{ $amendment->isAcknowledged() ? 'border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/20' : 'border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20' }}">
        <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-2">
                @if ($amendment->isAcknowledged())
                    <flux:icon name="check-circle" class="w-5 h-5 text-green-600" />
                @else
                    <flux:icon name="exclamation-triangle" class="w-5 h-5 text-amber-600" />
                @endif
                <flux:text class="font-semibold">Amendment #{{ $amendment->amendment_number }}</flux:text>
                <flux:text size="sm" class="text-zinc-500">{{ $amendment->created_at->format('M j, Y g:i A') }}</flux:text>
            </div>
        </div>

        {{-- Change Details --}}
        <div class="space-y-2 mb-3">
            @foreach ($amendment->changes as $change)
                <div class="flex items-start gap-2 text-sm">
                    @php
                        $iconName = match ($change['category'] ?? '') {
                            'area' => 'square-3-stack-3d',
                            'surface' => 'paint-brush',
                            'package' => 'cube',
                            'option' => 'adjustments-horizontal',
                            'total' => 'currency-dollar',
                            default => 'information-circle',
                        };
                        $actionColor = match ($change['action'] ?? '') {
                            'added' => 'text-green-600 dark:text-green-400',
                            'removed' => 'text-red-600 dark:text-red-400',
                            'modified' => 'text-amber-600 dark:text-amber-400',
                            default => 'text-zinc-600 dark:text-zinc-400',
                        };
                    @endphp
                    <flux:icon name="{{ $iconName }}" class="w-4 h-4 {{ $actionColor }} shrink-0 mt-0.5" />
                    <div>
                        @if (isset($change['field'], $change['old_value'], $change['new_value']))
                            <span class="font-medium">{{ $change['label'] }}</span>:
                            {{ $change['field'] }}
                            <span class="line-through text-red-500">{{ $change['old_value'] }}</span>
                            &rarr;
                            <span class="text-green-600 dark:text-green-400">{{ $change['new_value'] }}</span>
                        @else
                            {{ $change['details'] ?? $change['label'] ?? 'Change' }}
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Total Change --}}
        <div class="flex items-center gap-4 py-2 border-t border-zinc-200 dark:border-zinc-700">
            <flux:text size="sm" class="text-zinc-500">Previous: ${{ number_format($amendment->previous_total, 2) }}</flux:text>
            <flux:text size="sm" class="text-zinc-500">New: ${{ number_format($amendment->new_total, 2) }}</flux:text>
            <flux:text size="sm" class="font-semibold {{ $amendment->getTotalChangeAmount() >= 0 ? 'text-red-600' : 'text-green-600' }}">
                ({{ $amendment->getTotalChangeFormatted() }})
            </flux:text>
        </div>

        {{-- Acknowledged state --}}
        @if ($amendment->isAcknowledged())
            <div class="flex items-center gap-2 text-green-700 dark:text-green-400 mt-3">
                <flux:icon name="check-badge" class="w-5 h-5" />
                <flux:text size="sm" class="font-medium">
                    Acknowledged by {{ $amendment->customer_initials }} on {{ $amendment->customer_acknowledged_at->format('M j, Y g:i A') }}
                </flux:text>
            </div>
        @endif
    </div>
</div>
