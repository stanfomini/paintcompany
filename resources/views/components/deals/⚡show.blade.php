<?php

use App\Models\Deal;
use App\Services\DealStageService;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public function boot(): void
    {
        if (! auth()->user()?->can('deals.view')) {
            abort(403);
        }
    }

    public Deal $deal;

    // Triage state
    public bool $showFollowUpModal = false;

    public ?string $followUpDate = null;

    public function mount(Deal $deal): void
    {
        $this->deal = $deal->load([
            'customer',
            'agent',
            'property',
            'assignedTo',
            'createdBy',
            'notes.user',
            'estimates',
            'job',
            'activities.user',
            'activities.assignee',
            'appointmentBooking',
            'originVendor',
        ]);

        // Clear merge indicator when deal is viewed
        if ($this->deal->last_merged_at) {
            $this->deal->update(['last_merged_at' => null, 'last_merged_source' => null]);
        }
    }

    public function getListeners(): array
    {
        return [
            'deal-updated' => 'refreshDeal',
            'estimate-created' => 'refreshDeal',
            'note-created' => 'refreshNotes',
            'note-updated' => 'refreshNotes',
            'note-deleted' => 'refreshNotes',
            "echo-private:deal.{$this->deal->id},.deal.updated" => 'refreshDeal',
            "echo-private:deal.{$this->deal->id},.deal.note.added" => 'refreshNotes',
            "echo-private:deal.{$this->deal->id},.estimate.status.changed" => 'refreshDeal',
            "echo-private:deal.{$this->deal->id},.listing.scraped" => 'refreshDeal',
        ];
    }

    public function refreshDeal(): void
    {
        $this->deal = $this->deal->fresh([
            'customer',
            'property',
            'assignedTo',
            'createdBy',
            'notes.user',
            'estimates',
            'job',
            'activities.user',
            'activities.assignee',
            'appointmentBooking',
            'originVendor',
        ]);
    }

    public function refreshNotes(): void
    {
        $this->deal = $this->deal->fresh(['notes.user']);
    }

    #[Computed]
    public function stageColor(): string
    {
        return match ($this->deal->stage) {
            'lead' => 'blue',
            'not_contacted' => 'zinc',
            'follow_up' => 'amber',
            'quote_scheduled' => 'purple',
            'quoted' => 'yellow',
            'signed' => 'green',
            'lost' => 'orange',
            'cancelled' => 'red',
            default => 'zinc',
        };
    }

    #[Computed]
    public function dealActivities()
    {
        return $this->deal->activities->sortByDesc('created_at');
    }

    /**
     * Triage actions (same as kanban).
     */
    public function triageTo(string $action): void
    {
        abort_unless(auth()->user()->can('deals.edit'), 403);

        if ($action === 'follow_up') {
            $this->followUpDate = now()->addDay()->toDateString();
            $this->showFollowUpModal = true;

            return;
        }

        app(DealStageService::class)->transitionTo($this->deal, $action);

        if (class_exists(\App\Events\DealUpdated::class)) {
            broadcast(new \App\Events\DealUpdated($this->deal, auth()->user()->name, 'stage_changed'))->toOthers();
        }

        $this->refreshDeal();
    }

    public function confirmFollowUp(): void
    {
        abort_unless(auth()->user()->can('deals.edit'), 403);

        app(DealStageService::class)->transitionTo($this->deal, 'follow_up', $this->followUpDate);
        $this->showFollowUpModal = false;
        $this->followUpDate = null;

        if (class_exists(\App\Events\DealUpdated::class)) {
            broadcast(new \App\Events\DealUpdated($this->deal, auth()->user()->name, 'stage_changed'))->toOthers();
        }

        $this->refreshDeal();
    }

    #[Computed]
    public function commissionChain(): array
    {
        return app(\App\Services\CommissionService::class)
            ->getCommissionChainForDeal($this->deal);
    }
}; ?>

