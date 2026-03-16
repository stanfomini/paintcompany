<?php

use App\Models\Customer;
use App\Models\Deal;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.customer', ['title' => 'Dashboard'])] class extends Component {
    public ?int $selectedDealId = null;

    public bool $showAreaCarousel = false;

    public ?int $carouselEstimateId = null;

    public function mount(): void
    {
        if ($this->customers->isEmpty()) {
            return;
        }

        $deals = $this->deals;

        if ($deals->isNotEmpty()) {
            // Auto-select the deal most needing attention (has action items), else most recent
            $this->selectedDealId = $deals->first()->id;
        }
    }

    /**
     * All customer records belonging to the authenticated user.
     * A user may have multiple customer records (e.g. created from different deals).
     */
    #[Computed]
    public function customers(): Collection
    {
        return Customer::query()
            ->where('user_id', auth('customer')->id())
            ->get();
    }

    /**
     * Backward-compatible single customer (first record).
     */
    #[Computed]
    public function customer(): ?Customer
    {
        return $this->customers->first();
    }

    /**
     * All customer IDs for the authenticated user.
     */
    #[Computed]
    public function customerIds(): array
    {
        return $this->customers->pluck('id')->all();
    }

    #[Computed]
    public function deals()
    {
        if ($this->customers->isEmpty()) {
            return collect();
        }

        return Deal::query()
            ->where(function ($q) {
                // Deals owned by any of the user's customer records
                $q->whereIn('customer_id', $this->customerIds)
                    ->orWhereIn('id', \Illuminate\Support\Facades\DB::table('deal_collaborators')
                        ->whereIn('customer_id', $this->customerIds)
                        ->whereNull('revoked_at')
                        ->where('expires_at', '>', now())
                        ->select('deal_id')
                    );
            })
            ->where(function ($q) {
                // Include deals that are NOT lost/cancelled, OR have an active (non-completed) job
                $q->whereNotIn('stage', ['lost', 'cancelled'])
                    ->orWhereHas('job', fn ($jq) => $jq->where('stage', '!=', 'completed'));
            })
            ->with(['property', 'job'])
            ->latest()
            ->get();
    }

    #[Computed]
    public function pastDeals()
    {
        if ($this->customers->isEmpty()) {
            return collect();
        }

        return Deal::query()
            ->where(function ($q) {
                $q->whereIn('customer_id', $this->customerIds)
                    ->orWhereIn('id', \Illuminate\Support\Facades\DB::table('deal_collaborators')
                        ->whereIn('customer_id', $this->customerIds)
                        ->whereNull('revoked_at')
                        ->where('expires_at', '>', now())
                        ->select('deal_id')
                    );
            })
            ->whereHas('job', fn ($q) => $q->where('stage', 'completed'))
            ->with(['property', 'estimates', 'job.invoices'])
            ->latest()
            ->get();
    }

    #[Computed]
    public function selectedDeal(): ?Deal
    {
        if (! $this->selectedDealId) {
            return null;
        }

        return Deal::query()
            ->with(['property', 'estimates.areas', 'job.prepPlan', 'job.invoices', 'chatThread'])
            ->find($this->selectedDealId);
    }

    #[Computed]
    public function actionItems(): array
    {
        $deal = $this->selectedDeal;

        if (! $deal) {
            return [];
        }

        $items = [];

        // Check for estimates needing review
        foreach ($deal->estimates as $estimate) {
            if ($estimate->status === 'sent') {
                $items[] = [
                    'type' => 'estimate_review',
                    'icon' => 'document-text',
                    'text' => __('Review your estimate (:number)', ['number' => $estimate->estimate_number]),
                    'route' => route('customer.estimates.show', $estimate),
                ];
            }
        }

        // Check for job-related actions
        $job = $deal->job;

        if ($job) {
            if ($job->stage === 'scheduling_colors') {
                if (! $job->customer_date_confirmed) {
                    $items[] = [
                        'type' => 'date_confirm',
                        'icon' => 'calendar',
                        'text' => __('Confirm your scheduled date'),
                        'route' => route('customer.jobs.show', $job),
                    ];
                }

                if (! $job->customer_colors_confirmed) {
                    $items[] = [
                        'type' => 'colors_confirm',
                        'icon' => 'swatch',
                        'text' => __('Confirm your color selections'),
                        'route' => route('customer.jobs.show', $job),
                    ];
                }
            }

            if ($job->stage === 'job_prep' && $job->prepPlan && ! $job->prepPlan->customer_approved) {
                $items[] = [
                    'type' => 'prep_approve',
                    'icon' => 'clipboard-document-check',
                    'text' => __('Review the prep plan'),
                    'route' => route('customer.jobs.show', $job),
                ];
            }
        }

        // Check for unpaid invoices
        if ($job && $job->relationLoaded('invoices')) {
            foreach ($job->invoices as $invoice) {
                if (in_array($invoice->status, ['sent', 'pending', 'overdue'])) {
                    $items[] = [
                        'type' => 'invoice_payment',
                        'icon' => 'credit-card',
                        'text' => __('Pay invoice #:number ($:amount due)', [
                            'number' => $invoice->invoice_number ?? $invoice->id,
                            'amount' => number_format($invoice->balance_due ?? $invoice->total, 2),
                        ]),
                        'route' => route('customer.invoices.show', $invoice),
                    ];
                }
            }
        }

        return $items;
    }

    public function openEstimateOverlay(int $estimateId): void
    {
        $this->carouselEstimateId = $estimateId;
        $this->showAreaCarousel = true;
    }

    public function closeEstimateOverlay(): void
    {
        $this->showAreaCarousel = false;
        $this->carouselEstimateId = null;
    }

    public function selectDeal(int $dealId): void
    {
        // Verify deal is accessible by any of the user's customer records
        $accessible = $this->deals->contains('id', $dealId);

        if (! $accessible) {
            return;
        }

        $this->selectedDealId = $dealId;

        // Clear cached computed properties
        unset($this->selectedDeal, $this->actionItems);
    }

    public function getListeners(): array
    {
        $listeners = [];
        $deal = $this->selectedDealId ? Deal::with(['job', 'estimates'])->find($this->selectedDealId) : null;
        if (! $deal) {
            return $listeners;
        }

        // Job-level events
        if ($deal->job) {
            $jobId = $deal->job->id;
            $listeners["echo-private:customer.job.{$jobId},stage.changed"] = 'refreshDashboard';
            $listeners["echo-private:customer.job.{$jobId},date.updated"] = 'refreshDashboard';
            $listeners["echo-private:customer.job.{$jobId},color.updated"] = 'refreshDashboard';
            $listeners["echo-private:customer.job.{$jobId},prep.approval.changed"] = 'refreshDashboard';
            $listeners["echo-private:customer.job.{$jobId},photo.updated"] = 'refreshDashboard';
            $listeners["echo-private:customer.job.{$jobId},.invoice.created"] = 'refreshDashboard';
            $listeners["echo-private:customer.job.{$jobId},.task.updated"] = 'refreshDashboard';
        }

        // Estimate-level events
        foreach ($deal->estimates as $est) {
            $listeners["echo-private:estimate.{$est->id},.estimate.status.changed"] = 'refreshDashboard';
        }

        // Deal-level events
        $listeners["echo-private:deal.{$deal->id},.deal.updated"] = 'refreshDashboard';
        $listeners["echo-private:deal.{$deal->id},.deal.note.added"] = 'refreshDashboard';
        $listeners["echo-private:deal.{$deal->id},.listing.scraped"] = 'refreshDashboard';
        $listeners["echo-private:deal.{$deal->id},.estimate.status.changed"] = 'refreshDashboard';

        return $listeners;
    }

    public function refreshDashboard(): void
    {
        unset($this->selectedDeal, $this->actionItems, $this->deals, $this->pastDeals, $this->customers, $this->customer, $this->customerIds);
    }

    public function startVideoCall(): void
    {
        $deal = $this->selectedDeal;
        if (! $deal) {
            return;
        }

        $videoCall = \App\Models\VideoCall::create([
            'callable_type' => \App\Models\Deal::class,
            'callable_id' => $deal->id,
            'initiated_by' => auth('customer')->id(),
            'room_id' => 'call-'.\Illuminate\Support\Str::uuid(),
            'status' => 'pending',
        ]);

        $this->redirect(route('video-call.test', $videoCall->room_id));
    }

    /**
     * Customer-friendly status label (hides internal kanban stages).
     */
    public function customerStatusForDeal(Deal $deal): array
    {
        $hasActiveJob = $deal->job && $deal->job->stage !== 'completed';

        if ($hasActiveJob) {
            return [
                'label' => __('In Progress'),
                'color' => 'blue',
            ];
        }

        // Check estimates on the selected deal (need to load if not loaded)
        $estimates = $deal->relationLoaded('estimates') ? $deal->estimates : $deal->estimates()->get();
        $hasAccepted = $estimates->contains('status', 'accepted');
        $hasSent = $estimates->contains('status', 'sent');

        if ($hasAccepted) {
            return ['label' => __('Approved'), 'color' => 'green'];
        }

        if ($hasSent) {
            return ['label' => __('Estimate Ready'), 'color' => 'yellow'];
        }

        if ($estimates->isNotEmpty()) {
            return ['label' => __('Preparing Estimate'), 'color' => 'zinc'];
        }

        return ['label' => __('New Project'), 'color' => 'zinc'];
    }
}; ?>

