@props(['activity', 'isInbox' => false])

@php
    $typeIcons = [
        'task' => 'clipboard-document-check',
        'call' => 'phone',
        'email' => 'envelope',
        'meeting' => 'calendar',
        'note' => 'document-text',
        'other' => 'ellipsis-horizontal-circle',
    ];

    $icon = $typeIcons[$activity->type] ?? 'clipboard-document-check';

    $isDeal = $activity->subject_type === \App\Models\Deal::class;
    $isJob = $activity->subject_type === \App\Models\Job::class;
    $isUser = $activity->subject_type === \App\Models\User::class;
    $isCustomer = $activity->subject_type === \App\Models\Customer::class;
    $isEstimate = $activity->subject_type === \App\Models\Estimate::class;

    $isOverdue = $activity->due_at && ! $activity->is_completed && $activity->due_at->isPast();
    $isDueToday = $activity->due_at && $activity->due_at->isToday();
    $isSnoozed = $activity->snoozed_until && $activity->snoozed_until->isFuture();

    // Every activity row links to where you take action on it
    $rowUrl = $activity->getSubjectUrl();
@endphp

<div
    wire:key="activity-{{ $activity->id }}"
    @if ($rowUrl) onclick="window.location='{{ $rowUrl }}'" @endif
    class="flex items-start gap-3 p-3 group transition-colors {{ $rowUrl ? 'cursor-pointer' : '' }} {{ $activity->is_completed ? 'opacity-60' : '' }} {{ $isInbox ? 'hover:bg-amber-100/50 dark:hover:bg-amber-900/20' : 'hover:bg-zinc-50 dark:hover:bg-zinc-800/50' }}"
