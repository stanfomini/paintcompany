<?php

use App\Events\EstimateOptionsToggled;
use App\Events\EstimateSignatureAdded;
use App\Models\CompanySetting;
use App\Models\Estimate;
use App\Models\EstimateActivity;
use App\Models\EstimateAmendment;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\EstimateSignature;
use App\Models\EstimateView;
use App\Models\VideoRecording;
use App\Services\EstimateAmendmentService;
use App\Services\EstimateCalculationService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Validate;
use Livewire\Component;

new #[Layout('components.layouts.public')] class extends Component {
    public Estimate $estimate;

    public string $token = '';

    public bool $showSignatureModal = false;

    public ?int $selectedPhotoId = null;

    /** @var int|null The currently selected legacy package ID for filtering Scope of Work */
    public ?int $selectedPackageId = null;

    /** @var array<int, array<int, float>> Cache of proportional surface prices per area */
    protected array $surfacePriceCache = [];

    /** @var array<int, array<int, int[]>>|null packageId => [areaId => [surfaceId, ...]] */
    protected ?array $packageScopeMap = null;

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

    public string $amendmentInitials = '';


    public function mount(string $token): void
    {
        $estimate = Estimate::byPublicToken($token)
            ->with([
                'deal.customer',
                'deal.agent',
                'deal.property',
                'items',
                'areas.surfaces.productionRate',
                'options.serviceCategory',
                'packages.items.itemable',
                'photos' => fn ($q) => $q->with(['annotations', 'comments.user', 'comments.replies.user'])->orderBy('sort_order'),
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

        // Build package scope map for filtering Scope of Work
        $this->buildPackageScopeMap();

        // Auto-select the pre-selected or recommended package
        if ($estimate->selected_package_id) {
            $this->selectedPackageId = $estimate->selected_package_id;
        } elseif ($estimate->packages->isNotEmpty()) {
            $recommended = $estimate->packages->firstWhere('is_recommended', true);
            $this->selectedPackageId = $recommended?->id ?? $estimate->packages->first()->id;
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

    public function getListeners(): array
    {
        return [
            "echo-private:estimate.{$this->estimate->id},.estimate.status.changed" => 'refreshProposal',
            "echo-private:estimate.{$this->estimate->id},.estimate.area.updated" => 'refreshProposal',
            "echo-private:estimate.{$this->estimate->id},.estimate.note.added" => 'refreshProposal',
        ];
    }

    public function refreshProposal(): void
    {
        $this->estimate = $this->estimate->fresh([
            'deal.customer',
            'deal.agent',
            'deal.property',
            'items',
            'areas.surfaces.productionRate',
            'options.serviceCategory',
            'packages.items.itemable',
            'photos' => fn ($q) => $q->with(['annotations', 'comments.user', 'comments.replies.user'])->orderBy('sort_order'),
            'estimateNotes' => fn ($q) => $q->external()->rootNotes()->with('user'),
            'signature',
            'createdBy.internalUser',
        ]);
        $this->surfacePriceCache = [];
        $this->buildPackageScopeMap();
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
            'options.serviceCategory',
            'packages',
            'photos' => fn ($q) => $q->with(['annotations', 'comments.user', 'comments.replies.user'])->orderBy('sort_order'),
            'estimateNotes' => fn ($q) => $q->external()->rootNotes()->with('user'),
            'signature',
            'createdBy.internalUser',
        ]);

        $customerName = $this->estimate->deal?->customer?->full_name ?? $this->signerName ?: 'Customer';
        broadcast(new EstimateOptionsToggled(
            $this->estimate->id,
            $optionId,
            $option->is_selected,
            $customerName
        ))->toOthers();
    }

    public function openPhotoLightbox(int $id): void
    {
        $this->selectedPhotoId = $id;
    }

    #[On('close-lightbox')]
    public function closePhotoLightbox(): void
    {
        $this->selectedPhotoId = null;
    }

    #[On('amendment-acknowledged')]
    public function refreshAfterAmendment(): void
    {
        $this->estimate->refresh();
    }

    public function openSignatureModal(): void
    {
        // Validate estimate can be accepted
        if ($this->estimate->status === 'accepted') {
            session()->flash('error', __('This proposal has already been accepted.'));

            return;
        }

        if ($this->estimate->status === 'rejected') {
            session()->flash('error', __('This proposal has been rejected and cannot be accepted.'));

            return;
        }

        if ($this->estimate->isExpired()) {
            session()->flash('error', __('This proposal has expired and cannot be accepted.'));

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

        broadcast(new EstimateSignatureAdded(
            $this->estimate,
            $this->signerName,
            now()->toIso8601String()
        ))->toOthers();

        // Close modal and refresh
        $this->showSignatureModal = false;

        // Refresh the estimate to show accepted state
        $this->estimate = $this->estimate->fresh([
            'deal.customer',
            'items',
            'areas.surfaces.productionRate',
            'options.serviceCategory',
            'photos' => fn ($q) => $q->orderBy('sort_order'),
            'estimateNotes' => fn ($q) => $q->external()->rootNotes()->with('user'),
            'signature',
        ]);

        session()->flash('success', __('Thank you! Your proposal has been accepted.'));
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
     * Get a surface's customer-facing price (proportionally allocated from area total).
     * Ensures surface prices sum exactly to the area customer price.
     */
    public function getSurfaceDisplayTotal(object $surface): float
    {
        if ($surface instanceof \App\Models\EstimateAreaSurface && $surface->area) {
            // Use proportional allocation from area's customer price
            if (! isset($this->surfacePriceCache[$surface->area_id])) {
                $this->surfacePriceCache[$surface->area_id] = $surface->area->getSurfaceCustomerPrices();
            }

            return $this->surfacePriceCache[$surface->area_id][$surface->id] ?? $surface->getCustomerPrice();
        }

        // Fallback for non-model objects
        return (float) ($surface->total ?? 0) * $this->getMarginFactor();
    }

    #[Computed]
    public function canSubmitSignature(): bool
    {
        return $this->acceptedTerms && !empty($this->signatureData);
    }

    #[Computed]
    public function proposalVideos()
    {
        return VideoRecording::where('recordable_type', Estimate::class)
            ->where('recordable_id', $this->estimate->id)
            ->forProposal()
            ->orderByDesc('created_at')
            ->get();
    }

    public function canAccept(): bool
    {
        return in_array($this->estimate->status, ['sent', 'draft'])
            && ! $this->estimate->isExpired()
            && ! $this->estimate->hasSignature()
            && ! $this->estimate->hasPendingAmendments();
    }

    #[On('package-selected')]
    public function handlePackageSelected(int $packageId): void
    {
        $this->selectedPackageId = $packageId;
        $this->surfacePriceCache = [];
    }

    public function acknowledgeAllAmendments(): void
    {
        $this->validate([
            'amendmentInitials' => 'required|string|min:2|max:10',
        ], [
            'amendmentInitials.required' => 'Please enter your initials.',
            'amendmentInitials.min' => 'Initials must be at least 2 characters.',
        ]);

        $service = app(EstimateAmendmentService::class);
        $initials = strtoupper($this->amendmentInitials);
        $ip = request()->ip();

        $pendingAmendments = $this->estimate->amendments()
            ->whereNull('customer_acknowledged_at')
            ->orderBy('amendment_number')
            ->get();

        foreach ($pendingAmendments as $amendment) {
            $service->acknowledgeAmendment($amendment, $initials, $ip);
        }

        $this->estimate->refresh();
        $this->amendmentInitials = '';
        $this->dispatch('amendments-acknowledged');
    }

    /**
     * Get the package scope map, rebuilding it if needed.
     * Protected properties don't survive Livewire's dehydration/hydration,
     * so we rebuild lazily on each request.
     *
     * @return array<int, array<int, int[]>>
     */
    protected function getPackageScopeMap(): array
    {
        if ($this->packageScopeMap === null) {
            $this->buildPackageScopeMap();
        }

        return $this->packageScopeMap;
    }

    /**
     * Build a mapping of package IDs to their included area/surface IDs.
     * Structure: [packageId => [areaId => [surfaceId1, surfaceId2, ...]]]
     * An area with an empty surface array means the whole area is included.
     */
    protected function buildPackageScopeMap(): void
    {
        $this->packageScopeMap = [];

        foreach ($this->estimate->packages as $package) {
            $areaMap = [];

            foreach ($package->items as $item) {
                if (! $item->is_included || ! $item->customer_approved) {
                    continue;
                }

                if ($item->itemable_type === EstimateArea::class) {
                    // Entire area is included — use empty array as marker
                    if (! isset($areaMap[$item->itemable_id])) {
                        $areaMap[$item->itemable_id] = [];
                    }
                } elseif ($item->itemable_type === EstimateAreaSurface::class) {
                    $surface = $item->itemable;
                    if ($surface && $surface->area_id) {
                        if (! isset($areaMap[$surface->area_id])) {
                            $areaMap[$surface->area_id] = [];
                        }
                        $areaMap[$surface->area_id][] = $surface->id;
                    }
                }
            }

            $this->packageScopeMap[$package->id] = $areaMap;
        }
    }

    /**
     * Get the areas to display in Scope of Work, filtered by selected package.
     *
     * @return \Illuminate\Support\Collection<int, EstimateArea>
     */
    public function getFilteredAreas(): \Illuminate\Support\Collection
    {
        $areas = $this->estimate->areas;

        $scopeMap = $this->getPackageScopeMap();

        if (! $this->selectedPackageId || empty($scopeMap)) {
            return $areas;
        }

        $packageScope = $scopeMap[$this->selectedPackageId] ?? null;

        if ($packageScope === null) {
            return $areas;
        }

        return $areas->filter(fn ($area) => isset($packageScope[$area->id]));
    }

    /**
     * Get the included surface IDs for an area in the selected package.
     * Returns null if all surfaces should be shown (whole area included or no filtering).
     *
     * @return array<int>|null
     */
    public function getPackageSurfaceIds(int $areaId): ?array
    {
        $scopeMap = $this->getPackageScopeMap();

        if (! $this->selectedPackageId || empty($scopeMap)) {
            return null;
        }

        $packageScope = $scopeMap[$this->selectedPackageId] ?? null;
        if ($packageScope === null || ! isset($packageScope[$areaId])) {
            return null;
        }

        $surfaceIds = $packageScope[$areaId];

        // Empty array means entire area was added (EstimateArea itemable), show all surfaces
        if (empty($surfaceIds)) {
            return null;
        }

        return $surfaceIds;
    }

    /**
     * Get the area display total for the selected package context.
     * If only some surfaces are included, uses getPartialCustomerPrice.
     */
    public function getPackageAreaDisplayTotal(object $area): float
    {
        if (! $this->selectedPackageId || ! ($area instanceof EstimateArea)) {
            return $this->getAreaDisplayTotal($area);
        }

        $surfaceIds = $this->getPackageSurfaceIds($area->id);

        if ($surfaceIds === null) {
            return $this->getAreaDisplayTotal($area);
        }

        return $area->getPartialCustomerPrice($surfaceIds);
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
                        <flux:heading size="base" class="text-green-800 dark:text-green-200">{{ __('Proposal Accepted') }}</flux:heading>
                        <flux:text size="sm" class="text-green-700 dark:text-green-300">
                            {{ __('This proposal was accepted on :date', ['date' => $estimate->accepted_at?->format('F j, Y \a\t g:i A')]) }}
                        </flux:text>
                    </div>
                </div>
            </div>
        @elseif ($estimate->status === 'rejected')
            <div class="mb-6 bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-4">
                <div class="flex items-center gap-3">
                    <flux:icon name="x-circle" class="w-6 h-6 text-red-600 dark:text-red-400" />
                    <div>
                        <flux:heading size="base" class="text-red-800 dark:text-red-200">{{ __('Proposal Declined') }}</flux:heading>
                        <flux:text size="sm" class="text-red-700 dark:text-red-300">
                            {{ __('This proposal was declined on :date', ['date' => $estimate->rejected_at?->format('F j, Y \a\t g:i A')]) }}
                        </flux:text>
                    </div>
                </div>
            </div>
        @elseif ($estimate->isExpired())
            <div class="mb-6 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-4">
                <div class="flex items-center gap-3">
                    <flux:icon name="exclamation-triangle" class="w-6 h-6 text-yellow-600 dark:text-yellow-400" />
                    <div>
                        <flux:heading size="base" class="text-yellow-800 dark:text-yellow-200">{{ __('Proposal Expired') }}</flux:heading>
                        <flux:text size="sm" class="text-yellow-700 dark:text-yellow-300">
                            {{ __('This proposal expired on :date. Please contact us for a new quote.', ['date' => $estimate->valid_until?->format('F j, Y')]) }}
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
                    <flux:heading size="2xl" class="mb-2">{{ $estimate->title ?: __('Painting Proposal') }}</flux:heading>
                    <flux:text class="text-zinc-600 dark:text-zinc-400">
                        {{ __('Estimate #:number', ['number' => $estimate->estimate_number]) }}
                    </flux:text>
                </div>
                <div class="text-left sm:text-right">
                    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">{{ __('Date') }}</flux:text>
                    <flux:text class="font-medium">{{ $estimate->created_at->format('F j, Y') }}</flux:text>
                    @if ($estimate->valid_until)
                        <div class="mt-2">
                            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">{{ __('Valid Until') }}</flux:text>
                            <flux:text class="font-medium {{ $estimate->isExpired() ? 'text-red-600 dark:text-red-400' : '' }}">
                                {{ $estimate->valid_until->format('F j, Y') }}
                            </flux:text>
                        </div>
                    @endif
                </div>
            </div>
        </flux:card>

        {{-- Customer Information --}}
        @php
            $isAuthenticated = auth('web')->check() || auth('customer')->check() || auth('agent')->check();
        @endphp
        @if ($estimate->deal?->customer)
            <flux:card class="p-6 mb-6">
                <div class="flex items-center justify-between mb-4">
                    <flux:heading size="lg">{{ __('Prepared For') }}</flux:heading>
                    @if ($estimate->deal->customer && ! auth('customer')->check())
                        <flux:button variant="subtle" size="sm" icon="arrow-right-start-on-rectangle" :href="route('customer.login')">
                            {{ __('Customer Sign In') }}
                        </flux:button>
                    @elseif (! $estimate->deal->customer && $estimate->deal->agent && ! auth('agent')->check())
                        <flux:button variant="subtle" size="sm" icon="arrow-right-start-on-rectangle" :href="route('agent.login')">
                            {{ __('Agent Sign In') }}
                        </flux:button>
                    @endif
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">
                            {{ $estimate->deal->customer->first_name }} {{ $estimate->deal->customer->last_name }}
                        </flux:text>
                        @if ($estimate->deal->customer->email)
                            @if ($isAuthenticated)
                                <flux:text size="sm" class="text-zinc-600 dark:text-zinc-400">
                                    {{ $estimate->deal->customer->email }}
                                </flux:text>
                            @else
                                <flux:text size="sm" class="text-zinc-600 dark:text-zinc-400 blur-sm select-none" aria-hidden="true">
                                    {{ preg_replace('/[^@.]/', '*', $estimate->deal->customer->email) }}
                                </flux:text>
                            @endif
                        @endif
                        @if ($estimate->deal->customer->phone)
                            @if ($isAuthenticated)
                                <flux:text size="sm" class="text-zinc-600 dark:text-zinc-400">
                                    {{ $estimate->deal->customer->phone }}
                                </flux:text>
                            @else
                                <flux:text size="sm" class="text-zinc-600 dark:text-zinc-400 blur-sm select-none" aria-hidden="true">
                                    {{ preg_replace('/\d/', '*', $estimate->deal->customer->phone) }}
                                </flux:text>
                            @endif
                        @endif
                    </div>
                    @if ($estimate->deal->customer->address)
                        <div>
                            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400 mb-1">{{ __('Property Address') }}</flux:text>
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
        @elseif ($estimate->deal?->agent)
            <flux:card class="p-6 mb-6">
                <div class="flex items-center justify-between">
                    <flux:heading size="lg">{{ __('Prepared For') }}</flux:heading>
                    @if (! auth('agent')->check())
                        <flux:button variant="subtle" size="sm" icon="arrow-right-start-on-rectangle" :href="route('agent.login')">
                            {{ __('Agent Sign In') }}
                        </flux:button>
                    @endif
                </div>
            </flux:card>
        @endif

        {{-- Description --}}
        @if ($estimate->description)
            <flux:card class="p-6 mb-6">
                <flux:heading size="lg" class="mb-4">{{ __('Project Description') }}</flux:heading>
                <flux:text class="whitespace-pre-wrap text-zinc-700 dark:text-zinc-300">{{ $estimate->description }}</flux:text>
            </flux:card>
        @endif

        {{-- Photos Gallery (moved up for visual context before pricing) --}}
        @php
            // Gather proposal photos: estimate-linked (filtered) + property-level
            $proposalPhotos = $estimate->photos->where('show_on_proposal', true);
            if ($estimate->deal?->property) {
                $propertyPhotos = \App\Models\PropertyPhoto::where('property_id', $estimate->deal->property->id)
                    ->where('show_on_proposal', true)
                    ->whereNull('estimate_id')
                    ->with(['annotations', 'comments.user', 'comments.replies.user'])
                    ->orderBy('sort_order')
                    ->get();
                $proposalPhotos = $proposalPhotos->merge($propertyPhotos)->unique('id');
            }
        @endphp
        @if ($proposalPhotos->isNotEmpty())
            <flux:card class="p-6 mb-6">
                <flux:heading size="lg" class="mb-4">{{ __('Project Photos') }}</flux:heading>
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4">
                    @foreach ($proposalPhotos as $photo)
                        <div
                            wire:key="photo-{{ $photo->id }}"
                            wire:click="openPhotoLightbox({{ $photo->id }})"
                            class="relative group aspect-square cursor-pointer"
                        >
                            <img
                                src="{{ $photo->display_url }}"
                                alt="{{ $photo->caption ?: __('Project photo') }}"
                                class="w-full h-full object-cover rounded-lg transition-transform group-hover:scale-[1.02]"
                                loading="lazy"
                            />
                            {{-- Hover overlay --}}
                            <div class="absolute inset-0 bg-black/0 group-hover:bg-black/20 rounded-lg transition-colors flex items-center justify-center">
                                <svg class="w-8 h-8 text-white opacity-0 group-hover:opacity-100 transition-opacity drop-shadow-lg" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607zM10.5 7.5v6m3-3h-6" />
                                </svg>
                            </div>
                            @if ($photo->caption)
                                <div class="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/70 to-transparent p-2 rounded-b-lg">
                                    <flux:text size="sm" class="text-white truncate">{{ $photo->caption }}</flux:text>
                                </div>
                            @endif
                            @if ($photo->has_annotations && $photo->annotated_path)
                                <div class="absolute top-2 right-2 bg-white/80 rounded-full p-1">
                                    <svg class="w-3.5 h-3.5 text-zinc-700" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />
                                    </svg>
                                </div>
                            @endif
                            @if ($photo->comments->count() > 0)
                                <div class="absolute top-2 left-2 flex items-center gap-1 bg-white/80 rounded-full px-2 py-0.5 text-xs font-medium text-zinc-700">
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z" />
                                    </svg>
                                    {{ $photo->comments->count() }}
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </flux:card>

            {{-- Photo Lightbox --}}
            @if ($selectedPhotoId)
                @php
                    $selectedPhoto = $proposalPhotos->firstWhere('id', $selectedPhotoId);
                @endphp
                @if ($selectedPhoto)
                    <livewire:shared.photo-lightbox
                        :photo="$selectedPhoto"
                        :photos="$proposalPhotos"
                        :can-annotate="false"
                        :can-comment="true"
                        :can-delete="false"
                        wire:key="lightbox-{{ $selectedPhotoId }}"
                    />
                @endif
            @endif
        @endif

        {{-- Video Walkarounds (visible on proposal) --}}
        @if ($this->proposalVideos->isNotEmpty())
            <flux:card class="p-6 mb-6">
                <flux:heading size="lg" class="mb-4">{{ __('Property Walkaround') }}</flux:heading>
                <div class="space-y-3">
                    @foreach ($this->proposalVideos as $video)
                        <div
                            wire:key="proposal-video-{{ $video->id }}"
                            x-data="{ playing: false }"
                            class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden"
                        >
                            <div class="flex items-start gap-4 p-3">
                                {{-- Thumbnail with play overlay --}}
                                <div class="relative w-32 aspect-video shrink-0 rounded-md overflow-hidden bg-zinc-200 dark:bg-zinc-700 cursor-pointer" @click="playing = !playing">
                                    @if ($video->getThumbnailUrl())
                                        <img src="{{ $video->getThumbnailUrl() }}" alt="" class="w-full h-full object-cover" loading="lazy" />
                                    @else
                                        <div class="w-full h-full flex items-center justify-center">
                                            <svg class="w-8 h-8 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>
                                        </div>
                                    @endif
                                    <div class="absolute inset-0 flex items-center justify-center bg-black/30 hover:bg-black/40 transition-colors">
                                        <svg x-show="!playing" class="w-8 h-8 text-white" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"></path></svg>
                                        <svg x-show="playing" class="w-6 h-6 text-white" fill="currentColor" viewBox="0 0 24 24"><path d="M6 4h4v16H6V4zm8 0h4v16h-4V4z"></path></svg>
                                    </div>
                                    @if ($video->formatted_duration)
                                        <div class="absolute bottom-1 right-1 px-1.5 py-0.5 rounded bg-black/70 text-white text-xs font-medium">
                                            {{ $video->formatted_duration }}
                                        </div>
                                    @endif
                                </div>

                                {{-- Info --}}
                                <div class="flex-1 min-w-0">
                                    <flux:text class="font-medium text-sm">
                                        {{ $video->title ?: __('Property Walkaround') }}
                                    </flux:text>
                                    <flux:text class="text-xs text-zinc-500 mt-0.5">
                                        {{ $video->created_at->diffForHumans() }}
                                        @if ($video->formatted_duration)
                                            &bull; {{ $video->formatted_duration }}
                                        @endif
                                    </flux:text>
                                </div>
                            </div>

                            {{-- Inline video player --}}
                            <div x-show="playing" x-collapse>
                                <div class="border-t border-zinc-200 dark:border-zinc-700">
                                    <video
                                        x-init="$watch('playing', function(v) { if(v) { $el.play() } else { $el.pause(); $el.currentTime = 0 } })"
                                        @click="$el.paused ? $el.play() : $el.pause()"
                                        controls
                                        playsinline
                                        preload="metadata"
                                        class="w-full"
                                        src="{{ $video->getVideoUrl() }}"
                                    ></video>
                                </div>
                            </div>
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

        {{-- Areas Section (Expandable detail - filtered by selected package) --}}
        @php
            $filteredAreas = $this->getFilteredAreas();
        @endphp
        @if ($filteredAreas->isNotEmpty())
            <flux:card class="p-6 mb-6">
                <div class="flex items-center justify-between mb-4">
                    <flux:heading size="lg">{{ __('Scope of Work') }}</flux:heading>
                    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                        {{ $filteredAreas->count() }} {{ Str::plural('area', $filteredAreas->count()) }}
                    </flux:text>
                </div>
                <div class="space-y-3">
                    @foreach ($filteredAreas as $area)
                        @php
                            $packageSurfaceIds = $this->getPackageSurfaceIds($area->id);
                            $visibleSurfaces = $packageSurfaceIds === null
                                ? $area->surfaces->where('is_included', true)
                                : $area->surfaces->where('is_included', true)->whereIn('id', $packageSurfaceIds);
                        @endphp
                        <div
                            x-data="{ expanded: false }"
                            wire:key="area-{{ $area->id }}-pkg-{{ $this->selectedPackageId }}"
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
                                            @if ($visibleSurfaces->count() > 0)
                                                {{ trans_choice(':count surface|:count surfaces', $visibleSurfaces->count()) }}
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
                                        ${{ number_format($this->getPackageAreaDisplayTotal($area), 2) }}
                                    </flux:text>
                                </div>
                            </button>

                            {{-- Expanded Surface Details --}}
                            <div
                                x-show="expanded"
                                x-collapse
                                class="border-t border-zinc-200 dark:border-zinc-700"
                            >
                                @if ($visibleSurfaces->isNotEmpty())
                                    <div class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                        @foreach ($visibleSurfaces as $surface)
                                            <div class="px-4 py-3 flex items-center justify-between bg-white dark:bg-zinc-900">
                                                <div class="flex items-center gap-3">
                                                    <div class="w-2 h-2 rounded-full bg-blue-400 dark:bg-blue-500"></div>
                                                    <div>
                                                        <flux:text class="text-zinc-700 dark:text-zinc-300">
                                                            {{ $surface->name ?? $surface->productionRate?->name ?? __('Surface') }}
                                                        </flux:text>
                                                        <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                                                            {{ number_format($surface->quantity, 0) }} {{ $surface->unit ?? 'sq ft' }}
                                                            @if ($surface->coats > 1)
                                                                &bull; {{ __(':count coats', ['count' => $surface->coats]) }}
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
                <flux:heading size="lg" class="mb-4">{{ __('Line Items') }}</flux:heading>
                <div class="overflow-x-auto">
                    <table class="w-full">
                        <thead>
                            <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                <th class="text-left py-3 px-2 text-sm font-medium text-zinc-600 dark:text-zinc-400">{{ __('Description') }}</th>
                                <th class="text-right py-3 px-2 text-sm font-medium text-zinc-600 dark:text-zinc-400">{{ __('Qty') }}</th>
                                <th class="text-right py-3 px-2 text-sm font-medium text-zinc-600 dark:text-zinc-400">{{ __('Unit Price') }}</th>
                                <th class="text-right py-3 px-2 text-sm font-medium text-zinc-600 dark:text-zinc-400">{{ __('Total') }}</th>
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
                <flux:heading size="lg" class="mb-2">{{ __('Optional Add-ons') }}</flux:heading>
                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400 mb-4">
                    {{ __('Select additional services to customize your project. Prices update in real-time.') }}
                </flux:text>
                @php
                    $optionsByCategory = $estimate->options->groupBy(fn($o) => __($o->serviceCategory?->name ?? 'Painting'));
                    $showCategoryHeaders = $optionsByCategory->count() > 1;
                @endphp
                <div class="space-y-3">
                    @foreach ($optionsByCategory as $categoryName => $categoryOptions)
                        @if ($showCategoryHeaders)
                            <div class="flex items-center gap-2 pt-2 first:pt-0">
                                <flux:badge size="sm">{{ $categoryName }}</flux:badge>
                                <div class="flex-1 border-t border-zinc-200 dark:border-zinc-700"></div>
                            </div>
                        @endif
                        @foreach ($categoryOptions as $option)
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
                                                <flux:badge color="amber" size="sm">{{ __('Recommended') }}</flux:badge>
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
                                                {{ $option->is_selected ? __('Included') : __('Not Included') }}
                                            </flux:badge>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    @endforeach
                </div>
            </flux:card>
        @endif

        {{-- Pricing Summary (only shown when NOT using packages) --}}
        @if (! $this->hasPackages())
            <flux:card class="p-6 mb-6">
                <flux:heading size="lg" class="mb-4">{{ __('Pricing Summary') }}</flux:heading>
                <div class="space-y-3">
                    @if ((float) $estimate->areas_subtotal > 0)
                        <div class="flex justify-between items-center">
                            <flux:text class="text-zinc-600 dark:text-zinc-400">{{ __('Painting Work') }}</flux:text>
                            <flux:text class="text-zinc-900 dark:text-zinc-100">${{ number_format($estimate->areas_subtotal, 2) }}</flux:text>
                        </div>
                    @endif

                    @if ((float) $estimate->items_subtotal > 0)
                        <div class="flex justify-between items-center">
                            <flux:text class="text-zinc-600 dark:text-zinc-400">{{ __('Line Items') }}</flux:text>
                            <flux:text class="text-zinc-900 dark:text-zinc-100">${{ number_format($estimate->items_subtotal, 2) }}</flux:text>
                        </div>
                    @endif

                    <flux:separator />

                    <div class="flex justify-between items-center">
                        <flux:heading size="lg">{{ __('Total') }}</flux:heading>
                        <flux:heading size="lg" class="text-blue-600 dark:text-blue-400">${{ number_format($estimate->total, 2) }}</flux:heading>
                    </div>
                </div>
            </flux:card>
        @endif

        {{-- Notes Section --}}
        @if ($estimate->estimateNotes->isNotEmpty())
            <flux:card class="p-6 mb-6">
                <flux:heading size="lg" class="mb-4">{{ __('Notes') }}</flux:heading>
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

        {{-- Legal Links --}}
        <div class="mb-6 text-center">
            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                <a href="/terms" target="_blank" class="text-blue-600 dark:text-blue-400 hover:underline">{{ __('Terms & Conditions') }}</a>
                <span class="mx-2">&middot;</span>
                <a href="/privacy" target="_blank" class="text-blue-600 dark:text-blue-400 hover:underline">{{ __('Privacy Policy') }}</a>
            </flux:text>
        </div>

        {{-- Signatures Section --}}
        @if ($estimate->createdBy?->internalUser?->hasSignature() || $estimate->signature)
            <flux:card class="p-6 mb-6">
                <flux:heading size="lg" class="mb-4">{{ __('Signatures') }}</flux:heading>
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-8">
                    {{-- Prepared By / Estimator Signature --}}
                    @if ($estimate->createdBy?->internalUser?->hasSignature())
                        <div>
                            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400 mb-3 font-medium uppercase tracking-wider">{{ __('Prepared By') }}</flux:text>
                            @if ($estimate->createdBy->internalUser->signature_type === 'drawn')
                                <img
                                    src="{{ $estimate->createdBy->internalUser->signature_data }}"
                                    alt="{{ __('Signature') }}"
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
                            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400 mb-3 font-medium uppercase tracking-wider">{{ __('Accepted By') }}</flux:text>
                            @if ($estimate->signature->signature_type === 'drawn')
                                <img
                                    src="{{ $estimate->signature->signature_data }}"
                                    alt="{{ __('Customer Signature') }}"
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
                                        {{ __('Signed :date', ['date' => $estimate->signature->signed_at->format('F j, Y \a\t g:i A')]) }}
                                    </flux:text>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>
            </flux:card>
        @endif

        {{-- Pending Amendments Section --}}
        @php
            $pendingAmendments = $estimate->amendments()->whereNull('customer_acknowledged_at')->orderBy('amendment_number')->get();
            $acknowledgedAmendments = $estimate->amendments()->whereNotNull('customer_acknowledged_at')->orderByDesc('amendment_number')->get();
        @endphp

        @if ($pendingAmendments->isNotEmpty())
            <flux:card id="amendments-section" class="p-6 mb-6 border-amber-200 dark:border-amber-800 bg-amber-50/50 dark:bg-amber-900/10">
                <div class="flex items-center gap-2 mb-4">
                    <flux:icon name="exclamation-triangle" class="w-6 h-6 text-amber-600" />
                    <flux:heading size="lg" class="text-amber-800 dark:text-amber-200">{{ __('Action Required: Review Changes') }}</flux:heading>
                </div>
                <flux:text size="sm" class="text-amber-700 dark:text-amber-300 mb-4">
                    {{ __('This estimate has been updated since it was originally sent.') }}
                    {{ __('Please review the :count :label below and enter your initials to acknowledge.', ['count' => $pendingAmendments->count(), 'label' => Str::plural('change', $pendingAmendments->count())]) }}
                </flux:text>
                <div class="space-y-4">
                    @foreach ($pendingAmendments as $pendingAmendment)
                        <livewire:public.amendment-acknowledgment
                            :amendment="$pendingAmendment"
                            :key="'amendment-ack-'.$pendingAmendment->id"
                        />
                    @endforeach
                </div>

                {{-- Single acknowledgment form for all pending amendments --}}
                <div class="mt-6 pt-4 border-t border-amber-300 dark:border-amber-700">
                    <flux:text size="sm" class="text-amber-800 dark:text-amber-200 font-medium mb-3">
                        {{ __('By entering your initials below, you acknowledge all :count :label listed above.', ['count' => $pendingAmendments->count(), 'label' => Str::plural('change', $pendingAmendments->count())]) }}
                    </flux:text>
                    <div class="flex items-center gap-3">
                        <flux:input
                            wire:model="amendmentInitials"
                            :placeholder="__('Your initials')"
                            class="w-28 uppercase"
                            maxlength="10"
                        />
                        <flux:button
                            wire:click="acknowledgeAllAmendments"
                            variant="primary"
                            wire:loading.attr="disabled"
                        >
                            <span wire:loading.remove wire:target="acknowledgeAllAmendments">{{ __('I Acknowledge All Changes') }}</span>
                            <span wire:loading wire:target="acknowledgeAllAmendments">{{ __('Acknowledging...') }}</span>
                        </flux:button>
                    </div>
                    @error('amendmentInitials')
                        <flux:text size="sm" class="text-red-500 mt-1">{{ $message }}</flux:text>
                    @enderror
                </div>
            </flux:card>
        @endif

        @if ($acknowledgedAmendments->isNotEmpty())
            <flux:card class="p-6 mb-6">
                <details>
                    <summary class="cursor-pointer">
                        <flux:heading size="sm" class="inline">{{ __('Change History (:count)', ['count' => $acknowledgedAmendments->count()]) }}</flux:heading>
                    </summary>
                    <div class="mt-4 space-y-3">
                        @foreach ($acknowledgedAmendments as $ackedAmendment)
                            <div class="border border-green-200 dark:border-green-800 rounded-lg p-3 bg-green-50/50 dark:bg-green-900/10">
                                <div class="flex items-center justify-between mb-2">
                                    <flux:text size="sm" class="font-medium">{{ __('Amendment #:number', ['number' => $ackedAmendment->amendment_number]) }}</flux:text>
                                    <flux:badge size="sm" color="green">
                                        {{ __('Acknowledged (:initials)', ['initials' => $ackedAmendment->customer_initials]) }}
                                    </flux:badge>
                                </div>
                                <div class="space-y-1">
                                    @foreach ($ackedAmendment->changes as $change)
                                        <flux:text size="sm" class="text-zinc-600 dark:text-zinc-400">
                                            @if (isset($change['field'], $change['old_value'], $change['new_value']))
                                                {{ $change['label'] }}: {{ $change['field'] }}
                                                {{ $change['old_value'] }} &rarr; {{ $change['new_value'] }}
                                            @else
                                                {{ $change['details'] ?? $change['label'] ?? __('Change') }}
                                            @endif
                                        </flux:text>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </details>
            </flux:card>
        @endif

        {{-- Accept Button (only when NOT using packages - packages have their own Sign & Accept) --}}
        <div class="text-center">
            @if ($estimate->hasPendingAmendments())
                <button
                    disabled
                    class="inline-flex items-center justify-center gap-2 px-12 py-3 text-base bg-gray-300 dark:bg-gray-700 text-gray-500 dark:text-gray-400 font-semibold rounded-xl cursor-not-allowed mb-3"
                >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"></path>
                    </svg>
                    {{ __('Accept Proposal') }}
                </button>
                <div>
                    <a
                        href="#amendments-section"
                        class="inline-flex items-center gap-2 text-amber-700 dark:text-amber-400 font-medium hover:underline text-sm"
                    >
                        <flux:icon name="exclamation-triangle" class="w-4 h-4" />
                        {{ __('Review and acknowledge changes made to this estimate before signing') }}
                    </a>
                </div>
            @elseif ($this->canAccept() && ! $this->hasPackages())
                <flux:button
                    variant="primary"
                    wire:click="openSignatureModal"
                    class="px-12 py-3 text-base"
                >
                    {{ __('Accept Proposal') }}
                </flux:button>
                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400 mt-3">
                    {!! __('By accepting, you agree to the <a href="/terms" target="_blank" class="text-blue-600 dark:text-blue-400 hover:underline">Terms & Conditions</a> and <a href="/privacy" target="_blank" class="text-blue-600 dark:text-blue-400 hover:underline">Privacy Policy</a>.') !!}
                </flux:text>
            @elseif ($estimate->status === 'accepted')
                <div class="inline-flex items-center gap-2 bg-green-100 dark:bg-green-900/30 text-green-800 dark:text-green-200 px-6 py-3 rounded-lg">
                    <flux:icon name="check-circle" class="w-5 h-5" />
                    <span class="font-medium">{{ __('Proposal Accepted') }}</span>
                </div>
            @elseif ($estimate->status === 'rejected')
                <div class="inline-flex items-center gap-2 bg-red-100 dark:bg-red-900/30 text-red-800 dark:text-red-200 px-6 py-3 rounded-lg">
                    <flux:icon name="x-circle" class="w-5 h-5" />
                    <span class="font-medium">{{ __('Proposal Declined') }}</span>
                </div>
            @elseif ($estimate->isExpired())
                <div class="inline-flex items-center gap-2 bg-yellow-100 dark:bg-yellow-900/30 text-yellow-800 dark:text-yellow-200 px-6 py-3 rounded-lg">
                    <flux:icon name="exclamation-triangle" class="w-5 h-5" />
                    <span class="font-medium">{{ __('Proposal Expired') }}</span>
                </div>
            @endif
        </div>

        {{-- Include Signature Modal Partial --}}
        @include('partials.public.signature-modal')

    </div>
</div>
