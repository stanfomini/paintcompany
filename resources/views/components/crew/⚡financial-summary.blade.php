<?php

use App\Models\Job;
use App\Models\JobPayable;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Title('Crew Financial Summary')]
class extends Component
{
    public User $user;

    public function boot(): void
    {
        if (! Auth::user()?->can('crew.view_financials')) {
            abort(403);
        }
    }

    public function mount(User $user): void
    {
        $this->user = $user->load('crewProfile.serviceCategory');
    }

    #[Computed]
    public function crewJobs(): \Illuminate\Support\Collection
    {
        return Job::whereHas('crews', fn ($q) => $q->where('crew_user_id', $this->user->id))
            ->with([
                'deal.customer.user',
                'crews' => fn ($q) => $q->where('crew_user_id', $this->user->id),
                'payables' => fn ($q) => $q->where('payee_user_id', $this->user->id),
            ])
            ->orderByDesc('created_at')
            ->get();
    }

    #[Computed]
    public function payables(): \Illuminate\Database\Eloquent\Collection
    {
        return JobPayable::forUser($this->user->id)
            ->with(['job'])
            ->orderByDesc('created_at')
            ->get();
    }

    #[Computed]
    public function totalEarned(): float
    {
        return (float) $this->payables
            ->whereIn('status', [JobPayable::STATUS_APPROVED, JobPayable::STATUS_PAID])
            ->sum('amount');
    }

    #[Computed]
    public function totalPaid(): float
    {
        return (float) $this->payables
            ->where('status', JobPayable::STATUS_PAID)
            ->sum('amount');
    }

    #[Computed]
    public function totalOutstanding(): float
    {
        return $this->totalEarned - $this->totalPaid;
    }
};
?>

