<?php

use App\Models\MarketingCampaignLog;
use App\Models\MarketingStrategy;
use App\Models\MessengerConversation;
use App\Models\SmsConversation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts.app')]
#[Title('Marketing Dashboard')]
class extends Component
{
    public function boot(): void
    {
        if (! auth()->user()?->can('marketing.view')) {
            abort(403);
        }
    }

    /**
     * All strategies grouped by category.
     *
     * @return Collection<string, Collection<int, MarketingStrategy>>
     */
    #[Computed]
    public function strategiesByCategory(): Collection
    {
        return MarketingStrategy::query()
            ->orderBy('category')
            ->orderBy('name')
            ->get()
            ->groupBy('category');
    }

    /**
     * Bot conversations in the last 30 days (Messenger + SMS).
     */
    #[Computed]
    public function botConversations30d(): int
    {
        $messenger = MessengerConversation::where('created_at', '>=', now()->subDays(30))->count();
        $sms = SmsConversation::where('created_at', '>=', now()->subDays(30))->count();

        return $messenger + $sms;
    }

    /**
     * Leads created in the last 30 days (Messenger conversations that produced a customer).
     */
    #[Computed]
    public function leadsCreated30d(): int
    {
        return MessengerConversation::query()
            ->whereNotNull('customer_id')
            ->where('created_at', '>=', now()->subDays(30))
            ->count();
    }

    /**
     * Count of active marketing strategies.
     */
    #[Computed]
    public function activeStrategiesCount(): int
    {
        return MarketingStrategy::active()->count();
    }

    /**
     * Campaign actions logged in the last 30 days.
     */
    #[Computed]
    public function campaignActions30d(): int
    {
        return MarketingCampaignLog::where('created_at', '>=', now()->subDays(30))->count();
    }

    /**
     * Last 10 campaign log entries with their strategy.
     *
     * @return Collection<int, MarketingCampaignLog>
     */
    #[Computed]
    public function recentLogs(): Collection
    {
        return MarketingCampaignLog::query()
            ->with('strategy')
            ->latest()
            ->limit(10)
            ->get();
    }

    /**
     * Toggle a strategy between active and paused.
     */
    public function toggleStrategy(int $id): void
    {
        if (! Auth::user()?->can('marketing.manage')) {
            abort(403);
        }

        $strategy = MarketingStrategy::findOrFail($id);

        if ($strategy->isActive()) {
            $strategy->pause();
        } else {
            $strategy->activate();
        }

        unset($this->strategiesByCategory);
        unset($this->activeStrategiesCount);
    }

    /**
     * Badge color for a given strategy status.
     */
    public function statusColor(string $status): string
    {
        return match ($status) {
            'active' => 'green',
            'paused' => 'red',
            'setting_up' => 'yellow',
            default => 'zinc',
        };
    }

    /**
     * Badge color for a campaign log status.
     */
    public function logStatusColor(string $status): string
    {
        return match ($status) {
            'sent', 'delivered' => 'green',
            'queued', 'pending' => 'yellow',
            'failed', 'error' => 'red',
            default => 'zinc',
        };
    }

    /**
     * Human-readable category label.
     */
    public function categoryLabel(string $category): string
    {
        return ucfirst(str_replace(['_', '-'], ' ', $category));
    }
}; ?>

