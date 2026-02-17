<?php

use App\Models\PayStub;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new
#[Layout('layouts.app')]
#[Title('Pay Stub')]
class extends Component {
    public PayStub $payStub;

    public function boot(): void
    {
        $user = auth()->user();
        // Managers can view all stubs, employees can view their own
        if (! $user?->can('payroll.view')) {
            if (! $user?->employeeProfile) {
                abort(403);
            }
        }
    }

    public function mount(PayStub $payStub): void
    {
        $this->payStub = $payStub->load(['employeeProfile.user', 'payPeriod']);

        // If user doesn't have payroll.view permission, verify they own this stub
        if (! auth()->user()->can('payroll.view')) {
            if ($this->payStub->employee_profile_id !== auth()->user()->employeeProfile?->id) {
                abort(403);
            }
        }
    }

    #[Computed]
    public function employee()
    {
        return $this->payStub->employeeProfile;
    }

    #[Computed]
    public function period()
    {
        return $this->payStub->payPeriod;
    }

    public function downloadPdf(): mixed
    {
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('pdf.pay-stub', [
            'stub' => $this->payStub,
            'employee' => $this->employee,
            'period' => $this->period,
            'company' => \App\Models\CompanySetting::getInstance(),
        ]);

        return response()->streamDownload(
            fn () => print($pdf->output()),
            "pay-stub-{$this->employee->employee_number}-{$this->period->start_date->format('Y-m-d')}.pdf"
        );
    }
};
?>

