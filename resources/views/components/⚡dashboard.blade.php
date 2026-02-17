<?php

use App\Models\CommissionEntry;
use App\Models\Deal;
use App\Models\EmployeeContract;
use App\Models\EmployeeDocument;
use App\Models\EmployeeOnboardingProgress;
use App\Models\Estimate;
use App\Models\PayStub;
use App\Models\Schedule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts.app')]
#[Title('Dashboard')]
class extends Component {
    public function mount(): void
    {
        $employee = auth()->user()->employeeProfile;

        if ($employee && ! $employee->onboarding_completed_at && ! $employee->phone && ! $employee->address_line_1) {
            $this->redirect(route('employee.onboarding'));
        }
    }

    #[Computed]
    public function employeeProfile()
    {
        return auth()->user()->employeeProfile;
    }

    #[Computed]
    public function activeLeadsCount(): int
    {
        if (! auth()->user()->can('deals.view')) {
            return 0;
        }

        return Deal::where('assigned_to', auth()->id())->active()->count();
    }

    #[Computed]
    public function closingRatio(): float
    {
        if (! auth()->user()->can('deals.view')) {
            return 0;
        }

        $total = Deal::where('assigned_to', auth()->id())
            ->whereIn('stage', ['signed', 'completed', 'lost', 'cancelled'])
            ->count();

        if ($total === 0) {
            return 0;
        }

        $won = Deal::where('assigned_to', auth()->id())
            ->whereIn('stage', ['signed', 'completed'])
            ->count();

        return round(($won / $total) * 100, 1);
    }

    #[Computed]
    public function revenueThisMonth(): float
    {
        if (! auth()->user()->can('deals.view')) {
            return 0;
        }

        return (float) Estimate::query()
            ->where('status', 'accepted')
            ->whereMonth('updated_at', now()->month)
            ->whereYear('updated_at', now()->year)
            ->whereHas('deal', fn ($q) => $q->where('assigned_to', auth()->id()))
            ->sum('total');
    }

    #[Computed]
    public function pendingCommissions(): float
    {
        if (! $this->employeeProfile) {
            return 0;
        }

        return (float) CommissionEntry::query()
            ->forEmployee($this->employeeProfile->id)
            ->pending()
            ->sum('amount');
    }

    #[Computed]
    public function recentPayStubs()
    {
        if (! $this->employeeProfile) {
            return collect();
        }

        return PayStub::query()
            ->where('employee_profile_id', $this->employeeProfile->id)
            ->with('payPeriod')
            ->latest()
            ->limit(3)
            ->get();
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
            ->where('date', '<=', now()->addDays(7)->toDateString())
            ->orderBy('date')
            ->orderBy('start_time')
            ->with('job')
            ->get();
    }

    #[Computed]
    public function todaySchedule()
    {
        if (! $this->employeeProfile) {
            return null;
        }

        return Schedule::query()
            ->forEmployee($this->employeeProfile->id)
            ->forDate(now()->toDateString())
            ->orderBy('start_time')
            ->with('job')
            ->first();
    }

    #[Computed]
    public function ptoBalance(): float
    {
        if (! $this->employeeProfile) {
            return 0;
        }

        return (float) $this->employeeProfile->ptoRequests()
            ->where('status', 'approved')
            ->whereYear('start_date', now()->year)
            ->sum('hours');
    }

    #[Computed]
    public function unsignedContracts(): int
    {
        if (! $this->employeeProfile) {
            return 0;
        }

        return EmployeeContract::query()
            ->where('employee_profile_id', $this->employeeProfile->id)
            ->pending()
            ->count();
    }

    #[Computed]
    public function pendingOnboardingSteps(): int
    {
        if (! $this->employeeProfile) {
            return 0;
        }

        return EmployeeOnboardingProgress::query()
            ->where('employee_profile_id', $this->employeeProfile->id)
            ->where('status', 'pending')
            ->count();
    }

    #[Computed]
    public function recentCommissions()
    {
        if (! $this->employeeProfile) {
            return collect();
        }

        return CommissionEntry::query()
            ->forEmployee($this->employeeProfile->id)
            ->pending()
            ->latest('earned_date')
            ->limit(5)
            ->get();
    }

    #[Computed]
    public function pendingDocumentReviews(): int
    {
        if (! auth()->user()->can('employees.edit')) {
            return 0;
        }

        return EmployeeDocument::pending()->count();
    }

