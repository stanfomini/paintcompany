<?php

use App\Models\CompanySetting;
use App\Models\Estimate;
use App\Models\EstimateActivity;
use App\Models\EstimateSignature;
use App\Models\EstimateView;
use App\Services\EstimateCalculationService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Layout('components.layouts.public')] class extends Component {
    public Estimate $estimate;

    public string $token = '';

    public bool $showSignatureModal = false;

    #[Validate('required|string|max:255')]
    public string $signerName = '';

    #[Validate('required|email|max:255')]
    public string $signerEmail = '';

    #[Validate('required|in:drawn,typed')]
    public string $signatureType = 'typed';

    #[Validate('required|string')]
    public string $signatureData = '';

    #[Validate('accepted')]
    public bool $acceptedTerms = false;

    public function mount(string $token): void
    {
        $estimate = Estimate::byPublicToken($token)
            ->with([
                'deal.customer',
                'items',
                'areas.surfaces.productionRate',
                'options',
                'packages',
                'photos' => fn ($q) => $q->forProposal()->orderBy('sort_order'),
                'estimateNotes' => fn ($q) => $q->external()->rootNotes()->with('user'),
                'signature',
                'createdBy.internalUser',
            ])
            ->first();

        if (! $estimate) {
            abort(404, 'Proposal not found.');
        }

        if (! $estimate->isPublicTokenValid()) {
            abort(410, 'This proposal link has expired.');
        }

        $this->estimate = $estimate;
        $this->token = $token;

        // Pre-fill signer info from customer if available
        if ($estimate->deal?->customer) {
            $customer = $estimate->deal->customer;
            $this->signerName = trim($customer->first_name.' '.$customer->last_name);
            $this->signerEmail = $customer->email ?? '';
        }

        // Record view
        EstimateView::record($estimate);

        // Log activity
        EstimateActivity::log(
            $estimate,
            'viewed',
            'Proposal viewed via public link',
            ['ip_address' => request()->ip()]
        );
    }

    public function toggleOption(int $optionId): void
    {
        // Cannot modify if already accepted
        if ($this->estimate->status === 'accepted') {
            return;
        }

        $option = $this->estimate->options()->find($optionId);

        if (! $option) {
            return;
        }

        $option->is_selected = ! $option->is_selected;
        $option->save();

        // Log activity
        EstimateActivity::log(
            $this->estimate,
            'option_toggled',
            $option->is_selected
                ? "Option '{$option->name}' selected"
                : "Option '{$option->name}' deselected",
            [
                'option_id' => $optionId,
                'option_name' => $option->name,
                'is_selected' => $option->is_selected,
            ]
        );

        // Recalculate totals
        $calculationService = app(EstimateCalculationService::class);
        $calculationService->recalculateEstimateTotals($this->estimate);

        // Refresh the estimate
        $this->estimate = $this->estimate->fresh([
            'deal.customer',
            'items',
            'areas.surfaces.productionRate',
            'options',
            'packages',
            'photos' => fn ($q) => $q->forProposal()->orderBy('sort_order'),
            'estimateNotes' => fn ($q) => $q->external()->rootNotes()->with('user'),
            'signature',
            'createdBy.internalUser',
        ]);
    }

    public function openSignatureModal(): void
    {
        // Validate estimate can be accepted
        if ($this->estimate->status === 'accepted') {
            session()->flash('error', 'This proposal has already been accepted.');

            return;
        }

        if ($this->estimate->status === 'rejected') {
            session()->flash('error', 'This proposal has been rejected and cannot be accepted.');

            return;
        }

        if ($this->estimate->isExpired()) {
            session()->flash('error', 'This proposal has expired and cannot be accepted.');

            return;
        }

        $this->showSignatureModal = true;
    }

    #[On('proceed-to-signature')]
    public function handleProceedToSignature(array $data = []): void
    {
        // This is triggered from the ProposalPackages component
        $this->openSignatureModal();
    }

    public function closeSignatureModal(): void
    {
        $this->showSignatureModal = false;
        $this->reset(['signatureData']);
        $this->resetValidation();
    }

    public function submitSignature(): void
    {
        $this->validate([
            'signerName' => 'required|string|max:255',
            'signerEmail' => 'required|email|max:255',
            'signatureType' => 'required|in:drawn,typed',
            'signatureData' => 'required|string',
            'acceptedTerms' => 'accepted',
        ], [
            'signerName.required' => 'Please enter your name.',
            'signerEmail.required' => 'Please enter your email address.',
            'signerEmail.email' => 'Please enter a valid email address.',
            'signatureData.required' => $this->signatureType === 'drawn'
                ? 'Please draw your signature.'
                : 'Please type your signature.',
            'acceptedTerms.accepted' => 'You must accept the terms and conditions.',
        ]);

        // Create signature (model automatically marks estimate as accepted)
        EstimateSignature::createFromRequest(
            $this->estimate,
            $this->signerName,
            $this->signerEmail,
            $this->signatureData,
            $this->signatureType
        );

        // Log activity
        EstimateActivity::log(
            $this->estimate,
            'accepted',
            'Proposal accepted and signed',
            [
                'signer_name' => $this->signerName,
                'signer_email' => $this->signerEmail,
                'signature_type' => $this->signatureType,
            ]
        );

        // Dispatch event for any listeners
        $this->dispatch('estimate-accepted', estimateId: $this->estimate->id);

        // Close modal and refresh
        $this->showSignatureModal = false;

        // Refresh the estimate to show accepted state
        $this->estimate = $this->estimate->fresh([
            'deal.customer',
            'items',
            'areas.surfaces.productionRate',
            'options',
            'photos' => fn ($q) => $q->forProposal()->orderBy('sort_order'),
            'estimateNotes' => fn ($q) => $q->external()->rootNotes()->with('user'),
            'signature',
        ]);

        session()->flash('success', 'Thank you! Your proposal has been accepted.');
    }

    /**
     * Get the margin factor for scaling raw surface prices to match displayed totals.
     */
    public function getMarginFactor(): float
    {
        if (! $this->estimate->isTemplateMode()) {
            return 1.0;
        }

        $settings = CompanySetting::getInstance();
        $scope = $this->estimate->project_type ?? 'interior';
        $marginPercent = $settings->getMarginPercentForScope($scope);

        if ($marginPercent <= 0) {
            return 1.0;
        }

        return 1 / ($marginPercent / 100);
    }

    /**
     * Get an area's customer-facing price (with margins applied).
     * Uses EstimateArea::getCustomerPrice() which applies scope-specific margin.
     */
    public function getAreaDisplayTotal(object $area): float
    {
        if ($area instanceof \App\Models\EstimateArea) {
            return $area->getCustomerPrice();
        }

        // Fallback for non-model objects
        return (float) ($area->surfaces_total ?? 0) * $this->getMarginFactor();
    }

    /**
     * Get a surface's customer-facing price (with margins applied).
     * For display purposes only - estimate totals use area-level margin.
     */
    public function getSurfaceDisplayTotal(object $surface): float
    {
        if ($surface instanceof \App\Models\EstimateAreaSurface) {
            return $surface->getCustomerPrice();
        }

        // Fallback for non-model objects
        return (float) ($surface->total ?? 0) * $this->getMarginFactor();
    }

    public function canAccept(): bool
    {
        return in_array($this->estimate->status, ['sent', 'draft'])
            && ! $this->estimate->isExpired()
            && ! $this->estimate->hasSignature();
    }

    /**
     * Whether this estimate uses the package system (Good/Better/Best).
     */
    public function hasPackages(): bool
    {
        return $this->estimate->packages->isNotEmpty()
            || \App\Models\PackageTemplate::active()->exists();
    }
}; ?>

