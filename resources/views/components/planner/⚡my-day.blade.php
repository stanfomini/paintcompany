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

    // Quick add form
    public bool $showAddTask = false;

    public string $taskTitle = '';

    public string $taskType = 'task';

    public string $taskPriority = 'normal';

    public ?string $taskDueAt = null;

    public string $taskDescription = '';

    public ?int $taskAssignedTo = null;

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
    public function activityCalendarUrl(): string
    {
        $token = auth()->user()->getOrCreateCalendarToken();

        return route('calendar.activities', ['token' => $token]);
    }

    #[On('activity-created')]
    #[On('activity-updated')]
    public function refreshList(): void
    {
        unset($this->smartInbox);
        unset($this->activities);
        unset($this->unreadThreads);
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

    public function toggleComplete(int $activityId): void
    {
        $activity = Activity::query()
            ->forUserOrAssignedTo(auth()->user())
            ->findOrFail($activityId);

        if ($activity->is_completed) {
            $activity->markAsIncomplete();
        } else {
            $activity->markAsCompleted();
            app(PriorityScoreService::class)->updateScore($activity->fresh());
        }
    }

    public function setPriority(int $activityId, string $priority): void
    {
        $activity = Activity::query()
            ->forUserOrAssignedTo(auth()->user())
            ->findOrFail($activityId);

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
        $activity = Activity::query()
            ->forUserOrAssignedTo(auth()->user())
            ->findOrFail($activityId);

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

        $activity = Activity::query()
            ->forUserOrAssignedTo(auth()->user())
            ->findOrFail($this->snoozeActivityId);

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
        $this->taskDueAt = today()->format('Y-m-d');
        $this->showAddTask = true;
    }

    public function closeAddTask(): void
    {
        $this->showAddTask = false;
        $this->reset(['taskTitle', 'taskType', 'taskPriority', 'taskDueAt', 'taskDescription', 'taskAssignedTo']);
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
            'taskDescription' => ['nullable', 'string', 'max:5000'],
            'taskAssignedTo' => ['nullable', 'exists:users,id'],
        ]);

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
            'priority' => $this->taskPriority,
            'is_completed' => false,
        ]);

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

        if (in_array($newStage, Deal::KANBAN_STAGES)) {
            $activity->markAsCompleted();
        }
    }

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
        $activity = Activity::query()
            ->forUserOrAssignedTo(auth()->user())
            ->where('subject_type', Job::class)
            ->findOrFail($activityId);

        $this->redirect(route('jobs.show', $activity->subject_id), navigate: true);
    }

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

    public function goToDispatch(int $activityId): void
    {
        $activity = Activity::query()
            ->forUserOrAssignedTo(auth()->user())
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
            <div x-data="calendarView" class="mt-4">
                <div x-ref="calendar"></div>
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
