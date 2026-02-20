<?php

use App\Models\DoorKnock;
use App\Models\SalesTerritory;
use App\Models\TerritoryOffer;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.app')] class extends Component
{
    public string $period = 'week';

    public ?int $filterTerritory = null;

    #[Computed]
    public function dateRange(): array
    {
        return match ($this->period) {
            'today' => [today(), today()->endOfDay()],
            'week' => [now()->startOfWeek(), now()->endOfWeek()],
            'month' => [now()->startOfMonth(), now()->endOfMonth()],
            'quarter' => [now()->startOfQuarter(), now()->endOfQuarter()],
            default => [now()->startOfWeek(), now()->endOfWeek()],
        };
    }

    #[Computed]
    public function totalKnocks(): int
    {
        [$start, $end] = $this->dateRange;

        return DoorKnock::query()
            ->whereBetween('knocked_at', [$start, $end])
            ->when($this->filterTerritory, fn ($q) => $q->where('territory_id', $this->filterTerritory))
            ->count();
    }

    #[Computed]
    public function knocksByStatus(): array
    {
        [$start, $end] = $this->dateRange;

        return DoorKnock::query()
            ->select('status', DB::raw('COUNT(*) as count'))
            ->whereBetween('knocked_at', [$start, $end])
            ->when($this->filterTerritory, fn ($q) => $q->where('territory_id', $this->filterTerritory))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();
    }

    #[Computed]
    public function conversionRate(): float
    {
        if ($this->totalKnocks === 0) {
            return 0;
        }

        $converted = ($this->knocksByStatus['interested'] ?? 0)
            + ($this->knocksByStatus['appointment_set'] ?? 0)
            + ($this->knocksByStatus['sold'] ?? 0);

        return round(($converted / $this->totalKnocks) * 100, 1);
    }

    #[Computed]
    public function appointmentRate(): float
    {
        if ($this->totalKnocks === 0) {
            return 0;
        }

        $appointments = ($this->knocksByStatus['appointment_set'] ?? 0)
            + ($this->knocksByStatus['sold'] ?? 0);

        return round(($appointments / $this->totalKnocks) * 100, 1);
    }

    #[Computed]
    public function knocksByDay(): array
    {
        [$start, $end] = $this->dateRange;

        return DoorKnock::query()
            ->select(DB::raw('DATE(knocked_at) as date'), DB::raw('COUNT(*) as count'))
            ->whereBetween('knocked_at', [$start, $end])
            ->when($this->filterTerritory, fn ($q) => $q->where('territory_id', $this->filterTerritory))
            ->groupBy('date')
            ->orderBy('date')
            ->pluck('count', 'date')
            ->toArray();
    }

    #[Computed]
    public function leaderboard(): array
    {
        [$start, $end] = $this->dateRange;

        $rows = DoorKnock::query()
            ->select('user_id', DB::raw('COUNT(*) as total_knocks'))
            ->selectRaw("SUM(CASE WHEN status IN ('interested', 'appointment_set', 'sold') THEN 1 ELSE 0 END) as conversions")
            ->selectRaw("SUM(CASE WHEN status IN ('appointment_set', 'sold') THEN 1 ELSE 0 END) as appointments")
            ->whereBetween('knocked_at', [$start, $end])
            ->when($this->filterTerritory, fn ($q) => $q->where('territory_id', $this->filterTerritory))
            ->groupBy('user_id')
            ->orderByDesc('total_knocks')
            ->limit(10)
            ->get();

        $userIds = $rows->pluck('user_id')->filter()->all();
        $users = User::whereIn('id', $userIds)->get()->keyBy('id');

        return $rows->map(function ($row) use ($users) {
            return [
                'user' => $users->get($row->user_id),
                'total_knocks' => $row->total_knocks,
                'conversions' => $row->conversions,
                'appointments' => $row->appointments,
                'conversion_rate' => $row->total_knocks > 0
                    ? round(($row->conversions / $row->total_knocks) * 100, 1)
                    : 0,
            ];
        })->toArray();
    }

    #[Computed]
    public function territoryCoverage(): array
    {
        [$start, $end] = $this->dateRange;

        return SalesTerritory::query()
            ->active()
            ->withCount(['doorKnocks as period_knocks' => function ($q) use ($start, $end) {
                $q->whereBetween('knocked_at', [$start, $end]);
            }])
            ->withCount('doorKnocks as total_knocks')
            ->orderByDesc('period_knocks')
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'color' => $t->color,
                'period_knocks' => $t->period_knocks,
                'total_knocks' => $t->total_knocks,
            ])
            ->toArray();
    }

    #[Computed]
    public function territories()
    {
        return SalesTerritory::active()->orderBy('name')->get();
    }

    #[Computed]
    public function offerStats(): array
    {
        [$start, $end] = $this->dateRange;

        $total = TerritoryOffer::whereBetween('offered_at', [$start, $end])->count();
        $claimed = TerritoryOffer::where('status', 'claimed')->whereBetween('offered_at', [$start, $end])->count();
        $expired = TerritoryOffer::where('status', 'expired')->whereBetween('offered_at', [$start, $end])->count();

        return [
            'total' => $total,
            'claimed' => $claimed,
            'expired' => $expired,
            'claim_rate' => $total > 0 ? round(($claimed / $total) * 100, 1) : 0,
        ];
    }
};

