<?php

use App\Models\CommissionEntry;
use App\Models\Deal;
use App\Models\DoorKnock;
use App\Models\Job;
use App\Models\JobPayable;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Title('Financial Summary')]
class extends Component {
    public User $user;

    public function boot(): void
    {
        if (! Auth::user()?->can('team.view_financials')) {
            abort(403);
        }
    }

    public function mount(User $user): void
    {
        $this->user = $user;
    }

    #[Computed]
    public function employeeProfile()
    {
        return $this->user->employeeProfile;
    }

    #[Computed]
    public function leadsGenerated()
    {
        return Deal::where('lead_generator_id', $this->user->id)
            ->with('customer')
            ->latest()
            ->get();
    }

    #[Computed]
    public function salesDeals()
    {
        return Deal::where('assigned_to', $this->user->id)
            ->with('customer')
            ->latest()
            ->get();
    }

    #[Computed]
    public function managedJobs()
    {
        return Job::where('production_manager_id', $this->user->id)
            ->with('deal.customer')
            ->latest()
            ->get();
    }

    #[Computed]
    public function commissionEntries()
    {
        $profileId = $this->employeeProfile?->id;
        if (! $profileId) {
            return collect();
        }

        return CommissionEntry::where('employee_profile_id', $profileId)
            ->latest('earned_date')
            ->get();
    }

    #[Computed]
    public function commissionTotals(): array
    {
        $entries = $this->commissionEntries;

        return [
            'pending' => $entries->where('status', 'pending')->sum('amount'),
            'approved' => $entries->where('status', 'approved')->sum('amount'),
            'paid' => $entries->where('status', 'paid')->sum('amount'),
            'total' => $entries->whereIn('status', ['pending', 'approved', 'paid'])->sum('amount'),
        ];
    }

    #[Computed]
    public function flatRateEntries()
    {
        $profileId = $this->employeeProfile?->id;
        if (! $profileId) {
            return collect();
        }

        return CommissionEntry::where('employee_profile_id', $profileId)
            ->where('commission_type', 'flat')
            ->latest('earned_date')
            ->get();
    }

    #[Computed]
    public function doorKnockStats(): array
    {
        $total = DoorKnock::where('user_id', $this->user->id)->count();
        $converted = DoorKnock::where('user_id', $this->user->id)->whereNotNull('deal_id')->count();

        return [
            'total' => $total,
            'converted' => $converted,
            'rate' => $total > 0 ? round(($converted / $total) * 100, 1) : 0,
        ];
    }

    #[Computed]
    public function payables()
    {
        return JobPayable::forUser($this->user->id)
            ->with('job')
            ->latest()
            ->get();
    }

    #[Computed]
    public function payableTotals(): array
    {
        $payables = $this->payables;

        return [
            'pending' => $payables->where('status', 'pending')->sum('amount'),
            'approved' => $payables->where('status', 'approved')->sum('amount'),
            'paid' => $payables->where('status', 'paid')->sum('amount'),
            'total_owed' => $payables->whereIn('status', ['pending', 'approved'])->sum('amount'),
        ];
    }

    #[Computed]
    public function summaryTotals(): array
    {
        return [
            'total_commission' => (float) $this->commissionTotals['total'],
            'total_payables_owed' => (float) $this->payableTotals['total_owed'],
            'total_paid_out' => (float) $this->commissionTotals['paid'] + (float) $this->payableTotals['paid'],
        ];
    }
};

?>

