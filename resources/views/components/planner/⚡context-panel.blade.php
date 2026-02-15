<?php

use App\Models\Activity;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Job;
use App\Models\User;
use App\Services\CommandCenterService;
use App\Services\PriorityScoreService;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $subjectType;

    public int $subjectId;

    // Quick follow-up form
    public bool $showFollowUp = false;

    public string $followUpTitle = '';

    public string $followUpType = 'task';

    public ?string $followUpDueAt = null;

    public function mount(string $subjectType, int $subjectId): void
    {
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
    }

    #[Computed]
    public function context(): ?array
    {
        $modelClass = $this->subjectType;

        if (! class_exists($modelClass)) {
            return null;
        }

        $subject = $modelClass::find($this->subjectId);

        if (! $subject) {
            return null;
        }

        return app(CommandCenterService::class)->getContextForSubject($subject);
    }

    #[Computed]
    public function subject()
    {
        return $this->context['subject'] ?? null;
    }

    #[Computed]
    public function subjectActivities()
    {
        return $this->context['activities'] ?? collect();
    }

    #[Computed]
    public function related(): array
    {
        return $this->context['related'] ?? [];
    }

    public function openFollowUp(): void
    {
        $this->followUpDueAt = now()->addDay()->toDateString();
        $this->showFollowUp = true;
    }

    public function saveFollowUp(): void
    {
        $this->validate([
            'followUpTitle' => ['required', 'string', 'max:255'],
            'followUpType' => ['required', 'in:task,call,email,meeting,note,other'],
            'followUpDueAt' => ['nullable', 'date'],
        ]);

        $activity = Activity::create([
            'user_id' => auth()->id(),
            'assigned_to' => auth()->id(),
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'type' => $this->followUpType,
            'title' => $this->followUpTitle,
            'due_at' => $this->followUpDueAt ?: null,
            'is_completed' => false,
            'priority' => 'normal',
        ]);

        app(PriorityScoreService::class)->updateScore($activity);

        $this->showFollowUp = false;
        $this->reset(['followUpTitle', 'followUpType', 'followUpDueAt']);
        $this->followUpType = 'task';
        unset($this->context);
        unset($this->subjectActivities);

        $this->dispatch('activity-created');
    }
}; ?>

