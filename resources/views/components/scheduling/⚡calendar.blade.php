<?php

use App\Models\EmployeeProfile;
use App\Models\Schedule;
use App\Services\SchedulingService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts.app')]
#[Title('Schedule Calendar')]
class extends Component {
    public function boot(): void
    {
        if (! auth()->user()?->can('scheduling.view')) {
            abort(403);
        }
    }

    public string $viewMode = 'week';

    public string $weekOffset = '0';

    public string $monthOffset = '0';

    public string $employeeFilter = 'all';

    // Create/edit form
    public bool $showCreateModal = false;

    public ?int $editingScheduleId = null;

    public string $formEmployeeId = '';

    public string $formDate = '';

    public string $formStartTime = '07:00';

    public string $formEndTime = '16:00';

    public int $formBreakDuration = 30;

    public string $formNotes = '';

    public string $formStatus = 'scheduled';

    public string $formJobId = '';

    #[Computed]
    public function weekStart()
    {
        return Carbon::now()->startOfWeek(Carbon::MONDAY)->addWeeks((int) $this->weekOffset);
    }

    #[Computed]
    public function weekEnd()
    {
        return $this->weekStart->copy()->endOfWeek(Carbon::SUNDAY);
    }

    #[Computed]
    public function weekDays(): array
    {
        return CarbonPeriod::create($this->weekStart, $this->weekEnd)->toArray();
    }

    #[Computed]
    public function monthStart()
    {
        return Carbon::now()->startOfMonth()->addMonths((int) $this->monthOffset);
    }

    #[Computed]
    public function monthEnd()
    {
        return $this->monthStart->copy()->endOfMonth();
    }

    #[Computed]
    public function monthCalendarDays(): array
    {
        $start = $this->monthStart->copy()->startOfWeek(Carbon::MONDAY);
        $end = $this->monthEnd->copy()->endOfWeek(Carbon::SUNDAY);

        return CarbonPeriod::create($start, $end)->toArray();
    }

    #[Computed]
    public function monthSchedules()
    {
        $start = $this->monthStart->copy()->startOfWeek(Carbon::MONDAY);
        $end = $this->monthEnd->copy()->endOfWeek(Carbon::SUNDAY);

        return Schedule::query()
            ->with(['employeeProfile.user', 'job'])
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->when($this->employeeFilter !== 'all', fn ($q) => $q->forEmployee((int) $this->employeeFilter))
            ->where('status', '!=', 'cancelled')
            ->orderBy('start_time')
            ->get()
            ->groupBy(fn (Schedule $s) => $s->date->toDateString());
    }

    #[Computed]
    public function employees()
    {
        return EmployeeProfile::query()
            ->with('user')
            ->where('employment_status', 'active')
            ->orderBy('employee_number')
            ->get();
    }

    #[Computed]
    public function schedules()
    {
        return Schedule::query()
            ->with(['employeeProfile.user', 'job'])
            ->whereBetween('date', [
                $this->weekStart->toDateString(),
                $this->weekEnd->toDateString(),
            ])
            ->when($this->employeeFilter !== 'all', fn ($q) => $q->forEmployee((int) $this->employeeFilter))
            ->orderBy('start_time')
            ->get()
            ->groupBy(fn (Schedule $s) => $s->date->toDateString());
    }

    #[Computed]
    public function employeeWeeklyHours(): array
    {
        $service = app(SchedulingService::class);
        $hours = [];

        $employeesToCheck = $this->employeeFilter !== 'all'
            ? $this->employees->where('id', (int) $this->employeeFilter)
            : $this->employees;

        foreach ($employeesToCheck as $employee) {
            $weeklyHours = $service->getWeeklyHours($employee, $this->weekStart);
            $hours[$employee->id] = [
                'hours' => $weeklyHours,
                'overtime_risk' => $weeklyHours >= 38,
            ];
        }

        return $hours;
    }

    public function previousWeek(): void
    {
        $this->weekOffset = (string) ((int) $this->weekOffset - 1);
    }

    public function nextWeek(): void
    {
        $this->weekOffset = (string) ((int) $this->weekOffset + 1);
    }

    public function goToToday(): void
    {
        $this->weekOffset = '0';
    }

    public function previousMonth(): void
    {
        $this->monthOffset = (string) ((int) $this->monthOffset - 1);
    }

