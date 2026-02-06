<?php

use App\Models\Job;
use App\Services\JobService;
use Livewire\Component;

new class extends Component {
    public Job $job;

    public function mount(Job $job): void
    {
        $this->job = $job;
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
    <flux:heading size="sm" class="mb-4">Stage 4: In Progress</flux:heading>

    <div class="text-center py-12">
        <flux:icon name="paint-brush" class="w-16 h-16 mx-auto text-orange-500 mb-4" />
        <flux:heading size="lg">Job In Progress</flux:heading>
        <flux:text class="text-zinc-500 mt-2 max-w-md mx-auto">
            The painting crew is on site. Track daily progress, photos, and any issues that arise during the job.
        </flux:text>

        <div class="mt-4 flex items-center justify-center gap-4">
            @if ($job->actual_start_date)
                <flux:badge size="sm" color="green">
                    Started: {{ $job->actual_start_date->format('M j, Y') }}
                </flux:badge>
            @else
                <flux:button wire:click="markStarted" size="sm" variant="primary">
                    Mark as Started
                </flux:button>
            @endif
        </div>

        <div class="mt-6">
            <flux:button wire:click="completeJob" variant="primary" icon="check-circle"
                wire:confirm="Are you sure you want to mark this job as completed?">
                Mark Job Complete
            </flux:button>
        </div>
    </div>
</div>
