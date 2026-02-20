<?php

use App\Models\Job;
use App\Models\JobPrepApproval;
use App\Models\JobPrepDay;
use App\Models\JobPrepPlan;
use App\Models\JobPrepTask;
use App\Models\ServiceCategory;
use App\Services\JobPrepService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public Job $job;

    /** @var array<int, array{crew_size: int, hours_per_day: float}> Keyed by service_category_id */
    public array $serviceConfigs = [];

    public int $activeDay = 1;

    public ?int $serviceFilter = null;

    public string $customTaskTitle = '';

    public ?string $customTaskDescription = null;

    public ?int $customTaskServiceCategoryId = null;

    public bool $showCustomTaskForm = false;

    public function mount(Job $job): void
    {
        $this->job = $job;

        $plan = $this->job->prepPlan;

        if ($plan) {
            // Load from existing service settings
            $settings = $plan->serviceSettings()->with('serviceCategory')->get();

            if ($settings->isNotEmpty()) {
                foreach ($settings as $setting) {
                    $this->serviceConfigs[$setting->service_category_id] = [
                        'crew_size' => $setting->crew_size,
                        'hours_per_day' => $setting->hours_per_day,
                    ];
                }
            } else {
                // Legacy plan without service settings - use plan-level values
                $this->initDefaultConfigs($plan->crew_size, (float) $plan->hours_per_day);
            }
        } else {
            $this->initDefaultConfigs($this->job->required_crew_size ?? 2, 8);
        }
    }

    #[On('echo-private:job.{job.id},.prep.plan.updated')]
    #[On('echo-private:job.{job.id},.prep.approval.changed')]
    public function refreshFromEcho(): void
    {
        unset($this->plan, $this->days, $this->progress, $this->totalEstimatedHours, $this->serviceSettings, $this->approvals);
        $this->job->refresh();
    }

    #[Computed]
    public function plan(): ?JobPrepPlan
    {
        return $this->job->prepPlan?->load(['days.tasks.completedByUser', 'days.tasks.serviceCategory', 'days.tasks.removalRequestedByUser', 'days.tasks.addedByUser', 'approvals.approver', 'approvals.serviceCategory', 'serviceSettings.serviceCategory']);
    }

    #[Computed]
    public function days(): \Illuminate\Support\Collection
    {
        return $this->plan?->days ?? collect();
    }

    #[Computed]
    public function serviceCategories(): \Illuminate\Database\Eloquent\Collection
    {
        return ServiceCategory::active()->ordered()->get();
    }

    #[Computed]
    public function serviceSettings(): \Illuminate\Support\Collection
    {
        return $this->plan?->serviceSettings()->with('serviceCategory')->get() ?? collect();
    }

    #[Computed]
    public function approvals(): \Illuminate\Support\Collection
    {
        return $this->plan?->approvals()->with(['approver', 'serviceCategory'])->get() ?? collect();
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

    public function createPlan(): void
    {
        $configs = [];
        foreach ($this->serviceConfigs as $categoryId => $config) {
            $configs[] = [
                'service_category_id' => (int) $categoryId,
                'crew_size' => max(1, (int) $config['crew_size']),
                'hours_per_day' => max(1, (float) $config['hours_per_day']),
            ];
        }

        $service = app(JobPrepService::class);

        $plan = $service->createPlan($this->job, serviceConfigs: $configs);
        $service->generateTasksFromEstimate($plan);

        unset($this->plan, $this->days, $this->progress, $this->totalEstimatedHours, $this->serviceSettings, $this->approvals);
        $this->job->refresh();
        $this->dispatch('job-updated');
    }

    public function updateServiceConfig(int $serviceCategoryId, string $field, mixed $value): void
    {
        if (! isset($this->serviceConfigs[$serviceCategoryId])) {
            return;
        }

        $this->serviceConfigs[$serviceCategoryId][$field] = $field === 'crew_size'
            ? max(1, (int) $value)
            : max(1, (float) $value);

        $plan = $this->plan;
        if (! $plan) {
            return;
        }

        app(JobPrepService::class)->proposeChange($plan, $serviceCategoryId, auth()->user(), [
            $field => $this->serviceConfigs[$serviceCategoryId][$field],
        ]);

        unset($this->plan, $this->days, $this->progress, $this->serviceSettings, $this->approvals);
    }

    public function setActiveDay(int $dayNumber): void
    {
        $this->activeDay = $dayNumber;
    }

    public function setServiceFilter(?int $categoryId): void
    {
        $this->serviceFilter = $categoryId;
    }

    public function moveTaskToDay(int $taskId, int $dayId): void
    {
        $task = JobPrepTask::findOrFail($taskId);
        $day = JobPrepDay::findOrFail($dayId);

        app(JobPrepService::class)->moveTaskToDay($task, $day);

        unset($this->plan, $this->days, $this->progress, $this->approvals);
    }

    public function addDay(): void
    {
        if (! $this->plan) {
            return;
        }

        $newDay = app(JobPrepService::class)->addDay($this->plan);
        unset($this->plan, $this->days, $this->progress, $this->totalEstimatedHours, $this->approvals);
        $this->activeDay = $newDay->day_number;
    }

    public function deleteDay(int $dayId): void
    {
        $day = JobPrepDay::findOrFail($dayId);
        $result = app(JobPrepService::class)->deleteDay($day);

        if ($result) {
            unset($this->plan, $this->days, $this->progress, $this->totalEstimatedHours, $this->approvals);
            if ($this->activeDay > ($this->days->count() ?: 1)) {
                $this->activeDay = 1;
            }
        }
    }

    public function requestTaskRemoval(int $taskId): void
    {
        $task = JobPrepTask::findOrFail($taskId);
        app(JobPrepService::class)->requestTaskRemoval($task, auth()->user());
        unset($this->plan, $this->days, $this->progress, $this->approvals);
    }

    public function approveTaskRemoval(int $taskId): void
    {
        $task = JobPrepTask::findOrFail($taskId);
        app(JobPrepService::class)->approveTaskRemoval($task);
        unset($this->plan, $this->days, $this->progress, $this->approvals);
    }

    public function dismissTaskRemoval(int $taskId): void
    {
        $task = JobPrepTask::findOrFail($taskId);
        app(JobPrepService::class)->dismissTaskRemoval($task);
        unset($this->plan, $this->days, $this->progress, $this->approvals);
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
            $this->customTaskServiceCategoryId,
            $this->customTaskDescription,
            auth()->user()
        );

        $this->reset('customTaskTitle', 'customTaskDescription', 'customTaskServiceCategoryId', 'showCustomTaskForm');
        unset($this->plan, $this->days, $this->progress, $this->approvals);
    }

    public function removeTask(int $taskId): void
    {
        $task = JobPrepTask::findOrFail($taskId);
        app(JobPrepService::class)->removeTask($task);

        unset($this->plan, $this->days, $this->progress, $this->approvals);
    }

    public function autoDistribute(): void
    {
        if (! $this->plan) {
            return;
        }

        app(JobPrepService::class)->autoDistributeTasks($this->plan);

        unset($this->plan, $this->days, $this->progress, $this->approvals);
    }

    public function approveSection(string $party, int $serviceCategoryId): void
    {
        if (! $this->plan) {
            return;
        }

        app(JobPrepService::class)->approveSection($this->plan, auth()->user(), $party, $serviceCategoryId);

        unset($this->plan, $this->approvals);
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

    /**
     * Filter tasks by the current service filter.
     *
     * @return \Illuminate\Support\Collection<int, JobPrepTask>
     */
    public function filteredTasks(\Illuminate\Database\Eloquent\Collection $tasks): \Illuminate\Support\Collection
    {
        if ($this->serviceFilter === null) {
            return $tasks;
        }

        return $tasks->where('service_category_id', $this->serviceFilter);
    }

    private function initDefaultConfigs(int $crewSize, float $hoursPerDay): void
    {
        $categories = ServiceCategory::active()->ordered()->get();

        if ($categories->isEmpty()) {
            return;
        }

        foreach ($categories as $category) {
            $this->serviceConfigs[$category->id] = [
                'crew_size' => $crewSize,
                'hours_per_day' => $hoursPerDay,
            ];
        }
    }
}; ?>

