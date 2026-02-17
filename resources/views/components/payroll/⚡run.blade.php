<?php

use App\Models\CommissionEntry;
use App\Models\EmployeeProfile;
use App\Models\PayPeriod;
use App\Models\PayStub;
use App\Services\PayrollService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts.app')]
#[Title('Run Payroll')]
class extends Component {
    public PayPeriod $period;

    public int $step = 1;

    /** @var array<int, array{regular: string, overtime: string, pto: string}> */
    public array $hoursData = [];

    /** @var array<int, PayStub> */
    public array $previewStubs = [];

    public function boot(): void
    {
        if (! auth()->user()?->can('payroll.view')) {
            abort(403);
        }
    }

    public function mount(PayPeriod $period): void
    {
        $this->period = $period;

        // If completed, show step 3 (review)
        if ($period->isCompleted()) {
            $this->step = 3;
            return;
        }

        // If already has stubs (processing), skip to step 2
        if ($period->payStubs()->exists()) {
            $this->step = 2;
            return;
        }

        $this->initializeHoursData();
    }

    protected function initializeHoursData(): void
    {
        $employees = EmployeeProfile::query()
            ->where('employment_status', 'active')
            ->where(fn ($q) => $q->whereNotNull('pay_rate')->orWhere('pay_type', 'commission'))
            ->with('user')
            ->orderBy('employee_number')
            ->get();

        foreach ($employees as $emp) {
            if (! isset($this->hoursData[$emp->id])) {
                $this->hoursData[$emp->id] = [
                    'regular' => $emp->isCommissionOnly() ? '0.00' : '40.00',
                    'overtime' => '0.00',
                    'pto' => '0.00',
                ];
            }
        }
    }

    #[Computed]
    public function employees()
    {
        return EmployeeProfile::query()
            ->where('employment_status', 'active')
            ->where(fn ($q) => $q->whereNotNull('pay_rate')->orWhere('pay_type', 'commission'))
            ->with('user')
            ->orderBy('employee_number')
            ->get();
    }

    /**
     * Get pending (approved, unpaid) commission totals per employee for this period.
     *
     * @return array<int, float> Keyed by employee_profile_id
     */
    #[Computed]
    public function pendingCommissions(): array
    {
        return CommissionEntry::query()
            ->where('status', 'approved')
            ->whereNull('pay_stub_id')
            ->where('earned_date', '<=', $this->period->end_date)
            ->selectRaw('employee_profile_id, SUM(amount) as total')
            ->groupBy('employee_profile_id')
            ->pluck('total', 'employee_profile_id')
            ->map(fn ($v) => round((float) $v, 2))
            ->all();
    }

    #[Computed]
    public function stubs()
    {
        return $this->period->payStubs()
            ->with('employeeProfile.user')
            ->get();
    }

    #[Computed]
    public function totals(): array
    {
        $stubs = $this->stubs;

        return [
            'gross' => $stubs->sum('gross_pay'),
            'commission' => $stubs->sum('commission_amount'),
            'federal_tax' => $stubs->sum('federal_tax'),
            'state_tax' => $stubs->sum('state_tax'),
            'social_security' => $stubs->sum('social_security'),
            'medicare' => $stubs->sum('medicare') + $stubs->sum('additional_medicare'),
            'total_deductions' => $stubs->sum('total_deductions'),
            'net' => $stubs->sum('net_pay'),
        ];
    }

    public function generatePreview(): void
    {
        if (! auth()->user()?->can('payroll.manage')) {
            abort(403);
        }

        $this->validate([
            'hoursData.*.regular' => ['required', 'numeric', 'min:0', 'max:168'],
            'hoursData.*.overtime' => ['required', 'numeric', 'min:0', 'max:80'],
            'hoursData.*.pto' => ['required', 'numeric', 'min:0', 'max:80'],
        ]);

        $service = app(PayrollService::class);

        $formattedHours = [];
        foreach ($this->hoursData as $empId => $hours) {
            $formattedHours[$empId] = [
                'regular' => (float) $hours['regular'],
                'overtime' => (float) $hours['overtime'],
                'pto' => (float) $hours['pto'],
            ];
        }

        $service->runPayroll($this->period, $formattedHours);

        $this->step = 2;
        unset($this->stubs, $this->totals);
    }

