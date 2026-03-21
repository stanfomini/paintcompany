<?php

use App\Models\Account;
use App\Models\BudgetCategory;
use App\Models\CompanySetting;
use App\Models\ContractTemplate;
use App\Models\Vendor;
use App\Models\VendorContract;
use App\Services\VendorContractService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new class extends Component {
    use WithFileUploads, WithPagination;

    public function boot(): void
    {
        if (! auth()->user()?->can('vendors.view')) {
            abort(403);
        }
    }

    // Filters
    public string $search = '';
    public string $categoryFilter = '';
    public string $statusFilter = '';

    // Add/Edit form state
    public bool $showModal = false;
    public ?int $editingId = null;
    public string $vendorName = '';
    public string $contactName = '';
    public string $vendorEmail = '';
    public string $vendorPhone = '';
    public string $addressLine1 = '';
    public string $addressLine2 = '';
    public string $vendorCity = '';
    public string $vendorState = '';
    public string $vendorZip = '';
    public string $vendorCategory = 'supplier';
    public ?int $budgetCategoryId = null;
    public ?int $defaultExpenseAccountId = null;
    public ?int $paymentTermsDays = null;
    public bool $vendorActive = true;
    public string $vendorNotes = '';

    // Booking configuration
    public string $vendorBookingSlug = '';
    public bool $vendorBookingEnabled = false;
    public string $vendorBookingDisplayName = '';
    public string $vendorBookingBio = '';

    // Tax compliance
    public bool $w9OnFile = false;
    public bool $holdHarmless = false;
    public bool $certificateOfInsurance = false;
    public ?string $insuranceExpiration = null;
    public bool $additionalInsured = false;
    public bool $workersComp = false;
    public ?string $workersCompExpiration = null;
    public bool $rejectionForm = false;

    // Document viewer
    public bool $showDocumentsModal = false;
    public ?int $viewingVendorId = null;

    // Internal upload
    public $internalUploadFile = null;
    public string $internalUploadType = 'other';
    public string $internalUploadName = '';
    public ?string $internalUploadExpires = null;

    // Contracts flyout
    public bool $showContractsModal = false;
    public ?int $contractVendorId = null;
    public ?int $selectedTemplateId = null;

    // Delete confirmation
    public bool $showDeleteModal = false;
    public ?int $deletingId = null;
    public string $deletingName = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCategoryFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function vendors(): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        return Vendor::query()
            ->when($this->search, function ($q) {
                $q->where(function ($q2) {
                    $q2->where('name', 'like', '%' . $this->search . '%')
                        ->orWhere('contact_name', 'like', '%' . $this->search . '%')
                        ->orWhere('email', 'like', '%' . $this->search . '%');
                });
            })
            ->when($this->categoryFilter, fn ($q) => $q->where('category', $this->categoryFilter))
            ->when($this->statusFilter !== '', function ($q) {
                if ($this->statusFilter === 'active') {
                    $q->where('is_active', true);
                } elseif ($this->statusFilter === 'inactive') {
                    $q->where('is_active', false);
                }
            })
            ->with('budgetCategory')
            ->withCount('documents')
            ->orderBy('name')
            ->paginate(20);
    }

    #[Computed]
    public function budgetCategories(): \Illuminate\Database\Eloquent\Collection
    {
        return BudgetCategory::orderBy('name')->get();
    }

    #[Computed]
    public function expenseAccounts(): \Illuminate\Database\Eloquent\Collection
    {
        return Account::whereIn('type', ['expense', 'cost_of_goods'])
            ->where('is_active', true)
            ->orderBy('code')
            ->get();
    }

    #[Computed]
    public function complianceSummary(): array
    {
        $allVendors = Vendor::query();

        return [
            'total' => (clone $allVendors)->count(),
            'missing_w9' => (clone $allVendors)->where('w9_on_file', false)->count(),
            'expired_insurance' => Vendor::query()
                ->where('certificate_of_insurance', true)
                ->whereNotNull('insurance_expiration')
                ->where('insurance_expiration', '<', now())
                ->count(),
            'expired_workers_comp' => Vendor::query()
                ->where('workers_comp', true)
                ->whereNotNull('workers_comp_expiration')
                ->where('workers_comp_expiration', '<', now())
                ->count(),
            'expiring_soon' => Vendor::expiringSoon(30)
                ->where(function ($q) {
                    $q->where(function ($q2) {
                        $q2->where('certificate_of_insurance', true)
                            ->whereNotNull('insurance_expiration')
                            ->where('insurance_expiration', '>=', now());
                    })->orWhere(function ($q2) {
                        $q2->where('workers_comp', true)
                            ->whereNotNull('workers_comp_expiration')
                            ->where('workers_comp_expiration', '>=', now());
                    });
                })
                ->count(),
        ];
    }

    public function openAddModal(): void
    {
        $this->resetForm();
        $this->showModal = true;
    }

    public function openEditModal(int $id): void
    {
        $vendor = Vendor::findOrFail($id);

        $this->editingId = $vendor->id;
        $this->vendorName = $vendor->name;
        $this->contactName = $vendor->contact_name ?? '';
        $this->vendorEmail = $vendor->email ?? '';
        $this->vendorPhone = $vendor->phone ?? '';
        $this->addressLine1 = $vendor->address_line_1 ?? '';
        $this->addressLine2 = $vendor->address_line_2 ?? '';
        $this->vendorCity = $vendor->city ?? '';
        $this->vendorState = $vendor->state ?? '';
        $this->vendorZip = $vendor->zip ?? '';
        $this->vendorCategory = $vendor->category;
        $this->budgetCategoryId = $vendor->budget_category_id;
        $this->defaultExpenseAccountId = $vendor->default_expense_account_id;
        $this->paymentTermsDays = $vendor->payment_terms_days;
        $this->vendorActive = $vendor->is_active;
        $this->vendorNotes = $vendor->notes ?? '';
        $this->w9OnFile = $vendor->w9_on_file;
        $this->holdHarmless = $vendor->hold_harmless;
        $this->certificateOfInsurance = $vendor->certificate_of_insurance;
        $this->insuranceExpiration = $vendor->insurance_expiration?->format('Y-m-d');
        $this->additionalInsured = $vendor->additional_insured;
        $this->workersComp = $vendor->workers_comp;
        $this->workersCompExpiration = $vendor->workers_comp_expiration?->format('Y-m-d');
        $this->rejectionForm = $vendor->rejection_form;
        $this->vendorBookingSlug = $vendor->booking_slug ?? '';
        $this->vendorBookingEnabled = (bool) $vendor->booking_enabled;
        $this->vendorBookingDisplayName = $vendor->booking_display_name ?? '';
        $this->vendorBookingBio = $vendor->booking_bio ?? '';
        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
        $this->resetForm();
    }

    public function save(): void
    {
        if (! auth()->user()?->can('vendors.manage')) {
            abort(403);
        }

        $this->validate([
            'vendorName' => ['required', 'string', 'max:255'],
            'contactName' => ['nullable', 'string', 'max:255'],
            'vendorEmail' => ['nullable', 'email', 'max:255'],
            'vendorPhone' => ['nullable', 'string', 'max:50'],
            'addressLine1' => ['nullable', 'string', 'max:255'],
            'addressLine2' => ['nullable', 'string', 'max:255'],
            'vendorCity' => ['nullable', 'string', 'max:100'],
            'vendorState' => ['nullable', 'string', 'max:50'],
            'vendorZip' => ['nullable', 'string', 'max:20'],
            'vendorCategory' => ['required', 'string', 'in:supplier,subcontractor,service,other'],
            'budgetCategoryId' => ['nullable', 'exists:budget_categories,id'],
            'defaultExpenseAccountId' => ['nullable', 'exists:accounts,id'],
            'paymentTermsDays' => ['nullable', 'integer', 'min:0', 'max:365'],
            'vendorActive' => ['boolean'],
            'vendorNotes' => ['nullable', 'string'],
            'w9OnFile' => ['boolean'],
            'holdHarmless' => ['boolean'],
            'certificateOfInsurance' => ['boolean'],
            'insuranceExpiration' => ['nullable', 'date'],
            'additionalInsured' => ['boolean'],
            'workersComp' => ['boolean'],
            'workersCompExpiration' => ['nullable', 'date'],
            'rejectionForm' => ['boolean'],
            'vendorBookingSlug' => ['nullable', 'string', 'max:255', 'regex:/^[a-z0-9\-]*$/'],
            'vendorBookingEnabled' => ['boolean'],
            'vendorBookingDisplayName' => ['nullable', 'string', 'max:255'],
            'vendorBookingBio' => ['nullable', 'string', 'max:1000'],
        ]);

        $data = [
            'company_setting_id' => CompanySetting::getInstance()->id,
            'name' => $this->vendorName,
            'contact_name' => $this->contactName ?: null,
            'email' => $this->vendorEmail ?: null,
            'phone' => $this->vendorPhone ?: null,
            'address_line_1' => $this->addressLine1 ?: null,
            'address_line_2' => $this->addressLine2 ?: null,
            'city' => $this->vendorCity ?: null,
            'state' => $this->vendorState ?: null,
            'zip' => $this->vendorZip ?: null,
            'category' => $this->vendorCategory,
            'budget_category_id' => $this->budgetCategoryId,
            'default_expense_account_id' => $this->defaultExpenseAccountId,
            'payment_terms_days' => $this->paymentTermsDays,
            'is_active' => $this->vendorActive,
            'notes' => $this->vendorNotes ?: null,
            'w9_on_file' => $this->w9OnFile,
            'hold_harmless' => $this->holdHarmless,
            'certificate_of_insurance' => $this->certificateOfInsurance,
            'insurance_expiration' => $this->certificateOfInsurance ? $this->insuranceExpiration : null,
            'additional_insured' => $this->additionalInsured,
            'workers_comp' => $this->workersComp,
            'workers_comp_expiration' => $this->workersComp ? $this->workersCompExpiration : null,
            'rejection_form' => ! $this->workersComp ? $this->rejectionForm : false,
            'booking_slug' => $this->vendorBookingSlug ?: null,
            'booking_enabled' => $this->vendorBookingEnabled,
            'booking_display_name' => $this->vendorBookingDisplayName ?: null,
            'booking_bio' => $this->vendorBookingBio ?: null,
        ];

        if ($this->editingId) {
            $vendor = Vendor::findOrFail($this->editingId);
            $vendor->update($data);
        } else {
            Vendor::create($data);
        }

        $this->closeModal();
        unset($this->vendors, $this->complianceSummary);
        $this->dispatch('vendors-updated');
    }

    public function toggleActive(int $id): void
    {
        if (! auth()->user()?->can('vendors.manage')) {
            abort(403);
        }

        $vendor = Vendor::findOrFail($id);
        $vendor->update(['is_active' => ! $vendor->is_active]);
        unset($this->vendors, $this->complianceSummary);
    }

    public function confirmDelete(int $id): void
    {
        $vendor = Vendor::findOrFail($id);

        if ($vendor->hasPayables()) {
            session()->flash('error', __('Cannot delete ":name" because it has associated payables.', ['name' => $vendor->name]));

            return;
        }

        $this->deletingId = $id;
        $this->deletingName = $vendor->name;
        $this->showDeleteModal = true;
    }

    public function delete(): void
    {
        if (! auth()->user()?->can('vendors.manage')) {
            abort(403);
        }

        if (! $this->deletingId) {
            return;
        }

        $vendor = Vendor::findOrFail($this->deletingId);

        if ($vendor->hasPayables()) {
            session()->flash('error', __('Cannot delete ":name" because it has associated payables.', ['name' => $vendor->name]));
            $this->cancelDelete();

            return;
        }

        $vendor->delete();
        $this->cancelDelete();
        unset($this->vendors, $this->complianceSummary);
        $this->dispatch('vendors-updated');
    }

    public function cancelDelete(): void
    {
        $this->showDeleteModal = false;
        $this->deletingId = null;
        $this->deletingName = '';
    }

    public function sendOnboardingLink(int $id): void
    {
        if (! auth()->user()?->can('vendors.manage')) {
            abort(403);
        }

        $vendor = Vendor::findOrFail($id);

        if (! $vendor->email) {
            session()->flash('error', __('Vendor must have an email address to send onboarding link.'));

            return;
        }

        $vendor->generatePublicToken(30);

        \Illuminate\Support\Facades\Notification::route('mail', $vendor->email)
            ->notify(new \App\Notifications\VendorOnboardingInvite($vendor));

        session()->flash('status', __('Onboarding link sent to :email', ['email' => $vendor->email]));
    }

    public function viewDocuments(int $id): void
    {
        $this->viewingVendorId = $id;
        $this->showDocumentsModal = true;
        $this->internalUploadFile = null;
        $this->internalUploadType = 'other';
        $this->internalUploadName = '';
        $this->internalUploadExpires = null;
    }

    public function closeDocumentsModal(): void
    {
        $this->showDocumentsModal = false;
        $this->viewingVendorId = null;
    }

    #[Computed]
    public function viewingVendor(): ?Vendor
    {
        if (! $this->viewingVendorId) {
            return null;
        }

        return Vendor::with('documents')->find($this->viewingVendorId);
    }

    #[Computed]
    public function contractVendor(): ?Vendor
    {
        return $this->contractVendorId ? Vendor::find($this->contractVendorId) : null;
    }

    #[Computed]
    public function vendorContracts(): \Illuminate\Support\Collection
    {
        if (! $this->contractVendorId) {
            return collect();
        }

        return VendorContract::where('vendor_id', $this->contractVendorId)
            ->orderByDesc('created_at')
            ->get();
    }

    #[Computed]
    public function vendorTemplates(): \Illuminate\Support\Collection
    {
        return ContractTemplate::where('type', 'vendor')->where('is_active', true)->get();
    }

    public function viewContracts(int $vendorId): void
    {
        $this->contractVendorId = $vendorId;
        $this->showContractsModal = true;
    }

    public function closeContractsModal(): void
    {
        $this->showContractsModal = false;
        $this->contractVendorId = null;
        $this->selectedTemplateId = null;
    }

    public function createContract(): void
    {
        $vendor = Vendor::findOrFail($this->contractVendorId);
        $service = app(VendorContractService::class);

        if ($this->selectedTemplateId) {
            $template = ContractTemplate::findOrFail($this->selectedTemplateId);
            $contract = $service->createFromTemplate($vendor, $template, [auth()->id()], auth()->user());
        } else {
            $contract = $service->createBlank($vendor, auth()->user());
        }

        $this->redirect(route('vendor-contracts.edit', ['vendor' => $vendor->id, 'contract' => $contract->id]));
    }

    public function deleteContract(int $contractId): void
    {
        $contract = VendorContract::findOrFail($contractId);

        if ($contract->isDraft()) {
            $contract->delete();
        }
    }

    public function invalidateContract(int $contractId): void
    {
        $contract = VendorContract::findOrFail($contractId);
        app(VendorContractService::class)->invalidate($contract);
    }

    public function uploadInternalDocument(): void
    {
        if (! auth()->user()?->can('vendors.manage')) {
            abort(403);
        }

        $this->validate([
            'internalUploadFile' => ['required', 'file', 'max:10240'],
            'internalUploadType' => ['required', 'in:' . implode(',', array_keys(\App\Models\VendorDocument::TYPES))],
            'internalUploadName' => ['required', 'string', 'max:255'],
            'internalUploadExpires' => ['nullable', 'date'],
        ]);

        $path = $this->internalUploadFile->store('vendor-documents', config('filesystems.default'));

        \App\Models\VendorDocument::create([
            'vendor_id' => $this->viewingVendorId,
            'uploaded_by' => auth()->id(),
            'name' => $this->internalUploadName,
            'type' => $this->internalUploadType,
            'file_path' => $path,
            'original_filename' => $this->internalUploadFile->getClientOriginalName(),
            'status' => \App\Models\VendorDocument::STATUS_APPROVED,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
            'expires_at' => $this->internalUploadExpires,
        ]);

        $this->internalUploadFile = null;
        $this->internalUploadType = 'other';
        $this->internalUploadName = '';
        $this->internalUploadExpires = null;
        unset($this->viewingVendor);
    }

    public function approveDocument(int $docId): void
    {
        if (! auth()->user()?->can('vendors.manage')) {
            abort(403);
        }

        $doc = \App\Models\VendorDocument::findOrFail($docId);
        $doc->approve(auth()->user());

        // Auto-update vendor compliance flags based on document type
        $vendor = $doc->vendor;
        match ($doc->type) {
            \App\Models\VendorDocument::TYPE_W9 => $vendor->update(['w9_on_file' => true]),
            \App\Models\VendorDocument::TYPE_INSURANCE => $vendor->update([
                'certificate_of_insurance' => true,
                'insurance_expiration' => $doc->expires_at,
            ]),
            \App\Models\VendorDocument::TYPE_WORKERS_COMP => $vendor->update([
                'workers_comp' => true,
                'workers_comp_expiration' => $doc->expires_at,
            ]),
            \App\Models\VendorDocument::TYPE_HOLD_HARMLESS => $vendor->update(['hold_harmless' => true]),
            default => null,
        };

        unset($this->viewingVendor, $this->vendors, $this->complianceSummary);
    }

    public function rejectDocument(int $docId): void
    {
        if (! auth()->user()?->can('vendors.manage')) {
            abort(403);
        }

        $doc = \App\Models\VendorDocument::findOrFail($docId);
        $doc->reject(auth()->user());

        unset($this->viewingVendor);
    }

    public function deleteDocument(int $docId): void
    {
        if (! auth()->user()?->can('vendors.manage')) {
            abort(403);
        }

        $doc = \App\Models\VendorDocument::findOrFail($docId);
        \Illuminate\Support\Facades\Storage::delete($doc->file_path);
        $doc->delete();

        unset($this->viewingVendor);
    }

    public function generateBookingSlug(): void
    {
        $this->vendorBookingSlug = \Illuminate\Support\Str::slug($this->vendorName);
    }

    protected function resetForm(): void
    {
        $this->editingId = null;
        $this->vendorName = '';
        $this->contactName = '';
        $this->vendorEmail = '';
        $this->vendorPhone = '';
        $this->addressLine1 = '';
        $this->addressLine2 = '';
        $this->vendorCity = '';
        $this->vendorState = '';
        $this->vendorZip = '';
        $this->vendorCategory = 'supplier';
        $this->budgetCategoryId = null;
        $this->defaultExpenseAccountId = null;
        $this->paymentTermsDays = null;
        $this->vendorActive = true;
        $this->vendorNotes = '';
        $this->w9OnFile = false;
        $this->holdHarmless = false;
        $this->certificateOfInsurance = false;
        $this->insuranceExpiration = null;
        $this->additionalInsured = false;
        $this->workersComp = false;
        $this->workersCompExpiration = null;
        $this->rejectionForm = false;
        $this->vendorBookingSlug = '';
        $this->vendorBookingEnabled = false;
        $this->vendorBookingDisplayName = '';
        $this->vendorBookingBio = '';
        $this->resetValidation();
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <flux:heading class="sr-only">{{ __('Vendors') }}</flux:heading>

    <x-pages::settings.layout :heading="__('Vendors')" :subheading="__('Manage vendors, subcontractors, and supplier compliance')" :fullWidth="true">
        <div class="my-6 w-full space-y-6">

            {{-- Compliance Summary Bar --}}
            @php $summary = $this->complianceSummary; @endphp
            @if ($summary['missing_w9'] > 0 || $summary['expired_insurance'] > 0 || $summary['expired_workers_comp'] > 0 || $summary['expiring_soon'] > 0)
                <flux:callout variant="warning" icon="exclamation-triangle">
                    <flux:callout.text>
                        @php
                            $issues = [];
                            if ($summary['missing_w9'] > 0) {
                                $issues[] = $summary['missing_w9'] . ' ' . __('vendors missing W-9');
                            }
                            if ($summary['expired_insurance'] > 0) {
                                $issues[] = $summary['expired_insurance'] . ' ' . __('with expired insurance');
                            }
                            if ($summary['expired_workers_comp'] > 0) {
                                $issues[] = $summary['expired_workers_comp'] . ' ' . __('with expired workers comp');
                            }
                            if ($summary['expiring_soon'] > 0) {
                                $issues[] = $summary['expiring_soon'] . ' ' . __('expiring within 30 days');
                            }
                        @endphp
                        {{ implode(' | ', $issues) }}
                    </flux:callout.text>
                </flux:callout>
            @endif

            @if (session('error'))
                <flux:callout variant="danger" icon="exclamation-triangle">
                    <flux:callout.text>{{ session('error') }}</flux:callout.text>
                </flux:callout>
            @endif

            @if (session('status'))
                <flux:callout variant="success" icon="check-circle">
                    <flux:callout.text>{{ session('status') }}</flux:callout.text>
                </flux:callout>
            @endif

            {{-- Filters Row --}}
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center">
                <div class="flex-1">
                    <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search vendors...')" size="sm" />
                </div>
                <div class="flex gap-3">
                    <flux:select wire:model.live="categoryFilter" size="sm">
                        <flux:select.option value="">{{ __('All Categories') }}</flux:select.option>
                        @foreach (\App\Models\Vendor::CATEGORIES as $key => $label)
                            <flux:select.option value="{{ $key }}">{{ __($label) }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model.live="statusFilter" size="sm">
                        <flux:select.option value="">{{ __('All Status') }}</flux:select.option>
                        <flux:select.option value="active">{{ __('Active') }}</flux:select.option>
                        <flux:select.option value="inactive">{{ __('Inactive') }}</flux:select.option>
                    </flux:select>
                </div>
            </div>

            {{-- Vendor Table --}}
            <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-800">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Vendor') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Category') }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400 hidden lg:table-cell">{{ __('Budget Category') }}</th>
                            <th class="px-4 py-3 text-center text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400 hidden md:table-cell">{{ __('W-9') }}</th>
                            <th class="px-4 py-3 text-center text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400 hidden md:table-cell">{{ __('Insurance') }}</th>
                            <th class="px-4 py-3 text-center text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400 hidden md:table-cell">{{ __('Workers Comp') }}</th>
                            <th class="px-4 py-3 text-center text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400 hidden lg:table-cell">{{ __('Booking') }}</th>
                            <th class="px-4 py-3 text-center text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Active') }}</th>
                            <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-700 dark:bg-zinc-900">
                        @forelse ($this->vendors as $vendor)
                            <tr wire:key="vendor-{{ $vendor->id }}" class="{{ ! $vendor->is_active ? 'opacity-50' : '' }}">
                                {{-- Name + Contact --}}
                                <td class="px-4 py-3">
                                    <div class="font-medium text-zinc-900 dark:text-zinc-100">{{ $vendor->name }}</div>
                                    @if ($vendor->contact_name)
                                        <div class="text-sm text-zinc-500 dark:text-zinc-400">{{ $vendor->contact_name }}</div>
                                    @endif
                                    @if ($vendor->email)
                                        <div class="text-xs text-zinc-400 dark:text-zinc-500">{{ $vendor->email }}</div>
                                    @endif
                                    <div class="flex items-center gap-1.5 mt-1">
                                        @if ($vendor->hasCompletedOnboarding())
                                            <flux:badge size="sm" color="green">{{ __('Onboarded') }}</flux:badge>
                                        @elseif ($vendor->isPublicTokenValid())
                                            <flux:badge size="sm" color="amber">{{ __('Pending Onboarding') }}</flux:badge>
                                        @endif
                                        @if ($vendor->documents_count > 0)
                                            <span class="text-xs text-zinc-400">{{ $vendor->documents_count }} {{ __('docs') }}</span>
                                        @endif
                                    </div>
                                </td>

                                {{-- Category --}}
                                <td class="px-4 py-3">
                                    @php
                                        $catColor = match ($vendor->category) {
                                            'supplier' => 'blue',
                                            'subcontractor' => 'purple',
                                            'service' => 'green',
                                            default => 'zinc',
                                        };
                                    @endphp
                                    <flux:badge size="sm" :color="$catColor">{{ __(Vendor::CATEGORIES[$vendor->category] ?? $vendor->category) }}</flux:badge>
                                </td>

                                {{-- Budget Category --}}
                                <td class="px-4 py-3 hidden lg:table-cell">
                                    <span class="text-sm text-zinc-600 dark:text-zinc-400">{{ $vendor->budgetCategory?->name ?? '-' }}</span>
                                </td>

                                {{-- W-9 --}}
                                <td class="px-4 py-3 text-center hidden md:table-cell">
                                    @if ($vendor->w9_on_file)
                                        <flux:icon name="check-circle" variant="mini" class="mx-auto text-green-500" />
                                    @else
                                        <flux:icon name="x-circle" variant="mini" class="mx-auto text-red-500" />
                                    @endif
                                </td>

                                {{-- Insurance --}}
                                <td class="px-4 py-3 text-center hidden md:table-cell">
                                    @php $insStatus = $vendor->complianceStatus()['insurance']['status']; @endphp
                                    @if ($insStatus === 'ok')
                                        <flux:icon name="check-circle" variant="mini" class="mx-auto text-green-500" />
                                    @elseif ($insStatus === 'expired')
                                        <flux:badge size="sm" color="red">{{ __('Expired') }}</flux:badge>
                                    @elseif ($insStatus === 'warning')
                                        <flux:badge size="sm" color="amber">{{ __('Expiring') }}</flux:badge>
                                    @else
                                        <flux:icon name="x-circle" variant="mini" class="mx-auto text-red-500" />
                                    @endif
                                </td>

                                {{-- Workers Comp --}}
                                <td class="px-4 py-3 text-center hidden md:table-cell">
                                    @php $wcStatus = $vendor->complianceStatus()['workers_comp']['status']; @endphp
                                    @if ($wcStatus === 'ok')
                                        <flux:icon name="check-circle" variant="mini" class="mx-auto text-green-500" />
                                    @elseif ($wcStatus === 'expired')
                                        <flux:badge size="sm" color="red">{{ __('Expired') }}</flux:badge>
                                    @elseif ($wcStatus === 'warning')
                                        <flux:badge size="sm" color="amber">{{ __('Expiring') }}</flux:badge>
                                    @else
                                        <flux:icon name="x-circle" variant="mini" class="mx-auto text-red-500" />
                                    @endif
                                </td>

                                {{-- Booking --}}
                                <td class="px-4 py-3 text-center hidden lg:table-cell">
                                    @if ($vendor->booking_enabled && $vendor->booking_slug)
                                        <div class="flex flex-col items-center gap-1">
                                            <flux:badge size="sm" color="blue">{{ __('Active') }}</flux:badge>
                                            <span class="text-xs text-zinc-400 dark:text-zinc-500">/book/{{ $vendor->booking_slug }}</span>
                                        </div>
                                    @else
                                        <span class="text-xs text-zinc-400 dark:text-zinc-500">-</span>
                                    @endif
                                </td>

                                {{-- Active Toggle --}}
                                <td class="px-4 py-3 text-center">
                                    <flux:switch
                                        wire:click="toggleActive({{ $vendor->id }})"
                                        :checked="$vendor->is_active"
                                        size="sm"
                                    />
                                </td>

                                {{-- Actions --}}
                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        @can('vendors.manage')
                                            @if ($vendor->email)
                                                <flux:button size="sm" variant="ghost" icon="envelope" wire:click="sendOnboardingLink({{ $vendor->id }})" wire:confirm="{{ __('Send onboarding link to :email?', ['email' => $vendor->email]) }}" :title="__('Send Onboarding Link')" />
                                            @endif
                                        @endcan
                                        <flux:button size="sm" variant="ghost" icon="document-text" wire:click="viewContracts({{ $vendor->id }})" :title="__('Contracts')" />
                                        <flux:button size="sm" variant="ghost" icon="folder-open" wire:click="viewDocuments({{ $vendor->id }})" :title="__('Documents')" />
                                        <flux:button size="sm" variant="ghost" icon="pencil" wire:click="openEditModal({{ $vendor->id }})" />
                                        <flux:button size="sm" variant="ghost" icon="trash" wire:click="confirmDelete({{ $vendor->id }})" />
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="9" class="px-4 py-8 text-center text-zinc-500 dark:text-zinc-400">
                                    {{ __('No vendors found. Add one to get started.') }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- Pagination --}}
            @if ($this->vendors->hasPages())
                <div class="mt-4">
                    {{ $this->vendors->links() }}
                </div>
            @endif

            {{-- Add Button --}}
            @can('vendors.manage')
                <flux:button variant="primary" icon="plus" wire:click="openAddModal">
                    {{ __('Add Vendor') }}
                </flux:button>
            @endcan

            <x-action-message on="vendors-updated">
                {{ __('Saved.') }}
            </x-action-message>
        </div>
    </x-pages::settings.layout>

    {{-- Add/Edit Flyout Modal --}}
    <flux:modal wire:model="showModal" variant="flyout" class="w-[480px]">
        <form wire:submit="save" class="space-y-6">
            <div>
                <flux:heading size="lg">{{ $editingId ? __('Edit Vendor') : __('Add Vendor') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ $editingId ? __('Update vendor details and compliance information.') : __('Add a new vendor, subcontractor, or supplier.') }}
                </flux:text>
            </div>

            {{-- Basic Info Section --}}
            <div class="space-y-4">
                <flux:heading size="sm">{{ __('Basic Information') }}</flux:heading>

                <flux:input
                    wire:model="vendorName"
                    :label="__('Name')"
                    :placeholder="__('Company or vendor name')"
                    required
                />

                <flux:input
                    wire:model="contactName"
                    :label="__('Contact Name')"
                    :placeholder="__('Primary contact person')"
                />

                <flux:input
                    wire:model="vendorEmail"
                    type="email"
                    :label="__('Email')"
                    :placeholder="__('vendor@example.com')"
                />

                <flux:input
                    wire:model="vendorPhone"
                    :label="__('Phone')"
                    :placeholder="__('(555) 123-4567')"
                />
            </div>

            <flux:separator />

            {{-- Address Section --}}
            <div class="space-y-4">
                <flux:heading size="sm">{{ __('Address') }}</flux:heading>

                <flux:input
                    wire:model="addressLine1"
                    :label="__('Address Line 1')"
                />

                <flux:input
                    wire:model="addressLine2"
                    :label="__('Address Line 2')"
                />

                <div class="grid grid-cols-5 gap-3">
                    <div class="col-span-2">
                        <flux:input wire:model="vendorCity" :label="__('City')" />
                    </div>
                    <div class="col-span-1">
                        <flux:input wire:model="vendorState" :label="__('State')" />
                    </div>
                    <div class="col-span-2">
                        <flux:input wire:model="vendorZip" :label="__('Zip')" />
                    </div>
                </div>
            </div>

            <flux:separator />

            {{-- Accounting Section --}}
            <div class="space-y-4">
                <flux:heading size="sm">{{ __('Accounting') }}</flux:heading>

                <flux:select wire:model="vendorCategory" :label="__('Category')">
                    @foreach (\App\Models\Vendor::CATEGORIES as $key => $label)
                        <flux:select.option value="{{ $key }}">{{ __($label) }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="budgetCategoryId" :label="__('Budget Category')">
                    <flux:select.option value="">{{ __('None') }}</flux:select.option>
                    @foreach ($this->budgetCategories as $bc)
                        <flux:select.option value="{{ $bc->id }}">{{ $bc->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="defaultExpenseAccountId" :label="__('Default Expense Account')">
                    <flux:select.option value="">{{ __('None') }}</flux:select.option>
                    @foreach ($this->expenseAccounts as $account)
                        <flux:select.option value="{{ $account->id }}">{{ $account->code }} - {{ $account->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input
                    wire:model="paymentTermsDays"
                    type="number"
                    :label="__('Payment Terms (days)')"
                    :placeholder="__('e.g., 30')"
                    min="0"
                    max="365"
                />
            </div>

            <flux:separator />

            {{-- Tax Compliance Section --}}
            <div class="space-y-4">
                <flux:heading size="sm">{{ __('Tax Compliance') }}</flux:heading>

                <flux:switch wire:model="w9OnFile" :label="__('W-9 On File')" />

                <flux:switch wire:model="holdHarmless" :label="__('Hold Harmless Agreement')" />

                <flux:switch wire:model.live="certificateOfInsurance" :label="__('Certificate of Insurance')" />

                @if ($certificateOfInsurance)
                    <flux:input
                        wire:model="insuranceExpiration"
                        type="date"
                        :label="__('Insurance Expiration')"
                    />
                @endif

                <flux:switch wire:model="additionalInsured" :label="__('Additional Insured')" />

                <flux:switch wire:model.live="workersComp" :label="__('Workers Comp')" />

                @if ($workersComp)
                    <flux:input
                        wire:model="workersCompExpiration"
                        type="date"
                        :label="__('Workers Comp Expiration')"
                    />
                @endif

                @if (! $workersComp)
                    <flux:switch wire:model="rejectionForm" :label="__('Rejection Form')" :description="__('Workers comp rejection form on file')" />
                @endif
            </div>

            <flux:separator />

            {{-- Booking Configuration --}}
            <div class="space-y-4">
                <flux:heading size="sm">{{ __('Booking Configuration') }}</flux:heading>

                <flux:switch wire:model.live="vendorBookingEnabled" :label="__('Enable Booking Page')" :description="__('Allow this vendor to book estimate appointments')" />

                <flux:field>
                    <flux:label>{{ __('Booking Slug') }}</flux:label>
                    <div class="flex items-center gap-2">
                        <flux:input wire:model="vendorBookingSlug" placeholder="vendor-name" class="flex-1" />
                        <flux:button size="sm" variant="ghost" wire:click="generateBookingSlug" type="button">
                            {{ __('Auto') }}
                        </flux:button>
                    </div>
                    <flux:description>{{ __('Used in the public booking URL: /book/slug') }}</flux:description>
                </flux:field>

                @if ($vendorBookingSlug)
                    <div class="flex items-center gap-2 rounded-md bg-zinc-100 px-3 py-2 text-xs dark:bg-zinc-800">
                        <flux:icon name="link" class="size-3.5 text-zinc-400" />
                        <span class="text-zinc-600 dark:text-zinc-300" x-data x-ref="bookingUrl">{{ url('/book/' . $vendorBookingSlug) }}</span>
                        <button
                            type="button"
                            class="ml-auto text-blue-600 hover:text-blue-800 dark:text-blue-400"
                            x-data
                            x-on:click="
                                navigator.clipboard.writeText($refs.bookingUrl.textContent.trim());
                                $el.textContent = '{{ __('Copied!') }}';
                                setTimeout(() => $el.textContent = '{{ __('Copy') }}', 1500);
                            "
                        >{{ __('Copy') }}</button>
                    </div>
                @endif

                <flux:input wire:model="vendorBookingDisplayName" :label="__('Display Name')" :placeholder="__('Name shown on booking page')" />

                <flux:textarea wire:model="vendorBookingBio" :label="__('Bio')" rows="2" :placeholder="__('Short bio shown on the public booking page...')" />
            </div>

            <flux:separator />

            {{-- Notes Section --}}
            <div class="space-y-4">
                <flux:heading size="sm">{{ __('Notes') }}</flux:heading>

                <flux:textarea
                    wire:model="vendorNotes"
                    :placeholder="__('Any additional notes about this vendor...')"
                    rows="3"
                />
            </div>

            <flux:separator />

            {{-- Footer --}}
            <div class="space-y-4">
                <flux:switch wire:model="vendorActive" :label="__('Active')" :description="__('Inactive vendors are hidden from selection lists')" />

                <div class="flex gap-2">
                    <flux:button type="button" variant="ghost" wire:click="closeModal" class="flex-1">{{ __('Cancel') }}</flux:button>
                    <flux:button type="submit" variant="primary" class="flex-1">
                        {{ $editingId ? __('Update Vendor') : __('Add Vendor') }}
                    </flux:button>
                </div>
            </div>
        </form>
    </flux:modal>

    {{-- Delete Confirmation Modal --}}
    <flux:modal wire:model="showDeleteModal" class="max-w-sm">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">{{ __('Delete Vendor') }}</flux:heading>
                <flux:text class="mt-2">
                    {{ __('Are you sure you want to delete') }} <strong>{{ $deletingName }}</strong>{{ __('? This action cannot be undone.') }}
                </flux:text>
            </div>

            <div class="flex gap-2">
                <flux:button variant="ghost" wire:click="cancelDelete" class="flex-1">{{ __('Cancel') }}</flux:button>
                <flux:button variant="danger" wire:click="delete" class="flex-1">{{ __('Delete') }}</flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Documents Modal --}}
    <flux:modal wire:model="showDocumentsModal" variant="flyout" class="w-[520px]">
        @if ($this->viewingVendor)
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Documents: :name', ['name' => $this->viewingVendor->name]) }}</flux:heading>
                    <flux:text class="mt-1">
                        {{ __('Manage vendor documents and compliance files.') }}
                    </flux:text>
                </div>

                {{-- Onboarding Link Section --}}
                @can('vendors.manage')
                    <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-800/50">
                        <div class="flex items-center justify-between">
                            <div>
                                <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('Onboarding Link') }}</span>
                                @if ($this->viewingVendor->isPublicTokenValid())
                                    <div class="mt-0.5 text-xs text-green-600 dark:text-green-400">
                                        {{ __('Active until :date', ['date' => $this->viewingVendor->public_token_expires_at->format('M j, Y')]) }}
                                    </div>
                                @elseif ($this->viewingVendor->hasCompletedOnboarding())
                                    <div class="mt-0.5 text-xs text-green-600 dark:text-green-400">
                                        {{ __('Completed :date', ['date' => $this->viewingVendor->onboarding_completed_at->format('M j, Y')]) }}
                                    </div>
                                @else
                                    <div class="mt-0.5 text-xs text-zinc-500">{{ __('No active link') }}</div>
                                @endif
                            </div>
                            @if ($this->viewingVendor->email)
                                <flux:button size="sm" icon="envelope" wire:click="sendOnboardingLink({{ $this->viewingVendor->id }})" wire:confirm="{{ __('Send onboarding link to :email?', ['email' => $this->viewingVendor->email]) }}">
                                    {{ __('Send Link') }}
                                </flux:button>
                            @else
                                <flux:badge size="sm" color="amber">{{ __('No email') }}</flux:badge>
                            @endif
                        </div>
                        @if ($this->viewingVendor->isPublicTokenValid())
                            <div class="mt-2">
                                <input type="text" readonly value="{{ $this->viewingVendor->getOnboardingUrl() }}" class="w-full select-all rounded border border-zinc-300 bg-white px-2 py-1 text-xs text-zinc-500 dark:border-zinc-600 dark:bg-zinc-900" onclick="this.select()" />
                            </div>
                        @endif
                    </div>
                @endcan

                <flux:separator />

                {{-- Existing Documents --}}
                <div>
                    <flux:heading size="sm" class="mb-3">{{ __('Documents') }}</flux:heading>

                    @if ($this->viewingVendor->documents->isEmpty())
                        <flux:text class="text-sm">{{ __('No documents uploaded yet.') }}</flux:text>
                    @else
                        <div class="space-y-2">
                            @foreach ($this->viewingVendor->documents->sortByDesc('created_at') as $doc)
                                <div wire:key="doc-{{ $doc->id }}" class="flex items-center justify-between rounded-lg border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900">
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center gap-2">
                                            <flux:icon name="document" variant="mini" class="shrink-0 text-zinc-400" />
                                            <span class="truncate text-sm font-medium">{{ $doc->name }}</span>
                                        </div>
                                        <div class="ml-6 mt-1 flex items-center gap-2">
                                            <flux:badge size="sm">{{ $doc->getTypeLabel() }}</flux:badge>
                                            <flux:badge size="sm" :color="$doc->status === 'approved' ? 'green' : ($doc->status === 'rejected' ? 'red' : 'amber')">
                                                {{ $doc->getStatusLabel() }}
                                            </flux:badge>
                                            @if ($doc->expires_at)
                                                <span class="text-xs text-zinc-500 {{ $doc->isExpired() ? 'text-red-500' : '' }}">
                                                    {{ $doc->isExpired() ? __('Expired') : __('Exp') }} {{ $doc->expires_at->format('M j, Y') }}
                                                </span>
                                            @endif
                                        </div>
                                        <div class="ml-6 mt-0.5 text-xs text-zinc-400">
                                            {{ $doc->original_filename }} &middot; {{ $doc->created_at->format('M j, Y') }}
                                        </div>
                                    </div>
                                    <div class="ml-2 flex shrink-0 items-center gap-1">
                                        @can('vendors.manage')
                                            <a href="{{ route('vendors.documents.view', $doc) }}" target="_blank" title="{{ __('View') }}" class="inline-flex items-center justify-center rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-300">
                                                <flux:icon name="eye" variant="mini" />
                                            </a>
                                            <a href="{{ route('vendors.documents.download', $doc) }}" title="{{ __('Download') }}" class="inline-flex items-center justify-center rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-300">
                                                <flux:icon name="arrow-down-tray" variant="mini" />
                                            </a>
                                            @if ($doc->isPending())
                                                <flux:button size="sm" variant="ghost" icon="check" wire:click="approveDocument({{ $doc->id }})" class="text-green-600" :title="__('Approve')" />
                                                <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="rejectDocument({{ $doc->id }})" class="text-red-600" :title="__('Reject')" />
                                            @endif
                                            <flux:button size="sm" variant="ghost" icon="trash" wire:click="deleteDocument({{ $doc->id }})" wire:confirm="{{ __('Delete this document?') }}" :title="__('Delete')" />
                                        @endcan
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <flux:separator />

                {{-- Internal Upload --}}
                @can('vendors.manage')
                    <div>
                        <flux:heading size="sm" class="mb-3">{{ __('Upload Document') }}</flux:heading>

                        <div class="space-y-3">
                            <flux:input wire:model="internalUploadName" :label="__('Document Name')" :placeholder="__('e.g., W-9 Form 2026')" size="sm" />

                            <flux:select wire:model="internalUploadType" :label="__('Document Type')" size="sm">
                                @foreach (\App\Models\VendorDocument::TYPES as $val => $label)
                                    <flux:select.option value="{{ $val }}">{{ $label }}</flux:select.option>
                                @endforeach
                            </flux:select>

                            <flux:input wire:model="internalUploadExpires" type="date" :label="__('Expiration Date (optional)')" size="sm" />

                            <div>
                                <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ __('File') }}</label>
                                <input type="file" wire:model="internalUploadFile" accept=".pdf,.jpg,.jpeg,.png"
                                    class="block w-full text-sm text-zinc-500 file:mr-4 file:rounded-lg file:border-0 file:bg-blue-50 file:px-3 file:py-1.5 file:text-sm file:font-semibold file:text-blue-700 hover:file:bg-blue-100 dark:text-zinc-400 dark:file:bg-blue-900/50 dark:file:text-blue-300 dark:hover:file:bg-blue-900" />
                                <div wire:loading wire:target="internalUploadFile" class="mt-1 text-sm text-blue-600 dark:text-blue-400">{{ __('Uploading...') }}</div>
                                @error('internalUploadFile') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                            </div>

                            <flux:button size="sm" variant="primary" icon="arrow-up-tray" wire:click="uploadInternalDocument">
                                {{ __('Upload') }}
                            </flux:button>
                        </div>
                    </div>
                @endcan
            </div>
        @endif
    </flux:modal>

    {{-- Contracts Flyout Modal --}}
    <flux:modal wire:model="showContractsModal" variant="flyout" class="w-[520px]">
        @if ($this->contractVendor)
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">{{ __('Contracts') }} &mdash; {{ $this->contractVendor->name }}</flux:heading>
                    <flux:text class="mt-1">{{ __('Manage contracts for this vendor.') }}</flux:text>
                </div>

                {{-- Create New Contract --}}
                <div class="rounded-lg border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-800/50">
                    <flux:heading size="sm" class="mb-2">{{ __('New Contract') }}</flux:heading>
                    @if ($this->vendorTemplates->isNotEmpty())
                        <div class="flex items-end gap-2 mb-2">
                            <div class="flex-1">
                                <flux:select wire:model.live="selectedTemplateId" size="sm">
                                    <flux:select.option value="">{{ __('Select a template...') }}</flux:select.option>
                                    @foreach ($this->vendorTemplates as $template)
                                        <flux:select.option value="{{ $template->id }}">{{ $template->name }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </div>
                            <flux:button
                                size="sm"
                                variant="primary"
                                icon="plus"
                                wire:click="createContract"
                                :disabled="! $selectedTemplateId"
                            >
                                {{ __('From Template') }}
                            </flux:button>
                        </div>
                    @endif
                    <flux:button
                        size="sm"
                        variant="ghost"
                        icon="document-plus"
                        wire:click="createContract"
                        class="w-full"
                    >
                        {{ __('Blank Contract') }}
                    </flux:button>
                </div>

                <flux:separator />

                {{-- Contract List --}}
                <div>
                    <flux:heading size="sm" class="mb-3">{{ __('Contracts') }}</flux:heading>

                    @if ($this->vendorContracts->isEmpty())
                        <flux:text class="text-sm">{{ __('No contracts for this vendor.') }}</flux:text>
                    @else
                        <div class="space-y-2">
                            @foreach ($this->vendorContracts as $contract)
                                <div wire:key="contract-{{ $contract->id }}" class="rounded-lg border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="min-w-0 flex-1">
                                            <div class="font-medium text-sm text-zinc-900 dark:text-zinc-100">{{ $contract->title }}</div>
                                            <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                                @php
                                                    $statusColor = match ($contract->status) {
                                                        'draft' => 'zinc',
                                                        'pending_internal' => 'amber',
                                                        'pending_vendor' => 'blue',
                                                        'fully_executed' => 'green',
                                                        'expired' => 'red',
                                                        'invalidated' => 'zinc',
                                                        default => 'zinc',
                                                    };
                                                @endphp
                                                <flux:badge size="sm" :color="$statusColor">{{ $contract->getStatusLabel() }}</flux:badge>
                                                @if ($contract->expires_at)
                                                    <span class="text-xs {{ $contract->expires_at->isPast() ? 'text-red-500' : 'text-zinc-500' }}">
                                                        {{ $contract->expires_at->isPast() ? __('Expired') : __('Expires') }} {{ $contract->expires_at->format('M j, Y') }}
                                                    </span>
                                                @endif
                                                @if (! $contract->is_valid)
                                                    <flux:badge size="sm" color="red">{{ __('Invalid') }}</flux:badge>
                                                @endif
                                            </div>
                                            <div class="mt-0.5 text-xs text-zinc-400">
                                                {{ __('Created') }} {{ $contract->created_at->format('M j, Y') }}
                                            </div>
                                        </div>
                                        <div class="flex shrink-0 items-center gap-1">
                                            @if ($contract->isDraft())
                                                <a href="{{ route('vendor-contracts.edit', ['vendor' => $contract->vendor_id, 'contract' => $contract->id]) }}" class="inline-flex items-center justify-center rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-300" title="{{ __('Edit') }}">
                                                    <flux:icon name="pencil" variant="mini" />
                                                </a>
                                                <flux:button size="sm" variant="ghost" icon="trash" wire:click="deleteContract({{ $contract->id }})" wire:confirm="{{ __('Delete this draft contract?') }}" :title="__('Delete')" />
                                            @elseif ($contract->isPendingInternal())
                                                <a href="{{ route('vendor-contracts.edit', ['vendor' => $contract->vendor_id, 'contract' => $contract->id]) }}" class="inline-flex items-center justify-center rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-300" title="{{ __('Sign') }}">
                                                    <flux:icon name="pencil-square" variant="mini" />
                                                </a>
                                                <flux:button size="sm" variant="ghost" icon="x-circle" wire:click="invalidateContract({{ $contract->id }})" wire:confirm="{{ __('Invalidate this contract?') }}" :title="__('Invalidate')" />
                                            @elseif ($contract->isPendingVendor())
                                                <flux:button size="sm" variant="ghost" icon="x-circle" wire:click="invalidateContract({{ $contract->id }})" wire:confirm="{{ __('Invalidate this contract?') }}" :title="__('Invalidate')" />
                                            @elseif ($contract->isFullyExecuted())
                                                @if ($contract->executed_pdf_path)
                                                    <a href="{{ route('vendor-contracts.pdf.download', ['vendor' => $contract->vendor_id, 'contract' => $contract->id]) }}" class="inline-flex items-center justify-center rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-300" title="{{ __('Download PDF') }}">
                                                        <flux:icon name="arrow-down-tray" variant="mini" />
                                                    </a>
                                                @endif
                                                <flux:button size="sm" variant="ghost" icon="x-circle" wire:click="invalidateContract({{ $contract->id }})" wire:confirm="{{ __('Invalidate this contract?') }}" :title="__('Invalidate')" />
                                            @elseif ($contract->isExpired())
                                                @if ($contract->executed_pdf_path)
                                                    <a href="{{ route('vendor-contracts.pdf.download', ['vendor' => $contract->vendor_id, 'contract' => $contract->id]) }}" class="inline-flex items-center justify-center rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-300" title="{{ __('Download PDF') }}">
                                                        <flux:icon name="arrow-down-tray" variant="mini" />
                                                    </a>
                                                @endif
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </flux:modal>
</section>
