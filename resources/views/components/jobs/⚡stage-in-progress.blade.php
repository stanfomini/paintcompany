<?php

use App\Models\Job;
use App\Models\JobPrepPlan;
use App\Models\JobPrepTask;
use App\Services\JobPrepService;
use App\Services\JobService;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Job $job;

    public int $activeDay = 1;

    public function mount(Job $job): void
    {
        $this->job = $job;
        $this->job->load(['prepPlan.days.tasks.completedByUser']);
    }

    #[Computed]
    public function plan(): ?JobPrepPlan
    {
        return $this->job->prepPlan?->load(['days.tasks.completedByUser']);
    }

    #[Computed]
    public function progress(): array
    {
        if (! $this->plan) {
            return ['total' => 0, 'completed' => 0, 'percent' => 0, 'per_day' => []];
        }

        return app(JobPrepService::class)->getProgress($this->plan);
    }

    public function setActiveDay(int $dayNumber): void
    {
        $this->activeDay = $dayNumber;
    }

    public function cycleTask(int $taskId): void
    {
        $task = JobPrepTask::findOrFail($taskId);

        app(JobPrepService::class)->cycleTaskStatus($task, auth()->user());

        unset($this->plan, $this->progress);
        $this->job->refresh();
    }

    public function markStarted(): void
    {
        if (! $this->job->actual_start_date) {
            $this->job->update(['actual_start_date' => now()]);
            $this->job->refresh();
        }
    }

    public function completeJob(): void
    {
        app(JobService::class)->completeJob($this->job);
        $this->job->refresh();
        $this->dispatch('job-updated');
    }
}; ?>

