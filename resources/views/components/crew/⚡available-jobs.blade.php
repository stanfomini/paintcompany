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
    public function availableJobs(): Collection
    {
        return app(JobDispatchService::class)
            ->getAvailableJobsForCrew(Auth::guard('crew')->user());
    }

    public function acceptJob(int $jobId): void
    {
        $job = Job::findOrFail($jobId);
        $user = Auth::guard('crew')->user();

        if (app(JobDispatchService::class)->acceptJob($job, $user)) {
            session()->flash('success', 'Job accepted! View it in My Jobs.');
        } else {
            session()->flash('error', 'Unable to accept this job.');
        }
    }

    public function rejectJob(int $jobId): void
    {
        $job = Job::findOrFail($jobId);
        $user = Auth::guard('crew')->user();

        if (app(JobDispatchService::class)->rejectJob($job, $user)) {
            session()->flash('success', 'Job declined.');
        } else {
            session()->flash('error', 'Unable to decline this job.');
        }
    }
};
?>

<div>
    <flux:heading size="xl" class="mb-6">{{ __('Available Jobs') }}</flux:heading>

    @if (session('success'))
        <flux:callout variant="success" class="mb-4">
            {{ session('success') }}
        </flux:callout>
    @endif

    @if (session('error'))
        <flux:callout variant="danger" class="mb-4">
            {{ session('error') }}
        </flux:callout>
    @endif

    @if ($this->availableJobs->count() > 0)
        <div class="space-y-4">
            @foreach ($this->availableJobs as $job)
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden" wire:key="available-{{ $job->id }}">
                    <div class="p-6">
                        <div class="flex items-start justify-between mb-4">
                            <div>
                                <flux:heading size="lg">{{ $job->title }}</flux:heading>
                                <flux:text class="text-sm text-zinc-500">{{ $job->job_number }}</flux:text>
                            </div>
                            <flux:badge size="sm" color="blue">New</flux:badge>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2 mb-4">
                            @if ($job->deal?->customer)
                                <div>
                                    <flux:text class="text-sm font-medium text-zinc-500">Customer</flux:text>
                                    <flux:text>{{ $job->deal->customer->full_name }}</flux:text>
                                </div>
                            @endif
                            @if ($job->scheduled_date)
                                <div>
                                    <flux:text class="text-sm font-medium text-zinc-500">Scheduled Date</flux:text>
                                    <flux:text>{{ $job->scheduled_date->format('M j, Y') }}</flux:text>
                                </div>
                            @endif
                            <div>
                                <flux:text class="text-sm font-medium text-zinc-500">Required Crew Size</flux:text>
                                <flux:text>{{ $job->required_crew_size }} {{ Str::plural('person', $job->required_crew_size) }}</flux:text>
                            </div>
                            @if ($job->description)
                                <div class="sm:col-span-2">
                                    <flux:text class="text-sm font-medium text-zinc-500">Description</flux:text>
                                    <flux:text>{{ $job->description }}</flux:text>
                                </div>
                            @endif
                        </div>

                        @if ($job->colors->count() > 0)
                            <div class="mb-4">
                                <flux:text class="text-sm font-medium text-zinc-500 mb-2">Colors</flux:text>
                                <div class="flex flex-wrap gap-2">
                                    @foreach ($job->colors as $color)
                                        <div class="flex items-center gap-1 px-2 py-1 rounded border border-zinc-200 dark:border-zinc-700">
                                            @if ($color->hex_value)
                                                <div class="w-4 h-4 rounded border" style="background-color: {{ $color->hex_value }}"></div>
                                            @endif
                                            <flux:text class="text-xs">{{ $color->name }}</flux:text>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        <div class="flex items-center justify-between mt-4 pt-4 border-t border-zinc-200 dark:border-zinc-700">
                            <flux:button href="{{ route('crew.jobs.show', $job) }}" size="sm" variant="ghost" icon="eye">
                                View Details
                            </flux:button>
                            <div class="flex items-center gap-2">
                                <flux:button
                                    wire:click="rejectJob({{ $job->id }})"
                                    wire:confirm="Are you sure you want to decline this job?"
                                    size="sm"
                                    variant="ghost"
                                >
                                    Decline
                                </flux:button>
                                <flux:button
                                    wire:click="acceptJob({{ $job->id }})"
                                    size="sm"
                                    variant="primary"
                                    icon="check"
                                >
                                    Accept Job
                                </flux:button>
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-12 text-center">
            <flux:icon name="clipboard-document-check" class="w-16 h-16 mx-auto text-zinc-300 dark:text-zinc-600 mb-4" />
            <flux:heading size="lg">No Available Jobs</flux:heading>
            <flux:text class="text-zinc-500 mt-2">
                When new jobs are dispatched to your crew, they'll appear here.
            </flux:text>
        </div>
    @endif
</div>
