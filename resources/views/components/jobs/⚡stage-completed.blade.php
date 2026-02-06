<?php

use App\Models\Job;
use Livewire\Component;

new class extends Component {
    public Job $job;

    public function mount(Job $job): void
    {
        $this->job = $job;
    }
}; ?>

<div>
    <flux:heading size="sm" class="mb-4">Stage 5: Completed</flux:heading>

    <div class="text-center py-12">
        <flux:icon name="check-circle" class="w-16 h-16 mx-auto text-green-500 mb-4" />
        <flux:heading size="lg">Job Completed</flux:heading>
        <flux:text class="text-zinc-500 mt-2 max-w-md mx-auto">
            This job has been completed. Review the final details below.
        </flux:text>

        <div class="mt-6 inline-flex flex-col gap-2 text-left">
            @if ($job->actual_start_date)
                <div class="flex items-center gap-2">
                    <flux:icon name="calendar" class="w-4 h-4 text-zinc-400" />
                    <flux:text class="text-sm">Started: {{ $job->actual_start_date->format('M j, Y') }}</flux:text>
                </div>
            @endif
            @if ($job->actual_end_date)
                <div class="flex items-center gap-2">
                    <flux:icon name="calendar" class="w-4 h-4 text-zinc-400" />
                    <flux:text class="text-sm">Completed: {{ $job->actual_end_date->format('M j, Y') }}</flux:text>
                </div>
            @endif
            @if ($job->actual_start_date && $job->actual_end_date)
                <div class="flex items-center gap-2">
                    <flux:icon name="clock" class="w-4 h-4 text-zinc-400" />
                    <flux:text class="text-sm">
                        Duration: {{ $job->actual_start_date->diffInDays($job->actual_end_date) }} days
                    </flux:text>
                </div>
            @endif
        </div>

        @if ($job->estimate)
            <div class="mt-6">
                <flux:button :href="route('estimates.show', $job->estimate)" variant="ghost" icon="document-text" wire:navigate>
                    View Estimate
                </flux:button>
            </div>
        @endif
    </div>
</div>