<div>
    {{-- Header --}}
    <div class="mb-6 flex items-center gap-4">
        <flux:avatar name="{{ $user->name }}" size="lg" />
        <div>
            <flux:heading size="xl">{{ $user->name }}</flux:heading>
            <flux:text class="text-zinc-500 dark:text-zinc-400">
                {{ ucfirst($user->type) }}
                @if ($this->employeeProfile?->job_title)
                    — {{ $this->employeeProfile->job_title }}
                @endif
            </flux:text>
        </div>
    </div>

    {{-- Summary Totals --}}
    <div class="mb-8 grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">Total Commission Earned</flux:text>
            <p class="mt-1 text-2xl font-bold text-zinc-900 dark:text-white">${{ number_format($this->summaryTotals['total_commission'], 2) }}</p>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">Total Payables Owed</flux:text>
            <p class="mt-1 text-2xl font-bold text-amber-600 dark:text-amber-400">${{ number_format($this->summaryTotals['total_payables_owed'], 2) }}</p>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">Total Paid Out</flux:text>
            <p class="mt-1 text-2xl font-bold text-green-600 dark:text-green-400">${{ number_format($this->summaryTotals['total_paid_out'], 2) }}</p>
        </div>
    </div>

    <div class="space-y-8">
        {{-- As Lead Generator --}}
        <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:heading size="lg" class="mb-4">As Lead Generator</flux:heading>

            @if ($this->leadsGenerated->isEmpty())
                <flux:text class="text-zinc-500 dark:text-zinc-400">No leads generated.</flux:text>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-zinc-500 dark:text-zinc-400">
                                <th class="pb-2 pr-4 font-medium">Deal</th>
                                <th class="pb-2 pr-4 font-medium">Customer</th>
                                <th class="pb-2 pr-4 font-medium">Stage</th>
                                <th class="pb-2 pr-4 font-medium text-right">Value</th>
                                <th class="pb-2 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                            @foreach ($this->leadsGenerated as $deal)
                                <tr wire:key="lead-{{ $deal->id }}">
                                    <td class="py-2 pr-4 text-zinc-900 dark:text-white">{{ $deal->title }}</td>
                                    <td class="py-2 pr-4 text-zinc-600 dark:text-zinc-400">{{ $deal->customer?->full_name ?? '--' }}</td>
                                    <td class="py-2 pr-4">
                                        <flux:badge size="sm">{{ $deal->stage_label }}</flux:badge>
                                    </td>
                                    <td class="py-2 pr-4 text-right text-zinc-900 dark:text-white">${{ number_format((float) ($deal->estimated_value ?? 0), 2) }}</td>
                                    <td class="py-2">
                                        <a href="{{ route('ar.show', $deal) }}" class="text-sm text-blue-600 hover:underline dark:text-blue-400">View</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- As Salesperson --}}
        <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:heading size="lg" class="mb-4">As Salesperson</flux:heading>

            @if ($this->salesDeals->isEmpty())
                <flux:text class="text-zinc-500 dark:text-zinc-400">No deals assigned as salesperson.</flux:text>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-zinc-500 dark:text-zinc-400">
                                <th class="pb-2 pr-4 font-medium">Deal</th>
                                <th class="pb-2 pr-4 font-medium">Customer</th>
                                <th class="pb-2 pr-4 font-medium">Stage</th>
                                <th class="pb-2 pr-4 font-medium text-right">Value</th>
                                <th class="pb-2 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                            @foreach ($this->salesDeals as $deal)
                                <tr wire:key="sales-{{ $deal->id }}">
                                    <td class="py-2 pr-4 text-zinc-900 dark:text-white">{{ $deal->title }}</td>
                                    <td class="py-2 pr-4 text-zinc-600 dark:text-zinc-400">{{ $deal->customer?->full_name ?? '--' }}</td>
                                    <td class="py-2 pr-4">
                                        <flux:badge size="sm">{{ $deal->stage_label }}</flux:badge>
                                    </td>
                                    <td class="py-2 pr-4 text-right text-zinc-900 dark:text-white">${{ number_format((float) ($deal->estimated_value ?? 0), 2) }}</td>
                                    <td class="py-2">
                                        <a href="{{ route('ar.show', $deal) }}" class="text-sm text-blue-600 hover:underline dark:text-blue-400">View</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- As Production Manager --}}
        <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:heading size="lg" class="mb-4">As Production Manager</flux:heading>

            @if ($this->managedJobs->isEmpty())
                <flux:text class="text-zinc-500 dark:text-zinc-400">No jobs managed.</flux:text>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-zinc-500 dark:text-zinc-400">
                                <th class="pb-2 pr-4 font-medium">Job</th>
                                <th class="pb-2 pr-4 font-medium">Customer</th>
                                <th class="pb-2 pr-4 font-medium">Stage</th>
                                <th class="pb-2 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                            @foreach ($this->managedJobs as $job)
                                <tr wire:key="job-{{ $job->id }}">
                                    <td class="py-2 pr-4 text-zinc-900 dark:text-white">{{ $job->title }} <span class="text-xs text-zinc-500">({{ $job->job_number }})</span></td>
                                    <td class="py-2 pr-4 text-zinc-600 dark:text-zinc-400">{{ $job->deal?->customer?->full_name ?? '--' }}</td>
                                    <td class="py-2 pr-4">
                                        <flux:badge size="sm">{{ $job->stage_label }}</flux:badge>
                                    </td>
                                    <td class="py-2">
                                        @if ($job->deal)
                                            <a href="{{ route('ar.show', $job->deal) }}" class="text-sm text-blue-600 hover:underline dark:text-blue-400">View Deal</a>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- Commissions Summary --}}
        <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:heading size="lg" class="mb-4">Commissions Summary</flux:heading>

            <div class="mb-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div>
                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">Pending</flux:text>
                    <p class="text-lg font-semibold text-amber-600 dark:text-amber-400">${{ number_format((float) $this->commissionTotals['pending'], 2) }}</p>
                </div>
                <div>
                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">Approved</flux:text>
                    <p class="text-lg font-semibold text-blue-600 dark:text-blue-400">${{ number_format((float) $this->commissionTotals['approved'], 2) }}</p>
                </div>
                <div>
                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">Paid</flux:text>
                    <p class="text-lg font-semibold text-green-600 dark:text-green-400">${{ number_format((float) $this->commissionTotals['paid'], 2) }}</p>
                </div>
                <div>
                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">Total</flux:text>
                    <p class="text-lg font-semibold text-zinc-900 dark:text-white">${{ number_format((float) $this->commissionTotals['total'], 2) }}</p>
                </div>
            </div>

            @if ($this->commissionEntries->isNotEmpty())
                <flux:separator class="my-4" />
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-zinc-500 dark:text-zinc-400">
                                <th class="pb-2 pr-4 font-medium">Source</th>
                                <th class="pb-2 pr-4 font-medium">Type</th>
                                <th class="pb-2 pr-4 font-medium text-right">Amount</th>
                                <th class="pb-2 pr-4 font-medium">Status</th>
                                <th class="pb-2 font-medium">Date</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                            @foreach ($this->commissionEntries as $entry)
                                <tr wire:key="commission-{{ $entry->id }}">
                                    <td class="py-2 pr-4 text-zinc-900 dark:text-white">{{ $entry->source_label ?? '--' }}</td>
                                    <td class="py-2 pr-4 text-zinc-600 dark:text-zinc-400">{{ str_replace('_', ' ', $entry->commission_type) }}</td>
                                    <td class="py-2 pr-4 text-right text-zinc-900 dark:text-white">${{ number_format((float) $entry->amount, 2) }}</td>
                                    <td class="py-2 pr-4">
                                        <flux:badge size="sm" color="{{ match($entry->status) { 'pending' => 'amber', 'approved' => 'blue', 'paid' => 'green', 'reversed' => 'red', default => 'zinc' } }}">
                                            {{ ucfirst($entry->status) }}
                                        </flux:badge>
                                    </td>
                                    <td class="py-2 text-zinc-600 dark:text-zinc-400">{{ $entry->earned_date?->format('M d, Y') ?? '--' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <flux:text class="text-zinc-500 dark:text-zinc-400">No commission entries.</flux:text>
            @endif
        </div>

        {{-- Flat Rates --}}
        <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:heading size="lg" class="mb-4">Flat Rates</flux:heading>

            @if ($this->flatRateEntries->isEmpty())
                <flux:text class="text-zinc-500 dark:text-zinc-400">No flat rate entries.</flux:text>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-zinc-500 dark:text-zinc-400">
                                <th class="pb-2 pr-4 font-medium">Source</th>
                                <th class="pb-2 pr-4 font-medium text-right">Amount</th>
                                <th class="pb-2 pr-4 font-medium">Status</th>
                                <th class="pb-2 font-medium">Date</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                            @foreach ($this->flatRateEntries as $entry)
                                <tr wire:key="flat-{{ $entry->id }}">
                                    <td class="py-2 pr-4 text-zinc-900 dark:text-white">{{ $entry->source_label ?? '--' }}</td>
                                    <td class="py-2 pr-4 text-right text-zinc-900 dark:text-white">${{ number_format((float) $entry->amount, 2) }}</td>
                                    <td class="py-2 pr-4">
                                        <flux:badge size="sm" color="{{ match($entry->status) { 'pending' => 'amber', 'approved' => 'blue', 'paid' => 'green', 'reversed' => 'red', default => 'zinc' } }}">
                                            {{ ucfirst($entry->status) }}
                                        </flux:badge>
                                    </td>
                                    <td class="py-2 text-zinc-600 dark:text-zinc-400">{{ $entry->earned_date?->format('M d, Y') ?? '--' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- Door Knock Activity --}}
        <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:heading size="lg" class="mb-4">Door Knock Activity</flux:heading>

            <div class="grid grid-cols-3 gap-4">
                <div>
                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">Total Knocks</flux:text>
                    <p class="text-lg font-semibold text-zinc-900 dark:text-white">{{ $this->doorKnockStats['total'] }}</p>
                </div>
                <div>
                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">Conversions</flux:text>
                    <p class="text-lg font-semibold text-green-600 dark:text-green-400">{{ $this->doorKnockStats['converted'] }}</p>
                </div>
                <div>
                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">Conversion Rate</flux:text>
                    <p class="text-lg font-semibold text-zinc-900 dark:text-white">{{ $this->doorKnockStats['rate'] }}%</p>
                </div>
            </div>
        </div>

        {{-- Payables Owed --}}
        <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:heading size="lg" class="mb-4">Payables Owed</flux:heading>

            @if ($this->payables->isEmpty())
                <flux:text class="text-zinc-500 dark:text-zinc-400">No payables.</flux:text>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-zinc-500 dark:text-zinc-400">
                                <th class="pb-2 pr-4 font-medium">Job</th>
                                <th class="pb-2 pr-4 font-medium">Description</th>
                                <th class="pb-2 pr-4 font-medium text-right">Amount</th>
                                <th class="pb-2 pr-4 font-medium">Status</th>
                                <th class="pb-2 font-medium">Paid At</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                            @foreach ($this->payables as $payable)
                                <tr wire:key="payable-{{ $payable->id }}">
                                    <td class="py-2 pr-4 text-zinc-900 dark:text-white">{{ $payable->job?->title ?? '--' }}</td>
                                    <td class="py-2 pr-4 text-zinc-600 dark:text-zinc-400">{{ $payable->description }}</td>
                                    <td class="py-2 pr-4 text-right text-zinc-900 dark:text-white">${{ number_format((float) $payable->amount, 2) }}</td>
                                    <td class="py-2 pr-4">
                                        <flux:badge size="sm" color="{{ match($payable->status) { 'pending' => 'amber', 'approved' => 'blue', 'paid' => 'green', 'void' => 'zinc', default => 'zinc' } }}">
                                            {{ JobPayable::STATUSES[$payable->status] ?? $payable->status }}
                                        </flux:badge>
                                    </td>
                                    <td class="py-2 text-zinc-600 dark:text-zinc-400">{{ $payable->paid_at?->format('M d, Y') ?? '--' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    </div>
</div>