<div>
    <flux:heading size="sm" class="mb-4">{{ __('Stage 4: In Progress') }}</flux:heading>

    {{-- Overall Progress Bar --}}
    @if ($this->plan && $this->progress['total'] > 0)
        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 mb-6">
            <div class="flex items-center justify-between mb-2">
                <flux:heading size="sm">{{ __('Task Progress') }}</flux:heading>
                <flux:text class="text-sm text-zinc-500">
                    {{ $this->progress['completed'] }} / {{ $this->progress['total'] }} {{ __('tasks complete') }}
                </flux:text>
            </div>
            <div class="w-full bg-zinc-200 dark:bg-zinc-700 rounded-full h-3">
                <div
                    class="h-3 rounded-full transition-all duration-500 {{ $this->progress['percent'] >= 100 ? 'bg-green-500' : 'bg-blue-500' }}"
                    style="width: {{ $this->progress['percent'] }}%"
                ></div>
            </div>
            <flux:text class="text-xs text-zinc-400 mt-1">{{ $this->progress['percent'] }}% {{ __('complete') }}</flux:text>
        </div>

        {{-- Day Tabs with Task Checklists --}}
        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg mb-6">
            {{-- Tab Bar --}}
            <div class="flex items-center border-b border-zinc-200 dark:border-zinc-700 overflow-x-auto">
                @foreach ($this->plan->days as $day)
                    @php
                        $dayProgress = collect($this->progress['per_day'])->firstWhere('day_number', $day->day_number);
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
                                {{ $dayCompleted }}/{{ $dayTotal }}
                            </flux:badge>
                        @endif
                    </button>
                @endforeach
            </div>

            {{-- Active Day Content --}}
            @foreach ($this->plan->days as $day)
                <div
                    wire:key="day-content-{{ $day->id }}"
                    class="{{ $activeDay === $day->day_number ? '' : 'hidden' }}"
                >
                    <div class="p-4">
                        @if ($day->tasks->isEmpty())
                            <div class="text-center py-8">
                                <flux:icon name="clipboard" class="w-12 h-12 mx-auto text-zinc-300 mb-2" />
                                <flux:text class="text-zinc-400">{{ __('No tasks assigned to this day.') }}</flux:text>
                            </div>
                        @else
                            <div class="space-y-2">
                                @foreach ($day->tasks->sortBy('sort_order') as $task)
                                    @php
                                        $isCompleted = $task->status === 'completed';
                                        $isInProgress = $task->status === 'in_progress';
                                        $borderClass = match (true) {
                                            $isCompleted => 'border-green-200 dark:border-green-800 bg-green-50/50 dark:bg-green-900/10',
                                            $isInProgress => 'border-amber-200 dark:border-amber-800 bg-amber-50/50 dark:bg-amber-900/10',
                                            default => 'border-zinc-200 dark:border-zinc-700 hover:bg-zinc-50 dark:hover:bg-zinc-800/50',
                                        };
                                    @endphp
                                    <div
                                        wire:key="task-{{ $task->id }}"
                                        class="flex items-center gap-3 p-3 rounded-lg border transition-colors {{ $borderClass }}"
                                    >
                                        {{-- Task Status Button --}}
                                        <div class="shrink-0">
                                            <button
                                                wire:click="cycleTask({{ $task->id }})"
                                                wire:loading.attr="disabled"
                                                wire:target="cycleTask({{ $task->id }})"
                                                class="w-6 h-6 rounded-full flex items-center justify-center transition-colors
                                                    @if ($isCompleted)
                                                        bg-green-500 hover:bg-green-600
                                                    @elseif ($isInProgress)
                                                        bg-amber-500 hover:bg-amber-600
                                                    @else
                                                        border-2 border-zinc-300 dark:border-zinc-600 hover:border-amber-500 hover:bg-amber-50 dark:hover:bg-amber-900/20
                                                    @endif"
                                                title="{{ $isCompleted ? __('Reset to pending') : ($isInProgress ? __('Mark completed') : __('Start task')) }}"
                                            >
                                                @if ($isCompleted)
                                                    <flux:icon name="check" class="w-3 h-3 text-white" />
                                                @elseif ($isInProgress)
                                                    <flux:icon name="wrench" class="w-3 h-3 text-white" />
                                                @endif
                                            </button>
                                        </div>

                                        {{-- Task Info --}}
                                        <div class="flex-1 min-w-0">
                                            <flux:text class="text-sm font-medium truncate {{ $isCompleted ? 'line-through text-zinc-400' : '' }}">
                                                {{ $task->title }}
                                            </flux:text>
                                            @if ($task->area_name)
                                                <flux:text class="text-xs text-zinc-400">{{ $task->area_name }}</flux:text>
                                            @endif
                                            @if ($isCompleted && $task->completedByUser)
                                                <flux:text class="text-xs text-green-500">
                                                    {{ __('Completed by') }} {{ $task->completedByUser->name }} {{ $task->completed_at?->diffForHumans() }}
                                                </flux:text>
                                            @elseif ($isInProgress)
                                                <flux:text class="text-xs text-amber-500">
                                                    {{ __('In progress') }}
                                                </flux:text>
                                            @endif
                                        </div>

                                        {{-- Hours Badge --}}
                                        @if ($task->estimated_hours)
                                            <div class="shrink-0">
                                                <flux:badge size="sm" :color="$isCompleted ? 'green' : ($isInProgress ? 'amber' : 'blue')">
                                                    {{ number_format($task->estimated_hours, 1) }}h
                                                </flux:badge>
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Status and Actions --}}
    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6">
        <div class="text-center">
            <flux:icon name="paint-brush" class="w-12 h-12 mx-auto text-orange-500 mb-3" />
            <flux:heading size="lg">{{ __('Job In Progress') }}</flux:heading>
            <flux:text class="text-zinc-500 mt-1 max-w-md mx-auto">
                {{ __('The painting crew is on site. Track daily progress and mark tasks as they are completed.') }}
            </flux:text>

            <div class="mt-4 flex items-center justify-center gap-4">
                @if ($job->actual_start_date)
                    <flux:badge size="sm" color="green">
                        {{ __('Started:') }} {{ $job->actual_start_date->format('M j, Y') }}
                    </flux:badge>
                @else
                    <flux:button wire:click="markStarted" size="sm" variant="primary">
                        {{ __('Mark as Started') }}
                    </flux:button>
                @endif
            </div>

            <div class="mt-6">
                <flux:button wire:click="completeJob" variant="primary" icon="check-circle"
                    wire:confirm="{{ __('Are you sure you want to mark this job as completed?') }}">
                    {{ __('Mark Job Complete') }}
                </flux:button>
            </div>
        </div>
    </div>
</div>
