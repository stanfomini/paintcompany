<?php

use App\Models\AgentListing;
use App\Models\Customer;
use App\Models\Deal;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('components.layouts.agent', ['title' => 'Dashboard'])] class extends Component {
    #[Computed]
    public function commissionRate(): float
    {
        $agent = auth('agent')->user();
        $agentProfile = $agent->agentProfile;

        return (float) ($agentProfile?->commission_rate ?? 10);
    }

    #[Computed]
    public function commissionStats(): array
    {
        return $this->calculateCommissionStats(auth('agent')->user(), $this->commissionRate);
    }

    #[Computed]
    public function totalListings(): int
    {
        return AgentListing::query()->forAgent(auth('agent')->user())->count();
    }

    #[Computed]
    public function activeListings(): int
    {
        return AgentListing::query()->forAgent(auth('agent')->user())->active()->count();
    }

    #[Computed]
    public function referredCustomers(): int
    {
        return Customer::query()->referredByAgent(auth('agent')->user())->count();
    }

    #[Computed]
    public function upcomingOpenHousesCount(): int
    {
        return AgentListing::query()
            ->forAgent(auth('agent')->user())
            ->withUpcomingOpenHouse()
            ->where('open_house_date', '<=', now()->addDays(7))
            ->count();
    }

    #[Computed]
    public function performanceMetrics(): array
    {
        return $this->calculatePerformanceMetrics(auth('agent')->user());
    }

    #[Computed]
    public function dealsByStage(): array
    {
        return $this->getDealsByStage(auth('agent')->user());
    }

    #[Computed]
    public function monthlyDealCounts(): array
    {
        return $this->getMonthlyDealCounts(auth('agent')->user());
    }

    #[Computed]
    public function recentDeals()
    {
        return Deal::query()
            ->forAgent(auth('agent')->user())
            ->with(['customer', 'property'])
            ->latest()
            ->limit(5)
            ->get();
    }

    #[Computed]
    public function upcomingOpenHouses()
    {
        return AgentListing::query()
            ->forAgent(auth('agent')->user())
            ->withUpcomingOpenHouse()
            ->orderBy('open_house_date')
            ->limit(5)
            ->get();
    }

    #[Computed]
    public function recentCustomers()
    {
        return Customer::query()
            ->referredByAgent(auth('agent')->user())
            ->with('listing')
            ->latest()
            ->limit(5)
            ->get();
    }

/**
     * Calculate commission statistics for the agent.
     *
     * @return array{total_earned: float, pending: float, this_month: float}
     */
    public function calculateCommissionStats($agent, float $commissionRate): array
    {
        // Total earned commissions (completed or paid deals)
        $earnedDealsValue = Deal::query()
            ->forAgent($agent)
            ->whereIn('stage', ['completed', 'paid'])
            ->sum(DB::raw('COALESCE(actual_value, estimated_value, 0)'));

        $totalEarned = $earnedDealsValue * ($commissionRate / 100);

        // Pending commissions (active deals not yet completed/paid/cancelled)
        $pendingDealsValue = Deal::query()
            ->forAgent($agent)
            ->whereNotIn('stage', ['completed', 'paid', 'cancelled'])
            ->sum(DB::raw('COALESCE(estimated_value, 0)'));

        $pendingCommission = $pendingDealsValue * ($commissionRate / 100);

        // This month's earned commissions
        $thisMonthDealsValue = Deal::query()
            ->forAgent($agent)
            ->whereIn('stage', ['completed', 'paid'])
            ->whereMonth('updated_at', now()->month)
            ->whereYear('updated_at', now()->year)
            ->sum(DB::raw('COALESCE(actual_value, estimated_value, 0)'));

        $thisMonthCommission = $thisMonthDealsValue * ($commissionRate / 100);

        return [
            'total_earned' => (float) $totalEarned,
            'pending' => (float) $pendingCommission,
            'this_month' => (float) $thisMonthCommission,
        ];
    }

    /**
     * Calculate performance metrics for the agent.
     *
     * @return array{conversion_rate: float, average_deal_value: float, total_referral_value: float}
     */
    public function calculatePerformanceMetrics($agent): array
    {
        $totalDeals = Deal::query()->forAgent($agent)->count();
        $wonDeals = Deal::query()->forAgent($agent)->won()->count();

        $conversionRate = $totalDeals > 0 ? ($wonDeals / $totalDeals) * 100 : 0;

        $avgDealValue = Deal::query()
            ->forAgent($agent)
            ->avg(DB::raw('COALESCE(actual_value, estimated_value, 0)')) ?? 0;

        $totalReferralValue = Deal::query()
            ->forAgent($agent)
            ->sum(DB::raw('COALESCE(estimated_value, 0)'));

        return [
            'conversion_rate' => round((float) $conversionRate, 1),
            'average_deal_value' => (float) $avgDealValue,
            'total_referral_value' => (float) $totalReferralValue,
        ];
    }

    /**
     * Get deal counts grouped by stage.
     *
     * @return array<string, int>
     */
    public function getDealsByStage($agent): array
    {
        $stages = array_keys(Deal::STAGES);
        $counts = [];

        foreach ($stages as $stage) {
            $counts[$stage] = Deal::query()
                ->forAgent($agent)
                ->where('stage', $stage)
                ->count();
        }

        return $counts;
    }

    /**
     * Get monthly deal counts for the last 6 months.
     *
     * @return array<string, int>
     */
    public function getMonthlyDealCounts($agent): array
    {
        $months = [];

        for ($i = 5; $i >= 0; $i--) {
            $date = now()->subMonths($i);
            $monthKey = $date->format('M Y');

            $count = Deal::query()
                ->forAgent($agent)
                ->whereMonth('created_at', $date->month)
                ->whereYear('created_at', $date->year)
                ->count();

            $months[$monthKey] = $count;
        }

        return $months;
    }

    /**
     * Get badge color for a deal stage.
     */
    public function getStageBadgeColor(string $stage): string
    {
        return match ($stage) {
            'lead' => 'blue',
            'quoted' => 'purple',
            'scheduled' => 'yellow',
            'in_progress' => 'orange',
            'completed' => 'green',
            'paid' => 'emerald',
            'cancelled' => 'red',
            default => 'zinc',
        };
    }
}; ?>