<div class="overflow-x-hidden">
    <div class="space-y-4 sm:space-y-6">
        {{-- Header --}}
        <div>
            <flux:heading size="xl">{{ __('Welcome, :name!', ['name' => auth('customer')->user()->name]) }}</flux:heading>
            <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
                {{ __('Your painting project hub') }}
            </flux:text>
        </div>

        @if (! $this->customer)
            <flux:callout variant="info">
                <flux:callout.heading>{{ __('Welcome to Your Customer Portal') }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __('Your account has been set up. Once we create your customer profile and project, you\'ll be able to track everything here.') }}
                </flux:callout.text>
            </flux:callout>
        @elseif ($this->deals->isEmpty() && $this->pastDeals->isEmpty())
            <flux:callout variant="info">
                <flux:callout.heading>{{ __('No Active Projects') }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __('You don\'t have any active projects yet. Request a free estimate to get started!') }}
                </flux:callout.text>
            </flux:callout>

            {{-- CTA for no-project state --}}
            <flux:card>
                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <flux:heading size="lg">{{ __('Ready to Get Started?') }}</flux:heading>
                        <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
                            {{ __('Tell us about your project and we\'ll provide a free estimate.') }}
                        </flux:text>
                    </div>
                    <flux:button variant="primary" icon="plus" href="{{ route('customer.onboarding', ['new_project' => true]) }}" class="w-full sm:w-auto">
                        {{ __('Request a Free Estimate') }}
                    </flux:button>
                </div>
            </flux:card>
        @else
            {{-- Project Switcher --}}
            <flux:card class="!p-3 overflow-hidden">
                {{-- Mobile: native select --}}
                <div class="sm:hidden">
                    <div class="flex items-center gap-2 w-full">
                        <div class="min-w-0 flex-1">
                            <select
                                wire:change="selectDeal($event.target.value)"
                                class="w-full truncate rounded-lg border border-zinc-200 bg-white px-3 py-2.5 text-sm font-medium text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                            >
                                @foreach ($this->deals as $deal)
                                    <option
                                        wire:key="deal-mobile-{{ $deal->id }}"
                                        value="{{ $deal->id }}"
                                        {{ $deal->id === $this->selectedDealId ? 'selected' : '' }}
                                    >{{ $deal->property?->address ?? $deal->title }}</option>
                                @endforeach
                            </select>
                        </div>
                        <flux:button href="{{ route('customer.onboarding', ['new_project' => true]) }}" variant="ghost" size="sm" icon="plus" class="shrink-0">
                            {{ __('New') }}
                        </flux:button>
                    </div>

                    @if ($this->selectedDeal)
                        @php $mobileStatus = $this->customerStatusForDeal($this->selectedDeal); @endphp
                        <div class="mt-2 flex items-center justify-between rounded-lg bg-zinc-50 px-3 py-2 dark:bg-zinc-800/50">
                            <span class="truncate text-sm text-zinc-700 dark:text-zinc-300">{{ $this->selectedDeal->title }}</span>
                            <flux:badge size="sm" color="{{ $mobileStatus['color'] }}" class="ml-2 shrink-0">
                                {{ $mobileStatus['label'] }}
                            </flux:badge>
                        </div>
                    @endif
                </div>

                {{-- Desktop: horizontal buttons --}}
                <div class="hidden sm:flex sm:flex-wrap sm:items-center sm:gap-2">
                    <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400 mr-1">{{ __('Projects:') }}</flux:text>

                    @foreach ($this->deals as $deal)
                        @php
                            $isSelected = $deal->id === $this->selectedDealId;
                            $status = $this->customerStatusForDeal($deal);
                        @endphp
                        <button
                            wire:key="deal-switcher-{{ $deal->id }}"
                            wire:click="selectDeal({{ $deal->id }})"
                            class="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm transition-colors {{ $isSelected ? 'border-blue-500 bg-blue-50 dark:border-blue-400 dark:bg-blue-950' : 'border-zinc-200 bg-white hover:border-zinc-300 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:hover:border-zinc-600' }}"
                        >
                            <div class="text-left min-w-0">
                                <div class="font-medium truncate {{ $isSelected ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-900 dark:text-zinc-100' }}">
                                    {{ $deal->title }}
                                </div>
                                @if ($deal->property?->address)
                                    <div class="text-xs text-zinc-500 dark:text-zinc-400 truncate">{{ $deal->property->address }}</div>
                                @endif
                            </div>
                            <flux:badge size="sm" color="{{ $status['color'] }}">
                                {{ $status['label'] }}
                            </flux:badge>
                        </button>
                    @endforeach

                    <flux:button href="{{ route('customer.onboarding', ['new_project' => true]) }}" variant="ghost" size="sm" icon="plus">
                        {{ __('New Project') }}
                    </flux:button>

                    @if ($this->pastDeals->isNotEmpty())
                        <flux:dropdown>
                            <flux:button variant="ghost" size="sm" icon="clock">
                                {{ __('Past Projects') }}
                                <flux:badge size="sm" color="zinc" class="ml-1">{{ $this->pastDeals->count() }}</flux:badge>
                            </flux:button>

                            <flux:menu class="w-80">
                                @foreach ($this->pastDeals as $pastDeal)
                                    @php
                                        $pastEstimate = $pastDeal->estimates->firstWhere('status', 'accepted');
                                        $pastJob = $pastDeal->job;
                                        $pastInvoices = $pastJob?->invoices ?? collect();
                                    @endphp
                                    <div wire:key="past-{{ $pastDeal->id }}" class="px-3 py-2">
                                        <div class="flex items-center justify-between mb-1">
                                            <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100 truncate">{{ $pastDeal->title }}</span>
                                            @if ($pastEstimate && (float) $pastEstimate->total > 0)
                                                <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400 ml-2 shrink-0">${{ number_format($pastEstimate->total, 2) }}</span>
                                            @endif
                                        </div>
                                        @if ($pastDeal->property)
                                            <p class="text-xs text-zinc-500 dark:text-zinc-400 truncate mb-2">{{ $pastDeal->property->address }}</p>
                                        @endif
                                        <div class="flex flex-wrap items-center gap-2">
                                            @if ($pastEstimate)
                                                <a href="{{ route('customer.estimates.show', $pastEstimate) }}" class="inline-flex items-center gap-1 text-xs text-blue-600 dark:text-blue-400 hover:underline">
                                                    <flux:icon name="document-text" class="w-3 h-3" />
                                                    {{ __('Estimate') }}
                                                </a>
                                            @endif
                                            @if ($pastJob)
                                                <a href="{{ route('customer.jobs.show', $pastJob) }}" class="inline-flex items-center gap-1 text-xs text-blue-600 dark:text-blue-400 hover:underline">
                                                    <flux:icon name="paint-brush" class="w-3 h-3" />
                                                    {{ __('Job') }}
                                                </a>
                                            @endif
                                            @foreach ($pastInvoices as $pastInvoice)
                                                <a href="{{ route('customer.invoices.show', $pastInvoice) }}" class="inline-flex items-center gap-1 text-xs text-blue-600 dark:text-blue-400 hover:underline">
                                                    <flux:icon name="credit-card" class="w-3 h-3" />
                                                    {{ __('Invoice') }} #{{ $pastInvoice->invoice_number ?? $pastInvoice->id }}
                                                </a>
                                            @endforeach
                                        </div>
                                    </div>
                                    @if (! $loop->last)
                                        <flux:separator />
                                    @endif
                                @endforeach
                            </flux:menu>
                        </flux:dropdown>
                    @endif
                </div>

                {{-- Past projects link (mobile only) --}}
                @if ($this->pastDeals->isNotEmpty())
                    <div class="mt-2 pt-2 border-t border-zinc-100 dark:border-zinc-700 sm:hidden">
                        <flux:dropdown>
                            <flux:button variant="ghost" size="sm" icon="clock" class="w-full justify-center">
                                {{ __('Past Projects') }}
                                <flux:badge size="sm" color="zinc" class="ml-1">{{ $this->pastDeals->count() }}</flux:badge>
                            </flux:button>

                            <flux:menu class="w-72">
                                @foreach ($this->pastDeals as $pastDeal)
                                    @php
                                        $pastEstimate = $pastDeal->estimates->firstWhere('status', 'accepted');
                                        $pastJob = $pastDeal->job;
                                    @endphp
                                    <div wire:key="past-mobile-{{ $pastDeal->id }}" class="px-3 py-2">
                                        <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100 truncate block">{{ $pastDeal->title }}</span>
                                        <div class="mt-1 flex items-center gap-2">
                                            @if ($pastEstimate)
                                                <a href="{{ route('customer.estimates.show', $pastEstimate) }}" class="inline-flex items-center gap-1 text-xs text-blue-600 dark:text-blue-400">
                                                    <flux:icon name="document-text" class="w-3 h-3" />
                                                    {{ __('Estimate') }}
                                                </a>
                                            @endif
                                            @if ($pastJob)
                                                <a href="{{ route('customer.jobs.show', $pastJob) }}" class="inline-flex items-center gap-1 text-xs text-blue-600 dark:text-blue-400">
                                                    <flux:icon name="paint-brush" class="w-3 h-3" />
                                                    {{ __('Job') }}
                                                </a>
                                            @endif
                                        </div>
                                    </div>
                                    @if (! $loop->last)
                                        <flux:separator />
                                    @endif
                                @endforeach
                            </flux:menu>
                        </flux:dropdown>
                    </div>
                @endif
            </flux:card>

            {{-- Property & Contact Info --}}
            @if ($this->selectedDeal?->property)
                @php $property = $this->selectedDeal->property; @endphp
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-6 rounded-xl bg-zinc-50 dark:bg-zinc-800/50 px-4 py-3">
                    <div class="flex items-center gap-2 min-w-0">
                        <flux:icon name="map-pin" class="w-4 h-4 text-zinc-400 shrink-0" />
                        <span class="text-sm text-zinc-700 dark:text-zinc-300 truncate">
                            {{ $property->address }}@if ($property->city), {{ $property->city }}@endif @if ($property->state){{ $property->state }}@endif
                        </span>
                    </div>
                    @if ($this->selectedDeal->customer?->email)
                        <div class="flex items-center gap-2 min-w-0">
                            <flux:icon name="envelope" class="w-4 h-4 text-zinc-400 shrink-0" />
                            <span class="text-sm text-zinc-500 dark:text-zinc-400 truncate">{{ $this->selectedDeal->customer->email }}</span>
                        </div>
                    @endif
                    @if ($this->selectedDeal->customer?->phone)
                        <div class="flex items-center gap-2 min-w-0">
                            <flux:icon name="phone" class="w-4 h-4 text-zinc-400 shrink-0" />
                            <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ $this->selectedDeal->customer->phone }}</span>
                        </div>
                    @endif
                </div>
            @endif

            {{-- Quick Actions --}}
            @if ($this->selectedDeal)
                <div class="flex items-center gap-2">
                    <flux:button size="sm" icon="video-camera" variant="ghost" wire:click="startVideoCall">
                        {{ __('Start Video Call') }}
                    </flux:button>
                    @if ($this->selectedDeal->chatThread)
                        <flux:button size="sm" icon="chat-bubble-left" variant="ghost" :href="route('customer.chat')" wire:navigate>
                            {{ __('Chat') }}
                        </flux:button>
                    @endif
                </div>
            @endif

            @if ($this->selectedDeal && $this->customer && !in_array($this->selectedDeal->customer_id, $this->customerIds))
                <flux:callout variant="info">
                    <flux:callout.heading>{{ __('Shared Project') }}</flux:callout.heading>
                    <flux:callout.text>
                        {{ __('This project was shared with you by :name. Your access expires in :days days.', [
                            'name' => $this->selectedDeal->customer->full_name,
                            'days' => $this->selectedDeal->activeCollaborators()->whereIn('customer_id', $this->customerIds)->first()?->daysRemaining() ?? 0,
                        ]) }}
                    </flux:callout.text>
                </flux:callout>
            @endif

            @if ($this->selectedDeal)
                @php
                    $deal = $this->selectedDeal;
                    $estimates = $deal->estimates;
                    $job = $deal->job;
                    $property = $deal->property;
                    $acceptedEstimate = $estimates->firstWhere('status', 'accepted');
                    $sentEstimate = $estimates->firstWhere('status', 'sent');

                    // Timeline stage logic
                    $estimateStatus = 'future';
                    $estimateLabel = __('Not started');
                    if ($acceptedEstimate) {
                        $estimateStatus = 'completed';
                        $estimateLabel = __('Accepted');
                    } elseif ($sentEstimate) {
                        $estimateStatus = 'attention';
                        $estimateLabel = __('Needs review');
                    } elseif ($estimates->isNotEmpty()) {
                        $estimateStatus = 'in_progress';
                        $estimateLabel = __('In progress');
                    }

                    $jobStatus = 'future';
                    $jobLabel = __('Not started');
                    if ($job) {
                        if ($job->stage === 'completed') {
                            $jobStatus = 'completed';
                            $jobLabel = __('Completed');
                        } else {
                            $jobStatus = 'in_progress';
                            $jobLabel = __('In progress');
                        }
                    }

                    $invoiceStatus = 'future';
                    $invoiceLabel = __('Upcoming');
                    if ($job && $job->relationLoaded('invoices') && $job->invoices->isNotEmpty()) {
                        $paidAll = $job->invoices->every(fn ($inv) => $inv->status === 'paid');
                        $hasOverdue = $job->invoices->contains(fn ($inv) => $inv->status === 'overdue');
                        $hasSent = $job->invoices->contains(fn ($inv) => in_array($inv->status, ['sent', 'pending']));
                        if ($paidAll) {
                            $invoiceStatus = 'completed';
                            $invoiceLabel = __('Paid');
                        } elseif ($hasOverdue) {
                            $invoiceStatus = 'attention';
                            $invoiceLabel = __('Overdue');
                        } elseif ($hasSent) {
                            $invoiceStatus = 'in_progress';
                            $invoiceLabel = __('Due');
                        }
                    }
                @endphp

                {{-- Status Timeline --}}
                @php
                    $estimateLink = ($sentEstimate) ? route('customer.estimates.show', $sentEstimate) : (($acceptedEstimate) ? route('customer.estimates.show', $acceptedEstimate) : null);
                    $jobLink = $job ? route('customer.jobs.show', $job) : null;
                    $invoiceLink = ($job && $job->relationLoaded('invoices') && $job->invoices->isNotEmpty()) ? route('customer.invoices.index') : null;
                @endphp
                <flux:card class="!px-2 sm:!px-6">
                    <div class="flex items-center justify-between gap-2 sm:gap-4">
                        {{-- Estimate Stage --}}
                        @php
                            $estColor = match($estimateStatus) {
                                'completed' => 'text-green-500 dark:text-green-400',
                                'in_progress' => 'text-blue-500 dark:text-blue-400',
                                'attention' => 'text-amber-500 dark:text-amber-400',
                                default => 'text-zinc-300 dark:text-zinc-600',
                            };
                            $estBg = match($estimateStatus) {
                                'completed' => 'bg-green-100 dark:bg-green-900',
                                'in_progress' => 'bg-blue-100 dark:bg-blue-900',
                                'attention' => 'bg-amber-100 dark:bg-amber-900',
                                default => 'bg-zinc-100 dark:bg-zinc-800',
                            };
                        @endphp
                        @if ($estimateLink)
                            <a href="{{ $estimateLink }}" class="group flex flex-1 flex-col items-center text-center">
                                <div class="rounded-full p-2 sm:p-3 {{ $estBg }} group-hover:ring-2 group-hover:ring-offset-1 group-hover:ring-current transition">
                                    <flux:icon name="document-text" class="h-5 w-5 sm:h-6 sm:w-6 {{ $estColor }}" />
                                </div>
                                <flux:text class="mt-1.5 text-xs sm:text-sm font-medium group-hover:underline">{{ __('Estimate') }}</flux:text>
                                <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">{{ $estimateLabel }}</flux:text>
                                @if ($estimateStatus === 'completed' && $acceptedEstimate?->accepted_at)
                                    <flux:text class="text-[11px] text-zinc-400 dark:text-zinc-500">{{ $acceptedEstimate->accepted_at->format('M j') }}</flux:text>
                                @endif
                            </a>
                        @else
                            <div class="flex flex-1 flex-col items-center text-center">
                                <div class="rounded-full p-2 sm:p-3 {{ $estBg }}">
                                    <flux:icon name="document-text" class="h-5 w-5 sm:h-6 sm:w-6 {{ $estColor }}" />
                                </div>
                                <flux:text class="mt-1.5 text-xs sm:text-sm font-medium">{{ __('Estimate') }}</flux:text>
                                <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">{{ $estimateLabel }}</flux:text>
                            </div>
                        @endif

                        {{-- Connector --}}
                        <div class="h-0.5 flex-1 max-w-8 sm:max-w-12 {{ $estimateStatus === 'completed' ? 'bg-green-300 dark:bg-green-700' : 'bg-zinc-200 dark:bg-zinc-700' }}"></div>

                        {{-- Job Stage --}}
                        @php
                            $jobColor = match($jobStatus) {
                                'completed' => 'text-green-500 dark:text-green-400',
                                'in_progress' => 'text-blue-500 dark:text-blue-400',
                                default => 'text-zinc-300 dark:text-zinc-600',
                            };
                            $jobBg = match($jobStatus) {
                                'completed' => 'bg-green-100 dark:bg-green-900',
                                'in_progress' => 'bg-blue-100 dark:bg-blue-900',
                                default => 'bg-zinc-100 dark:bg-zinc-800',
                            };
                        @endphp
                        @if ($jobLink)
                            <a href="{{ $jobLink }}" class="group flex flex-1 flex-col items-center text-center">
                                <div class="rounded-full p-2 sm:p-3 {{ $jobBg }} group-hover:ring-2 group-hover:ring-offset-1 group-hover:ring-current transition">
                                    <flux:icon name="paint-brush" class="h-5 w-5 sm:h-6 sm:w-6 {{ $jobColor }}" />
                                </div>
                                <flux:text class="mt-1.5 text-xs sm:text-sm font-medium group-hover:underline">{{ __('Painting') }}</flux:text>
                                <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">{{ $jobLabel }}</flux:text>
                                @if ($jobStatus === 'completed' && $job->updated_at)
                                    <flux:text class="text-[11px] text-zinc-400 dark:text-zinc-500">{{ $job->updated_at->format('M j') }}</flux:text>
                                @endif
                            </a>
                        @else
                            <div class="flex flex-1 flex-col items-center text-center">
                                <div class="rounded-full p-2 sm:p-3 {{ $jobBg }}">
                                    <flux:icon name="paint-brush" class="h-5 w-5 sm:h-6 sm:w-6 {{ $jobColor }}" />
                                </div>
                                <flux:text class="mt-1.5 text-xs sm:text-sm font-medium">{{ __('Painting') }}</flux:text>
                                <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">{{ $jobLabel }}</flux:text>
                            </div>
                        @endif

                        {{-- Connector --}}
                        <div class="h-0.5 flex-1 max-w-8 sm:max-w-12 {{ $jobStatus === 'completed' ? 'bg-green-300 dark:bg-green-700' : 'bg-zinc-200 dark:bg-zinc-700' }}"></div>

                        {{-- Invoice Stage --}}
                        @php
                            $invColor = match($invoiceStatus) {
                                'completed' => 'text-green-500 dark:text-green-400',
                                'in_progress' => 'text-blue-500 dark:text-blue-400',
                                'attention' => 'text-red-500 dark:text-red-400',
                                default => 'text-zinc-300 dark:text-zinc-600',
                            };
                            $invBg = match($invoiceStatus) {
                                'completed' => 'bg-green-100 dark:bg-green-900',
                                'in_progress' => 'bg-blue-100 dark:bg-blue-900',
                                'attention' => 'bg-red-100 dark:bg-red-900',
                                default => 'bg-zinc-100 dark:bg-zinc-800',
                            };
                            $paidInvoice = ($invoiceStatus === 'completed' && $job) ? $job->invoices->firstWhere('status', 'paid') : null;
                        @endphp
                        @if ($invoiceLink)
                            <a href="{{ $invoiceLink }}" class="group flex flex-1 flex-col items-center text-center">
                                <div class="rounded-full p-2 sm:p-3 {{ $invBg }} group-hover:ring-2 group-hover:ring-offset-1 group-hover:ring-current transition">
                                    <flux:icon name="credit-card" class="h-5 w-5 sm:h-6 sm:w-6 {{ $invColor }}" />
                                </div>
                                <flux:text class="mt-1.5 text-xs sm:text-sm font-medium group-hover:underline">{{ __('Invoice') }}</flux:text>
                                <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">{{ $invoiceLabel }}</flux:text>
                                @if ($paidInvoice?->paid_at)
                                    <flux:text class="text-[11px] text-zinc-400 dark:text-zinc-500">{{ $paidInvoice->paid_at->format('M j') }}</flux:text>
                                @endif
                            </a>
                        @else
                            <div class="flex flex-1 flex-col items-center text-center">
                                <div class="rounded-full p-2 sm:p-3 {{ $invBg }}">
                                    <flux:icon name="credit-card" class="h-5 w-5 sm:h-6 sm:w-6 {{ $invColor }}" />
                                </div>
                                <flux:text class="mt-1.5 text-xs sm:text-sm font-medium">{{ __('Invoice') }}</flux:text>
                                <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">{{ $invoiceLabel }}</flux:text>
                            </div>
                        @endif
                    </div>
                </flux:card>

                {{-- Action Items Banner --}}
                @if (count($this->actionItems) > 0)
                    <flux:callout variant="warning">
                        <flux:callout.heading>{{ __('Action Needed') }}</flux:callout.heading>
                        <flux:callout.text>
                            <ul class="mt-2 space-y-3">
                                @foreach ($this->actionItems as $item)
                                    <li class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between sm:gap-4">
                                        <span class="flex items-center gap-2 min-w-0">
                                            <flux:icon name="{{ $item['icon'] }}" class="h-4 w-4 shrink-0" />
                                            <span class="text-sm">{{ $item['text'] }}</span>
                                        </span>
                                        <flux:button variant="primary" size="sm" href="{{ $item['route'] }}" class="w-full sm:w-auto shrink-0">
                                            @if ($item['type'] === 'estimate_review')
                                                {{ __('Review Your Estimate') }}
                                            @else
                                                {{ __('Take Action') }}
                                            @endif
                                        </flux:button>
                                    </li>
                                @endforeach
                            </ul>
                        </flux:callout.text>
                    </flux:callout>
                @endif

                {{-- Job Progress Card (high priority when active) --}}
                @if ($job)
                    @php
                        $jobStages = array_keys(\App\Models\Job::STAGES);
                        $jobCurrentIndex = array_search($job->stage, $jobStages);
                        $isCompleted = $job->isCompleted();
                    @endphp
                    <flux:card class="!p-5 sm:!p-6">
                        <div class="flex items-start justify-between gap-4 mb-4">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-full bg-sky-100 dark:bg-sky-900/50 flex items-center justify-center shrink-0">
                                    <flux:icon name="paint-brush" class="w-5 h-5 text-sky-600 dark:text-sky-400" />
                                </div>
                                <div>
                                    <flux:heading size="lg">{{ __('Job Progress') }}</flux:heading>
                                    @if ($job->scheduled_date)
                                        <flux:text class="text-sm mt-0.5">
                                            {{ __('Scheduled: :date', ['date' => $job->scheduled_date->format('M j, Y')]) }}
                                        </flux:text>
                                    @endif
                                </div>
                            </div>
                            <flux:badge size="lg" :color="$isCompleted ? 'green' : 'yellow'">
                                {{ $isCompleted ? __('Completed') : ucfirst(str_replace('_', ' ', $job->stage)) }}
                            </flux:badge>
                        </div>

                        {{-- Stage Progress Bar --}}
                        <div class="flex items-center gap-1 mb-4">
                            @foreach ($jobStages as $index => $stage)
                                <div
                                    wire:key="stage-bar-{{ $stage }}"
                                    class="h-2.5 flex-1 rounded-full transition-all {{ $index <= $jobCurrentIndex ? 'bg-sky-500 dark:bg-sky-400' : 'bg-zinc-200 dark:bg-zinc-700' }}"
                                ></div>
                            @endforeach
                        </div>

                        <a
                            href="{{ route('customer.jobs.show', $job) }}"
                            class="block w-full py-3 bg-sky-600 hover:bg-sky-700 dark:bg-sky-500 dark:hover:bg-sky-400 text-white font-bold text-base rounded-xl shadow transition-colors text-center min-h-[48px] leading-[48px]"
                        >
                            {{ __('View Job Details') }} &rarr;
                        </a>
                    </flux:card>
                @endif

                {{-- Estimate CTA Cards --}}
                <div class="space-y-4">
                        @if ($estimates->isNotEmpty())
                            @foreach ($estimates as $estimate)
                                @php
                                    $badgeColor = match($estimate->status) {
                                        'draft' => 'zinc',
                                        'sent' => 'blue',
                                        'accepted' => 'green',
                                        'rejected' => 'red',
                                        'expired' => 'zinc',
                                        default => 'zinc',
                                    };
                                @endphp
                                <flux:card wire:key="estimate-cta-{{ $estimate->id }}" class="!p-5 sm:!p-6 {{ $estimate->status === 'sent' ? 'border-l-4 border-l-amber-400 dark:border-l-amber-500' : '' }}">
                                    <div class="flex items-start justify-between gap-4">
                                        <div class="flex items-center gap-3">
                                            <div class="w-10 h-10 rounded-full {{ $estimate->status === 'sent' ? 'bg-amber-100 dark:bg-amber-900/50' : 'bg-sky-100 dark:bg-sky-900/50' }} flex items-center justify-center shrink-0">
                                                <flux:icon name="document-text" class="w-5 h-5 {{ $estimate->status === 'sent' ? 'text-amber-600 dark:text-amber-400' : 'text-sky-600 dark:text-sky-400' }}" />
                                            </div>
                                            <div>
                                                <flux:heading size="lg">{{ __('Your Painting Estimate') }}</flux:heading>
                                                <flux:text class="text-sm mt-0.5">{{ $estimate->estimate_number }}</flux:text>
                                            </div>
                                        </div>
                                        <flux:badge size="lg" color="{{ $badgeColor }}">
                                            {{ $estimate->status_label }}
                                        </flux:badge>
                                    </div>

                                    @if ($estimate->status === 'sent' && $estimate->total)
                                        <div class="mt-4 text-center">
                                            <p class="text-3xl font-bold text-zinc-900 dark:text-zinc-100">${{ number_format($estimate->total, 2) }}</p>
                                        </div>
                                        <a
                                            href="{{ route('customer.estimates.show', $estimate) }}"
                                            class="mt-4 block w-full py-3 bg-sky-600 hover:bg-sky-700 dark:bg-sky-500 dark:hover:bg-sky-400 text-white font-bold text-base rounded-xl shadow transition-colors text-center min-h-[48px]"
                                        >
                                            {{ __('View Your Full Proposal') }}
                                        </a>
                                    @elseif ($estimate->status === 'accepted' && $estimate->total)
                                        <div class="mt-4">
                                            <p class="text-3xl font-bold text-zinc-900 dark:text-zinc-100">${{ number_format($estimate->total, 2) }}</p>
                                        </div>
                                        <a
                                            href="{{ route('customer.estimates.show', $estimate) }}"
                                            class="mt-3 block w-full py-3 bg-sky-600 hover:bg-sky-700 dark:bg-sky-500 dark:hover:bg-sky-400 text-white font-bold text-base rounded-xl shadow transition-colors text-center min-h-[48px]"
                                        >
                                            {{ __('View Your Estimate') }}
                                        </a>
                                    @else
                                        {{-- Draft: no pricing visible --}}
                                        <button
                                            wire:click="openEstimateOverlay({{ $estimate->id }})"
                                            class="mt-4 w-full py-3 bg-sky-600 hover:bg-sky-700 dark:bg-sky-500 dark:hover:bg-sky-400 text-white font-bold text-base rounded-xl shadow transition-colors flex items-center justify-center gap-2 min-h-[48px]"
                                        >
                                            <flux:icon name="eye" class="w-5 h-5" />
                                            {{ __('Help Build Your Estimate') }}
                                        </button>
                                    @endif
                                </flux:card>
                            @endforeach
                        @else
                            <flux:card>
                                <div class="py-8 text-center">
                                    <flux:icon name="document-text" class="mx-auto h-12 w-12 text-zinc-300 dark:text-zinc-600" />
                                    <flux:text class="mt-3 text-zinc-500 dark:text-zinc-400">{{ __('No estimates yet for this project.') }}</flux:text>
                                </div>
                            </flux:card>
                        @endif
                </div>

                {{-- Add Rooms CTA --}}
                @php
                    $hasDraftEstimate = $estimates->contains(fn ($e) => $e->status === 'draft');
                    $hasAnyAreas = $estimates->contains(fn ($e) => $e->areas->isNotEmpty());
                @endphp
                @if ($hasDraftEstimate && ! $hasAnyAreas)
                    <div class="rounded-2xl border-2 border-dashed border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 p-5 sm:p-6">
                        <div class="flex items-start gap-4">
                            <div class="w-12 h-12 rounded-full bg-sky-100 dark:bg-sky-900/50 flex items-center justify-center shrink-0">
                                <flux:icon name="home" class="w-6 h-6 text-sky-600 dark:text-sky-400" />
                            </div>
                            <div class="flex-1">
                                <flux:heading size="lg">{{ __('Tell Us About Your Home') }}</flux:heading>
                                <flux:text class="mt-1 text-sm">
                                    {{ __('Add the rooms you want painted, upload photos, and share any details. The more we know, the better your estimate.') }}
                                </flux:text>
                                <button
                                    wire:click="openEstimateOverlay({{ $estimates->first()->id }})"
                                    class="mt-3 inline-flex items-center gap-2 px-5 py-3 bg-sky-600 hover:bg-sky-700 dark:bg-sky-500 dark:hover:bg-sky-400 text-white font-bold text-sm rounded-xl shadow transition-colors min-h-[48px]"
                                >
                                    <flux:icon name="plus" class="w-5 h-5" />
                                    {{ __('Add Rooms & Photos') }}
                                </button>
                            </div>
                        </div>
                    </div>
                @endif

                {{-- Collaborators / Shared Access --}}
                <livewire:customer.collaborators :dealId="$this->selectedDealId" :key="'collaborators-'.$this->selectedDealId" />
            @endif

        @endif
    </div>

    {{-- Area Carousel Overlay --}}
    @if ($showAreaCarousel && $carouselEstimateId)
        <div
            x-data
            x-on:close-area-carousel.window="$wire.closeEstimateOverlay()"
        >
            <livewire:customer.estimates.area-carousel
                :estimate="App\Models\Estimate::find($carouselEstimateId)"
                :key="'carousel-'.$carouselEstimateId"
            />
        </div>
    @endif
</div>
