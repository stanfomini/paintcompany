<?php

use App\Models\Job;
use App\Services\JobDispatchService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.crew')] class extends Component
{
    /** @var array<int, string> Job IDs that have been taken by another crew */
    public array $takenJobs = [];

    /** @var array<int> Job IDs to subscribe to for broadcast events */
    public array $subscribedJobIds = [];

    public function mount(): void
    {
        $this->subscribedJobIds = app(JobDispatchService::class)
            ->getAvailableJobsForCrew(Auth::guard('crew')->user())
            ->pluck('id')
            ->toArray();
    }

    public function getListeners(): array
    {
        $userId = Auth::guard('crew')->id();
        $listeners = [
            "echo-private:crew.user.{$userId},.job.dispatched" => 'handleNewDispatch',
        ];

        foreach ($this->subscribedJobIds as $jobId) {
            $listeners["echo-private:crew.job.{$jobId},.job.accepted"] = 'handleJobAccepted';
        }

        return $listeners;
    }

    public function handleNewDispatch(array $data): void
    {
        // Refresh the job list and subscribe to the new job's channel
        unset($this->availableJobs);
        $newJobId = $data['job_id'] ?? null;

        if ($newJobId && ! in_array($newJobId, $this->subscribedJobIds)) {
            $this->subscribedJobIds[] = $newJobId;
        }

        session()->flash('success', __('New job dispatched: :title', ['title' => $data['job_title'] ?? __('Unknown')]));
    }

    public function handleJobAccepted(array $data): void
    {
        $currentUser = Auth::guard('crew')->user();

        if (($data['accepted_by_id'] ?? null) !== $currentUser->id) {
            $this->takenJobs[$data['job_id']] = $data['accepted_by_name'] ?? __('Another crew');
        }
    }

    /**
     * @return Collection<int, Job>
     */
    #[Computed]
    public function availableJobs(): Collection
    {
        return app(JobDispatchService::class)
            ->getAvailableJobsForCrew(Auth::guard('crew')->user());
    }

    #[Computed]
    public function crewServiceCategoryName(): ?string
    {
        $name = Auth::guard('crew')->user()->crewProfile?->serviceCategory?->name;

        return $name ? __($name) : null;
    }

    public function acceptJob(int $jobId): void
    {
        $job = Job::findOrFail($jobId);
        $user = Auth::guard('crew')->user();

        if (app(JobDispatchService::class)->acceptJob($job, $user)) {
            session()->flash('success', __('Job accepted! View it in My Jobs.'));
        } else {
            session()->flash('error', __('This job has already been accepted by another crew.'));
        }
    }

    public function rejectJob(int $jobId): void
    {
        $job = Job::findOrFail($jobId);
        $user = Auth::guard('crew')->user();

        if (app(JobDispatchService::class)->rejectJob($job, $user)) {
            session()->flash('success', __('Job declined.'));
        } else {
            session()->flash('error', __('Unable to decline this job.'));
        }
    }

    public function getBonusForJob(Job $job): ?array
    {
        $user = Auth::guard('crew')->user();
        $profile = $user->crewProfile;

        if (! $profile || ! ($profile->bonus_percent > 0)) {
            return null;
        }

        $customerPrice = $job->estimate?->selectedPackage?->total
            ?? $job->estimate?->packages()->where('is_active', true)->value('total');

        if (! $customerPrice || $customerPrice <= 0) {
            return null;
        }

        return [
            'bonus_percent' => (float) $profile->bonus_percent,
            'bonus_amount' => round($customerPrice * ($profile->bonus_percent / 100), 2),
        ];
    }
};
?>

