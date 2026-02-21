<?php

use App\Models\SalesTerritory;
use App\Models\Schedule;
use App\Models\User;
use App\Notifications\TerritoryScheduleAssigned;
use App\Services\PermissionScopeService;
use App\Services\TerritoryDispatchService;
use Carbon\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $weekStart = '';

    // Schedule form
    public bool $showScheduleModal = false;
    public ?string $selectedDate = null;
    public ?int $selectedUserId = null;
    public ?int $selectedTerritoryId = null;
    public string $startTime = '08:00';
    public string $endTime = '16:00';
    public ?string $meetAddress = null;
    public ?float $meetLat = null;
    public ?float $meetLng = null;
    public ?string $scheduleNotes = null;
    public array $selectedUserIds = [];
    public bool $isFlex = false;

    public function mount(): void
    {
        $this->weekStart = now()->startOfWeek()->format('Y-m-d');
    }

    public function prevWeek(): void
    {
        $this->weekStart = Carbon::parse($this->weekStart)->subWeek()->format('Y-m-d');
        unset($this->weekSchedules);
    }

    public function nextWeek(): void
    {
        $this->weekStart = Carbon::parse($this->weekStart)->addWeek()->format('Y-m-d');
        unset($this->weekSchedules);
    }

    #[Computed]
    public function weekDays(): array
    {
        $start = Carbon::parse($this->weekStart);

        return collect(range(0, 6))->map(fn ($i) => $start->copy()->addDays($i))->toArray();
    }

    #[Computed]
    public function managedBlocks(): \Illuminate\Support\Collection
    {
        $user = auth()->user();
        $scopeService = app(PermissionScopeService::class);
        $visibleIds = $scopeService->getVisibleTerritoryIds($user);

        return SalesTerritory::whereIn('id', $visibleIds)
            ->where('level', 'block')
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'color']);
    }

    #[Computed]
    public function teamMembers(): \Illuminate\Support\Collection
    {
        $user = auth()->user();
        $scopeService = app(PermissionScopeService::class);
        $visibleIds = $scopeService->getVisibleTerritoryIds($user);

        return User::whereHas('territoryTeams', function ($q) use ($visibleIds) {
            $q->whereIn('sales_territories.id', $visibleIds);
        })
            ->where('is_active', true)
            ->with('employeeProfile')
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function weekSchedules(): \Illuminate\Support\Collection
    {
        $start = Carbon::parse($this->weekStart);
        $end = $start->copy()->addDays(6);
        $teamMemberProfileIds = $this->teamMembers
            ->pluck('employeeProfile.id')
            ->filter()
            ->toArray();

        $managedBlockIds = $this->managedBlocks->pluck('id')->toArray();

        // Assigned schedules for team members
        $assigned = empty($teamMemberProfileIds) ? collect() : Schedule::territoryShifts()
            ->whereIn('employee_profile_id', $teamMemberProfileIds)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->with(['territory', 'employeeProfile.user'])
            ->get();

        // Unclaimed flex blocks for visible territories
        $flex = Schedule::territoryShifts()
            ->flexBlocks()
            ->whereNull('employee_profile_id')
            ->whereIn('territory_id', $managedBlockIds)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->with(['territory'])
            ->get();

        return $assigned->merge($flex);
    }

    public function openScheduleModal(?string $date = null, ?int $userId = null): void
    {
        $this->reset(['selectedTerritoryId', 'startTime', 'endTime', 'meetAddress', 'meetLat', 'meetLng', 'scheduleNotes', 'selectedUserIds', 'isFlex']);
        $this->selectedDate = $date;
        $this->selectedUserId = $userId;
        $this->startTime = '08:00';
        $this->endTime = '16:00';
        if ($userId) {
            $this->selectedUserIds = [$userId];
        }
        $this->showScheduleModal = true;
    }

    public function openScheduleModalWithBlock(string $date, int $userId, int $blockId): void
    {
        $this->openScheduleModal($date, $userId);
        $this->selectedTerritoryId = $blockId;
    }

    public function saveSchedule(): void
    {
        $rules = [
            'selectedDate' => 'required|date',
            'selectedTerritoryId' => 'required|exists:sales_territories,id',
            'startTime' => 'required',
            'endTime' => 'required',
        ];

        if (! $this->isFlex) {
            $rules['selectedUserIds'] = 'required|array|min:1';
            $rules['selectedUserIds.*'] = 'exists:users,id';
        }

        $this->validate($rules);

        $creator = auth()->user();

        if ($this->isFlex) {
            Schedule::create([
                'employee_profile_id' => null,
                'schedule_type' => 'territory',
                'territory_id' => $this->selectedTerritoryId,
                'date' => $this->selectedDate,
                'start_time' => $this->startTime,
                'end_time' => $this->endTime,
                'meet_address' => $this->meetAddress,
                'meet_lat' => $this->meetLat,
                'meet_lng' => $this->meetLng,
                'notes' => $this->scheduleNotes,
                'created_by' => $creator->id,
                'is_flex' => true,
            ]);

            $this->showScheduleModal = false;
            $this->isFlex = false;
            unset($this->weekSchedules);
            session()->flash('success', __('Flex block created.'));

            return;
        }

        foreach ($this->selectedUserIds as $userId) {
            $user = User::find($userId);
            if (! $user?->employeeProfile) {
                continue;
            }

            $schedule = Schedule::create([
                'employee_profile_id' => $user->employeeProfile->id,
                'schedule_type' => 'territory',
                'territory_id' => $this->selectedTerritoryId,
                'date' => $this->selectedDate,
                'start_time' => $this->startTime,
                'end_time' => $this->endTime,
                'meet_address' => $this->meetAddress,
                'meet_lat' => $this->meetLat,
                'meet_lng' => $this->meetLng,
                'notes' => $this->scheduleNotes,
                'created_by' => $creator->id,
            ]);

            $user->notify(new TerritoryScheduleAssigned($schedule));
        }

        $this->showScheduleModal = false;
        unset($this->weekSchedules);
        session()->flash('success', __('Schedule created for :count team members.', ['count' => count($this->selectedUserIds)]));
    }

    public function claimFlexBlock(int $scheduleId): void
    {
        $schedule = Schedule::findOrFail($scheduleId);
        $service = app(TerritoryDispatchService::class);
        $result = $service->claimFlexBlock($schedule, auth()->user());

        if ($result) {
            session()->flash('success', __('Flex block claimed!'));
        } else {
            session()->flash('error', __('This flex block has already been claimed.'));
        }

        unset($this->weekSchedules);
    }

    public function deleteSchedule(int $scheduleId): void
    {
        Schedule::findOrFail($scheduleId)->delete();
        unset($this->weekSchedules);
    }

    public function copyWeekForward(): void
    {
        $currentSchedules = $this->weekSchedules;
        $nextStart = Carbon::parse($this->weekStart)->addWeek();
        $creator = auth()->user();

        foreach ($currentSchedules as $schedule) {
            $newDate = Carbon::parse($schedule->date)->addWeek();
            $user = $schedule->employeeProfile?->user;

            $newSchedule = Schedule::create([
                'employee_profile_id' => $schedule->employee_profile_id,
                'schedule_type' => 'territory',
                'territory_id' => $schedule->territory_id,
                'date' => $newDate->toDateString(),
                'start_time' => $schedule->start_time,
                'end_time' => $schedule->end_time,
                'meet_address' => $schedule->meet_address,
                'meet_lat' => $schedule->meet_lat,
                'meet_lng' => $schedule->meet_lng,
                'notes' => $schedule->notes,
                'created_by' => $creator->id,
            ]);

            if ($user) {
                $user->notify(new TerritoryScheduleAssigned($newSchedule));
            }
        }

        $this->weekStart = $nextStart->format('Y-m-d');
        unset($this->weekSchedules);
        session()->flash('success', __('Week schedule copied forward.'));
    }
};