<div>
    <flux:heading size="sm" class="mb-4">{{ __('Stage 3: Job Prep') }}</flux:heading>

    {{-- Per-Service Crew & Schedule Settings --}}
    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 mb-6">
        <div class="flex items-center justify-between mb-4">
            <flux:heading size="sm">{{ __('Crew & Schedule') }}</flux:heading>
            @if ($this->plan && $this->plan->days->count() > 1)
                <flux:button wire:click="autoDistribute" size="xs" variant="ghost" icon="arrows-right-left">
                    {{ __('Auto-Distribute') }}
                </flux:button>
            @endif
        </div>

        @if (count($serviceConfigs) === 0)
            <div class="text-center py-6">
                <flux:icon name="cog-6-tooth" class="w-10 h-10 mx-auto text-zinc-300 mb-2" />
                <flux:text class="text-zinc-400">{{ __('No service categories configured yet.') }}</flux:text>
                <flux:text class="text-xs text-zinc-400 mt-1">{{ __('Add service categories in Settings first.') }}</flux:text>
            </div>
        @else
            <div class="space-y-4">
                @foreach ($this->serviceCategories as $category)
                    @if (isset($serviceConfigs[$category->id]))
                        <div wire:key="service-config-{{ $category->id }}" class="p-4 border border-zinc-200 dark:border-zinc-700 rounded-lg">
                            <div class="flex items-center gap-2 mb-3">
                                @if ($category->icon)
                                    <flux:icon :name="$category->icon" class="w-5 h-5" style="color: {{ $category->color ?? '#6b7280' }}" />
                                @endif
                                <flux:text class="font-medium">{{ __($category->name) }}</flux:text>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                                {{-- Crew Size --}}
                                <div>
                                    <flux:input
                                        type="number"
                                        :label="__('Crew Size')"
                                        min="1"
                                        max="99"
                                        wire:model.lazy="serviceConfigs.{{ $category->id }}.crew_size"
                                        wire:change="updateServiceConfig({{ $category->id }}, 'crew_size', $event.target.value)"
                                    />
                                </div>

                                {{-- Hours Per Day --}}
                                <div>
                                    <flux:select
                                        :label="__('Hours / Day')"
                                        wire:model.live="serviceConfigs.{{ $category->id }}.hours_per_day"
                                        wire:change="updateServiceConfig({{ $category->id }}, 'hours_per_day', $event.target.value)"
                                    >
                                        <option value="6">{{ __('6 hours') }}</option>
                                        <option value="8">{{ __('8 hours') }}</option>
                                        <option value="10">{{ __('10 hours') }}</option>
                                    </flux:select>
                                </div>

                                {{-- Summary for this service --}}
                                <div>
                                    <flux:text class="text-sm font-medium text-zinc-500 mb-2">{{ __('Summary') }}</flux:text>
                                    <div class="p-3 bg-zinc-50 dark:bg-zinc-800 rounded-lg">
                                        @php
                                            $config = $serviceConfigs[$category->id];
                                            $setting = $this->serviceSettings->where('service_category_id', $category->id)->first();
                                        @endphp
                                        <flux:text class="text-sm font-semibold">
                                            {{ $config['crew_size'] }} {{ Str::plural('worker', $config['crew_size']) }} &times; {{ number_format($config['hours_per_day']) }}hr days
                                        </flux:text>
                                        @if ($setting)
                                            <flux:text class="text-xs text-zinc-500">
                                                {{ $setting->total_days ?? 1 }} {{ Str::plural('day', $setting->total_days ?? 1) }} | {{ number_format($setting->total_labor_hours + $setting->total_cleanup_hours, 1) }} {{ __('total hours') }}
                                            </flux:text>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        </div>
                    @endif
                @endforeach
            </div>

            @if (! $this->plan)
                <div class="mt-4">
                    <flux:button wire:click="createPlan" variant="primary" icon="clipboard-document-list">
                        {{ __('Generate Plan') }}
                    </flux:button>
                </div>
            @endif
        @endif
    </div>

    @if ($this->plan)
        {{-- Service Filter Tabs --}}
        <div class="flex items-center gap-2 mb-4 overflow-x-auto">
            <button
                wire:click="setServiceFilter(null)"
                class="px-3 py-1.5 text-sm font-medium rounded-full transition-colors
                    {{ $serviceFilter === null ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400' : 'text-zinc-500 hover:text-zinc-700 hover:bg-zinc-100 dark:hover:bg-zinc-800' }}"
            >
                {{ __('All') }}
            </button>
            @foreach ($this->serviceCategories as $category)
                <button
                    wire:click="setServiceFilter({{ $category->id }})"
                    wire:key="filter-{{ $category->id }}"
                    class="flex items-center gap-1.5 px-3 py-1.5 text-sm font-medium rounded-full transition-colors
                        {{ $serviceFilter === $category->id ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400' : 'text-zinc-500 hover:text-zinc-700 hover:bg-zinc-100 dark:hover:bg-zinc-800' }}"
                >
                    @if ($category->icon)
                        <flux:icon :name="$category->icon" class="w-4 h-4" />
                    @endif
                    {{ __($category->name) }}
                </button>
            @endforeach
        </div>

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
                        {{ __('Day') }} {{ $day->day_number }}
                        @if ($dayTotal > 0)
                            <flux:badge size="sm" :color="$dayCompleted === $dayTotal ? 'green' : 'zinc'">
                                {{ number_format($dayHours, 1) }}h
                            </flux:badge>
                        @endif
                    </button>
                @endforeach
                <button wire:click="addDay" class="px-3 py-3 text-sm text-zinc-400 hover:text-blue-600 border-b-2 border-transparent" title="{{ __('Add Day') }}">
                    <flux:icon name="plus" class="w-4 h-4" />
                </button>
            </div>

            {{-- Active Day Content --}}
            @foreach ($this->days as $day)
                <div
                    wire:key="day-content-{{ $day->id }}"
                    class="{{ $activeDay === $day->day_number ? '' : 'hidden' }}"
                >
                    <div class="p-4">
                        @php
                            $visibleTasks = $this->filteredTasks($day->tasks);
                        @endphp

                        @if ($visibleTasks->isEmpty())
                            <div class="text-center py-8">
                                <flux:icon name="clipboard" class="w-12 h-12 mx-auto text-zinc-300 mb-2" />
                                <flux:text class="text-zinc-400">
                                    {{ $serviceFilter !== null ? __('No tasks for this service on this day.') : __('No tasks assigned to this day yet.') }}
                                </flux:text>
                                <flux:text class="text-xs text-zinc-400">{{ __('Use the day dropdown on tasks to assign them here, or add custom tasks.') }}</flux:text>
                                @if ($day->tasks->isEmpty() && $this->days->count() > 1)
                                    <flux:button wire:click="deleteDay({{ $day->id }})" wire:confirm="{{ __('Delete this empty day?') }}" size="sm" variant="ghost" icon="trash" class="mt-2 text-red-500">
                                        {{ __('Delete Day') }}
                                    </flux:button>
                                @endif
                            </div>
                        @else
                            <div class="space-y-2">
                                @foreach ($visibleTasks as $task)
                                    <div
                                        wire:key="task-{{ $task->id }}"
                                        class="flex items-start gap-3 p-3 rounded-lg border {{ match($task->task_type) {
                                            'surface' => 'border-blue-200 dark:border-blue-800 bg-blue-50/50 dark:bg-blue-900/10',
                                            'addon' => 'border-amber-200 dark:border-amber-800 bg-amber-50/50 dark:bg-amber-900/10',
                                            default => 'border-zinc-200 dark:border-zinc-700',
                                        } }}"
                                    >
                                        {{-- Type indicator --}}
                                        <div class="shrink-0 mt-1">
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
                                            <flux:text class="text-sm font-medium">{{ $task->title }}</flux:text>
                                            @if ($task->description)
                                                <flux:text class="text-xs text-zinc-500 mt-0.5">{{ $task->description }}</flux:text>
                                            @endif
                                            @if ($task->area_name)
                                                <flux:text class="text-xs text-zinc-400">{{ $task->area_name }}</flux:text>
                                            @endif
                                        </div>

                                        {{-- Badges --}}
                                        <div class="flex items-center gap-2 shrink-0">
                                            @if ($task->serviceCategory)
                                                <flux:badge size="sm" :color="$task->serviceCategory->color ? 'zinc' : 'zinc'">
                                                    {{ __($task->serviceCategory->name) }}
                                                </flux:badge>
                                            @endif
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
                                                            {{ __('Day') }} {{ $targetDay->day_number }}
                                                        </option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        @endif

                                        {{-- Task removal actions (custom only) --}}
                                        @if ($task->isCustom())
                                            @if ($task->hasRemovalRequest() && $task->removalRequestedByUser?->type !== 'internal')
                                                {{-- Removal request from crew — team can approve/dismiss --}}
                                                <div class="flex flex-col items-end gap-1 shrink-0">
                                                    <div class="text-xs text-amber-600 dark:text-amber-400 max-w-48 text-right">
                                                        {{ $task->removalRequestedByUser?->name ?? __('Crew') }} {{ __('requests removal') }}
                                                        @if ($task->removal_reason)
                                                            — {{ $task->removal_reason }}
                                                        @endif
                                                    </div>
                                                    <div class="flex gap-1">
                                                        <button wire:click="approveTaskRemoval({{ $task->id }})" class="text-xs px-2 py-1 bg-green-100 text-green-700 hover:bg-green-200 dark:bg-green-900/30 dark:text-green-400 rounded">
                                                            {{ __('Approve') }}
                                                        </button>
                                                        <button wire:click="dismissTaskRemoval({{ $task->id }})" class="text-xs px-2 py-1 bg-zinc-100 text-zinc-600 hover:bg-zinc-200 dark:bg-zinc-700 dark:text-zinc-400 rounded">
                                                            {{ __('Dismiss') }}
                                                        </button>
                                                    </div>
                                                </div>
                                            @elseif ($task->hasRemovalRequest())
                                                {{-- Removal request from self (team) — show pending badge --}}
                                                <flux:badge size="sm" color="yellow" class="shrink-0">{{ __('Removal Pending') }}</flux:badge>
                                            @elseif ($task->added_by && $task->addedByUser?->type === 'crew')
                                                {{-- Crew-created task, no request — team can request removal --}}
                                                <button
                                                    wire:click="requestTaskRemoval({{ $task->id }})"
                                                    wire:confirm="{{ __('Request crew to remove this task?') }}"
                                                    class="text-amber-500 hover:text-amber-700 shrink-0"
                                                    title="{{ __('Request Removal') }}"
                                                >
                                                    <flux:icon name="hand-raised" class="w-4 h-4" />
                                                </button>
                                            @else
                                                {{-- Team's own task or no added_by — direct delete --}}
                                                <button
                                                    wire:click="removeTask({{ $task->id }})"
                                                    wire:confirm="{{ __('Remove this custom task?') }}"
                                                    class="text-red-400 hover:text-red-600 shrink-0"
                                                >
                                                    <flux:icon name="trash" class="w-4 h-4" />
                                                </button>
                                            @endif
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
                                        :label="__('Task Title')"
                                        :placeholder="__('e.g. Move furniture, Tape trim')"
                                    />
                                    <flux:textarea
                                        wire:model="customTaskDescription"
                                        :label="__('Description (optional)')"
                                        rows="2"
                                    />
                                    <flux:select
                                        wire:model="customTaskServiceCategoryId"
                                        :label="__('Service Category')"
                                    >
                                        <option value="">{{ __('-- None --') }}</option>
                                        @foreach ($this->serviceCategories as $cat)
                                            <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                                        @endforeach
                                    </flux:select>
                                    <flux:text class="text-xs text-zinc-400">{{ __('All labor included in estimate') }}</flux:text>
                                    <div class="flex items-center gap-2">
                                        <flux:button wire:click="addCustomTask" size="sm" variant="primary">
                                            {{ __('Add Task') }}
                                        </flux:button>
                                        <flux:button wire:click="$set('showCustomTaskForm', false)" size="sm" variant="ghost">
                                            {{ __('Cancel') }}
                                        </flux:button>
                                    </div>
                                </div>
                            @else
                                <flux:button wire:click="$set('showCustomTaskForm', true)" size="sm" variant="ghost" icon="plus">
                                    {{ __('Add Custom Task') }}
                                </flux:button>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Approval Matrix --}}
        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
            <flux:heading size="sm" class="mb-4">{{ __('Plan Approval') }}</flux:heading>
            <flux:text class="text-sm text-zinc-500 mb-4">
                {{ __('All parties must approve each service section before the job can start.') }}
            </flux:text>

            @php
                $approvalCategories = $this->serviceSettings->pluck('serviceCategory')->filter()->unique('id');
                $parties = [
                    JobPrepApproval::PARTY_TEAM => __('Team'),
                    JobPrepApproval::PARTY_CREW => __('Crew'),
                    JobPrepApproval::PARTY_CUSTOMER => __('Customer'),
                ];
                $allApprovals = $this->approvals;
            @endphp

            @if ($approvalCategories->isEmpty())
                {{-- Legacy fallback for plans without service settings --}}
                <div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
                    @foreach (['team' => __('Team'), 'crew' => __('Crew'), 'customer' => __('Customer')] as $party => $label)
                        <div class="p-4 border border-zinc-200 dark:border-zinc-700 rounded-lg">
                            <div class="flex items-center justify-between mb-2">
                                <flux:text class="text-sm font-medium">{{ $label }}</flux:text>
                                @if ($this->plan->{$party . '_approved'})
                                    <flux:badge size="sm" color="green">{{ __('Approved') }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="zinc">{{ __('Pending') }}</flux:badge>
                                @endif
                            </div>
                            @if ($this->plan->{$party . '_approved'})
                                <flux:text class="text-xs text-zinc-400">
                                    {{ $this->plan->{$party . 'Approver'}?->name }} &middot; {{ $this->plan->{$party . '_approved_at'}?->diffForHumans() }}
                                </flux:text>
                            @endif
                        </div>
                    @endforeach
                </div>
            @else
                {{-- Matrix Grid: rows = service categories, columns = parties --}}
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                <th class="text-left py-2 px-3 font-medium text-zinc-500">{{ __('Service') }}</th>
                                @foreach ($parties as $partyKey => $partyLabel)
                                    <th class="text-center py-2 px-3 font-medium text-zinc-500">{{ $partyLabel }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($approvalCategories as $category)
                                <tr wire:key="approval-row-{{ $category->id }}" class="border-b border-zinc-100 dark:border-zinc-800">
                                    <td class="py-3 px-3">
                                        <div class="flex items-center gap-2">
                                            @if ($category->icon)
                                                <flux:icon :name="$category->icon" class="w-4 h-4" style="color: {{ $category->color ?? '#6b7280' }}" />
                                            @endif
                                            <span class="font-medium">{{ __($category->name) }}</span>
                                        </div>
                                    </td>
                                    @foreach ($parties as $partyKey => $partyLabel)
                                        @php
                                            $approval = $allApprovals
                                                ->where('service_category_id', $category->id)
                                                ->where('party', $partyKey)
                                                ->first();
                                            $isApproved = $approval?->approved ?? false;
                                            $canApprove = match($partyKey) {
                                                'team' => auth()->user()?->isInternal(),
                                                'crew' => auth()->user()?->type === 'crew',
                                                'customer' => auth()->user()?->type === 'customer',
                                                default => false,
                                            };
                                        @endphp
                                        <td class="py-3 px-3 text-center">
                                            @if ($isApproved)
                                                <div>
                                                    <flux:badge size="sm" color="green">{{ __('Approved') }}</flux:badge>
                                                    <div class="text-xs text-zinc-400 mt-1">
                                                        {{ $approval->approver?->name ?? '' }}
                                                    </div>
                                                </div>
                                            @elseif ($canApprove)
                                                <flux:button
                                                    wire:click="approveSection('{{ $partyKey }}', {{ $category->id }})"
                                                    size="xs"
                                                    variant="primary"
                                                >
                                                    {{ __('Approve') }}
                                                </flux:button>
                                            @else
                                                <flux:badge size="sm" color="yellow">{{ __('Pending') }}</flux:badge>
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            {{-- Start Job Button --}}
            @if ($this->plan->isFullyApproved())
                <div class="text-center mt-6">
                    <flux:button
                        wire:click="startJob"
                        wire:confirm="{{ __('Start this job? This will advance the stage to In Progress.') }}"
                        variant="primary"
                        icon="play"
                        size="base"
                    >
                        {{ __('Start Job') }}
                    </flux:button>
                </div>
            @endif
        </div>
    @endif
</div>