<div wire:poll.120s.visible>
    <div class="flex items-center gap-3 mb-6">
        <flux:button variant="ghost" :href="route('crew.dashboard')" icon="arrow-left" class="shrink-0">
            <span class="hidden sm:inline">{{ __('Back') }}</span>
        </flux:button>
        <flux:heading size="xl" class="truncate">{{ __('Available Jobs') }}</flux:heading>
    </div>

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

    @if (! auth('crew')->user()->crewProfile?->service_category_id)
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-900/20 mb-6">
            <div class="flex items-start gap-3">
                <flux:icon name="exclamation-triangle" class="size-5 text-amber-600 shrink-0" />
                <div>
                    <p class="font-medium text-amber-800 dark:text-amber-200">{{ __('Service Category Required') }}</p>
                    <p class="text-sm text-amber-600 dark:text-amber-400">
                        {{ __('Please set your service category in your') }}
                        <a href="{{ route('crew.settings') }}" class="underline font-medium" wire:navigate>{{ __('settings') }}</a>
                        {{ __('to see available jobs.') }}
                    </p>
                </div>
            </div>
        </div>
    @endif

    @if ($this->availableJobs->count() > 0)
        <div class="space-y-4">
            @foreach ($this->availableJobs as $job)
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden {{ isset($this->takenJobs[$job->id]) ? 'opacity-60' : '' }}" wire:key="available-{{ $job->id }}">
                    <div class="p-6">
                        <div class="flex items-start justify-between gap-2 mb-4">
                            <div class="min-w-0">
                                <flux:heading size="lg" class="truncate">{{ $job->title }}</flux:heading>
                                <flux:text class="text-sm text-zinc-500">{{ $job->job_number }}</flux:text>
                            </div>
                            <div class="flex flex-wrap items-center gap-1 sm:gap-2 shrink-0">
                                @if ($this->crewServiceCategoryName)
                                    <flux:badge size="sm" color="purple">{{ $this->crewServiceCategoryName }}</flux:badge>
                                @endif
                                @if (isset($this->takenJobs[$job->id]))
                                    <flux:badge size="sm" color="red">{{ __('Taken') }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="blue">{{ __('New') }}</flux:badge>
                                @endif
                            </div>
                        </div>

                        <div class="grid gap-4 sm:grid-cols-2 mb-4">
                            @if ($job->deal?->customer)
                                <div>
                                    <flux:text class="text-sm font-medium text-zinc-500">{{ __('Customer') }}</flux:text>
                                    <flux:text>{{ $job->deal->customer->full_name }}</flux:text>
                                </div>
                            @endif
                            @if ($job->scheduled_date)
                                <div>
                                    <flux:text class="text-sm font-medium text-zinc-500">{{ __('Scheduled Date') }}</flux:text>
                                    <flux:text>{{ $job->scheduled_date->format('M j, Y') }}</flux:text>
                                </div>
                            @endif
                            <div>
                                <flux:text class="text-sm font-medium text-zinc-500">{{ __('Required Crew Size') }}</flux:text>
                                <flux:text>{{ $job->required_crew_size }} {{ Str::plural('person', $job->required_crew_size) }}</flux:text>
                            </div>
                            @if ($job->description)
                                <div class="sm:col-span-2">
                                    <flux:text class="text-sm font-medium text-zinc-500">{{ __('Description') }}</flux:text>
                                    <flux:text>{{ $job->description }}</flux:text>
                                </div>
                            @endif
                        </div>

                        @if ($job->colors->count() > 0)
                            <div class="mb-4">
                                <flux:text class="text-sm font-medium text-zinc-500 mb-2">{{ __('Colors') }}</flux:text>
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

                        @php $jobBonus = $this->getBonusForJob($job); @endphp
                        @if ($jobBonus)
                            <div class="bg-gradient-to-r from-amber-50 to-green-50 dark:from-amber-900/20 dark:to-green-900/20 border border-amber-200 dark:border-amber-800 rounded-lg p-3 sm:p-4 mt-4">
                                <div class="flex items-center justify-between gap-2">
                                    <div class="min-w-0">
                                        <span class="text-xs sm:text-sm font-bold text-amber-800 dark:text-amber-300">
                                            <flux:icon name="gift" class="w-4 h-4 inline mr-1" />
                                            {{ __('Crew Lead Bonus') }} ({{ number_format($jobBonus['bonus_percent'], 1) }}%)
                                        </span>
                                        <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5 hidden sm:block">{{ __('Performance bonus available on this job') }}</p>
                                    </div>
                                    <span class="text-lg sm:text-xl font-bold text-green-600 dark:text-green-400 shrink-0">${{ number_format($jobBonus['bonus_amount'], 2) }}</span>
                                </div>
                            </div>
                        @endif

                        <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-2 mt-4 pt-4 border-t border-zinc-200 dark:border-zinc-700">
                            @if (isset($this->takenJobs[$job->id]))
                                <flux:text class="text-sm text-red-600 dark:text-red-400">
                                    {{ __('Accepted by :name', ['name' => $this->takenJobs[$job->id]]) }}
                                </flux:text>
                            @else
                                <flux:button href="{{ route('crew.jobs.show', $job) }}" size="sm" variant="ghost" icon="eye" class="justify-center sm:justify-start">
                                    {{ __('View Details') }}
                                </flux:button>
                                <div class="flex items-center gap-2">
                                    <flux:button
                                        wire:click="rejectJob({{ $job->id }})"
                                        wire:confirm="{{ __('Are you sure you want to decline this job?') }}"
                                        size="sm"
                                        variant="ghost"
                                        class="flex-1 sm:flex-none"
                                    >
                                        {{ __('Decline') }}
                                    </flux:button>
                                    <flux:button
                                        wire:click="acceptJob({{ $job->id }})"
                                        size="sm"
                                        variant="primary"
                                        icon="check"
                                        class="flex-1 sm:flex-none"
                                    >
                                        {{ __('Accept Job') }}
                                    </flux:button>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @else
        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-12 text-center">
            <flux:icon name="clipboard-document-check" class="w-16 h-16 mx-auto text-zinc-300 dark:text-zinc-600 mb-4" />
            <flux:heading size="lg">{{ __('No Available Jobs') }}</flux:heading>
            <flux:text class="text-zinc-500 mt-2">
                {{ __('When new jobs are dispatched to your crew, they\'ll appear here.') }}
            </flux:text>
        </div>
    @endif
</div>