<div>
    <div class="max-w-4xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-4">
                <flux:button variant="ghost" :href="route('deals.index')" icon="arrow-left" wire:navigate>
                    {{ __('Back') }}
                </flux:button>
                <flux:heading size="xl">{{ $deal->title }}</flux:heading>
                <flux:badge size="sm" color="{{ $this->stageColor }}">{{ $deal->stage_label }}</flux:badge>
            </div>
            @can('deals.edit')
                @livewire('deals.edit-modal', ['dealId' => $deal->id])
            @endcan
        </div>

        {{-- Customer Hub Banner --}}
        @if ($deal->customer)
            <a href="{{ route('customers.show', ['customer' => $deal->customer_id, 'deal' => $deal->id]) }}" wire:navigate class="flex items-center justify-between p-3 mb-4 rounded-lg bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 hover:bg-blue-100 dark:hover:bg-blue-900/30 transition-colors">
                <div class="flex items-center gap-2">
                    <flux:icon name="user" class="w-5 h-5 text-blue-600 dark:text-blue-400" />
                    <flux:text class="font-medium text-blue-700 dark:text-blue-300">{{ __('Open Customer Hub for') }} {{ $deal->customer->full_name }}</flux:text>
                </div>
                <flux:icon name="arrow-right" class="w-4 h-4 text-blue-500" />
            </a>
        @endif

        {{-- Lead Triage Actions --}}
        @can('deals.edit')
            @if ($deal->stage === 'lead')
                <div class="flex flex-wrap gap-2 mb-4 p-3 rounded-lg bg-zinc-50 dark:bg-zinc-900/50 border border-zinc-200 dark:border-zinc-700">
                    <flux:text size="sm" class="text-zinc-500 mr-2 self-center">{{ __('Triage:') }}</flux:text>
                    <flux:button size="sm" wire:click="triageTo('not_contacted')">
                        {{ __('No Answer') }}
                    </flux:button>
                    <flux:button size="sm" variant="ghost" wire:click="triageTo('follow_up')">
                        {{ __('Follow Up') }}
                    </flux:button>
                    <flux:button size="sm" variant="ghost" wire:click="triageTo('quote_scheduled')">
                        {{ __('Schedule Quote') }}
                    </flux:button>
                    <flux:button size="sm" variant="ghost" wire:click="triageTo('lost')" wire:confirm="{{ __('Mark this deal as lost?') }}">
                        {{ __('Mark Lost') }}
                    </flux:button>
                </div>
            @endif
        @endcan

        @if (session('success'))
            <flux:callout variant="success" class="mb-4">
                {{ session('success') }}
            </flux:callout>
        @endif

        {{-- Linked Job --}}
        @if ($deal->job)
            <div class="mb-4 p-3 rounded-lg border border-emerald-200 dark:border-emerald-800 bg-emerald-50 dark:bg-emerald-900/20">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <flux:icon name="wrench-screwdriver" class="w-5 h-5 text-emerald-600 dark:text-emerald-400" />
                        <flux:text class="font-medium text-emerald-700 dark:text-emerald-300">
                            {{ __('Job:') }} {{ $deal->job->job_number ?? '' }}
                        </flux:text>
                        @php
                            $jobStageColors = [
                                'estimate_accepted' => 'blue',
                                'scheduling_colors' => 'purple',
                                'job_prep' => 'amber',
                                'in_progress' => 'emerald',
                                'completed' => 'green',
                            ];
                        @endphp
                        <flux:badge size="sm" :color="$jobStageColors[$deal->job->stage] ?? 'zinc'">
                            {{ str_replace('_', ' ', ucwords($deal->job->stage, '_')) }}
                        </flux:badge>
                    </div>
                    <flux:button :href="route('jobs.show', $deal->job)" size="sm" variant="ghost" icon="arrow-top-right-on-square" wire:navigate>
                        {{ __('View Job') }}
                    </flux:button>
                </div>
            </div>
        @endif

        <div class="grid gap-6 md:grid-cols-2">
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                <flux:heading size="sm" class="mb-4">{{ __('Deal Information') }}</flux:heading>
                <dl class="space-y-3">
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Customer') }}</dt>
                        <dd class="mt-1">
                            @if ($deal->customer)
                                <a href="{{ route('customers.show', $deal->customer) }}" class="text-blue-600 hover:underline" wire:navigate>
                                    {{ $deal->customer->full_name }}
                                </a>
                            @elseif ($deal->agent)
                                <span class="text-zinc-500">{{ __('Agent lead:') }} </span>
                                <a href="{{ route('agents.show', $deal->agent_id) }}" class="text-blue-600 hover:underline" wire:navigate>
                                    {{ $deal->agent->name }}
                                </a>
                            @else
                                <flux:text class="text-zinc-400">{{ __('No customer assigned') }}</flux:text>
                            @endif
                        </dd>
                    </div>
                    @if ($deal->property)
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Property') }}</dt>
                            <dd class="mt-1">
                                <a href="{{ route('properties.show', $deal->property) }}" class="text-blue-600 hover:underline" wire:navigate>
                                    {{ $deal->property->display_name }}
                                </a>
                            </dd>
                        </div>
                    @endif
                    @if ($deal->listing_url)
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Listing Link') }}</dt>
                            <dd class="mt-1">
                                <a href="{{ $deal->listing_url }}" target="_blank" class="text-sm text-blue-600 dark:text-blue-400 underline hover:no-underline break-all">
                                    {{ $deal->listing_url }}
                                </a>
                            </dd>
                        </div>
                    @endif
                    @if ($deal->description)
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Description') }}</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $deal->description }}</flux:text>
                            </dd>
                        </div>
                    @endif
                </dl>
            </div>

            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                <flux:heading size="sm" class="mb-4">{{ __('Financial Details') }}</flux:heading>
                <dl class="space-y-3">
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Estimated Value') }}</dt>
                        <dd class="mt-1">
                            <flux:text>{{ $deal->estimated_value ? '$' . number_format($deal->estimated_value, 2) : '-' }}</flux:text>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Actual Value') }}</dt>
                        <dd class="mt-1">
                            <flux:text>{{ $deal->actual_value ? '$' . number_format($deal->actual_value, 2) : '-' }}</flux:text>
                        </dd>
                    </div>
                </dl>
            </div>

            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                <flux:heading size="sm" class="mb-4">{{ __('Timeline') }}</flux:heading>
                <dl class="space-y-3">
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Expected Start') }}</dt>
                        <dd class="mt-1">
                            <flux:text>{{ $deal->expected_start_date?->format('M j, Y') ?? '-' }}</flux:text>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Expected End') }}</dt>
                        <dd class="mt-1">
                            <flux:text>{{ $deal->expected_end_date?->format('M j, Y') ?? '-' }}</flux:text>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Actual Start') }}</dt>
                        <dd class="mt-1">
                            <flux:text>{{ $deal->actual_start_date?->format('M j, Y') ?? '-' }}</flux:text>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Actual End') }}</dt>
                        <dd class="mt-1">
                            <flux:text>{{ $deal->actual_end_date?->format('M j, Y') ?? '-' }}</flux:text>
                        </dd>
                    </div>
                </dl>
            </div>

            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                <flux:heading size="sm" class="mb-4">{{ __('Assignment & Tracking') }}</flux:heading>
                <dl class="space-y-3">
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Assigned To') }}</dt>
                        <dd class="mt-1">
                            <flux:text>{{ $deal->assignedTo?->name ?? __('Unassigned') }}</flux:text>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Created By') }}</dt>
                        <dd class="mt-1">
                            <flux:text>{{ $deal->createdBy?->name ?? '-' }}</flux:text>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Created') }}</dt>
                        <dd class="mt-1">
                            <flux:text>{{ $deal->created_at->format('M j, Y g:i A') }}</flux:text>
                        </dd>
                    </div>
                </dl>
            </div>
        </div>

        {{-- Activity Timeline --}}
        <div class="mt-6 border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
            <flux:heading size="sm" class="mb-4">{{ __('Activity Timeline') }}</flux:heading>
            @if ($this->dealActivities->count() > 0)
                <div class="space-y-3">
                    @foreach ($this->dealActivities as $activity)
                        <div wire:key="deal-act-{{ $activity->id }}" class="flex gap-3 {{ $activity->is_completed ? 'opacity-60' : '' }}">
                            <div class="shrink-0 mt-0.5">
                                @php
                                    $typeIcons = [
                                        'task' => 'check-circle',
                                        'note' => 'document-text',
                                        'call' => 'phone',
                                        'email' => 'envelope',
                                        'meeting' => 'calendar',
                                        'other' => 'ellipsis-horizontal',
                                    ];
                                @endphp
                                <div class="w-5 h-5 rounded-full bg-zinc-200 dark:bg-zinc-700 flex items-center justify-center">
                                    <flux:icon :name="$typeIcons[$activity->type] ?? 'chat-bubble-left'" class="w-3 h-3 text-zinc-500" />
                                </div>
                            </div>
                            <div class="flex-1 min-w-0">
                                <flux:text class="{{ $activity->is_completed ? 'line-through' : '' }} text-sm">
                                    {{ $activity->title }}
                                </flux:text>
                                <div class="flex items-center gap-2 mt-0.5">
                                    <flux:badge size="sm" color="zinc">{{ $activity->type_label }}</flux:badge>
                                    @if ($activity->due_at)
                                        <flux:text size="xs" class="{{ $activity->isOverdue() ? 'text-red-500' : 'text-zinc-500' }}">
                                            {{ __('Due') }} {{ $activity->due_at->diffForHumans() }}
                                        </flux:text>
                                    @endif
                                    @if ($activity->assignee)
                                        <flux:text size="xs" class="text-zinc-400">
                                            {{ $activity->assignee->name }}
                                        </flux:text>
                                    @endif
                                    <flux:text size="xs" class="text-zinc-400">
                                        {{ $activity->created_at->diffForHumans() }}
                                    </flux:text>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <flux:text class="text-zinc-500">{{ __('No activities recorded for this deal yet.') }}</flux:text>
            @endif
        </div>

        {{-- Estimates Section --}}
        <div class="mt-6 border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
            <div class="flex items-center justify-between mb-4">
                <flux:heading size="sm">{{ __('Estimates') }}</flux:heading>
                @can('estimates.create')
                    <flux:button :href="route('estimates.create', $deal)" icon="plus" size="sm" wire:navigate>
                        {{ __('Create Estimate') }}
                    </flux:button>
                @endcan
            </div>

            @if ($deal->estimates->count() > 0)
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                        <thead>
                            <tr>
                                <th scope="col" class="py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    {{ __('Number') }}
                                </th>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    {{ __('Title') }}
                                </th>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    {{ __('Mode') }}
                                </th>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    {{ __('Status') }}
                                </th>
                                <th scope="col" class="px-4 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    {{ __('Total') }}
                                </th>
                                <th scope="col" class="px-4 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    {{ __('Created') }}
                                </th>
                                <th scope="col" class="py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    {{ __('Actions') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($deal->estimates->sortByDesc('created_at') as $estimate)
                                <tr wire:key="estimate-{{ $estimate->id }}">
                                    <td class="py-4 whitespace-nowrap">
                                        <a href="{{ route('estimates.show', $estimate) }}" class="text-blue-600 hover:underline font-medium" wire:navigate>
                                            {{ $estimate->estimate_number }}
                                        </a>
                                    </td>
                                    <td class="px-4 py-4">
                                        <flux:text>{{ $estimate->title }}</flux:text>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap">
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
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap">
                                        @php
                                            $statusColor = match ($estimate->status) {
                                                'draft' => 'zinc',
                                                'sent' => 'blue',
                                                'accepted' => 'green',
                                                'rejected' => 'red',
                                                'expired' => 'yellow',
                                                default => 'zinc',
                                            };
                                        @endphp
                                        <flux:badge size="sm" color="{{ $statusColor }}">{{ $estimate->status_label }}</flux:badge>
                                    </td>
                                    <td class="px-4 py-4 text-right whitespace-nowrap">
                                        <flux:text class="font-medium">${{ number_format($estimate->total, 2) }}</flux:text>
                                    </td>
                                    <td class="px-4 py-4 whitespace-nowrap">
                                        <flux:text class="text-sm text-zinc-500">{{ $estimate->created_at->format('M j, Y') }}</flux:text>
                                    </td>
                                    <td class="py-4 text-right whitespace-nowrap">
                                        <div class="flex items-center justify-end gap-2">
                                            <flux:button :href="route('estimates.show', $estimate)" size="sm" variant="ghost" icon="eye" wire:navigate />
                                            @if ($estimate->isEditable())
                                                @php
                                                    $editRoute = match($estimate->estimator_mode) {
                                                        'simple' => route('estimates.edit.simple', $estimate),
                                                        'template' => route('estimates.edit.template', $estimate),
                                                        default => route('estimates.edit', $estimate),
                                                    };
                                                @endphp
                                                <flux:button :href="$editRoute" size="sm" variant="ghost" icon="pencil" wire:navigate />
                                            @endif
                                            <flux:button :href="route('estimates.pdf', $estimate)" size="sm" variant="ghost" icon="document-arrow-down" target="_blank" />
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="text-center py-8">
                    <flux:icon name="document-text" class="w-12 h-12 mx-auto text-zinc-300 dark:text-zinc-600 mb-3" />
                    <flux:text class="text-zinc-500 dark:text-zinc-400">{{ __('No estimates yet') }}</flux:text>
                    <flux:text class="text-sm text-zinc-400 dark:text-zinc-500 mt-1">{{ __('Create an estimate to get started with pricing this project.') }}</flux:text>
                </div>
            @endif
        </div>

        {{-- Attribution & Commissions --}}
        @can('payroll.manage')
            @php $chain = $this->commissionChain; @endphp
            <div class="mt-6 border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                <flux:heading size="sm" class="mb-4">{{ __('Attribution & Commissions') }}</flux:heading>

                <div class="space-y-4">
                    {{-- Lead Source --}}
                    <div class="flex items-center gap-3">
                        <span class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Lead Source:') }}</span>
                        <flux:badge size="sm" color="{{ $chain['lead_source'] === 'door_knock' ? 'blue' : 'zinc' }}">
                            {{ ucfirst(str_replace('_', ' ', $chain['lead_source'] ?? 'Unknown')) }}
                        </flux:badge>
                    </div>

                    {{-- Knocker Info --}}
                    @if ($chain['knocker'])
                        <div class="rounded-md bg-zinc-50 p-3 dark:bg-zinc-800">
                            <div class="flex items-center gap-2 text-sm">
                                <flux:icon name="hand-raised" class="size-4 text-blue-500" />
                                <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $chain['knocker']['name'] }}</span>
                                <span class="text-zinc-400">&middot;</span>
                                <span class="text-zinc-500 dark:text-zinc-400">{{ $chain['knocker']['knock_date'] }}</span>
                                <span class="text-zinc-400">&middot;</span>
                                <span class="text-zinc-500 dark:text-zinc-400">{{ $chain['knocker']['knock_address'] }}</span>
                            </div>
                        </div>
                    @endif

                    {{-- Territory Path --}}
                    @if ($chain['territory_path'])
                        <div class="flex items-center gap-2 text-sm">
                            <flux:icon name="map-pin" class="size-4 text-zinc-400" />
                            <span class="text-zinc-600 dark:text-zinc-400">{{ $chain['territory_path'] }}</span>
                        </div>
                    @endif

                    {{-- Commission Entries Table --}}
                    @if (! empty($chain['entries']))
                        <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
                            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                                <thead class="bg-zinc-50 dark:bg-zinc-800">
                                    <tr>
                                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Person') }}</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Role') }}</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Source') }}</th>
                                        <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Amount') }}</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Status') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-700 dark:bg-zinc-900">
                                    @foreach ($chain['entries'] as $entry)
                                        <tr wire:key="chain-entry-{{ $entry['id'] }}">
                                            <td class="whitespace-nowrap px-4 py-2 text-sm text-zinc-900 dark:text-zinc-100">{{ $entry['person_name'] }}</td>
                                            <td class="whitespace-nowrap px-4 py-2">
                                                <flux:badge size="sm" color="zinc">{{ $entry['role'] }}</flux:badge>
                                            </td>
                                            <td class="whitespace-nowrap px-4 py-2 text-sm text-zinc-500 dark:text-zinc-400">{{ $entry['source_label'] }}</td>
                                            <td class="whitespace-nowrap px-4 py-2 text-right text-sm font-medium text-zinc-900 dark:text-zinc-100">${{ number_format($entry['amount'], 2) }}</td>
                                            <td class="whitespace-nowrap px-4 py-2">
                                                <flux:badge size="sm" color="{{ match($entry['status']) { 'pending' => 'yellow', 'approved' => 'green', 'paid' => 'blue', 'reversed' => 'red', default => 'zinc' } }}">
                                                    {{ ucfirst($entry['status']) }}
                                                </flux:badge>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="flex items-center justify-end gap-4 text-sm">
                            <span class="text-zinc-500 dark:text-zinc-400">{{ __('Total:') }}</span>
                            <span class="font-semibold text-zinc-900 dark:text-zinc-100">${{ number_format($chain['total_amount'], 2) }}</span>
                            @if ($chain['commission_percent'] > 0)
                                <span class="text-zinc-400 dark:text-zinc-500">({{ $chain['commission_percent'] }}% of revenue)</span>
                            @endif
                        </div>
                    @else
                        <flux:text class="text-zinc-500">{{ __('No commission entries recorded for this deal.') }}</flux:text>
                    @endif
                </div>
            </div>
        @endcan

        {{-- Notes Section --}}
        <div class="mt-6 border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
            <flux:heading size="sm" class="mb-4">{{ __('Notes') }}</flux:heading>
            @livewire('deals.note-form', ['dealId' => $deal->id])
        </div>
    </div>

    {{-- Follow-Up Date Modal --}}
    <flux:modal wire:model="showFollowUpModal" class="max-w-sm">
        <form wire:submit="confirmFollowUp">
            <flux:heading size="lg" class="mb-4">{{ __('Schedule Follow-Up') }}</flux:heading>

            <flux:field>
                <flux:label>{{ __('Follow-up Date') }}</flux:label>
                <flux:input wire:model="followUpDate" type="date" />
            </flux:field>

            <div class="mt-6 flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="$set('showFollowUpModal', false)" type="button">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Set Follow-Up') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
