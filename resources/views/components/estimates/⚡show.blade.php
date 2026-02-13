<?php

use App\Models\Activity;
use App\Models\Estimate;
use App\Models\User;
use App\Notifications\EstimateProposalSent;
use App\Notifications\EstimateRejected;
use App\Services\MeetingService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
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

    public string $listingUrl = '';

    public function mount(Estimate $estimate): void
    {
        $this->estimate = $estimate->load(['deal.customer', 'deal.property', 'createdBy.internalUser', 'items', 'areas.surfaces', 'areas.nestedAreaTemplate', 'templateUsages.template', 'packages', 'signature', 'videoRecordings']);
        $this->publicUrl = $this->estimate->getPublicUrl();
        $this->listingUrl = $this->estimate->deal->listing_url ?? '';
    }

    public function getListeners(): array
    {
        $listeners = [
            'estimate-updated' => 'refreshEstimate',
            "echo-private:estimate.{$this->estimate->id},.customer.contribution" => 'refreshEstimate',
            "echo-private:estimate.{$this->estimate->id},.photo.updated" => 'refreshEstimate',
            "echo-private:estimate.{$this->estimate->id},.estimate.status.changed" => 'refreshEstimate',
        ];

        if ($this->estimate->deal_id) {
            $listeners["echo-private:deal.{$this->estimate->deal_id},.listing.scraped"] = 'refreshEstimate';
        }

        return $listeners;
    }

    public function refreshEstimate(): void
    {
        $this->estimate = $this->estimate->fresh([
            'deal.customer',
            'deal.property',
            'createdBy.internalUser',
            'items',
            'areas.surfaces',
            'areas.nestedAreaTemplate',
            'options',
            'templateUsages.template',
            'packages',
            'signature',
            'videoRecordings',
        ]);
        $this->publicUrl = $this->estimate->getPublicUrl();
    }

    public function openSendModal(): void
    {
        abort_unless(auth()->user()->can('estimates.send'), 403);

        if ($this->estimate->status !== 'draft') {
            session()->flash('error', __('Only draft estimates can be sent.'));

            return;
        }

        $recipient = $this->getProposalRecipient();
        if (! $recipient) {
            session()->flash('error', __('A customer or agent email is required to send the proposal.'));

            return;
        }

        if (! $this->estimate->hasCreatorSignature()) {
            session()->flash('error', __('You must configure your signature before sending proposals. Go to Settings > Signature to add your signature.'));

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
            session()->flash('error', __('Only draft estimates can be sent.'));
            $this->showSendModal = false;

            return;
        }

        $recipient = $this->getProposalRecipient();
        if (! $recipient) {
            session()->flash('error', __('A customer or agent email is required to send the proposal.'));
            $this->showSendModal = false;

            return;
        }

        if (! $this->estimate->hasCreatorSignature()) {
            session()->flash('error', __('You must configure your signature before sending proposals. Go to Settings > Signature to add your signature.'));
            $this->showSendModal = false;

            return;
        }

        $this->estimate->markAsSent();
        $this->publicUrl = $this->estimate->getPublicUrl();

        $recipient->notify(new EstimateProposalSent($this->estimate));

        $this->showSendModal = false;
        $this->refreshEstimate();
        session()->flash('success', __('Proposal sent to :email', ['email' => $recipient->email]));
    }

    /**
     * Get the recipient for sending the proposal: customer if available, otherwise the agent.
     */
    private function getProposalRecipient(): ?object
    {
        $customer = $this->estimate->deal?->customer;
        if ($customer && $customer->email) {
            return $customer;
        }

        $agent = $this->estimate->deal?->agent;
        if ($agent && $agent->email) {
            return $agent;
        }

        return null;
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
            session()->flash('error', __('Only draft estimates can be marked as sent.'));

            return;
        }

        $this->estimate->markAsSent();
        $this->refreshEstimate();
        session()->flash('success', __('Estimate marked as sent.'));
    }

    public function markAsAccepted(): void
    {
        if (! in_array($this->estimate->status, ['sent', 'draft'])) {
            session()->flash('error', __('This estimate cannot be marked as accepted.'));

            return;
        }

        // Internal staff can force accept without signature
        $this->estimate->markAsAccepted(force: true);
        $this->refreshEstimate();
        session()->flash('success', __('Estimate marked as accepted.'));
    }

    public function markAsRejected(?string $reason = null): void
    {
        if (! in_array($this->estimate->status, ['sent', 'draft'])) {
            session()->flash('error', __('This estimate cannot be marked as rejected.'));

            return;
        }

        $this->estimate->markAsRejected();

        // Notify all internal users
        $internalUsers = User::where('type', 'internal')->get();
        foreach ($internalUsers as $user) {
            $user->notify(new EstimateRejected($this->estimate, $reason));
        }

        $this->refreshEstimate();
        session()->flash('success', __('Estimate marked as rejected.'));
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

    public function saveListing(): void
    {
        $this->validate([
            'listingUrl' => 'nullable|url',
        ]);

        $this->estimate->deal->update([
            'listing_url' => $this->listingUrl ?: null,
        ]);

        $this->refreshEstimate();
        session()->flash('success', __('Listing link saved.'));
    }

    // Meeting scheduling properties
    public string $meetingTitle = '';

    public string $meetingDateTime = '';

    public string $meetingType = 'in_person';

    public string $meetingDescription = '';

    public string $meetingTeamMember = '';

    #[Computed]
    public function meetingActivities(): \Illuminate\Database\Eloquent\Collection
    {
        if (! $this->estimate->deal_id) {
            return new \Illuminate\Database\Eloquent\Collection;
        }

        return Activity::query()
            ->where('subject_type', \App\Models\Deal::class)
            ->where('subject_id', $this->estimate->deal_id)
            ->whereNotNull('meeting_type')
            ->with(['proposedBy', 'videoCall'])
            ->orderBy('due_at')
            ->get();
    }

    #[Computed]
    public function teamMembers(): \Illuminate\Database\Eloquent\Collection
    {
        return User::where('type', 'internal')->orderBy('name')->get();
    }

    public function proposeMeeting(): void
    {
        $this->validate([
            'meetingTitle' => 'required|string|max:255',
            'meetingDateTime' => 'required|date|after:now',
            'meetingType' => 'required|in:in_person,virtual',
            'meetingDescription' => 'nullable|string|max:1000',
            'meetingTeamMember' => 'nullable|exists:users,id',
        ]);

        $deal = $this->estimate->deal;
        if (! $deal) {
            session()->flash('error', __('No deal associated with this estimate.'));

            return;
        }

        app(MeetingService::class)->proposeMeeting(
            deal: $deal,
            proposer: auth()->user(),
            title: $this->meetingTitle,
            dateTime: \Carbon\Carbon::parse($this->meetingDateTime),
            meetingType: $this->meetingType,
            teamMemberId: $this->meetingTeamMember ?: null,
            description: $this->meetingDescription ?: null,
            estimateId: $this->estimate->id,
        );

        $this->reset(['meetingTitle', 'meetingDateTime', 'meetingType', 'meetingDescription', 'meetingTeamMember']);
        unset($this->meetingActivities);
        session()->flash('success', __('Meeting proposed successfully.'));
    }

    public function confirmMeeting(int $activityId): void
    {
        $activity = Activity::findOrFail($activityId);
        app(MeetingService::class)->confirmMeeting($activity, auth()->user());
        unset($this->meetingActivities);
        session()->flash('success', __('Meeting confirmed.'));
    }

    public function declineMeeting(int $activityId): void
    {
        $activity = Activity::findOrFail($activityId);
        app(MeetingService::class)->declineMeeting($activity, auth()->user());
        unset($this->meetingActivities);
        session()->flash('success', __('Meeting declined.'));
    }

    public function cancelMeeting(int $activityId): void
    {
        $activity = Activity::findOrFail($activityId);
        app(MeetingService::class)->cancelMeeting($activity, auth()->user());
        unset($this->meetingActivities);
        session()->flash('success', __('Meeting cancelled.'));
    }
}; ?>

