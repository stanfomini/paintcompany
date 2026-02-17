<?php

use App\Models\EmployeeProfile;
use App\Models\TimeEntry;
use App\Services\TimeTrackingService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Carbon\Carbon;

new
#[Layout('layouts.app')]
#[Title('Time Tracking')]
class extends Component {
    public string $dateFrom = '';

    public string $dateTo = '';

    public int $employeeFilter = 0;

    public function boot(): void
    {
        if (! auth()->user()?->can('time_tracking.view_all')) {
            abort(403);
        }
    }

    public function mount(): void
    {
        $this->dateFrom = now()->startOfWeek(Carbon::MONDAY)->toDateString();
        $this->dateTo = now()->endOfWeek(Carbon::SUNDAY)->toDateString();
    }

    #[Computed]
    public function activeEntries(): \Illuminate\Database\Eloquent\Collection
    {
        return TimeEntry::query()
            ->with('employeeProfile.user', 'job')
            ->where('status', 'active')
            ->whereNull('clock_out')
            ->orderBy('clock_in', 'desc')
            ->get();
    }

    #[Computed]
    public function weeklySummary(): \Illuminate\Support\Collection
    {
        $query = TimeEntry::query()
            ->with('employeeProfile.user')
            ->whereDate('clock_in', '>=', $this->dateFrom)
            ->whereDate('clock_in', '<=', $this->dateTo)
            ->whereNotNull('total_hours');

        if ($this->employeeFilter) {
            $query->where('employee_profile_id', $this->employeeFilter);
        }

        return $query->get()
            ->groupBy('employee_profile_id')
            ->map(function ($entries) {
                $employee = $entries->first()->employeeProfile;

                return [
                    'employee' => $employee,
                    'total_hours' => round($entries->sum('total_hours'), 2),
                    'overtime_hours' => round($entries->sum('overtime_hours'), 2),
                    'entries_count' => $entries->count(),
                    'pending_count' => $entries->where('status', 'pending')->count(),
                    'approved_count' => $entries->where('status', 'approved')->count(),
                ];
            })
            ->sortByDesc('total_hours')
            ->values();
    }

    #[Computed]
    public function employees(): \Illuminate\Database\Eloquent\Collection
    {
        return EmployeeProfile::query()
            ->with('user')
            ->where('employment_status', 'active')
            ->orderBy('employee_number')
            ->get();
    }

    #[Computed]
    public function pendingEntries(): \Illuminate\Database\Eloquent\Collection
    {
        return TimeEntry::query()
            ->with('employeeProfile.user')
            ->where('status', 'pending')
            ->orderBy('clock_in', 'desc')
            ->limit(20)
            ->get();
    }

    public function approve(int $entryId): void
    {
        $entry = TimeEntry::findOrFail($entryId);
        $service = app(TimeTrackingService::class);
        $service->approveEntry($entry, auth()->user());
        unset($this->pendingEntries, $this->weeklySummary);
    }

