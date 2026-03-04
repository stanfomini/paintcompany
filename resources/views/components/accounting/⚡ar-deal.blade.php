<?php

use App\Models\Account;
use App\Models\CommissionEntry;
use App\Models\Deal;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\JobCostCard;
use App\Models\JobPayable;
use App\Models\Vendor;
use App\Services\CommissionService;
use App\Services\EstimateCostBreakdownService;
use App\Services\JobCostCardService;
use App\Services\JobPayableService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new
#[Title('AR Portal')]
class extends Component {
    use WithFileUploads;

    public Deal $deal;

    // Inline editing of actuals
    public ?string $editingActualField = null;
    public ?float $editingActualValue = null;

    // Quick-add vendor
    public bool $showQuickAddVendor = false;
    public string $quickVendorName = '';
    public string $quickVendorCategory = 'supplier';

    // Add payable modal
    public bool $showAddPayableModal = false;
    public string $newPayableType = 'vendor';
    public ?float $newPayableAmount = null;
    public string $newPayableDescription = '';
    public string $newPayableCategory = 'materials';
    public ?int $newPayableVendorId = null;
    public ?int $newPayableUserId = null;
    public ?int $newPayableDebitAccountId = null;
    public ?int $newPayableCreditAccountId = null;
    public string $newPayableInvoiceNumber = '';
    public $newPayableAttachment = null;

    // Payment modal
    public bool $showPaymentModal = false;
    public ?int $payingPayableId = null;
    public string $paymentMethod = 'check';
    public string $paymentReference = '';
    public ?int $paymentFromAccountId = null;

    // Edit payable modal
    public bool $showEditPayableModal = false;
    public ?int $editingPayableId = null;
    public ?float $editPayableAmount = null;
    public string $editPayableDescription = '';
    public string $editPayableCategory = '';
    public ?int $editPayableDebitAccountId = null;
    public ?int $editPayableCreditAccountId = null;

    // Allocation editing
    public array $allocationOverrides = [];
    public array $allocationAccounts = [];
    public bool $editingAllocations = false;

    public function boot(): void
    {
        if (! Auth::user()?->can('ar.view')) {
            abort(403);
        }
    }

    public function mount(Deal $deal): void
    {
        $this->deal = $deal->load([
            'customer',
            'property',
            'assignedTo',
            'leadGenerator',
        ]);
    }

    #[Computed]
    public function job(): ?Job
    {
        return $this->deal->job;
    }

    #[Computed]
    public function costCard(): ?JobCostCard
    {
        return $this->job?->costCards()->latest()->first();
    }

    #[Computed]
    public function allocations()
    {
        return $this->costCard?->cardAllocations()->with('account')->get() ?? collect();
    }

    #[Computed]
    public function payables()
    {
        if (! $this->job) {
            return collect();
        }

        return JobPayable::where('job_id', $this->job->id)
            ->with(['payeeUser', 'vendor', 'debitAccount', 'creditAccount'])
            ->get();
    }

    #[Computed]
    public function invoices()
    {
        if (! $this->job) {
            return collect();
        }

        return Invoice::where('job_id', $this->job->id)
            ->orderByDesc('created_at')
            ->get();
    }

    #[Computed]
    public function groupedPayables()
    {
        return $this->payables->groupBy('payee_type');
    }