<section class="w-full">
    @include('pages.marketing.layout', ['heading' => __('Dashboard'), 'subheading' => __('Overview of all marketing activity')])

    <div class="space-y-8">

        {{-- ========= FUNNEL SUMMARY ========= --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Bot Conversations (30d)') }}</flux:text>
                <div class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-white">{{ number_format($this->botConversations30d) }}</div>
            </div>

            <div class="rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Leads Created (30d)') }}</flux:text>
                <div class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-white">{{ number_format($this->leadsCreated30d) }}</div>
            </div>

            <div class="rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Active Strategies') }}</flux:text>
                <div class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-white">{{ number_format($this->activeStrategiesCount) }}</div>
            </div>

            <div class="rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Campaign Actions (30d)') }}</flux:text>
                <div class="mt-1 text-2xl font-semibold text-zinc-900 dark:text-white">{{ number_format($this->campaignActions30d) }}</div>
            </div>
        </div>

        {{-- ========= STRATEGY STATUS GRID ========= --}}
        <div>
            <flux:heading size="sm" class="mb-4">{{ __('Strategy Status') }}</flux:heading>

            @forelse ($this->strategiesByCategory as $category => $strategies)
                <div class="mb-6" wire:key="cat-{{ $category }}">
                    <flux:text class="mb-3 text-xs font-medium uppercase tracking-wider text-zinc-400">
                        {{ $this->categoryLabel($category) }}
                    </flux:text>

                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach ($strategies as $strategy)
                            <div
                                wire:key="strategy-{{ $strategy->id }}"
                                class="rounded-lg border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-800"
                            >
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0 flex-1">
                                        <flux:text class="font-medium text-zinc-900 dark:text-white">
                                            {{ $strategy->name }}
                                        </flux:text>
                                    </div>
                                    <div class="flex shrink-0 items-center gap-2">
                                        <flux:badge size="sm" color="{{ $this->statusColor($strategy->status) }}">
                                            {{ ucfirst(str_replace('_', ' ', $strategy->status)) }}
                                        </flux:badge>
                                    </div>
                                </div>

                                <div class="mt-3 flex items-center justify-between">
                                    <flux:badge size="sm" color="zinc">
                                        {{ $this->categoryLabel($strategy->category) }}
                                    </flux:badge>

                                    @can('marketing.manage')
                                        @if ($strategy->status === 'active')
                                            <flux:button
                                                size="xs"
                                                variant="ghost"
                                                icon="pause"
                                                wire:click="toggleStrategy({{ $strategy->id }})"
                                                wire:loading.attr="disabled"
                                                wire:target="toggleStrategy({{ $strategy->id }})"
                                            >
                                                {{ __('Pause') }}
                                            </flux:button>
                                        @elseif ($strategy->status === 'paused' || $strategy->status === 'not_started')
                                            <flux:button
                                                size="xs"
                                                variant="ghost"
                                                icon="play"
                                                wire:click="toggleStrategy({{ $strategy->id }})"
                                                wire:loading.attr="disabled"
                                                wire:target="toggleStrategy({{ $strategy->id }})"
                                            >
                                                {{ __('Activate') }}
                                            </flux:button>
                                        @endif
                                    @endcan
                                </div>

                                @if ($strategy->activated_at)
                                    <flux:text class="mt-2 text-xs text-zinc-400">
                                        {{ __('Activated') }}: {{ $strategy->activated_at->diffForHumans() }}
                                    </flux:text>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @empty
                <div class="rounded-lg border border-zinc-200 bg-white p-8 text-center dark:border-zinc-700 dark:bg-zinc-800">
                    <flux:icon name="chart-bar" class="mx-auto mb-3 size-10 text-zinc-300 dark:text-zinc-600" />
                    <flux:text class="text-zinc-500 dark:text-zinc-400">
                        {{ __('No marketing strategies configured yet.') }}
                    </flux:text>
                </div>
            @endforelse
        </div>

        <flux:separator />

        {{-- ========= RECENT CAMPAIGN ACTIVITY ========= --}}
        <div>
            <flux:heading size="sm" class="mb-4">{{ __('Recent Campaign Activity') }}</flux:heading>

            @if ($this->recentLogs->isEmpty())
                <div class="rounded-lg border border-zinc-200 bg-white p-8 text-center dark:border-zinc-700 dark:bg-zinc-800">
                    <flux:icon name="inbox" class="mx-auto mb-3 size-10 text-zinc-300 dark:text-zinc-600" />
                    <flux:text class="text-zinc-500 dark:text-zinc-400">
                        {{ __('No campaign activity logged yet. Activity will appear here once your strategies start sending messages.') }}
                    </flux:text>
                </div>
            @else
                <div class="overflow-hidden rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                    <div class="divide-y divide-zinc-100 dark:divide-zinc-700">
                        @foreach ($this->recentLogs as $log)
                            <div wire:key="log-{{ $log->id }}" class="flex items-center justify-between gap-4 px-5 py-3">
                                <div class="min-w-0 flex-1">
                                    <div class="flex items-center gap-2">
                                        <flux:text class="font-medium text-zinc-900 dark:text-white">
                                            {{ $log->strategy?->name ?? __('Unknown Strategy') }}
                                        </flux:text>
                                        <flux:badge size="sm" color="{{ $this->logStatusColor($log->status) }}">
                                            {{ ucfirst($log->status) }}
                                        </flux:badge>
                                    </div>
                                    <flux:text class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">
                                        {{ $log->action }}
                                        @if ($log->recipient_phone)
                                            &middot; {{ $log->recipient_phone }}
                                        @elseif ($log->recipient_address)
                                            &middot; {{ Str::limit($log->recipient_address, 40) }}
                                        @endif
                                    </flux:text>
                                </div>
                                <flux:text class="shrink-0 text-xs text-zinc-400">
                                    {{ $log->created_at->diffForHumans() }}
                                </flux:text>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>
</section>