>
    {{-- Checkbox --}}
    <div class="shrink-0 pt-0.5" onclick="event.stopPropagation()">
        <button
            wire:click="toggleComplete({{ $activity->id }})"
            class="w-5 h-5 rounded border flex items-center justify-center transition-colors {{ $activity->is_completed ? 'bg-green-500 border-green-500 text-white' : 'border-zinc-300 dark:border-zinc-600 hover:border-zinc-400' }}"
        >
            @if ($activity->is_completed)
                <flux:icon name="check" class="w-3 h-3" />
            @endif
        </button>
    </div>

    {{-- Icon --}}
    <div class="shrink-0 pt-0.5">
        <div class="w-7 h-7 rounded-full flex items-center justify-center {{ $isOverdue ? 'bg-red-100 dark:bg-red-900/40' : ($isDueToday ? 'bg-amber-100 dark:bg-amber-900/40' : 'bg-zinc-100 dark:bg-zinc-800') }}">
            <flux:icon :name="$icon" class="w-3.5 h-3.5 {{ $isOverdue ? 'text-red-600 dark:text-red-400' : ($isDueToday ? 'text-amber-600 dark:text-amber-400' : 'text-zinc-500 dark:text-zinc-400') }}" />
        </div>
    </div>

    {{-- Content --}}
    <div class="flex-1 min-w-0">
        <div class="flex items-center gap-2">
            <flux:text class="text-sm font-medium {{ $activity->is_completed ? 'line-through text-zinc-400' : '' }} truncate">
                {{ $activity->title }}
            </flux:text>

            {{-- Priority badge --}}
            @if ($activity->priority && $activity->priority !== 'normal')
                <flux:badge size="sm" :color="$this->priorityColors[$activity->priority] ?? 'zinc'">
                    {{ ucfirst($activity->priority) }}
                </flux:badge>
            @endif

            {{-- Snoozed indicator --}}
            @if ($isSnoozed)
                <flux:badge size="sm" color="purple">
                    <flux:icon name="clock" class="w-3 h-3 mr-1" />
                    {{ $activity->snoozed_until->diffForHumans() }}
                </flux:badge>
            @endif
        </div>

        {{-- Subject context --}}
        <div class="flex items-center gap-2 mt-0.5">
            @if ($isDeal && $activity->subject)
                <a href="{{ route('deals.show', $activity->subject) }}" wire:navigate class="text-xs text-blue-600 dark:text-blue-400 hover:underline truncate">
                    {{ $activity->subject->customer?->user?->name ?? $activity->subject->title }}
                </a>
                <flux:badge size="sm" :color="$this->dealStageColors[$activity->subject->stage] ?? 'zinc'">
                    {{ str_replace('_', ' ', ucfirst($activity->subject->stage)) }}
                </flux:badge>
            @elseif ($isJob && $activity->subject)
                <a href="{{ route('jobs.show', $activity->subject) }}" wire:navigate class="text-xs text-green-600 dark:text-green-400 hover:underline truncate">
                    {{ $activity->subject->deal?->customer?->user?->name ?? $activity->subject->title }}
                </a>
                <flux:badge size="sm" :color="$this->jobStageColors[$activity->subject->stage] ?? 'zinc'">
                    {{ str_replace('_', ' ', ucfirst($activity->subject->stage)) }}
                </flux:badge>
            @elseif ($isEstimate && $activity->subject)
                <a href="{{ route('estimates.show', $activity->subject) }}" wire:navigate class="text-xs text-orange-600 dark:text-orange-400 hover:underline truncate">
                    {{ $activity->subject->title ?? $activity->subject->estimate_number ?? 'Estimate' }}
                </a>
                <flux:badge size="sm" :color="match($activity->subject->status) { 'draft' => 'zinc', 'sent' => 'blue', 'accepted' => 'green', 'rejected' => 'red', default => 'zinc' }">
                    {{ ucfirst($activity->subject->status) }}
                </flux:badge>
            @elseif ($isCustomer && $activity->subject)
                <a href="{{ route('customers.show', $activity->subject) }}" wire:navigate class="text-xs text-purple-600 dark:text-purple-400 hover:underline truncate">
                    {{ $activity->subject->user?->name ?? 'Customer' }}
                </a>
            @elseif ($isUser && $activity->subject)
                @if ($activity->subject->isAgent())
                    <a href="{{ route('agents.show', $activity->subject) }}" wire:navigate class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline truncate">
                        {{ $activity->subject->name }}
                        <span class="text-zinc-400">({{ ucfirst($activity->subject->type) }})</span>
                    </a>
                @elseif ($activity->subject->isCrew())
                    <a href="{{ route('crews.show', $activity->subject) }}" wire:navigate class="text-xs text-indigo-600 dark:text-indigo-400 hover:underline truncate">
                        {{ $activity->subject->name }}
                        <span class="text-zinc-400">({{ ucfirst($activity->subject->type) }})</span>
                    </a>
                @else
                    <span class="text-xs text-indigo-600 dark:text-indigo-400 truncate">
                        {{ $activity->subject->name }}
                    </span>
                @endif
            @endif
        </div>

        {{-- Meta row --}}
        <div class="flex items-center gap-3 mt-1 text-xs text-zinc-400">
            @if ($activity->due_at)
                <span class="{{ $isOverdue ? 'text-red-500 font-medium' : ($isDueToday ? 'text-amber-500' : '') }}">
                    @if ($isOverdue)
                        {{ __('Overdue') }} {{ $activity->due_at->diffForHumans() }}
                    @elseif ($isDueToday)
                        {{ __('Due today') }} {{ $activity->due_at->format('g:ia') }}
                    @else
                        {{ $activity->due_at->format('M j') }}
                        @if ($activity->due_at->isTomorrow())
                            ({{ __('Tomorrow') }})
                        @endif
                    @endif
                </span>
            @endif

            @if ($activity->assignee && $activity->assigned_to !== auth()->id())
                <span class="flex items-center gap-1">
                    <flux:icon name="user" class="w-3 h-3" />
                    @if ($activity->assignee->isAgent())
                        <a href="{{ route('agents.show', $activity->assignee) }}" wire:navigate class="text-blue-600 hover:underline dark:text-blue-400" onclick="event.stopPropagation()">{{ $activity->assignee->name }}</a>
                    @elseif ($activity->assignee->isCrew())
                        <a href="{{ route('crews.show', $activity->assignee) }}" wire:navigate class="text-blue-600 hover:underline dark:text-blue-400" onclick="event.stopPropagation()">{{ $activity->assignee->name }}</a>
                    @else
                        {{ $activity->assignee->name }}
                    @endif
                </span>
            @endif

            @if ($activity->type_label)
                <span>{{ $activity->type_label }}</span>
            @endif

            @if ($activity->priority_score > 0 && $isInbox)
                <span class="text-amber-500" title="{{ __('Priority Score') }}">
                    {{ $activity->priority_score }} pts
                </span>
            @endif
        </div>
    </div>

    {{-- Actions --}}
    <div class="shrink-0 flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity" onclick="event.stopPropagation()">
        {{-- Snooze --}}
        @unless ($activity->is_completed)
            <flux:dropdown>
                <flux:button variant="ghost" size="sm" icon="clock" class="!p-1" />
                <flux:menu>
                    <flux:menu.item wire:click="snoozeQuick({{ $activity->id }}, '1h')">{{ __('1 hour') }}</flux:menu.item>
                    <flux:menu.item wire:click="snoozeQuick({{ $activity->id }}, '4h')">{{ __('4 hours') }}</flux:menu.item>
                    <flux:menu.item wire:click="snoozeQuick({{ $activity->id }}, 'tomorrow')">{{ __('Tomorrow 9am') }}</flux:menu.item>
                    <flux:menu.item wire:click="snoozeQuick({{ $activity->id }}, 'next_week')">{{ __('Next Monday') }}</flux:menu.item>
                    <flux:menu.separator />
                    <flux:menu.item wire:click="openSnooze({{ $activity->id }})">{{ __('Custom...') }}</flux:menu.item>
                </flux:menu>
            </flux:dropdown>

            {{-- Priority --}}
            <flux:dropdown>
                <flux:button variant="ghost" size="sm" icon="flag" class="!p-1" />
                <flux:menu>
                    <flux:menu.item wire:click="setPriority({{ $activity->id }}, 'urgent')">
                        <span class="text-red-600">{{ __('Urgent') }}</span>
                    </flux:menu.item>
                    <flux:menu.item wire:click="setPriority({{ $activity->id }}, 'high')">
                        <span class="text-yellow-600">{{ __('High') }}</span>
                    </flux:menu.item>
                    <flux:menu.item wire:click="setPriority({{ $activity->id }}, 'normal')">{{ __('Normal') }}</flux:menu.item>
                    <flux:menu.item wire:click="setPriority({{ $activity->id }}, 'low')">
                        <span class="text-zinc-400">{{ __('Low') }}</span>
                    </flux:menu.item>
                </flux:menu>
            </flux:dropdown>
        @endunless

        {{-- Deal stage actions --}}
        @if ($isDeal && $activity->subject && ! $activity->is_completed)
            @php $deal = $activity->subject; @endphp
            @if (in_array($deal->stage, \App\Models\Deal::PLANNER_STAGES))
                <flux:dropdown>
                    <flux:button variant="ghost" size="sm" icon="arrow-right" class="!p-1" />
                    <flux:menu>
                        <flux:menu.heading>{{ __('Advance Deal') }}</flux:menu.heading>
                        @if ($deal->stage === 'not_contacted')
                            <flux:menu.item wire:click="advanceDealStage({{ $activity->id }}, 'follow_up')">{{ __('Set Follow-up') }}</flux:menu.item>
                        @endif
                        <flux:menu.item wire:click="advanceDealStage({{ $activity->id }}, 'quote_scheduled')">{{ __('Schedule Quote') }}</flux:menu.item>
                        <flux:menu.separator />
                        <flux:menu.item wire:click="advanceDealStage({{ $activity->id }}, 'lost')">
                            <span class="text-red-600">{{ __('Mark Lost') }}</span>
                        </flux:menu.item>
                    </flux:menu>
                </flux:dropdown>
            @endif
        @endif

        {{-- Job actions --}}
        @if ($isJob && $activity->subject && ! $activity->is_completed)
            <flux:button variant="ghost" size="sm" icon="eye" class="!p-1" wire:click="viewJob({{ $activity->id }})" title="{{ __('View Job') }}" />
        @endif
    </div>
</div>
