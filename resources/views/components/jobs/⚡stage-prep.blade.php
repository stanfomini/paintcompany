<?php

use App\Models\Job;
use App\Models\JobPrepDay;
use App\Models\JobPrepPlan;
use App\Models\JobPrepTask;
use App\Services\JobPrepService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public Job $job;

    public int $crewSize = 2;

    public float $hoursPerDay = 8;

    public int $activeDay = 1;

    public string $customTaskTitle = '';

    public ?string $customTaskDescription = null;

    public ?float $customTaskHours = null;

    public bool $showCustomTaskForm = false;

    /** @var array<int, int> Task ID => Day number for surface picker */
    public array $taskDayAssignments = [];

    public function mount(Job $job): void
    {
        $this->job = $job;

        $plan = $this->job->prepPlan;
        if ($plan) {
            $this->crewSize = $plan->crew_size;
            $this->hoursPerDay = (float) $plan->hours_per_day;
        } else {
            $this->crewSize = $this->job->required_crew_size ?? 2;
        }
    }

    #[Computed]
    public function plan(): ?JobPrepPlan
    {
        return $this->job->prepPlan?->load(['days.tasks.completedByUser']);
    }

    #[Computed]
    public function days(): \Illuminate\Database\Eloquent\Collection
    {
        return $this->plan?->days ?? collect();
    }

    #[Computed]
    public function progress(): array
    {
        if (! $this->plan) {
            return ['total' => 0, 'completed' => 0, 'percent' => 0, 'per_day' => []];
        }

        return app(JobPrepService::class)->getProgress($this->plan);
    }

    #[Computed]
    public function totalEstimatedHours(): float
    {
        if (! $this->plan) {
            return 0;
        }

        return $this->plan->total_labor_hours + $this->plan->total_cleanup_hours;
    }

    #[Computed]
    public function calculatedDays(): int
    {
        $totalHours = $this->totalEstimatedHours;

        if ($totalHours <= 0 || $this->crewSize <= 0 || $this->hoursPerDay <= 0) {
            return 1;
        }

        return max(1, (int) ceil($totalHours / $this->crewSize / $this->hoursPerDay));
    }

    public function createPlan(): void
    {
        $service = app(JobPrepService::class);

        $plan = $service->createPlan($this->job, $this->crewSize, $this->hoursPerDay);
        $service->generateTasksFromEstimate($plan);

        unset($this->plan, $this->days, $this->progress, $this->totalEstimatedHours);
        $this->job->refresh();
        $this->dispatch('job-updated');
    }

    public function updateCrewSettings(): void
    {
        $plan = $this->plan;
        if (! $plan) {
            return;
        }

        $plan->update([
            'crew_size' => $this->crewSize,
            'hours_per_day' => $this->hoursPerDay,
        ]);

        app(JobPrepService::class)->recalculateDays($plan);

        unset($this->plan, $this->days, $this->progress, $this->calculatedDays);
    }

    public function setActiveDay(int $dayNumber): void
    {
        $this->activeDay = $dayNumber;
    }

    public function moveTaskToDay(int $taskId, int $dayId): void
    {
        $task = JobPrepTask::findOrFail($taskId);
        $day = JobPrepDay::findOrFail($dayId);

        app(JobPrepService::class)->moveTaskToDay($task, $day);

        unset($this->plan, $this->days, $this->progress);
    }

    public function addCustomTask(): void
    {
        if (empty(trim($this->customTaskTitle))) {
            return;
        }

        $day = $this->plan?->days()->where('day_number', $this->activeDay)->first();
        if (! $day) {
            return;
        }

        app(JobPrepService::class)->addCustomTask(
            $day,
            $this->customTaskTitle,
            $this->customTaskDescription,
            $this->customTaskHours
        );

        $this->reset('customTaskTitle', 'customTaskDescription', 'customTaskHours', 'showCustomTaskForm');
        unset($this->plan, $this->days, $this->progress);
    }

    public function removeTask(int $taskId): void
    {
        $task = JobPrepTask::findOrFail($taskId);
        app(JobPrepService::class)->removeTask($task);

        unset($this->plan, $this->days, $this->progress);
    }

    public function autoDistribute(): void
    {
        if (! $this->plan) {
            return;
        }

        app(JobPrepService::class)->autoDistributeTasks($this->plan);

        unset($this->plan, $this->days, $this->progress);
    }

    public function approvePlan(string $party): void
    {
        if (! $this->plan) {
            return;
        }

        app(JobPrepService::class)->approvePlan($this->plan, auth()->user(), $party);

        unset($this->plan);
        $this->dispatch('job-updated');
    }

    public function startJob(): void
    {
        if (! $this->plan?->isFullyApproved()) {
            return;
        }

        app(JobPrepService::class)->startJob($this->job);

        $this->job->refresh();
        $this->dispatch('job-updated');
    }
}; ?>

