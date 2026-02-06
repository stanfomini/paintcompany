<?php

use App\Models\CompanySetting;
use App\Models\JobCostCard;
use App\Services\JobCostCardService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public int $year;

    // Modal state
    public bool $showModal = false;
    public bool $showDeleteModal = false;
    public ?int $editingCardId = null;
    public ?int $deletingCardId = null;

    // Form fields
    public string $clientName = '';
    public string $price = '0';
    public string $productionMgmtLabor = '0';
    public string $subcontractorCost = '0';
    public string $wcInsuranceBurden = '0';
    public string $materialCost = '0';
    public string $salesCommission = '0';
    public string $notes = '';

    public function mount(): void
    {
        $this->year = now()->year;
    }

    public function boot(): void
    {
        $user = Auth::user();
        if (! $user || ! $user->can('business_plan.view')) {
            abort(403);
        }
    }

    #[Computed]
    public function canManage(): bool
    {
        return Auth::user()?->can('business_plan.manage') ?? false;
    }

    #[Computed]
    public function service(): JobCostCardService
    {
        return new JobCostCardService();
    }

    #[Computed]
    public function cards(): \Illuminate\Support\Collection
    {
        return $this->service->getCardsForYear($this->year);
    }

    #[Computed]
    public function totals(): array
    {
        return $this->service->getRunningTotals($this->year);
    }

    public function changeYear(int $year): void
    {
        $this->year = $year;
        unset($this->cards, $this->totals);
    }

    public function openCreateModal(): void
    {
        $this->authorize('business_plan.manage');

        $this->resetForm();
        $this->editingCardId = null;
        $this->showModal = true;
    }

    public function openEditModal(int $cardId): void
    {
        $this->authorize('business_plan.manage');

        $card = JobCostCard::find($cardId);
        if (! $card) {
            return;
        }

        $this->editingCardId = $cardId;
        $this->clientName = $card->client_name;
        $this->price = (string) $card->price;
        $this->productionMgmtLabor = (string) $card->production_mgmt_labor;
        $this->subcontractorCost = (string) $card->subcontractor_cost;
        $this->wcInsuranceBurden = (string) $card->wc_insurance_burden;
        $this->materialCost = (string) $card->material_cost;
        $this->salesCommission = (string) $card->sales_commission;
        $this->notes = $card->notes ?? '';
        $this->showModal = true;
    }

    public function save(): void
    {
        $this->authorize('business_plan.manage');

        $this->validate([
            'clientName' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'productionMgmtLabor' => 'required|numeric|min:0',
            'subcontractorCost' => 'required|numeric|min:0',
            'wcInsuranceBurden' => 'required|numeric|min:0',
            'materialCost' => 'required|numeric|min:0',
            'salesCommission' => 'required|numeric|min:0',
        ]);

        $settings = CompanySetting::getInstance();

        $data = [
            'company_setting_id' => $settings->id,
            'year' => $this->year,
            'client_name' => $this->clientName,
            'price' => (float) $this->price,
            'production_mgmt_labor' => (float) $this->productionMgmtLabor,
            'subcontractor_cost' => (float) $this->subcontractorCost,
            'wc_insurance_burden' => (float) $this->wcInsuranceBurden,
            'material_cost' => (float) $this->materialCost,
            'sales_commission' => (float) $this->salesCommission,
            'notes' => $this->notes ?: null,
            'is_manual' => true,
        ];

        if ($this->editingCardId) {
            $card = JobCostCard::find($this->editingCardId);
            if ($card) {
                $card->update($data);
            }
        } else {
            JobCostCard::create($data);
        }

        $this->showModal = false;
        $this->resetForm();
        unset($this->cards, $this->totals);
    }

    public function confirmDelete(int $cardId): void
    {
        $this->authorize('business_plan.manage');

        $this->deletingCardId = $cardId;
        $this->showDeleteModal = true;
    }

    public function deleteCard(): void
    {
        $this->authorize('business_plan.manage');

        if (! $this->deletingCardId) {
            return;
        }

        $card = JobCostCard::find($this->deletingCardId);
        if ($card && $card->is_manual) {
            $card->delete();
        }

        $this->showDeleteModal = false;
        $this->deletingCardId = null;
        unset($this->cards, $this->totals);
    }

    public function exportCsv(): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorize('business_plan.manage');
        $cards = $this->cards;
        $totals = $this->totals;
        $year = $this->year;

        return response()->streamDownload(function () use ($cards, $totals) {
            $handle = fopen('php://output', 'w');

            // Header row
            fputcsv($handle, [
                'Client',
                'Price',
                'Prod Mgmt Labor',
                'Prod Mgmt %',
                'Subcontractors',
                'Subcontractors %',
                'WC & Insurance',
                'WC & Insurance %',
                'Materials',
                'Materials %',
                'Sales Commission',
                'Commission %',
                'Total Cost',
                'Gross Profit',
                'Gross Profit %',
                'Type',
                'Notes',
            ]);

            // Data rows
            foreach ($cards as $card) {
                fputcsv($handle, [
                    $card->client_name,
                    number_format((float) $card->price, 2, '.', ''),
                    number_format((float) $card->production_mgmt_labor, 2, '.', ''),
                    $card->production_mgmt_percent,
                    number_format((float) $card->subcontractor_cost, 2, '.', ''),
                    $card->subcontractor_percent,
                    number_format((float) $card->wc_insurance_burden, 2, '.', ''),
                    $card->wc_insurance_percent,
                    number_format((float) $card->material_cost, 2, '.', ''),
                    $card->material_percent,
                    number_format((float) $card->sales_commission, 2, '.', ''),
                    $card->commission_percent,
                    number_format($card->total_cost, 2, '.', ''),
                    number_format($card->gross_profit, 2, '.', ''),
                    $card->gross_profit_percent,
                    $card->is_manual ? 'Manual' : 'Auto',
                    $card->notes ?? '',
                ]);
            }

            // Totals row
            fputcsv($handle, [
                'TOTALS',
                number_format($totals['price'], 2, '.', ''),
                number_format($totals['production_mgmt_labor'], 2, '.', ''),
                '',
                number_format($totals['subcontractor_cost'], 2, '.', ''),
                '',
                number_format($totals['wc_insurance_burden'], 2, '.', ''),
                '',
                number_format($totals['material_cost'], 2, '.', ''),
                '',
                number_format($totals['sales_commission'], 2, '.', ''),
                '',
                number_format($totals['total_cost'], 2, '.', ''),
                number_format($totals['gross_profit'], 2, '.', ''),
                $totals['gross_profit_percent'],
                '',
                '',
            ]);

            fclose($handle);
        }, "job-cost-cards-{$year}.csv");
    }

    private function resetForm(): void
    {
        $this->clientName = '';
        $this->price = '0';
        $this->productionMgmtLabor = '0';
        $this->subcontractorCost = '0';
        $this->wcInsuranceBurden = '0';
        $this->materialCost = '0';
        $this->salesCommission = '0';
        $this->notes = '';
        $this->editingCardId = null;
        $this->resetValidation();
    }
};
?>