<div>
    <div class="mb-6">
        <div class="flex items-center gap-2 text-sm text-zinc-500 dark:text-zinc-400 mb-2">
            <a href="{{ route('payroll.index') }}" class="hover:text-zinc-700 dark:hover:text-zinc-200" wire:navigate>Payroll</a>
            <span>/</span>
            <span>Pay Stub</span>
        </div>

        <div class="flex items-center justify-between">
            <flux:heading size="xl">Pay Stub - {{ $this->employee->user->name }}</flux:heading>
            <div class="flex items-center gap-2">
                @can('payroll.manage')
                    @if($payStub->check_number)
                        <flux:button :href="route('payroll.check', $payStub)" icon="printer" size="sm">
                            {{ __('Print Check') }}
                        </flux:button>
                    @endif
                @endcan
                <flux:button variant="ghost" wire:click="downloadPdf">
                    Download PDF
                </flux:button>
            </div>
        </div>
    </div>

    <div class="max-w-3xl mx-auto space-y-6">
        {{-- Employee & Period Info --}}
        <div class="grid grid-cols-2 gap-6">
            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:heading size="sm" class="mb-3">Employee Info</flux:heading>
                <div class="space-y-1 text-sm">
                    <div><span class="text-zinc-500 dark:text-zinc-400">Name:</span> <span class="text-zinc-900 dark:text-zinc-100">{{ $this->employee->user->name }}</span></div>
                    <div><span class="text-zinc-500 dark:text-zinc-400">Employee #:</span> <span class="text-zinc-900 dark:text-zinc-100">{{ $this->employee->employee_number }}</span></div>
                    <div><span class="text-zinc-500 dark:text-zinc-400">Department:</span> <span class="text-zinc-900 dark:text-zinc-100">{{ ucfirst($this->employee->department ?? 'N/A') }}</span></div>
                </div>
            </div>

            <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:heading size="sm" class="mb-3">Pay Period</flux:heading>
                <div class="space-y-1 text-sm">
                    <div><span class="text-zinc-500 dark:text-zinc-400">Period:</span> <span class="text-zinc-900 dark:text-zinc-100">{{ $this->period->start_date->format('M j, Y') }} - {{ $this->period->end_date->format('M j, Y') }}</span></div>
                    <div><span class="text-zinc-500 dark:text-zinc-400">Type:</span> <span class="text-zinc-900 dark:text-zinc-100">{{ ucfirst($this->period->type) }}</span></div>
                    <div><span class="text-zinc-500 dark:text-zinc-400">Check #:</span> <span class="text-zinc-900 dark:text-zinc-100">{{ $payStub->check_number ?? 'N/A' }}</span></div>
                    <div>
                        <flux:badge :variant="$payStub->isFinalized() ? 'success' : 'warning'" size="sm">
                            {{ ucfirst($payStub->status) }}
                        </flux:badge>
                    </div>
                </div>
            </div>
        </div>

        {{-- Earnings --}}
        <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
            <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                <flux:heading size="sm">Earnings</flux:heading>
            </div>
            <table class="min-w-full">
                <thead class="bg-zinc-50 dark:bg-zinc-900">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Description</th>
                        <th class="px-4 py-2 text-right text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Hours</th>
                        <th class="px-4 py-2 text-right text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Rate</th>
                        <th class="px-4 py-2 text-right text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Amount</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    <tr>
                        <td class="px-4 py-2 text-sm text-zinc-900 dark:text-zinc-100">Regular Pay</td>
                        <td class="px-4 py-2 text-right text-sm text-zinc-600 dark:text-zinc-400">{{ number_format($payStub->hours_regular, 2) }}</td>
                        <td class="px-4 py-2 text-right text-sm text-zinc-600 dark:text-zinc-400">${{ number_format($payStub->rate_regular, 2) }}</td>
                        <td class="px-4 py-2 text-right text-sm font-medium text-zinc-900 dark:text-zinc-100">${{ number_format($payStub->hours_regular * $payStub->rate_regular, 2) }}</td>
                    </tr>
                    @if($payStub->hours_overtime > 0)
                        <tr>
                            <td class="px-4 py-2 text-sm text-zinc-900 dark:text-zinc-100">Overtime Pay</td>
                            <td class="px-4 py-2 text-right text-sm text-zinc-600 dark:text-zinc-400">{{ number_format($payStub->hours_overtime, 2) }}</td>
                            <td class="px-4 py-2 text-right text-sm text-zinc-600 dark:text-zinc-400">${{ number_format($payStub->rate_overtime, 2) }}</td>
                            <td class="px-4 py-2 text-right text-sm font-medium text-zinc-900 dark:text-zinc-100">${{ number_format($payStub->hours_overtime * $payStub->rate_overtime, 2) }}</td>
                        </tr>
                    @endif
                    @if($payStub->hours_pto > 0)
                        <tr>
                            <td class="px-4 py-2 text-sm text-zinc-900 dark:text-zinc-100">PTO Pay</td>
                            <td class="px-4 py-2 text-right text-sm text-zinc-600 dark:text-zinc-400">{{ number_format($payStub->hours_pto, 2) }}</td>
                            <td class="px-4 py-2 text-right text-sm text-zinc-600 dark:text-zinc-400">${{ number_format($payStub->rate_regular, 2) }}</td>
                            <td class="px-4 py-2 text-right text-sm font-medium text-zinc-900 dark:text-zinc-100">${{ number_format($payStub->hours_pto * $payStub->rate_regular, 2) }}</td>
                        </tr>
                    @endif
                </tbody>
                <tfoot class="bg-zinc-50 dark:bg-zinc-900">
                    <tr class="font-semibold">
                        <td class="px-4 py-2 text-sm text-zinc-900 dark:text-zinc-100">Gross Pay</td>
                        <td colspan="2"></td>
                        <td class="px-4 py-2 text-right text-sm text-zinc-900 dark:text-zinc-100">${{ number_format($payStub->gross_pay, 2) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        {{-- Tax Withholdings --}}
        <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
            <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                <flux:heading size="sm">Tax Withholdings</flux:heading>
            </div>
            <table class="min-w-full">
                <thead class="bg-zinc-50 dark:bg-zinc-900">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Tax</th>
                        <th class="px-4 py-2 text-right text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Current</th>
                        <th class="px-4 py-2 text-right text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">YTD</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    <tr>
                        <td class="px-4 py-2 text-sm text-zinc-900 dark:text-zinc-100">Federal Income Tax</td>
                        <td class="px-4 py-2 text-right text-sm text-red-600 dark:text-red-400">${{ number_format($payStub->federal_tax, 2) }}</td>
                        <td class="px-4 py-2 text-right text-sm text-zinc-600 dark:text-zinc-400">${{ number_format($payStub->ytd_federal_tax, 2) }}</td>
                    </tr>
                    <tr>
                        <td class="px-4 py-2 text-sm text-zinc-900 dark:text-zinc-100">State Income Tax (CO)</td>
                        <td class="px-4 py-2 text-right text-sm text-red-600 dark:text-red-400">${{ number_format($payStub->state_tax, 2) }}</td>
                        <td class="px-4 py-2 text-right text-sm text-zinc-600 dark:text-zinc-400">${{ number_format($payStub->ytd_state_tax, 2) }}</td>
                    </tr>
                    <tr>
                        <td class="px-4 py-2 text-sm text-zinc-900 dark:text-zinc-100">Social Security (6.2%)</td>
                        <td class="px-4 py-2 text-right text-sm text-red-600 dark:text-red-400">${{ number_format($payStub->social_security, 2) }}</td>
                        <td class="px-4 py-2 text-right text-sm text-zinc-600 dark:text-zinc-400">${{ number_format($payStub->ytd_social_security, 2) }}</td>
                    </tr>
                    <tr>
                        <td class="px-4 py-2 text-sm text-zinc-900 dark:text-zinc-100">Medicare (1.45%)</td>
                        <td class="px-4 py-2 text-right text-sm text-red-600 dark:text-red-400">${{ number_format($payStub->medicare, 2) }}</td>
                        <td class="px-4 py-2 text-right text-sm text-zinc-600 dark:text-zinc-400">${{ number_format($payStub->ytd_medicare, 2) }}</td>
                    </tr>
                    @if($payStub->additional_medicare > 0)
                        <tr>
                            <td class="px-4 py-2 text-sm text-zinc-900 dark:text-zinc-100">Additional Medicare (0.9%)</td>
                            <td class="px-4 py-2 text-right text-sm text-red-600 dark:text-red-400">${{ number_format($payStub->additional_medicare, 2) }}</td>
                            <td class="px-4 py-2 text-right text-sm text-zinc-600 dark:text-zinc-400">--</td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>

        {{-- Deductions --}}
        @if(!empty($payStub->pretax_deductions) || !empty($payStub->posttax_deductions))
            <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                    <flux:heading size="sm">Deductions</flux:heading>
                </div>
                <table class="min-w-full">
                    <thead class="bg-zinc-50 dark:bg-zinc-900">
                        <tr>
                            <th class="px-4 py-2 text-left text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Description</th>
                            <th class="px-4 py-2 text-left text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Type</th>
                            <th class="px-4 py-2 text-right text-xs font-medium uppercase text-zinc-500 dark:text-zinc-400">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        @foreach($payStub->pretax_deductions ?? [] as $ded)
                            <tr>
                                <td class="px-4 py-2 text-sm text-zinc-900 dark:text-zinc-100">{{ $ded['description'] }}</td>
                                <td class="px-4 py-2 text-sm text-zinc-500 dark:text-zinc-400">Pre-tax</td>
                                <td class="px-4 py-2 text-right text-sm text-red-600 dark:text-red-400">${{ number_format($ded['amount'], 2) }}</td>
                            </tr>
                        @endforeach
                        @foreach($payStub->posttax_deductions ?? [] as $ded)
                            <tr>
                                <td class="px-4 py-2 text-sm text-zinc-900 dark:text-zinc-100">{{ $ded['description'] }}</td>
                                <td class="px-4 py-2 text-sm text-zinc-500 dark:text-zinc-400">Post-tax</td>
                                <td class="px-4 py-2 text-right text-sm text-red-600 dark:text-red-400">${{ number_format($ded['amount'], 2) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Summary --}}
        <div class="rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-800">
            <div class="flex items-center justify-between border-b border-zinc-200 pb-3 dark:border-zinc-700">
                <span class="text-sm text-zinc-600 dark:text-zinc-400">Gross Pay</span>
                <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">${{ number_format($payStub->gross_pay, 2) }}</span>
            </div>
            <div class="flex items-center justify-between border-b border-zinc-200 py-3 dark:border-zinc-700">
                <span class="text-sm text-zinc-600 dark:text-zinc-400">Total Deductions</span>
                <span class="text-sm font-medium text-red-600 dark:text-red-400">-${{ number_format($payStub->total_deductions, 2) }}</span>
            </div>
            <div class="flex items-center justify-between pt-3">
                <span class="text-base font-semibold text-zinc-900 dark:text-zinc-100">Net Pay</span>
                <span class="text-lg font-bold text-green-700 dark:text-green-400">${{ number_format($payStub->net_pay, 2) }}</span>
            </div>
        </div>

        {{-- YTD Summary --}}
        <div class="rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
            <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-700">
                <flux:heading size="sm">Year-to-Date Summary</flux:heading>
            </div>
            <div class="grid grid-cols-2 gap-4 p-4 sm:grid-cols-5">
                <div class="text-center">
                    <div class="text-xs text-zinc-500 dark:text-zinc-400">YTD Gross</div>
                    <div class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">${{ number_format($payStub->ytd_gross, 2) }}</div>
                </div>
                <div class="text-center">
                    <div class="text-xs text-zinc-500 dark:text-zinc-400">YTD Federal</div>
                    <div class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">${{ number_format($payStub->ytd_federal_tax, 2) }}</div>
                </div>
                <div class="text-center">
                    <div class="text-xs text-zinc-500 dark:text-zinc-400">YTD State</div>
                    <div class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">${{ number_format($payStub->ytd_state_tax, 2) }}</div>
                </div>
                <div class="text-center">
                    <div class="text-xs text-zinc-500 dark:text-zinc-400">YTD SS</div>
                    <div class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">${{ number_format($payStub->ytd_social_security, 2) }}</div>
                </div>
                <div class="text-center">
                    <div class="text-xs text-zinc-500 dark:text-zinc-400">YTD Medicare</div>
                    <div class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">${{ number_format($payStub->ytd_medicare, 2) }}</div>
                </div>
            </div>
        </div>
    </div>
</div>
