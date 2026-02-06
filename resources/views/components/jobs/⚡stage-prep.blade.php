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
    <flux:heading size="sm" class="mb-4">Stage 3: Job Prep</flux:heading>

    <div class="text-center py-12">
        <flux:icon name="wrench-screwdriver" class="w-16 h-16 mx-auto text-yellow-500 mb-4" />
        <flux:heading size="lg">Job Preparation</flux:heading>
        <flux:text class="text-zinc-500 mt-2 max-w-md mx-auto">
            Prepare materials, equipment, and crew assignments for the job. This stage will include material checklists, crew briefings, and site prep tasks.
        </flux:text>

        @if ($job->scheduled_date)
            <div class="mt-4">
                <flux:badge size="sm" color="blue">
                    Scheduled: {{ $job->scheduled_date->format('M j, Y') }}
                </flux:badge>
            </div>
        @endif

        @if ($job->acceptedCrew->count() > 0)
            <div class="mt-4 flex items-center justify-center gap-2">
                <flux:text class="text-sm text-zinc-500">Crew:</flux:text>
                @foreach ($job->acceptedCrew as $crew)
                    <flux:badge size="sm" color="green">{{ $crew->name }}</flux:badge>
                @endforeach
            </div>
        @endif
    </div>
</div>
