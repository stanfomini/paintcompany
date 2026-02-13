<?php

use App\Models\Activity;
use App\Models\ChatThread;
use App\Models\Deal;
use App\Models\Job;
use App\Models\User;
use App\Notifications\TaskAssigned;
use App\Services\DealStageService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public function boot(): void
    {
        if (! auth()->user()?->can('planner.view')) {
            abort(403);
        }
    }

    public string $view = 'my_day';

    public string $typeFilter = 'all';

    public string $statusFilter = 'all';

    // Calendar sync
    public bool $showCalendarSync = false;

    // Quick add form
    public bool $showAddTask = false;

    public string $taskTitle = '';

    public string $taskType = 'task';

    public ?string $taskDueAt = null;

    public string $taskDescription = '';

    public ?int $taskAssignedTo = null;

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedView(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function activityTypes(): array
    {
        return Activity::TYPES;
    }

    #[Computed]
    public function teamMembers()
    {
        return User::where('type', 'internal')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function activityCalendarUrl(): string
    {
        $token = auth()->user()->getOrCreateCalendarToken();

        return route('calendar.activities', ['token' => $token]);
    }

    #[On('activity-created')]
    #[On('activity-updated')]
    public function refreshList(): void
    {
        // Triggers re-render
    }

    public function toggleComplete(int $activityId): void
    {
        $activity = Activity::query()
            ->forUserOrAssignedTo(auth()->user())
            ->findOrFail($activityId);

        if ($activity->is_completed) {
            $activity->markAsIncomplete();
        } else {
            $activity->markAsCompleted();
        }
    }

    // Quick Add Task
    public function openAddTask(): void
    {
        $this->taskDueAt = today()->format('Y-m-d');
        $this->showAddTask = true;
    }

    public function closeAddTask(): void
    {
        $this->showAddTask = false;
        $this->reset(['taskTitle', 'taskType', 'taskDueAt', 'taskDescription', 'taskAssignedTo']);
        $this->taskType = 'task';
        $this->resetValidation();
    }

    public function saveTask(): void
    {
        $this->validate([
            'taskTitle' => ['required', 'string', 'max:255'],
            'taskType' => ['required', 'in:task,note,call,email,meeting,other'],
            'taskDueAt' => ['nullable', 'date'],
            'taskDescription' => ['nullable', 'string', 'max:5000'],
            'taskAssignedTo' => ['nullable', 'exists:users,id'],
        ]);

        // Only users with planner.manage can assign to others
        $assignedTo = $this->taskAssignedTo ?: auth()->id();
        if ($assignedTo !== auth()->id() && ! auth()->user()->can('planner.manage')) {
            $assignedTo = auth()->id();
        }

        $activity = Activity::create([
            'user_id' => auth()->id(),
            'assigned_to' => $assignedTo,
            'type' => $this->taskType,
            'title' => $this->taskTitle,
            'description' => $this->taskDescription ?: null,
            'due_at' => $this->taskDueAt ?: null,
            'is_completed' => false,
        ]);

        // Send notification if assigned to someone else
        if ($this->taskAssignedTo && $this->taskAssignedTo !== auth()->id()) {
            $assignee = User::find($this->taskAssignedTo);
            $assignee?->notify(new TaskAssigned($activity));
        }

        $this->dispatch('activity-created');
        session()->flash('success', __('Task created successfully.'));
        $this->closeAddTask();
    }

    // Deal stage advancement from planner
    public bool $showDealFollowUpModal = false;

    public ?int $advancingActivityId = null;

    public string $advancingStage = '';

    public ?string $dealFollowUpDate = null;

    /**
     * Advance a deal's stage from a planner activity.
     */
    public function advanceDealStage(int $activityId, string $newStage): void
    {
        $activity = Activity::query()
            ->forUserOrAssignedTo(auth()->user())
            ->where('subject_type', Deal::class)
            ->findOrFail($activityId);

        if ($newStage === 'follow_up' || $newStage === 'quote_scheduled') {
            $this->advancingActivityId = $activityId;
            $this->advancingStage = $newStage;
            $this->dealFollowUpDate = $newStage === 'quote_scheduled'
                ? now()->addDay()->setTime(9, 0)->format('Y-m-d\TH:i')
                : now()->addDay()->toDateString();
            $this->showDealFollowUpModal = true;

            return;
        }

        $deal = Deal::findOrFail($activity->subject_id);
        $service = app(DealStageService::class);
        $service->transitionTo($deal, $newStage);

        // Mark the follow-up activity as completed if moving to a kanban stage
        if (in_array($newStage, Deal::KANBAN_STAGES)) {
            $activity->markAsCompleted();
        }
    }

    /**
     * Confirm follow-up with date from modal.
     */
    public function confirmDealFollowUp(): void
    {
        if (! $this->advancingActivityId) {
            return;
        }

        $activity = Activity::query()
            ->forUserOrAssignedTo(auth()->user())
            ->where('subject_type', Deal::class)
            ->findOrFail($this->advancingActivityId);

        $deal = Deal::findOrFail($activity->subject_id);
        $service = app(DealStageService::class);
        $stage = $this->advancingStage ?: 'follow_up';
        $service->transitionTo($deal, $stage, $this->dealFollowUpDate);

        // Mark the current follow-up as completed since a new one was created
        $activity->markAsCompleted();

        $this->showDealFollowUpModal = false;
        $this->advancingActivityId = null;
        $this->dealFollowUpDate = null;
    }

    public function closeDealFollowUpModal(): void
    {
        $this->showDealFollowUpModal = false;
        $this->advancingActivityId = null;
        $this->advancingStage = '';
        $this->dealFollowUpDate = null;
    }

    /**
     * Navigate to a job from a planner activity.
     */
    public function viewJob(int $activityId): void
    {
        $activity = Activity::query()
            ->forUserOrAssignedTo(auth()->user())
            ->where('subject_type', Job::class)
            ->findOrFail($activityId);

        $this->redirect(route('jobs.show', $activity->subject_id), navigate: true);
    }

    /**
     * Confirm company date from planner and mark activity done.
     */
    public function confirmJobDate(int $activityId): void
    {
        $activity = Activity::query()
            ->forUserOrAssignedTo(auth()->user())
            ->where('subject_type', Job::class)
            ->findOrFail($activityId);

        $job = Job::findOrFail($activity->subject_id);
        app(\App\Services\JobService::class)->confirmCompanyDate($job);

        if ($activity->automation_key === 'review_proposed_date') {
            $activity->markAsCompleted();
        }
    }

    /**
     * Navigate to job page for dispatching crew.
     */
    public function goToDispatch(int $activityId): void
    {
        $activity = Activity::query()
            ->forUserOrAssignedTo(auth()->user())
            ->where('subject_type', Job::class)
            ->findOrFail($activityId);

        $this->redirect(route('jobs.show', $activity->subject_id), navigate: true);
    }

    /**
     * Stage colors for deal badges.
     */
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
            'lost' => 'red',
            'cancelled' => 'zinc',
        ];
    }

    #[Computed]
    public function jobStageColors(): array
    {
        return [
            'estimate_accepted' => 'blue',
            'scheduling_colors' => 'yellow',
            'job_prep' => 'purple',
            'in_progress' => 'green',
            'completed' => 'zinc',
        ];
    }

    #[Computed]
    public function unreadThreads()
    {
        $user = auth()->user();

        return ChatThread::query()
            ->forUser($user)
            ->active()
            ->with(['latestMessage.user', 'deal.customer', 'activeParticipants.user'])
            ->whereHas('messages', function ($q) use ($user) {
                $q->where('user_id', '!=', $user->id)
                    ->where('created_at', '>', function ($sub) use ($user) {
                        $sub->selectRaw("COALESCE(last_read_at, '1970-01-01')")
                            ->from('chat_participants')
                            ->whereColumn('chat_participants.thread_id', 'chat_messages.thread_id')
                            ->where('chat_participants.user_id', $user->id)
                            ->limit(1);
                    });
            })
            ->latestActivity()
            ->limit(20)
            ->get();
    }

    #[Computed]
    public function activities()
    {
        return $this->getActivitiesQuery()->paginate(20);
    }

    protected function getActivitiesQuery()
    {
        $query = Activity::query()
            ->with(['user', 'assignee', 'subject' => function ($morphTo) {
                $morphTo->morphWith([
                    Deal::class => ['customer'],
                    Job::class => ['deal.customer'],
                    User::class => ['agentProfile'],
                ]);
            }])
            ->when($this->typeFilter !== 'all', function ($query) {
                return $query->ofType($this->typeFilter);
            })
            ->when($this->statusFilter === 'incomplete', function ($query) {
                return $query->incomplete();
            })
            ->when($this->statusFilter === 'completed', function ($query) {
                return $query->completed();
            })
            ->when($this->statusFilter === 'overdue', function ($query) {
                return $query->overdue();
            });

        // Apply view-specific filters
        // "Mine" = assigned to me, OR unassigned but created by me
        $mineScope = function ($query) {
            $query->where(function ($q) {
                $q->where('assigned_to', auth()->id())
                    ->orWhere(function ($q2) {
                        $q2->whereNull('assigned_to')
                            ->where('user_id', auth()->id());
                    });
            });
        };

        switch ($this->view) {
            case 'my_day':
                $mineScope($query);
                $query->where(function ($q) {
                    $q->whereDate('due_at', today())
                        ->orWhere(function ($q2) {
                            // Overdue and incomplete
                            $q2->where('due_at', '<', today())
                                ->where('is_completed', false);
                        })
                        ->orWhere(function ($q2) {
                            // No due date and incomplete (unscheduled tasks)
                            $q2->whereNull('due_at')
                                ->where('is_completed', false);
                        });
                });
                break;

            case 'my_week':
                $mineScope($query);
                $query->where(function ($q) {
                    $q->whereBetween('due_at', [now()->startOfWeek(), now()->endOfWeek()])
                        ->orWhere(function ($q2) {
                            // Also show unscheduled incomplete tasks
                            $q2->whereNull('due_at')
                                ->where('is_completed', false);
                        });
                });
                break;

            case 'all_mine':
                $mineScope($query);
                break;

            case 'created_by_me':
                $query->forUser(auth()->user());
                break;

            case 'team':
                // Only users with planner.view_all can see all team activities
                if (! auth()->user()->can('planner.view_all')) {
                    $query->where(function ($q) {
                        $q->where('user_id', auth()->id())
                            ->orWhere('assigned_to', auth()->id());
                    });
                }
                break;
        }

        return $query
            ->orderByRaw('is_completed ASC')
            ->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_at')
            ->orderBy('created_at', 'desc');
    }
}; ?>