    public function nextMonth(): void
    {
        $this->monthOffset = (string) ((int) $this->monthOffset + 1);
    }

    public function goToMonthToday(): void
    {
        $this->monthOffset = '0';
    }

    public function switchToWeekOf(string $date): void
    {
        $targetDate = Carbon::parse($date);
        $today = Carbon::now()->startOfWeek(Carbon::MONDAY);
        $this->weekOffset = (string) $today->diffInWeeks($targetDate->startOfWeek(Carbon::MONDAY), false);
        $this->viewMode = 'week';
    }

    public function openCreateModal(?string $date = null): void
    {
        if (! auth()->user()?->can('scheduling.manage')) {
            return;
        }

        $this->resetForm();
        $this->formDate = $date ?? now()->toDateString();
        $this->showCreateModal = true;
    }

    public function editSchedule(int $scheduleId): void
    {
        if (! auth()->user()?->can('scheduling.manage')) {
            return;
        }

        $schedule = Schedule::findOrFail($scheduleId);
        $this->editingScheduleId = $schedule->id;
        $this->formEmployeeId = (string) $schedule->employee_profile_id;
        $this->formDate = $schedule->date->toDateString();
        $this->formStartTime = $schedule->start_time;
        $this->formEndTime = $schedule->end_time;
        $this->formBreakDuration = $schedule->break_duration;
        $this->formNotes = $schedule->notes ?? '';
        $this->formStatus = $schedule->status;
        $this->formJobId = (string) ($schedule->job_id ?? '');
        $this->showCreateModal = true;
    }

    public function saveSchedule(): void
    {
        if (! auth()->user()?->can('scheduling.manage')) {
            return;
        }

        $this->validate([
            'formEmployeeId' => 'required|exists:employee_profiles,id',
            'formDate' => 'required|date',
            'formStartTime' => 'required',
            'formEndTime' => 'required|after:formStartTime',
            'formBreakDuration' => 'required|integer|min:0',
        ]);

        $service = app(SchedulingService::class);

        $data = [
            'employee_profile_id' => (int) $this->formEmployeeId,
            'date' => $this->formDate,
            'start_time' => $this->formStartTime,
            'end_time' => $this->formEndTime,
            'break_duration' => $this->formBreakDuration,
            'notes' => $this->formNotes ?: null,
            'status' => $this->formStatus,
            'job_id' => $this->formJobId ?: null,
            'created_by' => auth()->id(),
        ];

        try {
            if ($this->editingScheduleId) {
                $conflicts = $service->detectConflicts(
                    (int) $this->formEmployeeId,
                    $this->formDate,
                    $this->formStartTime,
                    $this->formEndTime,
                    $this->editingScheduleId,
                );

                if (count($conflicts) > 0) {
                    $this->addError('formStartTime', 'Schedule conflicts with existing entries.');

                    return;
                }

                Schedule::findOrFail($this->editingScheduleId)->update($data);
                session()->flash('success', __('Schedule updated.'));
            } else {
                $service->createSchedule($data);
                session()->flash('success', __('Schedule created.'));
            }
        } catch (\InvalidArgumentException $e) {
            $this->addError('formStartTime', $e->getMessage());

            return;
        }

        $this->showCreateModal = false;
        $this->resetForm();
        unset($this->schedules, $this->employeeWeeklyHours, $this->monthSchedules);
    }

    public function deleteSchedule(int $scheduleId): void
    {
        if (! auth()->user()?->can('scheduling.manage')) {
            return;
        }

        Schedule::findOrFail($scheduleId)->delete();
        session()->flash('success', __('Schedule deleted.'));
        unset($this->schedules, $this->employeeWeeklyHours, $this->monthSchedules);
    }

    private function resetForm(): void
    {
        $this->editingScheduleId = null;
        $this->formEmployeeId = '';
        $this->formDate = '';
        $this->formStartTime = '07:00';
        $this->formEndTime = '16:00';
        $this->formBreakDuration = 30;
        $this->formNotes = '';
        $this->formStatus = 'scheduled';
        $this->formJobId = '';
        $this->resetValidation();
    }
}; ?>