    #[Computed]
    public function acceptedCrews()
    {
        return $this->job?->acceptedCrew()->get() ?? collect();
    }

    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()?->can('ar.manage') ?? false;
    }

    #[Computed]
    public function canApprovePayables(): bool
    {
        return Auth::user()?->can('ar.approve_payables') ?? false;
    }

    #[Computed]
    public function canCreateJournalEntries(): bool
    {
        return Auth::user()?->can('ar.create_journal_entries') ?? false;
    }

    #[Computed]
    public function dealCommissions()
    {
        if (! $this->job) {
            return collect();
        }

        return CommissionEntry::query()
            ->where('source_type', Job::class)
            ->where('source_id', $this->job->id)
            ->with('employeeProfile.user')
            ->get();
    }

    #[Computed]
    public function vendors()
    {
        return Vendor::where('is_active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function internalUsers()
    {
        return \App\Models\User::where('type', 'internal')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function crewUsers()
    {
        return \App\Models\User::where('type', 'crew')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    #[Computed]
    public function expenseAccounts()
    {
        return Account::where('type', 'expense')->active()->orderBy('code')->get();
    }

    #[Computed]
    public function liabilityAccounts()
    {
        return Account::where('type', 'liability')->active()->orderBy('code')->get();
    }

    #[Computed]
    public function bankAccounts()
    {
        return Account::bankAccounts()->orderBy('code')->get();
    }

    #[Computed]
    public function allExpenseAccounts()
    {
        return Account::whereIn('type', ['expense', 'revenue'])->active()->orderBy('code')->get();
    }

    #[Computed]
    public function cogsLines(): array
    {
        $card = $this->costCard;
        if (! $card) {
            return [];
        }

        return [
            ['label' => 'Labor', 'projected_field' => 'labor_cost', 'actual_field' => 'actual_labor_cost'],
            ['label' => 'Cleanup', 'projected_field' => 'cleanup_cost', 'actual_field' => 'actual_cleanup_cost'],
            ['label' => 'Sundries', 'projected_field' => 'sundries_cost', 'actual_field' => 'actual_sundries_cost'],
            ['label' => 'Materials', 'projected_field' => 'material_cost', 'actual_field' => 'actual_material_cost'],
            ['label' => 'Material Tax', 'projected_field' => 'material_tax', 'actual_field' => 'actual_material_tax'],
            ['label' => 'Labor Tax', 'projected_field' => 'labor_tax', 'actual_field' => 'actual_labor_tax'],
            ['label' => 'Add-ons', 'projected_field' => 'addon_cost', 'actual_field' => 'actual_addon_cost'],
            ['label' => 'Vendor Items', 'projected_field' => 'items_vendor_cost', 'actual_field' => 'actual_items_vendor_cost'],
            ['label' => 'Warranty Reserve', 'projected_field' => 'warranty_reserve', 'actual_field' => 'actual_warranty_reserve'],
        ];
    }

    #[Computed]
    public function estimateBreakdown(): ?array
    {
        $estimate = $this->job?->estimate;
        if (! $estimate) {
            return null;
        }
        $estimate->loadMissing(['areas.surfaces.productionRate', 'areas.addons', 'items', 'packages.items.itemable']);

        return app(EstimateCostBreakdownService::class)->calculateBreakdown($estimate);
    }

    #[Computed]
    public function estimateCostMapping(): array
    {
        $breakdown = $this->estimateBreakdown;
        if (! $breakdown) {
            return [];
        }

        $cogs = $breakdown['cogs'] ?? [];

        return [
            'labor_cost' => $cogs['labor_cost'] ?? null,
            'cleanup_cost' => $cogs['cleanup_cost'] ?? null,
            'sundries_cost' => $cogs['sundries_cost'] ?? null,
            'material_cost' => $cogs['material_cost'] ?? null,
            'material_tax' => $cogs['material_tax'] ?? null,
            'labor_tax' => $cogs['labor_tax'] ?? null,
            'addon_cost' => $cogs['addon_cost'] ?? null,
            'items_vendor_cost' => $cogs['items_vendor_cost'] ?? null,
            'warranty_reserve' => $cogs['warranty_price'] ?? null,
            'total' => $cogs['total'] ?? null,
        ];
    }

    #[Computed]
    public function profitSummary(): array
    {
        $card = $this->costCard;
        if (! $card) {
            return [];
        }

        $price = (float) $card->price;
        $projectedCogs = (float) $card->total_cogs;
        $actualCogs = $card->has_actuals ? $card->actual_cogs : null;
        $allocationTotal = (float) $card->allocation_total;
        $actualAllocationTotal = $card->actual_allocation_total !== null ? (float) $card->actual_allocation_total : null;

        $grossProfit = $price - $projectedCogs;
        $actualGrossProfit = $actualCogs !== null ? $price - $actualCogs : null;

        $netProfit = $grossProfit - $allocationTotal;
        $actualNetProfit = ($actualGrossProfit !== null && $actualAllocationTotal !== null)
            ? $actualGrossProfit - $actualAllocationTotal
            : null;

        $margin = $price > 0 ? round($grossProfit / $price * 100, 1) : 0;
        $actualMargin = ($actualGrossProfit !== null && $price > 0)
            ? round($actualGrossProfit / $price * 100, 1)
            : null;

        return [
            'revenue' => $price,
            'projected_cogs' => $projectedCogs,
            'actual_cogs' => $actualCogs,
            'gross_profit' => $grossProfit,
            'actual_gross_profit' => $actualGrossProfit,
            'allocation_total' => $allocationTotal,
            'actual_allocation_total' => $actualAllocationTotal,
            'net_profit' => $netProfit,
            'actual_net_profit' => $actualNetProfit,
            'margin' => $margin,
            'actual_margin' => $actualMargin,
        ];
    }

    #[Computed]
    public function bankDistribution(): array
    {
        $card = $this->costCard;
        if (! $card || ! $card->has_actuals) {
            return [];
        }

        return app(JobCostCardService::class)->calculateBankDistribution($card);
    }

    public function quickAddVendor(): void
    {
        $this->validate([
            'quickVendorName' => ['required', 'string', 'max:255'],
            'quickVendorCategory' => ['required', 'string', 'in:supplier,subcontractor,service,other'],
        ]);

        $vendor = Vendor::create([
            'company_setting_id' => \App\Models\CompanySetting::getInstance()->id,
            'name' => $this->quickVendorName,
            'category' => $this->quickVendorCategory,
            'is_active' => true,
        ]);

        $this->newPayableVendorId = $vendor->id;
        $this->showQuickAddVendor = false;
        $this->quickVendorName = '';
        $this->quickVendorCategory = 'supplier';
        unset($this->vendors);
    }

    public function updatedNewPayableVendorId(): void
    {
        if ($this->newPayableVendorId) {
            $vendor = Vendor::find($this->newPayableVendorId);
            if ($vendor?->default_expense_account_id) {
                $this->newPayableDebitAccountId = $vendor->default_expense_account_id;
            }
        }
    }

    // ─── Actions ───

    public function startEditingActual(string $field): void
    {
        abort_unless($this->canManage, 403);

        $card = $this->costCard;
        if (! $card) {
            return;
        }

        $this->editingActualField = $field;
        $this->editingActualValue = $card->$field !== null ? (float) $card->$field : null;
    }

    public function saveActual(): void
    {
        abort_unless($this->canManage, 403);

        $card = $this->costCard;
        if (! $card || ! $this->editingActualField) {
            return;
        }

        app(JobCostCardService::class)->updateActuals($card, [
            $this->editingActualField => $this->editingActualValue,
        ]);

        $this->editingActualField = null;
        $this->editingActualValue = null;

        unset($this->costCard, $this->profitSummary, $this->bankDistribution);
    }

    public function cancelEditingActual(): void
    {
        $this->editingActualField = null;
        $this->editingActualValue = null;
    }

    public function startEditingAllocations(): void
    {
        abort_unless($this->canManage, 403);

        $this->allocationOverrides = [];
        $this->allocationAccounts = [];
        foreach ($this->allocations as $alloc) {
            $this->allocationOverrides[$alloc->id] = $alloc->actual_amount !== null ? (float) $alloc->actual_amount : (float) $alloc->amount;
            $this->allocationAccounts[$alloc->id] = $alloc->account_id;
        }
        $this->editingAllocations = true;
    }

    public function saveAllocations(): void
    {
        abort_unless($this->canManage, 403);

        foreach ($this->allocations as $alloc) {
            $alloc->update([
                'actual_amount' => $this->allocationOverrides[$alloc->id] ?? $alloc->amount,
                'account_id' => $this->allocationAccounts[$alloc->id] ?? null,
            ]);
        }

        $this->editingAllocations = false;
        unset($this->allocations, $this->profitSummary, $this->bankDistribution);
    }

    public function resetAllocations(): void
    {
        abort_unless($this->canManage, 403);

        foreach ($this->allocations as $alloc) {
            $alloc->update(['actual_amount' => null, 'account_id' => null]);
        }

        $this->editingAllocations = false;
        unset($this->allocations, $this->profitSummary, $this->bankDistribution);
    }

    public function cancelEditingAllocations(): void
    {
        $this->editingAllocations = false;
        $this->allocationOverrides = [];
        $this->allocationAccounts = [];
    }

    public function approvePayable(int $payableId): void
    {
        abort_unless($this->canApprovePayables, 403);

        $payable = JobPayable::findOrFail($payableId);
        app(JobPayableService::class)->approve($payable, Auth::user());

        unset($this->payables, $this->groupedPayables);
    }

    public function openPaymentModal(int $payableId): void
    {
        abort_unless($this->canManage, 403);

        $this->payingPayableId = $payableId;
        $this->paymentMethod = 'check';
        $this->paymentReference = '';
        $this->paymentFromAccountId = null;
        $this->showPaymentModal = true;
    }

    public function markPayableAsPaid(): void
    {
        abort_unless($this->canManage, 403);

        $payable = JobPayable::findOrFail($this->payingPayableId);
        app(JobPayableService::class)->markPaid($payable, [
            'paid_via' => $this->paymentMethod,
            'reference_number' => $this->paymentReference,
            'paid_from_account_id' => $this->paymentFromAccountId,
        ]);

        $this->showPaymentModal = false;
        $this->payingPayableId = null;
        $this->paymentMethod = 'check';
        $this->paymentReference = '';
        $this->paymentFromAccountId = null;

        unset($this->payables, $this->groupedPayables);
    }

    public function voidPayable(int $payableId): void
    {
        abort_unless($this->canManage, 403);

        $payable = JobPayable::findOrFail($payableId);
        $payable->void();

        unset($this->payables, $this->groupedPayables);
    }

    public function openAddPayableModal(): void
    {
        abort_unless($this->canManage, 403);

        $this->reset(['newPayableType', 'newPayableAmount', 'newPayableDescription', 'newPayableCategory', 'newPayableVendorId', 'newPayableUserId', 'newPayableDebitAccountId', 'newPayableCreditAccountId', 'newPayableInvoiceNumber', 'newPayableAttachment']);
        $this->newPayableType = 'vendor';
        $this->newPayableCategory = 'materials';
        $this->showAddPayableModal = true;
    }

    public function createPayable(): void
    {
        abort_unless($this->canManage, 403);

        $this->validate([
            'newPayableAmount' => 'required|numeric|min:0.01',
            'newPayableDescription' => 'required|string|max:255',
            'newPayableType' => 'required|in:vendor,employee,crew,contractor',
            'newPayableCategory' => 'required|in:' . implode(',', array_keys(JobPayable::CATEGORIES)),
        ]);

        $job = $this->job;
        if (! $job) {
            return;
        }

        $service = app(JobPayableService::class);
        $data = [
            'amount' => $this->newPayableAmount,
            'description' => $this->newPayableDescription,
            'category' => $this->newPayableCategory,
            'debit_account_id' => $this->newPayableDebitAccountId,
            'credit_account_id' => $this->newPayableCreditAccountId,
        ];

        // Vendor-specific fields
        if ($this->newPayableType === 'vendor') {
            $this->validate([
                'newPayableInvoiceNumber' => 'nullable|string|max:100',
                'newPayableAttachment' => 'nullable|file|max:10240',
            ]);

            if ($this->newPayableInvoiceNumber) {
                $data['vendor_invoice_number'] = $this->newPayableInvoiceNumber;
            }

            if ($this->newPayableAttachment) {
                $data['attachment_path'] = $this->newPayableAttachment->store('payable-attachments', 'public');
            }
        }

        match ($this->newPayableType) {
            'vendor' => (function () use ($service, $job, $data) {
                $this->validate(['newPayableVendorId' => 'required|exists:vendors,id']);
                $vendor = Vendor::findOrFail($this->newPayableVendorId);
                $service->createVendorPayable($job, $vendor, $data);
            })(),
            'employee' => (function () use ($service, $job, $data) {
                $this->validate(['newPayableUserId' => 'required|exists:users,id']);
                $user = \App\Models\User::findOrFail($this->newPayableUserId);
                $service->createEmployeePayable($job, $user, $data);
            })(),
            'crew', 'contractor' => (function () use ($service, $job, $data) {
                $this->validate(['newPayableUserId' => 'required|exists:users,id']);
                $user = \App\Models\User::findOrFail($this->newPayableUserId);
                $service->createCrewManualPayable($job, $user, $data);
            })(),
        };

        $this->showAddPayableModal = false;
        unset($this->payables, $this->groupedPayables);
    }

    public function openEditPayableModal(int $payableId): void
    {
        abort_unless($this->canManage, 403);

        $payable = JobPayable::findOrFail($payableId);

        if ($payable->isPaid() || $payable->isVoid()) {
            return;
        }

        $this->editingPayableId = $payable->id;
        $this->editPayableAmount = (float) $payable->amount;
        $this->editPayableDescription = $payable->description;
        $this->editPayableCategory = $payable->category;
        $this->editPayableDebitAccountId = $payable->debit_account_id;
        $this->editPayableCreditAccountId = $payable->credit_account_id;
        $this->showEditPayableModal = true;
    }

    public function updatePayable(): void
    {
        abort_unless($this->canManage, 403);

        $this->validate([
            'editPayableAmount' => 'required|numeric|min:0.01',
            'editPayableDescription' => 'required|string|max:255',
            'editPayableCategory' => 'required|in:' . implode(',', array_keys(JobPayable::CATEGORIES)),
        ]);

        $payable = JobPayable::findOrFail($this->editingPayableId);

        if ($payable->isPaid() || $payable->isVoid()) {
            return;
        }

        $payable->update([
            'amount' => $this->editPayableAmount,
            'description' => $this->editPayableDescription,
            'category' => $this->editPayableCategory,
            'debit_account_id' => $this->editPayableDebitAccountId,
            'credit_account_id' => $this->editPayableCreditAccountId,
        ]);

        $this->showEditPayableModal = false;
        $this->editingPayableId = null;
        unset($this->payables, $this->groupedPayables);
    }

    public function createJournalEntry(): void
    {
        abort_unless($this->canCreateJournalEntries, 403);

        $card = $this->costCard;
        if (! $card || ! $card->has_actuals) {
            return;
        }

        app(JobCostCardService::class)->createJournalEntryFromActuals($card, Auth::id());
        unset($this->costCard);
    }

    public function approveCommission(int $id): void
    {
        abort_unless($this->canApprovePayables, 403);
        $entry = CommissionEntry::findOrFail($id);
        app(CommissionService::class)->approveCommission($entry, Auth::user());
        unset($this->dealCommissions);
    }

    public function reverseCommission(int $id): void
    {
        abort_unless($this->canManage, 403);
        $entry = CommissionEntry::findOrFail($id);
        app(CommissionService::class)->reverseCommission($entry, 'Reversed from AR deal page');
        unset($this->dealCommissions);
    }
};

?>

<div>
    {{-- Breadcrumb --}}
    <div class="mb-4">
        <a href="{{ route('ar.index') }}" class="inline-flex items-center gap-1 text-sm text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200">
            <flux:icon name="arrow-left" variant="micro" class="size-4" />
            Back to AR Portal
        </a>
    </div>

    {{-- Section 1: Header + People Connected --}}
    <div class="mb-6 rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
                <flux:heading size="xl">{{ $deal->title }}</flux:heading>
                <div class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    @if ($deal->customer)
                        {{ $deal->customer->full_name }}
                    @endif
                    @if ($deal->property)
                        &mdash; {{ $deal->property->full_address }}
                    @endif
                </div>
            </div>
            <flux:badge :color="match($deal->stage) {
                'signed' => 'blue',
                'completed' => 'green',
                'lost', 'cancelled' => 'red',
                'quoted' => 'amber',
                default => 'zinc',
            }" size="sm">{{ $deal->stage_label }}</flux:badge>
        </div>

        {{-- People Connected --}}
        <div class="mt-4 flex flex-wrap gap-2">
            @if ($deal->leadGenerator)
                <div class="inline-flex items-center gap-1.5 rounded-full border border-zinc-200 bg-zinc-50 px-3 py-1 text-xs dark:border-zinc-600 dark:bg-zinc-700">
                    <span class="font-medium text-zinc-500 dark:text-zinc-400">Lead Gen</span>
                    <span class="text-zinc-900 dark:text-zinc-100">{{ $deal->leadGenerator->name }}</span>
                </div>
            @endif
            @if ($deal->assignedTo)
                <div class="inline-flex items-center gap-1.5 rounded-full border border-zinc-200 bg-zinc-50 px-3 py-1 text-xs dark:border-zinc-600 dark:bg-zinc-700">
                    <span class="font-medium text-zinc-500 dark:text-zinc-400">Sales</span>
                    <span class="text-zinc-900 dark:text-zinc-100">{{ $deal->assignedTo->name }}</span>
                </div>
            @endif
            @if ($this->job?->productionManager)
                <div class="inline-flex items-center gap-1.5 rounded-full border border-zinc-200 bg-zinc-50 px-3 py-1 text-xs dark:border-zinc-600 dark:bg-zinc-700">
                    <span class="font-medium text-zinc-500 dark:text-zinc-400">Prod Mgr</span>
                    <span class="text-zinc-900 dark:text-zinc-100">{{ $this->job->productionManager->name }}</span>
                </div>
            @endif
            @foreach ($this->acceptedCrews as $crew)
                <div wire:key="crew-{{ $crew->id }}" class="inline-flex items-center gap-1.5 rounded-full border border-emerald-200 bg-emerald-50 px-3 py-1 text-xs dark:border-emerald-700 dark:bg-emerald-900/30">
                    <span class="font-medium text-emerald-600 dark:text-emerald-400">Crew</span>
                    <span class="text-emerald-900 dark:text-emerald-100">{{ $crew->name }}</span>
                </div>
            @endforeach
        </div>
    </div>

    @if (! $this->job)
        <flux:callout variant="warning" class="mb-6">
            <x-slot:heading>No Job Found</x-slot:heading>
            No job found for this deal. A job is created when the customer signs an estimate.
        </flux:callout>
    @elseif (! $this->costCard)
        <flux:callout variant="warning" class="mb-6">
            <x-slot:heading>No Cost Card Found</x-slot:heading>
            No cost card found. Cost cards are created automatically when an estimate is signed.
        </flux:callout>
    @else
    <div class="space-y-8">
        {{-- Section 2: Revenue --}}
        <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:heading size="lg" class="mb-4">Revenue</flux:heading>

            <div class="mb-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
                <div>
                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">Customer Price</flux:text>
                    <p class="text-lg font-semibold text-zinc-900 dark:text-white">${{ number_format((float) $this->costCard->price, 2) }}</p>
                </div>
                <div>
                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">Total Invoiced</flux:text>
                    <p class="text-lg font-semibold text-zinc-900 dark:text-white">${{ number_format((float) $this->costCard->total_invoiced, 2) }}</p>
                </div>
                <div>
                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">Total Collected</flux:text>
                    <p class="text-lg font-semibold text-green-600 dark:text-green-400">${{ number_format((float) $this->costCard->total_collected, 2) }}</p>
                </div>
                <div>
                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">Balance Due</flux:text>
                    <p class="text-lg font-semibold {{ (float) $this->costCard->balance_due > 0 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}">${{ number_format((float) $this->costCard->balance_due, 2) }}</p>
                </div>
            </div>

            @if ($this->invoices->isNotEmpty())
                <flux:separator class="my-4" />
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-zinc-500 dark:text-zinc-400">
                                <th class="pb-2 pr-4 font-medium">Invoice #</th>
                                <th class="pb-2 pr-4 font-medium">Type</th>
                                <th class="pb-2 pr-4 font-medium text-right">Amount</th>
                                <th class="pb-2 pr-4 font-medium">Status</th>
                                <th class="pb-2 font-medium"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                            @foreach ($this->invoices as $invoice)
                                <tr wire:key="invoice-{{ $invoice->id }}">
                                    <td class="py-2 pr-4 text-zinc-900 dark:text-white">{{ $invoice->invoice_number }}</td>
                                    <td class="py-2 pr-4">
                                        <flux:badge size="sm" color="{{ match($invoice->type) { 'deposit' => 'blue', 'final' => 'green', 'change_order' => 'amber', default => 'zinc' } }}">
                                            {{ Invoice::TYPES[$invoice->type] ?? $invoice->type }}
                                        </flux:badge>
                                    </td>
                                    <td class="py-2 pr-4 text-right text-zinc-900 dark:text-white">${{ number_format((float) $invoice->total, 2) }}</td>
                                    <td class="py-2 pr-4">
                                        <flux:badge size="sm" color="{{ match($invoice->status) { 'paid' => 'green', 'sent' => 'blue', 'partially_paid' => 'amber', 'overdue' => 'red', 'void' => 'zinc', default => 'zinc' } }}">
                                            {{ Invoice::STATUSES[$invoice->status] ?? $invoice->status }}
                                        </flux:badge>
                                    </td>
                                    <td class="py-2">
                                        <a href="{{ route('invoices.show', $invoice) }}" class="text-sm text-blue-600 hover:underline dark:text-blue-400">View</a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <flux:text class="text-zinc-500 dark:text-zinc-400">No invoices yet.</flux:text>
            @endif
        </div>

        {{-- Section 3: COGS Breakdown (Estimated vs Projected vs Actual) --}}
        @php
            $estimateMap = $this->estimateCostMapping;
            $hasEstimate = ! empty($estimateMap);
        @endphp
        <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:heading size="lg" class="mb-4">COGS Breakdown</flux:heading>

            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-zinc-500 dark:text-zinc-400">
                            <th class="pb-2 pr-4 font-medium">Line Item</th>
                            @if ($hasEstimate)
                                <th class="pb-2 pr-4 font-medium text-right">Estimated</th>
                            @endif
                            <th class="pb-2 pr-4 font-medium text-right">Projected</th>
                            <th class="pb-2 pr-4 font-medium text-right">Actual</th>
                            <th class="pb-2 font-medium text-right">Variance</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                        @foreach ($this->cogsLines as $line)
                            @php
                                $projected = (float) ($this->costCard->{$line['projected_field']} ?? 0);
                                $actual = $this->costCard->{$line['actual_field']};
                                $hasActual = $actual !== null;
                                $actualFloat = $hasActual ? (float) $actual : null;
                                $variance = $hasActual ? round($projected - $actualFloat, 2) : null;
                                $estimated = $hasEstimate ? ($estimateMap[$line['projected_field']] ?? null) : null;
                            @endphp
                            <tr wire:key="cogs-{{ $line['actual_field'] }}">
                                <td class="py-2 pr-4 text-zinc-900 dark:text-white">{{ $line['label'] }}</td>
                                @if ($hasEstimate)
                                    <td class="py-2 pr-4 text-right text-zinc-500 dark:text-zinc-400">
                                        @if ($estimated !== null)
                                            ${{ number_format((float) $estimated, 2) }}
                                        @else
                                            <span class="text-zinc-400">--</span>
                                        @endif
                                    </td>
                                @endif
                                <td class="py-2 pr-4 text-right text-zinc-900 dark:text-white">${{ number_format($projected, 2) }}</td>
                                <td class="py-2 pr-4 text-right">
                                    @if ($this->editingActualField === $line['actual_field'])
                                        <div class="flex items-center justify-end gap-1">
                                            <span class="text-zinc-500">$</span>
                                            <input
                                                type="number"
                                                step="0.01"
                                                wire:model="editingActualValue"
                                                wire:keydown.enter="saveActual"
                                                wire:keydown.escape="cancelEditingActual"
                                                class="w-24 rounded border border-zinc-300 px-2 py-1 text-right text-sm dark:border-zinc-600 dark:bg-zinc-700 dark:text-white"
                                                autofocus
                                            />
                                            <flux:button size="xs" wire:click="saveActual">Save</flux:button>
                                            <flux:button size="xs" variant="ghost" wire:click="cancelEditingActual">Cancel</flux:button>
                                        </div>
                                    @elseif ($hasActual)
                                        <span class="text-zinc-900 dark:text-white">
                                            ${{ number_format($actualFloat, 2) }}
                                        </span>
                                        @if ($this->canManage)
                                            <button wire:click="startEditingActual('{{ $line['actual_field'] }}')" class="ml-1 text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-300" title="Edit">
                                                <flux:icon name="pencil" variant="micro" class="size-3" />
                                            </button>
                                        @endif
                                    @else
                                        @if ($this->canManage)
                                            <button wire:click="startEditingActual('{{ $line['actual_field'] }}')" class="text-sm text-blue-600 hover:underline dark:text-blue-400">Enter</button>
                                        @else
                                            <span class="text-zinc-400">--</span>
                                        @endif
                                    @endif
                                </td>
                                <td class="py-2 text-right">
                                    @if ($variance !== null)
                                        <span class="{{ $variance >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                            {{ $variance >= 0 ? '+' : '' }}${{ number_format($variance, 2) }}
                                        </span>
                                    @else
                                        <span class="text-zinc-400">--</span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-zinc-300 font-semibold dark:border-zinc-600">
                            <td class="pt-2 pr-4 text-zinc-900 dark:text-white">Total COGS</td>
                            @if ($hasEstimate)
                                <td class="pt-2 pr-4 text-right text-zinc-500 dark:text-zinc-400">
                                    @if (isset($estimateMap['total']))
                                        ${{ number_format((float) $estimateMap['total'], 2) }}
                                    @else
                                        <span class="text-zinc-400">--</span>
                                    @endif
                                </td>
                            @endif
                            <td class="pt-2 pr-4 text-right text-zinc-900 dark:text-white">${{ number_format((float) $this->costCard->total_cogs, 2) }}</td>
                            <td class="pt-2 pr-4 text-right text-zinc-900 dark:text-white">
                                @if ($this->costCard->has_actuals)
                                    ${{ number_format($this->costCard->actual_cogs, 2) }}
                                @else
                                    <span class="text-zinc-400">--</span>
                                @endif
                            </td>
                            <td class="pt-2 text-right">
                                @if ($this->costCard->cost_variance !== null)
                                    <span class="{{ $this->costCard->cost_variance >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                        {{ $this->costCard->cost_variance >= 0 ? '+' : '' }}${{ number_format($this->costCard->cost_variance, 2) }}
                                    </span>
                                @else
                                    <span class="text-zinc-400">--</span>
                                @endif
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        {{-- Section 4: Payables — What We Owe --}}
        <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <flux:heading size="lg">Payables</flux:heading>
                    @if ($this->payables->isNotEmpty())
                        @php
                            $totalPending = $this->payables->whereIn('status', ['pending', 'approved'])->sum('amount');
                            $totalPaid = $this->payables->where('status', 'paid')->sum('amount');
                        @endphp
                        <div class="mt-1 flex gap-4 text-sm">
                            <span class="text-amber-600 dark:text-amber-400">Outstanding: ${{ number_format((float) $totalPending, 2) }}</span>
                            <span class="text-green-600 dark:text-green-400">Paid: ${{ number_format((float) $totalPaid, 2) }}</span>
                        </div>
                    @endif
                </div>
                @if ($this->canManage)
                    <flux:button size="sm" wire:click="openAddPayableModal">Add Payable</flux:button>
                @endif
            </div>

            @if ($this->payables->isEmpty())
                <flux:text class="text-zinc-500 dark:text-zinc-400">No payables recorded yet.</flux:text>
            @else
                @foreach (['crew' => 'Crew', 'vendor' => 'Vendor', 'employee' => 'Employee', 'contractor' => 'Contractor'] as $type => $typeLabel)
                    @if ($this->groupedPayables->has($type))
                        <div class="mb-6">
                            <h4 class="mb-2 text-sm font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ $typeLabel }}</h4>
                            <div class="overflow-x-auto">
                                <table class="min-w-full text-sm">
                                    <thead>
                                        <tr class="text-left text-zinc-500 dark:text-zinc-400">
                                            <th class="pb-2 pr-4 font-medium">Pay To</th>
                                            <th class="pb-2 pr-4 font-medium">Category</th>
                                            <th class="pb-2 pr-4 font-medium text-right">Amount</th>
                                            <th class="pb-2 pr-4 font-medium">Status</th>
                                            <th class="pb-2 pr-4 font-medium">Payment</th>
                                            <th class="pb-2 font-medium">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                                        @foreach ($this->groupedPayables[$type] as $payable)
                                            <tr wire:key="payable-{{ $payable->id }}">
                                                <td class="py-2 pr-4">
                                                    <div class="flex items-center gap-2">
                                                        <div class="flex size-8 shrink-0 items-center justify-center rounded-full {{ match($payable->payee_type) {
                                                            'crew' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
                                                            'vendor' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
                                                            'employee' => 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300',
                                                            default => 'bg-zinc-100 text-zinc-700 dark:bg-zinc-700 dark:text-zinc-300',
                                                        } }}">
                                                            <flux:icon name="{{ match($payable->payee_type) {
                                                                'crew' => 'wrench-screwdriver',
                                                                'vendor' => 'building-storefront',
                                                                'employee' => 'user',
                                                                default => 'user-circle',
                                                            } }}" variant="micro" class="size-4" />
                                                        </div>
                                                        <div>
                                                            <div class="font-medium text-zinc-900 dark:text-white">
                                                                @if ($payable->payeeUser)
                                                                    {{ $payable->payeeUser->name }}
                                                                @elseif ($payable->vendor)
                                                                    {{ $payable->vendor->name }}
                                                                @else
                                                                    {{ $payable->payee_name ?: 'Unknown' }}
                                                                @endif
                                                            </div>
                                                            <div class="flex items-center gap-1 text-xs text-zinc-500 dark:text-zinc-400">
                                                                {{ $payable->description }}
                                                                @if ($payable->hasAttachment())
                                                                    <a href="{{ Storage::disk('public')->url($payable->attachment_path) }}" target="_blank" title="View attachment" class="text-blue-500 hover:text-blue-700 dark:text-blue-400 dark:hover:text-blue-300">
                                                                        <flux:icon name="paper-clip" variant="micro" class="size-3" />
                                                                    </a>
                                                                @endif
                                                                @if ($payable->vendor_invoice_number)
                                                                    <span class="text-zinc-400 dark:text-zinc-500">Inv# {{ $payable->vendor_invoice_number }}</span>
                                                                @endif
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                                <td class="py-2 pr-4">
                                                    <flux:badge size="sm" variant="outline">
                                                        {{ JobPayable::CATEGORIES[$payable->category] ?? $payable->category }}
                                                    </flux:badge>
                                                </td>
                                                <td class="py-2 pr-4 text-right font-medium text-zinc-900 dark:text-white">${{ number_format((float) $payable->amount, 2) }}</td>
                                                <td class="py-2 pr-4">
                                                    <flux:badge size="sm" color="{{ match($payable->status) { 'pending' => 'amber', 'approved' => 'blue', 'paid' => 'green', 'void' => 'zinc', default => 'zinc' } }}">
                                                        {{ JobPayable::STATUSES[$payable->status] ?? $payable->status }}
                                                    </flux:badge>
                                                </td>
                                                <td class="py-2 pr-4">
                                                    @if ($payable->isPaid())
                                                        <div class="text-sm">
                                                            <span class="font-medium text-green-700 dark:text-green-400">{{ JobPayable::PAYMENT_METHODS[$payable->paid_via] ?? $payable->paid_via }}</span>
                                                            @if ($payable->reference_number)
                                                                <span class="block text-xs text-zinc-500 dark:text-zinc-400">#{{ $payable->reference_number }}</span>
                                                            @endif
                                                            @if ($payable->paid_at)
                                                                <span class="block text-xs text-zinc-400 dark:text-zinc-500">{{ $payable->paid_at->format('M j, Y') }}</span>
                                                            @endif
                                                        </div>
                                                    @elseif ($payable->due_date)
                                                        <span class="text-xs text-zinc-500 dark:text-zinc-400">Due {{ $payable->due_date->format('M j, Y') }}</span>
                                                    @else
                                                        <span class="text-zinc-400">--</span>
                                                    @endif
                                                </td>
                                                <td class="py-2">
                                                    <div class="flex items-center gap-1">
                                                        @if (! $payable->isPaid() && ! $payable->isVoid() && $this->canManage)
                                                            <flux:button size="xs" variant="ghost" wire:click="openEditPayableModal({{ $payable->id }})" title="Edit">
                                                                <flux:icon name="pencil" variant="micro" class="size-3" />
                                                            </flux:button>
                                                        @endif
                                                        @if ($payable->isPending() && $this->canApprovePayables)
                                                            <flux:button size="xs" wire:click="approvePayable({{ $payable->id }})" wire:confirm="Approve this payable?">Approve</flux:button>
                                                        @endif
                                                        @if (($payable->isPending() || $payable->isApproved()) && $this->canManage)
                                                            <flux:button size="xs" variant="primary" wire:click="openPaymentModal({{ $payable->id }})">Mark Paid</flux:button>
                                                        @endif
                                                        @if (! $payable->isPaid() && ! $payable->isVoid() && $this->canManage)
                                                            <flux:button size="xs" variant="ghost" wire:click="voidPayable({{ $payable->id }})" wire:confirm="Void this payable? This cannot be undone.">Void</flux:button>
                                                        @endif
                                                    </div>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif
                @endforeach

                {{-- Payables Summary Footer --}}
                <flux:separator class="my-4" />
                <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                    @php
                        $summary = [
                            'Pending' => ['amount' => $this->payables->where('status', 'pending')->sum('amount'), 'color' => 'text-amber-600 dark:text-amber-400'],
                            'Approved' => ['amount' => $this->payables->where('status', 'approved')->sum('amount'), 'color' => 'text-blue-600 dark:text-blue-400'],
                            'Paid' => ['amount' => $this->payables->where('status', 'paid')->sum('amount'), 'color' => 'text-green-600 dark:text-green-400'],
                            'Total' => ['amount' => $this->payables->whereNotIn('status', ['void'])->sum('amount'), 'color' => 'text-zinc-900 dark:text-white'],
                        ];
                    @endphp
                    @foreach ($summary as $label => $data)
                        <div>
                            <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">{{ $label }}</flux:text>
                            <p class="text-sm font-semibold {{ $data['color'] }}">${{ number_format((float) $data['amount'], 2) }}</p>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Section 4.5: Commissions & Bonuses --}}
        <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:heading size="lg" class="mb-4">Commissions & Bonuses</flux:heading>

            @if ($this->dealCommissions->isEmpty())
                <flux:text class="text-zinc-500 dark:text-zinc-400">No commissions recorded for this job.</flux:text>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-zinc-500 dark:text-zinc-400">
                                <th class="pb-2 pr-4 font-medium">Person</th>
                                <th class="pb-2 pr-4 font-medium">Type</th>
                                <th class="pb-2 pr-4 font-medium">Source</th>
                                <th class="pb-2 pr-4 font-medium text-right">Basis</th>
                                <th class="pb-2 pr-4 font-medium text-right">Rate</th>
                                <th class="pb-2 pr-4 font-medium text-right">Amount</th>
                                <th class="pb-2 pr-4 font-medium">Status</th>
                                <th class="pb-2 font-medium">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                            @foreach ($this->dealCommissions as $commission)
                                <tr wire:key="commission-{{ $commission->id }}" class="{{ str_contains($commission->source_label ?? '', 'Crew Bonus') ? 'bg-emerald-50/50 dark:bg-emerald-900/10' : '' }}">
                                    <td class="py-2 pr-4 text-zinc-900 dark:text-white">
                                        {{ $commission->employeeProfile?->user?->name ?? '-' }}
                                        @if (str_contains($commission->source_label ?? '', 'Crew Bonus'))
                                            <flux:badge size="sm" color="emerald" class="ml-1">Crew Bonus</flux:badge>
                                        @endif
                                    </td>
                                    <td class="py-2 pr-4 text-zinc-600 dark:text-zinc-400">{{ str_replace('_', ' ', ucfirst($commission->commission_type)) }}</td>
                                    <td class="py-2 pr-4 text-zinc-600 dark:text-zinc-400">{{ $commission->source_label ?? '-' }}</td>
                                    <td class="py-2 pr-4 text-right text-zinc-600 dark:text-zinc-400">${{ number_format((float) $commission->basis_amount, 2) }}</td>
                                    <td class="py-2 pr-4 text-right text-zinc-600 dark:text-zinc-400">
                                        @if ($commission->rate > 0)
                                            {{ number_format($commission->rate * 100, 2) }}%
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td class="py-2 pr-4 text-right font-medium text-zinc-900 dark:text-white">${{ number_format((float) $commission->amount, 2) }}</td>
                                    <td class="py-2 pr-4">
                                        <flux:badge size="sm" color="{{ match($commission->status) { 'pending' => 'amber', 'approved' => 'blue', 'paid' => 'green', 'reversed' => 'red', default => 'zinc' } }}">
                                            {{ ucfirst($commission->status) }}
                                        </flux:badge>
                                    </td>
                                    <td class="py-2">
                                        <div class="flex items-center gap-1">
                                            @if ($commission->isPending() && $this->canApprovePayables)
                                                <flux:button size="xs" wire:click="approveCommission({{ $commission->id }})" wire:confirm="Approve this commission?">Approve</flux:button>
                                            @endif
                                            @if (($commission->isPending() || $commission->isApproved()) && $this->canManage)
                                                <flux:button size="xs" variant="ghost" wire:click="reverseCommission({{ $commission->id }})" wire:confirm="Reverse this commission?">Reverse</flux:button>
                                            @endif
                                        </div>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- Section 5: Allocations --}}
        <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
            <div class="mb-4 flex items-center justify-between">
                <flux:heading size="lg">Allocations</flux:heading>
                @if ($this->canManage && $this->allocations->isNotEmpty())
                    <div class="flex items-center gap-2">
                        @if ($editingAllocations)
                            <flux:button size="sm" variant="primary" wire:click="saveAllocations">Save</flux:button>
                            <flux:button size="sm" variant="ghost" wire:click="resetAllocations" wire:confirm="Reset all allocation overrides to projected values?">Reset</flux:button>
                            <flux:button size="sm" variant="ghost" wire:click="cancelEditingAllocations">Cancel</flux:button>
                        @else
                            <flux:button size="sm" wire:click="startEditingAllocations">Edit Allocations</flux:button>
                        @endif
                    </div>
                @endif
            </div>

            @if ($this->allocations->isEmpty())
                <flux:text class="text-zinc-500 dark:text-zinc-400">No allocations found for this cost card.</flux:text>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-zinc-500 dark:text-zinc-400">
                                <th class="pb-2 pr-4 font-medium">Allocation</th>
                                <th class="pb-2 pr-4 font-medium">Type</th>
                                <th class="pb-2 pr-4 font-medium text-right">Rate</th>
                                <th class="pb-2 pr-4 font-medium text-right">Projected</th>
                                <th class="pb-2 pr-4 font-medium text-right">Actual</th>
                                <th class="pb-2 font-medium text-right">Variance</th>
                                @if ($editingAllocations || $this->allocations->whereNotNull('account_id')->isNotEmpty())
                                    <th class="pb-2 font-medium">GL Account</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                            @foreach ($this->allocations as $allocation)
                                <tr wire:key="alloc-{{ $allocation->id }}">
                                    <td class="py-2 pr-4 text-zinc-900 dark:text-white">{{ $allocation->name }}</td>
                                    <td class="py-2 pr-4 text-zinc-600 dark:text-zinc-400">{{ str_replace('_', ' ', $allocation->type) }}</td>
                                    <td class="py-2 pr-4 text-right text-zinc-600 dark:text-zinc-400">{{ number_format((float) $allocation->value, 2) }}{{ str_contains($allocation->type, 'percent') ? '%' : '' }}</td>
                                    <td class="py-2 pr-4 text-right text-zinc-900 dark:text-white">${{ number_format((float) $allocation->amount, 2) }}</td>
                                    <td class="py-2 pr-4 text-right">
                                        @if ($editingAllocations)
                                            <div class="flex items-center justify-end gap-1">
                                                <span class="text-zinc-500">$</span>
                                                <input
                                                    type="number"
                                                    step="0.01"
                                                    wire:model="allocationOverrides.{{ $allocation->id }}"
                                                    class="w-24 rounded border border-zinc-300 px-2 py-1 text-right text-sm dark:border-zinc-600 dark:bg-zinc-700 dark:text-white"
                                                />
                                            </div>
                                        @elseif ($allocation->actual_amount !== null)
                                            <span class="text-zinc-900 dark:text-white">${{ number_format((float) $allocation->actual_amount, 2) }}</span>
                                        @else
                                            <span class="text-zinc-400">--</span>
                                        @endif
                                    </td>
                                    <td class="py-2 text-right">
                                        @if ($allocation->variance !== null)
                                            <span class="{{ $allocation->variance >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                                {{ $allocation->variance >= 0 ? '+' : '' }}${{ number_format($allocation->variance, 2) }}
                                            </span>
                                        @else
                                            <span class="text-zinc-400">--</span>
                                        @endif
                                    </td>
                                    @if ($editingAllocations)
                                        <td class="py-2">
                                            <select
                                                wire:model="allocationAccounts.{{ $allocation->id }}"
                                                class="w-48 rounded border border-zinc-300 px-2 py-1 text-sm dark:border-zinc-600 dark:bg-zinc-700 dark:text-white"
                                            >
                                                <option value="">None</option>
                                                @foreach ($this->allExpenseAccounts as $account)
                                                    <option value="{{ $account->id }}">{{ $account->code }} - {{ $account->name }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                    @elseif ($this->allocations->whereNotNull('account_id')->isNotEmpty())
                                        <td class="py-2 text-sm text-zinc-600 dark:text-zinc-400">
                                            @if ($allocation->account)
                                                {{ $allocation->account->code }} - {{ $allocation->account->name }}
                                            @else
                                                <span class="text-zinc-400">--</span>
                                            @endif
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr class="border-t-2 border-zinc-300 font-semibold dark:border-zinc-600">
                                <td class="pt-2 pr-4 text-zinc-900 dark:text-white" colspan="3">Total Allocations</td>
                                <td class="pt-2 pr-4 text-right text-zinc-900 dark:text-white">${{ number_format((float) $this->costCard->allocation_total, 2) }}</td>
                                <td class="pt-2 pr-4 text-right text-zinc-900 dark:text-white">
                                    @if ($this->costCard->actual_allocation_total !== null)
                                        ${{ number_format((float) $this->costCard->actual_allocation_total, 2) }}
                                    @else
                                        <span class="text-zinc-400">--</span>
                                    @endif
                                </td>
                                <td class="pt-2 text-right">
                                    @php
                                        $allocVariance = $this->costCard->actual_allocation_total !== null
                                            ? round((float) $this->costCard->allocation_total - (float) $this->costCard->actual_allocation_total, 2)
                                            : null;
                                    @endphp
                                    @if ($allocVariance !== null)
                                        <span class="{{ $allocVariance >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                            {{ $allocVariance >= 0 ? '+' : '' }}${{ number_format($allocVariance, 2) }}
                                        </span>
                                    @else
                                        <span class="text-zinc-400">--</span>
                                    @endif
                                </td>
                                @if ($editingAllocations || $this->allocations->whereNotNull('account_id')->isNotEmpty())
                                    <td class="pt-2"></td>
                                @endif
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        </div>

        {{-- Section 6: Profit Summary --}}
        @if (! empty($this->profitSummary))
            <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:heading size="lg" class="mb-4">Profit Summary</flux:heading>

                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="text-left text-zinc-500 dark:text-zinc-400">
                                <th class="pb-2 pr-4 font-medium"></th>
                                <th class="pb-2 pr-4 font-medium text-right">Projected</th>
                                <th class="pb-2 font-medium text-right">Actual</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-700">
                            <tr>
                                <td class="py-2 pr-4 text-zinc-900 dark:text-white">Revenue</td>
                                <td class="py-2 pr-4 text-right text-zinc-900 dark:text-white">${{ number_format($this->profitSummary['revenue'], 2) }}</td>
                                <td class="py-2 text-right text-zinc-900 dark:text-white">${{ number_format($this->profitSummary['revenue'], 2) }}</td>
                            </tr>
                            <tr>
                                <td class="py-2 pr-4 text-zinc-900 dark:text-white">Total COGS</td>
                                <td class="py-2 pr-4 text-right text-red-600 dark:text-red-400">(${{ number_format($this->profitSummary['projected_cogs'], 2) }})</td>
                                <td class="py-2 text-right">
                                    @if ($this->profitSummary['actual_cogs'] !== null)
                                        <span class="text-red-600 dark:text-red-400">(${{ number_format($this->profitSummary['actual_cogs'], 2) }})</span>
                                    @else
                                        <span class="text-zinc-400">--</span>
                                    @endif
                                </td>
                            </tr>
                            <tr class="font-semibold">
                                <td class="py-2 pr-4 text-zinc-900 dark:text-white">Gross Profit</td>
                                <td class="py-2 pr-4 text-right text-zinc-900 dark:text-white">${{ number_format($this->profitSummary['gross_profit'], 2) }}</td>
                                <td class="py-2 text-right">
                                    @if ($this->profitSummary['actual_gross_profit'] !== null)
                                        <span class="{{ $this->profitSummary['actual_gross_profit'] >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">${{ number_format($this->profitSummary['actual_gross_profit'], 2) }}</span>
                                    @else
                                        <span class="text-zinc-400">--</span>
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <td class="py-2 pr-4 text-zinc-900 dark:text-white">Allocations</td>
                                <td class="py-2 pr-4 text-right text-red-600 dark:text-red-400">(${{ number_format($this->profitSummary['allocation_total'], 2) }})</td>
                                <td class="py-2 text-right">
                                    @if ($this->profitSummary['actual_allocation_total'] !== null)
                                        <span class="text-red-600 dark:text-red-400">(${{ number_format($this->profitSummary['actual_allocation_total'], 2) }})</span>
                                    @else
                                        <span class="text-zinc-400">--</span>
                                    @endif
                                </td>
                            </tr>
                            <tr class="border-t-2 border-zinc-300 font-bold dark:border-zinc-600">
                                <td class="pt-2 pr-4 text-zinc-900 dark:text-white">Net Profit</td>
                                <td class="pt-2 pr-4 text-right {{ $this->profitSummary['net_profit'] >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                    ${{ number_format($this->profitSummary['net_profit'], 2) }}
                                </td>
                                <td class="pt-2 text-right">
                                    @if ($this->profitSummary['actual_net_profit'] !== null)
                                        <span class="{{ $this->profitSummary['actual_net_profit'] >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400' }}">
                                            ${{ number_format($this->profitSummary['actual_net_profit'], 2) }}
                                        </span>
                                    @else
                                        <span class="text-zinc-400">--</span>
                                    @endif
                                </td>
                            </tr>
                            <tr>
                                <td class="py-2 pr-4 text-zinc-900 dark:text-white">Margin %</td>
                                <td class="py-2 pr-4 text-right text-zinc-900 dark:text-white">{{ $this->profitSummary['margin'] }}%</td>
                                <td class="py-2 text-right">
                                    @if ($this->profitSummary['actual_margin'] !== null)
                                        <span class="text-zinc-900 dark:text-white">{{ $this->profitSummary['actual_margin'] }}%</span>
                                    @else
                                        <span class="text-zinc-400">--</span>
                                    @endif
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- Section 7: Bank Distribution --}}
        @if (! empty($this->bankDistribution))
            <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                <flux:heading size="lg" class="mb-4">Bank Distribution</flux:heading>

                <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-6">
                    @php
                        $bucketLabels = [
                            'cogs_account' => 'COGS Account',
                            'tax_account' => 'Tax Account',
                            'marketing_account' => 'Marketing',
                            'sales_account' => 'Sales',
                            'operations_account' => 'Operations',
                            'profit_account' => 'Profit',
                        ];
                    @endphp
                    @foreach ($this->bankDistribution as $key => $amount)
                        <div class="rounded-lg border border-zinc-100 bg-zinc-50 p-4 text-center dark:border-zinc-700 dark:bg-zinc-900" wire:key="bank-{{ $key }}">
                            <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">{{ $bucketLabels[$key] ?? $key }}</flux:text>
                            <p class="mt-1 text-lg font-semibold {{ $amount >= 0 ? 'text-zinc-900 dark:text-white' : 'text-red-600 dark:text-red-400' }}">
                                ${{ number_format($amount, 2) }}
                            </p>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- Section 8: Accounting Actions --}}
        <div class="rounded-lg border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
            <flux:heading size="lg" class="mb-4">Accounting Actions</flux:heading>

            <div class="flex flex-wrap gap-3">
                @if ($this->canCreateJournalEntries && $this->costCard->has_actuals)
                    @if ($this->costCard->journal_entry_id)
                        <flux:badge color="green" size="sm">Journal Entry Created</flux:badge>
                        <a href="{{ route('business-plan.index', ['tab' => 'journal-entries']) }}" class="text-sm text-blue-600 hover:underline dark:text-blue-400">View Journal Entries</a>
                    @else
                        <flux:button wire:click="createJournalEntry" wire:confirm="Create a journal entry from actual costs?">
                            Create Journal Entry from Actuals
                        </flux:button>
                    @endif
                @elseif (! $this->costCard->has_actuals)
                    <flux:text class="text-zinc-500 dark:text-zinc-400">Enter actual costs to enable journal entry creation.</flux:text>
                @endif

                <a href="{{ route('business-plan.index', ['tab' => 'journal-entries']) }}" class="inline-flex items-center text-sm text-blue-600 hover:underline dark:text-blue-400">
                    View Journal Entries
                </a>
            </div>

            @if ($this->payables->whereNotNull('debit_account_id')->isNotEmpty())
                <div class="mt-4">
                    <flux:text class="text-sm text-zinc-500 dark:text-zinc-400">Linked GL Accounts:</flux:text>
                    <div class="mt-1 flex flex-wrap gap-2">
                        @foreach ($this->payables->whereNotNull('debit_account_id')->unique('debit_account_id') as $payable)
                            @if ($payable->debitAccount)
                                <flux:badge size="sm" variant="outline">
                                    {{ $payable->debitAccount->code }} - {{ $payable->debitAccount->name }}
                                </flux:badge>
                            @endif
                        @endforeach
                        @foreach ($this->payables->whereNotNull('credit_account_id')->unique('credit_account_id') as $payable)
                            @if ($payable->creditAccount)
                                <flux:badge size="sm" variant="outline">
                                    {{ $payable->creditAccount->code }} - {{ $payable->creditAccount->name }}
                                </flux:badge>
                            @endif
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>
    @endif

    {{-- Payment Modal --}}
    <flux:modal wire:model="showPaymentModal" class="max-w-md">
        <div class="space-y-4">
            <flux:heading size="lg">Mark Payable as Paid</flux:heading>

            <flux:field>
                <flux:label>Payment Method</flux:label>
                <flux:select wire:model="paymentMethod">
                    @foreach (JobPayable::PAYMENT_METHODS as $key => $label)
                        <flux:select.option value="{{ $key }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            <flux:field>
                <flux:label>Reference Number</flux:label>
                <flux:input wire:model="paymentReference" placeholder="Check #, ACH ref, etc." />
            </flux:field>

            <flux:field>
                <flux:label>Pay From Account</flux:label>
                <flux:select wire:model="paymentFromAccountId">
                    <flux:select.option value="">Select bank account...</flux:select.option>
                    @foreach ($this->bankAccounts as $account)
                        <flux:select.option value="{{ $account->id }}">{{ $account->code }} - {{ $account->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('showPaymentModal', false)">Cancel</flux:button>
                <flux:button variant="primary" wire:click="markPayableAsPaid">Confirm Payment</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Add Payable Modal --}}
    <flux:modal wire:model="showAddPayableModal" class="max-w-md">
        <div class="space-y-4">
            <flux:heading size="lg">Add Payable</flux:heading>

            <flux:field>
                <flux:label>Payee Type</flux:label>
                <flux:select wire:model.live="newPayableType">
                    @foreach (JobPayable::PAYEE_TYPES as $key => $label)
                        <flux:select.option value="{{ $key }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            @if ($newPayableType === 'vendor')
                <flux:field>
                    <flux:label>Vendor</flux:label>
                    <flux:select wire:model.live="newPayableVendorId">
                        <flux:select.option value="">Select vendor...</flux:select.option>
                        @foreach ($this->vendors as $vendor)
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
                            <flux:input wire:model="quickVendorName" :label="__('Vendor Name')" required size="sm" />
                            <flux:select wire:model="quickVendorCategory" :label="__('Category')" size="sm">
                                @foreach(Vendor::CATEGORIES as $val => $label)
                                    <flux:select.option value="{{ $val }}">{{ $label }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:button size="sm" variant="primary" wire:click="quickAddVendor" icon="plus">{{ __('Create Vendor') }}</flux:button>
                        </div>
                    @else
                        <flux:button size="sm" variant="ghost" icon="plus" wire:click="$set('showQuickAddVendor', true)">{{ __('New Vendor') }}</flux:button>
                    @endif
                </div>

                <flux:field>
                    <flux:label>Invoice Number</flux:label>
                    <flux:input wire:model="newPayableInvoiceNumber" placeholder="Vendor invoice #" />
                </flux:field>

                <flux:field>
                    <flux:label>Attachment</flux:label>
                    <input type="file" wire:model="newPayableAttachment" class="block w-full text-sm text-zinc-500 file:mr-4 file:rounded-md file:border-0 file:bg-zinc-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-zinc-700 hover:file:bg-zinc-200 dark:text-zinc-400 dark:file:bg-zinc-700 dark:file:text-zinc-300" />
                    @error('newPayableAttachment') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                </flux:field>
            @elseif ($newPayableType === 'employee')
                <flux:field>
                    <flux:label>Employee</flux:label>
                    <flux:select wire:model="newPayableUserId">
                        <flux:select.option value="">Select employee...</flux:select.option>
                        @foreach ($this->internalUsers as $user)
                            <flux:select.option value="{{ $user->id }}">{{ $user->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </flux:field>
            @elseif (in_array($newPayableType, ['crew', 'contractor']))
                <flux:field>
                    <flux:label>{{ $newPayableType === 'crew' ? 'Crew Member' : 'Contractor' }}</flux:label>
                    <flux:select wire:model="newPayableUserId">
                        <flux:select.option value="">Select {{ $newPayableType }}...</flux:select.option>
                        @foreach ($this->crewUsers as $user)
                            <flux:select.option value="{{ $user->id }}">{{ $user->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </flux:field>
            @endif

            <flux:field>
                <flux:label>Category</flux:label>
                <flux:select wire:model="newPayableCategory">
                    @foreach (JobPayable::CATEGORIES as $key => $label)
                        <flux:select.option value="{{ $key }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            <flux:field>
                <flux:label>Debit Account (GL)</flux:label>
                <flux:select wire:model="newPayableDebitAccountId">
                    <flux:select.option value="">Default (auto)</flux:select.option>
                    @foreach ($this->expenseAccounts as $account)
                        <flux:select.option value="{{ $account->id }}">{{ $account->code }} - {{ $account->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            <flux:field>
                <flux:label>Credit Account (GL)</flux:label>
                <flux:select wire:model="newPayableCreditAccountId">
                    <flux:select.option value="">Default (Accounts Payable)</flux:select.option>
                    @foreach ($this->liabilityAccounts as $account)
                        <flux:select.option value="{{ $account->id }}">{{ $account->code }} - {{ $account->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            <flux:field>
                <flux:label>Description</flux:label>
                <flux:input wire:model="newPayableDescription" placeholder="Description of the payable" />
            </flux:field>

            <flux:field>
                <flux:label>Amount</flux:label>
                <flux:input type="number" step="0.01" min="0" wire:model="newPayableAmount" placeholder="0.00" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('showAddPayableModal', false)">Cancel</flux:button>
                <flux:button variant="primary" wire:click="createPayable">Create Payable</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Edit Payable Modal --}}
    <flux:modal wire:model="showEditPayableModal" class="max-w-md">
        <div class="space-y-4">
            <flux:heading size="lg">Edit Payable</flux:heading>

            <flux:field>
                <flux:label>Category</flux:label>
                <flux:select wire:model="editPayableCategory">
                    @foreach (JobPayable::CATEGORIES as $key => $label)
                        <flux:select.option value="{{ $key }}">{{ $label }}</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            <flux:field>
                <flux:label>Debit Account (GL)</flux:label>
                <flux:select wire:model="editPayableDebitAccountId">
                    <flux:select.option value="">Default (auto)</flux:select.option>
                    @foreach ($this->expenseAccounts as $account)
                        <flux:select.option value="{{ $account->id }}">{{ $account->code }} - {{ $account->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            <flux:field>
                <flux:label>Credit Account (GL)</flux:label>
                <flux:select wire:model="editPayableCreditAccountId">
                    <flux:select.option value="">Default (Accounts Payable)</flux:select.option>
                    @foreach ($this->liabilityAccounts as $account)
                        <flux:select.option value="{{ $account->id }}">{{ $account->code }} - {{ $account->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </flux:field>

            <flux:field>
                <flux:label>Description</flux:label>
                <flux:input wire:model="editPayableDescription" />
            </flux:field>

            <flux:field>
                <flux:label>Amount</flux:label>
                <flux:input type="number" step="0.01" min="0" wire:model="editPayableAmount" />
            </flux:field>

            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="$set('showEditPayableModal', false)">Cancel</flux:button>
                <flux:button variant="primary" wire:click="updatePayable">Save Changes</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
