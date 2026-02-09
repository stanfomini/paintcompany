<?php

use App\Models\BudgetCategory;
use App\Models\CompanySetting;
use App\Models\Vendor;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $categoryFilter = '';

    // CRUD modal
    public bool $showVendorModal = false;
    public ?int $editingVendorId = null;
    public string $vendorName = '';
    public string $vendorContactName = '';
    public string $vendorEmail = '';
    public string $vendorPhone = '';
    public string $vendorAddressLine1 = '';
    public string $vendorAddressLine2 = '';
    public string $vendorCity = '';
    public string $vendorState = '';
    public string $vendorZip = '';
    public string $vendorCategory = 'supplier';
    public string $vendorPaymentTermsDays = '';
    public string $vendorNotes = '';
    public ?string $vendorBudgetCategoryId = null;

    // Subcontractor compliance fields
    public bool $vendorW9OnFile = false;
    public bool $vendorHoldHarmless = false;
    public bool $vendorCertificateOfInsurance = false;
    public ?string $vendorInsuranceExpiration = null;
    public bool $vendorAdditionalInsured = false;
    public bool $vendorWorkersComp = false;
    public ?string $vendorWorkersCompExpiration = null;
    public bool $vendorRejectionForm = false;

    public function boot(): void
    {
        $user = Auth::user();
        if (! $user || ! $user->can('vendors.view')) {
            abort(403);
        }
    }

    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()?->can('vendors.manage') ?? false;
    }

    #[Computed]
    public function budgetCategories(): \Illuminate\Support\Collection
    {
        $settings = CompanySetting::getInstance();

        return BudgetCategory::where('company_setting_id', $settings->id)
            ->whereIn('section', [
                BudgetCategory::SECTION_VARIABLE_EXPENSE,
                BudgetCategory::SECTION_VEHICLE_EXPENSE,
                BudgetCategory::SECTION_BUSINESS_EXPENSE,
            ])
            ->orderBy('section')
            ->orderBy('sort_order')
            ->get();
    }

    #[Computed]
    public function vendors()
    {
        $settings = CompanySetting::getInstance();

        $query = Vendor::where('company_setting_id', $settings->id)
            ->orderBy('name');

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('name', 'like', "%{$this->search}%")
                    ->orWhere('contact_name', 'like', "%{$this->search}%")
                    ->orWhere('email', 'like', "%{$this->search}%");
            });
        }

        if ($this->categoryFilter) {
            $query->where('category', $this->categoryFilter);
        }

        return $query->paginate(20);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
        unset($this->vendors);
    }

    public function updatedCategoryFilter(): void
    {
        $this->resetPage();
        unset($this->vendors);
    }

    public function openCreateModal(): void
    {
        $this->authorize('vendors.manage');

        $this->reset([
            'editingVendorId', 'vendorName', 'vendorContactName', 'vendorEmail',
            'vendorPhone', 'vendorAddressLine1', 'vendorAddressLine2', 'vendorCity',
            'vendorState', 'vendorZip', 'vendorPaymentTermsDays', 'vendorNotes',
            'vendorBudgetCategoryId',
            'vendorW9OnFile', 'vendorHoldHarmless', 'vendorCertificateOfInsurance',
            'vendorInsuranceExpiration', 'vendorAdditionalInsured', 'vendorWorkersComp',
            'vendorWorkersCompExpiration', 'vendorRejectionForm',
        ]);
        $this->vendorCategory = 'supplier';
        $this->showVendorModal = true;
    }

    public function openEditModal(int $vendorId): void
    {
        $this->authorize('vendors.manage');

        $vendor = Vendor::findOrFail($vendorId);
        $this->editingVendorId = $vendor->id;
        $this->vendorName = $vendor->name;
        $this->vendorContactName = $vendor->contact_name ?? '';
        $this->vendorEmail = $vendor->email ?? '';
        $this->vendorPhone = $vendor->phone ?? '';
        $this->vendorAddressLine1 = $vendor->address_line_1 ?? '';
        $this->vendorAddressLine2 = $vendor->address_line_2 ?? '';
        $this->vendorCity = $vendor->city ?? '';
        $this->vendorState = $vendor->state ?? '';
        $this->vendorZip = $vendor->zip ?? '';
        $this->vendorCategory = $vendor->category;
        $this->vendorPaymentTermsDays = $vendor->payment_terms_days ? (string) $vendor->payment_terms_days : '';
        $this->vendorNotes = $vendor->notes ?? '';
        $this->vendorBudgetCategoryId = $vendor->budget_category_id ? (string) $vendor->budget_category_id : null;

        // Compliance fields
        $this->vendorW9OnFile = (bool) $vendor->w9_on_file;
        $this->vendorHoldHarmless = (bool) $vendor->hold_harmless;
        $this->vendorCertificateOfInsurance = (bool) $vendor->certificate_of_insurance;
        $this->vendorInsuranceExpiration = $vendor->insurance_expiration?->format('Y-m-d');
        $this->vendorAdditionalInsured = (bool) $vendor->additional_insured;
        $this->vendorWorkersComp = (bool) $vendor->workers_comp;
        $this->vendorWorkersCompExpiration = $vendor->workers_comp_expiration?->format('Y-m-d');
        $this->vendorRejectionForm = (bool) $vendor->rejection_form;

        $this->showVendorModal = true;
    }

    public function saveVendor(): void
    {
        $this->authorize('vendors.manage');

        $this->validate([
            'vendorName' => 'required|string|max:255',
            'vendorEmail' => 'nullable|email|max:255',
            'vendorCategory' => 'required|in:supplier,subcontractor,service,other',
        ]);

        $settings = CompanySetting::getInstance();

        $data = [
            'company_setting_id' => $settings->id,
            'name' => $this->vendorName,
            'contact_name' => $this->vendorContactName ?: null,
            'email' => $this->vendorEmail ?: null,
            'phone' => $this->vendorPhone ?: null,
            'address_line_1' => $this->vendorAddressLine1 ?: null,
            'address_line_2' => $this->vendorAddressLine2 ?: null,
            'city' => $this->vendorCity ?: null,
            'state' => $this->vendorState ?: null,
            'zip' => $this->vendorZip ?: null,
            'category' => $this->vendorCategory,
            'budget_category_id' => $this->vendorBudgetCategoryId ?: null,
            'payment_terms_days' => $this->vendorPaymentTermsDays ? (int) $this->vendorPaymentTermsDays : null,
            'notes' => $this->vendorNotes ?: null,
        ];

        // Include compliance fields for subcontractors
        if ($this->vendorCategory === 'subcontractor') {
            $data = array_merge($data, [
                'w9_on_file' => $this->vendorW9OnFile,
                'hold_harmless' => $this->vendorHoldHarmless,
                'certificate_of_insurance' => $this->vendorCertificateOfInsurance,
                'insurance_expiration' => $this->vendorInsuranceExpiration ?: null,
                'additional_insured' => $this->vendorAdditionalInsured,
                'workers_comp' => $this->vendorWorkersComp,
                'workers_comp_expiration' => $this->vendorWorkersCompExpiration ?: null,
                'rejection_form' => $this->vendorRejectionForm,
            ]);
        }

        if ($this->editingVendorId) {
            Vendor::findOrFail($this->editingVendorId)->update($data);
        } else {
            Vendor::create($data);
        }

        $this->showVendorModal = false;
        unset($this->vendors);
    }

    public function toggleActive(int $vendorId): void
    {
        $this->authorize('vendors.manage');

        $vendor = Vendor::findOrFail($vendorId);
        $vendor->update(['is_active' => ! $vendor->is_active]);
        unset($this->vendors);
    }

    public function getComplianceScore(Vendor $vendor): int
    {
        if ($vendor->category !== 'subcontractor') {
            return 0;
        }

        $checks = [
            $vendor->w9_on_file,
            $vendor->hold_harmless,
            $vendor->certificate_of_insurance,
            $vendor->additional_insured,
            $vendor->workers_comp,
            $vendor->rejection_form,
        ];

        return collect($checks)->filter()->count();
    }
};
?>

