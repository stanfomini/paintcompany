<?php

use App\Models\Customer;
use App\Models\Deal;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.customer', ['title' => 'Dashboard'])] class extends Component {
    public ?int $selectedDealId = null;

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
            ->with(['property', 'estimates', 'job.prepPlan', 'job.invoices', 'chatThread'])
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
        }

        // Estimate-level events
        foreach ($deal->estimates as $est) {
            $listeners["echo-private:estimate.{$est->id},.estimate.status.changed"] = 'refreshDashboard';
        }

        // Deal-level events
        $listeners["echo-private:deal.{$deal->id},.listing.scraped"] = 'refreshDashboard';

        return $listeners;
    }

    public function refreshDashboard(): void
    {
        unset($this->selectedDeal, $this->actionItems, $this->deals, $this->pastDeals, $this->customers, $this->customer, $this->customerIds);
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
                        <flux:button href="{{ route('customer.onboarding', ['new_project' => true]) }}" variant="ghost" size="sm" icon="plus" class="shrink-0" />
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
                <flux:card class="!px-2 sm:!px-6">
                    <div class="flex items-center justify-between gap-2 sm:gap-4">
                        {{-- Estimate Stage --}}
                        <div class="flex flex-1 flex-col items-center text-center">
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
                            <div class="rounded-full p-2 sm:p-3 {{ $estBg }}">
                                <flux:icon name="document-text" class="h-5 w-5 sm:h-6 sm:w-6 {{ $estColor }}" />
                            </div>
                            <flux:text class="mt-1.5 text-xs sm:text-sm font-medium">{{ __('Estimate') }}</flux:text>
                            <flux:text class="text-[10px] sm:text-xs text-zinc-500 dark:text-zinc-400">{{ $estimateLabel }}</flux:text>
                        </div>

                        {{-- Connector --}}
                        <div class="h-0.5 flex-1 max-w-8 sm:max-w-12 {{ $estimateStatus === 'completed' ? 'bg-green-300 dark:bg-green-700' : 'bg-zinc-200 dark:bg-zinc-700' }}"></div>

                        {{-- Job Stage --}}
                        <div class="flex flex-1 flex-col items-center text-center">
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
                            <div class="rounded-full p-2 sm:p-3 {{ $jobBg }}">
                                <flux:icon name="paint-brush" class="h-5 w-5 sm:h-6 sm:w-6 {{ $jobColor }}" />
                            </div>
                            <flux:text class="mt-1.5 text-xs sm:text-sm font-medium">{{ __('Painting') }}</flux:text>
                            <flux:text class="text-[10px] sm:text-xs text-zinc-500 dark:text-zinc-400">{{ $jobLabel }}</flux:text>
                        </div>

                        {{-- Connector --}}
                        <div class="h-0.5 flex-1 max-w-8 sm:max-w-12 {{ $jobStatus === 'completed' ? 'bg-green-300 dark:bg-green-700' : 'bg-zinc-200 dark:bg-zinc-700' }}"></div>

                        {{-- Invoice Stage --}}
                        <div class="flex flex-1 flex-col items-center text-center">
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
                            @endphp
                            <div class="rounded-full p-2 sm:p-3 {{ $invBg }}">
                                <flux:icon name="credit-card" class="h-5 w-5 sm:h-6 sm:w-6 {{ $invColor }}" />
                            </div>
                            <flux:text class="mt-1.5 text-xs sm:text-sm font-medium">{{ __('Invoice') }}</flux:text>
                            <flux:text class="text-[10px] sm:text-xs text-zinc-500 dark:text-zinc-400">{{ $invoiceLabel }}</flux:text>
                        </div>
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
                                            {{ __('View') }}
                                        </flux:button>
                                    </li>
                                @endforeach
                            </ul>
                        </flux:callout.text>
                    </flux:callout>
                @endif

                {{-- Estimates Section --}}
                <flux:card>
                    <flux:heading size="lg" class="mb-4">{{ __('Estimates') }}</flux:heading>

                    @if ($estimates->isNotEmpty())
                        <div class="space-y-3">
                            @foreach ($estimates as $estimate)
                                @php
                                    $badgeColor = match($estimate->status) {
                                        'draft' => 'zinc',
                                        'sent' => 'yellow',
                                        'accepted' => 'green',
                                        'rejected' => 'red',
                                        'expired' => 'zinc',
                                        default => 'zinc',
                                    };
                                @endphp
                                <div wire:key="estimate-{{ $estimate->id }}" class="rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800">
                                    <div class="flex items-center justify-between gap-2">
                                        <div class="flex items-center gap-2 min-w-0">
                                            <flux:icon name="document-text" class="h-4 w-4 shrink-0 text-zinc-400" />
                                            <span class="text-sm font-medium truncate">{{ $estimate->estimate_number }}</span>
                                        </div>
                                        <flux:badge size="sm" color="{{ $badgeColor }}" class="shrink-0">
                                            {{ $estimate->status_label }}
                                        </flux:badge>
                                    </div>
                                    <div class="mt-2 flex items-center justify-between gap-2">
                                        @if (in_array($estimate->status, ['sent', 'accepted']))
                                            <span class="text-sm text-zinc-500 dark:text-zinc-400">${{ number_format($estimate->total, 2) }}</span>
                                        @else
                                            <span></span>
                                        @endif
                                        @if ($estimate->status === 'sent')
                                            <flux:button variant="primary" size="sm" href="{{ route('customer.estimates.show', $estimate) }}">
                                                {{ __('Review') }}
                                            </flux:button>
                                        @elseif ($estimate->status === 'draft')
                                            <flux:button variant="ghost" size="sm" href="{{ route('customer.estimates.show', $estimate) }}">
                                                {{ __('Collaborate') }}
                                            </flux:button>
                                        @elseif ($estimate->status === 'accepted')
                                            <flux:button variant="ghost" size="sm" href="{{ route('customer.estimates.show', $estimate) }}">
                                                {{ __('View') }}
                                            </flux:button>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="py-8 text-center">
                            <flux:icon name="document-text" class="mx-auto h-12 w-12 text-zinc-300 dark:text-zinc-600" />
                            <flux:text class="mt-3 text-zinc-500 dark:text-zinc-400">{{ __('No estimates yet for this project.') }}</flux:text>
                        </div>
                    @endif
                </flux:card>

                {{-- Job Section --}}
                @if ($job)
                    <flux:card>
                        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between mb-4">
                            <flux:heading size="lg">{{ __('Job Progress') }}</flux:heading>
                            <flux:button variant="ghost" size="sm" href="{{ route('customer.jobs.show', $job) }}" class="w-full sm:w-auto">
                                {{ __('View Details') }}
                            </flux:button>
                        </div>

                        {{-- Mini Stage Progress Bar --}}
                        @php
                            $stages = array_keys(\App\Models\Job::STAGES);
                            $currentIndex = array_search($job->stage, $stages);
                        @endphp
                        <div class="flex items-center gap-1 mb-3">
                            @foreach ($stages as $index => $stage)
                                <div
                                    wire:key="stage-{{ $stage }}"
                                    class="h-2 flex-1 rounded-full {{ $index <= $currentIndex ? 'bg-blue-500 dark:bg-blue-400' : 'bg-zinc-200 dark:bg-zinc-700' }}"
                                ></div>
                            @endforeach
                        </div>

                        <div class="flex items-center justify-between gap-2">
                            <div class="min-w-0">
                                @if ($job->scheduled_date)
                                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                                        {{ __('Scheduled: :date', ['date' => $job->scheduled_date->format('M j, Y')]) }}
                                    </flux:text>
                                @endif
                            </div>
                            <flux:badge size="sm" color="{{ $job->isCompleted() ? 'green' : 'blue' }}" class="shrink-0">
                                {{ $job->isCompleted() ? __('Completed') : __('In Progress') }}
                            </flux:badge>
                        </div>
                    </flux:card>
                @endif

                {{-- Property Info & Contact --}}
                <div class="grid grid-cols-1 gap-4 sm:gap-6 lg:grid-cols-2">
                    {{-- Property Info Card --}}
                    @if ($property)
                        <flux:card>
                            <flux:heading size="lg" class="mb-4">{{ __('Property') }}</flux:heading>
                            <div class="grid grid-cols-2 gap-3 sm:gap-4">
                                <div class="col-span-2">
                                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Address') }}</flux:text>
                                    <flux:text class="font-medium break-words">
                                        {{ $property->address }}@if ($property->city), {{ $property->city }}@endif
                                        @if ($property->state) {{ $property->state }}@endif
                                        @if ($property->zip) {{ $property->zip }}@endif
                                    </flux:text>
                                </div>
                                @if ($property->square_footage)
                                    <div>
                                        <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Sq Ft') }}</flux:text>
                                        <flux:text class="font-medium">{{ number_format($property->square_footage) }}</flux:text>
                                    </div>
                                @endif
                                @if ($property->year_built)
                                    <div>
                                        <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Year Built') }}</flux:text>
                                        <flux:text class="font-medium">{{ $property->year_built }}</flux:text>
                                    </div>
                                @endif
                                @if ($property->type)
                                    <div>
                                        <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Type') }}</flux:text>
                                        <flux:text class="font-medium capitalize">{{ $property->type }}</flux:text>
                                    </div>
                                @endif
                            </div>
                        </flux:card>
                    @endif

                    {{-- Contact Card --}}
                    <flux:card>
                        <flux:heading size="lg" class="mb-2">{{ __('Need Help?') }}</flux:heading>
                        <flux:text class="text-zinc-500 dark:text-zinc-400">
                            {{ __('Our team is here to assist you with any questions about your project.') }}
                        </flux:text>
                        <div class="mt-4">
                            <flux:button variant="primary" icon="chat-bubble-left-right" href="{{ route('customer.chat.index') }}" class="w-full sm:w-auto">
                                {{ __('Send a Message') }}
                            </flux:button>
                        </div>
                    </flux:card>
                </div>

                {{-- Collaborators / Shared Access --}}
                <livewire:customer.collaborators :dealId="$this->selectedDealId" :key="'collaborators-'.$this->selectedDealId" />
            @endif

        @endif
    </div>

</div>