<div>
    <div class="space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div>
                <flux:heading size="xl">{{ __('Welcome back, :name!', ['name' => auth('agent')->user()->name]) }}</flux:heading>
                <flux:text class="mt-2 text-zinc-600 dark:text-zinc-400">
                    {{ __('Here\'s an overview of your listings, referrals, and commissions.') }}
                </flux:text>
            </div>
            <div class="flex gap-2">
                <flux:button href="{{ route('agent.listings.index') }}" variant="primary" icon="plus">
                    {{ __('Add New Listing') }}
                </flux:button>
                <flux:button href="{{ route('agent.customers.index') }}" variant="ghost" icon="users">
                    {{ __('View My Customers') }}
                </flux:button>
            </div>
        </div>

        <!-- Commission Stats Grid -->
        <div>
            <flux:heading size="lg" class="mb-4">{{ __('Commission Overview') }}</flux:heading>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <flux:card>
                    <div class="flex items-center gap-4">
                        <div class="rounded-lg bg-emerald-100 dark:bg-emerald-900 p-3">
                            <flux:icon name="currency-dollar" class="h-6 w-6 text-emerald-600 dark:text-emerald-400" />
                        </div>
                        <div>
                            <flux:heading size="sm" class="text-zinc-500 dark:text-zinc-400">{{ __('Total Earned') }}</flux:heading>
                            <flux:text size="2xl" class="mt-1 font-semibold text-emerald-600 dark:text-emerald-400">${{ number_format($this->commissionStats['total_earned'], 2) }}</flux:text>
                        </div>
                    </div>
                </flux:card>

                <flux:card>
                    <div class="flex items-center gap-4">
                        <div class="rounded-lg bg-amber-100 dark:bg-amber-900 p-3">
                            <flux:icon name="clock" class="h-6 w-6 text-amber-600 dark:text-amber-400" />
                        </div>
                        <div>
                            <flux:heading size="sm" class="text-zinc-500 dark:text-zinc-400">{{ __('Pending') }}</flux:heading>
                            <flux:text size="2xl" class="mt-1 font-semibold text-amber-600 dark:text-amber-400">${{ number_format($this->commissionStats['pending'], 2) }}</flux:text>
                        </div>
                    </div>
                </flux:card>

                <flux:card>
                    <div class="flex items-center gap-4">
                        <div class="rounded-lg bg-blue-100 dark:bg-blue-900 p-3">
                            <flux:icon name="calendar-days" class="h-6 w-6 text-blue-600 dark:text-blue-400" />
                        </div>
                        <div>
                            <flux:heading size="sm" class="text-zinc-500 dark:text-zinc-400">{{ __('This Month') }}</flux:heading>
                            <flux:text size="2xl" class="mt-1 font-semibold text-blue-600 dark:text-blue-400">${{ number_format($this->commissionStats['this_month'], 2) }}</flux:text>
                        </div>
                    </div>
                </flux:card>
            </div>
            <flux:text class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('Your commission rate: :rate%', ['rate' => $this->commissionRate]) }}
            </flux:text>
        </div>

        <!-- Existing Stats Grid -->
        <div>
            <flux:heading size="lg" class="mb-4">{{ __('Listings & Referrals') }}</flux:heading>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <flux:card>
                    <div class="flex items-center gap-4">
                        <div class="rounded-lg bg-blue-100 dark:bg-blue-900 p-3">
                            <flux:icon name="building-office" class="h-6 w-6 text-blue-600 dark:text-blue-400" />
                        </div>
                        <div>
                            <flux:heading size="sm" class="text-zinc-500 dark:text-zinc-400">{{ __('Total Listings') }}</flux:heading>
                            <flux:text size="2xl" class="mt-1 font-semibold">{{ $this->totalListings }}</flux:text>
                        </div>
                    </div>
                </flux:card>

                <flux:card>
                    <div class="flex items-center gap-4">
                        <div class="rounded-lg bg-green-100 dark:bg-green-900 p-3">
                            <flux:icon name="check-circle" class="h-6 w-6 text-green-600 dark:text-green-400" />
                        </div>
                        <div>
                            <flux:heading size="sm" class="text-zinc-500 dark:text-zinc-400">{{ __('Active Listings') }}</flux:heading>
                            <flux:text size="2xl" class="mt-1 font-semibold">{{ $this->activeListings }}</flux:text>
                        </div>
                    </div>
                </flux:card>

                <flux:card>
                    <div class="flex items-center gap-4">
                        <div class="rounded-lg bg-purple-100 dark:bg-purple-900 p-3">
                            <flux:icon name="users" class="h-6 w-6 text-purple-600 dark:text-purple-400" />
                        </div>
                        <div>
                            <flux:heading size="sm" class="text-zinc-500 dark:text-zinc-400">{{ __('Referred Customers') }}</flux:heading>
                            <flux:text size="2xl" class="mt-1 font-semibold">{{ $this->referredCustomers }}</flux:text>
                        </div>
                    </div>
                </flux:card>

                <flux:card>
                    <div class="flex items-center gap-4">
                        <div class="rounded-lg bg-orange-100 dark:bg-orange-900 p-3">
                            <flux:icon name="calendar" class="h-6 w-6 text-orange-600 dark:text-orange-400" />
                        </div>
                        <div>
                            <flux:heading size="sm" class="text-zinc-500 dark:text-zinc-400">{{ __('Upcoming Open Houses') }}</flux:heading>
                            <flux:text size="2xl" class="mt-1 font-semibold">{{ $this->upcomingOpenHousesCount }}</flux:text>
                        </div>
                    </div>
                </flux:card>
            </div>
        </div>

        <!-- Performance Metrics -->
        <div>
            <flux:heading size="lg" class="mb-4">{{ __('Performance Metrics') }}</flux:heading>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <flux:card>
                    <div class="flex items-center gap-4">
                        <div class="rounded-lg bg-indigo-100 dark:bg-indigo-900 p-3">
                            <flux:icon name="chart-bar" class="h-6 w-6 text-indigo-600 dark:text-indigo-400" />
                        </div>
                        <div>
                            <flux:heading size="sm" class="text-zinc-500 dark:text-zinc-400">{{ __('Conversion Rate') }}</flux:heading>
                            <flux:text size="2xl" class="mt-1 font-semibold">{{ $this->performanceMetrics['conversion_rate'] }}%</flux:text>
                        </div>
                    </div>
                </flux:card>

                <flux:card>
                    <div class="flex items-center gap-4">
                        <div class="rounded-lg bg-cyan-100 dark:bg-cyan-900 p-3">
                            <flux:icon name="calculator" class="h-6 w-6 text-cyan-600 dark:text-cyan-400" />
                        </div>
                        <div>
                            <flux:heading size="sm" class="text-zinc-500 dark:text-zinc-400">{{ __('Avg Deal Value') }}</flux:heading>
                            <flux:text size="2xl" class="mt-1 font-semibold">${{ number_format($this->performanceMetrics['average_deal_value'], 0) }}</flux:text>
                        </div>
                    </div>
                </flux:card>

                <flux:card>
                    <div class="flex items-center gap-4">
                        <div class="rounded-lg bg-rose-100 dark:bg-rose-900 p-3">
                            <flux:icon name="banknotes" class="h-6 w-6 text-rose-600 dark:text-rose-400" />
                        </div>
                        <div>
                            <flux:heading size="sm" class="text-zinc-500 dark:text-zinc-400">{{ __('Total Referral Value') }}</flux:heading>
                            <flux:text size="2xl" class="mt-1 font-semibold">${{ number_format($this->performanceMetrics['total_referral_value'], 0) }}</flux:text>
                        </div>
                    </div>
                </flux:card>
            </div>
        </div>

        <!-- Deals by Stage -->
        <flux:card>
            <flux:heading size="lg" class="mb-4">{{ __('Deals by Stage') }}</flux:heading>
            @if (array_sum($this->dealsByStage) > 0)
                <div class="grid grid-cols-2 sm:grid-cols-4 lg:grid-cols-7 gap-4">
                    @foreach ($this->dealsByStage as $stage => $count)
                        <div wire:key="stage-{{ $stage }}" class="text-center p-3 rounded-lg bg-zinc-50 dark:bg-zinc-800">
                            <flux:badge size="sm" color="{{ $this->getStageBadgeColor($stage) }}">
                                {{ \App\Models\Deal::STAGES[$stage] ?? ucfirst($stage) }}
                            </flux:badge>
                            <flux:text size="xl" class="mt-2 font-bold">{{ $count }}</flux:text>
                        </div>
                    @endforeach
                </div>
            @else
                <div class="text-center py-8">
                    <flux:icon name="folder-open" class="mx-auto h-12 w-12 text-zinc-400" />
                    <flux:text class="mt-4 text-zinc-500">{{ __('No deals yet.') }}</flux:text>
                </div>
            @endif
        </flux:card>

        <!-- Monthly Deal Trend -->
        <flux:card>
            <flux:heading size="lg" class="mb-4">{{ __('Deal Trend (Last 6 Months)') }}</flux:heading>
            <div class="grid grid-cols-3 sm:grid-cols-6 gap-4">
                @foreach ($this->monthlyDealCounts as $month => $count)
                    <div wire:key="month-{{ $loop->index }}" class="text-center p-3 rounded-lg bg-zinc-50 dark:bg-zinc-800">
                        <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">{{ $month }}</flux:text>
                        <flux:text size="xl" class="mt-1 font-bold">{{ $count }}</flux:text>
                    </div>
                @endforeach
            </div>
        </flux:card>

        <!-- Content Sections -->
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <!-- Recent Deals -->
            <flux:card>
                <flux:heading size="lg" class="mb-4">{{ __('Recent Deals') }}</flux:heading>
                @if ($this->recentDeals->count() > 0)
                    <div class="space-y-4">
                        @foreach ($this->recentDeals as $deal)
                            <div wire:key="deal-{{ $deal->id }}" class="flex items-start gap-4 p-3 rounded-lg bg-zinc-50 dark:bg-zinc-800">
                                <div class="flex-shrink-0 rounded-lg bg-indigo-100 dark:bg-indigo-900 p-2">
                                    <flux:icon name="briefcase" class="h-5 w-5 text-indigo-600 dark:text-indigo-400" />
                                </div>
                                <div class="flex-1 min-w-0">
                                    <flux:text class="font-medium truncate">{{ $deal->title }}</flux:text>
                                    @if ($deal->customer)
                                        <flux:text class="text-sm text-zinc-500 dark:text-zinc-400 truncate">
                                            {{ $deal->customer->full_name }}
                                        </flux:text>
                                    @endif
                                    <div class="flex items-center gap-2 mt-1">
                                        <flux:badge size="sm" color="{{ $this->getStageBadgeColor($deal->stage) }}">
                                            {{ $deal->stage_label }}
                                        </flux:badge>
                                        @if ($deal->estimated_value)
                                            <flux:text class="text-sm font-medium">
                                                ${{ number_format($deal->estimated_value, 0) }}
                                            </flux:text>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-4 text-center">
                        <flux:button href="{{ route('agent.deals.index') }}" variant="ghost" size="sm">
                            {{ __('View All Deals') }}
                        </flux:button>
                    </div>
                @else
                    <div class="text-center py-8">
                        <flux:icon name="briefcase" class="mx-auto h-12 w-12 text-zinc-400" />
                        <flux:text class="mt-4 text-zinc-500">{{ __('No deals yet.') }}</flux:text>
                        <flux:text class="text-sm text-zinc-400">{{ __('Deals from your referred customers will appear here.') }}</flux:text>
                    </div>
                @endif
            </flux:card>

            <!-- Upcoming Open Houses -->
            <flux:card>
                <flux:heading size="lg" class="mb-4">{{ __('Upcoming Open Houses') }}</flux:heading>
                @if ($this->upcomingOpenHouses->count() > 0)
                    <div class="space-y-4">
                        @foreach ($this->upcomingOpenHouses as $listing)
                            <div wire:key="open-house-{{ $listing->id }}" class="flex items-start gap-4 p-3 rounded-lg bg-zinc-50 dark:bg-zinc-800">
                                <div class="flex-shrink-0 rounded-lg bg-orange-100 dark:bg-orange-900 p-2">
                                    <flux:icon name="home" class="h-5 w-5 text-orange-600 dark:text-orange-400" />
                                </div>
                                <div class="flex-1 min-w-0">
                                    <flux:text class="font-medium truncate">{{ $listing->full_address }}</flux:text>
                                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">
                                        {{ $listing->open_house_date->format('l, M j, Y \a\t g:i A') }}
                                    </flux:text>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="text-center py-8">
                        <flux:icon name="calendar" class="mx-auto h-12 w-12 text-zinc-400" />
                        <flux:text class="mt-4 text-zinc-500">{{ __('No upcoming open houses scheduled.') }}</flux:text>
                    </div>
                @endif
            </flux:card>
        </div>

        <!-- Second Row Content Sections -->
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <!-- Recent Customers -->
            <flux:card>
                <flux:heading size="lg" class="mb-4">{{ __('Recent Customers') }}</flux:heading>
                @if ($this->recentCustomers->count() > 0)
                    <div class="space-y-4">
                        @foreach ($this->recentCustomers as $customer)
                            <div wire:key="customer-{{ $customer->id }}" class="flex items-start gap-4 p-3 rounded-lg bg-zinc-50 dark:bg-zinc-800">
                                <div class="flex-shrink-0">
                                    <flux:avatar name="{{ $customer->full_name }}" size="sm" />
                                </div>
                                <div class="flex-1 min-w-0">
                                    <flux:text class="font-medium truncate">{{ $customer->full_name }}</flux:text>
                                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400 truncate">
                                        {{ $customer->email ?? $customer->phone ?? __('No contact info') }}
                                    </flux:text>
                                    @if ($customer->listing)
                                        <flux:text class="text-xs text-zinc-400 dark:text-zinc-500 truncate">
                                            {{ __('From: :address', ['address' => $customer->listing->address]) }}
                                        </flux:text>
                                    @endif
                                </div>
                                <div class="flex-shrink-0">
                                    <flux:badge size="sm" color="{{ $customer->isActive() ? 'green' : 'yellow' }}">
                                        {{ $customer->isActive() ? __('Active') : __('Inactive') }}
                                    </flux:badge>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    @if ($this->referredCustomers > 5)
                        <div class="mt-4 text-center">
                            <flux:button href="{{ route('agent.customers.index') }}" variant="ghost" size="sm">
                                {{ __('View All Customers') }}
                            </flux:button>
                        </div>
                    @endif
                @else
                    <div class="text-center py-8">
                        <flux:icon name="users" class="mx-auto h-12 w-12 text-zinc-400" />
                        <flux:text class="mt-4 text-zinc-500">{{ __('No customers referred yet.') }}</flux:text>
                    </div>
                @endif
            </flux:card>
        </div>
    </div>
</div>
