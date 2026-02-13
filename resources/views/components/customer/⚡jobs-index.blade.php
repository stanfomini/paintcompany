<?php

use App\Models\Customer;
use App\Models\Job;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.customer', ['title' => 'My Jobs'])] class extends Component {
    public function boot(): void
    {
        $user = Auth::guard('customer')->user();

        if (! $user || ! $user->isCustomer()) {
            abort(403);
        }
    }

    #[Computed]
    public function customer(): ?Customer
    {
        return Customer::where('user_id', Auth::guard('customer')->id())->first();
    }

    /**
     * @return Collection<int, Job>
     */
    #[Computed]
    public function activeJobs(): Collection
    {
        if (! $this->customer) {
            return collect();
        }

        return Job::query()
            ->whereHas('deal', fn ($q) => $q->where('customer_id', $this->customer->id))
            ->with(['deal', 'estimate'])
            ->active()
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * @return Collection<int, Job>
     */
    #[Computed]
    public function completedJobs(): Collection
    {
        if (! $this->customer) {
            return collect();
        }

        return Job::query()
            ->whereHas('deal', fn ($q) => $q->where('customer_id', $this->customer->id))
            ->with(['deal', 'estimate'])
            ->where('stage', 'completed')
            ->orderBy('updated_at', 'desc')
            ->get();
    }
}; ?>

<div>
    <div class="max-w-4xl mx-auto">
        <flux:heading size="xl" class="mb-6">{{ __('My Jobs') }}</flux:heading>

        {{-- Active Jobs --}}
        <div class="mb-8">
            <flux:heading size="sm" class="mb-4">{{ __('Active Jobs') }}</flux:heading>
            @if ($this->activeJobs->count() > 0)
                <div class="space-y-4">
                    @foreach ($this->activeJobs as $job)
                        <a href="{{ route('customer.jobs.show', $job) }}" class="block" wire:navigate wire:key="active-job-{{ $job->id }}">
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

                                {{-- Mini progress bar --}}
                                <div class="mt-3 flex gap-1">
                                    @foreach (array_keys(App\Models\Job::STAGES) as $stageKey)
                                        @php
                                            $stageNum = App\Models\Job::STAGE_ORDER[$stageKey];
                                            $currentNum = $job->stage_number;
                                        @endphp
                                        <div class="flex-1 h-2 rounded-full {{ $stageNum <= $currentNum ? 'bg-green-500' : 'bg-zinc-200 dark:bg-zinc-700' }}"></div>
                                    @endforeach
                                </div>

                                @if ($job->scheduled_date)
                                    <flux:text class="text-xs text-zinc-400 mt-2">
                                        {{ __('Scheduled') }}: {{ $job->scheduled_date->format('M j, Y') }}
                                    </flux:text>
                                @endif
                            </div>
                        </a>
                    @endforeach
                </div>
            @else
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
                    <flux:icon name="clipboard-document-check" class="w-12 h-12 mx-auto text-zinc-300 dark:text-zinc-600 mb-3" />
                    <flux:text class="text-zinc-500">{{ __('No active jobs at this time.') }}</flux:text>
                </div>
            @endif
        </div>

        {{-- Completed Jobs --}}
        @if ($this->completedJobs->count() > 0)
            <div>
                <flux:heading size="sm" class="mb-4">{{ __('Completed Jobs') }}</flux:heading>
                <div class="space-y-3">
                    @foreach ($this->completedJobs as $job)
                        <a href="{{ route('customer.jobs.show', $job) }}" class="block" wire:navigate wire:key="completed-job-{{ $job->id }}">
                            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 hover:shadow-md transition-shadow opacity-75">
                                <div class="flex items-center justify-between">
                                    <div>
                                        <flux:text class="font-medium">{{ $job->title }}</flux:text>
                                        <flux:text class="text-sm text-zinc-500">{{ $job->job_number }}</flux:text>
                                    </div>
                                    <flux:badge size="sm" color="green">{{ __('Completed') }}</flux:badge>
                                </div>
                                @if ($job->actual_end_date)
                                    <flux:text class="text-xs text-zinc-400 mt-1">
                                        {{ __('Completed') }}: {{ $job->actual_end_date->format('M j, Y') }}
                                    </flux:text>
                                @endif
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</div>