<div>
    <div class="max-w-5xl mx-auto">
        {{-- Header --}}
        <div class="flex items-center justify-between mb-6">
            <flux:heading size="xl">{{ __('Planner') }}</flux:heading>
            <div class="flex items-center gap-2">
                <flux:button icon="calendar" variant="ghost" wire:click="$set('showCalendarSync', true)">
                    {{ __('Subscribe') }}
                </flux:button>
                <flux:button icon="plus" wire:click="openAddTask">
                    {{ __('Add Task') }}
                </flux:button>
            </div>
        </div>

        @if (session('success'))
            <flux:callout variant="success" class="mb-4">
                {{ session('success') }}
            </flux:callout>
        @endif

        {{-- View Tabs --}}
        <div class="flex items-center gap-2 mb-6 overflow-x-auto pb-2">
            <flux:button
                size="sm"
                :variant="$view === 'my_day' ? 'primary' : 'ghost'"
                wire:click="$set('view', 'my_day')"
            >
                {{ __('My Day') }}
            </flux:button>
            <flux:button
                size="sm"
                :variant="$view === 'my_week' ? 'primary' : 'ghost'"
                wire:click="$set('view', 'my_week')"
            >
                {{ __('My Week') }}
            </flux:button>
            <flux:button
                size="sm"
                :variant="$view === 'all_mine' ? 'primary' : 'ghost'"
                wire:click="$set('view', 'all_mine')"
            >
                {{ __('All Assigned to Me') }}
            </flux:button>
            <flux:button
                size="sm"
                :variant="$view === 'created_by_me' ? 'primary' : 'ghost'"
                wire:click="$set('view', 'created_by_me')"
            >
                {{ __('Created by Me') }}
            </flux:button>
            @can('planner.view_all')
                <flux:button
                    size="sm"
                    :variant="$view === 'team' ? 'primary' : 'ghost'"
                    wire:click="$set('view', 'team')"
                >
                    {{ __('Team View') }}
                </flux:button>
            @endcan
            <flux:button
                size="sm"
                :variant="$view === 'calendar' ? 'primary' : 'ghost'"
                wire:click="$set('view', 'calendar')"
                icon="calendar-days"
            >
                {{ __('Calendar') }}
            </flux:button>
        </div>

        {{-- Filters --}}
        <div class="flex flex-wrap items-center gap-4 mb-6">
            <flux:select wire:model.live="typeFilter" class="w-40">
                <option value="all">{{ __('All Types') }}</option>
                @foreach ($this->activityTypes as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="statusFilter" class="w-40">
                <option value="all">{{ __('All Status') }}</option>
                <option value="incomplete">{{ __('Incomplete') }}</option>
                <option value="completed">{{ __('Completed') }}</option>
                <option value="overdue">{{ __('Overdue') }}</option>
            </flux:select>
        </div>

        {{-- Unread Messages --}}
        @if ($this->unreadThreads->isNotEmpty())
            <div class="mb-4">
                <div class="flex items-center gap-2 mb-2">
                    <flux:icon name="envelope" class="w-4 h-4 text-blue-600" />
                    <flux:text class="font-medium text-sm">{{ __('Unread Messages') }} ({{ $this->unreadThreads->count() }})</flux:text>
                </div>
                <div class="border border-blue-200 dark:border-blue-800 rounded-lg divide-y divide-blue-100 dark:divide-blue-900 bg-blue-50/50 dark:bg-blue-950/20">
                    @foreach ($this->unreadThreads as $thread)
                        <a href="{{ route('chat.show', $thread) }}" wire:navigate wire:key="thread-{{ $thread->id }}" class="flex items-start gap-3 p-3 hover:bg-blue-100/50 dark:hover:bg-blue-900/30 transition-colors">
                            <div class="shrink-0 mt-0.5">
                                <div class="w-8 h-8 rounded-full bg-blue-100 dark:bg-blue-900 flex items-center justify-center">
                                    <flux:icon name="chat-bubble-left-right" class="w-4 h-4 text-blue-600 dark:text-blue-400" />
                                </div>
                            </div>
                            <div class="flex-1 min-w-0">
                                <flux:text class="font-medium text-sm">
                                    {{ $thread->latestMessage?->user?->name ?? 'Unknown' }}
                                </flux:text>
                                <flux:text class="text-xs text-zinc-600 dark:text-zinc-400 truncate">
                                    {{ $thread->getDisplayNameFor(auth()->user()) }}
                                </flux:text>
                                @if ($thread->latestMessage)
                                    <flux:text class="text-xs text-zinc-500 truncate mt-0.5">
                                        {{ Str::limit($thread->latestMessage->content, 80) }}
                                    </flux:text>
                                @endif
                            </div>
                            <div class="shrink-0 text-right">
                                <flux:text class="text-xs text-zinc-400">
                                    {{ ($thread->last_message_at ?? $thread->created_at)->diffForHumans() }}
                                </flux:text>
                                @if ($thread->deal)
                                    <flux:badge size="sm" color="blue" class="mt-1">Deal</flux:badge>
                                @endif
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif

        @if ($view === 'calendar')
            <div x-data="calendarView" class="mt-4">
                <div x-ref="calendar"></div>
            </div>
        @else
        {{-- Activities List --}}
        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg divide-y divide-zinc-200 dark:divide-zinc-700">
            @forelse ($this->activities as $activity)
                <div
                    wire:key="activity-{{ $activity->id }}"
                    class="p-4 flex items-start gap-4 {{ $activity->is_completed ? 'bg-zinc-50 dark:bg-zinc-900/50' : '' }}"
                >
                    <div class="shrink-0 pt-0.5">
                        <button
                            wire:click="toggleComplete({{ $activity->id }})"
                            class="w-5 h-5 rounded border-2 flex items-center justify-center transition-colors {{ $activity->is_completed ? 'bg-green-500 border-green-500 text-white' : 'border-zinc-300 dark:border-zinc-600 hover:border-green-500' }}"
                        >
                            @if ($activity->is_completed)
                                <flux:icon name="check" class="w-3 h-3" />
                            @endif
                        </button>
                    </div>

                    {{-- Content --}}
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
                            <div class="shrink-0 text-right">
                                <flux:badge size="sm" color="zinc">{{ $activity->type_label }}</flux:badge>
                            </div>
                        </div>

                        {{-- Deal context (stage badge + customer name) --}}
                        @if ($activity->subject_type === \App\Models\Deal::class && $activity->subject)
                            @php $deal = $activity->subject; @endphp
                            <div class="flex flex-wrap items-center gap-2 mt-2 p-2 bg-zinc-50 dark:bg-zinc-900/50 rounded">
                                <flux:badge size="sm" :color="$this->dealStageColors[$deal->stage] ?? 'zinc'">
                                    {{ $deal->stage_label }}
                                </flux:badge>
                                @if ($deal->customer)
                                    <a href="{{ route('customers.show', ['customer' => $deal->customer_id, 'deal' => $deal->id]) }}" wire:navigate class="text-xs text-zinc-600 dark:text-zinc-400 hover:text-blue-600 dark:hover:text-blue-400 flex items-center gap-1">
                                        <flux:icon name="user" class="w-3 h-3" />
                                        {{ $deal->customer->full_name }}
                                    </a>
                                @endif
                                @if ($deal->estimated_value)
                                    <span class="text-xs text-zinc-500">${{ number_format($deal->estimated_value, 0) }}</span>
                                @endif

                                {{-- Quick action buttons for deal advancement --}}
                                @if (! $activity->is_completed && $deal->isOnPlanner())
                                    <div class="flex items-center gap-1 ml-auto">
                                        <button wire:click="advanceDealStage({{ $activity->id }}, 'quote_scheduled')" class="text-xs px-2 py-0.5 rounded bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 hover:bg-purple-200 dark:hover:bg-purple-900/50 transition-colors">
                                            {{ __('Schedule Quote') }}
                                        </button>
                                        <button wire:click="advanceDealStage({{ $activity->id }}, 'follow_up')" class="text-xs px-2 py-0.5 rounded bg-amber-100 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300 hover:bg-amber-200 dark:hover:bg-amber-900/50 transition-colors">
                                            {{ __('Follow Up') }}
                                        </button>
                                        <button wire:click="advanceDealStage({{ $activity->id }}, 'lost')" wire:confirm="{{ __('Mark this deal as lost?') }}" class="text-xs px-2 py-0.5 rounded bg-red-50 dark:bg-red-900/20 text-red-600 dark:text-red-400 hover:bg-red-100 dark:hover:bg-red-900/30 transition-colors">
                                            {{ __('Lost') }}
                                        </button>
                                    </div>
                                @endif
                            </div>
                        @endif

                        {{-- Job context (stage badge + customer name + quick actions) --}}
                        @if ($activity->subject_type === \App\Models\Job::class && $activity->subject)
                            @php $job = $activity->subject; @endphp
                            <div class="flex flex-wrap items-center gap-2 mt-2 p-2 bg-zinc-50 dark:bg-zinc-900/50 rounded">
                                <flux:badge size="sm" :color="$this->jobStageColors[$job->stage] ?? 'zinc'">
                                    {{ str_replace('_', ' ', ucfirst($job->stage)) }}
                                </flux:badge>
                                @if ($job->deal?->customer)
                                    <a href="{{ route('customers.show', $job->deal->customer_id) }}" wire:navigate class="text-xs text-zinc-600 dark:text-zinc-400 hover:text-blue-600 dark:hover:text-blue-400 flex items-center gap-1">
                                        <flux:icon name="user" class="w-3 h-3" />
                                        {{ $job->deal->customer->full_name }}
                                    </a>
                                @endif
                                @if ($job->scheduled_date)
                                    <span class="text-xs text-zinc-500 flex items-center gap-1">
                                        <flux:icon name="calendar" class="w-3 h-3" />
                                        {{ $job->scheduled_date->format('M j, Y') }}
                                    </span>
                                @endif

                                {{-- Job quick actions --}}
                                @if (! $activity->is_completed)
                                    <div class="flex items-center gap-1 ml-auto">
                                        @if ($activity->automation_key === 'review_proposed_date')
                                            <button wire:click="confirmJobDate({{ $activity->id }})" class="text-xs px-2 py-0.5 rounded bg-green-100 dark:bg-green-900/30 text-green-700 dark:text-green-300 hover:bg-green-200 dark:hover:bg-green-900/50 transition-colors">
                                                {{ __('Confirm Date') }}
                                            </button>
                                        @endif
                                        @if ($activity->automation_key === 'ready_for_dispatch')
                                            <button wire:click="goToDispatch({{ $activity->id }})" class="text-xs px-2 py-0.5 rounded bg-purple-100 dark:bg-purple-900/30 text-purple-700 dark:text-purple-300 hover:bg-purple-200 dark:hover:bg-purple-900/50 transition-colors">
                                                {{ __('Dispatch Crew') }}
                                            </button>
                                        @endif
                                        <button wire:click="viewJob({{ $activity->id }})" class="text-xs px-2 py-0.5 rounded bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 hover:bg-blue-200 dark:hover:bg-blue-900/50 transition-colors">
                                            {{ __('View Job') }}
                                        </button>
                                    </div>
                                @endif
                            </div>
                        @endif

                        {{-- User context (agent/crew profile link) --}}
                        @if ($activity->subject_type === \App\Models\User::class && $activity->subject)
                            @php $subjectUser = $activity->subject; @endphp
                            <div class="flex flex-wrap items-center gap-2 mt-2 p-2 bg-zinc-50 dark:bg-zinc-900/50 rounded">
                                <flux:badge size="sm" :color="$subjectUser->isAgent() ? 'blue' : ($subjectUser->isCrew() ? 'purple' : 'zinc')">
                                    {{ $subjectUser->isAgent() ? 'Agent' : ($subjectUser->isCrew() ? 'Crew' : ucfirst($subjectUser->type)) }}
                                </flux:badge>
                                <span class="text-xs text-zinc-600 dark:text-zinc-400 font-medium">{{ $subjectUser->name }}</span>
                                @if ($subjectUser->isAgent() && $subjectUser->agentProfile?->company_name)
                                    <span class="text-xs text-zinc-500">{{ $subjectUser->agentProfile->company_name }}</span>
                                @endif
                                @if ($activity->getSubjectUrl())
                                    <a href="{{ $activity->getSubjectUrl() }}" wire:navigate class="text-xs px-2 py-0.5 rounded bg-blue-100 dark:bg-blue-900/30 text-blue-700 dark:text-blue-300 hover:bg-blue-200 dark:hover:bg-blue-900/50 transition-colors ml-auto">
                                        {{ __('View Profile') }}
                                    </a>
                                @endif
                            </div>
                        @endif

                        {{-- Meta info --}}
                        <div class="flex flex-wrap items-center gap-3 mt-2">
                            @if ($activity->due_at)
                                <div class="flex items-center gap-1 text-xs {{ $activity->isOverdue() ? 'text-red-500' : 'text-zinc-500' }}">
                                    <flux:icon name="calendar" class="w-3 h-3" />
                                    <span>{{ $activity->due_at->format('M j') }}</span>
                                    @if ($activity->isOverdue())
                                        <span class="font-medium">({{ __('Overdue') }})</span>
                                    @endif
                                </div>
                            @endif

                            @if ($activity->assignee)
                                <div class="flex items-center gap-1 text-xs text-zinc-500">
                                    <flux:icon name="user" class="w-3 h-3" />
                                    <span>{{ $activity->assignee->name }}</span>
                                </div>
                            @endif

                            @if ($activity->getSubjectUrl())
                                <a href="{{ $activity->getSubjectUrl() }}" wire:navigate class="flex items-center gap-1 text-xs text-blue-600 hover:text-blue-800 dark:text-blue-400 dark:hover:text-blue-300">
                                    <flux:icon name="link" class="w-3 h-3" />
                                    {{ $activity->getSubjectLabel() }}
                                </a>
                            @endif

                            @if ($activity->user && $activity->user_id !== $activity->assigned_to)
                                <div class="flex items-center gap-1 text-xs text-zinc-400">
                                    <span>by {{ $activity->user->name }}</span>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <div class="p-8 text-center">
                    <flux:icon name="check-circle" class="w-12 h-12 mx-auto text-zinc-300 dark:text-zinc-600 mb-4" />
                    <flux:heading size="sm" class="text-zinc-500">{{ __('No tasks found') }}</flux:heading>
                    <flux:text size="sm" class="text-zinc-400 mt-1">
                        @switch($view)
                            @case('my_day')
                                {{ __("You're all caught up for today!") }}
                                @break
                            @case('my_week')
                                {{ __('No tasks scheduled for this week.') }}
                                @break
                            @default
                                {{ __('No tasks match your filters.') }}
                        @endswitch
                    </flux:text>
                </div>
            @endforelse
        </div>

        {{-- Pagination --}}
        @if ($this->activities->hasPages())
            <div class="mt-4">
                {{ $this->activities->links() }}
            </div>
        @endif
        @endif
    </div>

    {{-- Add Task Modal --}}
    <flux:modal wire:model="showAddTask" variant="flyout" class="max-w-md">
        <form wire:submit="saveTask">
            <flux:heading size="lg" class="mb-4">{{ __('Add Task') }}</flux:heading>

            <div class="space-y-4">
                <flux:field>
                    <flux:label>{{ __('Title') }}</flux:label>
                    <flux:input wire:model="taskTitle" :placeholder="__('What needs to be done?')" />
                    <flux:error name="taskTitle" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Type') }}</flux:label>
                    <flux:select wire:model="taskType">
                        @foreach ($this->activityTypes as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="taskType" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Due Date') }}</flux:label>
                    <flux:input wire:model="taskDueAt" type="date" />
                    <flux:error name="taskDueAt" />
                </flux:field>

                @can('planner.manage')
                <flux:field>
                    <flux:label>{{ __('Assign To') }}</flux:label>
                    <flux:select wire:model="taskAssignedTo">
                        <option value="">{{ __('Myself') }}</option>
                        @foreach ($this->teamMembers as $member)
                            @if ($member->id !== auth()->id())
                                <option value="{{ $member->id }}">{{ $member->name }}</option>
                            @endif
                        @endforeach
                    </flux:select>
                    <flux:error name="taskAssignedTo" />
                </flux:field>
                @endcan

                <flux:field>
                    <flux:label>{{ __('Description (Optional)') }}</flux:label>
                    <flux:textarea wire:model="taskDescription" rows="3" :placeholder="__('Add details...')" />
                    <flux:error name="taskDescription" />
                </flux:field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="closeAddTask" type="button">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">{{ __('Add Task') }}</flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Deal Follow-Up / Quote Schedule Modal --}}
    <flux:modal wire:model="showDealFollowUpModal" class="max-w-sm">
        <form wire:submit="confirmDealFollowUp">
            <flux:heading size="lg" class="mb-4">
                {{ $advancingStage === 'quote_scheduled' ? __('Schedule Quote Appointment') : __('Schedule Follow-Up') }}
            </flux:heading>

            <flux:field>
                <flux:label>{{ $advancingStage === 'quote_scheduled' ? __('Appointment Date & Time') : __('Follow-up Date') }}</flux:label>
                <flux:input wire:model="dealFollowUpDate" :type="$advancingStage === 'quote_scheduled' ? 'datetime-local' : 'date'" />
            </flux:field>

            <div class="mt-6 flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="closeDealFollowUpModal" type="button">{{ __('Cancel') }}</flux:button>
                <flux:button type="submit" variant="primary">
                    {{ $advancingStage === 'quote_scheduled' ? __('Schedule Quote') : __('Set Follow-Up') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    {{-- Calendar Sync Modal --}}
    <flux:modal wire:model="showCalendarSync" class="md:w-[28rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Sync Activities to Calendar') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Subscribe to your activities and appointments in your favorite calendar app.') }}</flux:text>
            </div>

            @php $calendarUrl = $this->activityCalendarUrl; @endphp

            <flux:field>
                <flux:label>{{ __('Calendar Feed URL') }}</flux:label>
                <div class="flex gap-2">
                    <flux:input value="{{ $calendarUrl }}" readonly class="flex-1" />
                    <flux:button
                        variant="ghost"
                        icon="clipboard"
                        x-on:click="navigator.clipboard.writeText('{{ $calendarUrl }}'); $el.querySelector('.flux-icon')?.classList.add('text-green-500')"
                        title="Copy URL"
                    />
                </div>
                <flux:text size="sm" class="mt-1 text-zinc-500">{{ __('This URL auto-updates when activities change.') }}</flux:text>
            </flux:field>

            <div class="space-y-2">
                <a
                    href="https://calendar.google.com/calendar/r?cid=webcal://{{ str_replace(['https://', 'http://'], '', $calendarUrl) }}"
                    target="_blank"
                    class="flex w-full items-center gap-3 rounded-lg border border-zinc-200 px-4 py-3 transition-colors hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800"
                >
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none">
                        <path d="M18.316 5.684H5.684v12.632h12.632V5.684z" fill="#fff"/>
                        <path d="M18.316 24l5.684-5.684h-5.684V24z" fill="#EA4335"/>
                        <path d="M24 5.684V0H18.316v5.684H24z" fill="#188038"/>
                        <path d="M18.316 18.316V24h5.684v-5.684h-5.684z" fill="#1967D2"/>
                        <path d="M5.684 18.316L0 24h5.684v-5.684z" fill="#FBBC04"/>
                        <path d="M24 5.684H18.316v12.632H24V5.684z" fill="#34A853"/>
                        <path d="M18.316 5.684H5.684v12.632h12.632V5.684z" fill="#4285F4"/>
                        <path d="M5.684 18.316V24H0v-5.684h5.684z" fill="#34A853"/>
                        <path d="M0 5.684v12.632h5.684V5.684H0z" fill="#4285F4"/>
                        <path d="M5.684 0H0v5.684h5.684V0z" fill="#188038"/>
                    </svg>
                    <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ __('Add to Google Calendar') }}</span>
                </a>

                <a
                    href="webcal://{{ str_replace(['https://', 'http://'], '', $calendarUrl) }}"
                    class="flex w-full items-center gap-3 rounded-lg border border-zinc-200 px-4 py-3 transition-colors hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800"
                >
                    <svg class="h-5 w-5 text-zinc-700 dark:text-zinc-300" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V9h14v11z"/>
                    </svg>
                    <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ __('Add to Apple Calendar / Outlook') }}</span>
                </a>
            </div>

            <flux:text size="sm" class="text-zinc-500">
                {{ __('Your calendar app will periodically check for updates. Changes to your activities will sync automatically.') }}
            </flux:text>

            <div class="flex justify-end">
                <flux:button variant="ghost" wire:click="$set('showCalendarSync', false)">
                    {{ __('Close') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
