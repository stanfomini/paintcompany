<?php

use App\Models\MessengerBotFlow;
use App\Models\MessengerConversation;
use App\Models\SmsBotFlow;
use App\Models\SmsConversation;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public function boot(): void
    {
        if (! auth()->user()?->can('marketing.view')) {
            abort(403);
        }
    }

    public string $dateRange = '30';

    /**
     * Get the start date for the selected range.
     */
    #[Computed]
    public function rangeStart(): CarbonInterface
    {
        return match ($this->dateRange) {
            'today' => now()->startOfDay(),
            '7' => now()->subDays(7)->startOfDay(),
            '90' => now()->subDays(90)->startOfDay(),
            default => now()->subDays(30)->startOfDay(),
        };
    }

    /**
     * Active conversations count (in_progress from both channels).
     */
    #[Computed]
    public function activeCount(): int
    {
        $messenger = MessengerConversation::query()
            ->where('status', 'in_progress')
            ->count();

        $sms = SmsConversation::query()
            ->where('status', 'in_progress')
            ->count();

        return $messenger + $sms;
    }

    /**
     * Completed conversations in selected date range.
     */
    #[Computed]
    public function completedCount(): int
    {
        $messenger = MessengerConversation::query()
            ->where('status', 'completed')
            ->where('completed_at', '>=', $this->rangeStart)
            ->count();

        $sms = SmsConversation::query()
            ->where('status', 'completed')
            ->where('completed_at', '>=', $this->rangeStart)
            ->count();

        return $messenger + $sms;
    }

    /**
     * Leads created: messenger conversations with a customer_id, completed in range.
     */
    #[Computed]
    public function leadsCreatedCount(): int
    {
        return MessengerConversation::query()
            ->whereNotNull('customer_id')
            ->where('completed_at', '>=', $this->rangeStart)
            ->count();
    }

    /**
     * Conversion rate: completed / (completed + abandoned) in range.
     */
    #[Computed]
    public function conversionRate(): float
    {
        $completed = $this->completedCount;

        $abandoned = MessengerConversation::query()
            ->where('status', 'abandoned')
            ->where('last_activity_at', '>=', $this->rangeStart)
            ->count()
            + SmsConversation::query()
                ->where('status', 'abandoned')
                ->where('last_message_at', '>=', $this->rangeStart)
                ->count();

        $total = $completed + $abandoned;

        return $total > 0 ? round(($completed / $total) * 100, 1) : 0;
    }

    /**
     * Entry source performance (Messenger only, grouped by entry_source).
     */
    #[Computed]
    public function entrySourceStats(): Collection
    {
        $sources = ['ad', 'keyword', 'default'];

        return collect($sources)->mapWithKeys(function (string $source) {
            $query = MessengerConversation::query()
                ->where('entry_source', $source)
                ->where('started_at', '>=', $this->rangeStart);

            $total = (clone $query)->count();
            $completed = (clone $query)->where('status', 'completed')->count();
            $abandoned = (clone $query)->where('status', 'abandoned')->count();
            $denominator = $completed + $abandoned;

            return [$source => [
                'total' => $total,
                'completed' => $completed,
                'rate' => $denominator > 0 ? round(($completed / $denominator) * 100, 1) : 0,
            ]];
        });
    }

    /**
     * Bot flow performance table (both Messenger and SMS flows combined).
     */
    #[Computed]
    public function flowPerformance(): Collection
    {
        $start = $this->rangeStart;

        $messengerFlows = MessengerBotFlow::query()
            ->withCount([
                'conversations as total_conversations' => fn ($q) => $q->where('started_at', '>=', $start),
                'conversations as completed_count' => fn ($q) => $q->where('status', 'completed')->where('completed_at', '>=', $start),
                'conversations as abandoned_count' => fn ($q) => $q->where('status', 'abandoned')->where('last_activity_at', '>=', $start),
                'conversations as handoff_count' => fn ($q) => $q->where('status', 'human_takeover')->where('last_activity_at', '>=', $start),
            ])
            ->get()
            ->map(fn (MessengerBotFlow $flow) => [
                'name' => $flow->name,
                'channel' => 'Messenger',
                'total' => $flow->total_conversations,
                'completed' => $flow->completed_count,
                'abandoned' => $flow->abandoned_count,
                'handoffs' => $flow->handoff_count,
                'rate' => ($flow->completed_count + $flow->abandoned_count) > 0
                    ? round(($flow->completed_count / ($flow->completed_count + $flow->abandoned_count)) * 100, 1)
                    : 0,
            ]);

        $smsFlows = SmsBotFlow::query()
            ->withCount([
                'conversations as total_conversations' => fn ($q) => $q->where('created_at', '>=', $start),
                'conversations as completed_count' => fn ($q) => $q->where('status', 'completed')->where('completed_at', '>=', $start),
                'conversations as abandoned_count' => fn ($q) => $q->where('status', 'abandoned')->where('last_message_at', '>=', $start),
                'conversations as handoff_count' => fn ($q) => $q->where('status', 'human_takeover')->where('last_message_at', '>=', $start),
            ])
            ->get()
            ->map(fn (SmsBotFlow $flow) => [
                'name' => $flow->name,
                'channel' => 'SMS',
                'total' => $flow->total_conversations,
                'completed' => $flow->completed_count,
                'abandoned' => $flow->abandoned_count,
                'handoffs' => $flow->handoff_count,
                'rate' => ($flow->completed_count + $flow->abandoned_count) > 0
                    ? round(($flow->completed_count / ($flow->completed_count + $flow->abandoned_count)) * 100, 1)
                    : 0,
            ]);

        return $messengerFlows->concat($smsFlows)->sortByDesc('total')->values();
    }

    /**
     * Recent conversations feed (last 20 from both channels).
     */
    #[Computed]
    public function recentConversations(): Collection
    {
        $messenger = MessengerConversation::query()
            ->select('id', 'status', 'entry_source', 'fb_profile_data', 'started_at', 'last_activity_at', 'completed_at')
            ->latest('last_activity_at')
            ->limit(20)
            ->get()
            ->map(fn (MessengerConversation $c) => [
                'id' => 'msg-'.$c->id,
                'channel' => 'Messenger',
                'status' => $c->status,
                'entry_source' => $c->entry_source,
                'name' => trim(($c->fb_profile_data['first_name'] ?? '').' '.($c->fb_profile_data['last_name'] ?? '')) ?: __('Unknown'),
                'time' => ($c->last_activity_at ?? $c->started_at)?->diffForHumans() ?? '',
                'sort_at' => $c->last_activity_at ?? $c->started_at,
            ]);

        $sms = SmsConversation::query()
            ->select('id', 'status', 'phone', 'last_message_at', 'completed_at', 'created_at')
            ->latest('last_message_at')
            ->limit(20)
            ->get()
            ->map(fn (SmsConversation $c) => [
                'id' => 'sms-'.$c->id,
                'channel' => 'SMS',
                'status' => $c->status,
                'entry_source' => null,
                'name' => $c->phone,
                'time' => ($c->last_message_at ?? $c->created_at)?->diffForHumans() ?? '',
                'sort_at' => $c->last_message_at ?? $c->created_at,
            ]);

        return $messenger->concat($sms)
            ->sortByDesc('sort_at')
            ->take(20)
            ->values();
    }

    public function updatedDateRange(): void
    {
        unset(
            $this->rangeStart,
            $this->activeCount,
            $this->completedCount,
            $this->leadsCreatedCount,
            $this->conversionRate,
            $this->entrySourceStats,
            $this->flowPerformance,
        );
    }
}; ?>

