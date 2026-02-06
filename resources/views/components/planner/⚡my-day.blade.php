<?php

use App\Models\Activity;
use App\Models\User;
use App\Notifications\TaskAssigned;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
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

        $activity = Activity::create([
            'user_id' => auth()->id(),
            'assigned_to' => $this->taskAssignedTo ?: auth()->id(),
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
        session()->flash('success', 'Task created successfully.');
        $this->closeAddTask();
    }

    #[Computed]
    public function activities()
    {
        return $this->getActivitiesQuery()->paginate(20);
    }

    protected function getActivitiesQuery()
    {
        $query = Activity::query()
            ->with(['user', 'assignee', 'subject'])
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
        switch ($this->view) {
            case 'my_day':
                $query->assignedTo(auth()->user())
                    ->where(function ($q) {
                        $q->whereDate('due_at', today())
                            ->orWhere(function ($q2) {
                                $q2->where('due_at', '<', today())
                                    ->where('is_completed', false);
                            });
                    });
                break;

            case 'my_week':
                $query->assignedTo(auth()->user())
                    ->whereBetween('due_at', [now()->startOfWeek(), now()->endOfWeek()]);
                break;

            case 'all_mine':
                $query->assignedTo(auth()->user());
                break;

            case 'created_by_me':
                $query->forUser(auth()->user());
                break;

            case 'team':
                // Show all activities for the team (internal users only)
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
            <flux:heading size="xl">Planner</flux:heading>
            <flux:button icon="plus" wire:click="openAddTask">
                Add Task
            </flux:button>
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
                My Day
            </flux:button>
            <flux:button
                size="sm"
                :variant="$view === 'my_week' ? 'primary' : 'ghost'"
                wire:click="$set('view', 'my_week')"
            >
                My Week
            </flux:button>
            <flux:button
                size="sm"
                :variant="$view === 'all_mine' ? 'primary' : 'ghost'"
                wire:click="$set('view', 'all_mine')"
            >
                All Assigned to Me
            </flux:button>
            <flux:button
                size="sm"
                :variant="$view === 'created_by_me' ? 'primary' : 'ghost'"
                wire:click="$set('view', 'created_by_me')"
            >
                Created by Me
            </flux:button>
            <flux:button
                size="sm"
                :variant="$view === 'team' ? 'primary' : 'ghost'"
                wire:click="$set('view', 'team')"
            >
                Team View
            </flux:button>
        </div>

        {{-- Filters --}}
        <div class="flex flex-wrap items-center gap-4 mb-6">
            <flux:select wire:model.live="typeFilter" class="w-40">
                <option value="all">All Types</option>
                @foreach ($this->activityTypes as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </flux:select>

            <flux:select wire:model.live="statusFilter" class="w-40">
                <option value="all">All Status</option>
                <option value="incomplete">Incomplete</option>
                <option value="completed">Completed</option>
                <option value="overdue">Overdue</option>
            </flux:select>
        </div>

        {{-- Activities List --}}
        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg divide-y divide-zinc-200 dark:divide-zinc-700">
            @forelse ($this->activities as $activity)
                <div
                    wire:key="activity-{{ $activity->id }}"
                    class="p-4 flex items-start gap-4 {{ $activity->is_completed ? 'bg-zinc-50 dark:bg-zinc-900/50' : '' }}"
                >
                    {{-- Checkbox for tasks --}}
                    <div class="shrink-0 pt-0.5">
                        @if ($activity->type === 'task')
                            <button
                                wire:click="toggleComplete({{ $activity->id }})"
                                class="w-5 h-5 rounded border-2 flex items-center justify-center transition-colors {{ $activity->is_completed ? 'bg-green-500 border-green-500 text-white' : 'border-zinc-300 dark:border-zinc-600 hover:border-green-500' }}"
                            >
                                @if ($activity->is_completed)
                                    <flux:icon name="check" class="w-3 h-3" />
                                @endif
                            </button>
                        @else
                            @php
                                $typeIcons = [
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
                        @endif
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

                        {{-- Meta info --}}
                        <div class="flex flex-wrap items-center gap-3 mt-2">
                            @if ($activity->due_at)
                                <div class="flex items-center gap-1 text-xs {{ $activity->isOverdue() ? 'text-red-500' : 'text-zinc-500' }}">
                                    <flux:icon name="calendar" class="w-3 h-3" />
                                    <span>{{ $activity->due_at->format('M j') }}</span>
                                    @if ($activity->isOverdue())
                                        <span class="font-medium">(Overdue)</span>
                                    @endif
                                </div>
                            @endif

                            @if ($activity->assignee)
                                <div class="flex items-center gap-1 text-xs text-zinc-500">
                                    <flux:icon name="user" class="w-3 h-3" />
                                    <span>{{ $activity->assignee->name }}</span>
                                </div>
                            @endif

                            @if ($activity->subject)
                                <div class="flex items-center gap-1 text-xs text-zinc-500">
                                    <flux:icon name="link" class="w-3 h-3" />
                                    @if ($activity->subject_type === \App\Models\Customer::class)
                                        <a href="{{ route('customers.show', $activity->subject_id) }}" wire:navigate class="hover:text-zinc-700 dark:hover:text-zinc-300">
                                            {{ $activity->subject->full_name ?? 'Customer' }}
                                        </a>
                                    @elseif ($activity->subject_type === \App\Models\Deal::class)
                                        <a href="{{ route('deals.show', $activity->subject_id) }}" wire:navigate class="hover:text-zinc-700 dark:hover:text-zinc-300">
                                            {{ $activity->subject->title ?? 'Deal' }}
                                        </a>
                                    @endif
                                </div>
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
                    <flux:heading size="sm" class="text-zinc-500">No tasks found</flux:heading>
                    <flux:text size="sm" class="text-zinc-400 mt-1">
                        @switch($view)
                            @case('my_day')
                                You're all caught up for today!
                                @break
                            @case('my_week')
                                No tasks scheduled for this week.
                                @break
                            @default
                                No tasks match your filters.
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
    </div>

    {{-- Add Task Modal --}}
    <flux:modal wire:model="showAddTask" variant="flyout" class="max-w-md">
        <form wire:submit="saveTask">
            <flux:heading size="lg" class="mb-4">Add Task</flux:heading>

            <div class="space-y-4">
                <flux:field>
                    <flux:label>Title</flux:label>
                    <flux:input wire:model="taskTitle" placeholder="What needs to be done?" />
                    <flux:error name="taskTitle" />
                </flux:field>

                <flux:field>
                    <flux:label>Type</flux:label>
                    <flux:select wire:model="taskType">
                        @foreach ($this->activityTypes as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </flux:select>
                    <flux:error name="taskType" />
                </flux:field>

                <flux:field>
                    <flux:label>Due Date</flux:label>
                    <flux:input wire:model="taskDueAt" type="date" />
                    <flux:error name="taskDueAt" />
                </flux:field>

                <flux:field>
                    <flux:label>Assign To</flux:label>
                    <flux:select wire:model="taskAssignedTo">
                        <option value="">Myself</option>
                        @foreach ($this->teamMembers as $member)
                            @if ($member->id !== auth()->id())
                                <option value="{{ $member->id }}">{{ $member->name }}</option>
                            @endif
                        @endforeach
                    </flux:select>
                    <flux:error name="taskAssignedTo" />
                </flux:field>

                <flux:field>
                    <flux:label>Description (Optional)</flux:label>
                    <flux:textarea wire:model="taskDescription" rows="3" placeholder="Add details..." />
                    <flux:error name="taskDescription" />
                </flux:field>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <flux:button variant="ghost" wire:click="closeAddTask" type="button">Cancel</flux:button>
                <flux:button type="submit" variant="primary">Add Task</flux:button>
            </div>
        </form>
    </flux:modal>
</div>