?>

<div>
    @php
        $start = \Carbon\Carbon::parse($weekStart);
        $end = $start->copy()->addDays(6);
    @endphp

    {{-- Week Navigation Header --}}
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-3">
            <flux:button variant="ghost" size="sm" icon="chevron-left" wire:click="prevWeek" />
            <flux:heading size="lg">
                {{ $start->format('M j') }} - {{ $end->format('M j, Y') }}
            </flux:heading>
            <flux:button variant="ghost" size="sm" icon="chevron-right" wire:click="nextWeek" />
        </div>
        <div class="flex items-center gap-2">
            <flux:button size="sm" wire:click="copyWeekForward" wire:confirm="{{ __('Copy all schedules from this week to next week?') }}">
                <flux:icon name="document-duplicate" class="size-4 mr-1" />
                {{ __('Copy Week Forward') }}
            </flux:button>
            <flux:button variant="primary" size="sm" wire:click="openScheduleModal">
                <flux:icon name="plus" class="size-4 mr-1" />
                {{ __('New Schedule') }}
            </flux:button>
        </div>
    </div>

    @if($this->teamMembers->isEmpty())
        <flux:callout icon="user-group" variant="outline">
            <flux:callout.heading>{{ __('No Team Members') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Add team members to territory blocks to start scheduling. Go to the Hierarchy tab to manage team assignments.') }}</flux:callout.text>
        </flux:callout>
    @else
        {{-- Block Sidebar + Weekly Grid --}}
        <div class="flex gap-4">
            {{-- Block Sidebar --}}
            <div class="hidden lg:block w-48 shrink-0 space-y-2">
                <flux:text size="sm" class="font-medium text-zinc-500 mb-2">{{ __('Drag blocks to schedule') }}</flux:text>
                @foreach($this->managedBlocks as $block)
                    <div wire:key="sidebar-block-{{ $block->id }}"
                         draggable="true"
                         x-data
                         x-on:dragstart="$event.dataTransfer.setData('text/plain', '{{ $block->id }}'); $event.dataTransfer.effectAllowed = 'copy';"
                         class="flex items-center gap-2 rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-2 cursor-grab active:cursor-grabbing hover:shadow-sm transition-shadow">
                        <div class="h-3 w-3 rounded-full shrink-0" style="background-color: {{ $block->color ?? '#3B82F6' }}"></div>
                        <span class="text-xs font-medium text-zinc-700 dark:text-zinc-300 truncate">{{ $block->name }}</span>
                    </div>
                @endforeach
                @if($this->managedBlocks->isEmpty())
                    <flux:text size="xs" class="text-zinc-400">{{ __('No blocks available') }}</flux:text>
                @endif
            </div>

            {{-- Weekly Grid Calendar --}}
            <div class="flex-1 overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700">
            <table class="w-full min-w-[800px]">
                {{-- Day Headers --}}
                <thead>
                    <tr class="bg-zinc-50 dark:bg-zinc-800/50">
                        <th class="w-40 px-3 py-2 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 border-b border-zinc-200 dark:border-zinc-700">
                            {{ __('Team Member') }}
                        </th>
                        @foreach($this->weekDays as $day)
                            @php $dayCarbon = \Carbon\Carbon::parse($day); @endphp
                            <th class="px-2 py-2 text-center text-xs font-medium border-b border-l border-zinc-200 dark:border-zinc-700 {{ $dayCarbon->isToday() ? 'bg-blue-50 dark:bg-blue-900/20 text-blue-600 dark:text-blue-400' : 'text-zinc-500 dark:text-zinc-400' }}">
                                <div>{{ $dayCarbon->format('D') }}</div>
                                <div class="text-lg font-semibold {{ $dayCarbon->isToday() ? 'text-blue-600 dark:text-blue-400' : 'text-zinc-700 dark:text-zinc-300' }}">{{ $dayCarbon->format('j') }}</div>
                            </th>
                        @endforeach
                    </tr>
                </thead>

                {{-- Team Member Rows --}}
                <tbody>
                    @foreach($this->teamMembers as $member)
                        <tr wire:key="member-row-{{ $member->id }}" class="border-b border-zinc-200 dark:border-zinc-700 last:border-b-0">
                            <td class="px-3 py-2 align-top">
                                <div class="flex items-center gap-2">
                                    <flux:avatar size="xs" name="{{ $member->name }}" />
                                    <div>
                                        <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100 truncate max-w-[120px]">{{ $member->name }}</div>
                                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $member->employeeProfile?->job_title ?? '' }}</div>
                                    </div>
                                </div>
                            </td>
                            @foreach($this->weekDays as $day)
                                @php
                                    $dayStr = \Carbon\Carbon::parse($day)->format('Y-m-d');
                                    $daySchedules = $this->weekSchedules->filter(function ($s) use ($member, $dayStr) {
                                        return $s->employee_profile_id === $member->employeeProfile?->id
                                            && $s->date->format('Y-m-d') === $dayStr;
                                    });
                                    $isToday = \Carbon\Carbon::parse($day)->isToday();
                                @endphp
                                <td wire:key="cell-{{ $member->id }}-{{ $dayStr }}"
                                    x-data
                                    x-on:dragover.prevent="$el.classList.add('bg-blue-50', 'dark:bg-blue-900/20')"
                                    x-on:dragleave="$el.classList.remove('bg-blue-50', 'dark:bg-blue-900/20')"
                                    x-on:drop.prevent="$el.classList.remove('bg-blue-50', 'dark:bg-blue-900/20'); $wire.openScheduleModalWithBlock('{{ $dayStr }}', {{ $member->id }}, parseInt($event.dataTransfer.getData('text/plain')))"
                                    class="px-1 py-1 align-top border-l border-zinc-200 dark:border-zinc-700 min-h-[60px] cursor-pointer hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition-colors {{ $isToday ? 'bg-blue-50/50 dark:bg-blue-900/10' : '' }}"
                                    wire:click="openScheduleModal('{{ $dayStr }}', {{ $member->id }})">
                                    <div class="space-y-1 min-h-[50px]">
                                        @foreach($daySchedules as $schedule)
                                            <div wire:key="sched-{{ $schedule->id }}"
                                                 class="group relative rounded-md px-2 py-1 text-xs"
                                                 style="background-color: {{ ($schedule->territory?->color ?? '#3B82F6') }}20; border-left: 3px solid {{ $schedule->territory?->color ?? '#3B82F6' }};"
                                                 wire:click.stop>
                                                <div class="font-medium text-zinc-800 dark:text-zinc-200 truncate">
                                                    {{ $schedule->territory?->name ?? __('Unknown') }}
                                                </div>
                                                <div class="text-zinc-500 dark:text-zinc-400">
                                                    {{ \Carbon\Carbon::parse($schedule->start_time)->format('g:ia') }} - {{ \Carbon\Carbon::parse($schedule->end_time)->format('g:ia') }}
                                                </div>
                                                @if($schedule->meet_address)
                                                    <div class="text-zinc-400 dark:text-zinc-500 truncate">
                                                        {{ $schedule->meet_address }}
                                                    </div>
                                                @endif
                                                <button wire:click.stop="deleteSchedule({{ $schedule->id }})"
                                                        wire:confirm="{{ __('Delete this schedule?') }}"
                                                        class="absolute top-0.5 right-0.5 hidden group-hover:flex items-center justify-center size-5 rounded bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400 hover:bg-red-200 dark:hover:bg-red-900/50">
                                                    <flux:icon name="x-mark" class="size-3" />
                                                </button>
                                            </div>
                                        @endforeach
                                    </div>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach

                    {{-- Flex / Unclaimed Row --}}
                    @php
                        $flexSchedules = $this->weekSchedules->filter(fn ($s) => $s->is_flex && $s->employee_profile_id === null);
                    @endphp
                    @if($flexSchedules->isNotEmpty())
                        <tr wire:key="flex-row" class="border-b border-zinc-200 dark:border-zinc-700 bg-amber-50/30 dark:bg-amber-900/10">
                            <td class="px-3 py-2 align-top">
                                <div class="flex items-center gap-2">
                                    <div class="size-6 rounded-full bg-amber-100 dark:bg-amber-900/30 flex items-center justify-center">
                                        <flux:icon name="bolt" class="size-3.5 text-amber-600" />
                                    </div>
                                    <div>
                                        <div class="text-sm font-medium text-amber-700 dark:text-amber-400">{{ __('Flex / Unclaimed') }}</div>
                                        <div class="text-xs text-amber-500 dark:text-amber-600">{{ __('Open shifts') }}</div>
                                    </div>
                                </div>
                            </td>
                            @foreach($this->weekDays as $day)
                                @php
                                    $dayStr = \Carbon\Carbon::parse($day)->format('Y-m-d');
                                    $dayFlex = $flexSchedules->filter(fn ($s) => $s->date->format('Y-m-d') === $dayStr);
                                    $isToday = \Carbon\Carbon::parse($day)->isToday();
                                @endphp
                                <td wire:key="flex-cell-{{ $dayStr }}"
                                    class="px-1 py-1 align-top border-l border-zinc-200 dark:border-zinc-700 min-h-[60px] {{ $isToday ? 'bg-amber-50/50 dark:bg-amber-900/10' : '' }}">
                                    <div class="space-y-1 min-h-[50px]">
                                        @foreach($dayFlex as $schedule)
                                            <div wire:key="flex-sched-{{ $schedule->id }}"
                                                 class="group relative rounded-md px-2 py-1 text-xs border-2 border-dashed border-amber-300 dark:border-amber-700"
                                                 style="background-color: {{ ($schedule->territory?->color ?? '#F59E0B') }}15;">
                                                <div class="font-medium text-zinc-800 dark:text-zinc-200 truncate">
                                                    {{ $schedule->territory?->name ?? __('Unknown') }}
                                                </div>
                                                <div class="text-zinc-500 dark:text-zinc-400">
                                                    {{ \Carbon\Carbon::parse($schedule->start_time)->format('g:ia') }} - {{ \Carbon\Carbon::parse($schedule->end_time)->format('g:ia') }}
                                                </div>
                                                <button wire:click="claimFlexBlock({{ $schedule->id }})"
                                                        class="mt-1 w-full rounded bg-amber-500 hover:bg-amber-600 text-white text-xs py-0.5 font-medium transition-colors">
                                                    {{ __('Claim') }}
                                                </button>
                                                <button wire:click.stop="deleteSchedule({{ $schedule->id }})"
                                                        wire:confirm="{{ __('Delete this flex block?') }}"
                                                        class="absolute top-0.5 right-0.5 hidden group-hover:flex items-center justify-center size-5 rounded bg-red-100 dark:bg-red-900/30 text-red-600 dark:text-red-400 hover:bg-red-200 dark:hover:bg-red-900/50">
                                                    <flux:icon name="x-mark" class="size-3" />
                                                </button>
                                            </div>
                                        @endforeach
                                    </div>
                                </td>
                            @endforeach
                        </tr>
                    @endif
                </tbody>
            </table>
            </div>
        </div>
    @endif

    {{-- Schedule Modal --}}
    <flux:modal wire:model="showScheduleModal" class="max-w-lg">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Schedule Territory Shift') }}</flux:heading>
            <flux:text>{{ __('Assign team members to a territory block for a specific date and time.') }}</flux:text>

            <flux:field>
                <flux:label>{{ __('Date') }}</flux:label>
                <flux:input type="date" wire:model="selectedDate" />
                @error('selectedDate') <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text> @enderror
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Territory Block') }}</flux:label>
                <flux:select wire:model="selectedTerritoryId">
                    <option value="">{{ __('Select a block...') }}</option>
                    @foreach($this->managedBlocks as $block)
                        <option value="{{ $block->id }}">{{ $block->name }}</option>
                    @endforeach
                </flux:select>
                @error('selectedTerritoryId') <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text> @enderror
            </flux:field>

            <div class="grid grid-cols-2 gap-3">
                <flux:field>
                    <flux:label>{{ __('Start Time') }}</flux:label>
                    <flux:input type="time" wire:model="startTime" />
                    @error('startTime') <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text> @enderror
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('End Time') }}</flux:label>
                    <flux:input type="time" wire:model="endTime" />
                    @error('endTime') <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text> @enderror
                </flux:field>
            </div>

            <flux:field>
                <flux:label>{{ __('Meet-up Address') }}</flux:label>
                <flux:input wire:model="meetAddress" placeholder="{{ __('e.g. 123 Main St, Denver, CO') }}" />
            </flux:field>

            <flux:field>
                <flux:label>{{ __('Notes') }}</flux:label>
                <flux:textarea wire:model="scheduleNotes" rows="2" placeholder="{{ __('Optional notes for team members...') }}" />
            </flux:field>

            {{-- Flex Block Toggle --}}
            <div class="flex items-center justify-between">
                <div>
                    <flux:label>{{ __('Flex Block') }}</flux:label>
                    <flux:text size="sm" class="text-zinc-400">{{ __('Create as open shift anyone can claim') }}</flux:text>
                </div>
                <flux:switch wire:model.live="isFlex" />
            </div>

            {{-- Team Member Selection --}}
            @if(!$isFlex)
                <flux:field>
                    <flux:label>{{ __('Team Members') }}</flux:label>
                    @error('selectedUserIds') <flux:text class="text-red-500 text-sm">{{ $message }}</flux:text> @enderror
                    <div class="space-y-2 max-h-48 overflow-y-auto rounded-lg border border-zinc-200 dark:border-zinc-700 p-2">
                        @foreach($this->teamMembers as $member)
                            <label wire:key="member-check-{{ $member->id }}" class="flex items-center gap-2 p-1.5 rounded hover:bg-zinc-50 dark:hover:bg-zinc-800/50 cursor-pointer">
                                <input type="checkbox" wire:model="selectedUserIds" value="{{ $member->id }}"
                                       class="rounded border-zinc-300 text-blue-600 focus:ring-blue-500" />
                                <flux:avatar size="xs" name="{{ $member->name }}" />
                                <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ $member->name }}</span>
                            </label>
                        @endforeach
                    </div>
                </flux:field>
            @endif

            <div class="flex gap-2 pt-2">
                <flux:button variant="primary" wire:click="saveSchedule" class="grow">
                    <flux:icon name="calendar-days" class="size-4 mr-1" />
                    {{ __('Create Schedule') }}
                </flux:button>
                <flux:button variant="ghost" wire:click="$set('showScheduleModal', false)">
                    {{ __('Cancel') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
