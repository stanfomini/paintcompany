<div>
    <div class="max-w-5xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-4">
                <flux:button variant="ghost" :href="route('estimates.index')" icon="arrow-left" wire:navigate>
                    Back
                </flux:button>
                <flux:heading size="xl">{{ $estimate->estimate_number }}</flux:heading>
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
                <flux:badge size="sm" color="{{ $this->statusColor }}">{{ $estimate->status_label }}</flux:badge>
            </div>
            <div class="flex items-center gap-2">
                @if ($estimate->isEditable())
                    @php
                        $editRoute = match($estimate->estimator_mode) {
                            'simple' => route('estimates.edit.simple', $estimate),
                            'template' => route('estimates.edit.template', $estimate),
                            default => route('estimates.edit', $estimate),
                        };
                    @endphp
                    <flux:button :href="$editRoute" icon="pencil" wire:navigate>
                        Edit
                    </flux:button>
                @endif
                @if ($estimate->status === 'draft')
                    <flux:button wire:click="openSendModal" icon="paper-airplane" variant="primary">
                        Send Proposal
                    </flux:button>
                @endif
                <flux:button :href="route('estimates.pdf', $estimate)" target="_blank" icon="document-arrow-down" variant="ghost">
                    Download PDF
                </flux:button>
                <flux:dropdown>
                    <flux:button variant="ghost" icon="ellipsis-horizontal" />
                    <flux:menu>
                        @if ($estimate->status === 'draft')
                            <flux:menu.item wire:click="markAsSent" icon="paper-airplane">Mark as Sent (No Email)</flux:menu.item>
                        @endif
                        @if (in_array($estimate->status, ['draft', 'sent']))
                            <flux:menu.item wire:click="markAsAccepted" icon="check-circle">Mark as Accepted</flux:menu.item>
                            <flux:menu.item wire:click="markAsRejected" icon="x-circle">Mark as Rejected</flux:menu.item>
                        @endif
                        <flux:menu.item icon="printer" onclick="window.print()">Print</flux:menu.item>
                    </flux:menu>
                </flux:dropdown>
            </div>
        </div>

        @if (session('success'))
            <flux:callout variant="success" class="mb-4 print:hidden">
                {{ session('success') }}
            </flux:callout>
        @endif

        @if (session('error'))
            <flux:callout variant="danger" class="mb-4 print:hidden">
                {{ session('error') }}
            </flux:callout>
        @endif

        @if ($publicUrl)
            <div class="border border-blue-200 dark:border-blue-700 bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4 mb-6 print:hidden">
                <div class="flex items-center justify-between gap-4">
                    <div class="flex items-center gap-2 min-w-0">
                        <flux:icon name="link" class="w-5 h-5 text-blue-600 dark:text-blue-400 shrink-0" />
                        <span class="text-sm font-medium text-blue-800 dark:text-blue-200 shrink-0">Public Link:</span>
                        <span class="text-sm text-blue-600 dark:text-blue-400 truncate">{{ $publicUrl }}</span>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <flux:button
                            wire:click="copyPublicUrl"
                            icon="clipboard-document"
                            size="sm"
                            variant="ghost"
                            x-data="{}"
                            x-on:copy-to-clipboard.window="navigator.clipboard.writeText($event.detail.url).then(() => $flux.toast('Link copied to clipboard'))"
                        >
                            Copy
                        </flux:button>
                        <flux:button :href="$publicUrl" target="_blank" icon="arrow-top-right-on-square" size="sm" variant="ghost">
                            Open
                        </flux:button>
                    </div>
                </div>
                @if ($estimate->public_token_expires_at)
                    <p class="text-xs text-blue-600/70 dark:text-blue-400/70 mt-1 ml-7">
                        Expires: {{ $estimate->public_token_expires_at->format('M j, Y') }}
                    </p>
                @endif
            </div>
        @endif

        <div class="space-y-6">
            <div class="grid gap-6 md:grid-cols-2">
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <flux:heading size="sm" class="mb-4">Estimate Details</flux:heading>
                    <dl class="space-y-3">
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Title</dt>
                            <dd class="mt-1">
                                <flux:text class="font-medium">{{ $estimate->title }}</flux:text>
                            </dd>
                        </div>
                        @if ($estimate->description)
                            <div>
                                <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Description</dt>
                                <dd class="mt-1">
                                    <flux:text>{{ $estimate->description }}</flux:text>
                                </dd>
                            </div>
                        @endif
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Valid Until</dt>
                            <dd class="mt-1">
                                @if ($estimate->valid_until)
                                    <flux:text class="{{ $estimate->isExpired() ? 'text-red-600' : '' }}">
                                        {{ $estimate->valid_until->format('M j, Y') }}
                                        @if ($estimate->isExpired())
                                            (Expired)
                                        @endif
                                    </flux:text>
                                @else
                                    <flux:text>-</flux:text>
                                @endif
                            </dd>
                        </div>
                    </dl>
                </div>

                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <flux:heading size="sm" class="mb-4">Customer & Deal</flux:heading>
                    <dl class="space-y-3">
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Deal</dt>
                            <dd class="mt-1">
                                <a href="{{ route('deals.show', $estimate->deal) }}" class="text-blue-600 hover:underline print:text-black print:no-underline" wire:navigate>
                                    {{ $estimate->deal->title }}
                                </a>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Customer</dt>
                            <dd class="mt-1">
                                <a href="{{ route('customers.show', $estimate->deal->customer) }}" class="text-blue-600 hover:underline print:text-black print:no-underline" wire:navigate>
                                    {{ $estimate->deal->customer->full_name }}
                                </a>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Created By</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $estimate->createdBy?->name ?? '-' }}</flux:text>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">Created</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $estimate->created_at->format('M j, Y g:i A') }}</flux:text>
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>

            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                @if ($estimate->isTemplateMode() && $estimate->templateUsages->count() > 0)
                    {{-- Template Mode: Show template usages --}}
                    <flux:heading size="sm" class="mb-4">Estimate Items</flux:heading>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                            <thead>
                                <tr>
                                    <th scope="col" class="py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                        Item
                                    </th>
                                    <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                        Qty
                                    </th>
                                    <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                        Coats
                                    </th>
                                    <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                        Price
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                                @foreach ($estimate->templateUsages as $usage)
                                    <tr wire:key="usage-{{ $usage->id }}">
                                        <td class="py-4">
                                            <div class="flex items-center gap-3">
                                                @if ($usage->template?->icon)
                                                    <flux:icon name="{{ $usage->template->icon }}" class="w-5 h-5 text-zinc-400" />
                                                @endif
                                                <div>
                                                    <flux:text class="font-medium">{{ $usage->template?->name ?? 'Unknown Template' }}</flux:text>
                                                    @if ($usage->template?->description)
                                                        <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ Str::limit($usage->template->description, 60) }}</flux:text>
                                                    @endif
                                                </div>
                                            </div>
                                        </td>
                                        <td class="px-4 py-4 text-right whitespace-nowrap">
                                            <flux:text>{{ number_format($usage->quantity, 0) }} {{ $usage->template?->default_unit ?? 'sqft' }}</flux:text>
                                        </td>
                                        <td class="px-4 py-4 text-center whitespace-nowrap">
                                            <flux:text>{{ $usage->coats }}</flux:text>
                                        </td>
                                        <td class="px-4 py-4 text-right whitespace-nowrap">
                                            <flux:text class="font-medium">${{ number_format($usage->calculated_price, 2) }}</flux:text>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @elseif ($estimate->isSimpleMode())
                    {{-- Simple Mode: Show sqft calculation --}}
                    <flux:heading size="sm" class="mb-4">Quick Estimate</flux:heading>
                    <div class="bg-zinc-50 dark:bg-zinc-800 rounded-lg p-4">
                        <dl class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <div>
                                <dt class="text-sm text-zinc-500 dark:text-zinc-400">Square Feet</dt>
                                <dd class="text-lg font-medium">{{ number_format($estimate->simple_sqft ?? 0, 0) }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm text-zinc-500 dark:text-zinc-400">Rate per Sqft</dt>
                                <dd class="text-lg font-medium">${{ number_format($estimate->simple_rate_per_sqft ?? 0, 2) }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm text-zinc-500 dark:text-zinc-400">Project Type</dt>
                                <dd class="text-lg font-medium">{{ ucfirst($estimate->project_type ?? 'Interior') }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm text-zinc-500 dark:text-zinc-400">Calculated</dt>
                                <dd class="text-lg font-medium">${{ number_format(($estimate->simple_sqft ?? 0) * ($estimate->simple_rate_per_sqft ?? 0), 2) }}</dd>
                            </div>
                        </dl>
                    </div>
                @else
                    {{-- Standard/Detailed Mode: Show line items --}}
                    <flux:heading size="sm" class="mb-4">Line Items</flux:heading>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                            <thead>
                                <tr>
                                    <th scope="col" class="py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                        Description
                                    </th>
                                    <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                        Qty
                                    </th>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                        Unit
                                    </th>
                                    <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                        Unit Price
                                    </th>
                                    <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                        Total
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                                @forelse ($estimate->items as $item)
                                    <tr wire:key="item-{{ $item->id }}">
                                        <td class="py-4">
                                            <flux:text class="font-medium">{{ $item->description }}</flux:text>
                                            @if ($item->details)
                                                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">{{ $item->details }}</flux:text>
                                            @endif
                                        </td>
                                        <td class="px-4 py-4 text-right whitespace-nowrap">
                                            <flux:text>{{ number_format($item->quantity, 2) }}</flux:text>
                                        </td>
                                        <td class="px-4 py-4 whitespace-nowrap">
                                            <flux:text>{{ $item->unit }}</flux:text>
                                        </td>
                                        <td class="px-4 py-4 text-right whitespace-nowrap">
                                            <flux:text>${{ number_format($item->unit_price, 2) }}</flux:text>
                                        </td>
                                        <td class="px-4 py-4 text-right whitespace-nowrap">
                                            <flux:text class="font-medium">${{ number_format($item->total, 2) }}</flux:text>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="py-8 text-center text-zinc-500 dark:text-zinc-400">
                                            No line items added yet.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @endif

                <div class="mt-6 pt-6 border-t border-zinc-200 dark:border-zinc-700">
                    <dl class="space-y-2 w-full md:w-64 ml-auto">
                        <div class="flex justify-between">
                            <dt class="text-sm text-zinc-500 dark:text-zinc-400">Subtotal</dt>
                            <dd class="font-medium">${{ number_format($estimate->subtotal, 2) }}</dd>
                        </div>
                        @if ($estimate->tax_rate > 0)
                            <div class="flex justify-between">
                                <dt class="text-sm text-zinc-500 dark:text-zinc-400">Tax ({{ number_format($estimate->tax_rate, 2) }}%)</dt>
                                <dd class="font-medium">${{ number_format($estimate->tax_amount, 2) }}</dd>
                            </div>
                        @endif
                        @if ($estimate->discount_amount > 0)
                            <div class="flex justify-between">
                                <dt class="text-sm text-zinc-500 dark:text-zinc-400">Discount</dt>
                                <dd class="font-medium text-red-600">-${{ number_format($estimate->discount_amount, 2) }}</dd>
                            </div>
                        @endif
                        <div class="flex justify-between pt-2 border-t border-zinc-200 dark:border-zinc-700">
                            <dt class="text-base font-medium">Total</dt>
                            <dd class="text-lg font-bold">${{ number_format($estimate->total, 2) }}</dd>
                        </div>
                    </dl>
                </div>
            </div>

            {{-- Package Builder Section (for creating Good/Better/Best options) --}}
            @if ($estimate->isEditable())
                <div class="print:hidden">
                    <livewire:estimates.package-builder :estimate="$estimate" :key="'package-builder-'.$estimate->id" />
                </div>
            @elseif ($estimate->packages()->count() > 0)
                {{-- Show packages if they exist on a non-editable estimate --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <flux:heading size="sm" class="mb-4">Pricing Packages</flux:heading>
                    <div class="grid gap-4 md:grid-cols-3">
                        @foreach ($estimate->packages as $package)
                            <div class="border rounded-lg p-4 {{ $package->is_selected ? 'border-green-500 bg-green-50 dark:bg-green-900/20' : 'border-zinc-200 dark:border-zinc-700' }}">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="font-medium">{{ $package->name }}</span>
                                    @if ($package->is_recommended)
                                        <flux:badge size="sm" color="blue">Recommended</flux:badge>
                                    @endif
                                    @if ($package->is_selected)
                                        <flux:badge size="sm" color="green">Selected</flux:badge>
                                    @endif
                                </div>
                                <div class="text-2xl font-bold">${{ number_format($package->total, 2) }}</div>
                                @if ($package->description)
                                    <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-2">{{ $package->description }}</p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($estimate->notes || $estimate->terms)
                <div class="grid gap-6 md:grid-cols-2">
                    @if ($estimate->notes)
                        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                            <flux:heading size="sm" class="mb-4">Notes</flux:heading>
                            <flux:text class="whitespace-pre-wrap">{{ $estimate->notes }}</flux:text>
                        </div>
                    @endif

                    @if ($estimate->terms)
                        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                            <flux:heading size="sm" class="mb-4">Terms & Conditions</flux:heading>
                            <flux:text class="whitespace-pre-wrap">{{ $estimate->terms }}</flux:text>
                        </div>
                    @endif
                </div>
            @endif

            @if ($estimate->sent_at || $estimate->accepted_at || $estimate->rejected_at)
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <flux:heading size="sm" class="mb-4">Status History</flux:heading>
                    <dl class="space-y-3">
                        @if ($estimate->sent_at)
                            <div class="flex items-center gap-2">
                                <flux:icon name="paper-airplane" class="w-4 h-4 text-blue-500" />
                                <dt class="text-sm text-zinc-500 dark:text-zinc-400">Sent:</dt>
                                <dd><flux:text>{{ $estimate->sent_at->format('M j, Y g:i A') }}</flux:text></dd>
                            </div>
                        @endif
                        @if ($estimate->accepted_at)
                            <div class="flex items-center gap-2">
                                <flux:icon name="check-circle" class="w-4 h-4 text-green-500" />
                                <dt class="text-sm text-zinc-500 dark:text-zinc-400">Accepted:</dt>
                                <dd><flux:text>{{ $estimate->accepted_at->format('M j, Y g:i A') }}</flux:text></dd>
                            </div>
                        @endif
                        @if ($estimate->rejected_at)
                            <div class="flex items-center gap-2">
                                <flux:icon name="x-circle" class="w-4 h-4 text-red-500" />
                                <dt class="text-sm text-zinc-500 dark:text-zinc-400">Rejected:</dt>
                                <dd><flux:text>{{ $estimate->rejected_at->format('M j, Y g:i A') }}</flux:text></dd>
                            </div>
                        @endif
                    </dl>
                </div>
            @endif

        </div>
    </div>

    <flux:modal wire:model="showSendModal" class="max-w-lg">
        <div class="space-y-6">
            <flux:heading size="lg">Send Proposal</flux:heading>

            <div class="space-y-4">
                <div class="bg-zinc-50 dark:bg-zinc-800 rounded-lg p-4">
                    <dl class="space-y-2">
                        <div class="flex justify-between">
                            <dt class="text-sm text-zinc-500 dark:text-zinc-400">Estimate</dt>
                            <dd class="font-medium">{{ $estimate->estimate_number }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-sm text-zinc-500 dark:text-zinc-400">Title</dt>
                            <dd class="font-medium">{{ $estimate->title }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-sm text-zinc-500 dark:text-zinc-400">Total</dt>
                            <dd class="font-medium">${{ number_format($estimate->total, 2) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-sm text-zinc-500 dark:text-zinc-400">Customer</dt>
                            <dd class="font-medium">{{ $estimate->deal?->customer?->full_name ?? '-' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-sm text-zinc-500 dark:text-zinc-400">Email</dt>
                            <dd class="font-medium">{{ $estimate->deal?->customer?->email ?? '-' }}</dd>
                        </div>
                    </dl>
                </div>

                <flux:callout variant="info">
                    An email will be sent to the customer with a link to view and accept the proposal.
                </flux:callout>
            </div>

            <div class="flex justify-end gap-3">
                <flux:button wire:click="closeSendModal" variant="ghost">Cancel</flux:button>
                <flux:button wire:click="sendProposal" variant="primary" icon="paper-airplane">
                    Send Proposal
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