<div>
    {{-- Header with year selector and actions --}}
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-3">
            <flux:heading size="lg">Job Cost Cards</flux:heading>
            <flux:badge color="zinc">{{ $this->totals['count'] }} {{ Str::plural('job', $this->totals['count']) }}</flux:badge>
        </div>

        <div class="flex items-center gap-2">
            {{-- Year Selector --}}
            <flux:button variant="ghost" icon="chevron-left" wire:click="changeYear({{ $year - 1 }})" />
            <span class="min-w-[4rem] text-center text-sm font-semibold text-zinc-700 dark:text-zinc-300">{{ $year }}</span>
            <flux:button variant="ghost" icon="chevron-right" wire:click="changeYear({{ $year + 1 }})" />

            <flux:separator vertical class="mx-1 h-6" />

            {{-- Export CSV --}}
            <flux:button variant="ghost" icon="table-cells" wire:click="exportCsv" size="sm">
                CSV
            </flux:button>

            {{-- Add Manual Entry --}}
            @if ($this->canManage)
                <flux:button variant="primary" icon="plus" wire:click="openCreateModal" size="sm">
                    Add Entry
                </flux:button>
            @endif
        </div>
    </div>

    {{-- Running Totals Summary --}}
    @if ($this->totals['count'] > 0)
        <div class="mb-6 rounded-lg border border-blue-200 bg-blue-50 p-4 dark:border-blue-800 dark:bg-blue-950/30">
            <div class="mb-2 text-xs font-semibold uppercase tracking-wide text-blue-700 dark:text-blue-400">
                {{ $year }} Running Totals
            </div>
            <div class="grid grid-cols-2 gap-4 sm:grid-cols-4 lg:grid-cols-6">
                <div>
                    <div class="text-xs text-zinc-500 dark:text-zinc-400">Revenue</div>
                    <div class="text-lg font-bold text-zinc-900 dark:text-zinc-100">${{ number_format($this->totals['price'], 0) }}</div>
                </div>
                <div>
                    <div class="text-xs text-zinc-500 dark:text-zinc-400">Total Costs</div>
                    <div class="text-lg font-bold text-zinc-900 dark:text-zinc-100">${{ number_format($this->totals['total_cost'], 0) }}</div>
                </div>
                <div>
                    <div class="text-xs text-zinc-500 dark:text-zinc-400">Gross Profit</div>
                    <div class="text-lg font-bold {{ $this->totals['gross_profit'] >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">
                        ${{ number_format($this->totals['gross_profit'], 0) }}
                    </div>
                </div>
                <div>
                    <div class="text-xs text-zinc-500 dark:text-zinc-400">GP %</div>
                    <div class="text-lg font-bold {{ $this->totals['gross_profit_percent'] >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">
                        {{ $this->totals['gross_profit_percent'] }}%
                    </div>
                </div>
                <div>
                    <div class="text-xs text-zinc-500 dark:text-zinc-400">Materials</div>
                    <div class="text-lg font-bold text-zinc-900 dark:text-zinc-100">${{ number_format($this->totals['material_cost'], 0) }}</div>
                </div>
                <div>
                    <div class="text-xs text-zinc-500 dark:text-zinc-400">Commissions</div>
                    <div class="text-lg font-bold text-zinc-900 dark:text-zinc-100">${{ number_format($this->totals['sales_commission'], 0) }}</div>
                </div>
            </div>
        </div>
    @endif

    {{-- Cards Table --}}
    @if ($this->cards->isEmpty())
        <flux:callout variant="outline" icon="calculator">
            <flux:callout.heading>No Job Cost Cards for {{ $year }}</flux:callout.heading>
            <flux:callout.text>
                Add manual entries or complete jobs to auto-populate cost cards.
            </flux:callout.text>
        </flux:callout>
    @else
        <div class="overflow-x-auto rounded-lg border border-zinc-200 dark:border-zinc-700">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                <thead class="bg-zinc-50 dark:bg-zinc-800/50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Client</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Price</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                            <span class="hidden lg:inline">Prod Mgmt</span>
                            <span class="lg:hidden">PM</span>
                        </th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Subs</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                            <span class="hidden lg:inline">WC/Ins</span>
                            <span class="lg:hidden">WC</span>
                        </th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                            <span class="hidden lg:inline">Materials</span>
                            <span class="lg:hidden">Mat</span>
                        </th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                            <span class="hidden lg:inline">Commission</span>
                            <span class="lg:hidden">Comm</span>
                        </th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                            <span class="hidden lg:inline">Gross Profit</span>
                            <span class="lg:hidden">GP</span>
                        </th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">GP %</th>
                        @if ($this->canManage)
                            <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Actions</th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 bg-white dark:divide-zinc-700 dark:bg-zinc-900">
                    @foreach ($this->cards as $card)
                        <tr wire:key="card-{{ $card->id ?? $card->job_id ?? $loop->index }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                            <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-900 dark:text-zinc-100">
                                <div class="flex items-center gap-2">
                                    {{ $card->client_name }}
                                    @if (! $card->is_manual)
                                        <flux:badge size="sm" color="blue">Auto</flux:badge>
                                    @endif
                                </div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                ${{ number_format((float) $card->price, 0) }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-zinc-600 dark:text-zinc-400">
                                <div>${{ number_format((float) $card->production_mgmt_labor, 0) }}</div>
                                <div class="text-xs text-zinc-400 dark:text-zinc-500">{{ $card->production_mgmt_percent }}%</div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-zinc-600 dark:text-zinc-400">
                                <div>${{ number_format((float) $card->subcontractor_cost, 0) }}</div>
                                <div class="text-xs text-zinc-400 dark:text-zinc-500">{{ $card->subcontractor_percent }}%</div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-zinc-600 dark:text-zinc-400">
                                <div>${{ number_format((float) $card->wc_insurance_burden, 0) }}</div>
                                <div class="text-xs text-zinc-400 dark:text-zinc-500">{{ $card->wc_insurance_percent }}%</div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-zinc-600 dark:text-zinc-400">
                                <div>${{ number_format((float) $card->material_cost, 0) }}</div>
                                <div class="text-xs text-zinc-400 dark:text-zinc-500">{{ $card->material_percent }}%</div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-zinc-600 dark:text-zinc-400">
                                <div>${{ number_format((float) $card->sales_commission, 0) }}</div>
                                <div class="text-xs text-zinc-400 dark:text-zinc-500">{{ $card->commission_percent }}%</div>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-medium {{ $card->gross_profit >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">
                                ${{ number_format($card->gross_profit, 0) }}
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-medium {{ $card->gross_profit_percent >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">
                                {{ $card->gross_profit_percent }}%
                            </td>
                            @if ($this->canManage)
                                <td class="whitespace-nowrap px-4 py-3 text-right text-sm">
                                    <div class="flex items-center justify-end gap-1">
                                        @if ($card->id)
                                            <flux:button variant="ghost" size="sm" icon="pencil-square" wire:click="openEditModal({{ $card->id }})" />
                                        @endif
                                        @if ($card->is_manual && $card->id)
                                            <flux:button variant="ghost" size="sm" icon="trash" class="text-red-500 hover:text-red-700" wire:click="confirmDelete({{ $card->id }})" />
                                        @endif
                                    </div>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                </tbody>
                {{-- Totals Footer --}}
                <tfoot class="bg-zinc-100 font-semibold dark:bg-zinc-800">
                    <tr>
                        <td class="px-4 py-3 text-sm text-zinc-900 dark:text-zinc-100">TOTALS</td>
                        <td class="px-4 py-3 text-right text-sm text-zinc-900 dark:text-zinc-100">${{ number_format($this->totals['price'], 0) }}</td>
                        <td class="px-4 py-3 text-right text-sm text-zinc-900 dark:text-zinc-100">${{ number_format($this->totals['production_mgmt_labor'], 0) }}</td>
                        <td class="px-4 py-3 text-right text-sm text-zinc-900 dark:text-zinc-100">${{ number_format($this->totals['subcontractor_cost'], 0) }}</td>
                        <td class="px-4 py-3 text-right text-sm text-zinc-900 dark:text-zinc-100">${{ number_format($this->totals['wc_insurance_burden'], 0) }}</td>
                        <td class="px-4 py-3 text-right text-sm text-zinc-900 dark:text-zinc-100">${{ number_format($this->totals['material_cost'], 0) }}</td>
                        <td class="px-4 py-3 text-right text-sm text-zinc-900 dark:text-zinc-100">${{ number_format($this->totals['sales_commission'], 0) }}</td>
                        <td class="px-4 py-3 text-right text-sm font-bold {{ $this->totals['gross_profit'] >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">
                            ${{ number_format($this->totals['gross_profit'], 0) }}
                        </td>
                        <td class="px-4 py-3 text-right text-sm font-bold {{ $this->totals['gross_profit_percent'] >= 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-red-600 dark:text-red-400' }}">
                            {{ $this->totals['gross_profit_percent'] }}%
                        </td>
                        @if ($this->canManage)
                            <td></td>
                        @endif
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif

    {{-- Create/Edit Modal --}}
    <flux:modal wire:model="showModal" class="max-w-lg">
        <div class="space-y-4">
            <flux:heading size="lg">
                {{ $editingCardId ? 'Edit Job Cost Card' : 'Add Manual Entry' }}
            </flux:heading>

            <flux:field>
                <flux:label>Client Name</flux:label>
                <flux:input wire:model="clientName" placeholder="Client name" />
                <flux:error name="clientName" />
            </flux:field>

            <flux:field>
                <flux:label>Price (Revenue)</flux:label>
                <flux:input wire:model="price" type="number" step="0.01" min="0" />
                <flux:error name="price" />
            </flux:field>

            <div class="grid grid-cols-2 gap-4">
                <flux:field>
                    <flux:label>Prod Mgmt Labor</flux:label>
                    <flux:input wire:model="productionMgmtLabor" type="number" step="0.01" min="0" />
                    <flux:error name="productionMgmtLabor" />
                </flux:field>

                <flux:field>
                    <flux:label>Subcontractors</flux:label>
                    <flux:input wire:model="subcontractorCost" type="number" step="0.01" min="0" />
                    <flux:error name="subcontractorCost" />
                </flux:field>

                <flux:field>
                    <flux:label>WC & Insurance</flux:label>
                    <flux:input wire:model="wcInsuranceBurden" type="number" step="0.01" min="0" />
                    <flux:error name="wcInsuranceBurden" />
                </flux:field>

                <flux:field>
                    <flux:label>Materials</flux:label>
                    <flux:input wire:model="materialCost" type="number" step="0.01" min="0" />
                    <flux:error name="materialCost" />
                </flux:field>

                <flux:field>
                    <flux:label>Sales Commission</flux:label>
                    <flux:input wire:model="salesCommission" type="number" step="0.01" min="0" />
                    <flux:error name="salesCommission" />
                </flux:field>
            </div>

            <flux:field>
                <flux:label>Notes (optional)</flux:label>
                <flux:textarea wire:model="notes" rows="2" placeholder="Optional notes..." />
            </flux:field>

            <div class="flex justify-end gap-2 pt-2">
                <flux:button variant="ghost" wire:click="$set('showModal', false)">Cancel</flux:button>
                <flux:button variant="primary" wire:click="save">
                    {{ $editingCardId ? 'Update' : 'Create' }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Delete Confirmation Modal --}}
    <flux:modal wire:model="showDeleteModal" class="max-w-sm">
        <div class="space-y-4">
            <flux:heading size="lg">Delete Entry</flux:heading>
            <flux:text>Are you sure you want to delete this manual entry? This cannot be undone.</flux:text>
            <div class="flex justify-end gap-2 pt-2">
                <flux:button variant="ghost" wire:click="$set('showDeleteModal', false)">Cancel</flux:button>
                <flux:button variant="danger" wire:click="deleteCard">Delete</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