    public function finalizePayroll(): void
    {
        if (! auth()->user()?->can('payroll.manage')) {
            abort(403);
        }

        $service = app(PayrollService::class);
        $service->finalizePayroll($this->period, auth()->user());

        $this->period->refresh();
        $this->step = 3;
        unset($this->stubs, $this->totals);
    }

    public function importHoursFromTimeEntries(): void
    {
        if (! auth()->user()?->can('payroll.manage')) {
            abort(403);
        }

        $service = app(PayrollService::class);
        $imported = $service->calculateHoursFromTimeEntries($this->period);

        $count = 0;
        foreach ($imported as $empId => $hours) {
            if (isset($this->hoursData[$empId])) {
                $this->hoursData[$empId] = [
                    'regular' => number_format($hours['regular'], 2, '.', ''),
                    'overtime' => number_format($hours['overtime'], 2, '.', ''),
                    'pto' => number_format($hours['pto'], 2, '.', ''),
                ];
                $count++;
            }
        }

        session()->flash('message', __(':count employee(s) hours imported from time entries.', ['count' => $count]));
    }

    public function goBack(): void
    {
        if ($this->step === 2 && ! $this->period->isCompleted()) {
            // Delete draft stubs and go back
            $this->period->payStubs()->where('status', 'draft')->delete();
            $this->period->update(['status' => 'open']);
            $this->step = 1;
            unset($this->stubs, $this->totals, $this->pendingCommissions);

            $this->initializeHoursData();
        }
    }
};
?>