<div>
    {{-- Header --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <flux:heading size="lg">Vendors</flux:heading>
            <flux:text class="mt-1 text-zinc-500 dark:text-zinc-400">
                Manage suppliers, subcontractors, and service providers.
            </flux:text>
        </div>

        @if ($this->canManage)
            <flux:button variant="primary" icon="plus" wire:click="openCreateModal" size="sm">
                Add Vendor
            </flux:button>
        @endif
    </div>

    {{-- Filters --}}
    <div class="mb-4 flex flex-wrap items-center gap-3">
        <flux:input wire:model.live.debounce.300ms="search" placeholder="Search vendors..." icon="magnifying-glass" class="w-64" />

        <flux:select wire:model.live="categoryFilter" class="w-44">
            <option value="">All Categories</option>
            @foreach (App\Models\Vendor::CATEGORIES as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </flux:select>
    </div>

    {{-- Vendors Table --}}
    @if ($this->vendors->isEmpty())
        <flux:callout variant="outline" icon="building-storefront">
            <flux:callout.heading>No vendors found</flux:callout.heading>
            <flux:callout.text>Add your first vendor to start tracking supplier relationships.</flux:callout.text>
        </flux:callout>
    @else
        <div class="overflow-hidden rounded-lg border border-zinc-200 dark:border-zinc-700">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                <thead class="bg-zinc-50 dark:bg-zinc-800">
                    <tr>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Name</th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Category</th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Contact</th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Email</th>
                        <th class="px-4 py-2 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Phone</th>
                        <th class="px-4 py-2 text-center text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Status</th>
                        @if ($this->canManage)
                            <th class="px-4 py-2 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Actions</th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-100 bg-white dark:divide-zinc-800 dark:bg-zinc-900">
                    @foreach ($this->vendors as $vendor)
                        <tr wire:key="vendor-{{ $vendor->id }}" class="{{ ! $vendor->is_active ? 'opacity-50' : '' }}">
                            <td class="whitespace-nowrap px-4 py-2 text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                <div>{{ $vendor->name }}</div>
                                @if ($vendor->category === 'subcontractor')
                                    @php $score = $this->getComplianceScore($vendor); @endphp
                                    <div class="mt-0.5 flex items-center gap-1">
                                        <div class="flex gap-0.5">
                                            @for ($i = 0; $i < 6; $i++)
                                                <div class="h-1.5 w-1.5 rounded-full {{ $i < $score ? 'bg-green-500' : 'bg-zinc-300 dark:bg-zinc-600' }}"></div>
                                            @endfor
                                        </div>
                                        <span class="text-[10px] text-zinc-400">{{ $score }}/6</span>
                                    </div>
                                @endif
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-sm">
                                <flux:badge size="sm" color="zinc">
                                    {{ App\Models\Vendor::CATEGORIES[$vendor->category] ?? ucfirst($vendor->category) }}
                                </flux:badge>
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-sm text-zinc-600 dark:text-zinc-400">
                                {{ $vendor->contact_name ?? '-' }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-sm text-zinc-600 dark:text-zinc-400">
                                {{ $vendor->email ?? '-' }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-sm text-zinc-600 dark:text-zinc-400">
                                {{ $vendor->phone ?? '-' }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-2 text-center text-sm">
                                <flux:badge size="sm" :color="$vendor->is_active ? 'green' : 'red'">
                                    {{ $vendor->is_active ? 'Active' : 'Inactive' }}
                                </flux:badge>
                            </td>
                            @if ($this->canManage)
                                <td class="whitespace-nowrap px-4 py-2 text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        <flux:button variant="ghost" size="sm" icon="pencil" wire:click="openEditModal({{ $vendor->id }})" />
                                        <flux:button variant="ghost" size="sm"
                                            :icon="$vendor->is_active ? 'eye-slash' : 'eye'"
                                            wire:click="toggleActive({{ $vendor->id }})"
                                            wire:confirm="{{ $vendor->is_active ? 'Deactivate this vendor?' : 'Reactivate this vendor?' }}" />
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $this->vendors->links() }}
        </div>
    @endif

    {{-- Vendor Modal --}}
    @if ($this->canManage)
        <flux:modal wire:model="showVendorModal" class="max-w-lg">
            <form wire:submit="saveVendor">
                <div class="space-y-4">
                    <flux:heading size="lg">{{ $editingVendorId ? 'Edit Vendor' : 'Add Vendor' }}</flux:heading>

                    <flux:field>
                        <flux:label>Company Name</flux:label>
                        <flux:input wire:model="vendorName" placeholder="Vendor name" />
                        <flux:error name="vendorName" />
                    </flux:field>

                    <div class="grid grid-cols-2 gap-4">
                        <flux:field>
                            <flux:label>Contact Name</flux:label>
                            <flux:input wire:model="vendorContactName" placeholder="Contact person" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Category</flux:label>
                            <flux:select wire:model.live="vendorCategory">
                                @foreach (App\Models\Vendor::CATEGORIES as $value => $label)
                                    <option value="{{ $value }}">{{ $label }}</option>
                                @endforeach
                            </flux:select>
                            <flux:error name="vendorCategory" />
                        </flux:field>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <flux:field>
                            <flux:label>Email</flux:label>
                            <flux:input wire:model="vendorEmail" type="email" placeholder="email@example.com" />
                            <flux:error name="vendorEmail" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Phone</flux:label>
                            <flux:input wire:model="vendorPhone" placeholder="(555) 123-4567" />
                        </flux:field>
                    </div>

                    <flux:field>
                        <flux:label>Address Line 1</flux:label>
                        <flux:input wire:model="vendorAddressLine1" placeholder="Street address" />
                    </flux:field>

                    <flux:field>
                        <flux:label>Address Line 2</flux:label>
                        <flux:input wire:model="vendorAddressLine2" placeholder="Suite, unit, etc." />
                    </flux:field>

                    <div class="grid grid-cols-3 gap-4">
                        <flux:field>
                            <flux:label>City</flux:label>
                            <flux:input wire:model="vendorCity" />
                        </flux:field>
                        <flux:field>
                            <flux:label>State</flux:label>
                            <flux:input wire:model="vendorState" maxlength="2" placeholder="CA" />
                        </flux:field>
                        <flux:field>
                            <flux:label>ZIP</flux:label>
                            <flux:input wire:model="vendorZip" maxlength="10" placeholder="90210" />
                        </flux:field>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <flux:field>
                            <flux:label>Payment Terms (days)</flux:label>
                            <flux:input wire:model="vendorPaymentTermsDays" type="number" min="0" placeholder="30" />
                        </flux:field>

                        <flux:field>
                            <flux:label>Default Budget Category</flux:label>
                            <flux:select wire:model="vendorBudgetCategoryId">
                                <option value="">None</option>
                                @foreach ($this->budgetCategories as $cat)
                                    <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                                @endforeach
                            </flux:select>
                        </flux:field>
                    </div>

                    {{-- Subcontractor Compliance Section --}}
                    @if ($vendorCategory === 'subcontractor')
                        <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-900/20">
                            <flux:heading size="sm" class="mb-3 text-amber-800 dark:text-amber-300">Subcontractor Compliance</flux:heading>

                            <div class="grid grid-cols-2 gap-3">
                                <flux:checkbox wire:model="vendorW9OnFile" label="W-9 on File" />
                                <flux:checkbox wire:model="vendorHoldHarmless" label="Hold Harmless Agreement" />
                                <flux:checkbox wire:model="vendorCertificateOfInsurance" label="Certificate of Insurance" />
                                <flux:checkbox wire:model="vendorAdditionalInsured" label="Additional Insured" />
                                <flux:checkbox wire:model="vendorWorkersComp" label="Workers' Comp" />
                                <flux:checkbox wire:model="vendorRejectionForm" label="Rejection Form" />
                            </div>

                            <div class="mt-3 grid grid-cols-2 gap-4">
                                <flux:field>
                                    <flux:label>Insurance Expiration</flux:label>
                                    <flux:input wire:model="vendorInsuranceExpiration" type="date" />
                                </flux:field>
                                <flux:field>
                                    <flux:label>Workers' Comp Expiration</flux:label>
                                    <flux:input wire:model="vendorWorkersCompExpiration" type="date" />
                                </flux:field>
                            </div>
                        </div>
                    @endif

                    <flux:field>
                        <flux:label>Notes</flux:label>
                        <flux:textarea wire:model="vendorNotes" rows="2" placeholder="Additional notes..." />
                    </flux:field>

                    <div class="flex justify-end gap-2 pt-2">
                        <flux:button variant="ghost" wire:click="$set('showVendorModal', false)" type="button">Cancel</flux:button>
                        <flux:button type="submit" variant="primary">{{ $editingVendorId ? 'Update' : 'Create' }}</flux:button>
                    </div>
                </div>
            </form>
        </flux:modal>
    @endif
</div>