?>

<div>
    <div class="mx-auto max-w-7xl py-6 space-y-6">
        {{-- Header --}}
        <div class="flex items-center justify-between flex-wrap gap-3">
            <div>
                <flux:heading size="xl">{{ __('Sales Dashboard') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Territory performance and door knock analytics.') }}</flux:text>
            </div>
            <div class="flex items-center gap-2">
                <flux:select wire:model.live="filterTerritory" class="w-48">
                    <option value="">{{ __('All Territories') }}</option>
                    @foreach($this->territories as $territory)
                        <option value="{{ $territory->id }}">{{ $territory->name }}</option>
                    @endforeach
                </flux:select>

                <flux:select wire:model.live="period" class="w-36">
                    <option value="today">{{ __('Today') }}</option>
                    <option value="week">{{ __('This Week') }}</option>
                    <option value="month">{{ __('This Month') }}</option>
                    <option value="quarter">{{ __('This Quarter') }}</option>
                </flux:select>
            </div>
        </div>

        {{-- KPI Cards --}}
        <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5">
                <flux:text size="sm" class="text-zinc-500">{{ __('Total Knocks') }}</flux:text>
                <flux:heading size="xl" class="mt-1">{{ number_format($this->totalKnocks) }}</flux:heading>
            </div>

            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5">
                <flux:text size="sm" class="text-zinc-500">{{ __('Conversion Rate') }}</flux:text>
                <flux:heading size="xl" class="mt-1">{{ $this->conversionRate }}%</flux:heading>
                <flux:text size="sm" class="text-green-600">{{ __('interested + appt + sold') }}</flux:text>
            </div>

            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5">
                <flux:text size="sm" class="text-zinc-500">{{ __('Appointment Rate') }}</flux:text>
                <flux:heading size="xl" class="mt-1">{{ $this->appointmentRate }}%</flux:heading>
                <flux:text size="sm" class="text-blue-600">{{ __('appointments + sold') }}</flux:text>
            </div>

            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5">
                <flux:text size="sm" class="text-zinc-500">{{ __('Offer Claim Rate') }}</flux:text>
                <flux:heading size="xl" class="mt-1">{{ $this->offerStats['claim_rate'] }}%</flux:heading>
                <flux:text size="sm">{{ $this->offerStats['claimed'] }}/{{ $this->offerStats['total'] }} {{ __('claimed') }}</flux:text>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            {{-- Knocks by Status --}}
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5">
                <flux:heading size="lg" class="mb-4">{{ __('Knocks by Result') }}</flux:heading>

                <div class="space-y-3">
                    @foreach(DoorKnock::STATUSES as $key => $label)
                        @php
                            $count = $this->knocksByStatus[$key] ?? 0;
                            $pct = $this->totalKnocks > 0 ? ($count / $this->totalKnocks) * 100 : 0;
                        @endphp
                        <div>
                            <div class="flex items-center justify-between text-sm mb-1">
                                <div class="flex items-center gap-2">
                                    <span class="h-2.5 w-2.5 rounded-full" style="background-color: {{ DoorKnock::STATUS_COLORS[$key] }}"></span>
                                    <span>{{ __($label) }}</span>
                                </div>
                                <span class="font-medium">{{ $count }}</span>
                            </div>
                            <div class="h-2 rounded-full bg-zinc-100 dark:bg-zinc-800 overflow-hidden">
                                <div
                                    class="h-full rounded-full transition-all"
                                    style="width: {{ $pct }}%; background-color: {{ DoorKnock::STATUS_COLORS[$key] }}"
                                ></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            {{-- Territory Coverage --}}
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5">
                <flux:heading size="lg" class="mb-4">{{ __('Territory Coverage') }}</flux:heading>

                @if(empty($this->territoryCoverage))
                    <flux:text class="text-zinc-500">{{ __('No territories yet.') }}</flux:text>
                @else
                    <div class="space-y-3">
                        @foreach($this->territoryCoverage as $tc)
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <span class="h-3 w-3 rounded-full" style="background-color: {{ $tc['color'] }}"></span>
                                    <flux:text>{{ $tc['name'] }}</flux:text>
                                </div>
                                <div class="flex items-center gap-3">
                                    <flux:badge size="sm">{{ $tc['period_knocks'] }} {{ __('this period') }}</flux:badge>
                                    <flux:text size="sm" class="text-zinc-400">{{ $tc['total_knocks'] }} {{ __('total') }}</flux:text>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>

        {{-- Leaderboard --}}
        <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5">
            <flux:heading size="lg" class="mb-4">{{ __('Sales Rep Leaderboard') }}</flux:heading>

            @if(empty($this->leaderboard))
                <flux:text class="text-zinc-500">{{ __('No knocks recorded in this period.') }}</flux:text>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                <th class="text-left py-2 pr-4 font-medium text-zinc-500">#</th>
                                <th class="text-left py-2 pr-4 font-medium text-zinc-500">{{ __('Rep') }}</th>
                                <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Knocks') }}</th>
                                <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Conversions') }}</th>
                                <th class="text-right py-2 pr-4 font-medium text-zinc-500">{{ __('Appointments') }}</th>
                                <th class="text-right py-2 font-medium text-zinc-500">{{ __('Conv. Rate') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @foreach($this->leaderboard as $i => $entry)
                                <tr>
                                    <td class="py-2.5 pr-4">
                                        @if($i === 0)
                                            <span class="text-lg">&#x1f947;</span>
                                        @elseif($i === 1)
                                            <span class="text-lg">&#x1f948;</span>
                                        @elseif($i === 2)
                                            <span class="text-lg">&#x1f949;</span>
                                        @else
                                            <span class="text-zinc-400">{{ $i + 1 }}</span>
                                        @endif
                                    </td>
                                    <td class="py-2.5 pr-4">
                                        <div class="flex items-center gap-2">
                                            <flux:avatar :name="$entry['user']?->name ?? 'Unknown'" size="xs" />
                                            <span class="font-medium">{{ $entry['user']?->name ?? __('Unknown') }}</span>
                                        </div>
                                    </td>
                                    <td class="py-2.5 pr-4 text-right font-mono">{{ $entry['total_knocks'] }}</td>
                                    <td class="py-2.5 pr-4 text-right font-mono text-green-600">{{ $entry['conversions'] }}</td>
                                    <td class="py-2.5 pr-4 text-right font-mono text-blue-600">{{ $entry['appointments'] }}</td>
                                    <td class="py-2.5 text-right">
                                        <flux:badge size="sm" :color="$entry['conversion_rate'] >= 20 ? 'green' : ($entry['conversion_rate'] >= 10 ? 'yellow' : 'zinc')">
                                            {{ $entry['conversion_rate'] }}%
                                        </flux:badge>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- Daily Knock Chart (simple bar chart with CSS) --}}
        @if(!empty($this->knocksByDay))
            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-900 p-5">
                <flux:heading size="lg" class="mb-4">{{ __('Knocks per Day') }}</flux:heading>

                @php
                    $maxKnocks = max(array_values($this->knocksByDay) ?: [1]);
                @endphp

                <div class="flex items-end gap-1 h-32">
                    @foreach($this->knocksByDay as $date => $count)
                        <div class="flex-1 flex flex-col items-center gap-1">
                            <flux:text size="xs" class="font-mono">{{ $count }}</flux:text>
                            <div
                                class="w-full rounded-t bg-blue-500 dark:bg-blue-400 transition-all"
                                style="height: {{ ($count / $maxKnocks) * 100 }}%"
                            ></div>
                            <flux:text size="xs" class="text-zinc-400">
                                {{ Carbon::parse($date)->format('D') }}
                            </flux:text>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</div>
