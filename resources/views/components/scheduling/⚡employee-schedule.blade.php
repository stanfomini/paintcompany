<?php

use App\Models\PtoRequest;
use App\Models\Schedule;
use Carbon\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public function boot(): void
    {
        $user = auth()->user();
        if (! $user?->can('scheduling.view') && ! $user?->employeeProfile) {
            abort(403);
        }
    }

    #[Computed]
    public function employeeProfile()
    {
        return auth()->user()?->employeeProfile;
    }

    #[Computed]
    public function upcomingSchedules()
    {
        if (! $this->employeeProfile) {
            return collect();
        }

        return Schedule::query()
            ->forEmployee($this->employeeProfile->id)
            ->upcoming()
            ->where('status', '!=', 'cancelled')
            ->with('job')
            ->orderBy('date')
            ->orderBy('start_time')
            ->limit(14)
            ->get();
    }

    #[Computed]
    public function ptoRequests()
    {
        if (! $this->employeeProfile) {
            return collect();
        }

        return PtoRequest::query()
            ->where('employee_profile_id', $this->employeeProfile->id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();
    }

    public function confirmShift(int $scheduleId): void
    {
        $schedule = Schedule::where('id', $scheduleId)
            ->where('employee_profile_id', $this->employeeProfile?->id)
            ->where('status', 'scheduled')
            ->firstOrFail();

        $schedule->update(['status' => 'confirmed']);
        unset($this->upcomingSchedules);
        session()->flash('success', __('Shift confirmed.'));
    }
}; ?>

<div>
    @if (! $this->employeeProfile)
        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-8 text-center">
            <flux:icon name="calendar" class="mx-auto h-12 w-12 text-zinc-400" />
            <flux:heading size="sm" class="mt-4">{{ __('No employee profile found') }}</flux:heading>
            <flux:text class="mt-2 text-zinc-500">{{ __('Your employee profile has not been set up yet.') }}</flux:text>
        </div>
    @else
        @if (session('success'))
            <flux:callout variant="success" class="mb-4">
                {{ session('success') }}
            </flux:callout>
        @endif

        <div class="flex items-center justify-between mb-4">
            <flux:heading size="lg">{{ __('My Upcoming Schedule') }}</flux:heading>

            <div x-data="{ copied: false }" class="flex items-center gap-2">
                <flux:button
                    size="sm"
                    variant="ghost"
                    icon="calendar"
                    x-on:click="
                        navigator.clipboard.writeText('{{ route('calendar.schedule-feed', auth()->user()->getOrCreateCalendarToken()) }}');
                        copied = true;
                        setTimeout(() => copied = false, 2000);
                    "
                >
                    <span x-show="!copied">{{ __('Subscribe to Calendar') }}</span>
                    <span x-show="copied" x-cloak>{{ __('Link Copied!') }}</span>
                </flux:button>
            </div>
        </div>

        @if ($this->upcomingSchedules->isEmpty())
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 text-center mb-6">
                <flux:text class="text-zinc-500">{{ __('No upcoming shifts scheduled.') }}</flux:text>
            </div>
        @else
            <div class="space-y-3 mb-6">
                @foreach ($this->upcomingSchedules as $schedule)
                    <div wire:key="my-sched-{{ $schedule->id }}"
                         class="flex items-center justify-between border border-zinc-200 dark:border-zinc-700 rounded-lg p-4
                            {{ $schedule->date->isToday() ? 'bg-blue-50 dark:bg-blue-900/20 border-blue-300 dark:border-blue-700' : '' }}">
                        <div>
                            <flux:text class="font-medium">
                                {{ $schedule->date->format('l, M j, Y') }}
                                @if ($schedule->date->isToday())
                                    <flux:badge size="sm" color="blue" class="ml-1">{{ __('Today') }}</flux:badge>
                                @endif
                            </flux:text>
                            <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
                                {{ Carbon::parse($schedule->start_time)->format('g:i A') }} - {{ Carbon::parse($schedule->end_time)->format('g:i A') }}
                                ({{ number_format($schedule->getScheduledHours(), 1) }}h)
                            </flux:text>
                            @if ($schedule->job)
                                <flux:text class="text-sm text-zinc-500">{{ $schedule->job->job_number }} - {{ $schedule->job->title }}</flux:text>
                            @endif
                            @if ($schedule->notes)
                                <flux:text class="text-sm text-zinc-500 mt-1">{{ $schedule->notes }}</flux:text>
                            @endif
                        </div>
                        <div class="flex items-center gap-2">
                            @if ($schedule->status === 'scheduled')
                                <flux:button size="sm" variant="primary" wire:click="confirmShift({{ $schedule->id }})">
                                    {{ __('Confirm') }}
                                </flux:button>
                            @elseif ($schedule->status === 'confirmed')
                                <flux:badge size="sm" color="green">{{ __('Confirmed') }}</flux:badge>
                            @elseif ($schedule->status === 'completed')
                                <flux:badge size="sm" color="zinc">{{ __('Completed') }}</flux:badge>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        {{-- PTO Requests --}}
        <flux:heading size="lg" class="mb-4">{{ __('My PTO Requests') }}</flux:heading>

        @if ($this->ptoRequests->isEmpty())
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 text-center">
                <flux:text class="text-zinc-500">{{ __('No PTO requests.') }}</flux:text>
            </div>
        @else
            <div class="space-y-2">
                @foreach ($this->ptoRequests as $pto)
                    <div wire:key="my-pto-{{ $pto->id }}" class="flex items-center justify-between border border-zinc-200 dark:border-zinc-700 rounded-lg p-3">
                        <div>
                            <flux:text class="font-medium capitalize">{{ $pto->type }}</flux:text>
                            <flux:text class="text-sm text-zinc-500">
                                {{ $pto->start_date->format('M j') }}{{ $pto->start_date->ne($pto->end_date) ? ' - ' . $pto->end_date->format('M j, Y') : ', ' . $pto->start_date->format('Y') }}
                                ({{ $pto->hours }}h)
                            </flux:text>
                        </div>
                        @if ($pto->status === 'pending')
                            <flux:badge size="sm" color="yellow">{{ __('Pending') }}</flux:badge>
                        @elseif ($pto->status === 'approved')
                            <flux:badge size="sm" color="green">{{ __('Approved') }}</flux:badge>
                        @elseif ($pto->status === 'denied')
                            <flux:badge size="sm" color="red">{{ __('Denied') }}</flux:badge>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    @endif
</div>