<div class="space-y-6">
    @if ($this->subject)
        @php
            $subject = $this->subject;
            $isDeal = $subject instanceof \App\Models\Deal;
            $isJob = $subject instanceof \App\Models\Job;
            $isCustomer = $subject instanceof \App\Models\Customer;
            $isUser = $subject instanceof \App\Models\User;
            $isEstimate = $subject instanceof \App\Models\Estimate;

            $dealStageColors = [
                'lead' => 'blue', 'not_contacted' => 'zinc', 'follow_up' => 'yellow',
                'quote_scheduled' => 'purple', 'quoted' => 'yellow', 'signed' => 'green',
                'lost' => 'red', 'cancelled' => 'zinc',
            ];
            $jobStageColors = [
                'estimate_accepted' => 'blue', 'scheduling_colors' => 'yellow',
                'job_prep' => 'purple', 'in_progress' => 'green', 'completed' => 'zinc',
            ];
        @endphp

        {{-- Header --}}
        <div>
            @if ($isDeal)
                <div class="flex items-center gap-2 mb-1">
                    <flux:badge size="sm" color="blue">{{ __('Deal') }}</flux:badge>
                    <flux:badge size="sm" :color="$dealStageColors[$subject->stage] ?? 'zinc'">
                        {{ $subject->stage_label }}
                    </flux:badge>
                </div>
                <flux:heading size="lg">{{ $subject->title }}</flux:heading>
                @if ($subject->customer?->user)
                    <flux:text class="text-sm text-zinc-500 mt-1">
                        <flux:icon name="user" class="w-3.5 h-3.5 inline" />
                        <a href="{{ route('customers.show', $subject->customer) }}" wire:navigate class="text-blue-600 hover:underline dark:text-blue-400">{{ $subject->customer->user->name }}</a>
                        @if ($subject->customer->user->email)
                            &middot; {{ $subject->customer->user->email }}
                        @endif
                    </flux:text>
                @endif
                @if ($subject->agent)
                    <flux:text class="text-xs text-zinc-400 mt-0.5">
                        {{ __('Agent') }}: <a href="{{ route('agents.show', $subject->agent) }}" wire:navigate class="text-blue-600 hover:underline dark:text-blue-400">{{ $subject->agent->name }}</a>
                    </flux:text>
                @endif
                @if ($subject->estimated_value)
                    <flux:text class="text-sm font-medium text-green-600 mt-1">
                        ${{ number_format($subject->estimated_value, 2) }}
                    </flux:text>
                @endif

            @elseif ($isJob)
                <div class="flex items-center gap-2 mb-1">
                    <flux:badge size="sm" color="green">{{ __('Job') }}</flux:badge>
                    <flux:badge size="sm" :color="$jobStageColors[$subject->stage] ?? 'zinc'">
                        {{ $subject->stage_label }}
                    </flux:badge>
                </div>
                <flux:heading size="lg">{{ $subject->title }}</flux:heading>
                @if ($subject->deal?->customer?->user)
                    <flux:text class="text-sm text-zinc-500 mt-1">
                        <flux:icon name="user" class="w-3.5 h-3.5 inline" />
                        <a href="{{ route('customers.show', $subject->deal->customer) }}" wire:navigate class="text-blue-600 hover:underline dark:text-blue-400">{{ $subject->deal->customer->user->name }}</a>
                    </flux:text>
                @endif
                @if ($subject->scheduled_date)
                    <flux:text class="text-xs text-zinc-400 mt-0.5">
                        {{ __('Scheduled') }}: {{ $subject->scheduled_date->format('M j, Y') }}
                    </flux:text>
                @endif

            @elseif ($isCustomer)
                <div class="flex items-center gap-2 mb-1">
                    <flux:badge size="sm" color="purple">{{ __('Customer') }}</flux:badge>
                </div>
                <flux:heading size="lg">{{ $subject->user?->name ?? __('Unknown') }}</flux:heading>
                @if ($subject->user?->email)
                    <flux:text class="text-sm text-zinc-500 mt-1">{{ $subject->user->email }}</flux:text>
                @endif
                @if ($subject->user?->phone)
                    <flux:text class="text-xs text-zinc-400 mt-0.5">{{ $subject->user->phone }}</flux:text>
                @endif

            @elseif ($isEstimate)
                <div class="flex items-center gap-2 mb-1">
                    <flux:badge size="sm" color="orange">{{ __('Estimate') }}</flux:badge>
                    <flux:badge size="sm" :color="match($subject->status) { 'draft' => 'zinc', 'sent' => 'blue', 'accepted' => 'green', 'rejected' => 'red', default => 'zinc' }">
                        {{ ucfirst($subject->status) }}
                    </flux:badge>
                </div>
                <flux:heading size="lg">{{ $subject->title ?? $subject->estimate_number }}</flux:heading>
                @if ($subject->deal?->customer?->user)
                    <flux:text class="text-sm text-zinc-500 mt-1">
                        <flux:icon name="user" class="w-3.5 h-3.5 inline" />
                        <a href="{{ route('customers.show', $subject->deal->customer) }}" wire:navigate class="text-blue-600 hover:underline dark:text-blue-400">{{ $subject->deal->customer->user->name }}</a>
                    </flux:text>
                @endif
                @if ($subject->total)
                    <flux:text class="text-sm font-medium text-green-600 mt-1">
                        ${{ number_format($subject->total, 2) }}
                    </flux:text>
                @endif

            @elseif ($isUser)
                <div class="flex items-center gap-2 mb-1">
                    <flux:badge size="sm" color="indigo">{{ ucfirst($subject->type) }}</flux:badge>
                </div>
                <flux:heading size="lg">{{ $subject->name }}</flux:heading>
                @if ($subject->email)
                    <flux:text class="text-sm text-zinc-500 mt-1">{{ $subject->email }}</flux:text>
                @endif
            @endif
        </div>

        {{-- Quick Actions --}}
        <div class="flex flex-wrap gap-2">
            <flux:button size="sm" icon="plus" wire:click="openFollowUp">
                {{ __('Add Follow-up') }}
            </flux:button>

            @if ($isDeal)
                <flux:button size="sm" icon="document-text" variant="ghost"
                    href="{{ route('deals.show', $subject) }}" wire:navigate>
                    {{ __('View Deal') }}
                </flux:button>
            @elseif ($isJob)
                <flux:button size="sm" icon="wrench-screwdriver" variant="ghost"
                    href="{{ route('jobs.show', $subject) }}" wire:navigate>
                    {{ __('View Job') }}
                </flux:button>
            @elseif ($isEstimate)
                <flux:button size="sm" icon="document-text" variant="ghost"
                    href="{{ route('estimates.show', $subject) }}" wire:navigate>
                    {{ __('View Estimate') }}
                </flux:button>
            @elseif ($isCustomer)
                <flux:button size="sm" icon="user" variant="ghost"
                    href="{{ route('customers.show', $subject) }}" wire:navigate>
                    {{ __('View Customer') }}
                </flux:button>
            @elseif ($isUser)
                @if ($subject->isAgent())
                    <flux:button size="sm" icon="user" variant="ghost"
                        href="{{ route('agents.show', $subject) }}" wire:navigate>
                        {{ __('View Profile') }}
                    </flux:button>
                @elseif ($subject->isCrew())
                    <flux:button size="sm" icon="user" variant="ghost"
                        href="{{ route('crews.show', $subject) }}" wire:navigate>
                        {{ __('View Profile') }}
                    </flux:button>
                @endif
            @endif
        </div>

        {{-- Quick Follow-up Form --}}
        @if ($showFollowUp)
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 space-y-3">
                <flux:heading size="sm">{{ __('New Follow-up') }}</flux:heading>
                <flux:field>
                    <flux:input wire:model="followUpTitle" :placeholder="__('What needs to happen?')" size="sm" />
                    <flux:error name="followUpTitle" />
                </flux:field>
                <div class="grid grid-cols-2 gap-3">
                    <flux:field>
                        <flux:select wire:model="followUpType" size="sm">
                            @foreach (\App\Models\Activity::TYPES as $val => $label)
                                <option value="{{ $val }}">{{ $label }}</option>
                            @endforeach
                        </flux:select>
                    </flux:field>
                    <flux:field>
                        <flux:input wire:model="followUpDueAt" type="date" size="sm" />
                    </flux:field>
                </div>
                <div class="flex justify-end gap-2">
                    <flux:button size="sm" variant="ghost" wire:click="$set('showFollowUp', false)">{{ __('Cancel') }}</flux:button>
                    <flux:button size="sm" variant="primary" wire:click="saveFollowUp">{{ __('Save') }}</flux:button>
                </div>
            </div>
        @endif

        {{-- Related Info --}}
        @if ($isDeal)
            {{-- Estimates --}}
            @if (! empty($this->related['estimates']) && $this->related['estimates']->isNotEmpty())
                <div>
                    <flux:text class="text-xs font-semibold uppercase text-zinc-400 mb-2">{{ __('Estimates') }}</flux:text>
                    <div class="space-y-1">
                        @foreach ($this->related['estimates'] as $estimate)
                            <a href="{{ route('estimates.show', $estimate) }}" wire:navigate
                                class="flex items-center justify-between p-2 rounded hover:bg-zinc-50 dark:hover:bg-zinc-800 transition-colors">
                                <div>
                                    <flux:text class="text-sm font-medium">{{ $estimate->number ?? __('Draft') }}</flux:text>
                                    <flux:text class="text-xs text-zinc-400">{{ ucfirst($estimate->status) }}</flux:text>
                                </div>
                                @if ($estimate->total)
                                    <flux:text class="text-sm font-medium">${{ number_format($estimate->total, 2) }}</flux:text>
                                @endif
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Job --}}
            @if (! empty($this->related['job']))
                @php $job = $this->related['job']; @endphp
                <div>
                    <flux:text class="text-xs font-semibold uppercase text-zinc-400 mb-2">{{ __('Job') }}</flux:text>
                    <a href="{{ route('jobs.show', $job) }}" wire:navigate
                        class="flex items-center justify-between p-2 rounded hover:bg-zinc-50 dark:hover:bg-zinc-800 transition-colors">
                        <div>
                            <flux:text class="text-sm font-medium">{{ $job->title }}</flux:text>
                            <flux:badge size="sm" :color="$jobStageColors[$job->stage] ?? 'zinc'" class="mt-1">
                                {{ $job->stage_label }}
                            </flux:badge>
                        </div>
                        @if ($job->scheduled_date)
                            <flux:text class="text-xs text-zinc-400">{{ $job->scheduled_date->format('M j') }}</flux:text>
                        @endif
                    </a>
                </div>
            @endif

        @elseif ($isJob && ! empty($this->related['deal']))
            <div>
                <flux:text class="text-xs font-semibold uppercase text-zinc-400 mb-2">{{ __('Deal') }}</flux:text>
                @php $deal = $this->related['deal']; @endphp
                <a href="{{ route('deals.show', $deal) }}" wire:navigate
                    class="flex items-center justify-between p-2 rounded hover:bg-zinc-50 dark:hover:bg-zinc-800 transition-colors">
                    <div>
                        <flux:text class="text-sm font-medium">{{ $deal->title }}</flux:text>
                        <flux:badge size="sm" :color="$dealStageColors[$deal->stage] ?? 'zinc'" class="mt-1">
                            {{ $deal->stage_label }}
                        </flux:badge>
                    </div>
                </a>
            </div>

        @elseif ($isCustomer)
            {{-- Customer Deals --}}
            @if (! empty($this->related['deals']) && $this->related['deals']->isNotEmpty())
                <div>
                    <flux:text class="text-xs font-semibold uppercase text-zinc-400 mb-2">{{ __('Deals') }}</flux:text>
                    <div class="space-y-1">
                        @foreach ($this->related['deals'] as $deal)
                            <a href="{{ route('deals.show', $deal) }}" wire:navigate
                                class="flex items-center justify-between p-2 rounded hover:bg-zinc-50 dark:hover:bg-zinc-800 transition-colors">
                                <div>
                                    <flux:text class="text-sm font-medium">{{ $deal->title }}</flux:text>
                                    <flux:badge size="sm" :color="$dealStageColors[$deal->stage] ?? 'zinc'" class="mt-1">
                                        {{ $deal->stage_label }}
                                    </flux:badge>
                                </div>
                                @if ($deal->estimated_value)
                                    <flux:text class="text-sm">${{ number_format($deal->estimated_value, 2) }}</flux:text>
                                @endif
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Customer Properties --}}
            @if (! empty($this->related['properties']) && $this->related['properties']->isNotEmpty())
                <div>
                    <flux:text class="text-xs font-semibold uppercase text-zinc-400 mb-2">{{ __('Properties') }}</flux:text>
                    <div class="space-y-1">
                        @foreach ($this->related['properties'] as $property)
                            <div class="p-2 rounded bg-zinc-50 dark:bg-zinc-800">
                                <flux:text class="text-sm">{{ $property->address }}</flux:text>
                                @if ($property->city || $property->state)
                                    <flux:text class="text-xs text-zinc-400">{{ $property->city }}, {{ $property->state }}</flux:text>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

        @elseif ($isUser && $subject->isAgent() && ! empty($this->related['deals']))
            <div>
                <flux:text class="text-xs font-semibold uppercase text-zinc-400 mb-2">{{ __('Agent Deals') }}</flux:text>
                <div class="space-y-1">
                    @foreach ($this->related['deals'] as $deal)
                        <a href="{{ route('deals.show', $deal) }}" wire:navigate
                            class="flex items-center justify-between p-2 rounded hover:bg-zinc-50 dark:hover:bg-zinc-800 transition-colors">
                            <div>
                                <flux:text class="text-sm font-medium">{{ $deal->title }}</flux:text>
                                <flux:badge size="sm" :color="$dealStageColors[$deal->stage] ?? 'zinc'" class="mt-1">
                                    {{ $deal->stage_label }}
                                </flux:badge>
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Activity Timeline --}}
        @if ($this->subjectActivities->isNotEmpty())
            <div>
                <flux:text class="text-xs font-semibold uppercase text-zinc-400 mb-2">
                    {{ __('Recent Activity') }} ({{ $this->subjectActivities->count() }})
                </flux:text>
                <div class="space-y-2">
                    @foreach ($this->subjectActivities as $activity)
                        <div wire:key="ctx-activity-{{ $activity->id }}" class="flex items-start gap-2 p-2 rounded {{ $activity->is_completed ? 'opacity-50' : '' }}">
                            <div class="shrink-0 mt-1">
                                @if ($activity->is_completed)
                                    <flux:icon name="check-circle" class="w-4 h-4 text-green-500" />
                                @else
                                    <div class="w-4 h-4 rounded-full border-2 border-zinc-300 dark:border-zinc-600"></div>
                                @endif
                            </div>
                            <div class="flex-1 min-w-0">
                                <flux:text class="text-sm {{ $activity->is_completed ? 'line-through' : '' }}">
                                    {{ $activity->title }}
                                </flux:text>
                                <div class="flex items-center gap-2 text-xs text-zinc-400 mt-0.5">
                                    <span>{{ $activity->type_label }}</span>
                                    @if ($activity->due_at)
                                        <span>&middot; {{ $activity->due_at->format('M j') }}</span>
                                    @endif
                                    @if ($activity->assignee)
                                        <span>&middot;
                                            @if ($activity->assignee->isAgent())
                                                <a href="{{ route('agents.show', $activity->assignee) }}" wire:navigate class="text-blue-600 hover:underline dark:text-blue-400">{{ $activity->assignee->name }}</a>
                                            @elseif ($activity->assignee->isCrew())
                                                <a href="{{ route('crews.show', $activity->assignee) }}" wire:navigate class="text-blue-600 hover:underline dark:text-blue-400">{{ $activity->assignee->name }}</a>
                                            @else
                                                {{ $activity->assignee->name }}
                                            @endif
                                        </span>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @else
            <div class="text-center py-4">
                <flux:text class="text-sm text-zinc-400">{{ __('No activity history yet.') }}</flux:text>
            </div>
        @endif
    @else
        <div class="text-center py-8">
            <flux:icon name="exclamation-circle" class="w-8 h-8 mx-auto text-zinc-300 mb-2" />
            <flux:text class="text-zinc-500">{{ __('Subject not found.') }}</flux:text>
        </div>
    @endif
</div>
