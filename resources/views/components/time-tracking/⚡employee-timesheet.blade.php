<?php

use App\Models\EmployeeProfile;
use App\Models\TimeEntry;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Carbon\Carbon;

new
#[Layout('layouts.app')]
#[Title('Timesheet')]
class extends Component {
    public ?int $employeeProfileId = null;

    public string $weekStart = '';

    public ?int $editingEntryId = null;

    public function boot(): void
    {
        $user = auth()->user();

        // Employees can view their own, managers can view all
        if (! $user?->can('time_tracking.view_all') && ! $user?->employeeProfile) {
            abort(403);
        }
    }

    public function mount(?EmployeeProfile $employeeProfile = null): void
    {
        if ($employeeProfile?->id) {
            $this->employeeProfileId = $employeeProfile->id;
        } else {
            $this->employeeProfileId = auth()->user()?->employeeProfile?->id;
        }

        $this->weekStart = now()->startOfWeek(Carbon::MONDAY)->toDateString();
    }

    public function previousWeek(): void
    {
        $this->weekStart = Carbon::parse($this->weekStart)->subWeek()->toDateString();
        unset($this->entries, $this->dailyBreakdown);
    }

    public function nextWeek(): void
    {
        $this->weekStart = Carbon::parse($this->weekStart)->addWeek()->toDateString();
        unset($this->entries, $this->dailyBreakdown);
    }

    #[Computed]
    public function employee(): ?EmployeeProfile
    {
        if (! $this->employeeProfileId) {
            return null;
        }

        return EmployeeProfile::with('user')->find($this->employeeProfileId);
    }

    #[Computed]
    public function entries()
    {
        if (! $this->employeeProfileId) {
            return collect();
        }

        $weekEnd = Carbon::parse($this->weekStart)->endOfWeek(Carbon::SUNDAY);

        return TimeEntry::query()
            ->with('job', 'approvedBy', 'editedBy')
            ->where('employee_profile_id', $this->employeeProfileId)
            ->whereDate('clock_in', '>=', $this->weekStart)
            ->whereDate('clock_in', '<=', $weekEnd)
            ->orderBy('clock_in')
            ->get();
    }

    #[Computed]
    public function dailyBreakdown(): \Illuminate\Support\Collection
    {
        $start = Carbon::parse($this->weekStart);
        $days = collect();

        for ($i = 0; $i < 7; $i++) {
            $date = $start->copy()->addDays($i);
            $dayEntries = $this->entries->filter(
                fn ($e) => $e->clock_in->toDateString() === $date->toDateString()
            );

            $days->push([
                'date' => $date,
                'day_name' => $date->format('l'),
                'entries' => $dayEntries,
                'total_hours' => round($dayEntries->sum('total_hours'), 2),
                'break_minutes' => $dayEntries->sum('break_minutes'),
            ]);
        }

        return $days;
    }

    #[Computed]
    public function weekTotalHours(): float
    {
        return round($this->entries->sum('total_hours'), 2);
    }

    #[Computed]
    public function weekOvertimeHours(): float
    {
        return round($this->entries->sum('overtime_hours'), 2);
    }

    #[Computed]
    public function weekBreakMinutes(): int
    {
        return $this->entries->sum('break_minutes');
    }

    #[Computed]
    public function canManage(): bool
    {
        return auth()->user()?->can('time_tracking.manage') ?? false;
    }

    public function openEditModal(int $entryId): void
    {
        $this->editingEntryId = $entryId;
        $this->dispatch('open-entry-edit-modal', entryId: $entryId);
    }

    public function refreshEntries(): void
    {
        unset($this->entries, $this->dailyBreakdown);
    }
};
?>