    #[Computed]
    public function expiringDocuments(): int
    {
        if (! auth()->user()->can('employees.edit')) {
            return 0;
        }

        return EmployeeDocument::expiringSoon()->where('status', 'approved')->count();
    }

    #[Computed]
    public function pendingContractSignatures(): int
    {
        if (! auth()->user()->can('employees.view')) {
            return 0;
        }

        return EmployeeContract::pending()->count();
    }
};
?>

<div>
    <div class="mb-6">
        <flux:heading size="xl">{{ __('Welcome back, :name', ['name' => auth()->user()->name]) }}</flux:heading>
        <flux:text class="mt-1">{{ now()->format('l, F j, Y') }}</flux:text>
    </div>

    @if($this->employeeProfile)
        @if(! $this->employeeProfile->onboarding_completed_at && ($this->employeeProfile->phone || $this->employeeProfile->address_line_1))
            <flux:callout variant="warning" class="mb-6">
                <div class="flex items-center justify-between w-full">
                    <div>
                        <span class="font-medium">{{ __('Onboarding in progress') }}</span>
                        @if($this->pendingOnboardingSteps > 0)
                            <span class="text-sm"> &mdash; {{ trans_choice(':count item remaining|:count items remaining', $this->pendingOnboardingSteps) }}</span>
                        @endif
                    </div>
                    <flux:button variant="primary" size="sm" :href="route('my.onboarding')" wire:navigate>
                        {{ __('Continue Onboarding') }}
                    </flux:button>
                </div>
            </flux:callout>
        @endif

        {{-- Quick Actions Row --}}
        <div class="mb-6 grid grid-cols-1 gap-4 md:grid-cols-3">
            {{-- Clock In/Out --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <div class="mb-3 flex items-center gap-2">
                    <flux:icon name="clock" class="size-5 text-zinc-500 dark:text-zinc-400" />
                    <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('Time Clock') }}</span>
                </div>
                    <livewire:employees.clock-button />
            </div>

            {{-- Today's Schedule --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <div class="mb-3 flex items-center gap-2">
                    <flux:icon name="calendar" class="size-5 text-zinc-500 dark:text-zinc-400" />
                    <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __("Today's Schedule") }}</span>
                </div>
                @if($this->todaySchedule)
                    <div class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">
                        {{ \Carbon\Carbon::parse($this->todaySchedule->start_time)->format('g:i A') }}
                        &ndash;
                        {{ \Carbon\Carbon::parse($this->todaySchedule->end_time)->format('g:i A') }}
                    </div>
                    @if($this->todaySchedule->job)
                        <flux:text size="sm" class="mt-1">{{ $this->todaySchedule->job->title ?? __('Job assigned') }}</flux:text>
                    @endif
                @else
                    <flux:text size="sm">{{ __('No shifts today') }}</flux:text>
                @endif
            </div>

            {{-- PTO Balance --}}
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <div class="mb-3 flex items-center gap-2">
                    <flux:icon name="sun" class="size-5 text-zinc-500 dark:text-zinc-400" />
                    <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('PTO Used This Year') }}</span>
                </div>
                <div class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">
                    {{ number_format($this->ptoBalance, 1) }} {{ __('hours') }}
                </div>
                <div class="mt-1">
                    <flux:button variant="subtle" size="sm" :href="route('my.pto')" wire:navigate>
                        {{ __('Request PTO') }}
                    </flux:button>
                </div>
            </div>
        </div>

        {{-- Stats Cards (only for users with deals.view permission) --}}
        @can('deals.view')
            <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <div class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('My Active Leads') }}</div>
                    <div class="mt-1 text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $this->activeLeadsCount }}</div>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <div class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Closing Ratio') }}</div>
                    <div class="mt-1 text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $this->closingRatio }}%</div>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <div class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Revenue This Month') }}</div>
                    <div class="mt-1 text-2xl font-bold text-zinc-900 dark:text-zinc-100">${{ number_format($this->revenueThisMonth, 2) }}</div>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <div class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Pending Commissions') }}</div>
                    <div class="mt-1 text-2xl font-bold text-zinc-900 dark:text-zinc-100">${{ number_format($this->pendingCommissions, 2) }}</div>
                </div>
            </div>
        @endcan

        {{-- Bottom Section: Two Columns --}}
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            {{-- Left Column --}}
            <div class="space-y-6">
                {{-- Recent Pay Stubs --}}
                <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                    <div class="flex items-center justify-between border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                        <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('Recent Pay Stubs') }}</span>
                        <flux:button variant="subtle" size="sm" :href="route('my.pay-stubs')" wire:navigate>
                            {{ __('View All') }}
                        </flux:button>
                    </div>
                    @if($this->recentPayStubs->isEmpty())
                        <div class="px-4 py-6 text-center">
                            <flux:text size="sm">{{ __('No pay stubs yet.') }}</flux:text>
                        </div>
                    @else
                        <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach($this->recentPayStubs as $stub)
                                <div wire:key="stub-{{ $stub->id }}" class="flex items-center justify-between px-4 py-3">
                                    <div>
                                        <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                            {{ $stub->payPeriod?->start_date?->format('M j') }} &ndash; {{ $stub->payPeriod?->end_date?->format('M j') }}
                                        </div>
                                        <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                            {{ __('Check') }} #{{ $stub->check_number ?? '-' }}
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-sm font-semibold text-green-700 dark:text-green-400">${{ number_format($stub->net_pay, 2) }}</div>
                                        <flux:badge :color="$stub->isFinalized() ? 'green' : 'amber'" size="sm">{{ ucfirst($stub->status) }}</flux:badge>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Pending Commissions --}}
                <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                    <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                        <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('Pending Commissions') }}</span>
                    </div>
                    @if($this->recentCommissions->isEmpty())
                        <div class="px-4 py-6 text-center">
                            <flux:text size="sm">{{ __('No pending commissions.') }}</flux:text>
                        </div>
                    @else
                        <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach($this->recentCommissions as $entry)
                                <div wire:key="comm-{{ $entry->id }}" class="flex items-center justify-between px-4 py-3">
                                    <div>
                                        <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $entry->source_label }}</div>
                                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $entry->earned_date?->format('M j, Y') }}</div>
                                    </div>
                                    <div class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">${{ number_format($entry->amount, 2) }}</div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            {{-- Right Column --}}
            <div class="space-y-6">
                {{-- Upcoming Schedule --}}
                <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                    <div class="flex items-center justify-between border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                        <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('Upcoming Schedule') }}</span>
                        <flux:button variant="subtle" size="sm" :href="route('my.schedule')" wire:navigate>
                            {{ __('View All') }}
                        </flux:button>
                    </div>
                    @if($this->upcomingSchedules->isEmpty())
                        <div class="px-4 py-6 text-center">
                            <flux:text size="sm">{{ __('No upcoming shifts.') }}</flux:text>
                        </div>
                    @else
                        <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @foreach($this->upcomingSchedules as $schedule)
                                <div wire:key="sched-{{ $schedule->id }}" class="flex items-center justify-between px-4 py-3">
                                    <div>
                                        <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                            {{ $schedule->date->format('D, M j') }}
                                        </div>
                                        <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                            {{ \Carbon\Carbon::parse($schedule->start_time)->format('g:i A') }}
                                            &ndash;
                                            {{ \Carbon\Carbon::parse($schedule->end_time)->format('g:i A') }}
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        @if($schedule->job)
                                            <div class="text-sm text-zinc-700 dark:text-zinc-300">{{ $schedule->job->title ?? '' }}</div>
                                        @endif
                                        <flux:badge :color="$schedule->isConfirmed() ? 'green' : 'amber'" size="sm">
                                            {{ ucfirst($schedule->status) }}
                                        </flux:badge>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                {{-- Action Items --}}
                <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                    <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                        <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('Action Items') }}</span>
                    </div>
                    @php
                        $hasEmployeeActions = $this->unsignedContracts > 0 || $this->pendingOnboardingSteps > 0;
                        $hasAdminActions = $this->pendingDocumentReviews > 0 || $this->expiringDocuments > 0 || $this->pendingContractSignatures > 0;
                    @endphp
                    @if(! $hasEmployeeActions && ! $hasAdminActions)
                        <div class="px-4 py-6 text-center">
                            <flux:icon name="check-circle" class="mx-auto mb-2 size-8 text-green-500" />
                            <flux:text size="sm">{{ __('All caught up!') }}</flux:text>
                        </div>
                    @else
                        <div class="divide-y divide-zinc-200 dark:divide-zinc-700">
                            @if($this->unsignedContracts > 0)
                                <a href="{{ route('my.contracts') }}" wire:navigate class="flex items-center gap-3 px-4 py-3 transition hover:bg-zinc-50 dark:hover:bg-zinc-700/50">
                                    <flux:icon name="document-text" class="size-5 text-amber-500" />
                                    <div class="flex-1">
                                        <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                            {{ trans_choice(':count unsigned contract|:count unsigned contracts', $this->unsignedContracts) }}
                                        </div>
                                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Requires your signature') }}</div>
                                    </div>
                                    <flux:icon name="chevron-right" class="size-4 text-zinc-400" />
                                </a>
                            @endif
                            @if($this->pendingOnboardingSteps > 0)
                                <a href="{{ route('my.onboarding') }}" wire:navigate class="flex items-center gap-3 px-4 py-3 transition hover:bg-zinc-50 dark:hover:bg-zinc-700/50">
                                    <flux:icon name="clipboard-document-check" class="size-5 text-blue-500" />
                                    <div class="flex-1">
                                        <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                            {{ trans_choice(':count onboarding step remaining|:count onboarding steps remaining', $this->pendingOnboardingSteps) }}
                                        </div>
                                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Complete your onboarding') }}</div>
                                    </div>
                                    <flux:icon name="chevron-right" class="size-4 text-zinc-400" />
                                </a>
                            @endif
                            @if($this->pendingDocumentReviews > 0)
                                <a href="{{ route('document-center') }}" wire:navigate class="flex items-center gap-3 px-4 py-3 transition hover:bg-zinc-50 dark:hover:bg-zinc-700/50">
                                    <flux:icon name="folder-open" class="size-5 text-amber-500" />
                                    <div class="flex-1">
                                        <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                            {{ trans_choice(':count document pending review|:count documents pending review', $this->pendingDocumentReviews) }}
                                        </div>
                                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Employee uploads need review') }}</div>
                                    </div>
                                    <flux:icon name="chevron-right" class="size-4 text-zinc-400" />
                                </a>
                            @endif
                            @if($this->pendingContractSignatures > 0)
                                <a href="{{ route('document-center') }}?tab=pending-signatures" wire:navigate class="flex items-center gap-3 px-4 py-3 transition hover:bg-zinc-50 dark:hover:bg-zinc-700/50">
                                    <flux:icon name="pencil-square" class="size-5 text-blue-500" />
                                    <div class="flex-1">
                                        <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                            {{ trans_choice(':count contract awaiting signature|:count contracts awaiting signature', $this->pendingContractSignatures) }}
                                        </div>
                                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Contracts sent but not yet signed') }}</div>
                                    </div>
                                    <flux:icon name="chevron-right" class="size-4 text-zinc-400" />
                                </a>
                            @endif
                            @if($this->expiringDocuments > 0)
                                <a href="{{ route('document-center') }}?tab=expiring" wire:navigate class="flex items-center gap-3 px-4 py-3 transition hover:bg-zinc-50 dark:hover:bg-zinc-700/50">
                                    <flux:icon name="exclamation-triangle" class="size-5 text-red-500" />
                                    <div class="flex-1">
                                        <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                            {{ trans_choice(':count document expiring soon|:count documents expiring soon', $this->expiringDocuments) }}
                                        </div>
                                        <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Within the next 30 days') }}</div>
                                    </div>
                                    <flux:icon name="chevron-right" class="size-4 text-zinc-400" />
                                </a>
                            @endif
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @else
        {{-- No Employee Profile --}}
        <div class="rounded-xl border border-zinc-200 bg-white p-12 text-center dark:border-zinc-700 dark:bg-zinc-800">
            <flux:icon name="user-circle" class="mx-auto mb-3 size-12 text-zinc-400 dark:text-zinc-500" />
            <flux:heading size="lg">{{ __('Welcome to Apex Negotiations') }}</flux:heading>
            <flux:text class="mt-2">
                {{ __('Your employee profile has not been set up yet. Contact your administrator to get started.') }}
            </flux:text>
        </div>

        {{-- Still show deals stats if user has permission --}}
        @can('deals.view')
            <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <div class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('My Active Leads') }}</div>
                    <div class="mt-1 text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $this->activeLeadsCount }}</div>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <div class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Closing Ratio') }}</div>
                    <div class="mt-1 text-2xl font-bold text-zinc-900 dark:text-zinc-100">{{ $this->closingRatio }}%</div>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <div class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Revenue This Month') }}</div>
                    <div class="mt-1 text-2xl font-bold text-zinc-900 dark:text-zinc-100">${{ number_format($this->revenueThisMonth, 2) }}</div>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                    <div class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Pending Commissions') }}</div>
                    <div class="mt-1 text-2xl font-bold text-zinc-900 dark:text-zinc-100">${{ number_format($this->pendingCommissions, 2) }}</div>
                </div>
            </div>
        @endcan
    @endif
</div>
