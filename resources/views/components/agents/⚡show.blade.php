<?php

use App\Models\Activity;
use App\Models\Deal;
use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public function boot(): void
    {
        if (! auth()->user()?->can('people.view')) {
            abort(403);
        }
    }

    public User $user;

    public string $activeTab = 'overview';

    // Quick add activity form
    public bool $showAddActivity = false;

    public string $activityTitle = '';

    public string $activityType = 'task';

    public ?string $activityDueAt = null;

    public string $activityDescription = '';

    public function mount(User $user): void
    {
        if (! $user->isAgent()) {
            abort(404);
        }

        $this->user = $user->load([
            'agentProfile',
            'agentProperties',
            'referredCustomers',
        ]);
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    #[Computed]
    public function agentDeals()
    {
        return Deal::where('agent_id', $this->user->id)
            ->with(['customer', 'estimates', 'job'])
            ->latest()
            ->get();
    }

    #[Computed]
    public function agentActivities()
    {
        return Activity::query()
            ->where('subject_type', User::class)
            ->where('subject_id', $this->user->id)
            ->with(['user', 'assignee'])
            ->orderByRaw('is_completed ASC')
            ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_at')
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get();
    }

    #[Computed]
    public function activityTypes(): array
    {
        return Activity::TYPES;
    }

    #[Computed]
    public function dealStageColors(): array
    {
        return [
            'lead' => 'blue',
            'not_contacted' => 'zinc',
            'follow_up' => 'yellow',
            'quote_scheduled' => 'purple',
            'quoted' => 'yellow',
            'signed' => 'green',
            'completed' => 'green',
            'lost' => 'red',
            'cancelled' => 'zinc',
        ];
    }

    public function toggleActivityComplete(int $activityId): void
    {
        $activity = Activity::query()
            ->where('subject_type', User::class)
            ->where('subject_id', $this->user->id)
            ->findOrFail($activityId);

        if ($activity->is_completed) {
            $activity->markAsIncomplete();
        } else {
            $activity->markAsCompleted();
        }

        unset($this->agentActivities);
    }

    // Quick Add Activity
    public function openAddActivity(): void
    {
        $this->showAddActivity = true;
    }

    public function closeAddActivity(): void
    {
        $this->showAddActivity = false;
        $this->reset(['activityTitle', 'activityType', 'activityDueAt', 'activityDescription']);
        $this->activityType = 'task';
        $this->resetValidation();
    }

    public function saveActivity(): void
    {
        abort_unless(auth()->user()->can('people.edit'), 403);

        $this->validate([
            'activityTitle' => ['required', 'string', 'max:255'],
            'activityType' => ['required', 'in:task,note,call,email,meeting,other'],
            'activityDueAt' => ['nullable', 'date'],
            'activityDescription' => ['nullable', 'string', 'max:5000'],
        ]);

        Activity::create([
            'user_id' => auth()->id(),
            'assigned_to' => auth()->id(),
            'subject_type' => User::class,
            'subject_id' => $this->user->id,
            'type' => $this->activityType,
            'title' => $this->activityTitle,
            'description' => $this->activityDescription ?: null,
            'due_at' => $this->activityDueAt ?: null,
            'is_completed' => false,
        ]);

        unset($this->agentActivities);
        session()->flash('success', __('Activity added successfully.'));
        $this->closeAddActivity();
    }
}; ?>

