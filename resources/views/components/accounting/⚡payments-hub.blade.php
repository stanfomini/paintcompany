<?php

use App\Models\Account;
use App\Models\CommissionEntry;
use App\Models\CompanySetting;
use App\Models\JobPayable;
use App\Models\PayPeriod;
use App\Models\PayStub;
use App\Models\Vendor;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new
#[Layout('layouts.app')]
#[Title('Payments Hub')]
class extends Component
{
    use WithFileUploads, WithPagination;

    #[Url]
    public string $tab = 'payables';

    #[Url]
    public string $payeeTypeFilter = '';

    #[Url]
    public string $statusFilter = '';

    #[Url]
    public string $search = '';

    #[Url]
    public string $commissionStatusFilter = 'pending';

    #[Url]
    public int $taxYear = 0;

    /** @var array<int> */
    public array $selectedPayables = [];

    /** @var array<int> */
    public array $selectedCommissions = [];

    public bool $showBulkPayModal = false;
    public ?int $bulkPayFromAccountId = null;

    // Quick-add vendor
    public bool $showQuickAddVendor = false;
    public string $quickVendorName = '';
    public string $quickVendorCategory = 'supplier';
    public string $quickVendorEmail = '';
    public string $quickVendorPhone = '';

    // Create vendor bill modal
    public bool $showCreateBillModal = false;
    public ?int $billVendorId = null;
    public ?float $billAmount = null;
    public string $billDescription = '';
    public string $billCategory = 'materials';
    public string $billInvoiceNumber = '';
    public ?string $billDueDate = null;
    public ?int $billDebitAccountId = null;
    public ?int $billCreditAccountId = null;
    public ?int $billJobId = null;
    public $billAttachment = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('ar.view'), 403);

        if ($this->taxYear === 0) {
            $this->taxYear = (int) now()->year;
        }
    }

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('ar.view'), 403);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedPayeeTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedTab(): void
    {
        $this->resetPage();
        $this->selectedPayables = [];
        $this->selectedCommissions = [];
    }

    public function updatedCommissionStatusFilter(): void
    {
        $this->resetPage();
    }

    public function quickAddVendor(): void
    {
        $this->validate([
            'quickVendorName' => ['required', 'string', 'max:255'],
            'quickVendorCategory' => ['required', 'string', 'in:supplier,subcontractor,service,other'],
            'quickVendorEmail' => ['nullable', 'email', 'max:255'],
            'quickVendorPhone' => ['nullable', 'string', 'max:50'],
        ]);

        $vendor = Vendor::create([
            'company_setting_id' => CompanySetting::getInstance()->id,
            'name' => $this->quickVendorName,
            'category' => $this->quickVendorCategory,
            'email' => $this->quickVendorEmail ?: null,
            'phone' => $this->quickVendorPhone ?: null,
            'is_active' => true,
        ]);

        $this->billVendorId = $vendor->id;
        $this->showQuickAddVendor = false;
        $this->quickVendorName = '';
        $this->quickVendorCategory = 'supplier';
        $this->quickVendorEmail = '';
        $this->quickVendorPhone = '';
        unset($this->activeVendors);
    }

    public function updatedBillVendorId(): void
    {
        if ($this->billVendorId) {
            $vendor = Vendor::find($this->billVendorId);
            if ($vendor?->default_expense_account_id) {
                $this->billDebitAccountId = $vendor->default_expense_account_id;
            }
        }
    }

    // ─── Summary Cards ───

    #[Computed]
    public function totalOutstanding(): float
    {
        return (float) JobPayable::whereIn('status', ['pending', 'approved'])->sum('amount');
    }

    #[Computed]
    public function totalPaidThisMonth(): float
    {
        return (float) JobPayable::where('status', JobPayable::STATUS_PAID)
            ->whereMonth('paid_at', now()->month)
            ->whereYear('paid_at', now()->year)
            ->sum('amount');
    }

    #[Computed]
    public function totalPaidYtd(): float
    {
        return (float) JobPayable::where('status', JobPayable::STATUS_PAID)
            ->whereYear('paid_at', now()->year)
            ->sum('amount');
    }

    #[Computed]
    public function pendingCommissionsTotal(): float
    {
        return (float) CommissionEntry::pending()->sum('amount');
    }

    #[Computed]
    public function activeVendors()
    {
        return Vendor::where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function allExpenseAccounts()
    {
        return Account::where('type', 'expense')->active()->orderBy('code')->get();
    }

    #[Computed]
    public function allLiabilityAccounts()
    {
        return Account::where('type', 'liability')->active()->orderBy('code')->get();
    }

    #[Computed]
    public function allBankAccounts()
    {
        return Account::bankAccounts()->orderBy('code')->get();
    }

    #[Computed]
    public function activeJobs()
    {
        return \App\Models\Job::whereNotIn('stage', ['completed'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();
    }

    // ─── Tab 1: All Payables ───

    #[Computed]
    public function payables()
    {
        return JobPayable::query()
            ->with(['job', 'payeeUser'])
            ->when($this->payeeTypeFilter, fn ($q) => $q->where('payee_type', $this->payeeTypeFilter))
            ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
            ->when($this->search, function ($q) {
                $q->where(function ($query) {
                    $query->where('payee_name', 'like', "%{$this->search}%")
                        ->orWhereHas('job', fn ($jq) => $jq->where('title', 'like', "%{$this->search}%"));
                });
            })
            ->latest()
            ->paginate(20);
    }

    // ─── Tab 2: Payroll ───

    #[Computed]
    public function latestPayPeriod(): ?PayPeriod
    {
        return PayPeriod::latest('end_date')->first();
    }

    #[Computed]
    public function payStubs()
    {
        $period = $this->latestPayPeriod;

        if (! $period) {
            return collect();
        }

        return PayStub::where('pay_period_id', $period->id)
            ->with('employeeProfile.user')
            ->get();
    }

    // ─── Tab 3: Commissions ───

    #[Computed]
    public function commissions()
    {
        return CommissionEntry::query()
            ->with('employeeProfile.user')
            ->when($this->commissionStatusFilter, fn ($q) => $q->where('status', $this->commissionStatusFilter))
            ->latest('earned_date')
            ->paginate(20);
    }

    // ─── Tab 4: 1099 Tracker ───

    #[Computed]
    public function subcontractorTotals()
    {
        return JobPayable::query()
            ->where('payee_type', JobPayable::PAYEE_TYPE_CREW)
            ->where('status', JobPayable::STATUS_PAID)
            ->whereYear('paid_at', $this->taxYear)
            ->with('payeeUser.crewProfile')
            ->get()
            ->groupBy('payee_user_id')
            ->map(function ($payables) {
                $user = $payables->first()->payeeUser;

                return [
                    'name' => $user?->name ?? $payables->first()->payee_name,
                    'company' => $user?->crewProfile?->company_name ?? '',
                    'total_paid' => $payables->sum('amount'),
                    'w9_on_file' => $user?->crewProfile?->w9_received_date !== null,
                ];
            })
            ->sortByDesc('total_paid')
            ->values();
    }

    #[Computed]
    public function vendor1099Totals()
    {
        return Vendor::query()
            ->whereHas('payables', function ($q) {
                $q->where('status', JobPayable::STATUS_PAID)
                    ->whereYear('paid_at', $this->taxYear);
            })
            ->withSum(['payables as total_paid' => function ($q) {
                $q->where('status', JobPayable::STATUS_PAID)
                    ->whereYear('paid_at', $this->taxYear);
            }], 'amount')
            ->orderByDesc('total_paid')
            ->get();
    }

    // ─── Actions ───

    public function approveSelected(): void
    {
        abort_unless(auth()->user()?->can('ar.manage'), 403);

        JobPayable::whereIn('id', $this->selectedPayables)
            ->where('status', JobPayable::STATUS_PENDING)
            ->each(fn (JobPayable $p) => $p->approve(auth()->user()));

        $this->selectedPayables = [];
    }

    public function markSelectedPaid(): void
    {
        abort_unless(auth()->user()?->can('ar.manage'), 403);

        JobPayable::whereIn('id', $this->selectedPayables)
            ->where('status', JobPayable::STATUS_APPROVED)
            ->each(function (JobPayable $p) {
                if ($this->bulkPayFromAccountId) {
                    $p->update(['paid_from_account_id' => $this->bulkPayFromAccountId]);
                }
                $p->markPaid();
            });

        $this->selectedPayables = [];
        $this->bulkPayFromAccountId = null;
        $this->showBulkPayModal = false;
    }

    public function approveCommission(int $id): void
    {
        abort_unless(auth()->user()?->can('payroll.manage'), 403);

        $entry = CommissionEntry::findOrFail($id);
        $entry->approve(auth()->user());
    }

    public function approveSelectedCommissions(): void
    {
        abort_unless(auth()->user()?->can('payroll.manage'), 403);

        CommissionEntry::whereIn('id', $this->selectedCommissions)
            ->where('status', 'pending')
            ->each(fn (CommissionEntry $e) => $e->approve(auth()->user()));

        $this->selectedCommissions = [];
    }

    public function openCreateBillModal(): void
    {
        abort_unless(auth()->user()?->can('ar.manage'), 403);

        $this->reset(['billVendorId', 'billAmount', 'billDescription', 'billCategory', 'billInvoiceNumber', 'billDueDate', 'billDebitAccountId', 'billCreditAccountId', 'billJobId', 'billAttachment']);
        $this->billCategory = 'materials';
        $this->showCreateBillModal = true;
    }

    public function createVendorBill(): void
    {
        abort_unless(auth()->user()?->can('ar.manage'), 403);

        $this->validate([
            'billVendorId' => 'required|exists:vendors,id',
            'billAmount' => 'required|numeric|min:0.01',
            'billDescription' => 'required|string|max:255',
            'billCategory' => 'required|in:' . implode(',', array_keys(JobPayable::CATEGORIES)),
            'billAttachment' => 'nullable|file|max:10240|mimes:pdf,jpg,jpeg,png',
        ]);

        $attachmentPath = null;
        if ($this->billAttachment) {
            $attachmentPath = $this->billAttachment->store('vendor-bills', 'private');
        }

        $settings = CompanySetting::getInstance();
        $defaultDebit = $this->billDebitAccountId ?? Account::where('company_setting_id', $settings->id)->where('code', '5030')->first()?->id;
        $defaultCredit = $this->billCreditAccountId ?? Account::where('company_setting_id', $settings->id)->where('code', '2000')->first()?->id;

        JobPayable::create([
            'job_id' => $this->billJobId ?: null,
            'payee_type' => JobPayable::PAYEE_TYPE_VENDOR,
            'vendor_id' => $this->billVendorId,
            'payee_name' => Vendor::find($this->billVendorId)?->name ?? 'Vendor',
            'category' => $this->billCategory,
            'description' => $this->billDescription,
            'amount' => $this->billAmount,
            'status' => JobPayable::STATUS_PENDING,
            'due_date' => $this->billDueDate,
            'vendor_invoice_number' => $this->billInvoiceNumber ?: null,
            'attachment_path' => $attachmentPath,
            'debit_account_id' => $defaultDebit,
            'credit_account_id' => $defaultCredit,
            'created_by' => auth()->id(),
        ]);

        $this->showCreateBillModal = false;
        unset($this->payables, $this->totalOutstanding);
    }
};
?>

<div>
    {{-- Summary Cards --}}
    <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-4">
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Outstanding') }}</div>
            <div class="mt-1 text-2xl font-bold text-amber-600 dark:text-amber-400">${{ number_format($this->totalOutstanding, 2) }}</div>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Paid This Month') }}</div>
            <div class="mt-1 text-2xl font-bold text-emerald-600 dark:text-emerald-400">${{ number_format($this->totalPaidThisMonth, 2) }}</div>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Paid YTD') }}</div>
            <div class="mt-1 text-2xl font-bold text-zinc-900 dark:text-zinc-100">${{ number_format($this->totalPaidYtd, 2) }}</div>
        </div>
        <div class="rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('Pending Commissions') }}</div>
            <div class="mt-1 text-2xl font-bold text-blue-600 dark:text-blue-400">${{ number_format($this->pendingCommissionsTotal, 2) }}</div>
        </div>
    </div>

    {{-- Tabs --}}
    <div class="mb-6 flex items-center gap-1 border-b border-zinc-200 dark:border-zinc-700">
        <button
            wire:click="$set('tab', 'payables')"
            class="px-4 py-2 text-sm font-medium transition {{ $tab === 'payables' ? 'border-b-2 border-blue-600 text-blue-600 dark:border-blue-400 dark:text-blue-400' : 'text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' }}"
        >
            {{ __('All Payables') }}
        </button>
        <button
            wire:click="$set('tab', 'payroll')"
            class="px-4 py-2 text-sm font-medium transition {{ $tab === 'payroll' ? 'border-b-2 border-blue-600 text-blue-600 dark:border-blue-400 dark:text-blue-400' : 'text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' }}"
        >
            {{ __('Payroll') }}
        </button>
        <button
            wire:click="$set('tab', 'commissions')"
            class="px-4 py-2 text-sm font-medium transition {{ $tab === 'commissions' ? 'border-b-2 border-blue-600 text-blue-600 dark:border-blue-400 dark:text-blue-400' : 'text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' }}"
        >
            {{ __('Commissions') }}
        </button>
        <button
            wire:click="$set('tab', '1099')"
            class="px-4 py-2 text-sm font-medium transition {{ $tab === '1099' ? 'border-b-2 border-blue-600 text-blue-600 dark:border-blue-400 dark:text-blue-400' : 'text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' }}"
        >
            {{ __('1099 Tracker') }}
        </button>
    </div>

    {{-- Tab 1: All Payables --}}
    @if($tab === 'payables')
        <div class="mb-4 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading size="lg">{{ __('All Payables') }}</flux:heading>
            <div class="flex flex-wrap items-center gap-2">
                @can('ar.manage')
                    <flux:button size="sm" wire:click="openCreateBillModal" icon="plus">{{ __('Vendor Bill') }}</flux:button>
                @endcan
                <flux:input wire:model.live.debounce.300ms="search" placeholder="{{ __('Search...') }}" icon="magnifying-glass" size="sm" class="w-48" />
                <flux:select wire:model.live="payeeTypeFilter" size="sm" class="w-36">
                    <option value="">{{ __('All Types') }}</option>
                    @foreach(\App\Models\JobPayable::PAYEE_TYPES as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="statusFilter" size="sm" class="w-36">
                    <option value="">{{ __('All Statuses') }}</option>
                    @foreach(\App\Models\JobPayable::STATUSES as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </flux:select>
            </div>
        </div>

        @if(auth()->user()?->can('ar.manage') && count($this->selectedPayables) > 0)
            <div class="mb-4 flex items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-4 py-2 dark:border-blue-800 dark:bg-blue-900/20">
                <flux:text size="sm">{{ count($this->selectedPayables) }} {{ __('selected') }}</flux:text>
                <flux:button size="sm" variant="primary" wire:click="approveSelected">{{ __('Approve Selected') }}</flux:button>
                <flux:button size="sm" wire:click="$set('showBulkPayModal', true)">{{ __('Mark Paid') }}</flux:button>
            </div>
        @endif

        @if($this->payables->isEmpty())
            <flux:callout variant="outline" icon="banknotes">
                <flux:callout.heading>{{ __('No payables found') }}</flux:callout.heading>
                <flux:callout.text>{{ __('No payables match your current filters.') }}</flux:callout.text>
            </flux:callout>
        @else
            <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                        <tr>
                            @can('ar.manage')
                                <th class="px-3 py-3 text-left">
                                    <input type="checkbox" wire:click="$set('selectedPayables', {{ $this->payables->pluck('id')->toJson() }})" class="rounded border-zinc-300 dark:border-zinc-600" />
                                </th>
                            @endcan
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Payee') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Type') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Job') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Category') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Amount') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Status') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Due Date') }}</th>
                            <th class="px-2 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-700 dark:bg-zinc-900">
                        @foreach($this->payables as $payable)
                            <tr wire:key="payable-{{ $payable->id }}">
                                @can('ar.manage')
                                    <td class="px-3 py-3">
                                        <input type="checkbox" wire:model.live="selectedPayables" value="{{ $payable->id }}" class="rounded border-zinc-300 dark:border-zinc-600" />
                                    </td>
                                @endcan
                                <td class="whitespace-nowrap px-4 py-3 text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                    @if($payable->payee_user_id && $payable->payee_type === 'crew')
                                        <a href="{{ route('crew.financial-summary', $payable->payee_user_id) }}" class="text-blue-600 hover:underline dark:text-blue-400" wire:navigate>{{ $payable->payee_name }}</a>
                                    @elseif($payable->payee_user_id && $payable->payee_type === 'employee')
                                        <a href="{{ route('team.financial-summary', $payable->payee_user_id) }}" class="text-blue-600 hover:underline dark:text-blue-400" wire:navigate>{{ $payable->payee_name }}</a>
                                    @else
                                        {{ $payable->payee_name }}
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <flux:badge size="sm" color="zinc">{{ \App\Models\JobPayable::PAYEE_TYPES[$payable->payee_type] ?? $payable->payee_type }}</flux:badge>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-600 dark:text-zinc-400">
                                    @if($payable->job?->deal_id)
                                        <a href="{{ route('ar.show', $payable->job->deal_id) }}" class="text-blue-600 hover:underline dark:text-blue-400" wire:navigate>{{ Str::limit($payable->job?->title ?? '-', 30) }}</a>
                                    @else
                                        {{ Str::limit($payable->job?->title ?? '-', 30) }}
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-600 dark:text-zinc-400">
                                    {{ \App\Models\JobPayable::CATEGORIES[$payable->category] ?? $payable->category }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                    ${{ number_format($payable->amount, 2) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <flux:badge size="sm" :color="match($payable->status) {
                                        'pending' => 'amber',
                                        'approved' => 'blue',
                                        'paid' => 'green',
                                        'void' => 'red',
                                        default => 'zinc',
                                    }">{{ \App\Models\JobPayable::STATUSES[$payable->status] ?? $payable->status }}</flux:badge>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-600 dark:text-zinc-400">
                                    {{ $payable->due_date?->format('M j, Y') ?? '-' }}
                                </td>
                                <td class="whitespace-nowrap px-2 py-3">
                                    @if ($payable->hasAttachment())
                                        <flux:icon name="paper-clip" variant="micro" class="size-4 text-zinc-400" title="{{ __('Has attachment') }}" />
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">
                {{ $this->payables->links() }}
            </div>
        @endif

        {{-- Bulk Pay Confirmation Modal --}}
        <flux:modal wire:model="showBulkPayModal">
            <div class="space-y-4">
                <flux:heading size="lg">{{ __('Mark Selected as Paid') }}</flux:heading>
                <flux:text>{{ __('Are you sure you want to mark :count approved payable(s) as paid?', ['count' => count($this->selectedPayables)]) }}</flux:text>

                <flux:field>
                    <flux:label>{{ __('Pay From Account') }}</flux:label>
                    <flux:select wire:model="bulkPayFromAccountId">
                        <flux:select.option value="">{{ __('Select bank account...') }}</flux:select.option>
                        @foreach ($this->allBankAccounts as $account)
                            <flux:select.option value="{{ $account->id }}">{{ $account->code }} - {{ $account->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </flux:field>

                <div class="flex justify-end gap-2">
                    <flux:button wire:click="$set('showBulkPayModal', false)">{{ __('Cancel') }}</flux:button>
                    <flux:button variant="primary" wire:click="markSelectedPaid">{{ __('Confirm') }}</flux:button>
                </div>
            </div>
        </flux:modal>

        {{-- Create Vendor Bill Modal --}}
        <flux:modal wire:model="showCreateBillModal" class="max-w-lg">
            <div class="space-y-4">
                <flux:heading size="lg">{{ __('Create Vendor Bill') }}</flux:heading>

                <flux:field>
                    <flux:label>{{ __('Vendor') }}</flux:label>
                    <flux:select wire:model.live="billVendorId">
                        <flux:select.option value="">{{ __('Select vendor...') }}</flux:select.option>
                        @foreach ($this->activeVendors as $vendor)
                            <flux:select.option value="{{ $vendor->id }}">{{ $vendor->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </flux:field>

                <div class="mt-1">
                    @if($showQuickAddVendor)
                        <div class="p-3 border border-zinc-200 dark:border-zinc-700 rounded-lg bg-zinc-50 dark:bg-zinc-800/50 space-y-3">
                            <div class="flex items-center justify-between">
                                <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('New Vendor') }}</span>
                                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="$set('showQuickAddVendor', false)" />
                            </div>
                            <flux:input wire:model="quickVendorName" :label="__('Vendor Name')" :placeholder="__('e.g., Sherwin-Williams')" required size="sm" />
                            <flux:select wire:model="quickVendorCategory" :label="__('Category')" size="sm">
                                @foreach(Vendor::CATEGORIES as $val => $label)
                                    <flux:select.option value="{{ $val }}">{{ $label }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <div class="grid grid-cols-2 gap-2">
                                <flux:input wire:model="quickVendorEmail" :label="__('Email')" type="email" size="sm" />
                                <flux:input wire:model="quickVendorPhone" :label="__('Phone')" size="sm" />
                            </div>
                            <flux:button size="sm" variant="primary" wire:click="quickAddVendor" icon="plus">{{ __('Create Vendor') }}</flux:button>
                        </div>
                    @else
                        <flux:button size="sm" variant="ghost" icon="plus" wire:click="$set('showQuickAddVendor', true)">{{ __('New Vendor') }}</flux:button>
                    @endif
                </div>

                <flux:field>
                    <flux:label>{{ __('Job (optional)') }}</flux:label>
                    <flux:select wire:model="billJobId">
                        <flux:select.option value="">{{ __('Standalone bill (no job)') }}</flux:select.option>
                        @foreach ($this->activeJobs as $job)
                            <flux:select.option value="{{ $job->id }}">{{ $job->title }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Vendor Invoice #') }}</flux:label>
                    <flux:input wire:model="billInvoiceNumber" placeholder="{{ __('INV-001') }}" />
                </flux:field>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>{{ __('Amount') }}</flux:label>
                        <flux:input type="number" step="0.01" min="0" wire:model="billAmount" placeholder="0.00" />
                    </flux:field>
                    <flux:field>
                        <flux:label>{{ __('Category') }}</flux:label>
                        <flux:select wire:model="billCategory">
                            @foreach (JobPayable::CATEGORIES as $key => $label)
                                <flux:select.option value="{{ $key }}">{{ $label }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>{{ __('Description') }}</flux:label>
                    <flux:input wire:model="billDescription" placeholder="{{ __('Description of the bill') }}" />
                </flux:field>

                <div class="grid grid-cols-2 gap-4">
                    <flux:field>
                        <flux:label>{{ __('Debit Account (GL)') }}</flux:label>
                        <flux:select wire:model="billDebitAccountId">
                            <flux:select.option value="">{{ __('Default (Materials COGS)') }}</flux:select.option>
                            @foreach ($this->allExpenseAccounts as $account)
                                <flux:select.option value="{{ $account->id }}">{{ $account->code }} - {{ $account->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </flux:field>
                    <flux:field>
                        <flux:label>{{ __('Credit Account (GL)') }}</flux:label>
                        <flux:select wire:model="billCreditAccountId">
                            <flux:select.option value="">{{ __('Default (AP)') }}</flux:select.option>
                            @foreach ($this->allLiabilityAccounts as $account)
                                <flux:select.option value="{{ $account->id }}">{{ $account->code }} - {{ $account->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </flux:field>
                </div>

                <flux:field>
                    <flux:label>{{ __('Due Date') }}</flux:label>
                    <flux:input type="date" wire:model="billDueDate" />
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Attachment (PDF, JPG, PNG)') }}</flux:label>
                    <input type="file" wire:model="billAttachment" accept=".pdf,.jpg,.jpeg,.png" class="block w-full text-sm text-zinc-500 file:mr-4 file:rounded file:border-0 file:bg-zinc-100 file:px-4 file:py-2 file:text-sm file:font-medium file:text-zinc-700 dark:text-zinc-400 dark:file:bg-zinc-700 dark:file:text-zinc-200" />
                    @error('billAttachment') <span class="text-xs text-red-500">{{ $message }}</span> @enderror
                </flux:field>

                <div class="flex justify-end gap-2">
                    <flux:button variant="ghost" wire:click="$set('showCreateBillModal', false)">{{ __('Cancel') }}</flux:button>
                    <flux:button variant="primary" wire:click="createVendorBill">{{ __('Create Bill') }}</flux:button>
                </div>
            </div>
        </flux:modal>
    @endif

    {{-- Tab 2: Payroll --}}
    @if($tab === 'payroll')
        <div class="mb-4 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading size="lg">{{ __('Payroll') }}</flux:heading>
            <div class="flex items-center gap-2">
                @if($this->latestPayPeriod)
                    <a href="{{ route('payroll.run', $this->latestPayPeriod) }}" class="inline-flex" wire:navigate>
                        <flux:button size="sm" variant="primary" icon="arrow-top-right-on-square">{{ __('Open Payroll') }}</flux:button>
                    </a>
                @endif
                <a href="{{ route('payroll.index') }}" class="inline-flex" wire:navigate>
                    <flux:button size="sm" icon="list-bullet">{{ __('All Periods') }}</flux:button>
                </a>
            </div>
        </div>

        @if($this->latestPayPeriod)
            <div class="mb-4 rounded-lg border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900">
                <div class="flex items-center justify-between">
                    <div>
                        <flux:text size="sm" class="font-medium">{{ __('Pay Period') }}: {{ $this->latestPayPeriod->start_date->format('M j') }} - {{ $this->latestPayPeriod->end_date->format('M j, Y') }}</flux:text>
                        <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">{{ ucfirst($this->latestPayPeriod->type) }} &middot; {{ ucfirst($this->latestPayPeriod->status) }}</flux:text>
                    </div>
                    <flux:badge size="sm" :color="match($this->latestPayPeriod->status) {
                        'open' => 'amber',
                        'processing' => 'blue',
                        'completed' => 'green',
                        default => 'zinc',
                    }">{{ ucfirst($this->latestPayPeriod->status) }}</flux:badge>
                </div>
            </div>

            @if($this->payStubs->isEmpty())
                <flux:callout variant="outline" icon="banknotes">
                    <flux:callout.heading>{{ __('No pay stubs') }}</flux:callout.heading>
                    <flux:callout.text>{{ __('No pay stubs found for this pay period.') }}</flux:callout.text>
                </flux:callout>
            @else
                <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                    <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                        <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Employee') }}</th>
                                <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Gross') }}</th>
                                <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Taxes') }}</th>
                                <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Deductions') }}</th>
                                <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Net') }}</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Status') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-700 dark:bg-zinc-900">
                            @foreach($this->payStubs as $stub)
                                <tr wire:key="stub-{{ $stub->id }}">
                                    <td class="whitespace-nowrap px-4 py-3 text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                        {{ $stub->employeeProfile?->user?->name ?? '-' }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-zinc-900 dark:text-zinc-100">
                                        ${{ number_format($stub->gross_pay, 2) }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-zinc-600 dark:text-zinc-400">
                                        ${{ number_format(($stub->federal_tax ?? 0) + ($stub->state_tax ?? 0) + ($stub->local_tax ?? 0) + ($stub->social_security ?? 0) + ($stub->medicare ?? 0), 2) }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-zinc-600 dark:text-zinc-400">
                                        ${{ number_format($stub->total_deductions ?? 0, 2) }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-medium text-emerald-600 dark:text-emerald-400">
                                        ${{ number_format($stub->net_pay, 2) }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3">
                                        <flux:badge size="sm" :color="$stub->isFinalized() ? 'green' : 'amber'">{{ ucfirst($stub->status ?? 'draft') }}</flux:badge>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @else
            <flux:callout variant="outline" icon="calendar">
                <flux:callout.heading>{{ __('No pay periods') }}</flux:callout.heading>
                <flux:callout.text>{{ __('No pay periods have been created yet.') }}</flux:callout.text>
            </flux:callout>
        @endif
    @endif

    {{-- Tab 3: Commissions --}}
    @if($tab === 'commissions')
        <div class="mb-4 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading size="lg">{{ __('Commissions') }}</flux:heading>
            <div class="flex items-center gap-2">
                <flux:select wire:model.live="commissionStatusFilter" size="sm" class="w-36">
                    <option value="">{{ __('All') }}</option>
                    <option value="pending">{{ __('Pending') }}</option>
                    <option value="approved">{{ __('Approved') }}</option>
                    <option value="paid">{{ __('Paid') }}</option>
                    <option value="reversed">{{ __('Reversed') }}</option>
                </flux:select>
                <a href="{{ route('payroll.commissions') }}" class="inline-flex" wire:navigate>
                    <flux:button size="sm" icon="arrow-top-right-on-square">{{ __('Commission Manager') }}</flux:button>
                </a>
            </div>
        </div>

        @if(auth()->user()?->can('payroll.manage') && count($this->selectedCommissions) > 0)
            <div class="mb-4 flex items-center gap-2 rounded-lg border border-blue-200 bg-blue-50 px-4 py-2 dark:border-blue-800 dark:bg-blue-900/20">
                <flux:text size="sm">{{ count($this->selectedCommissions) }} {{ __('selected') }}</flux:text>
                <flux:button size="sm" variant="primary" wire:click="approveSelectedCommissions">{{ __('Approve Selected') }}</flux:button>
            </div>
        @endif

        @if($this->commissions->isEmpty())
            <flux:callout variant="outline" icon="currency-dollar">
                <flux:callout.heading>{{ __('No commissions found') }}</flux:callout.heading>
                <flux:callout.text>{{ __('No commission entries match your current filter.') }}</flux:callout.text>
            </flux:callout>
        @else
            <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                        <tr>
                            @can('payroll.manage')
                                <th class="px-3 py-3 text-left">
                                    <input type="checkbox" wire:click="$set('selectedCommissions', {{ $this->commissions->pluck('id')->toJson() }})" class="rounded border-zinc-300 dark:border-zinc-600" />
                                </th>
                            @endcan
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Employee') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Source') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Type') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Rate') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Amount') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Status') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-700 dark:bg-zinc-900">
                        @foreach($this->commissions as $entry)
                            <tr wire:key="commission-{{ $entry->id }}">
                                @can('payroll.manage')
                                    <td class="px-3 py-3">
                                        <input type="checkbox" wire:model.live="selectedCommissions" value="{{ $entry->id }}" class="rounded border-zinc-300 dark:border-zinc-600" />
                                    </td>
                                @endcan
                                <td class="whitespace-nowrap px-4 py-3 text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                    {{ $entry->employeeProfile?->user?->name ?? '-' }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-600 dark:text-zinc-400">
                                    {{ $entry->source_label ?? '-' }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-600 dark:text-zinc-400">
                                    {{ str_replace('_', ' ', ucfirst($entry->commission_type ?? '-')) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-zinc-600 dark:text-zinc-400">
                                    @if($entry->rate && $entry->rate > 0)
                                        {{ number_format($entry->rate * 100, 2) }}%
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                    ${{ number_format($entry->amount, 2) }}
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    <flux:badge size="sm" :color="match($entry->status) {
                                        'pending' => 'amber',
                                        'approved' => 'blue',
                                        'paid' => 'green',
                                        'reversed' => 'red',
                                        default => 'zinc',
                                    }">{{ ucfirst($entry->status) }}</flux:badge>
                                </td>
                                <td class="whitespace-nowrap px-4 py-3">
                                    @if($entry->isPending() && auth()->user()?->can('payroll.manage'))
                                        <flux:button size="sm" wire:click="approveCommission({{ $entry->id }})">{{ __('Approve') }}</flux:button>
                                    @endif
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
    @endif

    {{-- Tab 4: 1099 Tracker --}}
    @if($tab === '1099')
        <div class="mb-4 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <flux:heading size="lg">{{ __('1099 Tracker') }}</flux:heading>
            <div class="flex items-center gap-2">
                <flux:select wire:model.live="taxYear" size="sm" class="w-28">
                    @for($y = now()->year; $y >= now()->year - 3; $y--)
                        <option value="{{ $y }}">{{ $y }}</option>
                    @endfor
                </flux:select>
                @can('ar.manage')
                    <a href="{{ route('payments.1099-export', ['year' => $taxYear]) }}">
                        <flux:button size="sm" icon="arrow-down-tray">{{ __('Export CSV') }}</flux:button>
                    </a>
                @endcan
            </div>
        </div>

        @if($this->subcontractorTotals->isEmpty())
            <flux:callout variant="outline" icon="document-text">
                <flux:callout.heading>{{ __('No 1099 data') }}</flux:callout.heading>
                <flux:callout.text>{{ __('No paid subcontractor payables found for :year.', ['year' => $taxYear]) }}</flux:callout.text>
            </flux:callout>
        @else
            <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Crew Name') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Company') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Total Paid YTD') }}</th>
                            <th class="px-4 py-3 text-center text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('W9 On File') }}</th>
                            <th class="px-4 py-3 text-center text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('1099 Required') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-700 dark:bg-zinc-900">
                        @foreach($this->subcontractorTotals as $index => $row)
                            <tr wire:key="sub-{{ $index }}">
                                <td class="whitespace-nowrap px-4 py-3 text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $row['name'] }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-600 dark:text-zinc-400">{{ $row['company'] ?: '-' }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-medium text-zinc-900 dark:text-zinc-100">${{ number_format($row['total_paid'], 2) }}</td>
                                <td class="whitespace-nowrap px-4 py-3 text-center">
                                    @if($row['w9_on_file'])
                                        <flux:badge size="sm" color="green">{{ __('Yes') }}</flux:badge>
                                    @else
                                        <flux:badge size="sm" color="red">{{ __('Missing') }}</flux:badge>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-4 py-3 text-center">
                                    @if($row['total_paid'] >= 600)
                                        <flux:badge size="sm" color="amber">{{ __('Yes') }}</flux:badge>
                                    @else
                                        <flux:badge size="sm" color="zinc">{{ __('No') }}</flux:badge>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Vendor 1099 Tracker --}}
        <div class="mt-6">
            <flux:heading size="md" class="mb-3">{{ __('Vendor Payments') }}</flux:heading>

            @if($this->vendor1099Totals->isEmpty())
                <flux:callout variant="outline" icon="document-text">
                    <flux:callout.text>{{ __('No paid vendor payables found for :year.', ['year' => $taxYear]) }}</flux:callout.text>
                </flux:callout>
            @else
                <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                    <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                        <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Vendor') }}</th>
                                <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Category') }}</th>
                                <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Total Paid YTD') }}</th>
                                <th class="px-4 py-3 text-center text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('W9 On File') }}</th>
                                <th class="px-4 py-3 text-center text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('1099 Required') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-700 dark:bg-zinc-900">
                            @foreach($this->vendor1099Totals as $vendor)
                                <tr wire:key="vendor-1099-{{ $vendor->id }}">
                                    <td class="whitespace-nowrap px-4 py-3 text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                        {{ $vendor->name }}
                                        @if($vendor->contact_name)
                                            <div class="text-xs text-zinc-500">{{ $vendor->contact_name }}</div>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-500 dark:text-zinc-400">
                                        <flux:badge size="sm">{{ Vendor::CATEGORIES[$vendor->category] ?? $vendor->category }}</flux:badge>
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-semibold {{ $vendor->total_paid >= 600 ? 'text-red-600 dark:text-red-400' : 'text-zinc-900 dark:text-zinc-100' }}">
                                        ${{ number_format($vendor->total_paid, 2) }}
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-center">
                                        @if($vendor->w9_on_file)
                                            <flux:icon name="check-circle" variant="mini" class="text-green-500 inline" />
                                        @else
                                            <flux:icon name="x-circle" variant="mini" class="text-red-500 inline" />
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-4 py-3 text-center">
                                        @if($vendor->total_paid >= 600)
                                            <flux:badge color="red" size="sm">{{ __('Yes') }}</flux:badge>
                                        @else
                                            <flux:badge size="sm" color="zinc">{{ __('No') }}</flux:badge>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif
</div>
