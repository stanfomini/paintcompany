<?php

use App\Models\CompanySetting;
use App\Models\LifeCommitment;
use Carbon\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public string $viewMode = 'commitments';

    public int $calendarMonth;

    public int $calendarYear;

    public bool $showModal = false;

    public bool $showCalendarSync = false;

    public ?int $editingId = null;

    public string $area = '';

    public string $commitment = '';

    public string $action_type = 'ongoing';

    public bool $is_active = true;

    public ?string $start_date = null;

    /** @var array<int> */
    public array $recurrence_days = [];

    public ?string $recurrence_time = null;

    public ?int $recurrence_day_of_month = null;

    /** @var array<string, bool> */
    public array $collapsedAreas = [];

    public function mount(): void
    {
        $this->calendarMonth = now()->month;
        $this->calendarYear = now()->year;
    }

    public function boot(): void
    {
        $user = auth()->user();
        if (! $user || ! $user->can('business_plan.view')) {
            abort(403);
        }
    }

    #[Computed]
    public function commitments()
    {
        return LifeCommitment::query()
            ->where('company_setting_id', CompanySetting::getInstance()->id)
            ->ordered()
            ->get();
    }

    #[Computed]
    public function commitmentsByArea()
    {
        return $this->commitments->groupBy('area');
    }

    #[Computed]
    public function canManage(): bool
    {
        return auth()->user()?->can('business_plan.manage') ?? false;
    }

    #[Computed]
    public function calendarDays(): array
    {
        $firstOfMonth = Carbon::create($this->calendarYear, $this->calendarMonth, 1);
        $lastOfMonth = $firstOfMonth->copy()->endOfMonth();

        // Start from Monday of the week containing the 1st
        $startDate = $firstOfMonth->copy()->startOfWeek(Carbon::MONDAY);
        // End on Sunday of the week containing the last day
        $endDate = $lastOfMonth->copy()->endOfWeek(Carbon::SUNDAY);

        $days = [];
        $current = $startDate->copy();

        $activeCommitments = $this->commitments->where('is_active', true);

        while ($current->lte($endDate)) {
            $dayCommitments = [];

            foreach ($activeCommitments as $commitment) {
                if ($this->commitmentFallsOnDate($commitment, $current)) {
                    $dayCommitments[] = $commitment;
                }
            }

            $days[] = [
                'date' => $current->copy(),
                'day' => $current->day,
                'isCurrentMonth' => $current->month === $this->calendarMonth,
                'isToday' => $current->isToday(),
                'commitments' => $dayCommitments,
            ];

            $current->addDay();
        }

        return $days;
    }

    public function openCreateModal(): void
    {
        $this->authorize('business_plan.manage');

        $this->resetForm();
        $this->showModal = true;
    }

    public function openEditModal(int $id): void
    {
        $this->authorize('business_plan.manage');

        $commitment = LifeCommitment::findOrFail($id);

        $this->editingId = $commitment->id;
        $this->area = $commitment->area;
        $this->commitment = $commitment->commitment;
        $this->action_type = $commitment->action_type;
        $this->is_active = $commitment->is_active;
        $this->start_date = $commitment->start_date?->format('Y-m-d');
        $this->recurrence_days = $commitment->recurrence_days ?? [];
        $this->recurrence_time = $commitment->recurrence_time;
        $this->recurrence_day_of_month = $commitment->recurrence_day_of_month;
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->authorize('business_plan.manage');

        $this->validate([
            'area' => ['required', 'string', 'max:255'],
            'commitment' => ['required', 'string', 'max:5000'],
            'action_type' => ['required', 'string', 'in:daily,weekly,monthly,quarterly,annual,ongoing'],
            'is_active' => ['boolean'],
            'start_date' => ['nullable', 'date'],
            'recurrence_days' => ['nullable', 'array'],
            'recurrence_days.*' => ['integer', 'min:1', 'max:7'],
            'recurrence_time' => ['nullable', 'string', 'regex:/^\d{1,2}:\d{2}$/'],
            'recurrence_day_of_month' => ['nullable', 'integer', 'min:1', 'max:31'],
        ]);

        $companySettingId = CompanySetting::getInstance()->id;

        $data = [
            'area' => $this->area,
            'commitment' => $this->commitment,
            'action_type' => $this->action_type,
            'is_active' => $this->is_active,
            'start_date' => $this->start_date,
            'recurrence_days' => $this->action_type === 'weekly' && ! empty($this->recurrence_days) ? $this->recurrence_days : null,
            'recurrence_time' => in_array($this->action_type, ['daily', 'weekly', 'monthly', 'quarterly', 'annual']) ? $this->recurrence_time : null,
            'recurrence_day_of_month' => in_array($this->action_type, ['monthly', 'quarterly']) ? $this->recurrence_day_of_month : null,
        ];

        if ($this->editingId) {
            $record = LifeCommitment::findOrFail($this->editingId);
            $record->update($data);
        } else {
            $maxSort = LifeCommitment::where('company_setting_id', $companySettingId)->max('sort_order') ?? -1;

            LifeCommitment::create(array_merge($data, [
                'company_setting_id' => $companySettingId,
                'sort_order' => $maxSort + 1,
            ]));
        }

        $this->showModal = false;
        $this->resetForm();
        unset($this->commitments, $this->commitmentsByArea, $this->calendarDays);
    }

    public function delete(int $id): void
    {
        $this->authorize('business_plan.manage');

        LifeCommitment::findOrFail($id)->delete();
        unset($this->commitments, $this->commitmentsByArea, $this->calendarDays);
    }

    public function toggleActive(int $id): void
    {
        $this->authorize('business_plan.manage');

        $commitment = LifeCommitment::findOrFail($id);
        $commitment->update(['is_active' => ! $commitment->is_active]);
        unset($this->commitments, $this->commitmentsByArea, $this->calendarDays);
    }

    public function moveUp(int $id): void
    {
        $this->authorize('business_plan.manage');

        $commitment = LifeCommitment::findOrFail($id);
        $companySettingId = CompanySetting::getInstance()->id;

        $previous = LifeCommitment::query()
            ->where('company_setting_id', $companySettingId)
            ->where('sort_order', '<', $commitment->sort_order)
            ->orderByDesc('sort_order')
            ->first();

        if ($previous) {
            $tempSort = $commitment->sort_order;
            $commitment->update(['sort_order' => $previous->sort_order]);
            $previous->update(['sort_order' => $tempSort]);
            unset($this->commitments, $this->commitmentsByArea);
        }
    }

    public function moveDown(int $id): void
    {
        $this->authorize('business_plan.manage');

        $commitment = LifeCommitment::findOrFail($id);
        $companySettingId = CompanySetting::getInstance()->id;

        $next = LifeCommitment::query()
            ->where('company_setting_id', $companySettingId)
            ->where('sort_order', '>', $commitment->sort_order)
            ->orderBy('sort_order')
            ->first();

        if ($next) {
            $tempSort = $commitment->sort_order;
            $commitment->update(['sort_order' => $next->sort_order]);
            $next->update(['sort_order' => $tempSort]);
            unset($this->commitments, $this->commitmentsByArea);
        }
    }

    public function previousMonth(): void
    {
        $date = Carbon::create($this->calendarYear, $this->calendarMonth, 1)->subMonth();
        $this->calendarMonth = $date->month;
        $this->calendarYear = $date->year;
        unset($this->calendarDays);
    }

    public function nextMonth(): void
    {
        $date = Carbon::create($this->calendarYear, $this->calendarMonth, 1)->addMonth();
        $this->calendarMonth = $date->month;
        $this->calendarYear = $date->year;
        unset($this->calendarDays);
    }

    public function toggleArea(string $area): void
    {
        $this->collapsedAreas[$area] = ! ($this->collapsedAreas[$area] ?? false);
    }

    public function toggleDay(int $day): void
    {
        if (in_array($day, $this->recurrence_days)) {
            $this->recurrence_days = array_values(array_filter(
                $this->recurrence_days,
                fn ($d) => $d !== $day
            ));
        } else {
            $this->recurrence_days[] = $day;
            sort($this->recurrence_days);
        }
    }

    public function generateCalendarUrl(): string
    {
        $token = auth()->user()->getOrCreateCalendarToken();

        return route('calendar.feed', ['token' => $token]);
    }

    protected function resetForm(): void
    {
        $this->editingId = null;
        $this->area = '';
        $this->commitment = '';
        $this->action_type = 'ongoing';
        $this->is_active = true;
        $this->start_date = null;
        $this->recurrence_days = [];
        $this->recurrence_time = null;
        $this->recurrence_day_of_month = null;
        $this->resetValidation();
    }

    protected function actionTypeLabel(string $type): string
    {
        return match ($type) {
            'daily' => __('Daily'),
            'weekly' => __('Weekly'),
            'monthly' => __('Monthly'),
            'quarterly' => __('Quarterly'),
            'annual' => __('Annual'),
            'ongoing' => __('Ongoing'),
            default => ucfirst($type),
        };
    }

    protected function actionTypeBadgeColor(string $type): string
    {
        return match ($type) {
            'daily' => 'green',
            'weekly' => 'blue',
            'monthly' => 'purple',
            'quarterly' => 'amber',
            'annual' => 'red',
            'ongoing' => 'zinc',
            default => 'zinc',
        };
    }

    protected function areaConfig(string $area): array
    {
        $normalized = strtolower(trim($area));

        return match (true) {
            str_contains($normalized, 'physical') || str_contains($normalized, 'health') && ! str_contains($normalized, 'mental') => [
                'color' => 'green',
                'bgColor' => 'bg-green-50 dark:bg-green-950/30',
                'borderColor' => 'border-green-400 dark:border-green-600',
                'textColor' => 'text-green-700 dark:text-green-400',
                'dotColor' => 'bg-green-500',
                'icon' => 'heart',
            ],
            str_contains($normalized, 'mental') => [
                'color' => 'teal',
                'bgColor' => 'bg-teal-50 dark:bg-teal-950/30',
                'borderColor' => 'border-teal-400 dark:border-teal-600',
                'textColor' => 'text-teal-700 dark:text-teal-400',
                'dotColor' => 'bg-teal-500',
                'icon' => 'sparkles',
            ],
            str_contains($normalized, 'family') => [
                'color' => 'blue',
                'bgColor' => 'bg-blue-50 dark:bg-blue-950/30',
                'borderColor' => 'border-blue-400 dark:border-blue-600',
                'textColor' => 'text-blue-700 dark:text-blue-400',
                'dotColor' => 'bg-blue-500',
                'icon' => 'users',
            ],
            str_contains($normalized, 'business') => [
                'color' => 'purple',
                'bgColor' => 'bg-purple-50 dark:bg-purple-950/30',
                'borderColor' => 'border-purple-400 dark:border-purple-600',
                'textColor' => 'text-purple-700 dark:text-purple-400',
                'dotColor' => 'bg-purple-500',
                'icon' => 'briefcase',
            ],
            str_contains($normalized, 'financial') || str_contains($normalized, 'finance') => [
                'color' => 'amber',
                'bgColor' => 'bg-amber-50 dark:bg-amber-950/30',
                'borderColor' => 'border-amber-400 dark:border-amber-600',
                'textColor' => 'text-amber-700 dark:text-amber-400',
                'dotColor' => 'bg-amber-500',
                'icon' => 'currency-dollar',
            ],
            str_contains($normalized, 'spiritual') => [
                'color' => 'indigo',
                'bgColor' => 'bg-indigo-50 dark:bg-indigo-950/30',
                'borderColor' => 'border-indigo-400 dark:border-indigo-600',
                'textColor' => 'text-indigo-700 dark:text-indigo-400',
                'dotColor' => 'bg-indigo-500',
                'icon' => 'sun',
            ],
            str_contains($normalized, 'social') => [
                'color' => 'pink',
                'bgColor' => 'bg-pink-50 dark:bg-pink-950/30',
                'borderColor' => 'border-pink-400 dark:border-pink-600',
                'textColor' => 'text-pink-700 dark:text-pink-400',
                'dotColor' => 'bg-pink-500',
                'icon' => 'chat-bubble-left-right',
            ],
            str_contains($normalized, 'education') || str_contains($normalized, 'learning') => [
                'color' => 'cyan',
                'bgColor' => 'bg-cyan-50 dark:bg-cyan-950/30',
                'borderColor' => 'border-cyan-400 dark:border-cyan-600',
                'textColor' => 'text-cyan-700 dark:text-cyan-400',
                'dotColor' => 'bg-cyan-500',
                'icon' => 'academic-cap',
            ],
            default => [
                'color' => 'zinc',
                'bgColor' => 'bg-zinc-50 dark:bg-zinc-800/30',
                'borderColor' => 'border-zinc-400 dark:border-zinc-600',
                'textColor' => 'text-zinc-700 dark:text-zinc-400',
                'dotColor' => 'bg-zinc-500',
                'icon' => 'star',
            ],
        };
    }

    protected function commitmentFallsOnDate(LifeCommitment $commitment, Carbon $date): bool
    {
        $startDate = Carbon::parse($commitment->start_date ?? today());

        // Don't show before start date
        if ($date->lt($startDate->startOfDay())) {
            return false;
        }

        return match ($commitment->action_type) {
            'daily' => true,
            'weekly' => ! empty($commitment->recurrence_days)
                ? in_array($date->dayOfWeekIso, $commitment->recurrence_days)
                : $date->dayOfWeekIso === 1,
            'monthly' => $date->day === ($commitment->recurrence_day_of_month ?? 1),
            'quarterly' => $date->day === ($commitment->recurrence_day_of_month ?? 1)
                && in_array($date->month, [1, 4, 7, 10]),
            'annual' => $date->month === $startDate->month && $date->day === $startDate->day,
            'ongoing' => true,
            default => false,
        };
    }

    protected function dayOfWeekLabel(int $day): string
    {
        return match ($day) {
            1 => __('Monday'),
            2 => __('Tuesday'),
            3 => __('Wednesday'),
            4 => __('Thursday'),
            5 => __('Friday'),
            6 => __('Saturday'),
            7 => __('Sunday'),
            default => __('Monday'),
        };
    }

    protected function dayOfWeekShort(int $day): string
    {
        return match ($day) {
            1 => __('Mon'),
            2 => __('Tue'),
            3 => __('Wed'),
            4 => __('Thu'),
            5 => __('Fri'),
            6 => __('Sat'),
            7 => __('Sun'),
            default => __('Mon'),
        };
    }

    protected function formatRecurrenceDays(array $days): string
    {
        return implode(', ', array_map(fn ($d) => $this->dayOfWeekShort($d), $days));
    }
};
?>