{{-- Signature Pad JS CDN --}}
@assets
<script src="https://cdn.jsdelivr.net/npm/signature_pad@4.1.7/dist/signature_pad.umd.min.js"></script>
@endassets

<div class="py-8 px-4 sm:px-6 lg:px-8">
    <div class="max-w-4xl mx-auto">
        {{-- Flash Messages --}}
        @if (session()->has('success'))
            <flux:callout variant="success" class="mb-6">
                {{ session('success') }}
            </flux:callout>
        @endif

        @if (session()->has('error'))
            <flux:callout variant="danger" class="mb-6">
                {{ session('error') }}
            </flux:callout>
        @endif

        {{-- Status Banner --}}
        @if ($estimate->status === 'accepted')
            <div class="mb-6 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg p-4">
                <div class="flex items-center gap-3">
                    <flux:icon name="check-circle" class="w-6 h-6 text-green-600 dark:text-green-400" />
                    <div>
                        <flux:heading size="base" class="text-green-800 dark:text-green-200">Proposal Accepted</flux:heading>
                        <flux:text size="sm" class="text-green-700 dark:text-green-300">
                            This proposal was accepted on {{ $estimate->accepted_at?->format('F j, Y \a\t g:i A') }}
                        </flux:text>
                    </div>
                </div>
            </div>
        @elseif ($estimate->status === 'rejected')
            <div class="mb-6 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
                <div class="flex items-center gap-3">
                    <flux:icon name="x-circle" class="w-6 h-6 text-red-600 dark:text-red-400" />
                    <div>
                        <flux:heading size="base" class="text-red-800 dark:text-red-200">Proposal Declined</flux:heading>
                        <flux:text size="sm" class="text-red-700 dark:text-red-300">
                            This proposal was declined on {{ $estimate->rejected_at?->format('F j, Y \a\t g:i A') }}
                        </flux:text>
                    </div>
                </div>
            </div>
        @elseif ($estimate->isExpired())
            <div class="mb-6 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-4">
                <div class="flex items-center gap-3">
                    <flux:icon name="exclamation-triangle" class="w-6 h-6 text-yellow-600 dark:text-yellow-400" />
                    <div>
                        <flux:heading size="base" class="text-yellow-800 dark:text-yellow-200">Proposal Expired</flux:heading>
                        <flux:text size="sm" class="text-yellow-700 dark:text-yellow-300">
                            This proposal expired on {{ $estimate->valid_until?->format('F j, Y') }}. Please contact us for a new quote.
                        </flux:text>
                    </div>
                </div>
            </div>
        @endif

        {{-- Estimate Header --}}
        <flux:card class="p-6 mb-6">
            <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-4">
                <div>
                    <flux:badge
                        :color="match($estimate->status) {
                            'draft' => 'zinc',
                            'sent' => 'blue',
                            'accepted' => 'green',
                            'rejected' => 'red',
                            default => 'zinc'
                        }"
                        class="mb-3"
                    >
                        {{ $estimate->status_label }}
                    </flux:badge>
                    <flux:heading size="2xl" class="mb-2">{{ $estimate->title ?: 'Painting Proposal' }}</flux:heading>
                    <flux:text class="text-zinc-600 dark:text-zinc-400">
                        Estimate #{{ $estimate->estimate_number }}
                    </flux:text>
                </div>
                <div class="text-left sm:text-right">
                    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">Date</flux:text>
                    <flux:text class="font-medium">{{ $estimate->created_at->format('F j, Y') }}</flux:text>
                    @if ($estimate->valid_until)
                        <div class="mt-2">
                            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">Valid Until</flux:text>
                            <flux:text class="font-medium {{ $estimate->isExpired() ? 'text-red-600 dark:text-red-400' : '' }}">
                                {{ $estimate->valid_until->format('F j, Y') }}
                            </flux:text>
                        </div>
                    @endif
                </div>
            </div>
        </flux:card>

        {{-- Customer Information --}}
        @if ($estimate->deal?->customer)
            <flux:card class="p-6 mb-6">
                <flux:heading size="lg" class="mb-4">Prepared For</flux:heading>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">
                            {{ $estimate->deal->customer->first_name }} {{ $estimate->deal->customer->last_name }}
                        </flux:text>
                        @if ($estimate->deal->customer->email)
                            <flux:text size="sm" class="text-zinc-600 dark:text-zinc-400">
                                {{ $estimate->deal->customer->email }}
                            </flux:text>
                        @endif
                        @if ($estimate->deal->customer->phone)
                            <flux:text size="sm" class="text-zinc-600 dark:text-zinc-400">
                                {{ $estimate->deal->customer->phone }}
                            </flux:text>
                        @endif
                    </div>
                    @if ($estimate->deal->customer->address)
                        <div>
                            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400 mb-1">Property Address</flux:text>
                            <flux:text class="text-zinc-900 dark:text-zinc-100">
                                {{ $estimate->deal->customer->address }}
                                @if ($estimate->deal->customer->city || $estimate->deal->customer->state || $estimate->deal->customer->zip)
                                    <br>
                                    {{ $estimate->deal->customer->city }}@if($estimate->deal->customer->city && $estimate->deal->customer->state), @endif{{ $estimate->deal->customer->state }} {{ $estimate->deal->customer->zip }}
                                @endif
                            </flux:text>
                        </div>
                    @endif
                </div>
            </flux:card>
        @endif

        {{-- Description --}}
        @if ($estimate->description)
            <flux:card class="p-6 mb-6">
                <flux:heading size="lg" class="mb-4">Project Description</flux:heading>
                <flux:text class="whitespace-pre-wrap text-zinc-700 dark:text-zinc-300">{{ $estimate->description }}</flux:text>
            </flux:card>
        @endif

        {{-- Photos Gallery (moved up for visual context before pricing) --}}
        @if ($estimate->photos->isNotEmpty())
            <flux:card class="p-6 mb-6">
                <flux:heading size="lg" class="mb-4">Project Photos</flux:heading>
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4">
                    @foreach ($estimate->photos as $photo)
                        <div wire:key="photo-{{ $photo->id }}" class="relative group aspect-square">
                            <img
                                src="{{ $photo->url }}"
                                alt="{{ $photo->caption ?: 'Project photo' }}"
                                class="w-full h-full object-cover rounded-lg"
                            />
                            @if ($photo->caption)
                                <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 to-transparent p-2 rounded-b-lg">
                                    <flux:text size="sm" class="text-white truncate">{{ $photo->caption }}</flux:text>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </flux:card>
        @endif

        {{-- PACKAGES SECTION (Good/Better/Best) - Primary pricing flow --}}
        @if ($this->hasPackages())
            <div class="mb-6">
                <livewire:public.proposal-packages :estimate="$estimate" :key="'proposal-packages-'.$estimate->id" />
            </div>
        @endif

        {{-- Areas Section (Expandable detail - what's included in the job) --}}
        @if ($estimate->areas->isNotEmpty())
            <flux:card class="p-6 mb-6">
                <div class="flex items-center justify-between mb-4">
                    <flux:heading size="lg">Scope of Work</flux:heading>
                    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                        {{ $estimate->areas->count() }} {{ Str::plural('area', $estimate->areas->count()) }}
                    </flux:text>
                </div>
                <div class="space-y-3">
                    @foreach ($estimate->areas as $area)
                        <div
                            x-data="{ expanded: false }"
                            wire:key="area-{{ $area->id }}"
                            class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden transition-shadow hover:shadow-sm"
                        >
                            {{-- Area Header (Always Visible) --}}
                            <button
                                @click="expanded = !expanded"
                                type="button"
                                class="w-full px-4 py-3 flex items-center justify-between bg-zinc-50 dark:bg-zinc-800/50 hover:bg-zinc-100 dark:hover:bg-zinc-800 transition-colors text-left"
                            >
                                <div class="flex items-center gap-3">
                                    <div
                                        class="flex-shrink-0 w-8 h-8 rounded-lg flex items-center justify-center transition-colors"
                                        :class="expanded ? 'bg-blue-100 dark:bg-blue-900/50' : 'bg-zinc-200 dark:bg-zinc-700'"
                                    >
                                        <flux:icon
                                            name="chevron-right"
                                            class="w-4 h-4 transition-transform duration-200"
                                            ::class="expanded ? 'rotate-90 text-blue-600 dark:text-blue-400' : 'text-zinc-500 dark:text-zinc-400'"
                                        />
                                    </div>
                                    <div>
                                        <flux:heading size="base">{{ $area->name }}</flux:heading>
                                        <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                                            @if ($area->surfaces->where('is_included', true)->count() > 0)
                                                {{ $area->surfaces->where('is_included', true)->count() }} {{ Str::plural('surface', $area->surfaces->where('is_included', true)->count()) }}
                                            @endif
                                            @if ($area->wall_sqft > 0 || $area->ceiling_sqft > 0)
                                                <span class="text-zinc-300 dark:text-zinc-600 mx-1">&bull;</span>
                                                {{ number_format(($area->wall_sqft ?? 0) + ($area->ceiling_sqft ?? 0), 0) }} sq ft
                                            @endif
                                        </flux:text>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <flux:text class="text-lg font-bold text-zinc-900 dark:text-zinc-100">
                                        ${{ number_format($this->getAreaDisplayTotal($area), 2) }}
                                    </flux:text>
                                </div>
                            </button>

                            {{-- Expanded Surface Details --}}
                            <div
                                x-show="expanded"
                                x-collapse
                                class="border-t border-zinc-200 dark:border-zinc-700"
                            >
                                @if ($area->surfaces->where('is_included', true)->isNotEmpty())
                                    <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                        @foreach ($area->surfaces->where('is_included', true) as $surface)
                                            <div class="px-4 py-3 flex items-center justify-between bg-white dark:bg-zinc-900">
                                                <div class="flex items-center gap-3">
                                                    <div class="w-2 h-2 rounded-full bg-blue-400 dark:bg-blue-500"></div>
                                                    <div>
                                                        <flux:text class="text-zinc-700 dark:text-zinc-300">
                                                            {{ $surface->name ?? $surface->productionRate?->name ?? 'Surface' }}
                                                        </flux:text>
                                                        <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                                                            {{ number_format($surface->quantity, 0) }} {{ $surface->unit ?? 'sq ft' }}
                                                            @if ($surface->coats > 1)
                                                                &bull; {{ $surface->coats }} coats
                                                            @endif
                                                        </flux:text>
                                                    </div>
                                                </div>
                                                <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">
                                                    ${{ number_format($this->getSurfaceDisplayTotal($surface), 2) }}
                                                </flux:text>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif

                                @if ($area->notes)
                                    <div class="px-4 py-3 bg-amber-50 dark:bg-amber-900/20 border-t border-zinc-200 dark:border-zinc-700">
                                        <div class="flex items-start gap-2">
                                            <flux:icon name="information-circle" class="w-4 h-4 text-amber-600 dark:text-amber-400 mt-0.5 flex-shrink-0" />
                                            <flux:text size="sm" class="text-amber-800 dark:text-amber-200">
                                                {{ $area->notes }}
                                            </flux:text>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </flux:card>
        @endif

        {{-- Line Items (only shown when NOT using packages - packages already include these) --}}
        @if ($estimate->items->isNotEmpty() && ! $this->hasPackages())
            <flux:card class="p-6 mb-6">
                <flux:heading size="lg" class="mb-4">Line Items</flux:heading>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                <th class="text-left py-3 px-2 text-sm font-medium text-zinc-600 dark:text-zinc-400">Description</th>
                                <th class="text-right py-3 px-2 text-sm font-medium text-zinc-600 dark:text-zinc-400">Qty</th>
                                <th class="text-right py-3 px-2 text-sm font-medium text-zinc-600 dark:text-zinc-400">Unit Price</th>
                                <th class="text-right py-3 px-2 text-sm font-medium text-zinc-600 dark:text-zinc-400">Total</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($estimate->items as $item)
                                <tr>
                                    <td class="py-3 px-2">
                                        <flux:text class="text-zinc-900 dark:text-zinc-100">{{ $item->description }}</flux:text>
                                        @if ($item->details)
                                            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">{{ $item->details }}</flux:text>
                                        @endif
                                    </td>
                                    <td class="py-3 px-2 text-right">
                                        <flux:text class="text-zinc-700 dark:text-zinc-300">
                                            {{ number_format($item->quantity, 2) }} {{ $item->unit }}
                                        </flux:text>
                                    </td>
                                    <td class="py-3 px-2 text-right">
                                        <flux:text class="text-zinc-700 dark:text-zinc-300">
                                            ${{ number_format($item->unit_price, 2) }}
                                        </flux:text>
                                    </td>
                                    <td class="py-3 px-2 text-right">
                                        <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">
                                            ${{ number_format($item->total, 2) }}
                                        </flux:text>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </flux:card>
        @endif

        {{-- Options Section (only shown when NOT using packages) --}}
        @if ($estimate->options->isNotEmpty() && ! $this->hasPackages())
            <flux:card class="p-6 mb-6">
                <flux:heading size="lg" class="mb-2">Optional Add-ons</flux:heading>
                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400 mb-4">
                    Select additional services to customize your project. Prices update in real-time.
                </flux:text>
                <div class="space-y-3">
                    @foreach ($estimate->options as $option)
                        <div
                            wire:key="option-{{ $option->id }}"
                            class="border rounded-lg p-4 transition-all {{ $option->is_selected ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-zinc-200 dark:border-zinc-700' }}"
                        >
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex-1">
                                    <div class="flex items-center gap-2 mb-1">
                                        <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">
                                            {{ $option->name }}
                                        </flux:text>
                                        @if ($option->is_recommended)
                                            <flux:badge color="amber" size="sm">Recommended</flux:badge>
                                        @endif
                                    </div>
                                    @if ($option->description)
                                        <flux:text size="sm" class="text-zinc-600 dark:text-zinc-400">
                                            {{ $option->description }}
                                        </flux:text>
                                    @endif
                                </div>
                                <div class="flex items-center gap-4">
                                    <flux:text class="font-semibold text-zinc-900 dark:text-zinc-100 whitespace-nowrap">
                                        +${{ number_format($option->calculated_price, 2) }}
                                    </flux:text>
                                    @if ($this->canAccept())
                                        <flux:switch
                                            wire:click="toggleOption({{ $option->id }})"
                                            :checked="$option->is_selected"
                                            wire:loading.attr="disabled"
                                        />
                                    @else
                                        <flux:badge :color="$option->is_selected ? 'green' : 'zinc'" size="sm">
                                            {{ $option->is_selected ? 'Included' : 'Not Included' }}
                                        </flux:badge>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </flux:card>
        @endif

        {{-- Pricing Summary (only shown when NOT using packages) --}}
        @if (! $this->hasPackages())
            <flux:card class="p-6 mb-6">
                <flux:heading size="lg" class="mb-4">Pricing Summary</flux:heading>
                <div class="space-y-3">
                    @if ((float) $estimate->areas_subtotal > 0)
                        <div class="flex justify-between items-center">
                            <flux:text class="text-zinc-600 dark:text-zinc-400">Painting Work</flux:text>
                            <flux:text class="text-zinc-900 dark:text-zinc-100">${{ number_format($estimate->areas_subtotal, 2) }}</flux:text>
                        </div>
                    @endif

                    @if ((float) $estimate->items_subtotal > 0)
                        <div class="flex justify-between items-center">
                            <flux:text class="text-zinc-600 dark:text-zinc-400">Line Items</flux:text>
                            <flux:text class="text-zinc-900 dark:text-zinc-100">${{ number_format($estimate->items_subtotal, 2) }}</flux:text>
                        </div>
                    @endif

                    <flux:separator />

                    <div class="flex justify-between items-center">
                        <flux:heading size="lg">Total</flux:heading>
                        <flux:heading size="lg" class="text-blue-600 dark:text-blue-400">${{ number_format($estimate->total, 2) }}</flux:heading>
                    </div>
                </div>
            </flux:card>
        @endif

        {{-- Notes Section --}}
        @if ($estimate->estimateNotes->isNotEmpty())
            <flux:card class="p-6 mb-6">
                <flux:heading size="lg" class="mb-4">Notes</flux:heading>
                <div class="space-y-3">
                    @foreach ($estimate->estimateNotes as $note)
                        <div wire:key="note-{{ $note->id }}" class="bg-zinc-50 dark:bg-zinc-800/50 rounded-lg p-4">
                            <flux:text class="whitespace-pre-wrap text-zinc-700 dark:text-zinc-300">{{ $note->content }}</flux:text>
                            @if ($note->user)
                                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400 mt-2">
                                    - {{ $note->user->name }}, {{ $note->created_at->format('M j, Y') }}
                                </flux:text>
                            @endif
                        </div>
                    @endforeach
                </div>
            </flux:card>
        @endif

        {{-- Terms and Conditions --}}
        @if ($estimate->terms)
            <flux:card class="p-6 mb-6">
                <flux:heading size="lg" class="mb-4">Terms and Conditions</flux:heading>
                <div class="prose prose-sm dark:prose-invert max-w-none">
                    <flux:text class="whitespace-pre-wrap text-zinc-700 dark:text-zinc-300">{{ $estimate->terms }}</flux:text>
                </div>
            </flux:card>
        @endif

        {{-- Signatures Section --}}
        @if ($estimate->createdBy?->internalUser?->hasSignature() || $estimate->signature)
            <flux:card class="p-6 mb-6">
                <flux:heading size="lg" class="mb-4">Signatures</flux:heading>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-8">
                    {{-- Prepared By / Estimator Signature --}}
                    @if ($estimate->createdBy?->internalUser?->hasSignature())
                        <div>
                            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400 mb-3 font-medium uppercase tracking-wider">Prepared By</flux:text>
                            @if ($estimate->createdBy->internalUser->signature_type === 'drawn')
                                <img
                                    src="{{ $estimate->createdBy->internalUser->signature_data }}"
                                    alt="Signature"
                                    class="max-h-20 mb-2"
                                />
                            @else
                                <div
                                    class="text-3xl text-zinc-900 dark:text-white mb-2"
                                    style="font-family: 'Brush Script MT', 'Segoe Script', 'Bradley Hand', cursive;"
                                >
                                    {{ $estimate->createdBy->internalUser->signature_data }}
                                </div>
                            @endif
                            <div class="border-t border-zinc-300 dark:border-zinc-600 pt-2">
                                <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">
                                    {{ $estimate->createdBy->name }}
                                </flux:text>
                                @if ($estimate->createdBy->internalUser->job_title)
                                    <flux:text size="sm" class="text-zinc-600 dark:text-zinc-400">
                                        {{ $estimate->createdBy->internalUser->job_title }}
                                    </flux:text>
                                @endif
                            </div>
                        </div>
                    @endif

                    {{-- Accepted By / Customer Signature --}}
                    @if ($estimate->signature)
                        <div>
                            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400 mb-3 font-medium uppercase tracking-wider">Accepted By</flux:text>
                            @if ($estimate->signature->signature_type === 'drawn')
                                <img
                                    src="{{ $estimate->signature->signature_data }}"
                                    alt="Customer Signature"
                                    class="max-h-20 mb-2"
                                />
                            @else
                                <div
                                    class="text-3xl text-zinc-900 dark:text-white mb-2"
                                    style="font-family: 'Brush Script MT', 'Segoe Script', 'Bradley Hand', cursive;"
                                >
                                    {{ $estimate->signature->signature_data }}
                                </div>
                            @endif
                            <div class="border-t border-zinc-300 dark:border-zinc-600 pt-2">
                                <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">
                                    {{ $estimate->signature->signer_name }}
                                </flux:text>
                                @if ($estimate->signature->signed_at)
                                    <flux:text size="sm" class="text-zinc-600 dark:text-zinc-400">
                                        Signed {{ $estimate->signature->signed_at->format('F j, Y \a\t g:i A') }}
                                    </flux:text>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>
            </flux:card>
        @endif

        {{-- Accept Button (only when NOT using packages - packages have their own Sign & Accept) --}}
        <div class="text-center">
            @if ($this->canAccept() && ! $this->hasPackages())
                <flux:button
                    variant="primary"
                    wire:click="openSignatureModal"
                    class="px-12 py-3 text-base"
                >
                    Accept Proposal
                </flux:button>
                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400 mt-3">
                    By accepting, you agree to the terms and conditions above.
                </flux:text>
            @elseif ($estimate->status === 'accepted')
                <div class="inline-flex items-center gap-2 bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-200 px-6 py-3 rounded-lg">
                    <flux:icon name="check-circle" class="w-5 h-5" />
                    <span class="font-medium">Proposal Accepted</span>
                </div>
            @elseif ($estimate->status === 'rejected')
                <div class="inline-flex items-center gap-2 bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-200 px-6 py-3 rounded-lg">
                    <flux:icon name="x-circle" class="w-5 h-5" />
                    <span class="font-medium">Proposal Declined</span>
                </div>
            @elseif ($estimate->isExpired())
                <div class="inline-flex items-center gap-2 bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-200 px-6 py-3 rounded-lg">
                    <flux:icon name="exclamation-triangle" class="w-5 h-5" />
                    <span class="font-medium">Proposal Expired</span>
                </div>
            @endif
        </div>

        {{-- Include Signature Modal Partial --}}
        @include('partials.public.signature-modal')
    </div>
</div>