<div>
    <div class="max-w-6xl mx-auto">
        {{-- Header --}}
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-4">
                <flux:button variant="ghost" :href="route('agents.index')" icon="arrow-left" wire:navigate>
                    {{ __('Back') }}
                </flux:button>
                <flux:heading size="xl">{{ $user->name }}</flux:heading>
                @if ($user->is_active)
                    <flux:badge size="sm" color="green">{{ __('Active') }}</flux:badge>
                @else
                    <flux:badge size="sm" color="yellow">{{ __('Inactive') }}</flux:badge>
                @endif
            </div>
            <div class="flex items-center gap-2">
                @if ($user->agentProfile?->phone)
                    <flux:button variant="ghost" size="sm" icon="phone" :href="'tel:' . $user->agentProfile->phone">
                        {{ __('Call') }}
                    </flux:button>
                @endif
                <flux:button variant="ghost" size="sm" icon="envelope" :href="'mailto:' . $user->email">
                    {{ __('Email') }}
                </flux:button>
                @can('people.edit')
                    <flux:button size="sm" icon="plus" wire:click="openAddActivity">
                        {{ __('Add Activity') }}
                    </flux:button>
                @endcan
            </div>
        </div>

        @if (session('success'))
            <flux:callout variant="success" class="mb-4">
                {{ session('success') }}
            </flux:callout>
        @endif

        <!-- Stats Overview -->
        <div class="grid gap-4 md:grid-cols-4 mb-6">
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Total Listings') }}</flux:text>
                <flux:heading size="xl" class="mt-1">{{ $user->agentProperties->count() }}</flux:heading>
            </div>
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Customers Referred') }}</flux:text>
                <flux:heading size="xl" class="mt-1">{{ $user->referredCustomers->count() }}</flux:heading>
            </div>
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Active Deals') }}</flux:text>
                <flux:heading size="xl" class="mt-1">{{ $this->agentDeals->whereNotIn('stage', ['lost', 'cancelled', 'completed'])->count() }}</flux:heading>
            </div>
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                <flux:text class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Open Tasks') }}</flux:text>
                <flux:heading size="xl" class="mt-1">{{ $this->agentActivities->where('is_completed', false)->count() }}</flux:heading>
            </div>
        </div>

        <!-- Tab Navigation -->
        <div class="border-b border-zinc-200 dark:border-zinc-700 mb-6">
            <nav class="-mb-px flex space-x-8 overflow-x-auto">
                <button
                    wire:click="setTab('overview')"
                    class="py-4 px-1 border-b-2 font-medium text-sm whitespace-nowrap {{ $activeTab === 'overview' ? 'border-zinc-800 dark:border-zinc-200 text-zinc-900 dark:text-white' : 'border-transparent text-zinc-500 hover:text-zinc-700 hover:border-zinc-300 dark:hover:text-zinc-300' }}"
                >
                    {{ __('Overview') }}
                </button>
                <button
                    wire:click="setTab('deals')"
                    class="py-4 px-1 border-b-2 font-medium text-sm whitespace-nowrap {{ $activeTab === 'deals' ? 'border-zinc-800 dark:border-zinc-200 text-zinc-900 dark:text-white' : 'border-transparent text-zinc-500 hover:text-zinc-700 hover:border-zinc-300 dark:hover:text-zinc-300' }}"
                >
                    {{ __('Deals') }} ({{ $this->agentDeals->count() }})
                </button>
                <button
                    wire:click="setTab('activities')"
                    class="py-4 px-1 border-b-2 font-medium text-sm whitespace-nowrap {{ $activeTab === 'activities' ? 'border-zinc-800 dark:border-zinc-200 text-zinc-900 dark:text-white' : 'border-transparent text-zinc-500 hover:text-zinc-700 hover:border-zinc-300 dark:hover:text-zinc-300' }}"
                >
                    {{ __('Activities') }} ({{ $this->agentActivities->count() }})
                </button>
                <button
                    wire:click="setTab('listings')"
                    class="py-4 px-1 border-b-2 font-medium text-sm whitespace-nowrap {{ $activeTab === 'listings' ? 'border-zinc-800 dark:border-zinc-200 text-zinc-900 dark:text-white' : 'border-transparent text-zinc-500 hover:text-zinc-700 hover:border-zinc-300 dark:hover:text-zinc-300' }}"
                >
                    {{ __('Listings') }} ({{ $user->agentProperties->count() }})
                </button>
                <button
                    wire:click="setTab('customers')"
                    class="py-4 px-1 border-b-2 font-medium text-sm whitespace-nowrap {{ $activeTab === 'customers' ? 'border-zinc-800 dark:border-zinc-200 text-zinc-900 dark:text-white' : 'border-transparent text-zinc-500 hover:text-zinc-700 hover:border-zinc-300 dark:hover:text-zinc-300' }}"
                >
                    {{ __('Referred Customers') }} ({{ $user->referredCustomers->count() }})
                </button>
            </nav>
        </div>

        <!-- Tab Content -->
        @if ($activeTab === 'overview')
            <div class="grid gap-6 md:grid-cols-2">
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <flux:heading size="sm" class="mb-4">{{ __('Contact Information') }}</flux:heading>
                    <dl class="space-y-3">
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Email') }}</dt>
                            <dd class="mt-1">
                                <a href="mailto:{{ $user->email }}" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">{{ $user->email }}</a>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Phone') }}</dt>
                            <dd class="mt-1">
                                @if ($user->agentProfile?->phone)
                                    <a href="tel:{{ $user->agentProfile->phone }}" class="text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">{{ $user->agentProfile->phone }}</a>
                                @else
                                    <flux:text>-</flux:text>
                                @endif
                            </dd>
                        </div>
                    </dl>
                </div>

                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <flux:heading size="sm" class="mb-4">{{ __('Professional Details') }}</flux:heading>
                    <dl class="space-y-3">
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Company') }}</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $user->agentProfile?->company_name ?? '-' }}</flux:text>
                            </dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('License Number') }}</dt>
                            <dd class="mt-1">
                                <flux:text>{{ $user->agentProfile?->license_number ?? '-' }}</flux:text>
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>

            @if ($user->agentProfile?->bio)
                <div class="mt-6 border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
                    <flux:heading size="sm" class="mb-4">{{ __('Bio') }}</flux:heading>
                    <flux:text>{{ $user->agentProfile->bio }}</flux:text>
                </div>
            @endif

            {{-- Recent Deals on Overview --}}
            @if ($this->agentDeals->isNotEmpty())
                <div class="mt-6 border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                    <div class="flex items-center justify-between mb-3">
                        <flux:heading size="sm">{{ __('Recent Deals') }}</flux:heading>
                        <flux:button size="xs" variant="ghost" wire:click="setTab('deals')">{{ __('View All') }}</flux:button>
                    </div>
                    <div class="space-y-2">
                        @foreach ($this->agentDeals->take(3) as $deal)
                            <a href="{{ route('deals.show', $deal) }}" wire:navigate wire:key="overview-deal-{{ $deal->id }}" class="flex items-center justify-between p-2 rounded bg-zinc-50 dark:bg-zinc-900/50 hover:bg-zinc-100 dark:hover:bg-zinc-800/50 transition-colors">
                                <div class="flex items-center gap-2">
                                    <flux:badge size="sm" :color="$this->dealStageColors[$deal->stage] ?? 'zinc'">
                                        {{ $deal->stage_label }}
                                    </flux:badge>
                                    <flux:text class="text-sm font-medium">{{ $deal->title }}</flux:text>
                                </div>
                                @if ($deal->customer)
                                    <flux:text size="xs" class="text-zinc-500">{{ $deal->customer->full_name }}</flux:text>
                                @endif
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Recent Activities on Overview --}}
            @if ($this->agentActivities->where('is_completed', false)->isNotEmpty())
                <div class="mt-6 border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                    <div class="flex items-center justify-between mb-3">
                        <flux:heading size="sm">{{ __('Open Tasks') }}</flux:heading>
                        <flux:button size="xs" variant="ghost" wire:click="setTab('activities')">{{ __('View All') }}</flux:button>
                    </div>
                    <div class="space-y-2">
                        @foreach ($this->agentActivities->where('is_completed', false)->take(5) as $activity)
                            <div wire:key="overview-activity-{{ $activity->id }}" class="flex items-center gap-3 p-2 rounded {{ $activity->isOverdue() ? 'bg-red-50 dark:bg-red-900/10' : 'bg-zinc-50 dark:bg-zinc-900/50' }}">
                                <button
                                    wire:click="toggleActivityComplete({{ $activity->id }})"
                                    class="w-5 h-5 shrink-0 rounded border-2 flex items-center justify-center border-zinc-300 dark:border-zinc-600 hover:border-green-500"
                                >
                                </button>
                                <div class="flex-1 min-w-0">
                                    <flux:text class="text-sm {{ $activity->isOverdue() ? 'text-red-600' : '' }}">{{ $activity->title }}</flux:text>
                                </div>
                                @if ($activity->due_at)
                                    <flux:text size="xs" class="{{ $activity->isOverdue() ? 'text-red-500' : 'text-zinc-500' }}">
                                        {{ $activity->due_at->format('M j') }}
                                    </flux:text>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        @endif

        @if ($activeTab === 'deals')
            @if ($this->agentDeals->isNotEmpty())
                <div class="space-y-3">
                    @foreach ($this->agentDeals as $deal)
                        <div wire:key="deal-{{ $deal->id }}" class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                            <div class="flex items-start justify-between">
                                <div class="flex items-center gap-3">
                                    <flux:badge size="sm" :color="$this->dealStageColors[$deal->stage] ?? 'zinc'">
                                        {{ $deal->stage_label }}
                                    </flux:badge>
                                    <div>
                                        <a href="{{ route('deals.show', $deal) }}" wire:navigate class="font-medium text-zinc-900 dark:text-white hover:text-blue-600 dark:hover:text-blue-400 transition-colors">
                                            {{ $deal->title }}
                                        </a>
                                        @if ($deal->customer)
                                            <a href="{{ route('customers.show', ['customer' => $deal->customer_id, 'deal' => $deal->id]) }}" wire:navigate class="text-sm text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300 flex items-center gap-1 mt-0.5">
                                                <flux:icon name="user" class="w-3 h-3" />
                                                {{ $deal->customer->full_name }}
                                            </a>
                                        @endif
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    <div class="text-right">
                                        @if ($deal->estimated_value)
                                            <flux:text class="font-medium">${{ number_format($deal->estimated_value, 0) }}</flux:text>
                                        @endif
                                        @if ($deal->estimates->isNotEmpty())
                                            <flux:text size="xs" class="text-zinc-500">{{ $deal->estimates->count() }} {{ Str::plural('estimate', $deal->estimates->count()) }}</flux:text>
                                        @endif
                                    </div>
                                    <flux:button variant="ghost" size="sm" icon="arrow-top-right-on-square" :href="route('deals.show', $deal)" wire:navigate />
                                </div>
                            </div>

                            {{-- Linked estimates --}}
                            @if ($deal->estimates->isNotEmpty())
                                <div class="mt-3 pt-3 border-t border-zinc-100 dark:border-zinc-800 flex flex-wrap gap-2">
                                    @foreach ($deal->estimates as $estimate)
                                        <a href="{{ route('estimates.show', $estimate) }}" wire:navigate class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-medium bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 hover:bg-blue-100 dark:hover:bg-blue-900/30 hover:text-blue-700 dark:hover:text-blue-300 transition-colors">
                                            <flux:icon name="document-text" class="w-3 h-3" />
                                            #{{ $estimate->id }}
                                            @if ($estimate->status)
                                                <flux:badge size="sm" :color="match($estimate->status) { 'draft' => 'zinc', 'sent' => 'blue', 'accepted' => 'green', 'rejected' => 'red', default => 'zinc' }">
                                                    {{ ucfirst($estimate->status) }}
                                                </flux:badge>
                                            @endif
                                        </a>
                                    @endforeach
                                </div>
                            @endif

                            {{-- Linked job --}}
                            @if ($deal->job)
                                <div class="mt-2 {{ $deal->estimates->isEmpty() ? 'pt-3 border-t border-zinc-100 dark:border-zinc-800' : '' }}">
                                    <a href="{{ route('jobs.show', $deal->job) }}" wire:navigate class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-medium bg-emerald-50 dark:bg-emerald-900/20 text-emerald-700 dark:text-emerald-300 hover:bg-emerald-100 dark:hover:bg-emerald-900/40 transition-colors">
                                        <flux:icon name="wrench-screwdriver" class="w-3 h-3" />
                                        {{ __('Job') }} {{ $deal->job->job_number ?? '#' . $deal->job->id }}
                                        <flux:badge size="sm" color="emerald">{{ str_replace('_', ' ', ucfirst($deal->job->stage)) }}</flux:badge>
                                    </a>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            @else
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
                    <flux:icon name="currency-dollar" class="mx-auto h-12 w-12 text-zinc-400" />
                    <flux:heading size="sm" class="mt-4">{{ __('No deals') }}</flux:heading>
                    <flux:text class="mt-2 text-zinc-500">{{ __('This agent has no deals yet.') }}</flux:text>
                </div>
            @endif
        @endif

        @if ($activeTab === 'activities')
            <div class="flex items-center justify-between mb-4">
                <flux:heading size="sm">{{ __('Activities') }}</flux:heading>
                @can('people.edit')
                    <flux:button size="sm" icon="plus" wire:click="openAddActivity">{{ __('Add Activity') }}</flux:button>
                @endcan
            </div>
            @if ($this->agentActivities->isNotEmpty())
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach ($this->agentActivities as $activity)
                        <div
                            wire:key="activity-{{ $activity->id }}"
                            class="p-4 flex items-start gap-4 {{ $activity->is_completed ? 'bg-zinc-50 dark:bg-zinc-900/50' : '' }}"
                        >
                            <div class="shrink-0 pt-0.5">
                                <button
                                    wire:click="toggleActivityComplete({{ $activity->id }})"
                                    class="w-5 h-5 rounded border-2 flex items-center justify-center transition-colors {{ $activity->is_completed ? 'bg-green-500 border-green-500 text-white' : 'border-zinc-300 dark:border-zinc-600 hover:border-green-500' }}"
                                >
                                    @if ($activity->is_completed)
                                        <flux:icon name="check" class="w-3 h-3" />
                                    @endif
                                </button>
                            </div>

                            <div class="flex-1 min-w-0">
                                <div class="flex items-start justify-between gap-2">
                                    <div>
                                        <flux:text class="font-medium {{ $activity->is_completed ? 'line-through text-zinc-500' : '' }} {{ $activity->isOverdue() ? 'text-red-600' : '' }}">
                                            {{ $activity->title }}
                                        </flux:text>
                                        @if ($activity->description)
                                            <flux:text size="sm" class="text-zinc-500 line-clamp-2 mt-1">
                                                {{ $activity->description }}
                                            </flux:text>
                                        @endif
                                    </div>
                                    <div class="shrink-0">
                                        <flux:badge size="sm" color="zinc">{{ $activity->type_label }}</flux:badge>
                                    </div>
                                </div>

                                <div class="flex flex-wrap items-center gap-3 mt-2">
                                    @if ($activity->due_at)
                                        <div class="flex items-center gap-1 text-xs {{ $activity->isOverdue() ? 'text-red-500' : 'text-zinc-500' }}">
                                            <flux:icon name="calendar" class="w-3 h-3" />
                                            <span>{{ $activity->due_at->format('M j, Y') }}</span>
                                            @if ($activity->isOverdue())
                                                <span class="font-medium">{{ __('(Overdue)') }}</span>
                                            @endif
                                        </div>
                                    @endif

                                    @if ($activity->assignee)
                                        <div class="flex items-center gap-1 text-xs text-zinc-500">
                                            <flux:icon name="user" class="w-3 h-3" />
                                            <span>{{ $activity->assignee->name }}</span>
                                        </div>
                                    @endif

                                    @if ($activity->is_completed && $activity->completed_at)
                                        <div class="flex items-center gap-1 text-xs text-green-600">
                                            <flux:icon name="check-circle" class="w-3 h-3" />
                                            <span>{{ __('Completed') }} {{ $activity->completed_at->diffForHumans() }}</span>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
                    <flux:icon name="clipboard-document-list" class="mx-auto h-12 w-12 text-zinc-400" />
                    <flux:heading size="sm" class="mt-4">{{ __('No activities') }}</flux:heading>
                    <flux:text class="mt-2 text-zinc-500">{{ __('No activities for this agent yet.') }}</flux:text>
                    @can('people.edit')
                        <flux:button size="sm" class="mt-4" wire:click="openAddActivity">{{ __('Add Activity') }}</flux:button>
                    @endcan
                </div>
            @endif
        @endif

        @if ($activeTab === 'listings')
            @if ($user->agentProperties->count() > 0)
                <div class="overflow-x-auto border border-zinc-200 dark:border-zinc-700 rounded-lg">
                    <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                        <thead class="bg-zinc-50 dark:bg-zinc-800">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    {{ __('Address') }}
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    {{ __('Price') }}
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    {{ __('Status') }}
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    {{ __('Open House') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-zinc-900 divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($user->agentProperties as $listing)
                                <tr wire:key="listing-{{ $listing->id }}">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <flux:text class="font-medium">{{ $listing->address }}</flux:text>
                                        <flux:text class="text-sm text-zinc-500">{{ $listing->city }}, {{ $listing->state }} {{ $listing->zip }}</flux:text>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <flux:text>${{ number_format($listing->listing_price, 2) }}</flux:text>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        @switch($listing->status)
                                            @case('active')
                                                <flux:badge size="sm" color="green">{{ __('Active') }}</flux:badge>
                                                @break
                                            @case('pending')
                                                <flux:badge size="sm" color="yellow">{{ __('Pending') }}</flux:badge>
                                                @break
                                            @case('sold')
                                                <flux:badge size="sm" color="blue">{{ __('Sold') }}</flux:badge>
                                                @break
                                            @default
                                                <flux:badge size="sm" color="zinc">{{ __('Inactive') }}</flux:badge>
                                        @endswitch
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <flux:text>
                                            @if ($listing->open_house_date)
                                                {{ $listing->open_house_date->format('M j, Y g:i A') }}
                                            @else
                                                -
                                            @endif
                                        </flux:text>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
                    <flux:icon name="building-office" class="mx-auto h-12 w-12 text-zinc-400" />
                    <flux:heading size="sm" class="mt-4">{{ __('No listings') }}</flux:heading>
                    <flux:text class="mt-2 text-zinc-500">{{ __('This agent has no listings yet.') }}</flux:text>
                </div>
            @endif
        @endif

        @if ($activeTab === 'customers')
            @if ($user->referredCustomers->count() > 0)
                <div class="overflow-x-auto border border-zinc-200 dark:border-zinc-700 rounded-lg">
                    <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                        <thead class="bg-zinc-50 dark:bg-zinc-800">
                            <tr>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    {{ __('Name') }}
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    {{ __('Email') }}
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    {{ __('Phone') }}
                                </th>
                                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    {{ __('Status') }}
                                </th>
                                <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">
                                    {{ __('Actions') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="bg-white dark:bg-zinc-900 divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($user->referredCustomers as $customer)
                                <tr wire:key="customer-{{ $customer->id }}">
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <flux:text class="font-medium">{{ $customer->full_name }}</flux:text>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <flux:text>{{ $customer->email ?? '-' }}</flux:text>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        <flux:text>{{ $customer->phone ?? '-' }}</flux:text>
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap">
                                        @if ($customer->status === 'active')
                                            <flux:badge size="sm" color="green">{{ __('Active') }}</flux:badge>
                                        @else
                                            <flux:badge size="sm" color="yellow">{{ __('Inactive') }}</flux:badge>
                                        @endif
                                    </td>
                                    <td class="px-6 py-4 whitespace-nowrap text-right">
                                        <flux:button variant="ghost" size="sm" icon="eye" :href="route('customers.show', $customer)" wire:navigate>
                                            {{ __('View') }}
                                        </flux:button>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
                    <flux:icon name="users" class="mx-auto h-12 w-12 text-zinc-400" />
                    <flux:heading size="sm" class="mt-4">{{ __('No referred customers') }}</flux:heading>
                    <flux:text class="mt-2 text-zinc-500">{{ __('This agent has not referred any customers yet.') }}</flux:text>
                </div>
            @endif
        @endif
    </div>

    {{-- Add Activity Modal --}}
    <flux:modal wire:model="showAddActivity" variant="flyout" class="max-w-md">
        <form wire:submit="saveActivity">
            <flux:heading size="lg" class="mb-4">{{ __('Add Activity') }}</flux:heading>

            <div class="space-y-4">
                <flux:field>
                    <flux:label>{{ __('Title') }}</flux:label>
                    <flux:input wire:model="activityTitle" :placeholder="__('Follow up call')" />
                    <flux:error name="activityTitle" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Type') }}</flux:label>
                    <flux:select wire:model="activityType">
                        @foreach ($this->activityTypes as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="activityType" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Due Date (Optional)') }}</flux:label>
                    <flux:input wire:model="activityDueAt" type="date" />
                    <flux:error name="activityDueAt" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Description (Optional)') }}</flux:label>
                    <flux:textarea wire:model="activityDescription" rows="3" :placeholder="__('Add notes...')" />
                    <flux:error name="activityDescription" />
                </flux:field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="closeAddActivity" type="button">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Add Activity') }}</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
