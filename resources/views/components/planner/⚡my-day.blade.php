<?php

use App\Models\Activity;
use App\Models\ChatThread;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Job;
use App\Models\User;
use App\Notifications\TaskAssigned;
use App\Services\CommandCenterService;
use App\Services\DealStageService;
use App\Services\PriorityScoreService;
use App\Services\SlaMetricService;
use App\Services\UnifiedCalendarService;
use Carbon\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
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

    // Command Center filters
    #[Url]
    public string $scope = 'mine';

    #[Url]
    public string $statusFilter = 'active';

    #[Url]
    public string $typeFilter = 'all';

    #[Url]
    public string $timeFilter = 'all';

    #[Url]
    public string $search = '';

    public string $view = 'command_center';

    // Calendar sync
    public bool $showCalendarSync = false;

    // Calendar view
    public string $calendarMode = 'month'; // day, week, month

    public ?string $calendarDate = null;

    public function mountCalendar(): void
    {
        $this->calendarDate = today()->format('Y-m-d');
    }

    public function mount(): void
    {
        $this->calendarDate = today()->format('Y-m-d');
    }

    public function calendarPrev(): void
    {
        $date = Carbon::parse($this->calendarDate);
        $this->calendarDate = match ($this->calendarMode) {
            'day' => $date->subDay()->format('Y-m-d'),
            'week' => $date->subWeek()->format('Y-m-d'),
            'month' => $date->subMonth()->format('Y-m-d'),
        };
    }

    public function calendarNext(): void
    {
        $date = Carbon::parse($this->calendarDate);
        $this->calendarDate = match ($this->calendarMode) {
            'day' => $date->addDay()->format('Y-m-d'),
            'week' => $date->addWeek()->format('Y-m-d'),
            'month' => $date->addMonth()->format('Y-m-d'),
        };
    }

    public function calendarToday(): void
    {
        $this->calendarDate = today()->format('Y-m-d');
    }

    #[Computed]
    public function calendarEvents(): array
    {
        $service = app(UnifiedCalendarService::class);
        $date = Carbon::parse($this->calendarDate);

        $events = match ($this->calendarMode) {
            'day' => $service->getEventsForDate(auth()->user(), $date),
            'week' => $service->getEventsForWeek(auth()->user(), $date->copy()->startOfWeek()),
            'month' => $service->getEventsForMonth(auth()->user(), $date->copy()->startOfMonth()),
        };

        return $events->map(fn ($event) => [
            'id' => $event['id'],
            'title' => $event['title'],
            'start' => $event['start']->format('Y-m-d H:i'),
            'start_time' => $event['start']->format('g:ia'),
            'end' => $event['end']->format('Y-m-d H:i'),
            'end_time' => $event['end']->format('g:ia'),
            'date' => $event['start']->format('Y-m-d'),
            'type' => $event['type'],
            'source' => $event['source'],
            'color' => $event['color'],
            'url' => $event['url'],
            'meta' => $event['meta'],
        ])->toArray();
    }

    #[Computed]
    public function calendarTitle(): string
    {
        $date = Carbon::parse($this->calendarDate);

        return match ($this->calendarMode) {
            'day' => $date->format('l, F j, Y'),
            'week' => $date->copy()->startOfWeek()->format('M j') . ' - ' . $date->copy()->endOfWeek()->format('M j, Y'),
            'month' => $date->format('F Y'),
        };
    }

    #[Computed]
    public function calendarDays(): array
    {
        $date = Carbon::parse($this->calendarDate);

        if ($this->calendarMode === 'day') {
            return [$date->format('Y-m-d')];
        }

        if ($this->calendarMode === 'week') {
            $start = $date->copy()->startOfWeek();
            $days = [];
            for ($i = 0; $i < 7; $i++) {
                $days[] = $start->copy()->addDays($i)->format('Y-m-d');
            }

            return $days;
        }

        // Month view - include padding days from previous/next month
        $monthStart = $date->copy()->startOfMonth();
        $monthEnd = $date->copy()->endOfMonth();
        $calStart = $monthStart->copy()->startOfWeek();
        $calEnd = $monthEnd->copy()->endOfWeek();

        $days = [];
        $current = $calStart->copy();
        while ($current <= $calEnd) {
            $days[] = $current->format('Y-m-d');
            $current->addDay();
        }

        return $days;
    }

    // Quick add form
    public bool $showAddTask = false;

    public string $taskTitle = '';

    public string $taskType = 'task';

    public string $taskPriority = 'normal';

    public ?string $taskDueAt = null;

    public ?string $taskEndsAt = null;

    public string $taskDescription = '';

    public ?int $taskAssignedTo = null;

    // Task subject linking (Step 4)
    public ?string $taskSubjectType = null;

    public ?int $taskSubjectId = null;

    // Context panel
    public bool $showContextPanel = false;

    public ?string $contextSubjectType = null;

    public ?int $contextSubjectId = null;

    // Snooze
    public bool $showSnoozeModal = false;

    public ?int $snoozeActivityId = null;

    public string $snoozeUntil = '';

    public function updatedScope(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedTimeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
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
    public function dashboardMetrics(): array
    {
        return app(SlaMetricService::class)->getDashboardMetrics(auth()->user());
    }

    #[Computed]
    public function activityCalendarUrl(): string
    {
        $token = auth()->user()->getOrCreateCalendarToken();

        return route('calendar.activities', ['token' => $token]);
    }

    // ============ Contact Searchable List ============

    #[Computed]
    public function searchableContacts()
    {
        return User::query()
            ->whereIn('type', ['customer', 'agent', 'crew'])
            ->where('is_active', true)
            ->orderBy('name')
            ->limit(200)
            ->get(['id', 'name', 'type']);
    }

    #[On('activity-created')]
    #[On('activity-updated')]
    public function refreshList(): void
    {
        unset($this->smartInbox);
        unset($this->activities);
        unset($this->unreadThreads);
        unset($this->calendarEvents);
    }

    // ============ Smart Inbox ============

    #[Computed]
    public function smartInbox()
    {
        $service = app(CommandCenterService::class);

        return $service->getSmartInbox(auth()->user(), 10);
    }

    // ============ Activity Stream ============

    #[Computed]
    public function activities()
    {
        $service = app(CommandCenterService::class);

        $filters = [
            'scope' => $this->scope,
            'status' => $this->statusFilter,
        ];

        if ($this->typeFilter !== 'all') {
            $filters['type'] = $this->typeFilter;
        }

        if ($this->timeFilter !== 'all') {
            $filters['time'] = $this->timeFilter;
        }

        return $service->getActivityStream(
            auth()->user(),
            $filters,
            $this->search ?: null,
            20,
        );
    }

    // ============ Unread Messages ============

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

    // ============ Actions ============

    /**
     * Base query for action methods. If the user has planner.view_all,
     * they can act on any activity (matches CommandCenterService viewing logic).
     * Otherwise, restrict to activities they created or are assigned to.
     */
    private function activityQueryForAction(): \Illuminate\Database\Eloquent\Builder
    {
        $user = auth()->user();

        if ($user->can('planner.view_all')) {
            return Activity::query();
        }

        return Activity::query()->forUserOrAssignedTo($user);
    }

    public function toggleComplete(int $activityId): void
    {
        $activity = $this->activityQueryForAction()->findOrFail($activityId);

        if ($activity->is_completed) {
            $activity->markAsIncomplete();
        } else {
            $activity->markAsCompleted();
            app(PriorityScoreService::class)->updateScore($activity->fresh());
        }
    }

    public function setPriority(int $activityId, string $priority): void
    {
        $activity = $this->activityQueryForAction()->findOrFail($activityId);

        $activity->update(['priority' => $priority]);
        app(PriorityScoreService::class)->updateScore($activity->fresh());
    }

    // Snooze
    public function openSnooze(int $activityId): void
    {
        $this->snoozeActivityId = $activityId;
        $this->snoozeUntil = now()->addHours(4)->format('Y-m-d\TH:i');
        $this->showSnoozeModal = true;
    }

    public function snoozeQuick(int $activityId, string $duration): void
    {
        $activity = $this->activityQueryForAction()->findOrFail($activityId);

        $until = match ($duration) {
            '1h' => now()->addHour(),
            '4h' => now()->addHours(4),
            'tomorrow' => now()->addDay()->setTime(9, 0),
            'next_week' => now()->next('Monday')->setTime(9, 0),
            default => now()->addHours(4),
        };

        $activity->snoozeUntil($until);
    }

    public function confirmSnooze(): void
    {
        if (! $this->snoozeActivityId || ! $this->snoozeUntil) {
            return;
        }

        $activity = $this->activityQueryForAction()->findOrFail($this->snoozeActivityId);

        $activity->snoozeUntil(\Carbon\Carbon::parse($this->snoozeUntil));
        $this->showSnoozeModal = false;
        $this->snoozeActivityId = null;
    }

    // Context panel
    public function openContext(string $subjectType, int $subjectId): void
    {
        $this->contextSubjectType = $subjectType;
        $this->contextSubjectId = $subjectId;
        $this->showContextPanel = true;
    }

    public function closeContext(): void
    {
        $this->showContextPanel = false;
        $this->contextSubjectType = null;
        $this->contextSubjectId = null;
    }

    #[Computed]
    public function contextData(): ?array
    {
        if (! $this->contextSubjectType || ! $this->contextSubjectId) {
            return null;
        }

        $modelClass = $this->contextSubjectType;
        $subject = $modelClass::find($this->contextSubjectId);

        if (! $subject) {
            return null;
        }

        return app(CommandCenterService::class)->getContextForSubject($subject);
    }

    // Quick Add Task
    public function openAddTask(): void
    {
        $this->taskDueAt = now()->format('Y-m-d\TH:i');
        $this->showAddTask = true;
    }

    public function closeAddTask(): void
    {
        $this->showAddTask = false;
        $this->reset(['taskTitle', 'taskType', 'taskPriority', 'taskDueAt', 'taskEndsAt', 'taskDescription', 'taskAssignedTo', 'taskSubjectType', 'taskSubjectId']);
        $this->taskType = 'task';
        $this->taskPriority = 'normal';
        $this->resetValidation();
    }

    public function saveTask(): void
    {
        $this->validate([
            'taskTitle' => ['required', 'string', 'max:255'],
            'taskType' => ['required', 'in:task,note,call,email,meeting,other'],
            'taskPriority' => ['required', 'in:low,normal,high,urgent'],
            'taskDueAt' => ['nullable', 'date'],
            'taskEndsAt' => ['nullable', 'date'],
            'taskDescription' => ['nullable', 'string', 'max:5000'],
            'taskAssignedTo' => ['nullable', 'exists:users,id'],
        ]);

        $assignedTo = $this->taskAssignedTo ?: auth()->id();
        if ($assignedTo !== auth()->id() && ! auth()->user()->can('planner.manage')) {
            $assignedTo = auth()->id();
        }

        $data = [
            'user_id' => auth()->id(),
            'assigned_to' => $assignedTo,
            'type' => $this->taskType,
            'title' => $this->taskTitle,
            'description' => $this->taskDescription ?: null,
            'due_at' => $this->taskDueAt ?: null,
            'ends_at' => $this->taskEndsAt ?: null,
            'priority' => $this->taskPriority,
            'is_completed' => false,
        ];

        // Link to a contact if selected
        if ($this->taskSubjectType && $this->taskSubjectId) {
            $validTypes = [User::class, Customer::class];
            if (in_array($this->taskSubjectType, $validTypes)) {
                $data['subject_type'] = $this->taskSubjectType;
                $data['subject_id'] = $this->taskSubjectId;
            }
        }

        $activity = Activity::create($data);

        app(PriorityScoreService::class)->updateScore($activity);

        if ($this->taskAssignedTo && $this->taskAssignedTo !== auth()->id()) {
            $assignee = User::find($this->taskAssignedTo);
            $assignee?->notify(new TaskAssigned($activity));
        }

        $this->dispatch('activity-created');
        session()->flash('success', __('Task created successfully.'));
        $this->closeAddTask();
    }

    // ============ Deal Stage Advancement ============

    public bool $showDealFollowUpModal = false;

    public ?int $advancingActivityId = null;

    public string $advancingStage = '';

    public ?string $dealFollowUpDate = null;

    public function advanceDealStage(int $activityId, string $newStage): void
    {
        $activity = $this->activityQueryForAction()
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

        if (in_array($newStage, Deal::KANBAN_STAGES)) {
            $activity->markAsCompleted();
        }
    }

    public function confirmDealFollowUp(): void
    {
        if (! $this->advancingActivityId) {
            return;
        }

        $activity = $this->activityQueryForAction()
            ->where('subject_type', Deal::class)
            ->findOrFail($this->advancingActivityId);

        $deal = Deal::findOrFail($activity->subject_id);
        $service = app(DealStageService::class);
        $stage = $this->advancingStage ?: 'follow_up';
        $service->transitionTo($deal, $stage, $this->dealFollowUpDate);

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

    // ============ Job Actions ============

    public function viewJob(int $activityId): void
    {
        $activity = $this->activityQueryForAction()
            ->where('subject_type', Job::class)
            ->findOrFail($activityId);

        $this->redirect(route('jobs.show', $activity->subject_id), navigate: true);
    }

    public function confirmJobDate(int $activityId): void
    {
        $activity = $this->activityQueryForAction()
            ->where('subject_type', Job::class)
            ->findOrFail($activityId);

        $job = Job::findOrFail($activity->subject_id);
        app(\App\Services\JobService::class)->confirmCompanyDate($job);

        if ($activity->automation_key === 'review_proposed_date') {
            $activity->markAsCompleted();
        }
    }

    public function goToDispatch(int $activityId): void
    {
        $activity = $this->activityQueryForAction()
            ->where('subject_type', Job::class)
            ->findOrFail($activityId);

        $this->redirect(route('jobs.show', $activity->subject_id), navigate: true);
    }

    // ============ Color Maps ============

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
    public function priorityColors(): array
    {
        return [
            'urgent' => 'red',
            'high' => 'yellow',
            'normal' => 'zinc',
            'low' => 'zinc',
        ];
    }
}; ?>

<div>
    <div class="max-w-6xl mx-auto">
        {{-- Header --}}
        <div class="flex items-center justify-between mb-6">
            <div>
                <flux:heading size="xl">{{ __('Command Center') }}</flux:heading>
                <flux:text size="sm" class="text-zinc-500 mt-1">{{ __('Everything that needs your attention, in one place.') }}</flux:text>
            </div>
            <div class="flex items-center gap-2">
                <flux:button icon="calendar" variant="ghost" wire:click="$set('showCalendarSync', true)" size="sm">
                    {{ __('Subscribe') }}
                </flux:button>
                <flux:button icon="plus" wire:click="openAddTask" variant="primary" size="sm">
                    {{ __('Add Task') }}
                </flux:button>
            </div>
        </div>

        @if (session('success'))
            <flux:callout variant="success" class="mb-4">
                {{ session('success') }}
            </flux:callout>
        @endif

        {{-- Dashboard Metrics --}}
        @if ($view === 'command_center')
            <div x-data="{ metricsOpen: false }" class="mb-4">
                <button x-on:click="metricsOpen = !metricsOpen" class="flex items-center gap-2 text-sm text-zinc-500 hover:text-zinc-700 dark:hover:text-zinc-300 transition-colors">
                    <flux:icon name="chart-bar" class="w-4 h-4" />
                    <span>{{ __('Performance Metrics') }}</span>
                    <flux:icon x-bind:class="metricsOpen ? 'rotate-180' : ''" name="chevron-down" class="w-3 h-3 transition-transform" />
                </button>
                <div x-show="metricsOpen" x-collapse x-cloak class="mt-3">
                    @php $metrics = $this->dashboardMetrics; @endphp
                    <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3">
                        <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-3 text-center">
                            <div class="text-2xl font-bold text-red-600">{{ $metrics['overdue_count'] }}</div>
                            <div class="text-xs text-zinc-500 mt-1">{{ __('Overdue') }}</div>
                        </div>
                        <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-3 text-center">
                            <div class="text-2xl font-bold text-amber-600">{{ $metrics['due_today_count'] }}</div>
                            <div class="text-xs text-zinc-500 mt-1">{{ __('Due Today') }}</div>
                        </div>
                        <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-3 text-center">
                            <div class="text-2xl font-bold text-green-600">{{ $metrics['completed_today_count'] }}</div>
                            <div class="text-xs text-zinc-500 mt-1">{{ __('Done Today') }}</div>
                        </div>
                        <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-3 text-center">
                            <div class="text-2xl font-bold text-blue-600">{{ $metrics['speed_to_lead'] ? number_format($metrics['speed_to_lead'], 0) . 'm' : '-' }}</div>
                            <div class="text-xs text-zinc-500 mt-1">{{ __('Speed to Lead') }}</div>
                        </div>
                        <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-3 text-center">
                            <div class="text-2xl font-bold text-purple-600">{{ $metrics['follow_up_compliance'] ? number_format($metrics['follow_up_compliance'], 0) . '%' : '-' }}</div>
                            <div class="text-xs text-zinc-500 mt-1">{{ __('Follow-Up Rate') }}</div>
                        </div>
                        <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-3 text-center">
                            <div class="text-2xl font-bold text-cyan-600">{{ $metrics['response_time'] ? number_format($metrics['response_time'], 0) . 'm' : '-' }}</div>
                            <div class="text-xs text-zinc-500 mt-1">{{ __('Avg Response') }}</div>
                        </div>
                        <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-3 text-center">
                            <div class="text-2xl font-bold text-orange-600">{{ $metrics['estimate_turnaround'] ? number_format($metrics['estimate_turnaround'], 0) . 'h' : '-' }}</div>
                            <div class="text-xs text-zinc-500 mt-1">{{ __('Est. Turnaround') }}</div>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        {{-- Search Bar --}}
        <div class="mb-4">
            <flux:input
                wire:model.live.debounce.300ms="search"
                icon="magnifying-glass"
                :placeholder="__('Search tasks, deals, customers, jobs...')"
                clearable
            />
        </div>

        {{-- View Tabs --}}
        <div class="flex items-center gap-2 mb-4 overflow-x-auto pb-2">
            <flux:button
                size="sm"
                :variant="$view === 'command_center' ? 'primary' : 'ghost'"
                wire:click="$set('view', 'command_center')"
                icon="bolt"
            >
                {{ __('Command Center') }}
            </flux:button>
            <flux:button
                size="sm"
                :variant="$view === 'stream' ? 'primary' : 'ghost'"
                wire:click="$set('view', 'stream')"
                icon="queue-list"
            >
                {{ __('Activity Stream') }}
            </flux:button>
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
        @if ($view !== 'calendar')
            <div class="flex flex-wrap items-center gap-3 mb-6">
                <flux:select wire:model.live="scope" class="w-36">
                    <option value="mine">{{ __('My Stuff') }}</option>
                    @can('planner.view_all')
                        <option value="team">{{ __('My Team') }}</option>
                        <option value="everyone">{{ __('Everyone') }}</option>
                    @endcan
                </flux:select>

                <flux:select wire:model.live="statusFilter" class="w-40">
                    <option value="active">{{ __('Active') }}</option>
                    <option value="needs_attention">{{ __('Needs Attention') }}</option>
                    <option value="completed">{{ __('Completed') }}</option>
                    <option value="snoozed">{{ __('Snoozed') }}</option>
                    <option value="all">{{ __('All') }}</option>
                </flux:select>

                <flux:select wire:model.live="typeFilter" class="w-36">
                    <option value="all">{{ __('All Types') }}</option>
                    @foreach ($this->activityTypes as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="timeFilter" class="w-36">
                    <option value="all">{{ __('All Time') }}</option>
                    <option value="today">{{ __('Today') }}</option>
                    <option value="this_week">{{ __('This Week') }}</option>
                    <option value="this_month">{{ __('This Month') }}</option>
                    <option value="overdue">{{ __('Overdue') }}</option>
                </flux:select>
            </div>
        @endif

        @if ($view === 'calendar')
            {{-- Calendar Controls --}}
            <div class="flex items-center justify-between mb-4">
                <div class="flex items-center gap-2">
                    <flux:button size="sm" variant="ghost" wire:click="calendarPrev" icon="chevron-left" />
                    <flux:button size="sm" variant="ghost" wire:click="calendarToday">{{ __('Today') }}</flux:button>
                    <flux:button size="sm" variant="ghost" wire:click="calendarNext" icon="chevron-right" />
                    <flux:heading size="lg" class="ml-2">{{ $this->calendarTitle }}</flux:heading>
                </div>
                <div class="flex items-center gap-1">
                    <flux:button size="sm" :variant="$calendarMode === 'day' ? 'primary' : 'ghost'" wire:click="$set('calendarMode', 'day')">{{ __('Day') }}</flux:button>
                    <flux:button size="sm" :variant="$calendarMode === 'week' ? 'primary' : 'ghost'" wire:click="$set('calendarMode', 'week')">{{ __('Week') }}</flux:button>
                    <flux:button size="sm" :variant="$calendarMode === 'month' ? 'primary' : 'ghost'" wire:click="$set('calendarMode', 'month')">{{ __('Month') }}</flux:button>
                </div>
            </div>

            @php
                $events = $this->calendarEvents;
                $eventsByDate = collect($events)->groupBy('date');
                $days = $this->calendarDays;
                $today = today()->format('Y-m-d');
                $currentMonth = \Carbon\Carbon::parse($this->calendarDate)->month;
            @endphp

            @if ($calendarMode === 'month')
                {{-- Month Grid --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">
                    {{-- Day headers --}}
                    <div class="grid grid-cols-7 bg-zinc-50 dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700">
                        @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $dayName)
                            <div class="px-2 py-2 text-xs font-medium text-center text-zinc-500 dark:text-zinc-400">{{ __($dayName) }}</div>
                        @endforeach
                    </div>
                    {{-- Day cells --}}
                    <div class="grid grid-cols-7">
                        @foreach ($days as $day)
                            @php
                                $dayCarbon = \Carbon\Carbon::parse($day);
                                $isCurrentMonth = $dayCarbon->month === $currentMonth;
                                $isToday = $day === $today;
                                $dayEvents = $eventsByDate->get($day, collect());
                            @endphp
                            <div class="min-h-[100px] border-b border-r border-zinc-200 dark:border-zinc-700 p-1 {{ ! $isCurrentMonth ? 'bg-zinc-50/50 dark:bg-zinc-800/30' : '' }}">
                                <div class="flex items-center justify-center mb-1">
                                    <span class="text-xs font-medium {{ $isToday ? 'bg-blue-600 text-white rounded-full w-6 h-6 flex items-center justify-center' : ($isCurrentMonth ? 'text-zinc-700 dark:text-zinc-300' : 'text-zinc-400 dark:text-zinc-600') }}">
                                        {{ $dayCarbon->day }}
                                    </span>
                                </div>
                                <div class="space-y-0.5">
                                    @foreach ($dayEvents->take(3) as $event)
                                        <div
                                            class="text-xs px-1 py-0.5 rounded truncate {{ $event['url'] ? 'cursor-pointer' : 'cursor-default' }}"
                                            style="background-color: {{ $event['color'] }}20; color: {{ $event['color'] }}; border-left: 2px solid {{ $event['color'] }};"
                                            title="{{ $event['title'] }} ({{ $event['start_time'] }} - {{ $event['end_time'] }})"
                                            @if ($event['url']) onclick="window.location='{{ $event['url'] }}'" @endif
                                        >
                                            {{ $event['start_time'] }} {{ Str::limit($event['title'], 15) }}
                                        </div>
                                    @endforeach
                                    @if ($dayEvents->count() > 3)
                                        <div class="text-xs text-zinc-500 dark:text-zinc-400 px-1">+{{ $dayEvents->count() - 3 }} {{ __('more') }}</div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @elseif ($calendarMode === 'week')
                {{-- Week View --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">
                    {{-- Day columns header --}}
                    <div class="grid grid-cols-7 bg-zinc-50 dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700">
                        @foreach ($days as $day)
                            @php $dayCarbon = \Carbon\Carbon::parse($day); @endphp
                            <div class="px-2 py-2 text-center border-r border-zinc-200 dark:border-zinc-700 last:border-r-0">
                                <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ $dayCarbon->format('D') }}</div>
                                <div class="text-sm font-semibold {{ $day === $today ? 'text-blue-600' : 'text-zinc-700 dark:text-zinc-300' }}">{{ $dayCarbon->day }}</div>
                            </div>
                        @endforeach
                    </div>
                    {{-- Event rows --}}
                    <div class="grid grid-cols-7 min-h-[400px]">
                        @foreach ($days as $day)
                            @php $dayEvents = $eventsByDate->get($day, collect()); @endphp
                            <div class="p-1 border-r border-zinc-200 dark:border-zinc-700 last:border-r-0 space-y-1">
                                @foreach ($dayEvents as $event)
                                    <div
                                        class="text-xs p-1.5 rounded {{ $event['url'] ? 'cursor-pointer' : '' }}"
                                        style="background-color: {{ $event['color'] }}15; border-left: 3px solid {{ $event['color'] }};"
                                        @if ($event['url']) onclick="window.location='{{ $event['url'] }}'" @endif
                                    >
                                        <div class="font-medium truncate" style="color: {{ $event['color'] }};">{{ $event['start_time'] }} - {{ $event['end_time'] }}</div>
                                        <div class="truncate text-zinc-700 dark:text-zinc-300">{{ $event['title'] }}</div>
                                        <div class="text-zinc-500 dark:text-zinc-400 capitalize">{{ $event['source'] }}</div>
                                        @if ($event['source'] === 'territory' && ($event['meta']['meet_address'] ?? null))
                                            <div class="truncate text-zinc-500 text-[10px]">
                                                @if ($event['url'])
                                                    <a href="{{ $event['url'] }}" target="_blank" class="underline" onclick="event.stopPropagation()">{{ Str::limit($event['meta']['meet_address'], 25) }}</a>
                                                @else
                                                    {{ Str::limit($event['meta']['meet_address'], 25) }}
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                                @if ($dayEvents->isEmpty())
                                    <div class="flex items-center justify-center h-full text-xs text-zinc-400">-</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @else
                {{-- Day View --}}
                @php $dayEvents = collect($events)->sortBy('start'); @endphp
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">
                    @if ($dayEvents->isEmpty())
                        <div class="p-8 text-center">
                            <flux:icon name="calendar" class="w-8 h-8 mx-auto text-zinc-300 dark:text-zinc-600 mb-2" />
                            <flux:text class="text-zinc-500">{{ __('No events scheduled for this day.') }}</flux:text>
                        </div>
                    @else
                        <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach ($dayEvents as $event)
                                <div
                                    class="flex items-start gap-3 p-3 hover:bg-zinc-50 dark:hover:bg-zinc-800 transition-colors {{ $event['url'] ? 'cursor-pointer' : '' }}"
                                    @if ($event['url']) onclick="window.location='{{ $event['url'] }}'" @endif
                                >
                                    <div class="shrink-0 w-1 h-10 rounded-full mt-0.5" style="background-color: {{ $event['color'] }};"></div>
                                    <div class="shrink-0 text-right">
                                        <div class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ $event['start_time'] }}</div>
                                        <div class="text-xs text-zinc-400 dark:text-zinc-500">{{ $event['end_time'] }}</div>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100 truncate">{{ $event['title'] }}</div>
                                        <div class="flex items-center gap-2 mt-0.5">
                                            <span class="text-xs capitalize px-1.5 py-0.5 rounded-full" style="background-color: {{ $event['color'] }}15; color: {{ $event['color'] }};">
                                                {{ str_replace('_', ' ', $event['source']) }}
                                            </span>
                                            @if ($event['type'] !== $event['source'])
                                                <span class="text-xs text-zinc-500 capitalize">{{ str_replace('_', ' ', $event['type']) }}</span>
                                            @endif
                                            @if (! empty($event['meta']['is_completed']))
                                                <span class="text-xs text-green-600">{{ __('Completed') }}</span>
                                            @elseif (! empty($event['meta']['is_overdue']))
                                                <span class="text-xs text-red-600">{{ __('Overdue') }}</span>
                                            @endif
                                        </div>
                                        @if ($event['source'] === 'territory' && ($event['meta']['meet_address'] ?? null))
                                            <div class="mt-1 flex items-center gap-1 text-xs text-zinc-500">
                                                <flux:icon name="map-pin" class="w-3 h-3 shrink-0" />
                                                @if ($event['url'])
                                                    <a href="{{ $event['url'] }}" target="_blank" class="underline hover:text-zinc-700 dark:hover:text-zinc-300" onclick="event.stopPropagation()">{{ $event['meta']['meet_address'] }}</a>
                                                @else
                                                    {{ $event['meta']['meet_address'] }}
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif

            {{-- Color legend --}}
            <div class="flex flex-wrap items-center gap-4 mt-4 text-xs text-zinc-500 dark:text-zinc-400">
                <div class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-emerald-500"></span> {{ __('Tasks') }}</div>
                <div class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-purple-500"></span> {{ __('Meetings') }}</div>
                <div class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-amber-500"></span> {{ __('Calls') }}</div>
                <div class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-blue-500"></span> {{ __('Emails / Jobs') }}</div>
                <div class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-violet-500"></span> {{ __('Commitments') }}</div>
                <div class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-orange-500"></span> {{ __('Territory') }}</div>
                <div class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-red-500"></span> {{ __('Overdue') }}</div>
                <div class="flex items-center gap-1.5"><span class="w-2.5 h-2.5 rounded-full bg-gray-400"></span> {{ __('Completed') }}</div>
            </div>
        @elseif ($view === 'command_center')
            {{-- ===== LAYER 1: Smart Inbox ===== --}}
            @if ($this->smartInbox->isNotEmpty() && $statusFilter !== 'completed')
                <div class="mb-6">
                    <div class="flex items-center gap-2 mb-3">
                        <flux:icon name="bolt" class="w-4 h-4 text-amber-500" />
                        <flux:text class="font-semibold text-sm">{{ __('Needs Attention') }} ({{ $this->smartInbox->count() }})</flux:text>
                    </div>
                    <div class="border border-amber-200 dark:border-amber-800/50 rounded-lg divide-y divide-amber-100 dark:divide-amber-900/30 bg-amber-50/30 dark:bg-amber-950/10">
                        @foreach ($this->smartInbox as $item)
                            @include('components.planner._activity-row', ['activity' => $item, 'isInbox' => true])
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- ===== Unread Messages ===== --}}
            @if ($this->unreadThreads->isNotEmpty())
                <div class="mb-4">
                    <div class="flex items-center gap-2 mb-2">
                        <flux:icon name="envelope" class="w-4 h-4 text-blue-600" />
                        <flux:text class="font-medium text-sm">{{ __('Unread Messages') }} ({{ $this->unreadThreads->count() }})</flux:text>
                    </div>
                    <div class="border border-blue-200 dark:border-blue-800 rounded-lg divide-y divide-blue-100 dark:divide-blue-900 bg-blue-50/50 dark:bg-blue-950/20">
                        @foreach ($this->unreadThreads as $thread)
                            <div wire:key="thread-{{ $thread->id }}" onclick="window.location='{{ route('chat.show', $thread) }}'" class="flex items-start gap-3 p-3 hover:bg-blue-100/50 dark:hover:bg-blue-900/30 transition-colors cursor-pointer">
                                <div class="shrink-0 mt-0.5">
                                    <div class="w-8 h-8 rounded-full bg-blue-100 dark:bg-blue-900 flex items-center justify-center">
                                        <flux:icon name="chat-bubble-left-right" class="w-4 h-4 text-blue-600 dark:text-blue-400" />
                                    </div>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <flux:text class="font-medium text-sm">
                                        @php $sender = $thread->latestMessage?->user; @endphp
                                        @if ($sender?->isAgent())
                                            <a href="{{ route('agents.show', $sender) }}" wire:navigate class="text-blue-600 hover:underline dark:text-blue-400" onclick="event.stopPropagation()">{{ $sender->name }}</a>
                                        @elseif ($sender?->isCrew())
                                            <a href="{{ route('crews.show', $sender) }}" wire:navigate class="text-blue-600 hover:underline dark:text-blue-400" onclick="event.stopPropagation()">{{ $sender->name }}</a>
                                        @elseif ($sender?->isCustomer())
                                            @if ($sender->customerProfile)
                                                <a href="{{ route('customers.show', $sender->customerProfile) }}" wire:navigate class="text-blue-600 hover:underline dark:text-blue-400" onclick="event.stopPropagation()">{{ $sender->name }}</a>
                                            @else
                                                {{ $sender->name }}
                                            @endif
                                        @else
                                            {{ $sender?->name ?? 'Unknown' }}
                                        @endif
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
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- ===== LAYER 2: Activity Stream ===== --}}
            <div class="flex items-center gap-2 mb-3">
                <flux:icon name="queue-list" class="w-4 h-4 text-zinc-400" />
                <flux:text class="font-semibold text-sm">{{ __('Activity Stream') }}</flux:text>
            </div>
            @include('components.planner._activity-stream')

        @elseif ($view === 'stream')
            {{-- Pure stream view without inbox --}}
            @include('components.planner._activity-stream')

        @endif
    </div>

    {{-- ===== Context Panel Flyout ===== --}}
    <flux:modal wire:model="showContextPanel" variant="flyout" class="max-w-lg">
        @if ($this->contextData)
            <livewire:is component="planner.context-panel"
                :subject-type="$contextSubjectType"
                :subject-id="$contextSubjectId"
                wire:key="context-{{ $contextSubjectType }}-{{ $contextSubjectId }}"
            />
        @endif
    </flux:modal>

    {{-- ===== Snooze Modal ===== --}}
    <flux:modal wire:model="showSnoozeModal" class="max-w-xs">
        <flux:heading size="lg" class="mb-4">{{ __('Snooze Until') }}</flux:heading>
        <flux:field>
            <flux:input wire:model="snoozeUntil" type="datetime-local" />
        </flux:field>
        <div class="mt-4 flex justify-end gap-3">
            <flux:button variant="ghost" wire:click="$set('showSnoozeModal', false)" type="button">{{ __('Cancel') }}</flux:button>
            <flux:button variant="primary" wire:click="confirmSnooze">{{ __('Snooze') }}</flux:button>
        </div>
    </flux:modal>

    {{-- ===== Add Task Modal ===== --}}
    <flux:modal wire:model="showAddTask" variant="flyout" class="max-w-md">
        <form wire:submit="saveTask">
            <flux:heading size="lg" class="mb-4">{{ __('Add Task') }}</flux:heading>

            <div class="space-y-4">
                <flux:field>
                    <flux:label>{{ __('Title') }}</flux:label>
                    <flux:input wire:model="taskTitle" :placeholder="__('What needs to be done?')" />
                    <flux:error name="taskTitle" />
                </flux:field>

                <div class="grid grid-cols-2 gap-4">
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
                        <flux:label>{{ __('Priority') }}</flux:label>
                        <flux:select wire:model="taskPriority">
                            @foreach (\App\Models\Activity::PRIORITIES as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </flux:select>
                        <flux:error name="taskPriority" />
                    </flux:field>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>{{ __('Start Date/Time') }}</flux:label>
                        <flux:input wire:model="taskDueAt" type="datetime-local" />
                        <flux:error name="taskDueAt" />
                    </flux:field>
                    <flux:field>
                        <flux:label>{{ __('End Date/Time') }}</flux:label>
                        <flux:input wire:model="taskEndsAt" type="datetime-local" />
                        <flux:error name="taskEndsAt" />
                    </flux:field>
                </div>

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
                    <flux:label>{{ __('Link to Contact (Optional)') }}</flux:label>
                    <div x-data="{
                        open: false,
                        search: '',
                        get filtered() {
                            if (!this.search) return $wire.searchableContacts.slice(0, 20);
                            const s = this.search.toLowerCase();
                            return $wire.searchableContacts.filter(c => c.name.toLowerCase().includes(s)).slice(0, 20);
                        },
                        select(contact) {
                            $wire.set('taskSubjectType', 'App\\\\Models\\\\User');
                            $wire.set('taskSubjectId', contact.id);
                            this.open = false;
                            this.search = contact.name;
                        },
                        clear() {
                            $wire.set('taskSubjectType', null);
                            $wire.set('taskSubjectId', null);
                            this.search = '';
                        }
                    }">
                        <div class="relative">
                            <flux:input
                                x-model="search"
                                x-on:focus="open = true"
                                x-on:click="open = true"
                                :placeholder="__('Search contacts...')"
                                icon="user"
                            />
                            <template x-if="search">
                                <button x-on:click="clear()" type="button" class="absolute right-2 top-1/2 -translate-y-1/2 text-zinc-400 hover:text-zinc-600">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </template>
                        </div>
                        <div x-show="open" x-on:click.away="open = false" x-cloak class="absolute z-50 mt-1 w-full max-h-48 overflow-auto rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 shadow-lg">
                            <template x-for="contact in filtered" :key="contact.id">
                                <button
                                    type="button"
                                    x-on:click="select(contact)"
                                    class="flex w-full items-center gap-2 px-3 py-2 text-sm hover:bg-zinc-100 dark:hover:bg-zinc-700 text-left"
                                >
                                    <span x-text="contact.name" class="flex-1"></span>
                                    <flux:badge size="sm" x-text="contact.type" />
                                </button>
                            </template>
                            <template x-if="filtered.length === 0">
                                <div class="px-3 py-2 text-sm text-zinc-500">{{ __('No contacts found') }}</div>
                            </template>
                        </div>
                    </div>
                </flux:field>

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