<section class="w-full">
    <x-pages::marketing.layout :heading="__('Bot Tracker')" :subheading="__('Messenger and SMS bot conversation analytics')">

        {{-- Date Range Filter --}}
        <div class="mb-6 flex items-center justify-end">
            <div class="w-48">
                <flux:select wire:model.live="dateRange" label="{{ __('Date Range') }}">
                    <flux:select.option value="today">{{ __('Today') }}</flux:select.option>
                    <flux:select.option value="7">{{ __('Last 7 Days') }}</flux:select.option>
                    <flux:select.option value="30">{{ __('Last 30 Days') }}</flux:select.option>
                    <flux:select.option value="90">{{ __('Last 90 Days') }}</flux:select.option>
                </flux:select>
            </div>
        </div>

        {{-- Metrics Bar --}}
        <div class="mb-8 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {{-- Active Conversations --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
                <div class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Active Conversations') }}</div>
                <div class="mt-2 text-3xl font-bold text-zinc-900 dark:text-zinc-100" wire:loading.class="opacity-50" wire:target="dateRange">
                    {{ $this->activeCount }}
                </div>
                <div class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">{{ __('Currently in progress') }}</div>
            </div>

            {{-- Completed --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
                <div class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Completed') }}</div>
                <div class="mt-2 text-3xl font-bold text-emerald-600 dark:text-emerald-400" wire:loading.class="opacity-50" wire:target="dateRange">
                    {{ $this->completedCount }}
                </div>
                <div class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">{{ __('In selected period') }}</div>
            </div>

            {{-- Leads Created --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
                <div class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Leads Created') }}</div>
                <div class="mt-2 text-3xl font-bold text-blue-600 dark:text-blue-400" wire:loading.class="opacity-50" wire:target="dateRange">
                    {{ $this->leadsCreatedCount }}
                </div>
                <div class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">{{ __('From Messenger bots') }}</div>
            </div>

            {{-- Conversion Rate --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
                <div class="text-sm font-medium text-zinc-500 dark:text-zinc-400">{{ __('Conversion Rate') }}</div>
                <div class="mt-2 text-3xl font-bold text-violet-600 dark:text-violet-400" wire:loading.class="opacity-50" wire:target="dateRange">
                    {{ $this->conversionRate }}%
                </div>
                <div class="mt-1 text-xs text-zinc-400 dark:text-zinc-500">{{ __('Completed vs abandoned') }}</div>
            </div>
        </div>

        {{-- Entry Source Performance --}}
        <div class="mb-8">
            <flux:heading size="sm" class="mb-4">{{ __('Entry Source Performance') }}</flux:heading>
            <flux:text class="mb-4 text-sm">{{ __('Messenger conversations grouped by how users entered the bot.') }}</flux:text>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3" wire:loading.class="opacity-50" wire:target="dateRange">
                @foreach ($this->entrySourceStats as $source => $stats)
                    <div class="rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
                        <div class="mb-3 flex items-center gap-2">
                            @if ($source === 'ad')
                                <flux:badge color="amber" size="sm">{{ __('Ad') }}</flux:badge>
                            @elseif ($source === 'keyword')
                                <flux:badge color="sky" size="sm">{{ __('Keyword') }}</flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm">{{ __('Default') }}</flux:badge>
                            @endif
                        </div>

                        <div class="space-y-2">
                            <div class="flex items-baseline justify-between">
                                <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Total') }}</span>
                                <span class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">{{ $stats['total'] }}</span>
                            </div>
                            <div class="flex items-baseline justify-between">
                                <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Completed') }}</span>
                                <span class="text-lg font-semibold text-emerald-600 dark:text-emerald-400">{{ $stats['completed'] }}</span>
                            </div>
                            <div class="flex items-baseline justify-between">
                                <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Conversion') }}</span>
                                <span class="text-lg font-semibold text-violet-600 dark:text-violet-400">{{ $stats['rate'] }}%</span>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Bot Flow Performance Table --}}
        <div class="mb-8">
            <flux:heading size="sm" class="mb-4">{{ __('Bot Flow Performance') }}</flux:heading>

            <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-700" wire:loading.class="opacity-50" wire:target="dateRange">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Flow Name') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Channel') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Total') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Completed') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Abandoned') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Handoffs') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Conv. Rate') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-700 dark:bg-zinc-800">
                        @forelse ($this->flowPerformance as $flow)
                            <tr wire:key="flow-{{ $loop->index }}">
                                <td class="whitespace-nowrap px-4 py-3 text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                    {{ $flow['name'] }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm">
                                    @if ($flow['channel'] === 'Messenger')
                                        <flux:badge color="blue" size="sm">{{ __('Messenger') }}</flux:badge>
                                    @else
                                        <flux:badge color="green" size="sm">{{ __('SMS') }}</flux:badge>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-zinc-700 dark:text-zinc-300">{{ $flow['total'] }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-emerald-600 dark:text-emerald-400">{{ $flow['completed'] }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-red-600 dark:text-red-400">{{ $flow['abandoned'] }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-sky-600 dark:text-sky-400">{{ $flow['handoffs'] }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $flow['rate'] }}%</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-8 text-center text-sm text-zinc-400 dark:text-zinc-500">
                                    {{ __('No bot flows found. Create a flow in Messenger or SMS settings.') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Recent Conversations Feed --}}
        <div>
            <flux:heading size="sm" class="mb-4">{{ __('Recent Conversations') }}</flux:heading>

            <div class="space-y-2">
                @forelse ($this->recentConversations as $convo)
                    <div wire:key="{{ $convo['id'] }}" class="flex items-center gap-3 rounded-lg border border-zinc-200 bg-white px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800">
                        {{-- Status Badge --}}
                        @switch($convo['status'])
                            @case('completed')
                                <flux:badge color="green" size="sm">{{ __('Completed') }}</flux:badge>
                                @break
                            @case('in_progress')
                                <flux:badge color="yellow" size="sm">{{ __('In Progress') }}</flux:badge>
                                @break
                            @case('abandoned')
                                <flux:badge color="red" size="sm">{{ __('Abandoned') }}</flux:badge>
                                @break
                            @case('human_takeover')
                                <flux:badge color="blue" size="sm">{{ __('Handoff') }}</flux:badge>
                                @break
                            @default
                                <flux:badge color="zinc" size="sm">{{ $convo['status'] }}</flux:badge>
                        @endswitch

                        {{-- Channel Badge --}}
                        @if ($convo['channel'] === 'Messenger')
                            <flux:badge color="blue" size="sm" class="!bg-blue-50 !text-blue-700 dark:!bg-blue-900/30 dark:!text-blue-300">{{ __('Messenger') }}</flux:badge>
                        @else
                            <flux:badge color="green" size="sm" class="!bg-green-50 !text-green-700 dark:!bg-green-900/30 dark:!text-green-300">{{ __('SMS') }}</flux:badge>
                        @endif

                        {{-- Entry Source Badge (Messenger only) --}}
                        @if ($convo['entry_source'])
                            @if ($convo['entry_source'] === 'ad')
                                <flux:badge color="amber" size="sm">{{ __('Ad') }}</flux:badge>
                            @elseif ($convo['entry_source'] === 'keyword')
                                <flux:badge color="sky" size="sm">{{ __('Keyword') }}</flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm">{{ __('Default') }}</flux:badge>
                            @endif
                        @endif

                        {{-- Name --}}
                        <span class="min-w-0 flex-1 truncate text-sm font-medium text-zinc-900 dark:text-zinc-100">
                            {{ $convo['name'] }}
                        </span>

                        {{-- Time Ago --}}
                        <span class="shrink-0 text-xs text-zinc-400 dark:text-zinc-500">
                            {{ $convo['time'] }}
                        </span>
                    </div>
                @empty
                    <div class="rounded-lg border border-zinc-200 bg-white px-4 py-8 text-center text-sm text-zinc-400 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-500">
                        {{ __('No conversations yet.') }}
                    </div>
                @endforelse
            </div>
        </div>

    </x-pages::marketing.layout>
</section>
