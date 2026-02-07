<?php

use App\Models\Estimate;
use App\Models\User;
use App\Notifications\EstimateProposalSent;
use App\Notifications\EstimateRejected;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public function boot(): void
    {
        if (! auth()->user()?->can('estimates.view')) {
            abort(403);
        }
    }

    public Estimate $estimate;

    public bool $showSendModal = false;

    public ?string $publicUrl = null;

    public function mount(Estimate $estimate): void
    {
        $this->estimate = $estimate->load(['deal.customer', 'deal.property', 'createdBy.internalUser', 'items', 'areas.surfaces', 'templateUsages.template', 'packages', 'signature']);
        $this->publicUrl = $this->estimate->getPublicUrl();
    }

    #[On('estimate-updated')]
    public function refreshEstimate(): void
    {
        $this->estimate = $this->estimate->fresh([
            'deal.customer',
            'deal.property',
            'createdBy.internalUser',
            'items',
            'areas.surfaces',
            'options',
            'templateUsages.template',
            'packages',
            'signature',
        ]);
        $this->publicUrl = $this->estimate->getPublicUrl();
    }

    public function openSendModal(): void
    {
        abort_unless(auth()->user()->can('estimates.send'), 403);

        if ($this->estimate->status !== 'draft') {
            session()->flash('error', 'Only draft estimates can be sent.');

            return;
        }

        $customer = $this->estimate->deal?->customer;
        if (! $customer || ! $customer->email) {
            session()->flash('error', 'Customer email is required to send the proposal.');

            return;
        }

        if (! $this->estimate->hasCreatorSignature()) {
            session()->flash('error', 'You must configure your signature before sending proposals. Go to Settings > Signature to add your signature.');

            return;
        }

        $this->showSendModal = true;
    }

    public function closeSendModal(): void
    {
        $this->showSendModal = false;
    }

    public function sendProposal(): void
    {
        abort_unless(auth()->user()->can('estimates.send'), 403);

        if ($this->estimate->status !== 'draft') {
            session()->flash('error', 'Only draft estimates can be sent.');
            $this->showSendModal = false;

            return;
        }

        $customer = $this->estimate->deal?->customer;
        if (! $customer || ! $customer->email) {
            session()->flash('error', 'Customer email is required to send the proposal.');
            $this->showSendModal = false;

            return;
        }

        if (! $this->estimate->hasCreatorSignature()) {
            session()->flash('error', 'You must configure your signature before sending proposals. Go to Settings > Signature to add your signature.');
            $this->showSendModal = false;

            return;
        }

        $this->estimate->markAsSent();
        $this->publicUrl = $this->estimate->getPublicUrl();

        $customer->notify(new EstimateProposalSent($this->estimate));

        $this->showSendModal = false;
        $this->refreshEstimate();
        session()->flash('success', 'Proposal sent to '.$customer->email);
    }

    public function copyPublicUrl(): void
    {
        if ($this->publicUrl) {
            $this->dispatch('copy-to-clipboard', url: $this->publicUrl);
        }
    }

    public function markAsSent(): void
    {
        abort_unless(auth()->user()->can('estimates.send'), 403);

        if ($this->estimate->status !== 'draft') {
            session()->flash('error', 'Only draft estimates can be marked as sent.');

            return;
        }

        $this->estimate->markAsSent();
        $this->refreshEstimate();
        session()->flash('success', 'Estimate marked as sent.');
    }

    public function markAsAccepted(): void
    {
        if (! in_array($this->estimate->status, ['sent', 'draft'])) {
            session()->flash('error', 'This estimate cannot be marked as accepted.');

            return;
        }

        // Internal staff can force accept without signature
        $this->estimate->markAsAccepted(force: true);
        $this->refreshEstimate();
        session()->flash('success', 'Estimate marked as accepted.');
    }

    public function markAsRejected(?string $reason = null): void
    {
        if (! in_array($this->estimate->status, ['sent', 'draft'])) {
            session()->flash('error', 'This estimate cannot be marked as rejected.');

            return;
        }

        $this->estimate->markAsRejected();

        // Notify all internal users
        $internalUsers = User::where('type', 'internal')->get();
        foreach ($internalUsers as $user) {
            $user->notify(new EstimateRejected($this->estimate, $reason));
        }

        $this->refreshEstimate();
        session()->flash('success', 'Estimate marked as rejected.');
    }

    public function getStatusColorProperty(): string
    {
        return match ($this->estimate->status) {
            'draft' => 'zinc',
            'sent' => 'blue',
            'accepted' => 'green',
            'rejected' => 'red',
            'expired' => 'yellow',
            default => 'zinc',
        };
    }
}; ?>

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
                @can('estimates.edit')
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
                @endcan
                @can('estimates.send')
                    @if ($estimate->status === 'draft')
                        <flux:button wire:click="openSendModal" icon="paper-airplane" variant="primary">
                            Send Proposal
                        </flux:button>
                    @endif
                @endcan
                <flux:button :href="route('estimates.breakdown', $estimate)" icon="calculator" variant="ghost" wire:navigate>
                    Cost Breakdown
                </flux:button>
                <flux:button :href="route('estimates.pdf', $estimate)" target="_blank" icon="document-arrow-down" variant="ghost">
                    Download PDF
                </flux:button>
                <flux:dropdown>
                    <flux:button variant="ghost" icon="ellipsis-horizontal" />
                    <flux:menu>
                        @can('estimates.send')
                            @if ($estimate->status === 'draft')
                                <flux:menu.item wire:click="markAsSent" icon="paper-airplane">Mark as Sent (No Email)</flux:menu.item>
                            @endif
                        @endcan
                        @if (in_array($estimate->status, ['draft', 'sent']))
                            <flux:menu.item wire:click="markAsAccepted" icon="check-circle">Mark as Accepted</flux:menu.item>
                            <flux:menu.item wire:click="markAsRejected" icon="x-circle">Mark as Rejected</flux:menu.item>
                        @endif
                        <flux:menu.item :href="route('estimates.painter-breakdown', $estimate)" icon="clipboard-document-list" wire:navigate>Painter's Work Order</flux:menu.item>
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
                @if ($estimate->isTemplateMode() && ($estimate->areas->count() > 0 || $estimate->templateUsages->count() > 0))
                    {{-- Template Mode: Show areas with PBP breakdown --}}
                    <flux:heading size="sm" class="mb-4">Estimate Areas</flux:heading>

                    @if ($estimate->areas->count() > 0)
                        <div class="space-y-4">
                            @foreach ($estimate->areas as $area)
                                <div wire:key="area-{{ $area->id }}" x-data="{ expanded: false }" class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">
                                    {{-- Area Header --}}
                                    <div class="flex items-center justify-between p-4 bg-zinc-50 dark:bg-zinc-800/50 cursor-pointer" @click="expanded = !expanded">
                                        <div class="flex items-center gap-3">
                                            <flux:icon name="squares-2x2" class="w-5 h-5 text-zinc-400" />
                                            <div>
                                                <flux:text class="font-medium">{{ $area->name }}</flux:text>
                                                <div class="text-sm text-zinc-500 dark:text-zinc-400">
                                                    {{ number_format($area->wall_sqft, 0) }} sqft
                                                    @if ($area->surfaces->count() > 0)
                                                        · {{ $area->surfaces->count() }} surfaces
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-4">
                                            @php
                                                // Use canonical area-level pricing (margin applied once per area)
                                                $areaCustomerPrice = $area->getCustomerPrice();
                                                $totalLaborHours = $area->surfaces->where('is_included', true)->sum('labor_hours');
                                            @endphp
                                            <div class="text-right">
                                                <div class="text-lg font-bold text-blue-600 dark:text-blue-400">${{ number_format($areaCustomerPrice, 2) }}</div>
                                                <div class="text-xs text-zinc-500">{{ number_format($totalLaborHours, 1) }} hrs</div>
                                            </div>
                                            <svg x-show="!expanded" class="w-5 h-5 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                            <svg x-show="expanded" class="w-5 h-5 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>
                                        </div>
                                    </div>

                                    {{-- Expanded Details --}}
                                    <div x-show="expanded" x-collapse class="border-t border-zinc-200 dark:border-zinc-700">
                                        <div class="p-4 space-y-4">
                                            {{-- Surfaces --}}
                                            @if ($area->surfaces->count() > 0)
                                                <div>
                                                    <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase mb-2">Surfaces</div>
                                                    <div class="space-y-2">
                                                        @foreach ($area->surfaces as $surface)
                                                            <div class="flex justify-between items-center text-sm">
                                                                <div>
                                                                    <span class="font-medium">{{ $surface->name }}</span>
                                                                    <span class="text-zinc-500">{{ number_format($surface->quantity, 0) }} {{ $surface->unit }} · {{ $surface->coats }} coats</span>
                                                                </div>
                                                                <div class="text-right">
                                                                    <span class="text-zinc-500">{{ number_format($surface->labor_hours, 1) }} hrs</span>
                                                                    <span class="mx-1">·</span>
                                                                    <span class="font-medium">${{ number_format($surface->total, 2) }}</span>
                                                                </div>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                </div>
                                            @endif

                                            {{-- PBP Calculation Breakdown --}}
                                            @php
                                                $settings = \App\Models\CompanySetting::getInstance();
                                                $scopeType = $area->scope_type ?? 'interior';
                                                $hourlyWage = $settings->getHourlyWageForScope($scopeType);
                                                $includedSurfaces = $area->surfaces->where('is_included', true);
                                                $totalLaborCost = $includedSurfaces->sum('labor_cost');
                                                $totalMaterialCost = $includedSurfaces->sum('material_cost');
                                                $cleanupHours = $settings->calculateCleanupHours($totalLaborHours, $scopeType);
                                                $cleanupCost = $cleanupHours * $hourlyWage;
                                                $sundriesCost = $settings->calculateSundriesCost($totalLaborHours, $scopeType);
                                                $addonCost = (float) $area->addons()->sum('total_price');
                                                $cogsResult = $settings->calculateCOGS($totalLaborCost, $cleanupCost, $sundriesCost, $totalMaterialCost + $addonCost);
                                                $cogs = $cogsResult['cogs'];
                                                $marginPercent = $settings->getMarginPercentForScope($scopeType);
                                            @endphp
                                            <div class="bg-blue-50 dark:bg-blue-950/30 rounded-lg p-3">
                                                <div class="text-xs font-medium text-blue-700 dark:text-blue-400 mb-2">PBP Calculation</div>
                                                <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs">
                                                    <div>
                                                        <div class="text-zinc-500 dark:text-zinc-400">Labor</div>
                                                        <div class="font-medium">{{ number_format($totalLaborHours, 1) }} hrs × ${{ number_format($hourlyWage, 2) }} = ${{ number_format($totalLaborCost, 2) }}</div>
                                                    </div>
                                                    <div>
                                                        <div class="text-zinc-500 dark:text-zinc-400">Cleanup</div>
                                                        <div class="font-medium">{{ number_format($cleanupHours, 2) }} hrs = ${{ number_format($cleanupCost, 2) }}</div>
                                                    </div>
                                                    <div>
                                                        <div class="text-zinc-500 dark:text-zinc-400">Sundries</div>
                                                        <div class="font-medium">${{ number_format($sundriesCost, 2) }}</div>
                                                    </div>
                                                    <div>
                                                        <div class="text-zinc-500 dark:text-zinc-400">Materials</div>
                                                        <div class="font-medium">${{ number_format($totalMaterialCost, 2) }}</div>
                                                    </div>
                                                </div>
                                                @if ($addonCost > 0)
                                                <div class="grid grid-cols-2 gap-2 text-xs mt-2">
                                                    <div>
                                                        <div class="text-zinc-500 dark:text-zinc-400">Addons</div>
                                                        <div class="font-medium">${{ number_format($addonCost, 2) }}</div>
                                                    </div>
                                                </div>
                                                @endif
                                                @if ($cogsResult['material_tax'] > 0 || $cogsResult['labor_tax'] > 0)
                                                <div class="grid grid-cols-2 gap-2 text-xs mt-2">
                                                    @if ($cogsResult['material_tax'] > 0)
                                                    <div>
                                                        <div class="text-zinc-500 dark:text-zinc-400">Material Tax ({{ number_format($settings->getMaterialTaxRate(), 1) }}%)</div>
                                                        <div class="font-medium">${{ number_format($cogsResult['material_tax'], 2) }}</div>
                                                    </div>
                                                    @endif
                                                    @if ($cogsResult['labor_tax'] > 0)
                                                    <div>
                                                        <div class="text-zinc-500 dark:text-zinc-400">Labor Tax</div>
                                                        <div class="font-medium">${{ number_format($cogsResult['labor_tax'], 2) }}</div>
                                                    </div>
                                                    @endif
                                                </div>
                                                @endif
                                                <div class="mt-3 pt-2 border-t border-blue-200 dark:border-blue-800 text-xs">
                                                    <div class="flex justify-between items-center">
                                                        <span class="text-zinc-600 dark:text-zinc-400 font-medium">COGS</span>
                                                        <span class="font-medium">${{ number_format($cogs, 2) }}</span>
                                                    </div>
                                                    <div class="flex justify-between items-center mt-1">
                                                        <span class="text-zinc-600 dark:text-zinc-400">OTD = COGS / {{ number_format($marginPercent, 0) }}%</span>
                                                        <span class="font-bold text-blue-600 dark:text-blue-400">${{ number_format($cogs, 2) }} / {{ number_format($marginPercent/100, 2) }} = ${{ number_format($areaCustomerPrice, 2) }}</span>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @elseif ($estimate->templateUsages->count() > 0)
                        {{-- Fallback to template usages table if no areas --}}
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
                    @endif
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

            </div>

            {{-- Package Builder Section --}}
            {{-- Editable for draft and sent estimates, read-only for accepted/rejected --}}
            @if ($estimate->isEditable() || $estimate->status === 'sent')
                <div class="print:hidden">
                    @if ($estimate->status === 'sent')
                        <div class="mb-4 p-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-lg">
                            <div class="flex items-center gap-2">
                                <flux:icon name="exclamation-triangle" class="w-5 h-5 text-amber-600 dark:text-amber-400" />
                                <flux:text size="sm" class="text-amber-700 dark:text-amber-300">
                                    This estimate has been sent to the customer. Any changes you make will notify them automatically.
                                </flux:text>
                            </div>
                        </div>
                    @endif
                    <livewire:estimates.package-builder :estimate="$estimate" :key="'package-builder-'.$estimate->id" />
                </div>
            @elseif ($estimate->packages()->count() > 0)
                {{-- Show read-only packages for accepted/rejected estimates --}}
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

            {{-- Signatures --}}
            @if ($estimate->createdBy?->internalUser?->hasSignature() || $estimate->signature)
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <flux:heading size="sm" class="mb-4">Signatures</flux:heading>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-8">
                        {{-- Estimator Signature --}}
                        @if ($estimate->createdBy?->internalUser?->hasSignature())
                            <div>
                                <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider mb-3">Prepared By</div>
                                @if ($estimate->createdBy->internalUser->signature_type === 'drawn')
                                    <img
                                        src="{{ $estimate->createdBy->internalUser->signature_data }}"
                                        alt="Estimator Signature"
                                        class="max-h-16 mb-2"
                                    />
                                @else
                                    <div
                                        class="text-2xl text-zinc-900 dark:text-white mb-2"
                                        style="font-family: 'Brush Script MT', 'Segoe Script', 'Bradley Hand', cursive;"
                                    >
                                        {{ $estimate->createdBy->internalUser->signature_data }}
                                    </div>
                                @endif
                                <div class="border-t border-zinc-300 dark:border-zinc-600 pt-2">
                                    <flux:text class="font-medium">{{ $estimate->createdBy->name }}</flux:text>
                                    @if ($estimate->createdBy->internalUser->job_title)
                                        <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ $estimate->createdBy->internalUser->job_title }}</flux:text>
                                    @endif
                                </div>
                            </div>
                        @endif

                        {{-- Customer Signature --}}
                        @if ($estimate->signature)
                            <div>
                                <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider mb-3">Accepted By</div>
                                @if ($estimate->signature->signature_type === 'drawn')
                                    <img
                                        src="{{ $estimate->signature->signature_data }}"
                                        alt="Customer Signature"
                                        class="max-h-16 mb-2"
                                    />
                                @else
                                    <div
                                        class="text-2xl text-zinc-900 dark:text-white mb-2"
                                        style="font-family: 'Brush Script MT', 'Segoe Script', 'Bradley Hand', cursive;"
                                    >
                                        {{ $estimate->signature->signature_data }}
                                    </div>
                                @endif
                                <div class="border-t border-zinc-300 dark:border-zinc-600 pt-2">
                                    <flux:text class="font-medium">{{ $estimate->signature->signer_name }}</flux:text>
                                    @if ($estimate->signature->signed_at)
                                        <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                                            Signed {{ $estimate->signature->signed_at->format('M j, Y g:i A') }}
                                        </flux:text>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            {{-- Photos --}}
            @if ($estimate->deal?->property)
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 print:hidden">
                    <livewire:shared.photo-gallery
                        :property="$estimate->deal->property"
                        :estimate="$estimate"
                        context="estimate"
                        :key="'photos-'.$estimate->id"
                    />
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
