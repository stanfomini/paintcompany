<?php

use App\Services\JobDispatchService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.crew')] class extends Component
{
    public function getListeners(): array
    {
        $userId = Auth::guard('crew')->id();

        return [
            "echo-private:crew.user.{$userId},.job.dispatched" => 'handleNewDispatch',
        ];
    }

    public function handleNewDispatch(array $data): void
    {
        unset($this->availableJobCount);
    }

    #[Computed]
    public function availableJobCount(): int
    {
        return app(JobDispatchService::class)
            ->getAvailableJobsForCrew(Auth::guard('crew')->user())
            ->count();
    }

    #[Computed]
    public function activeJobCount(): int
    {
        return app(JobDispatchService::class)
            ->getAcceptedJobsForCrew(Auth::guard('crew')->user())
            ->where('stage', '!=', 'completed')
            ->count();
    }

    #[Computed]
    public function completedJobCount(): int
    {
        return app(JobDispatchService::class)
            ->getAcceptedJobsForCrew(Auth::guard('crew')->user())
            ->where('stage', 'completed')
            ->count();
    }
};
?>

<div>
    <flux:heading size="xl">{{ __('Crew Dashboard') }}</flux:heading>
    <flux:text class="mt-2">{{ __('Welcome back, :name!', ['name' => auth('crew')->user()->name]) }}</flux:text>

    <div class="grid grid-cols-1 gap-6 mt-8 sm:grid-cols-3">
        <a href="{{ route('crew.jobs.available') }}" class="block p-6 rounded-lg bg-zinc-50 dark:bg-zinc-900 hover:shadow-md transition-shadow">
            <flux:text class="text-sm text-zinc-500">{{ __('Available Jobs') }}</flux:text>
            <flux:heading size="xl" class="mt-2">{{ $this->availableJobCount }}</flux:heading>
            @if ($this->availableJobCount > 0)
                <flux:text class="text-xs text-blue-500 mt-1">{{ __('Review and accept') }}</flux:text>
            @endif
        </a>
        <a href="{{ route('crew.jobs.mine') }}" class="block p-6 rounded-lg bg-zinc-50 dark:bg-zinc-900 hover:shadow-md transition-shadow">
            <flux:text class="text-sm text-zinc-500">{{ __('Active Jobs') }}</flux:text>
            <flux:heading size="xl" class="mt-2">{{ $this->activeJobCount }}</flux:heading>
        </a>
        <div class="p-6 rounded-lg bg-zinc-50 dark:bg-zinc-900">
            <flux:text class="text-sm text-zinc-500">{{ __('Completed Jobs') }}</flux:text>
            <flux:heading size="xl" class="mt-2">{{ $this->completedJobCount }}</flux:heading>
        </div>
    </div>

    @if ($this->availableJobCount > 0)
        <div class="mt-8">
            <flux:callout variant="info">
                {{ __('You have :count :jobs waiting for your response.', ['count' => $this->availableJobCount, 'jobs' => Str::plural('job', $this->availableJobCount)]) }}
                <flux:button :href="route('crew.jobs.available')" size="sm" variant="primary" class="ml-2">
                    {{ __('Review Jobs') }}
                </flux:button>
            </flux:callout>
        </div>
    @endif
</div>