<div>
    <flux:heading size="sm" class="mb-4">Stage 3: Job Prep</flux:heading>

    {{-- Crew / Days Calculator Card --}}
    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 mb-6">
        <div class="flex items-center justify-between mb-4">
            <flux:heading size="sm">Crew & Schedule</flux:heading>
            @if ($this->plan && $this->plan->days->count() > 1)
                <flux:button wire:click="autoDistribute" size="xs" variant="ghost" icon="arrows-right-left">
                    Auto-Distribute
                </flux:button>
            @endif
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            {{-- Crew Size --}}
            <div>
                <flux:text class="text-sm font-medium text-zinc-500 mb-2">Crew Size</flux:text>
                <div class="flex items-center gap-2">
                    @foreach (range(1, 4) as $size)
                        <button
                            wire:click="$set('crewSize', {{ $size }})"
                            class="flex items-center justify-center w-12 h-12 rounded-lg border-2 transition-colors
                                {{ $crewSize === $size ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20 text-blue-600' : 'border-zinc-200 dark:border-zinc-700 hover:border-zinc-300' }}"
                        >
                            <div class="text-center">
                                <flux:icon name="user" class="w-5 h-5 mx-auto" />
                                <span class="text-xs font-bold">{{ $size }}</span>
                            </div>
                        </button>
                    @endforeach
                </div>
            </div>

            {{-- Hours Per Day --}}
            <div>
                <flux:text class="text-sm font-medium text-zinc-500 mb-2">Hours / Day</flux:text>
                <flux:select wire:model.live="hoursPerDay">
                    <option value="6">6 hours</option>
                    <option value="8">8 hours</option>
                    <option value="10">10 hours</option>
                </flux:select>
            </div>

            {{-- Summary --}}
            <div>
                <flux:text class="text-sm font-medium text-zinc-500 mb-2">Summary</flux:text>
                <div class="p-3 bg-zinc-50 dark:bg-zinc-800 rounded-lg">
                    <flux:text class="text-lg font-semibold">
                        {{ $crewSize }} {{ Str::plural('painter', $crewSize) }} &times; {{ number_format($hoursPerDay) }}hr days
                    </flux:text>
                    @if ($this->plan)
                        <flux:text class="text-sm text-zinc-500">
                            = {{ $this->plan->total_days }} {{ Str::plural('day', $this->plan->total_days) }} | {{ number_format($this->totalEstimatedHours, 1) }} total hours
                        </flux:text>
                    @endif
                </div>
            </div>
        </div>

        @if (! $this->plan)
            <div class="mt-4">
                <flux:button wire:click="createPlan" variant="primary" icon="clipboard-document-list">
                    Generate Plan
                </flux:button>
            </div>
        @else
            <div class="mt-4">
                <flux:button wire:click="updateCrewSettings" size="sm" variant="ghost" icon="arrow-path">
                    Recalculate Days
                </flux:button>
            </div>
        @endif
    </div>

    @if ($this->plan)
        {{-- Day Tabs with Task Lists --}}
        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg mb-6">
            {{-- Tab Bar --}}
            <div class="flex items-center border-b border-zinc-200 dark:border-zinc-700 overflow-x-auto">
                @foreach ($this->days as $day)
                    @php
                        $dayProgress = collect($this->progress['per_day'])->firstWhere('day_number', $day->day_number);
                        $dayHours = $dayProgress['hours'] ?? 0;
                        $dayCompleted = $dayProgress['completed'] ?? 0;
                        $dayTotal = $dayProgress['total'] ?? 0;
                    @endphp
                    <button
                        wire:click="setActiveDay({{ $day->day_number }})"
                        wire:key="day-tab-{{ $day->id }}"
                        class="flex items-center gap-2 px-4 py-3 text-sm font-medium border-b-2 whitespace-nowrap transition-colors
                            {{ $activeDay === $day->day_number
                                ? 'border-blue-500 text-blue-600 dark:text-blue-400'
                                : 'border-transparent text-zinc-500 hover:text-zinc-700 hover:border-zinc-300' }}"
                    >
                        Day {{ $day->day_number }}
                        @if ($dayTotal > 0)
                            <flux:badge size="sm" :color="$dayCompleted === $dayTotal ? 'green' : 'zinc'">
                                {{ number_format($dayHours, 1) }}h
                            </flux:badge>
                        @endif
                    </button>
                @endforeach
            </div>

            {{-- Active Day Content --}}
            @foreach ($this->days as $day)
                <div
                    wire:key="day-content-{{ $day->id }}"
                    class="{{ $activeDay === $day->day_number ? '' : 'hidden' }}"
                >
                    <div class="p-4">
                        @if ($day->tasks->isEmpty())
                            <div class="text-center py-8">
                                <flux:icon name="clipboard" class="w-12 h-12 mx-auto text-zinc-300 mb-2" />
                                <flux:text class="text-zinc-400">No tasks assigned to this day yet.</flux:text>
                                <flux:text class="text-xs text-zinc-400">Use the day dropdown on tasks to assign them here, or add custom tasks.</flux:text>
                            </div>
                        @else
                            <div class="space-y-2">
                                @foreach ($day->tasks as $task)
                                    <div
                                        wire:key="task-{{ $task->id }}"
                                        class="flex items-center gap-3 p-3 rounded-lg border {{ match($task->task_type) {
                                            'surface' => 'border-blue-200 dark:border-blue-800 bg-blue-50/50 dark:bg-blue-900/10',
                                            'addon' => 'border-amber-200 dark:border-amber-800 bg-amber-50/50 dark:bg-amber-900/10',
                                            default => 'border-zinc-200 dark:border-zinc-700',
                                        } }}"
                                    >
                                        {{-- Type indicator --}}
                                        <div class="shrink-0">
                                            @if ($task->task_type === 'surface')
                                                <div class="w-2 h-8 rounded-full bg-blue-500"></div>
                                            @elseif ($task->task_type === 'addon')
                                                <div class="w-2 h-8 rounded-full bg-amber-500"></div>
                                            @else
                                                <div class="w-2 h-8 rounded-full bg-zinc-400"></div>
                                            @endif
                                        </div>

                                        {{-- Task Info --}}
                                        <div class="flex-1 min-w-0">
                                            <flux:text class="text-sm font-medium truncate">{{ $task->title }}</flux:text>
                                            @if ($task->area_name)
                                                <flux:text class="text-xs text-zinc-400">{{ $task->area_name }}</flux:text>
                                            @endif
                                        </div>

                                        {{-- Badges --}}
                                        <div class="flex items-center gap-2 shrink-0">
                                            @if ($task->quantity && $task->unit)
                                                <flux:badge size="sm" color="zinc">
                                                    {{ number_format($task->quantity) }} {{ $task->unit }}
                                                </flux:badge>
                                            @endif
                                            @if ($task->estimated_hours)
                                                <flux:badge size="sm" color="blue">
                                                    {{ number_format($task->estimated_hours, 1) }}h
                                                </flux:badge>
                                            @endif
                                        </div>

                                        {{-- Day Assignment Dropdown --}}
                                        @if ($this->days->count() > 1)
                                            <div class="shrink-0">
                                                <select
                                                    wire:change="moveTaskToDay({{ $task->id }}, $event.target.value)"
                                                    class="text-xs border-zinc-200 dark:border-zinc-700 rounded bg-white dark:bg-zinc-800 px-2 py-1"
                                                >
                                                    @foreach ($this->days as $targetDay)
                                                        <option
                                                            value="{{ $targetDay->id }}"
                                                            {{ $task->job_prep_day_id === $targetDay->id ? 'selected' : '' }}
                                                        >
                                                            Day {{ $targetDay->day_number }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        @endif

                                        {{-- Delete (custom only) --}}
                                        @if ($task->isCustom())
                                            <button
                                                wire:click="removeTask({{ $task->id }})"
                                                wire:confirm="Remove this custom task?"
                                                class="text-red-400 hover:text-red-600 shrink-0"
                                            >
                                                <flux:icon name="trash" class="w-4 h-4" />
                                            </button>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        {{-- Add Custom Task --}}
                        <div class="mt-4">
                            @if ($showCustomTaskForm)
                                <div class="p-4 border border-zinc-200 dark:border-zinc-700 rounded-lg space-y-3">
                                    <flux:input
                                        wire:model="customTaskTitle"
                                        label="Task Title"
                                        placeholder="e.g. Move furniture, Tape trim"
                                    />
                                    <flux:textarea
                                        wire:model="customTaskDescription"
                                        label="Description (optional)"
                                        rows="2"
                                    />
                                    <flux:input
                                        wire:model="customTaskHours"
                                        label="Estimated Hours (optional)"
                                        type="number"
                                        step="0.5"
                                        min="0"
                                    />
                                    <div class="flex items-center gap-2">
                                        <flux:button wire:click="addCustomTask" size="sm" variant="primary">
                                            Add Task
                                        </flux:button>
                                        <flux:button wire:click="$set('showCustomTaskForm', false)" size="sm" variant="ghost">
                                            Cancel
                                        </flux:button>
                                    </div>
                                </div>
                            @else
                                <flux:button wire:click="$set('showCustomTaskForm', true)" size="sm" variant="ghost" icon="plus">
                                    Add Custom Task
                                </flux:button>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Tri-Party Approval Section --}}
        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
            <flux:heading size="sm" class="mb-4">Plan Approval</flux:heading>
            <flux:text class="text-sm text-zinc-500 mb-4">
                All three parties must approve the plan before the job can start.
            </flux:text>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                {{-- Team Approval --}}
                <div class="p-4 border border-zinc-200 dark:border-zinc-700 rounded-lg">
                    <div class="flex items-center justify-between mb-2">
                        <flux:text class="text-sm font-medium">Team</flux:text>
                        @if ($this->plan->team_approved)
                            <flux:badge size="sm" color="green">Approved</flux:badge>
                        @else
                            <flux:badge size="sm" color="zinc">Pending</flux:badge>
                        @endif
                    </div>
                    @if ($this->plan->team_approved)
                        <flux:text class="text-xs text-zinc-400">
                            {{ $this->plan->teamApprover?->name }} &middot; {{ $this->plan->team_approved_at?->diffForHumans() }}
                        </flux:text>
                    @elseif (auth()->user()?->isInternal())
                        <flux:button wire:click="approvePlan('team')" size="sm" variant="primary" class="mt-2 w-full">
                            Approve as Team
                        </flux:button>
                    @endif
                </div>

                {{-- Crew Approval --}}
                <div class="p-4 border border-zinc-200 dark:border-zinc-700 rounded-lg">
                    <div class="flex items-center justify-between mb-2">
                        <flux:text class="text-sm font-medium">Crew</flux:text>
                        @if ($this->plan->crew_approved)
                            <flux:badge size="sm" color="green">Approved</flux:badge>
                        @else
                            <flux:badge size="sm" color="zinc">Pending</flux:badge>
                        @endif
                    </div>
                    @if ($this->plan->crew_approved)
                        <flux:text class="text-xs text-zinc-400">
                            {{ $this->plan->crewApprover?->name }} &middot; {{ $this->plan->crew_approved_at?->diffForHumans() }}
                        </flux:text>
                    @endif
                </div>

                {{-- Customer Approval --}}
                <div class="p-4 border border-zinc-200 dark:border-zinc-700 rounded-lg">
                    <div class="flex items-center justify-between mb-2">
                        <flux:text class="text-sm font-medium">Customer</flux:text>
                        @if ($this->plan->customer_approved)
                            <flux:badge size="sm" color="green">Approved</flux:badge>
                        @else
                            <flux:badge size="sm" color="zinc">Pending</flux:badge>
                        @endif
                    </div>
                    @if ($this->plan->customer_approved)
                        <flux:text class="text-xs text-zinc-400">
                            {{ $this->plan->customerApprover?->name }} &middot; {{ $this->plan->customer_approved_at?->diffForHumans() }}
                        </flux:text>
                    @endif
                </div>
            </div>

            {{-- Start Job Button --}}
            @if ($this->plan->isFullyApproved())
                <div class="text-center">
                    <flux:button
                        wire:click="startJob"
                        wire:confirm="Start this job? This will advance the stage to In Progress."
                        variant="primary"
                        icon="play"
                        size="base"
                    >
                        Start Job
                    </flux:button>
                </div>
            @endif
        </div>
    @endif
</div>