<div>
    <div class="max-w-7xl mx-auto">
        <div class="flex items-center justify-between mb-6">
            <flux:heading size="xl">{{ __('Schedule') }}</flux:heading>
            @can('scheduling.manage')
                <flux:button variant="primary" icon="plus" wire:click="openCreateModal">
                    {{ __('Add Shift') }}
                </flux:button>
            @endcan
        </div>

        @if (session('success'))
            <flux:callout variant="success" class="mb-4">
                {{ session('success') }}
            </flux:callout>
        @endif

        {{-- View Mode Toggle + Employee Filter --}}
        <div class="flex flex-col sm:flex-row items-center justify-between gap-4 mb-6">
            <div class="flex items-center gap-2">
                <div class="flex items-center gap-1 border border-zinc-200 dark:border-zinc-700 rounded-lg p-0.5">
                    <button wire:click="$set('viewMode', 'week')"
                            class="px-3 py-1 text-sm rounded-md transition {{ $viewMode === 'week' ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900' : 'text-zinc-600 dark:text-zinc-400 hover:text-zinc-900 dark:hover:text-zinc-100' }}">
                        {{ __('Week') }}
                    </button>
                    <button wire:click="$set('viewMode', 'month')"
                            class="px-3 py-1 text-sm rounded-md transition {{ $viewMode === 'month' ? 'bg-zinc-900 text-white dark:bg-zinc-100 dark:text-zinc-900' : 'text-zinc-600 dark:text-zinc-400 hover:text-zinc-900 dark:hover:text-zinc-100' }}">
                        {{ __('Month') }}
                    </button>
                </div>
            </div>
            <div class="sm:w-64">
                <flux:select wire:model.live="employeeFilter">
                    <option value="all">{{ __('All Employees') }}</option>
                    @foreach ($this->employees as $emp)
                        <flux:select.option value="{{ $emp->id }}">{{ $emp->user?->name }} ({{ $emp->employee_number }})</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </div>

        @if ($viewMode === 'week')
            {{-- Week Navigation --}}
            <div class="flex items-center gap-2 mb-6">
                <flux:button variant="ghost" size="sm" icon="chevron-left" wire:click="previousWeek" />
                <flux:button variant="ghost" size="sm" wire:click="goToToday">{{ __('Today') }}</flux:button>
                <flux:button variant="ghost" size="sm" icon="chevron-right" wire:click="nextWeek" />
                <flux:heading size="lg" class="ml-2">
                    {{ $this->weekStart->format('M d') }} - {{ $this->weekEnd->format('M d, Y') }}
                </flux:heading>
            </div>

            {{-- Weekly Hours Summary --}}
            @if (count($this->employeeWeeklyHours) > 0)
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3 mb-6">
                    @foreach ($this->employeeWeeklyHours as $empId => $data)
                        @php $emp = $this->employees->firstWhere('id', $empId); @endphp
                        @if ($emp)
                            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-3 {{ $data['overtime_risk'] ? 'bg-amber-50 dark:bg-amber-900/20 border-amber-300 dark:border-amber-700' : '' }}">
                                <flux:text class="text-sm font-medium truncate">{{ $emp->user?->name }}</flux:text>
                                <div class="flex items-center gap-1 mt-1">
                                    <flux:text class="text-lg font-bold {{ $data['overtime_risk'] ? 'text-amber-600 dark:text-amber-400' : '' }}">
                                        {{ number_format($data['hours'], 1) }}h
                                    </flux:text>
                                    @if ($data['overtime_risk'])
                                        <flux:badge size="sm" color="amber">{{ __('OT Risk') }}</flux:badge>
                                    @endif
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif

            {{-- Calendar Grid --}}
            <div class="overflow-x-auto border border-zinc-200 dark:border-zinc-700 rounded-lg">
                <div class="grid grid-cols-7 min-w-[700px]">
                    {{-- Day Headers --}}
                    @foreach ($this->weekDays as $day)
                        <div class="border-b border-r last:border-r-0 border-zinc-200 dark:border-zinc-700 p-3 text-center {{ $day->isToday() ? 'bg-blue-50 dark:bg-blue-900/20' : 'bg-zinc-50 dark:bg-zinc-800' }}">
                            <flux:text class="text-xs uppercase tracking-wider text-zinc-500">{{ $day->format('D') }}</flux:text>
                            <flux:text class="text-lg font-semibold {{ $day->isToday() ? 'text-blue-600 dark:text-blue-400' : '' }}">{{ $day->format('j') }}</flux:text>
                        </div>
                    @endforeach

                    {{-- Day Content --}}
                    @foreach ($this->weekDays as $day)
                        @php $daySchedules = $this->schedules[$day->toDateString()] ?? collect(); @endphp
                        <div class="border-r last:border-r-0 border-zinc-200 dark:border-zinc-700 p-2 min-h-[120px] {{ $day->isToday() ? 'bg-blue-50/50 dark:bg-blue-900/10' : '' }}">
                            @foreach ($daySchedules as $schedule)
                                <div wire:key="sched-{{ $schedule->id }}"
                                     class="mb-1 p-2 rounded text-xs cursor-pointer
                                        {{ match($schedule->status) {
                                            'confirmed' => 'bg-green-100 dark:bg-green-900/30 border border-green-300 dark:border-green-700',
                                            'completed' => 'bg-zinc-100 dark:bg-zinc-800 border border-zinc-300 dark:border-zinc-600',
                                            'cancelled' => 'bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 opacity-60',
                                            default => 'bg-blue-100 dark:bg-blue-900/30 border border-blue-300 dark:border-blue-700',
                                        } }}"
                                     wire:click="editSchedule({{ $schedule->id }})"
                                >
                                    <flux:text class="font-medium truncate">{{ $schedule->employeeProfile?->user?->name }}</flux:text>
                                    <flux:text class="text-zinc-600 dark:text-zinc-400">
                                        {{ Carbon::parse($schedule->start_time)->format('g:ia') }}-{{ Carbon::parse($schedule->end_time)->format('g:ia') }}
                                    </flux:text>
                                    @if ($schedule->job)
                                        <flux:text class="text-zinc-500 truncate">{{ $schedule->job->job_number }}</flux:text>
                                    @endif
                                </div>
                            @endforeach

                            @can('scheduling.manage')
                                <button wire:click="openCreateModal('{{ $day->toDateString() }}')"
                                        class="w-full mt-1 p-1 rounded border border-dashed border-zinc-300 dark:border-zinc-600 text-zinc-400 hover:text-zinc-600 hover:border-zinc-400 dark:hover:text-zinc-300 text-xs text-center transition">
                                    +
                                </button>
                            @endcan
                        </div>
                    @endforeach
                </div>
            </div>
        @else
            {{-- Month Navigation --}}
            <div class="flex items-center gap-2 mb-6">
                <flux:button variant="ghost" size="sm" icon="chevron-left" wire:click="previousMonth" />
                <flux:button variant="ghost" size="sm" wire:click="goToMonthToday">{{ __('Today') }}</flux:button>
                <flux:button variant="ghost" size="sm" icon="chevron-right" wire:click="nextMonth" />
                <flux:heading size="lg" class="ml-2">
                    {{ $this->monthStart->format('F Y') }}
                </flux:heading>
            </div>

            {{-- Month Calendar Grid --}}
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">
                {{-- Day of week headers --}}
                <div class="grid grid-cols-7 bg-zinc-50 dark:bg-zinc-800 border-b border-zinc-200 dark:border-zinc-700">
                    @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $day)
                        <div class="p-2 text-center text-xs uppercase tracking-wider text-zinc-500 font-medium">{{ __($day) }}</div>
                    @endforeach
                </div>

                {{-- Day cells --}}
                <div class="grid grid-cols-7">
                    @foreach ($this->monthCalendarDays as $day)
                        @php
                            $dayKey = $day->toDateString();
                            $daySchedules = $this->monthSchedules[$dayKey] ?? collect();
                            $isCurrentMonth = $day->month === $this->monthStart->month;
                            $isToday = $day->isToday();
                        @endphp
                        <div
                            wire:key="month-day-{{ $dayKey }}"
                            class="border-b border-r border-zinc-200 dark:border-zinc-700 p-2 min-h-[90px] cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors
                                {{ ! $isCurrentMonth ? 'bg-zinc-50/50 dark:bg-zinc-900/50' : '' }}
                                {{ $isToday ? 'bg-blue-50 dark:bg-blue-900/10' : '' }}"
                            wire:click="switchToWeekOf('{{ $dayKey }}')"
                        >
                            <div class="flex items-center justify-between mb-1">
                                <span class="text-sm font-medium {{ ! $isCurrentMonth ? 'text-zinc-400 dark:text-zinc-600' : '' }} {{ $isToday ? 'text-blue-600 dark:text-blue-400 font-bold' : '' }}">
                                    {{ $day->format('j') }}
                                </span>
                                @if ($daySchedules->count() > 0)
                                    <flux:badge size="sm" color="{{ $isToday ? 'blue' : 'zinc' }}">{{ $daySchedules->count() }}</flux:badge>
                                @endif
                            </div>
                            @if ($daySchedules->count() > 0)
                                <div class="space-y-0.5">
                                    @foreach ($daySchedules->take(3) as $schedule)
                                        <div class="text-xs truncate {{ match($schedule->status) {
                                            'confirmed' => 'text-green-600 dark:text-green-400',
                                            'completed' => 'text-zinc-500',
                                            default => 'text-blue-600 dark:text-blue-400',
                                        } }}">
                                            {{ Str::before($schedule->employeeProfile?->user?->name ?? '?', ' ') }}
                                            {{ \Carbon\Carbon::parse($schedule->start_time)->format('g:ia') }}
                                        </div>
                                    @endforeach
                                    @if ($daySchedules->count() > 3)
                                        <div class="text-xs text-zinc-400">+{{ $daySchedules->count() - 3 }} {{ __('more') }}</div>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    {{-- Create/Edit Modal --}}
    <flux:modal wire:model="showCreateModal" class="max-w-lg">
        <div class="space-y-4">
            <flux:heading size="lg">
                {{ $editingScheduleId ? __('Edit Shift') : __('New Shift') }}
            </flux:heading>

            <flux:field>
                <flux:label>{{ __('Employee') }}</flux:label>
                <flux:select wire:model="formEmployeeId">
                    <option value="">{{ __('Select employee...') }}</option>
                    @foreach ($this->employees as $emp)
                        <flux:select.option value="{{ $emp->id }}">{{ $emp->user?->name }} ({{ $emp->employee_number }})</flux:select.option>
                    @endforeach
                </flux:select>
                @error('formEmployeeId') <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text> @enderror
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Date') }}</flux:label>
                <flux:input type="date" wire:model="formDate" />
                @error('formDate') <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text> @enderror
            </flux:field>

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>{{ __('Start Time') }}</flux:label>
                    <flux:input type="time" wire:model="formStartTime" />
                    @error('formStartTime') <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text> @enderror
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('End Time') }}</flux:label>
                    <flux:input type="time" wire:model="formEndTime" />
                    @error('formEndTime') <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text> @enderror
                </flux:field>
            </div>

            <flux:field>
                <flux:label>{{ __('Break (minutes)') }}</flux:label>
                <flux:input type="number" wire:model="formBreakDuration" min="0" />
            </flux:field>

            @if ($editingScheduleId)
                <flux:field>
                    <flux:label>{{ __('Status') }}</flux:label>
                    <flux:select wire:model="formStatus">
                        <flux:select.option value="scheduled">{{ __('Scheduled') }}</flux:select.option>
                        <flux:select.option value="confirmed">{{ __('Confirmed') }}</flux:select.option>
                        <flux:select.option value="completed">{{ __('Completed') }}</flux:select.option>
                        <flux:select.option value="cancelled">{{ __('Cancelled') }}</flux:select.option>
                    </flux:select>
                </flux:field>
            @endif

            <flux:field>
                <flux:label>{{ __('Notes') }}</flux:label>
                <flux:textarea wire:model="formNotes" rows="2" />
            </flux:field>

            <div class="flex justify-between pt-2">
                @if ($editingScheduleId)
                    <flux:button variant="danger" size="sm" wire:click="deleteSchedule({{ $editingScheduleId }})" wire:confirm="{{ __('Delete this shift?') }}">
                        {{ __('Delete') }}
                    </flux:button>
                @else
                    <div></div>
                @endif
                <div class="flex gap-2">
                    <flux:button variant="ghost" wire:click="$set('showCreateModal', false)">{{ __('Cancel') }}</flux:button>
                    <flux:button variant="primary" wire:click="saveSchedule">
                        {{ $editingScheduleId ? __('Update') : __('Create') }}
                    </flux:button>
                </div>
            </div>
        </div>
    </flux:modal>
</div>