<div>
    <div class="max-w-6xl mx-auto">
        {{-- Header --}}
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
            <div>
                <flux:heading size="xl">{{ $user->name }}</flux:heading>
                <div class="flex items-center gap-3 mt-1">
                    @if ($user->crewProfile?->serviceCategory)
                        <flux:badge variant="pill" color="blue">{{ $user->crewProfile->serviceCategory->name }}</flux:badge>
                    @endif
                    @if ($user->crewProfile?->crew_size)
                        <flux:text class="text-zinc-500">{{ __('Crew Size: :size', ['size' => $user->crewProfile->crew_size]) }}</flux:text>
                    @endif
                </div>
            </div>
        </div>

        {{-- Summary Cards --}}
        <div class="grid grid-cols-3 gap-2 sm:gap-4 mb-6">
            <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-3 sm:p-4">
                <flux:text class="text-xs sm:text-sm text-zinc-500">{{ __('Total Earned') }}</flux:text>
                <div class="text-lg sm:text-2xl font-bold text-zinc-900 dark:text-white mt-1">${{ number_format($this->totalEarned, 2) }}</div>
            </div>
            <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-3 sm:p-4">
                <flux:text class="text-xs sm:text-sm text-zinc-500">{{ __('Total Paid') }}</flux:text>
                <div class="text-lg sm:text-2xl font-bold text-green-600 dark:text-green-400 mt-1">${{ number_format($this->totalPaid, 2) }}</div>
            </div>
            <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-3 sm:p-4">
                <flux:text class="text-xs sm:text-sm text-zinc-500">{{ __('Outstanding') }}</flux:text>
                <div class="text-lg sm:text-2xl font-bold {{ $this->totalOutstanding > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-zinc-900 dark:text-white' }} mt-1">${{ number_format($this->totalOutstanding, 2) }}</div>
            </div>
        </div>

        {{-- Jobs Table --}}
        <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden mb-6">
            <div class="p-4 border-b border-zinc-200 dark:border-zinc-700">
                <flux:heading size="sm">{{ __('Jobs') }}</flux:heading>
            </div>

            @if ($this->crewJobs->isEmpty())
                <div class="text-center py-12 text-zinc-500">
                    {{ __('No jobs assigned to this crew member.') }}
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50">
                                <th class="text-left py-3 px-3 sm:px-4 font-semibold text-zinc-600 dark:text-zinc-400">{{ __('Job') }}</th>
                                <th class="text-left py-3 px-3 sm:px-4 font-semibold text-zinc-600 dark:text-zinc-400 hidden md:table-cell">{{ __('Customer') }}</th>
                                <th class="text-left py-3 px-3 sm:px-4 font-semibold text-zinc-600 dark:text-zinc-400">{{ __('Status') }}</th>
                                <th class="text-left py-3 px-3 sm:px-4 font-semibold text-zinc-600 dark:text-zinc-400 hidden sm:table-cell">{{ __('Dispatched') }}</th>
                                <th class="text-left py-3 px-3 sm:px-4 font-semibold text-zinc-600 dark:text-zinc-400 hidden lg:table-cell">{{ __('Responded') }}</th>
                                <th class="text-right py-3 px-3 sm:px-4 font-semibold text-zinc-600 dark:text-zinc-400">{{ __('Payables') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->crewJobs as $job)
                                @php
                                    $pivot = $job->crews->first()?->pivot;
                                    $jobPayables = $job->payables;
                                    $statusColor = match ($pivot?->status) {
                                        'accepted' => 'green',
                                        'dispatched' => 'blue',
                                        'rejected' => 'red',
                                        default => 'zinc',
                                    };
                                @endphp
                                <tr class="border-b border-zinc-100 dark:border-zinc-700/50" wire:key="job-{{ $job->id }}">
                                    <td class="py-3 px-3 sm:px-4">
                                        <div class="font-medium text-zinc-900 dark:text-white truncate max-w-[140px] sm:max-w-none">{{ $job->title }}</div>
                                        <div class="text-xs text-zinc-500">{{ $job->job_number }}</div>
                                    </td>
                                    <td class="py-3 px-3 sm:px-4 text-zinc-700 dark:text-zinc-300 hidden md:table-cell">
                                        {{ $job->deal?->customer?->user?->name ?? '-' }}
                                    </td>
                                    <td class="py-3 px-3 sm:px-4">
                                        <flux:badge variant="pill" :color="$statusColor">{{ ucfirst($pivot?->status ?? '-') }}</flux:badge>
                                    </td>
                                    <td class="py-3 px-3 sm:px-4 text-zinc-700 dark:text-zinc-300 hidden sm:table-cell">
                                        {{ $pivot?->dispatched_at ? \Carbon\Carbon::parse($pivot->dispatched_at)->format('M j, Y') : '-' }}
                                    </td>
                                    <td class="py-3 px-3 sm:px-4 text-zinc-700 dark:text-zinc-300 hidden lg:table-cell">
                                        {{ $pivot?->responded_at ? \Carbon\Carbon::parse($pivot->responded_at)->format('M j, Y') : '-' }}
                                    </td>
                                    <td class="py-3 px-3 sm:px-4 text-right font-medium text-zinc-900 dark:text-white">
                                        ${{ number_format($jobPayables->sum('amount'), 2) }}
                                    </td>
                                </tr>
                                @if ($jobPayables->isNotEmpty())
                                    <tr class="bg-zinc-50/50 dark:bg-zinc-900/30" wire:key="job-payables-{{ $job->id }}">
                                        <td colspan="6" class="px-3 sm:px-4 py-2">
                                            <div class="ml-2 sm:ml-4 space-y-1">
                                                @foreach ($jobPayables as $payable)
                                                    @php
                                                        $payableStatusColor = match ($payable->status) {
                                                            'pending' => 'amber',
                                                            'approved' => 'blue',
                                                            'paid' => 'green',
                                                            'void' => 'red',
                                                            default => 'zinc',
                                                        };
                                                    @endphp
                                                    <div class="flex items-center justify-between text-xs gap-2" wire:key="payable-inline-{{ $payable->id }}">
                                                        <div class="flex items-center gap-2 min-w-0">
                                                            <flux:badge variant="pill" :color="$payableStatusColor" size="sm">{{ ucfirst($payable->status) }}</flux:badge>
                                                            <span class="text-zinc-600 dark:text-zinc-400 truncate">{{ $payable->description }}</span>
                                                        </div>
                                                        <span class="font-medium text-zinc-900 dark:text-white shrink-0">${{ number_format($payable->amount, 2) }}</span>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- Payables Detail --}}
        <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">
            <div class="p-4 border-b border-zinc-200 dark:border-zinc-700">
                <flux:heading size="sm">{{ __('All Payables') }}</flux:heading>
            </div>

            @if ($this->payables->isEmpty())
                <div class="text-center py-12 text-zinc-500">
                    {{ __('No payables found for this crew member.') }}
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50">
                                <th class="text-left py-3 px-3 sm:px-4 font-semibold text-zinc-600 dark:text-zinc-400 hidden md:table-cell">{{ __('Job') }}</th>
                                <th class="text-left py-3 px-3 sm:px-4 font-semibold text-zinc-600 dark:text-zinc-400 hidden lg:table-cell">{{ __('Category') }}</th>
                                <th class="text-left py-3 px-3 sm:px-4 font-semibold text-zinc-600 dark:text-zinc-400">{{ __('Description') }}</th>
                                <th class="text-right py-3 px-3 sm:px-4 font-semibold text-zinc-600 dark:text-zinc-400">{{ __('Amount') }}</th>
                                <th class="text-left py-3 px-3 sm:px-4 font-semibold text-zinc-600 dark:text-zinc-400">{{ __('Status') }}</th>
                                <th class="text-left py-3 px-3 sm:px-4 font-semibold text-zinc-600 dark:text-zinc-400 hidden sm:table-cell">{{ __('Paid At') }}</th>
                                <th class="text-left py-3 px-3 sm:px-4 font-semibold text-zinc-600 dark:text-zinc-400 hidden lg:table-cell">{{ __('Reference') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->payables as $payable)
                                @php
                                    $statusColor = match ($payable->status) {
                                        'pending' => 'amber',
                                        'approved' => 'blue',
                                        'paid' => 'green',
                                        'void' => 'red',
                                        default => 'zinc',
                                    };
                                @endphp
                                <tr class="border-b border-zinc-100 dark:border-zinc-700/50" wire:key="payable-{{ $payable->id }}">
                                    <td class="py-3 px-3 sm:px-4 text-zinc-700 dark:text-zinc-300 hidden md:table-cell">{{ $payable->job?->title ?? '-' }}</td>
                                    <td class="py-3 px-3 sm:px-4 text-zinc-700 dark:text-zinc-300 hidden lg:table-cell">{{ JobPayable::CATEGORIES[$payable->category] ?? $payable->category }}</td>
                                    <td class="py-3 px-3 sm:px-4 text-zinc-900 dark:text-white">{{ $payable->description }}</td>
                                    <td class="py-3 px-3 sm:px-4 text-right font-medium text-zinc-900 dark:text-white">${{ number_format($payable->amount, 2) }}</td>
                                    <td class="py-3 px-3 sm:px-4">
                                        <flux:badge variant="pill" :color="$statusColor">{{ ucfirst($payable->status) }}</flux:badge>
                                    </td>
                                    <td class="py-3 px-3 sm:px-4 text-zinc-700 dark:text-zinc-300 hidden sm:table-cell">{{ $payable->paid_at?->format('M j, Y') ?? '-' }}</td>
                                    <td class="py-3 px-3 sm:px-4 text-zinc-700 dark:text-zinc-300 hidden lg:table-cell">{{ $payable->reference_number ?? '-' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