<div>
    {{-- Header --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="lg">{{ __('Life Commitments') }}</flux:heading>
            <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
                {{ __('Track personal and business commitments by area of life.') }}
            </flux:text>
        </div>

        <div class="flex items-center gap-2">
            {{-- View Mode Toggle --}}
            <div class="inline-flex rounded-lg bg-zinc-100 p-0.5 dark:bg-zinc-800">
                <button
                    wire:click="$set('viewMode', 'commitments')"
                    class="rounded-md px-3 py-1.5 text-sm font-medium transition-colors {{ $viewMode === 'commitments' ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-700 dark:text-zinc-100' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-200' }}"
                >
                    {{ __('Commitments') }}
                </button>
                <button
                    wire:click="$set('viewMode', 'calendar')"
                    class="rounded-md px-3 py-1.5 text-sm font-medium transition-colors {{ $viewMode === 'calendar' ? 'bg-white text-zinc-900 shadow-sm dark:bg-zinc-700 dark:text-zinc-100' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-200' }}"
                >
                    {{ __('Calendar') }}
                </button>
            </div>

            {{-- Calendar Sync --}}
            <flux:button wire:click="$set('showCalendarSync', true)" variant="ghost" size="sm" icon="calendar-days" />

            @if ($this->canManage)
                <flux:button wire:click="openCreateModal" variant="primary" icon="plus" size="sm">
                    {{ __('Add Commitment') }}
                </flux:button>
            @endif
        </div>
    </div>

    @if ($viewMode === 'commitments')
        {{-- Commitments View (Card-based, grouped by area) --}}
        @if ($this->commitments->isEmpty())
            <flux:callout variant="outline" icon="heart">
                <flux:callout.heading>{{ __('No commitments yet') }}</flux:callout.heading>
                <flux:callout.text>{{ __('Add your first life commitment to start tracking your personal and business goals.') }}</flux:callout.text>
            </flux:callout>
        @else
            <div class="space-y-4">
                @foreach ($this->commitmentsByArea as $area => $items)
                    @php $config = $this->areaConfig($area); @endphp
                    <div class="overflow-hidden rounded-lg border {{ $config['borderColor'] }} border-l-4" wire:key="area-{{ md5($area) }}">
                        {{-- Area Header --}}
                        <button
                            wire:click="toggleArea('{{ $area }}')"
                            class="flex w-full items-center justify-between {{ $config['bgColor'] }} px-4 py-3 transition-colors hover:opacity-80"
                        >
                            <div class="flex items-center gap-3">
                                <flux:icon :name="$config['icon']" class="{{ $config['textColor'] }}" variant="mini" />
                                <span class="font-semibold {{ $config['textColor'] }}">{{ $area }}</span>
                                <flux:badge size="sm" :color="$config['color']" inset="top bottom">
                                    {{ $items->count() }}
                                </flux:badge>
                            </div>
                            <flux:icon
                                name="chevron-down"
                                class="{{ $config['textColor'] }} transition-transform {{ ($collapsedAreas[$area] ?? false) ? '-rotate-90' : '' }}"
                                variant="mini"
                            />
                        </button>

                        {{-- Commitments List --}}
                        @unless ($collapsedAreas[$area] ?? false)
                            <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                                @foreach ($items as $item)
                                    <div
                                        wire:key="commitment-{{ $item->id }}"
                                        class="flex items-center gap-4 px-4 py-3 {{ ! $item->is_active ? 'opacity-50' : '' }}"
                                    >
                                        {{-- Active Toggle --}}
                                        @if ($this->canManage)
                                            <button
                                                wire:click="toggleActive({{ $item->id }})"
                                                class="shrink-0"
                                                title="{{ $item->is_active ? __('Pause') : __('Activate') }}"
                                            >
                                                <div class="flex h-5 w-5 items-center justify-center rounded-full {{ $item->is_active ? $config['dotColor'] : 'bg-zinc-300 dark:bg-zinc-600' }}">
                                                    @if ($item->is_active)
                                                        <flux:icon name="check" class="text-white" variant="micro" />
                                                    @else
                                                        <flux:icon name="pause" class="text-white" variant="micro" />
                                                    @endif
                                                </div>
                                            </button>
                                        @else
                                            <div class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full {{ $item->is_active ? $config['dotColor'] : 'bg-zinc-300 dark:bg-zinc-600' }}">
                                                @if ($item->is_active)
                                                    <flux:icon name="check" class="text-white" variant="micro" />
                                                @endif
                                            </div>
                                        @endif

                                        {{-- Commitment Text --}}
                                        <div class="min-w-0 flex-1">
                                            <p class="text-sm text-zinc-900 dark:text-zinc-100 {{ ! $item->is_active ? 'line-through' : '' }}">
                                                {{ $item->commitment }}
                                            </p>
                                            <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                                                @if ($item->start_date)
                                                    {{ __('Starts') }} {{ $item->start_date->format('M j, Y') }}
                                                @endif
                                                @if ($item->action_type === 'weekly' && ! empty($item->recurrence_days))
                                                    @if ($item->start_date) &middot; @endif
                                                    {{ __('Every') }} {{ $this->formatRecurrenceDays($item->recurrence_days) }}
                                                @elseif (in_array($item->action_type, ['monthly', 'quarterly']) && $item->recurrence_day_of_month)
                                                    @if ($item->start_date) &middot; @endif
                                                    {{ __('Day') }} {{ $item->recurrence_day_of_month }} {{ __('of month') }}
                                                @endif
                                                @if ($item->recurrence_time)
                                                    @if ($item->start_date || ($item->action_type === 'weekly' && ! empty($item->recurrence_days)) || (in_array($item->action_type, ['monthly', 'quarterly']) && $item->recurrence_day_of_month))
                                                        &middot;
                                                    @endif
                                                    {{ \Carbon\Carbon::createFromFormat('H:i', $item->recurrence_time)->format('g:i A') }}
                                                @endif
                                            </p>
                                        </div>

                                        {{-- Action Type Badge --}}
                                        <flux:badge size="sm" :color="$this->actionTypeBadgeColor($item->action_type)" inset="top bottom">
                                            {{ $this->actionTypeLabel($item->action_type) }}
                                        </flux:badge>

                                        {{-- Actions --}}
                                        @if ($this->canManage)
                                            <div class="flex shrink-0 items-center gap-0.5">
                                                <flux:button wire:click="moveUp({{ $item->id }})" variant="ghost" size="sm" icon="chevron-up" />
                                                <flux:button wire:click="moveDown({{ $item->id }})" variant="ghost" size="sm" icon="chevron-down" />
                                                <flux:button wire:click="openEditModal({{ $item->id }})" variant="ghost" size="sm" icon="pencil-square" />
                                                <flux:button wire:click="delete({{ $item->id }})" wire:confirm="{{ __('Are you sure you want to delete this commitment?') }}" variant="ghost" size="sm" icon="trash" class="text-red-500 hover:text-red-700" />
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endunless
                    </div>
                @endforeach
            </div>
        @endif
    @else
        {{-- Calendar View --}}
        <div class="rounded-lg border border-zinc-200 dark:border-zinc-700">
            {{-- Calendar Header --}}
            <div class="flex items-center justify-between border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                <flux:button wire:click="previousMonth" variant="ghost" size="sm" icon="chevron-left" />
                <flux:heading size="lg">
                    {{ Carbon::create($calendarYear, $calendarMonth, 1)->format('F Y') }}
                </flux:heading>
                <flux:button wire:click="nextMonth" variant="ghost" size="sm" icon="chevron-right" />
            </div>

            {{-- Day Headers --}}
            <div class="grid grid-cols-7 border-b border-zinc-200 dark:border-zinc-700">
                @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $dayName)
                    <div class="px-2 py-2 text-center text-xs font-medium text-zinc-500 dark:text-zinc-400">
                        {{ $dayName }}
                    </div>
                @endforeach
            </div>

            {{-- Calendar Grid --}}
            <div class="grid grid-cols-7">
                @foreach ($this->calendarDays as $i => $day)
                    <div
                        class="min-h-[80px] border-b border-r border-zinc-100 p-1.5 dark:border-zinc-800 {{ ! $day['isCurrentMonth'] ? 'bg-zinc-50 dark:bg-zinc-900/50' : '' }} {{ $day['isToday'] ? 'bg-blue-50 dark:bg-blue-950/30' : '' }}"
                        wire:key="cal-day-{{ $i }}"
                    >
                        {{-- Day Number --}}
                        <div class="mb-1 text-xs {{ $day['isToday'] ? 'font-bold text-blue-600 dark:text-blue-400' : ($day['isCurrentMonth'] ? 'text-zinc-700 dark:text-zinc-300' : 'text-zinc-400 dark:text-zinc-600') }}">
                            {{ $day['day'] }}
                        </div>

                        {{-- Commitment Dots --}}
                        @if (count($day['commitments']) > 0)
                            <div class="flex flex-wrap gap-0.5">
                                @foreach (array_slice($day['commitments'], 0, 4) as $commitment)
                                    @php $config = $this->areaConfig($commitment->area); @endphp
                                    <div
                                        class="h-2 w-2 rounded-full {{ $config['dotColor'] }}"
                                        title="{{ $commitment->area }}: {{ $commitment->commitment }}"
                                    ></div>
                                @endforeach
                                @if (count($day['commitments']) > 4)
                                    <span class="text-[10px] text-zinc-500">+{{ count($day['commitments']) - 4 }}</span>
                                @endif
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            {{-- Calendar Legend --}}
            @if ($this->commitments->where('is_active', true)->isNotEmpty())
                <div class="flex flex-wrap gap-4 border-t border-zinc-200 px-4 py-3 dark:border-zinc-700">
                    @foreach ($this->commitments->where('is_active', true)->pluck('area')->unique() as $area)
                        @php $config = $this->areaConfig($area); @endphp
                        <div class="flex items-center gap-1.5">
                            <div class="h-2.5 w-2.5 rounded-full {{ $config['dotColor'] }}"></div>
                            <span class="text-xs text-zinc-600 dark:text-zinc-400">{{ $area }}</span>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    @endif

    {{-- Create/Edit Modal --}}
    <flux:modal wire:model="showModal" class="md:w-[28rem]">
        <form wire:submit="save">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ $editingId ? __('Edit Commitment') : __('Add Commitment') }}</flux:heading>
                    <flux:text class="mt-2">{{ __('Define a personal or business commitment to track.') }}</flux:text>
                </div>

                <flux:field>
                    <flux:label>{{ __('Area') }}</flux:label>
                    <flux:input wire:model="area" :placeholder="__('e.g., Physical Health, Family, Business')" />
                    <flux:error name="area" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Commitment') }}</flux:label>
                    <flux:textarea wire:model="commitment" rows="3" :placeholder="__('e.g., Exercise 4x per week')" />
                    <flux:error name="commitment" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Action Type') }}</flux:label>
                    <flux:select wire:model.live="action_type">
                        <flux:select.option value="daily">{{ __('Daily') }}</flux:select.option>
                        <flux:select.option value="weekly">{{ __('Weekly') }}</flux:select.option>
                        <flux:select.option value="monthly">{{ __('Monthly') }}</flux:select.option>
                        <flux:select.option value="quarterly">{{ __('Quarterly') }}</flux:select.option>
                        <flux:select.option value="annual">{{ __('Annual') }}</flux:select.option>
                        <flux:select.option value="ongoing">{{ __('Ongoing') }}</flux:select.option>
                    </flux:select>
                    <flux:error name="action_type" />
                </flux:field>

                @if ($action_type === 'weekly')
                    <flux:field>
                        <flux:label>{{ __('Repeat on') }}</flux:label>
                        <div class="flex flex-wrap gap-2">
                            @foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'] as $dayNum => $dayLabel)
                                <button
                                    type="button"
                                    wire:click="toggleDay({{ $dayNum }})"
                                    class="rounded-lg border px-3 py-1.5 text-sm font-medium transition-colors {{ in_array($dayNum, $recurrence_days) ? 'border-blue-500 bg-blue-50 text-blue-700 dark:border-blue-400 dark:bg-blue-950/50 dark:text-blue-300' : 'border-zinc-300 text-zinc-600 hover:border-zinc-400 dark:border-zinc-600 dark:text-zinc-400 dark:hover:border-zinc-500' }}"
                                >
                                    {{ $dayLabel }}
                                </button>
                            @endforeach
                        </div>
                        <flux:error name="recurrence_days" />
                    </flux:field>
                @endif

                @if (in_array($action_type, ['monthly', 'quarterly']))
                    <flux:field>
                        <flux:label>{{ __('Day of month') }}</flux:label>
                        <flux:input wire:model="recurrence_day_of_month" type="number" min="1" max="31" placeholder="1" />
                        <flux:error name="recurrence_day_of_month" />
                    </flux:field>
                @endif

                @if (in_array($action_type, ['daily', 'weekly', 'monthly', 'quarterly', 'annual']))
                    <flux:field>
                        <flux:label>{{ __('Time (optional)') }}</flux:label>
                        <flux:input wire:model="recurrence_time" type="time" />
                        <flux:error name="recurrence_time" />
                    </flux:field>
                @endif

                <flux:field>
                    <flux:label>{{ __('Start Date (optional)') }}</flux:label>
                    <flux:input wire:model="start_date" type="date" />
                    <flux:error name="start_date" />
                </flux:field>

                <div class="flex items-center gap-3">
                    <flux:switch wire:model="is_active" />
                    <flux:label>{{ __('Active') }}</flux:label>
                </div>

                <div class="flex justify-end gap-3">
                    <flux:button variant="ghost" wire:click="$set('showModal', false)" type="button">
                        {{ __('Cancel') }}
                    </flux:button>
                    <flux:button type="submit" variant="primary">
                        {{ $editingId ? __('Update') : __('Create') }}
                    </flux:button>
                </div>
            </div>
        </form>
    </flux:modal>

    {{-- Calendar Sync Modal --}}
    <flux:modal wire:model="showCalendarSync" class="md:w-[28rem]">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Sync to Calendar') }}</flux:heading>
                <flux:text class="mt-2">{{ __('Subscribe to your life commitments in your favorite calendar app.') }}</flux:text>
            </div>

            @php $calendarUrl = $this->generateCalendarUrl(); @endphp

            <flux:field>
                <flux:label>{{ __('Calendar Feed URL') }}</flux:label>
                <div class="flex gap-2">
                    <flux:input value="{{ $calendarUrl }}" readonly class="flex-1" />
                    <flux:button
                        variant="ghost"
                        icon="clipboard"
                        x-on:click="navigator.clipboard.writeText('{{ $calendarUrl }}'); $el.querySelector('.flux-icon')?.classList.add('text-green-500')"
                        title="Copy URL"
                    />
                </div>
                <flux:text size="sm" class="mt-1 text-zinc-500">{{ __('This URL auto-updates when you add or change commitments.') }}</flux:text>
            </flux:field>

            <div class="space-y-2">
                <a
                    href="https://calendar.google.com/calendar/r?cid=webcal://{{ str_replace(['https://', 'http://'], '', $calendarUrl) }}"
                    target="_blank"
                    class="flex w-full items-center gap-3 rounded-lg border border-zinc-200 px-4 py-3 transition-colors hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800"
                >
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none">
                        <path d="M18.316 5.684H5.684v12.632h12.632V5.684z" fill="#fff"/>
                        <path d="M18.316 24l5.684-5.684h-5.684V24z" fill="#EA4335"/>
                        <path d="M24 5.684V0H18.316v5.684H24z" fill="#188038"/>
                        <path d="M18.316 18.316V24h5.684v-5.684h-5.684z" fill="#1967D2"/>
                        <path d="M5.684 18.316L0 24h5.684v-5.684z" fill="#FBBC04"/>
                        <path d="M24 5.684H18.316v12.632H24V5.684z" fill="#34A853"/>
                        <path d="M18.316 5.684H5.684v12.632h12.632V5.684z" fill="#4285F4"/>
                        <path d="M5.684 18.316V24H0v-5.684h5.684z" fill="#34A853"/>
                        <path d="M0 5.684v12.632h5.684V5.684H0z" fill="#4285F4"/>
                        <path d="M5.684 0H0v5.684h5.684V0z" fill="#188038"/>
                    </svg>
                    <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ __('Add to Google Calendar') }}</span>
                </a>

                <a
                    href="webcal://{{ str_replace(['https://', 'http://'], '', $calendarUrl) }}"
                    class="flex w-full items-center gap-3 rounded-lg border border-zinc-200 px-4 py-3 transition-colors hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800"
                >
                    <svg class="h-5 w-5 text-zinc-700 dark:text-zinc-300" fill="currentColor" viewBox="0 0 24 24">
                        <path d="M19 4h-1V2h-2v2H8V2H6v2H5c-1.11 0-2 .9-2 2v14c0 1.1.89 2 2 2h14c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 16H5V9h14v11z"/>
                    </svg>
                    <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ __('Add to Apple Calendar / Outlook') }}</span>
                </a>
            </div>

            <flux:text size="sm" class="text-zinc-500">
                {{ __('Your calendar app will periodically check for updates. Changes to your commitments will sync automatically.') }}
            </flux:text>

            <div class="flex justify-end">
                <flux:button variant="ghost" wire:click="$set('showCalendarSync', false)">
                    {{ __('Close') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
