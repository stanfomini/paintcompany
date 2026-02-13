{{-- Read-Only Pricing Summary for Proposal Preview --}}
{{-- Receives: $estimate, $hasPackages, $packageData --}}
@php
    $depositPercent = (float) \App\Models\CompanySetting::getInstance()->default_deposit_percent;
    $total = (float) $estimate->total;
    $depositAmount = round($total * ($depositPercent / 100), 2);
    $balanceDue = round($total - $depositAmount, 2);
@endphp

<div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
    <flux:heading size="sm" class="mb-4">{{ __('Pricing Summary') }}</flux:heading>

    <div class="space-y-2">
        @if (!$hasPackages)
            {{-- Non-package mode: show breakdown --}}
            @if ((float) $estimate->areas_subtotal > 0)
                <div class="flex justify-between items-center">
                    <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Painting Work') }}</flux:text>
                    <flux:text class="text-sm text-zinc-900 dark:text-zinc-100">${{ number_format($estimate->areas_subtotal, 2) }}</flux:text>
                </div>
            @endif

            @if ((float) $estimate->items_subtotal > 0)
                <div class="flex justify-between items-center">
                    <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Line Items') }}</flux:text>
                    <flux:text class="text-sm text-zinc-900 dark:text-zinc-100">${{ number_format($estimate->items_subtotal, 2) }}</flux:text>
                </div>
            @endif

            @if ((float) $estimate->options_subtotal > 0)
                <div class="flex justify-between items-center">
                    <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('Selected Options') }}</flux:text>
                    <flux:text class="text-sm text-zinc-900 dark:text-zinc-100">${{ number_format($estimate->options_subtotal, 2) }}</flux:text>
                </div>
            @endif

            @if ((float) ($estimate->subtotal ?? 0) > 0 && (float) ($estimate->subtotal ?? 0) !== $total)
                <div class="flex justify-between items-center">
                    <flux:text class="text-sm text-zinc-500">{{ __('Subtotal') }}</flux:text>
                    <flux:text class="text-sm font-medium">${{ number_format($estimate->subtotal, 2) }}</flux:text>
                </div>
            @endif

            @if ((float) ($estimate->tax_amount ?? 0) > 0)
                <div class="flex justify-between items-center">
                    <flux:text class="text-sm text-zinc-500">{{ __('Tax') }}</flux:text>
                    <flux:text class="text-sm">${{ number_format($estimate->tax_amount, 2) }}</flux:text>
                </div>
            @endif

            @if ((float) ($estimate->discount_amount ?? 0) > 0)
                <div class="flex justify-between items-center">
                    <flux:text class="text-sm text-green-600 dark:text-green-400">{{ __('Discount') }}</flux:text>
                    <flux:text class="text-sm text-green-600 dark:text-green-400">-${{ number_format($estimate->discount_amount, 2) }}</flux:text>
                </div>
            @endif
        @else
            {{-- Package mode: show selected/recommended package --}}
            @php
                $selectedPackage = collect($packageData)->first(fn ($p) => ($p['is_selected'] ?? false) || ($p['is_recommended'] ?? false));
                $selectedPackage = $selectedPackage ?: collect($packageData)->first();
            @endphp
            @if ($selectedPackage)
                <div class="flex justify-between items-center">
                    <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
                        {{ $selectedPackage['name'] ?? __('Selected Package') }}
                    </flux:text>
                    <flux:text class="text-sm text-zinc-900 dark:text-zinc-100">${{ number_format($selectedPackage['total'], 2) }}</flux:text>
                </div>
            @endif
        @endif

        {{-- Total --}}
        @if ($total > 0)
            <div class="flex justify-between items-center pt-3 mt-2 border-t border-zinc-200 dark:border-zinc-700">
                <flux:text class="font-semibold">{{ __('Total') }}</flux:text>
                <flux:text class="text-xl font-bold text-blue-600 dark:text-blue-400">${{ number_format($total, 2) }}</flux:text>
            </div>
        @endif

        {{-- Deposit & Balance --}}
        @if ($total > 0 && $depositPercent > 0)
            <div class="pt-3 mt-2 border-t border-zinc-100 dark:border-zinc-800 space-y-1.5">
                <div class="flex justify-between items-center">
                    <flux:text class="text-sm text-zinc-500">{{ __('Deposit Due') }} ({{ number_format($depositPercent, 0) }}%)</flux:text>
                    <flux:text class="text-sm font-medium">${{ number_format($depositAmount, 2) }}</flux:text>
                </div>
                <div class="flex justify-between items-center">
                    <flux:text class="text-sm text-zinc-500">{{ __('Balance Due on Completion') }}</flux:text>
                    <flux:text class="text-sm font-medium">${{ number_format($balanceDue, 2) }}</flux:text>
                </div>
            </div>
        @endif
    </div>
</div>
