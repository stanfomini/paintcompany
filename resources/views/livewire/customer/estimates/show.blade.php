<div>
    <div class="max-w-4xl mx-auto">
        <div class="mb-6">
            <flux:button variant="ghost" href="{{ route('customer.estimates.index') }}" icon="arrow-left">
                {{ __('Back to Estimates') }}
            </flux:button>
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

        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
            <div>
                <flux:heading size="xl">{{ $estimate->estimate_number }}</flux:heading>
                <flux:text class="mt-1 text-zinc-500">{{ $estimate->deal->title }}</flux:text>
            </div>
            <div class="flex items-center gap-3">
                @php
                    $statusColor = match($estimate->status) {
                        'sent' => 'yellow',
                        'accepted' => 'green',
                        'rejected' => 'red',
                        'expired' => 'zinc',
                        default => 'zinc',
                    };
                    $statusLabel = match($estimate->status) {
                        'sent' => 'Pending Review',
                        'accepted' => 'Accepted',
                        'rejected' => 'Declined',
                        'expired' => 'Expired',
                        default => $estimate->status_label,
                    };
                @endphp
                <flux:badge size="lg" color="{{ $statusColor }}">
                    {{ $statusLabel }}
                </flux:badge>
            </div>
        </div>

        <!-- Action Buttons for Pending Estimates -->
        @if ($estimate->status === 'sent')
            <div class="flex flex-col sm:flex-row gap-3 mb-6 p-4 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg">
                <div class="flex-1">
                    <flux:heading size="sm" class="text-yellow-800 dark:text-yellow-200">{{ __('Action Required') }}</flux:heading>
                    <flux:text class="text-sm text-yellow-700 dark:text-yellow-300">
                        {{ __('Please review this estimate and accept or decline it.') }}
                    </flux:text>
                </div>
                <div class="flex gap-2">
                    <flux:button variant="primary" icon="check" wire:click="confirmAccept">
                        {{ __('Accept Estimate') }}
                    </flux:button>
                    <flux:button variant="ghost" wire:click="confirmDecline">
                        {{ __('Decline') }}
                    </flux:button>
                </div>
            </div>
        @endif

        <!-- Estimate Info Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-6">
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                <flux:text class="text-zinc-500 text-sm">{{ __('Date Created') }}</flux:text>
                <flux:text class="text-lg font-semibold">
                    {{ $estimate->created_at->format('M j, Y') }}
                </flux:text>
            </div>
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                <flux:text class="text-zinc-500 text-sm">{{ __('Valid Until') }}</flux:text>
                <flux:text class="text-lg font-semibold">
                    @if ($estimate->valid_until)
                        {{ $estimate->valid_until->format('M j, Y') }}
                        @if ($estimate->isExpired())
                            <span class="text-red-600 text-sm">({{ __('Expired') }})</span>
                        @endif
                    @else
                        {{ __('No expiration') }}
                    @endif
                </flux:text>
            </div>
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                <flux:text class="text-zinc-500 text-sm">{{ __('Total') }}</flux:text>
                <flux:text class="text-lg font-semibold text-green-600 dark:text-green-400">
                    ${{ number_format($estimate->total, 2) }}
                </flux:text>
            </div>
        </div>

        <!-- Estimate Title/Description -->
        @if ($estimate->title || $estimate->description)
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 mb-6">
                @if ($estimate->title)
                    <flux:heading size="lg" class="mb-2">{{ $estimate->title }}</flux:heading>
                @endif
                @if ($estimate->description)
                    <flux:text class="whitespace-pre-wrap text-zinc-600 dark:text-zinc-400">{{ $estimate->description }}</flux:text>
                @endif
            </div>
        @endif

        <!-- Line Items -->
        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden mb-6">
            <div class="px-6 py-4 bg-zinc-50 dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700">
                <flux:heading size="lg">{{ __('Line Items') }}</flux:heading>
            </div>
            @if ($items->isNotEmpty())
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                        <thead class="bg-zinc-50 dark:bg-zinc-800">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    {{ __('Description') }}
                                </th>
                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    {{ __('Qty') }}
                                </th>
                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    {{ __('Unit Price') }}
                                </th>
                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    {{ __('Total') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-zinc-900 divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($items as $item)
                                <tr wire:key="item-{{ $item->id }}">
                                    <td class="px-6 py-4">
                                        <flux:text class="font-medium">{{ $item->description }}</flux:text>
                                        @if ($item->details)
                                            <flux:text class="text-sm text-zinc-500">{{ $item->details }}</flux:text>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 text-right whitespace-nowrap">
                                        <flux:text>{{ $item->quantity }} {{ $item->unit ?? '' }}</flux:text>
                                    </td>
                                    <td class="px-6 py-4 text-right whitespace-nowrap">
                                        <flux:text>${{ number_format($item->unit_price, 2) }}</flux:text>
                                    </td>
                                    <td class="px-6 py-4 text-right whitespace-nowrap">
                                        <flux:text class="font-medium">${{ number_format($item->total, 2) }}</flux:text>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="p-6 text-center">
                    <flux:text class="text-zinc-500">{{ __('No line items.') }}</flux:text>
                </div>
            @endif
        </div>

        <!-- Totals Summary -->
        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 mb-6">
            <div class="max-w-xs ml-auto space-y-3">
                <div class="flex justify-between">
                    <flux:text class="text-zinc-500">{{ __('Subtotal') }}</flux:text>
                    <flux:text>${{ number_format($estimate->subtotal, 2) }}</flux:text>
                </div>
                @if ($estimate->discount_amount > 0)
                    <div class="flex justify-between text-green-600 dark:text-green-400">
                        <flux:text>{{ __('Discount') }}</flux:text>
                        <flux:text>-${{ number_format($estimate->discount_amount, 2) }}</flux:text>
                    </div>
                @endif
                @if ($estimate->tax_amount > 0)
                    <div class="flex justify-between">
                        <flux:text class="text-zinc-500">{{ __('Tax') }} ({{ number_format($estimate->tax_rate, 1) }}%)</flux:text>
                        <flux:text>${{ number_format($estimate->tax_amount, 2) }}</flux:text>
                    </div>
                @endif
                <div class="flex justify-between pt-3 border-t border-zinc-200 dark:border-zinc-700">
                    <flux:text class="font-semibold">{{ __('Total') }}</flux:text>
                    <flux:text class="font-semibold text-lg">${{ number_format($estimate->total, 2) }}</flux:text>
                </div>
            </div>
        </div>

        <!-- Terms & Notes -->
        @if ($estimate->notes || $estimate->terms)
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                @if ($estimate->notes)
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                        <flux:heading size="sm" class="mb-2">{{ __('Notes') }}</flux:heading>
                        <flux:text class="whitespace-pre-wrap text-zinc-600 dark:text-zinc-400">{{ $estimate->notes }}</flux:text>
                    </div>
                @endif
                @if ($estimate->terms)
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                        <flux:heading size="sm" class="mb-2">{{ __('Terms & Conditions') }}</flux:heading>
                        <flux:text class="whitespace-pre-wrap text-zinc-600 dark:text-zinc-400">{{ $estimate->terms }}</flux:text>
                    </div>
                @endif
            </div>
        @endif

        <!-- Download PDF -->
        <div class="flex items-center justify-between">
            <div class="text-sm text-zinc-500">
                @if ($estimate->sent_at)
                    <flux:text>{{ __('Sent') }}: {{ $estimate->sent_at->format('M j, Y g:i A') }}</flux:text>
                @endif
                @if ($estimate->accepted_at)
                    <flux:text class="ml-4">{{ __('Accepted') }}: {{ $estimate->accepted_at->format('M j, Y g:i A') }}</flux:text>
                @endif
                @if ($estimate->rejected_at)
                    <flux:text class="ml-4">{{ __('Declined') }}: {{ $estimate->rejected_at->format('M j, Y g:i A') }}</flux:text>
                @endif
            </div>
            <flux:button variant="ghost" icon="arrow-down-tray" href="{{ route('estimates.pdf', $estimate) }}" target="_blank">
                {{ __('Download PDF') }}
            </flux:button>
        </div>
    </div>

    @if ($estimate->status === 'sent')
        <!-- Accept Confirmation Modal -->
        <flux:modal wire:model="showAcceptModal" class="max-w-md">
            <div class="text-center">
                <flux:icon name="check-circle" class="mx-auto h-12 w-12 text-green-500" />
                <flux:heading size="lg" class="mt-4">{{ __('Accept Estimate') }}</flux:heading>
                <flux:text class="mt-2 text-zinc-500">
                    {{ __('Are you sure you want to accept this estimate for') }} <strong>${{ number_format($estimate->total, 2) }}</strong>?
                </flux:text>
                <flux:text class="mt-2 text-sm text-zinc-500">
                    {{ __('By accepting, you agree to the terms and conditions outlined in this estimate.') }}
                </flux:text>
                <div class="mt-6 flex justify-center gap-3">
                    <flux:button variant="ghost" wire:click="cancelAccept">
                        {{ __('Cancel') }}
                    </flux:button>
                    <flux:button variant="primary" wire:click="accept" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="accept">{{ __('Accept Estimate') }}</span>
                        <span wire:loading wire:target="accept">{{ __('Processing...') }}</span>
                    </flux:button>
                </div>
            </div>
        </flux:modal>

        <!-- Decline Confirmation Modal -->
        <flux:modal wire:model="showDeclineModal" class="max-w-md">
            <div class="text-center">
                <flux:icon name="x-circle" class="mx-auto h-12 w-12 text-red-500" />
                <flux:heading size="lg" class="mt-4">{{ __('Decline Estimate') }}</flux:heading>
                <flux:text class="mt-2 text-zinc-500">
                    {{ __('Are you sure you want to decline this estimate? You can always contact us if you have questions.') }}
                </flux:text>
                <div class="mt-6 flex justify-center gap-3">
                    <flux:button variant="ghost" wire:click="cancelDecline">
                        {{ __('Cancel') }}
                    </flux:button>
                    <flux:button variant="danger" wire:click="decline" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="decline">{{ __('Decline Estimate') }}</span>
                        <span wire:loading wire:target="decline">{{ __('Processing...') }}</span>
                    </flux:button>
                </div>
            </div>
        </flux:modal>
    @endif
</div>