<div>
    <div class="mb-6">
        <div class="flex items-center gap-2 text-sm text-zinc-500 dark:text-zinc-400 mb-2">
            <a href="{{ route('payroll.index') }}" class="hover:text-zinc-700 dark:hover:text-zinc-200" wire:navigate>Payroll</a>
            <span>/</span>
            <span>{{ $period->start_date->format('M j') }} - {{ $period->end_date->format('M j, Y') }}</span>
        </div>

        <div class="flex items-center justify-between">
            <div>
                <flux:heading size="xl">
                    @if($period->isCompleted())
                        Payroll Summary
                    @else
                        Run Payroll
                    @endif
                </flux:heading>
                <flux:text class="mt-1">
                    {{ ucfirst($period->type) }} period: {{ $period->start_date->format('M j, Y') }} - {{ $period->end_date->format('M j, Y') }}
                    ({{ $period->getDurationDays() }} days)
                </flux:text>
            </div>

            <flux:badge
                :variant="match($period->status) { 'open' => 'warning', 'processing' => 'primary', 'completed' => 'success', default => 'outline' }"
            >
                {{ ucfirst($period->status) }}
            </flux:badge>
        </div>
    </div>

    {{-- Step Indicators --}}
    <div class="mb-8 flex items-center gap-4">
        @foreach([1 => 'Enter Hours', 2 => 'Review & Preview', 3 => 'Finalized'] as $num => $label)
            <div class="flex items-center gap-2 {{ $step >= $num ? 'text-blue-600 dark:text-blue-400' : 'text-zinc-400 dark:text-zinc-500' }}">
                <div class="flex h-8 w-8 items-center justify-center rounded-full text-sm font-medium {{ $step >= $num ? 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300' : 'bg-zinc-100 text-zinc-400 dark:bg-zinc-800 dark:text-zinc-500' }}">
                    @if($step > $num)
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                    @else
                        {{ $num }}
                    @endif
                </div>
                <span class="text-sm font-medium">{{ $label }}</span>
            </div>
            @if($num < 3)
                <div class="h-px flex-1 {{ $step > $num ? 'bg-blue-300 dark:bg-blue-600' : 'bg-zinc-200 dark:bg-zinc-700' }}"></div>
            @endif
        @endforeach
    </div>

    {{-- Step 1: Enter Hours --}}
    @if($step === 1)
        @if(session('message'))
            <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-800 dark:bg-green-900/30 dark:text-green-300">
                {{ session('message') }}
            </div>
        @endif

        <div class="mb-4 flex items-center justify-between">
            <flux:heading size="lg">{{ __('Enter Hours') }}</flux:heading>
            @can('payroll.manage')
                <flux:button wire:click="importHoursFromTimeEntries" variant="ghost" size="sm" icon="arrow-down-tray">
                    {{ __('Import from Time Entries') }}
                </flux:button>
            @endcan
        </div>

        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                <thead class="bg-zinc-50 dark:bg-zinc-900">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Employee</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Pay Rate</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Regular Hours</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Overtime Hours</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">PTO Hours</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Commissions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach($this->employees as $employee)
                        @php $empCommission = $this->pendingCommissions[$employee->id] ?? 0; @endphp
                        <tr wire:key="emp-{{ $employee->id }}">
                            <td class="px-4 py-3">
                                <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                    {{ $employee->user->name }}
                                </div>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ $employee->employee_number }} - {{ $employee->job_title }}
                                    @if ($employee->isCommissionOnly())
                                        <span class="ml-1 text-amber-600 dark:text-amber-400">{{ __('(Commission Only)') }}</span>
                                    @endif
                                </div>
                            </td>
                            <td class="px-4 py-3 text-sm text-zinc-600 dark:text-zinc-400">
                                {{ $employee->getPayRateLabel() }}
                            </td>
                            <td class="px-4 py-3">
                                <flux:input type="number" step="0.25" min="0" max="168"
                                    wire:model="hoursData.{{ $employee->id }}.regular"
                                    class="w-24" />
                            </td>
                            <td class="px-4 py-3">
                                <flux:input type="number" step="0.25" min="0" max="80"
                                    wire:model="hoursData.{{ $employee->id }}.overtime"
                                    class="w-24" />
                            </td>
                            <td class="px-4 py-3">
                                <flux:input type="number" step="0.25" min="0" max="80"
                                    wire:model="hoursData.{{ $employee->id }}.pto"
                                    class="w-24" />
                            </td>
                            <td class="px-4 py-3 text-right text-sm">
                                @if ($empCommission > 0)
                                    <span class="font-medium text-green-700 dark:text-green-400">${{ number_format($empCommission, 2) }}</span>
                                @else
                                    <span class="text-zinc-400 dark:text-zinc-500">&mdash;</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                @php $totalPendingCommission = array_sum($this->pendingCommissions); @endphp
                @if ($totalPendingCommission > 0)
                    <tfoot class="bg-zinc-50 dark:bg-zinc-900">
                        <tr>
                            <td colspan="5" class="px-4 py-2 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Total Pending Commissions') }}</td>
                            <td class="px-4 py-2 text-right text-sm font-semibold text-green-700 dark:text-green-400">${{ number_format($totalPendingCommission, 2) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>

        @can('payroll.manage')
            <div class="mt-6 flex justify-end">
                <flux:button variant="primary" wire:click="generatePreview" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="generatePreview">Generate Preview</span>
                    <span wire:loading wire:target="generatePreview">Calculating...</span>
                </flux:button>
            </div>
        @endcan
    @endif

    {{-- Step 2 & 3: Preview / Finalized --}}
    @if($step >= 2)
        <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                <thead class="bg-zinc-50 dark:bg-zinc-900">
                    <tr>
                        <th class="px-3 py-3 text-left text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Employee</th>
                        <th class="px-3 py-3 text-right text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Hours</th>
                        <th class="px-3 py-3 text-right text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Commission</th>
                        <th class="px-3 py-3 text-right text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Gross</th>
                        <th class="px-3 py-3 text-right text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Fed Tax</th>
                        <th class="px-3 py-3 text-right text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">State Tax</th>
                        <th class="px-3 py-3 text-right text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">SS</th>
                        <th class="px-3 py-3 text-right text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Medicare</th>
                        <th class="px-3 py-3 text-right text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Deductions</th>
                        <th class="px-3 py-3 text-right text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Net Pay</th>
                        @if($step === 3)
                            <th class="px-3 py-3 text-right text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Check #</th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach($this->stubs as $stub)
                        <tr wire:key="stub-{{ $stub->id }}">
                            <td class="px-3 py-3">
                                <div class="text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                    {{ $stub->employeeProfile->user->name }}
                                </div>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ $stub->employeeProfile->employee_number }}
                                </div>
                            </td>
                            <td class="px-3 py-3 text-right text-sm">
                                <span class="text-zinc-900 dark:text-zinc-100">{{ number_format($stub->hours_regular, 1) }}</span>
                                @if($stub->hours_overtime > 0)
                                    <span class="text-xs text-orange-600 dark:text-orange-400">+{{ number_format($stub->hours_overtime, 1) }} OT</span>
                                @endif
                                @if($stub->hours_pto > 0)
                                    <span class="text-xs text-blue-600 dark:text-blue-400">+{{ number_format($stub->hours_pto, 1) }} PTO</span>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-right text-sm">
                                @if((float) $stub->commission_amount > 0)
                                    <span class="font-medium text-green-700 dark:text-green-400">${{ number_format($stub->commission_amount, 2) }}</span>
                                @else
                                    <span class="text-zinc-400 dark:text-zinc-500">&mdash;</span>
                                @endif
                            </td>
                            <td class="px-3 py-3 text-right text-sm font-medium text-zinc-900 dark:text-zinc-100">${{ number_format($stub->gross_pay, 2) }}</td>
                            <td class="px-3 py-3 text-right text-sm text-red-600 dark:text-red-400">${{ number_format($stub->federal_tax, 2) }}</td>
                            <td class="px-3 py-3 text-right text-sm text-red-600 dark:text-red-400">${{ number_format($stub->state_tax, 2) }}</td>
                            <td class="px-3 py-3 text-right text-sm text-red-600 dark:text-red-400">${{ number_format($stub->social_security, 2) }}</td>
                            <td class="px-3 py-3 text-right text-sm text-red-600 dark:text-red-400">${{ number_format($stub->medicare + $stub->additional_medicare, 2) }}</td>
                            <td class="px-3 py-3 text-right text-sm text-red-600 dark:text-red-400">${{ number_format($stub->total_deductions, 2) }}</td>
                            <td class="px-3 py-3 text-right text-sm font-semibold text-green-700 dark:text-green-400">${{ number_format($stub->net_pay, 2) }}</td>
                            @if($step === 3)
                                <td class="px-3 py-3 text-right text-sm text-zinc-600 dark:text-zinc-400">
                                    {{ $stub->check_number ?? '--' }}
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="bg-zinc-50 dark:bg-zinc-900">
                    <tr class="font-semibold">
                        <td class="px-3 py-3 text-sm text-zinc-900 dark:text-zinc-100">Totals</td>
                        <td class="px-3 py-3"></td>
                        <td class="px-3 py-3 text-right text-sm text-green-700 dark:text-green-400">
                            @if($this->totals['commission'] > 0)
                                ${{ number_format($this->totals['commission'], 2) }}
                            @endif
                        </td>
                        <td class="px-3 py-3 text-right text-sm text-zinc-900 dark:text-zinc-100">${{ number_format($this->totals['gross'], 2) }}</td>
                        <td class="px-3 py-3 text-right text-sm text-red-600 dark:text-red-400">${{ number_format($this->totals['federal_tax'], 2) }}</td>
                        <td class="px-3 py-3 text-right text-sm text-red-600 dark:text-red-400">${{ number_format($this->totals['state_tax'], 2) }}</td>
                        <td class="px-3 py-3 text-right text-sm text-red-600 dark:text-red-400">${{ number_format($this->totals['social_security'], 2) }}</td>
                        <td class="px-3 py-3 text-right text-sm text-red-600 dark:text-red-400">${{ number_format($this->totals['medicare'], 2) }}</td>
                        <td class="px-3 py-3 text-right text-sm text-red-600 dark:text-red-400">${{ number_format($this->totals['total_deductions'], 2) }}</td>
                        <td class="px-3 py-3 text-right text-sm text-green-700 dark:text-green-400">${{ number_format($this->totals['net'], 2) }}</td>
                        @if($step === 3)
                            <td class="px-3 py-3"></td>
                        @endif
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="mt-6 flex justify-between">
            @if($step === 2 && !$period->isCompleted())
                <flux:button variant="ghost" wire:click="goBack">
                    Back to Hours
                </flux:button>

                @can('payroll.manage')
                    <flux:button variant="primary" wire:click="finalizePayroll"
                        wire:confirm="Are you sure you want to finalize this payroll? This action cannot be undone.">
                        Approve &amp; Finalize
                    </flux:button>
                @endcan
            @else
                <div></div>
                <flux:button variant="ghost" href="{{ route('payroll.index') }}" wire:navigate>
                    Back to Payroll
                </flux:button>
            @endif
        </div>
    @endif
</div>