<div>
    <div class="max-w-5xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-4">
                <flux:button variant="ghost" :href="route('estimates.index')" icon="arrow-left" wire:navigate>
                    {{ __('Back') }}
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
                        'simple' => __('Quick'),
                        'template' => __('Template'),
                        'detailed' => __('Detailed'),
                        default => __('Standard'),
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
                        @if ($estimate->status === 'sent')
                            <flux:button :href="$editRoute" icon="pencil" variant="filled" class="!bg-amber-600 hover:!bg-amber-700 !text-white" wire:navigate>
                                {{ __('Edit (Sent)') }}
                            </flux:button>
                        @else
                            <flux:button :href="$editRoute" icon="pencil" wire:navigate>
                                {{ __('Edit') }}
                            </flux:button>
                        @endif
                    @endif
                @endcan
                @can('estimates.send')
                    @if ($estimate->status === 'draft')
                        <flux:button wire:click="openSendModal" icon="paper-airplane" variant="primary">
                            {{ __('Send Proposal') }}
                        </flux:button>
                    @endif
                @endcan
                <flux:button :href="route('estimates.breakdown', $estimate)" icon="calculator" variant="ghost" wire:navigate>
                    {{ __('Cost Breakdown') }}
                </flux:button>
                <flux:button :href="route('estimates.pdf', $estimate)" target="_blank" icon="document-arrow-down" variant="ghost">
                    {{ __('Download PDF') }}
                </flux:button>
                <flux:dropdown>
                    <flux:button variant="ghost" icon="ellipsis-horizontal" />
                    <flux:menu>
                        @can('estimates.send')
                            @if ($estimate->status === 'draft')
                                <flux:menu.item wire:click="markAsSent" icon="paper-airplane">{{ __('Mark as Sent (No Email)') }}</flux:menu.item>
                            @endif
                        @endcan
                        @if (in_array($estimate->status, ['draft', 'sent']))
                            <flux:menu.item wire:click="markAsAccepted" icon="check-circle">{{ __('Mark as Accepted') }}</flux:menu.item>
                            <flux:menu.item wire:click="markAsRejected" icon="x-circle">{{ __('Mark as Rejected') }}</flux:menu.item>
                        @endif
                        <flux:menu.item :href="route('estimates.painter-breakdown', $estimate)" icon="clipboard-document-list" wire:navigate>{{ __("Painter's Work Order") }}</flux:menu.item>
                        <flux:menu.item icon="printer" onclick="window.print()">{{ __('Print') }}</flux:menu.item>
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

        @if ($estimate->status === 'sent' && $estimate->hasPendingAmendments())
            <div class="border border-amber-200 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/20 rounded-lg p-4 mb-4 print:hidden">
                <div class="flex items-center gap-2">
                    <flux:icon name="exclamation-triangle" class="w-5 h-5 text-amber-600 dark:text-amber-400 shrink-0" />
                    <div>
                        <flux:text class="font-medium text-amber-800 dark:text-amber-200">
                            {{ __(':count pending amendment(s) awaiting customer acknowledgment.', ['count' => $estimate->pendingAmendmentsCount()]) }}
                        </flux:text>
                        <flux:text size="sm" class="text-amber-700 dark:text-amber-300">
                            {{ __('The customer must review and initial these changes before they can sign the proposal.') }}
                        </flux:text>
                    </div>
                </div>
            </div>
        @endif

        @if ($publicUrl)
            <div class="border border-blue-200 dark:border-blue-700 bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4 mb-6 print:hidden">
                <div class="flex items-center justify-between gap-4">
                    <div class="flex items-center gap-2 min-w-0">
                        <flux:icon name="link" class="w-5 h-5 text-blue-600 dark:text-blue-400 shrink-0" />
                        <span class="text-sm font-medium text-blue-800 dark:text-blue-200 shrink-0">{{ __('Public Link:') }}</span>
                        <span class="text-sm text-blue-600 dark:text-blue-400 truncate">{{ $publicUrl }}</span>
                    </div>
                    <div class="flex items-center gap-2 shrink-0" x-data="{ copied: false, canShare: !!navigator.share, shareUrl: @js($publicUrl), shareTitle: @js($estimate->title ?? 'Estimate #' . $estimate->estimate_number) }">
                        <button
                            x-on:click="navigator.clipboard.writeText(shareUrl).then(() => { copied = true; setTimeout(() => copied = false, 2000) })"
                            class="inline-flex items-center gap-1.5 px-2 py-1 text-sm text-zinc-600 dark:text-zinc-400 hover:text-zinc-900 dark:hover:text-zinc-100 hover:bg-zinc-100 dark:hover:bg-zinc-700 rounded-md transition-colors"
                        >
                            <flux:icon x-show="!copied" name="clipboard-document" class="w-4 h-4" />
                            <flux:icon x-show="copied" x-cloak name="check" class="w-4 h-4 text-green-500" />
                            <span x-text="copied ? '{{ __('Copied!') }}' : '{{ __('Copy') }}'"></span>
                        </button>
                        <button
                            x-show="canShare"
                            x-cloak
                            x-on:click="navigator.share({ title: shareTitle, url: shareUrl }).catch(() => {})"
                            class="inline-flex items-center gap-1.5 px-2 py-1 text-sm text-zinc-600 dark:text-zinc-400 hover:text-zinc-900 dark:hover:text-zinc-100 hover:bg-zinc-100 dark:hover:bg-zinc-700 rounded-md transition-colors"
                        >
                            <flux:icon name="share" class="w-4 h-4" />
                            {{ __('Share') }}
                        </button>
                        <flux:button :href="$publicUrl" target="_blank" icon="arrow-top-right-on-square" size="sm" variant="ghost">
                            {{ __('Open') }}
                        </flux:button>
                    </div>
                </div>
                @if ($estimate->public_token_expires_at)
                    <p class="text-xs text-blue-600/70 dark:text-blue-400/70 mt-1 ml-7">
                        {{ __('Expires:') }} {{ $estimate->public_token_expires_at->format('M j, Y') }}
                    </p>
                @endif
            </div>
        @endif

        <div class="space-y-6">
            <div class="grid gap-6 md:grid-cols-2">
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <flux:heading size="sm" class="mb-4">{{ __('Estimate Details') }}</flux:heading>
                    <dl class="space-y-3">
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Title') }}</dt>
                            <dd class="mt-1">
                                <flux:text class="font-medium">{{ $estimate->title }}</flux:text>
                            </dd>
                        </div>
                        @if ($estimate->description)
                            <div>
                                <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Description') }}</dt>
                                <dd class="mt-1">
                                    <flux:text>{{ $estimate->description }}</flux:text>
                                </dd>
                            </div>
                        @endif
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Valid Until') }}</dt>
                            <dd class="mt-1">
                                @if ($estimate->valid_until)
                                    <flux:text class="{{ $estimate->isExpired() ? 'text-red-600' : '' }}">
                                        {{ $estimate->valid_until->format('M j, Y') }}
                                        @if ($estimate->isExpired())
                                            ({{ __('Expired') }})
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
                    <flux:heading size="sm" class="mb-4">{{ __('Customer & Deal') }}</flux:heading>
                    <dl class="space-y-3">
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Deal') }}</dt>
                            <dd class="mt-1">
                                <a href="{{ route('deals.show', $estimate->deal) }}" class="text-blue-600 hover:underline print:text-black print:no-underline" wire:navigate>
                                    {{ $estimate->deal->title }}
                                </a>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Customer') }}</dt>
                            <dd class="mt-1">
                                @if ($estimate->deal?->customer)
                                    <a href="{{ route('customers.show', $estimate->deal->customer) }}" class="text-blue-600 hover:underline print:text-black print:no-underline" wire:navigate>
                                        {{ $estimate->deal->customer->full_name }}
                                    </a>
                                @else
                                    <flux:text class="text-zinc-400">-</flux:text>
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Created By') }}</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $estimate->createdBy?->name ?? '-' }}</flux:text>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Created') }}</dt>
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
                    <flux:heading size="sm" class="mb-4">{{ __('Estimate Areas') }}</flux:heading>

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
                                                    @if ($area->is_fixed_price)
                                                        {{ __('Fixed Price') }}
                                                        @if ($area->nestedAreaTemplate?->category)
                                                            · {{ ucfirst($area->nestedAreaTemplate->category) }}
                                                        @endif
                                                    @else
                                                        {{ number_format($area->wall_sqft, 0) }} {{ __('sqft') }}
                                                        @if ($area->surfaces->count() > 0)
                                                            · {{ $area->surfaces->count() }} {{ __('surfaces') }}
                                                        @endif
                                                        @if ($area->surfaces->where('is_custom', true)->whereNotNull('sale_price')->isNotEmpty())
                                                            <span class="ml-1 px-1.5 py-0.5 text-xs bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300 rounded">{{ __('Custom') }}</span>
                                                        @endif
                                                    @endif
                                                </div>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-4">
                                            @php
                                                // Use canonical area-level pricing (margin applied once per area)
                                                $areaCustomerPrice = $area->getCustomerPrice();
                                                $totalLaborHours = $area->is_fixed_price
                                                    ? (float) ($area->estimated_labor_hours ?? 0)
                                                    : $area->surfaces->where('is_included', true)->sum('labor_hours');
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
                                            @if ($area->is_fixed_price)
                                                {{-- Fixed-price area breakdown --}}
                                                @php
                                                    $settings = \App\Models\CompanySetting::getInstance();
                                                    $scopeType = $area->scope_type ?? 'interior';
                                                    $fpLaborHours = (float) ($area->estimated_labor_hours ?? 0);
                                                    $fpLaborCost = (float) ($area->estimated_labor_cost ?? 0);
                                                    $fpMaterialCost = (float) ($area->estimated_material_cost ?? 0);
                                                @endphp

                                                @if ($fpLaborHours > 0)
                                                    @php
                                                        $hourlyRate = $settings->getEffectiveHourlyRateForScope($scopeType);
                                                        $cleanupHours = $settings->calculateCleanupHours($fpLaborHours, $scopeType);
                                                        $cleanupCost = $cleanupHours * $hourlyRate;
                                                        $sundriesCost = $settings->calculateSundriesCost($fpLaborHours, $scopeType);
                                                    @endphp
                                                    <div class="bg-blue-50 dark:bg-blue-950/30 rounded-lg p-3">
                                                        <div class="text-xs font-medium text-blue-700 dark:text-blue-400 mb-2">{{ __('Fixed-Price Breakdown') }}</div>
                                                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs">
                                                            <div>
                                                                <div class="text-zinc-500 dark:text-zinc-400">{{ __('Labor') }}</div>
                                                                <div class="font-medium">{{ number_format($fpLaborHours, 1) }} hrs = ${{ number_format($fpLaborCost, 2) }}</div>
                                                            </div>
                                                            <div>
                                                                <div class="text-zinc-500 dark:text-zinc-400">{{ __('Cleanup') }}</div>
                                                                <div class="font-medium">{{ number_format($cleanupHours, 2) }} hrs = ${{ number_format($cleanupCost, 2) }}</div>
                                                            </div>
                                                            <div>
                                                                <div class="text-zinc-500 dark:text-zinc-400">{{ __('Sundries') }}</div>
                                                                <div class="font-medium">${{ number_format($sundriesCost, 2) }}</div>
                                                            </div>
                                                            <div>
                                                                <div class="text-zinc-500 dark:text-zinc-400">{{ __('Materials') }}</div>
                                                                <div class="font-medium">${{ number_format($fpMaterialCost, 2) }}</div>
                                                            </div>
                                                        </div>
                                                        <div class="mt-3 pt-2 border-t border-blue-200 dark:border-blue-800 text-xs">
                                                            <div class="flex justify-between items-center">
                                                                <span class="text-zinc-600 dark:text-zinc-400 font-medium">{{ __('Fixed Price') }}</span>
                                                                <span class="font-bold text-blue-600 dark:text-blue-400">${{ number_format($areaCustomerPrice, 2) }}</span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                @else
                                                    <div class="bg-blue-50 dark:bg-blue-950/30 rounded-lg p-3">
                                                        <div class="flex justify-between items-center text-sm">
                                                            <span class="text-zinc-600 dark:text-zinc-400 font-medium">{{ __('Fixed Price') }}</span>
                                                            <span class="font-bold text-blue-600 dark:text-blue-400">${{ number_format($areaCustomerPrice, 2) }}</span>
                                                        </div>
                                                    </div>
                                                @endif
                                            @else
                                                {{-- Standard area with surfaces --}}
                                                @if ($area->surfaces->count() > 0)
                                                    @php
                                                        $surfaceCustomerPrices = $area->getSurfaceCustomerPrices();
                                                    @endphp
                                                    <div>
                                                        <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase mb-2">{{ __('Surfaces') }}</div>
                                                        <div class="space-y-2">
                                                            @foreach ($area->surfaces as $surface)
                                                                <div class="flex justify-between items-center text-sm">
                                                                    <div>
                                                                        <span class="font-medium">{{ $surface->name }}</span>
                                                                        @if ($surface->is_custom)
                                                                            <span class="text-zinc-500">{{ number_format($surface->labor_hours, 1) }} {{ __('hrs') }} · {{ __('custom') }}</span>
                                                                        @else
                                                                            <span class="text-zinc-500">{{ number_format($surface->quantity, 0) }} {{ $surface->unit }} · {{ $surface->coats }} {{ __('coats') }}</span>
                                                                        @endif
                                                                    </div>
                                                                    <div class="text-right">
                                                                        <span class="text-zinc-500">{{ number_format($surface->labor_hours, 1) }} hrs</span>
                                                                        <span class="mx-1">·</span>
                                                                        <span class="font-medium">${{ number_format($surfaceCustomerPrices[$surface->id] ?? $surface->getCustomerPrice(), 2) }}</span>
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
                                                    <div class="text-xs font-medium text-blue-700 dark:text-blue-400 mb-2">{{ __('PBP Calculation') }}</div>
                                                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 text-xs">
                                                        <div>
                                                            <div class="text-zinc-500 dark:text-zinc-400">{{ __('Labor') }}</div>
                                                            <div class="font-medium">{{ number_format($totalLaborHours, 1) }} hrs × ${{ number_format($hourlyWage, 2) }} = ${{ number_format($totalLaborCost, 2) }}</div>
                                                        </div>
                                                        <div>
                                                            <div class="text-zinc-500 dark:text-zinc-400">{{ __('Cleanup') }}</div>
                                                            <div class="font-medium">{{ number_format($cleanupHours, 2) }} hrs = ${{ number_format($cleanupCost, 2) }}</div>
                                                        </div>
                                                        <div>
                                                            <div class="text-zinc-500 dark:text-zinc-400">{{ __('Sundries') }}</div>
                                                            <div class="font-medium">${{ number_format($sundriesCost, 2) }}</div>
                                                        </div>
                                                        <div>
                                                            <div class="text-zinc-500 dark:text-zinc-400">{{ __('Materials') }}</div>
                                                            <div class="font-medium">${{ number_format($totalMaterialCost, 2) }}</div>
                                                        </div>
                                                    </div>
                                                    @if ($addonCost > 0)
                                                    <div class="grid grid-cols-2 gap-2 text-xs mt-2">
                                                        <div>
                                                            <div class="text-zinc-500 dark:text-zinc-400">{{ __('Addons') }}</div>
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
                                            @endif
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
                                            {{ __('Item') }}
                                        </th>
                                        <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                            {{ __('Qty') }}
                                        </th>
                                        <th scope="col" class="px-4 py-3 text-center text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                            {{ __('Coats') }}
                                        </th>
                                        <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                            {{ __('Price') }}
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
                                                        <flux:text class="font-medium">{{ $usage->template ? __($usage->template->name) : __('Unknown Template') }}</flux:text>
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
                    <flux:heading size="sm" class="mb-4">{{ __('Quick Estimate') }}</flux:heading>
                    <div class="bg-zinc-50 dark:bg-zinc-800 rounded-lg p-4">
                        <dl class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <div>
                                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Square Feet') }}</dt>
                                <dd class="text-lg font-medium">{{ number_format($estimate->simple_sqft ?? 0, 0) }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Rate per Sqft') }}</dt>
                                <dd class="text-lg font-medium">${{ number_format($estimate->simple_rate_per_sqft ?? 0, 2) }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Project Type') }}</dt>
                                <dd class="text-lg font-medium">{{ ucfirst($estimate->project_type ?? 'Interior') }}</dd>
                            </div>
                            <div>
                                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Calculated') }}</dt>
                                <dd class="text-lg font-medium">${{ number_format(($estimate->simple_sqft ?? 0) * ($estimate->simple_rate_per_sqft ?? 0), 2) }}</dd>
                            </div>
                        </dl>
                    </div>
                @elseif ($estimate->isTemplateMode())
                    {{-- Template Mode with no areas/usages yet --}}
                    <flux:heading size="sm" class="mb-4">{{ __('Estimate Areas') }}</flux:heading>
                    <div class="py-8 text-center text-zinc-500 dark:text-zinc-400">
                        {{ __('No areas added yet.') }}
                    </div>
                @else
                    {{-- Standard/Detailed Mode: Show line items --}}
                    <flux:heading size="sm" class="mb-4">{{ __('Line Items') }}</flux:heading>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                            <thead>
                                <tr>
                                    <th scope="col" class="py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                        {{ __('Description') }}
                                    </th>
                                    <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                        {{ __('Qty') }}
                                    </th>
                                    <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                        {{ __('Unit') }}
                                    </th>
                                    <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                        {{ __('Unit Price') }}
                                    </th>
                                    <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                        {{ __('Total') }}
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
                                            {{ __('No line items added yet.') }}
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                @endif

            </div>

            {{-- Video Walkarounds --}}
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 print:hidden">
                <livewire:estimates.video-walkarounds
                    :recordable="$estimate"
                    :key="'walkarounds-'.$estimate->id"
                />
            </div>

            {{-- Listing Link --}}
            <flux:card>
                <flux:heading size="lg" class="mb-4">{{ __('Property Listing Link') }}</flux:heading>
                <flux:text class="text-sm text-zinc-500 mb-4">
                    {{ __('Paste a Zillow, Redfin, or other listing link so the team and customer can reference it.') }}
                </flux:text>

                <form wire:submit="saveListing" class="flex items-end gap-3">
                    <flux:field class="flex-1">
                        <flux:input
                            wire:model="listingUrl"
                            type="url"
                            placeholder="https://www.zillow.com/homedetails/..."
                        />
                        <flux:error name="listingUrl" />
                    </flux:field>
                    <flux:button type="submit" variant="primary" size="sm" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="saveListing">{{ __('Save') }}</span>
                        <span wire:loading wire:target="saveListing">{{ __('Saving...') }}</span>
                    </flux:button>
                </form>

                @if ($estimate->deal?->listing_url)
                    <div class="mt-3 flex items-center gap-2">
                        <flux:icon name="link" class="h-4 w-4 text-zinc-400 shrink-0" />
                        <a href="{{ $estimate->deal->listing_url }}" target="_blank" class="text-sm text-blue-600 dark:text-blue-400 underline hover:no-underline break-all">
                            {{ $estimate->deal->listing_url }}
                        </a>
                    </div>
                @endif
            </flux:card>

            {{-- Customer Contributions --}}
            @php
                $hasContributions = $estimate->customer_notes
                    || !empty($estimate->customer_checklist)
                    || $estimate->areas->contains(fn($a) => $a->customer_notes || !empty($a->customer_checklist));
            @endphp

            @if ($hasContributions)
                <flux:card>
                    <div x-data="{ open: true }">
                        <button type="button" @click="open = !open" class="flex w-full items-center justify-between">
                            <flux:heading size="lg">{{ __('Customer Contributions') }}</flux:heading>
                            <flux:icon x-show="!open" name="chevron-down" class="h-5 w-5 text-zinc-400" />
                            <flux:icon x-show="open" name="chevron-up" class="h-5 w-5 text-zinc-400" />
                        </button>

                        <div x-show="open" x-transition class="mt-4 space-y-4">
                            {{-- General Notes --}}
                            @if ($estimate->customer_notes)
                                <div>
                                    <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Customer Notes') }}</flux:text>
                                    <div class="mt-1 rounded-lg bg-blue-50 p-3 dark:bg-blue-900/20">
                                        <flux:text>{{ $estimate->customer_notes }}</flux:text>
                                    </div>
                                </div>
                            @endif

                            {{-- Checklist --}}
                            @if (!empty($estimate->customer_checklist))
                                <div>
                                    <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Customer Wishlist') }}</flux:text>
                                    <div class="mt-1 flex flex-wrap gap-2">
                                        @foreach ($estimate->customer_checklist as $item)
                                            <flux:badge size="sm" color="blue">{{ str_replace('_', ' ', ucfirst($item)) }}</flux:badge>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            {{-- Per-Area Notes --}}
                            @foreach ($estimate->areas as $area)
                                @if ($area->customer_notes || !empty($area->customer_checklist))
                                    <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                                        <flux:text class="text-sm font-medium">{{ $area->name }}</flux:text>
                                        @if ($area->customer_notes)
                                            <div class="mt-1 rounded bg-blue-50 p-2 dark:bg-blue-900/20">
                                                <flux:text class="text-sm">{{ $area->customer_notes }}</flux:text>
                                            </div>
                                        @endif
                                        @if (!empty($area->customer_checklist))
                                            <div class="mt-1 flex flex-wrap gap-1">
                                                @foreach ($area->customer_checklist as $item)
                                                    <flux:badge size="sm" color="zinc">{{ str_replace('_', ' ', ucfirst($item)) }}</flux:badge>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                </flux:card>
            @endif

            {{-- Agent Contributions --}}
            @php
                $hasAgentContributions = $estimate->agent_notes
                    || !empty($estimate->agent_checklist)
                    || $estimate->areas->contains(fn($a) => $a->agent_notes || !empty($a->agent_checklist));
            @endphp

            @if ($hasAgentContributions)
                <flux:card>
                    <div x-data="{ open: true }">
                        <button type="button" @click="open = !open" class="flex w-full items-center justify-between">
                            <flux:heading size="lg">{{ __('Agent Contributions') }}</flux:heading>
                            <flux:icon x-show="!open" name="chevron-down" class="h-5 w-5 text-zinc-400" />
                            <flux:icon x-show="open" name="chevron-up" class="h-5 w-5 text-zinc-400" />
                        </button>

                        <div x-show="open" x-transition class="mt-4 space-y-4">
                            {{-- General Notes --}}
                            @if ($estimate->agent_notes)
                                <div>
                                    <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Agent Notes') }}</flux:text>
                                    <div class="mt-1 rounded-lg bg-purple-50 p-3 dark:bg-purple-900/20">
                                        <flux:text>{{ $estimate->agent_notes }}</flux:text>
                                    </div>
                                </div>
                            @endif

                            {{-- Checklist --}}
                            @if (!empty($estimate->agent_checklist))
                                <div>
                                    <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Property Conditions') }}</flux:text>
                                    <div class="mt-1 flex flex-wrap gap-2">
                                        @foreach ($estimate->agent_checklist as $item)
                                            <flux:badge size="sm" color="purple">{{ str_replace('_', ' ', ucfirst($item)) }}</flux:badge>
                                        @endforeach
                                    </div>
                                </div>
                            @endif

                            {{-- Per-Area Notes --}}
                            @foreach ($estimate->areas as $area)
                                @if ($area->agent_notes || !empty($area->agent_checklist))
                                    <div class="rounded-lg border border-zinc-200 p-3 dark:border-zinc-700">
                                        <flux:text class="text-sm font-medium">{{ $area->name }}</flux:text>
                                        @if ($area->agent_notes)
                                            <div class="mt-1 rounded bg-purple-50 p-2 dark:bg-purple-900/20">
                                                <flux:text class="text-sm">{{ $area->agent_notes }}</flux:text>
                                            </div>
                                        @endif
                                        @if (!empty($area->agent_checklist))
                                            <div class="mt-1 flex flex-wrap gap-1">
                                                @foreach ($area->agent_checklist as $item)
                                                    <flux:badge size="sm" color="zinc">{{ str_replace('_', ' ', ucfirst($item)) }}</flux:badge>
                                                @endforeach
                                            </div>
                                        @endif
                                    </div>
                                @endif
                            @endforeach
                        </div>
                    </div>
                </flux:card>
            @endif

            {{-- Meetings Section --}}
            @if ($estimate->deal_id)
                <flux:card>
                    <div x-data="{ open: true }">
                        <button type="button" @click="open = !open" class="flex w-full items-center justify-between">
                            <div class="flex items-center gap-2">
                                <flux:heading size="lg">{{ __('Meetings') }}</flux:heading>
                                @if ($this->meetingActivities->count() > 0)
                                    <flux:badge size="sm" color="zinc">{{ $this->meetingActivities->count() }}</flux:badge>
                                @endif
                            </div>
                            <flux:icon x-show="!open" name="chevron-down" class="h-5 w-5 text-zinc-400" />
                            <flux:icon x-show="open" name="chevron-up" class="h-5 w-5 text-zinc-400" />
                        </button>

                        <div x-show="open" x-transition class="mt-4 space-y-4">
                            {{-- Propose Meeting Form --}}
                            <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
                                <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400 mb-3">{{ __('Schedule a Meeting') }}</flux:text>
                                <form wire:submit="proposeMeeting" class="space-y-3">
                                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                        <flux:input wire:model="meetingTitle" label="{{ __('Title') }}" placeholder="{{ __('e.g. Estimate Walk-Through') }}" required />
                                        <flux:input wire:model="meetingDateTime" type="datetime-local" label="{{ __('Date & Time') }}" required />
                                    </div>
                                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                                        <flux:select wire:model="meetingType" label="{{ __('Type') }}">
                                            <option value="in_person">{{ __('In Person') }}</option>
                                            <option value="virtual">{{ __('Virtual (Video Call)') }}</option>
                                        </flux:select>
                                        <flux:select wire:model="meetingTeamMember" label="{{ __('Assign Team Member') }}">
                                            <option value="">{{ __('Select team member...') }}</option>
                                            @foreach ($this->teamMembers as $member)
                                                <option value="{{ $member->id }}">{{ $member->name }}</option>
                                            @endforeach
                                        </flux:select>
                                    </div>
                                    <flux:textarea wire:model="meetingDescription" label="{{ __('Description') }}" placeholder="{{ __('Optional meeting details...') }}" rows="2" />
                                    <div class="flex justify-end">
                                        <flux:button type="submit" variant="primary" size="sm">{{ __('Propose Meeting') }}</flux:button>
                                    </div>
                                </form>
                            </div>

                            {{-- Meeting List --}}
                            @forelse ($this->meetingActivities as $meeting)
                                <div wire:key="meeting-{{ $meeting->id }}" class="rounded-lg border p-4 {{ $meeting->meeting_status === 'confirmed' ? 'border-green-200 bg-green-50 dark:border-green-800 dark:bg-green-900/20' : ($meeting->meeting_status === 'cancelled' || $meeting->meeting_status === 'declined' ? 'border-red-200 bg-red-50 dark:border-red-800 dark:bg-red-900/20' : 'border-zinc-200 dark:border-zinc-700') }}">
                                    <div class="flex items-start justify-between">
                                        <div class="space-y-1">
                                            <div class="flex items-center gap-2">
                                                <flux:text class="font-medium">{{ $meeting->title }}</flux:text>
                                                <flux:badge size="sm" color="{{ $meeting->meeting_type === 'virtual' ? 'blue' : 'zinc' }}">
                                                    {{ $meeting->meeting_type === 'virtual' ? __('Virtual') : __('In Person') }}
                                                </flux:badge>
                                                <flux:badge size="sm" color="{{ match($meeting->meeting_status) { 'confirmed' => 'green', 'proposed' => 'yellow', 'declined' => 'red', 'cancelled' => 'red', default => 'zinc' } }}">
                                                    {{ ucfirst($meeting->meeting_status) }}
                                                </flux:badge>
                                            </div>
                                            @if ($meeting->due_at)
                                                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                                                    <flux:icon name="calendar" class="inline h-4 w-4" />
                                                    {{ $meeting->due_at->format('l, M j, Y \a\t g:i A') }}
                                                </flux:text>
                                            @endif
                                            @if ($meeting->description)
                                                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ $meeting->description }}</flux:text>
                                            @endif
                                            @if ($meeting->proposedBy)
                                                <flux:text class="text-xs text-zinc-400">{{ __('Proposed by :name', ['name' => $meeting->proposedBy->name]) }}</flux:text>
                                            @endif
                                        </div>
                                    </div>

                                    {{-- Confirmation Status --}}
                                    @if ($meeting->meeting_status === 'proposed' || $meeting->meeting_status === 'confirmed')
                                        <div class="mt-3 flex flex-wrap items-center gap-3 text-sm">
                                            <div class="flex items-center gap-1 {{ $meeting->team_confirmed_at ? 'text-green-600 dark:text-green-400' : 'text-zinc-400' }}">
                                                <flux:icon name="{{ $meeting->team_confirmed_at ? 'check-circle' : 'clock' }}" class="h-4 w-4" />
                                                <span>{{ __('Team') }}</span>
                                            </div>
                                            @if ($estimate->deal?->customer_id)
                                                <div class="flex items-center gap-1 {{ $meeting->customer_confirmed_at ? 'text-green-600 dark:text-green-400' : 'text-zinc-400' }}">
                                                    <flux:icon name="{{ $meeting->customer_confirmed_at ? 'check-circle' : 'clock' }}" class="h-4 w-4" />
                                                    <span>{{ __('Customer') }}</span>
                                                </div>
                                            @endif
                                            @if ($estimate->deal?->agent_id)
                                                <div class="flex items-center gap-1 {{ $meeting->agent_confirmed_at ? 'text-green-600 dark:text-green-400' : 'text-zinc-400' }}">
                                                    <flux:icon name="{{ $meeting->agent_confirmed_at ? 'check-circle' : 'clock' }}" class="h-4 w-4" />
                                                    <span>{{ __('Agent') }}</span>
                                                </div>
                                            @endif
                                        </div>
                                    @endif

                                    {{-- Actions --}}
                                    @if (in_array($meeting->meeting_status, ['proposed', 'confirmed']))
                                        <div class="mt-3 flex flex-wrap gap-2">
                                            @if (! $meeting->team_confirmed_at)
                                                <flux:button wire:click="confirmMeeting({{ $meeting->id }})" size="sm" variant="primary">{{ __('Confirm') }}</flux:button>
                                            @endif
                                            @if ($meeting->meeting_status === 'proposed')
                                                <flux:button wire:click="declineMeeting({{ $meeting->id }})" size="sm" variant="danger">{{ __('Decline') }}</flux:button>
                                            @endif
                                            <flux:button wire:click="cancelMeeting({{ $meeting->id }})" size="sm" variant="ghost">{{ __('Cancel') }}</flux:button>
                                            @if ($meeting->meeting_type === 'virtual' && $meeting->meeting_status === 'confirmed' && $meeting->videoCall)
                                                <flux:button href="{{ route('video-call.test', $meeting->videoCall->room_id) }}" size="sm" variant="primary">
                                                    <flux:icon name="video-camera" class="h-4 w-4 mr-1" />
                                                    {{ __('Join Video Call') }}
                                                </flux:button>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            @empty
                                <div class="text-center py-4">
                                    <flux:icon name="calendar-days" class="h-8 w-8 mx-auto text-zinc-300 dark:text-zinc-600" />
                                    <flux:text class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">{{ __('No meetings scheduled yet.') }}</flux:text>
                                </div>
                            @endforelse
                        </div>
                    </div>
                </flux:card>
            @endif

            {{-- Package Builder Section --}}
            {{-- Editable for draft and sent estimates, read-only for accepted/rejected --}}
            @if ($estimate->isEditable() || $estimate->status === 'sent')
                <div class="print:hidden">
                    @if ($estimate->status === 'sent')
                        <div class="mb-4 p-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700 rounded-lg">
                            <div class="flex items-center gap-2">
                                <flux:icon name="exclamation-triangle" class="w-5 h-5 text-amber-600 dark:text-amber-400" />
                                <flux:text size="sm" class="text-amber-700 dark:text-amber-300">
                                    {{ __('This estimate has been sent to the customer. Any changes you make will notify them automatically.') }}
                                </flux:text>
                            </div>
                        </div>
                    @endif
                    <livewire:estimates.package-builder :estimate="$estimate" :key="'package-builder-'.$estimate->id" />
                </div>
            @elseif ($estimate->packages()->count() > 0)
                {{-- Show read-only packages for accepted/rejected estimates --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <flux:heading size="sm" class="mb-4">{{ __('Pricing Packages') }}</flux:heading>
                    <div class="grid gap-4 md:grid-cols-3">
                        @foreach ($estimate->packages as $package)
                            <div class="border rounded-lg p-4 {{ $package->is_selected ? 'border-green-500 bg-green-50 dark:bg-green-900/20' : 'border-zinc-200 dark:border-zinc-700' }}">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="font-medium">{{ $package->name }}</span>
                                    @if ($package->is_recommended)
                                        <flux:badge size="sm" color="blue">{{ __('Recommended') }}</flux:badge>
                                    @endif
                                    @if ($package->is_selected)
                                        <flux:badge size="sm" color="green">{{ __('Selected') }}</flux:badge>
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

            @if ($estimate->notes)
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <flux:heading size="sm" class="mb-4">{{ __('Notes') }}</flux:heading>
                    <flux:text class="whitespace-pre-wrap">{{ $estimate->notes }}</flux:text>
                </div>
            @endif

            @if ($estimate->sent_at || $estimate->accepted_at || $estimate->rejected_at)
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <flux:heading size="sm" class="mb-4">{{ __('Status History') }}</flux:heading>
                    <dl class="space-y-3">
                        @if ($estimate->sent_at)
                            <div class="flex items-center gap-2">
                                <flux:icon name="paper-airplane" class="w-4 h-4 text-blue-500" />
                                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Sent:') }}</dt>
                                <dd><flux:text>{{ $estimate->sent_at->format('M j, Y g:i A') }}</flux:text></dd>
                            </div>
                        @endif
                        @if ($estimate->accepted_at)
                            <div class="flex items-center gap-2">
                                <flux:icon name="check-circle" class="w-4 h-4 text-green-500" />
                                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Accepted:') }}</dt>
                                <dd><flux:text>{{ $estimate->accepted_at->format('M j, Y g:i A') }}</flux:text></dd>
                            </div>
                        @endif
                        @if ($estimate->rejected_at)
                            <div class="flex items-center gap-2">
                                <flux:icon name="x-circle" class="w-4 h-4 text-red-500" />
                                <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Rejected:') }}</dt>
                                <dd><flux:text>{{ $estimate->rejected_at->format('M j, Y g:i A') }}</flux:text></dd>
                            </div>
                        @endif
                    </dl>
                </div>
            @endif

            {{-- Signatures --}}
            @if ($estimate->createdBy?->internalUser?->hasSignature() || $estimate->signature)
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <flux:heading size="sm" class="mb-4">{{ __('Signatures') }}</flux:heading>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-8">
                        {{-- Estimator Signature --}}
                        @if ($estimate->createdBy?->internalUser?->hasSignature())
                            <div>
                                <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider mb-3">{{ __('Prepared By') }}</div>
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
                                <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider mb-3">{{ __('Accepted By') }}</div>
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
                                            {{ __('Signed') }} {{ $estimate->signature->signed_at->format('M j, Y g:i A') }}
                                        </flux:text>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            {{-- Amendment History --}}
            @if ($estimate->amendments()->count() > 0)
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 print:hidden">
                    <details>
                        <summary class="cursor-pointer">
                            <flux:heading size="sm" class="inline">
                                {{ __('Amendment History') }} ({{ $estimate->amendments()->count() }})
                            </flux:heading>
                        </summary>
                        <div class="mt-4 space-y-4">
                            @foreach ($estimate->amendments()->with('amendedBy')->orderByDesc('amendment_number')->get() as $amendment)
                                <div class="border rounded-lg p-4 {{ $amendment->isAcknowledged() ? 'border-green-200 dark:border-green-800 bg-green-50/50 dark:bg-green-900/10' : 'border-amber-200 dark:border-amber-800 bg-amber-50/50 dark:bg-amber-900/10' }}">
                                    <div class="flex items-center justify-between mb-2">
                                        <div class="flex items-center gap-2">
                                            <flux:text class="font-medium">Amendment #{{ $amendment->amendment_number }}</flux:text>
                                            <flux:text size="sm" class="text-zinc-500">by {{ $amendment->amendedBy?->name ?? 'Unknown' }}</flux:text>
                                            <flux:text size="sm" class="text-zinc-400">{{ $amendment->created_at->diffForHumans() }}</flux:text>
                                        </div>
                                        <div class="flex items-center gap-2">
                                            @if ($amendment->isAcknowledged())
                                                <flux:badge size="sm" color="green">
                                                    Acknowledged ({{ $amendment->customer_initials }}) {{ $amendment->customer_acknowledged_at->format('M j') }}
                                                </flux:badge>
                                            @else
                                                <flux:badge size="sm" color="amber">{{ __('Pending') }}</flux:badge>
                                            @endif
                                        </div>
                                    </div>
                                    <div class="space-y-1">
                                        @foreach ($amendment->changes as $change)
                                            <div class="text-sm text-zinc-600 dark:text-zinc-400">
                                                @if (isset($change['field'], $change['old_value'], $change['new_value']))
                                                    <span class="font-medium">{{ $change['label'] }}</span>:
                                                    {{ $change['field'] }}
                                                    <span class="line-through text-red-500">{{ $change['old_value'] }}</span>
                                                    &rarr;
                                                    <span class="text-green-600">{{ $change['new_value'] }}</span>
                                                @else
                                                    {{ $change['details'] ?? $change['label'] ?? __('Change') }}
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                    <div class="mt-2 pt-2 border-t border-zinc-200 dark:border-zinc-700 flex items-center gap-4">
                                        <flux:text size="sm">
                                            {{ __('Previous:') }} ${{ number_format($amendment->previous_total, 2) }}
                                        </flux:text>
                                        <flux:text size="sm">
                                            {{ __('New:') }} ${{ number_format($amendment->new_total, 2) }}
                                        </flux:text>
                                        <flux:text size="sm" class="font-medium {{ $amendment->getTotalChangeAmount() >= 0 ? 'text-red-600' : 'text-green-600' }}">
                                            ({{ $amendment->getTotalChangeFormatted() }})
                                        </flux:text>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </details>
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

            {{-- Activity Timeline --}}
            <div class="print:hidden">
                <livewire:estimates.activity-timeline
                    :estimate="$estimate"
                    :key="'activity-timeline-'.$estimate->id"
                />
            </div>

        </div>
    </div>

    <flux:modal wire:model="showSendModal" class="max-w-lg">
        <div class="space-y-6">
            <flux:heading size="lg">{{ __('Send Proposal') }}</flux:heading>

            <div class="space-y-4">
                <div class="bg-zinc-50 dark:bg-zinc-800 rounded-lg p-4">
                    <dl class="space-y-2">
                        <div class="flex justify-between">
                            <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Estimate') }}</dt>
                            <dd class="font-medium">{{ $estimate->estimate_number }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Title') }}</dt>
                            <dd class="font-medium">{{ $estimate->title }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Total') }}</dt>
                            <dd class="font-medium">${{ number_format($estimate->total, 2) }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Customer') }}</dt>
                            <dd class="font-medium">{{ $estimate->deal?->customer?->full_name ?? '-' }}</dd>
                        </div>
                        <div class="flex justify-between">
                            <dt class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Email') }}</dt>
                            <dd class="font-medium">{{ $estimate->deal?->customer?->email ?? '-' }}</dd>
                        </div>
                    </dl>
                </div>

                <flux:callout variant="info">
                    {{ __('An email will be sent to the customer with a link to view and accept the proposal.') }}
                </flux:callout>
            </div>

            <div class="flex justify-end gap-3">
                <flux:button wire:click="closeSendModal" variant="ghost">{{ __('Cancel') }}</flux:button>
                <flux:button wire:click="sendProposal" variant="primary" icon="paper-airplane">
                    {{ __('Send Proposal') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