    public function dispute(int $entryId, string $reason = ''): void
    {
        $entry = TimeEntry::findOrFail($entryId);
        $entry->update([
            'status' => 'disputed',
            'dispute_reason' => $reason,
        ]);
        unset($this->pendingEntries, $this->weeklySummary);
    }
};
?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <flux:heading size="xl">Time Tracking</flux:heading>
    </div>

    {{-- Active Clock-ins --}}
    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
        <flux:heading size="lg" class="mb-4">Currently Working</flux:heading>

        @if($this->activeEntries->isEmpty())
            <p class="text-sm text-zinc-500 dark:text-zinc-400">No employees currently clocked in.</p>
        @else
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                @foreach($this->activeEntries as $entry)
                    <div wire:key="active-{{ $entry->id }}" class="flex items-center gap-3 rounded-lg border border-green-200 bg-green-50 p-3 dark:border-green-800 dark:bg-green-900/20">
                        <div class="flex h-8 w-8 items-center justify-center rounded-full bg-green-500 text-xs font-bold text-white">
                            {{ substr($entry->employeeProfile->user->name ?? '?', 0, 1) }}
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                {{ $entry->employeeProfile->user->name ?? 'Unknown' }}
                            </p>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                Since {{ $entry->clock_in->format('g:i A') }}
                                @if($entry->job)
                                    &middot; {{ $entry->job->job_number }}
                                @endif
                            </p>
                        </div>
                        @if($entry->isOnBreak())
                            <flux:badge color="amber" size="sm">Break</flux:badge>
                        @else
                            <flux:badge color="green" size="sm">Working</flux:badge>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap gap-3">
        <flux:input type="date" wire:model.live="dateFrom" label="From" />
        <flux:input type="date" wire:model.live="dateTo" label="To" />
        <div>
            <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">Employee</label>
            <select wire:model.live="employeeFilter" class="rounded-md border-zinc-300 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                <option value="0">All Employees</option>
                @foreach($this->employees as $emp)
                    <option value="{{ $emp->id }}">{{ $emp->user->name ?? $emp->employee_number }}</option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- Weekly Summary --}}
    <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
        <div class="border-b border-zinc-200 px-6 py-4 dark:border-zinc-700">
            <flux:heading size="lg">Weekly Summary</flux:heading>
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 dark:border-zinc-700">
                        <th class="px-6 py-3 text-left font-medium text-zinc-500 dark:text-zinc-400">Employee</th>
                        <th class="px-6 py-3 text-right font-medium text-zinc-500 dark:text-zinc-400">Entries</th>
                        <th class="px-6 py-3 text-right font-medium text-zinc-500 dark:text-zinc-400">Total Hours</th>
                        <th class="px-6 py-3 text-right font-medium text-zinc-500 dark:text-zinc-400">Overtime</th>
                        <th class="px-6 py-3 text-right font-medium text-zinc-500 dark:text-zinc-400">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @forelse($this->weeklySummary as $row)
                        <tr wire:key="summary-{{ $row['employee']->id }}">
                            <td class="px-6 py-3 text-zinc-900 dark:text-zinc-100">
                                {{ $row['employee']->user->name ?? $row['employee']->employee_number }}
                            </td>
                            <td class="px-6 py-3 text-right text-zinc-600 dark:text-zinc-300">{{ $row['entries_count'] }}</td>
                            <td class="px-6 py-3 text-right font-mono text-zinc-900 dark:text-zinc-100">{{ number_format($row['total_hours'], 2) }}</td>
                            <td class="px-6 py-3 text-right font-mono {{ $row['overtime_hours'] > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-zinc-400' }}">
                                {{ number_format($row['overtime_hours'], 2) }}
                            </td>
                            <td class="px-6 py-3 text-right">
                                @if($row['pending_count'] > 0)
                                    <flux:badge color="yellow" size="sm">{{ $row['pending_count'] }} Pending</flux:badge>
                                @else
                                    <flux:badge color="green" size="sm">All Approved</flux:badge>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-8 text-center text-zinc-500 dark:text-zinc-400">No time entries for this period.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Pending Approvals --}}
    @if($this->pendingEntries->isNotEmpty())
        <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
            <div class="border-b border-zinc-200 px-6 py-4 dark:border-zinc-700">
                <flux:heading size="lg">Pending Approvals</flux:heading>
            </div>
            <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @foreach($this->pendingEntries as $entry)
                    <div wire:key="pending-{{ $entry->id }}" class="flex items-center justify-between px-6 py-3">
                        <div>
                            <p class="text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                {{ $entry->employeeProfile->user->name ?? 'Unknown' }}
                            </p>
                            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $entry->clock_in->format('M j') }}: {{ $entry->clock_in->format('g:i A') }} - {{ $entry->clock_out?->format('g:i A') ?? 'N/A' }}
                                &middot; {{ number_format($entry->total_hours, 2) }}h
                            </p>
                        </div>
                        <div class="flex gap-2">
                            <flux:button size="sm" variant="primary" wire:click="approve({{ $entry->id }})">
                                Approve
                            </flux:button>
                            <flux:button size="sm" variant="danger" wire:click="dispute({{ $entry->id }})">
                                Dispute
                            </flux:button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</div>
