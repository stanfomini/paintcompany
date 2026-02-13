<?php

use App\Models\Job;
use App\Services\JobDispatchService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.crew')] class extends Component {
    /**
     * @return Collection<int, Job>
     */
    #[Computed]
    public function acceptedJobs(): Collection
    {
        return app(JobDispatchService::class)
            ->getAcceptedJobsForCrew(Auth::guard('crew')->user());
    }

    #[Computed]
    public function activeJobs(): Collection
    {
        return $this->acceptedJobs->where('stage', '!=', 'completed');
    }

    #[Computed]
    public function completedJobs(): Collection
    {
        return $this->acceptedJobs->where('stage', 'completed');
    }
};
?>

<div>
    <flux:heading size="xl" class="mb-6">{{ __('My Jobs') }}</flux:heading>

    {{-- Active Jobs --}}
    <div class="mb-8">
        <flux:heading size="sm" class="mb-4">{{ __('Active Jobs') }}</flux:heading>
        @if ($this->activeJobs->count() > 0)
            <div class="space-y-4">
                @foreach ($this->activeJobs as $job)
                    <a href="{{ route('crew.jobs.show', $job) }}" class="block" wire:key="my-job-{{ $job->id }}">
                        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 hover:shadow-md transition-shadow">
                            <div class="flex items-center justify-between mb-2">
                                <flux:heading size="sm">{{ $job->title }}</flux:heading>
                                @php
                                    $color = match ($job->stage) {
                                        'estimate_accepted' => 'blue',
                                        'scheduling_colors' => 'purple',
                                        'job_prep' => 'yellow',
                                        'in_progress' => 'orange',
                                        default => 'zinc',
                                    };
                                @endphp
                                <flux:badge size="sm" color="{{ $color }}">{{ $job->stage_label }}</flux:badge>
                            </div>
                            <flux:text class="text-sm text-zinc-500">{{ $job->job_number }}</flux:text>

                            <div class="mt-3 flex gap-1">
                                @foreach (array_keys(App\Models\Job::STAGES) as $stageKey)
                                    @php
                                        $stageNum = App\Models\Job::STAGE_ORDER[$stageKey];
                                        $currentNum = $job->stage_number;
                                    @endphp
                                    <div class="flex-1 h-2 rounded-full {{ $stageNum <= $currentNum ? 'bg-green-500' : 'bg-zinc-200 dark:bg-zinc-700' }}"></div>
                                @endforeach
                            </div>

                            <div class="flex items-center justify-between mt-3">
                                @if ($job->deal?->customer)
                                    <flux:text class="text-xs text-zinc-500">
                                        {{ $job->deal->customer->full_name }}
                                    </flux:text>
                                @endif
                                @if ($job->scheduled_date)
                                    <flux:text class="text-xs text-zinc-400">
                                        {{ $job->scheduled_date->format('M j, Y') }}
                                    </flux:text>
                                @endif
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        @else
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-12 text-center">
                <flux:icon name="clipboard-document-check" class="w-12 h-12 mx-auto text-zinc-300 dark:text-zinc-600 mb-3" />
                <flux:text class="text-zinc-500">{{ __('No active jobs. Accept available jobs to get started.') }}</flux:text>
            </div>
        @endif
    </div>

    {{-- Completed Jobs --}}
    @if ($this->completedJobs->count() > 0)
        <div>
            <flux:heading size="sm" class="mb-4">{{ __('Completed Jobs') }}</flux:heading>
            <div class="space-y-3">
                @foreach ($this->completedJobs as $job)
                    <a href="{{ route('crew.jobs.show', $job) }}" class="block" wire:key="completed-{{ $job->id }}">
                        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 hover:shadow-md transition-shadow opacity-75">
                            <div class="flex items-center justify-between">
                                <div>
                                    <flux:text class="font-medium">{{ $job->title }}</flux:text>
                                    <flux:text class="text-sm text-zinc-500">{{ $job->job_number }}</flux:text>
                                </div>
                                <flux:badge size="sm" color="green">{{ __('Completed') }}</flux:badge>
                            </div>
                        </div>
                    </a>
                @endforeach
            </div>
        </div>
    @endif
</div>
