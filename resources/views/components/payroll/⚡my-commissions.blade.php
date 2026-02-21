<?php

use App\Models\CommissionEntry;
use App\Models\User;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new
#[Layout('layouts.app')]
#[Title('My Commissions')]
class extends Component
{
    use WithPagination;

    public string $statusFilter = 'all';

    public function boot(): void
    {
        $user = auth()->user();

        // Allow access if user has an employee profile OR has any commission entries as User
        $hasEmployeeProfile = $user?->employeeProfile !== null;
        $hasUserCommissions = $user && CommissionEntry::query()
            ->where('commissionable_type', User::class)
            ->where('commissionable_id', $user->id)
            ->exists();

        if (! $hasEmployeeProfile && ! $hasUserCommissions) {
            abort(403);
        }
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Build the base query that finds all commissions for this user
     * across both legacy employee_profile_id and polymorphic commissionable columns.
     */
    protected function myCommissionsQuery()
    {
        $user = auth()->user();
        $profileId = $user->employeeProfile?->id;

        return CommissionEntry::query()
            ->where(function ($q) use ($user, $profileId) {
                if ($profileId) {
                    $q->where('employee_profile_id', $profileId);
                }
                $q->orWhere(function ($q2) use ($user) {
                    $q2->where('commissionable_type', User::class)
                        ->where('commissionable_id', $user->id);
                });
            });
    }

    #[Computed]
    public function commissions()
    {
        return $this->myCommissionsQuery()
            ->when($this->statusFilter !== 'all', fn ($q) => $q->where('status', $this->statusFilter))
            ->orderByDesc('earned_date')
            ->orderByDesc('id')
            ->paginate(20);
    }

    #[Computed]
    public function summaryCards(): array
    {
        $pending = (clone $this->myCommissionsQuery())
            ->where('status', 'pending')
            ->sum('amount');

        $approved = (clone $this->myCommissionsQuery())
            ->where('status', 'approved')
            ->sum('amount');

        $paidThisMonth = (clone $this->myCommissionsQuery())
            ->where('status', 'paid')
            ->whereMonth('earned_date', now()->month)
            ->whereYear('earned_date', now()->year)
            ->sum('amount');

        $ytdTotal = (clone $this->myCommissionsQuery())
            ->whereIn('status', ['paid', 'approved'])
            ->whereYear('earned_date', now()->year)
            ->sum('amount');

        return [
            'pending' => (float) $pending,
            'approved' => (float) $approved,
            'paidThisMonth' => (float) $paidThisMonth,
            'ytdTotal' => (float) $ytdTotal,
        ];
    }
};
?>

<div>
    <div class="mb-6">
        <flux:heading size="xl">{{ __('My Commissions') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Track your commission earnings and payouts.') }}</flux:text>
    </div>

    {{-- Summary Cards --}}
    <div class="mb-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <div class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Pending') }}</div>
            <div class="mt-1 text-2xl font-bold text-amber-600 dark:text-amber-400">${{ number_format($this->summaryCards['pending'], 2) }}</div>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <div class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Approved') }}</div>
            <div class="mt-1 text-2xl font-bold text-blue-600 dark:text-blue-400">${{ number_format($this->summaryCards['approved'], 2) }}</div>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <div class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Paid This Month') }}</div>
            <div class="mt-1 text-2xl font-bold text-green-700 dark:text-green-400">${{ number_format($this->summaryCards['paidThisMonth'], 2) }}</div>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <div class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('YTD Total') }}</div>
            <div class="mt-1 text-2xl font-bold text-zinc-900 dark:text-zinc-100">${{ number_format($this->summaryCards['ytdTotal'], 2) }}</div>
        </div>
    </div>

    {{-- Status Filter --}}
    <div class="mb-4">
        <flux:select wire:model.live="statusFilter" class="w-40">
            <option value="all">{{ __('All Statuses') }}</option>
            <option value="pending">{{ __('Pending') }}</option>
            <option value="approved">{{ __('Approved') }}</option>
            <option value="paid">{{ __('Paid') }}</option>
            <option value="reversed">{{ __('Reversed') }}</option>
        </flux:select>
    </div>

    {{-- Commissions Table --}}
    @if($this->commissions->isEmpty())
        <div class="rounded-xl border border-zinc-200 bg-white p-12 text-center dark:border-zinc-700 dark:bg-zinc-800">
            <flux:icon name="currency-dollar" class="mx-auto mb-3 size-10 text-zinc-400 dark:text-zinc-500" />
            <flux:heading size="lg">{{ __('No Commissions Yet') }}</flux:heading>
            <flux:text class="mt-1">{{ __('Your commission entries will appear here as they are earned.') }}</flux:text>
        </div>
    @else
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                <thead class="bg-zinc-50 dark:bg-zinc-900">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Date') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Source') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Type') }}</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Amount') }}</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">{{ __('Status') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach($this->commissions as $entry)
                        <tr wire:key="commission-{{ $entry->id }}">
                            <td class="px-4 py-3 text-sm text-zinc-900 dark:text-zinc-100">
                                {{ $entry->earned_date?->format('M j, Y') ?? '-' }}
                            </td>
                            <td class="px-4 py-3 text-sm text-zinc-600 dark:text-zinc-400">
                                {{ $entry->source_label ?? '-' }}
                            </td>
                            <td class="px-4 py-3 text-sm text-zinc-600 dark:text-zinc-400">
                                {{ match($entry->commission_type) {
                                    'revenue_percentage' => __('Revenue %'),
                                    'profit_percentage' => __('Profit %'),
                                    'flat' => __('Flat'),
                                    default => ucfirst(str_replace('_', ' ', $entry->commission_type)),
                                } }}
                            </td>
                            <td class="px-4 py-3 text-right text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                ${{ number_format($entry->amount, 2) }}
                            </td>
                            <td class="px-4 py-3">
                                <flux:badge
                                    size="sm"
                                    :color="match($entry->status) {
                                        'pending' => 'yellow',
                                        'approved' => 'blue',
                                        'paid' => 'green',
                                        'reversed' => 'red',
                                        default => 'zinc',
                                    }"
                                >
                                    {{ ucfirst($entry->status) }}
                                </flux:badge>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $this->commissions->links() }}
        </div>
    @endif
</div>