<div class="space-y-6">
    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <flux:heading size="xl">Timesheet</flux:heading>
            @if($this->employee)
                <p class="text-sm text-zinc-500 dark:text-zinc-400">
                    {{ $this->employee->user->name ?? $this->employee->employee_number }}
                </p>
            @endif
        </div>
    </div>

    {{-- Week Navigation --}}
    <div class="flex items-center gap-4">
        <flux:button size="sm" variant="ghost" wire:click="previousWeek">
            <flux:icon name="chevron-left" class="h-4 w-4" />
        </flux:button>
        <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">
            {{ Carbon::parse($this->weekStart)->format('M j') }} - {{ Carbon::parse($this->weekStart)->addDays(6)->format('M j, Y') }}
        </span>
        <flux:button size="sm" variant="ghost" wire:click="nextWeek">
            <flux:icon name="chevron-right" class="h-4 w-4" />
        </flux:button>
    </div>

    {{-- Week Totals --}}
    <div class="grid grid-cols-3 gap-4">
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <p class="text-xs text-zinc-500 dark:text-zinc-400">Total Hours</p>
            <p class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ number_format($this->weekTotalHours, 2) }}</p>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <p class="text-xs text-zinc-500 dark:text-zinc-400">Overtime</p>
            <p class="text-2xl font-bold {{ $this->weekOvertimeHours > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-zinc-900 dark:text-zinc-100' }}">
                {{ number_format($this->weekOvertimeHours, 2) }}
            </p>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <p class="text-xs text-zinc-500 dark:text-zinc-400">Break Time</p>
            <p class="text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $this->weekBreakMinutes }}m</p>
        </div>
    </div>

    {{-- Daily Breakdown --}}
    <div class="space-y-3">
        @foreach($this->dailyBreakdown as $day)
            <div wire:key="day-{{ $day['date']->toDateString() }}" class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                <div class="flex items-center justify-between border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                    <div class="flex items-center gap-3">
                        <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $day['day_name'] }}</span>
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $day['date']->format('M j') }}</span>
                    </div>
                    <span class="text-sm font-mono font-medium text-zinc-900 dark:text-zinc-100">
                        {{ number_format($day['total_hours'], 2) }}h
                    </span>
                </div>

                @if($day['entries']->isEmpty())
                    <div class="px-4 py-3">
                        <p class="text-xs text-zinc-400 dark:text-zinc-500">No entries</p>
                    </div>
                @else
                    <div class="divide-y divide-zinc-100 dark:divide-zinc-700/50">
                        @foreach($day['entries'] as $entry)
                            <div wire:key="entry-{{ $entry->id }}" class="flex items-center justify-between px-4 py-2">
                                <div class="flex items-center gap-3">
                                    <div>
                                        <p class="text-sm text-zinc-900 dark:text-zinc-100">
                                            {{ $entry->clock_in->format('g:i A') }}
                                            -
                                            {{ $entry->clock_out?->format('g:i A') ?? 'Active' }}
                                        </p>
                                        <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                            @if($entry->job)
                                                {{ $entry->job->job_number }}
                                            @endif
                                            @if($entry->break_minutes > 0)
                                                &middot; {{ $entry->break_minutes }}m break
                                            @endif
                                            @if($entry->total_hours)
                                                &middot; {{ number_format($entry->total_hours, 2) }}h
                                            @endif
                                        </p>
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    @php
                                        $statusColor = match($entry->status) {
                                            'active' => 'blue',
                                            'pending' => 'yellow',
                                            'approved' => 'green',
                                            'disputed' => 'red',
                                            'edited' => 'purple',
                                            default => 'zinc',
                                        };
                                    @endphp
                                    <flux:badge color="{{ $statusColor }}" size="sm">{{ ucfirst($entry->status) }}</flux:badge>

                                    @if($this->canManage)
                                        <flux:button size="sm" variant="ghost" wire:click="openEditModal({{ $entry->id }})">
                                            <flux:icon name="pencil" class="h-3 w-3" />
                                        </flux:button>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Edit Modal --}}
    @if($this->editingEntryId)
        <livewire:time-tracking.entry-edit-modal
            :entry-id="$this->editingEntryId"
            wire:key="edit-modal-{{ $this->editingEntryId }}"
        />
    @endif
</div>
