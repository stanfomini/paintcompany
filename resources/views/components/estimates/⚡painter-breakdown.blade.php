<?php

use App\Models\CompanySetting;
use App\Models\Estimate;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    public Estimate $estimate;

    public array $expandedAreas = [];

    public string $activeTab = 'work-order';

    public int $crewSize = 2;

    public float $hoursPerDay = 8;

    /** @var array<int|string, float> Actual paint costs keyed by paint_product_id */
    public array $actualPaintCosts = [];

    /** @var array<int, array{name: string, hourly_rate: float, hours: float, notes: string}> */
    public array $painters = [];

    public float $burdenMultiplier = 1.35;

    public function mount(Estimate $estimate): void
    {
        $this->estimate = $estimate->load([
            'areas.surfaces.productionRate',
            'areas.surfaces.paintProduct.brand',
            'areas.addons.nestedAreaTemplate',
            'areas.substrate',
            'deal.property',
            'deal.customer.user',
        ]);

        // Expand all areas by default
        foreach ($this->estimate->areas as $area) {
            $this->expandedAreas[$area->id] = true;
        }

        // Load existing actuals
        $this->actualPaintCosts = $this->estimate->actual_paint_costs ?? [];
        $this->painters = $this->estimate->actual_painters ?? [];

        $settings = CompanySetting::getInstance();
        $this->burdenMultiplier = (float) $settings->payroll_burden_multiplier;
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function setCrewSize(int $size): void
    {
        $this->crewSize = max(1, min(4, $size));
    }

    public function setHoursPerDay(float $hours): void
    {
        $this->hoursPerDay = max(4, min(12, $hours));
    }

    public function toggleArea(int $areaId): void
    {
        $this->expandedAreas[$areaId] = ! ($this->expandedAreas[$areaId] ?? false);
    }

    public function expandAll(): void
    {
        foreach ($this->estimate->areas as $area) {
            $this->expandedAreas[$area->id] = true;
        }
    }

    public function collapseAll(): void
    {
        $this->expandedAreas = [];
    }

    public function addPainter(): void
    {
        $settings = CompanySetting::getInstance();
        $scope = $this->estimate->project_type ?? 'interior';

        $this->painters[] = [
            'name' => '',
            'hourly_rate' => $settings->getHourlyWageForScope($scope),
            'hours' => 0,
            'notes' => '',
        ];
    }

    public function removePainter(int $index): void
    {
        unset($this->painters[$index]);
        $this->painters = array_values($this->painters);
    }

    public function saveActuals(): void
    {
        $this->estimate->actual_paint_costs = $this->actualPaintCosts;
        $this->estimate->actual_painters = $this->painters;
        $this->estimate->save();
    }

    public function saveSnapshot(): void
    {
        $this->saveActuals();

        $costs = $this->profitSummary;
        $this->estimate->profit_snapshot = array_merge($costs, [
            'captured_at' => now()->toIso8601String(),
            'painters' => $this->painters,
            'paint_costs' => $this->actualPaintCosts,
        ]);
        $this->estimate->save();
    }

    #[Computed]
    public function jobTotals(): array
    {
        $laborHours = 0;
        $cleanupHours = 0;
        $areaCount = 0;

        $settings = CompanySetting::getInstance();
        $scope = $this->estimate->project_type ?? 'interior';

        foreach ($this->estimate->areas as $area) {
            $areaLaborHours = (float) $area->surfaces()->where('is_included', true)->sum('labor_hours');
            $laborHours += $areaLaborHours;
            $cleanupHours += $settings->calculateCleanupHours($areaLaborHours, $scope);
            $areaCount++;
        }

        return [
            'labor_hours' => $laborHours,
            'cleanup_hours' => $cleanupHours,
            'total_hours' => $laborHours + $cleanupHours,
            'area_count' => $areaCount,
        ];
    }

    #[Computed]
    public function materialsNeeded(): array
    {
        $materials = [];

        foreach ($this->estimate->areas as $area) {
            foreach ($area->surfaces()->where('is_included', true)->get() as $surface) {
                $paintProduct = $surface->getEffectivePaintProduct();

                if ($paintProduct) {
                    $key = $paintProduct->id;

                    if (! isset($materials[$key])) {
                        $materials[$key] = [
                            'product' => $paintProduct,
                            'sheen' => ucfirst($paintProduct->sheen),
                            'brand' => $paintProduct->brand?->name ?? 'Unknown',
                            'name' => $paintProduct->name,
                            'gallons' => 0,
                            'surfaces' => [],
                        ];
                    }

                    $gallons = (float) ($surface->paint_gallons ?? 0);
                    if ($gallons === 0.0 && $surface->quantity > 0) {
                        // Calculate gallons if not stored
                        $calcResult = $paintProduct->calculateGallons(
                            (float) $surface->quantity,
                            $surface->coats ?? 2,
                            $surface->application_method ?? 'brush_roll'
                        );
                        $gallons = $calcResult['gallons_rounded'];
                    }

                    $materials[$key]['gallons'] += $gallons;
                    $materials[$key]['surfaces'][] = "{$area->name} - {$surface->name}";
                } elseif ($surface->paint_brand && $surface->paint_type) {
                    // Legacy paint brand/type
                    $key = "{$surface->paint_brand}_{$surface->paint_type}";

                    if (! isset($materials[$key])) {
                        $materials[$key] = [
                            'product' => null,
                            'brand' => $surface->paint_brand_label,
                            'name' => $surface->paint_type_label,
                            'sheen' => null,
                            'gallons' => 0,
                            'surfaces' => [],
                        ];
                    }

                    $materials[$key]['gallons'] += (float) ($surface->paint_gallons ?? 1);
                    $materials[$key]['surfaces'][] = "{$area->name} - {$surface->name}";
                }
            }
        }

        return array_values($materials);
    }

    #[Computed]
    public function materialsWithCosts(): array
    {
        $settings = CompanySetting::getInstance();
        $materials = [];
        $totalGallons = 0;
        $totalPaintCost = 0;
        $totalRecycleFee = 0;

        foreach ($this->estimate->areas as $area) {
            foreach ($area->surfaces()->where('is_included', true)->get() as $surface) {
                $paintProduct = $surface->getEffectivePaintProduct();

                if ($paintProduct) {
                    $key = $paintProduct->id;

                    if (! isset($materials[$key])) {
                        $materials[$key] = [
                            'product' => $paintProduct,
                            'brand' => $paintProduct->brand?->name ?? 'Unknown',
                            'name' => $paintProduct->name,
                            'sheen' => ucfirst($paintProduct->sheen ?? ''),
                            'price_per_gallon' => (float) $paintProduct->price_per_gallon,
                            'gallons' => 0,
                            'paint_cost' => 0,
                            'surfaces' => [],
                        ];
                    }

                    $gallons = (float) ($surface->paint_gallons ?? 0);
                    if ($gallons === 0.0 && $surface->quantity > 0) {
                        $calcResult = $paintProduct->calculateGallons(
                            (float) $surface->quantity,
                            $surface->coats ?? 2,
                            $surface->application_method ?? 'brush_roll'
                        );
                        $gallons = $calcResult['gallons_rounded'];
                    }

                    $materials[$key]['gallons'] += $gallons;
                    $materials[$key]['paint_cost'] = $materials[$key]['gallons'] * $materials[$key]['price_per_gallon'];
                    $materials[$key]['surfaces'][] = "{$area->name} - {$surface->name}";
                }
            }
        }

        $materials = array_values($materials);

        foreach ($materials as $material) {
            $totalGallons += $material['gallons'];
            $totalPaintCost += $material['paint_cost'];
        }

        $recycleFeeRate = (float) ($settings->recycle_fee_per_gallon);
        $totalRecycleFee = round($totalGallons * $recycleFeeRate, 2);

        return [
            'items' => $materials,
            'total_gallons' => $totalGallons,
            'total_paint_cost' => round($totalPaintCost, 2),
            'recycle_fee_rate' => $recycleFeeRate,
            'total_recycle_fee' => $totalRecycleFee,
            'total_materials_cost' => round($totalPaintCost + $totalRecycleFee, 2),
        ];
    }

    #[Computed]
    public function sundriesBreakdown(): array
    {
        $settings = CompanySetting::getInstance();
        $scope = $this->estimate->project_type ?? 'interior';
        $jobTotals = $this->jobTotals;

        $laborHours = $jobTotals['labor_hours'];
        $sundriesCostPerHour = $settings->getSundriesCostForScope($scope);
        $totalSundriesCost = round($laborHours * $sundriesCostPerHour, 2);

        return [
            'labor_hours' => $laborHours,
            'cost_per_hour' => $sundriesCostPerHour,
            'total_cost' => $totalSundriesCost,
            'scope' => $scope,
        ];
    }

    #[Computed]
    public function painterPayBreakdown(): array
    {
        $settings = CompanySetting::getInstance();
        $scope = $this->estimate->project_type ?? 'interior';
        $jobTotals = $this->jobTotals;

        $baseHourlyWage = $settings->getHourlyWageForScope($scope);
        $payrollBurdenMultiplier = (float) ($settings->payroll_burden_multiplier);
        $payrollBurdenPercent = ($payrollBurdenMultiplier - 1) * 100;
        $effectiveHourlyRate = $baseHourlyWage * $payrollBurdenMultiplier;

        $laborHours = $jobTotals['labor_hours'];
        $cleanupHours = $jobTotals['cleanup_hours'];
        $totalHours = $jobTotals['total_hours'];

        $basePay = round($totalHours * $baseHourlyWage, 2);
        $burdenAmount = round($basePay * ($payrollBurdenMultiplier - 1), 2);
        $loadedPay = round($totalHours * $effectiveHourlyRate, 2);

        return [
            'base_hourly_wage' => $baseHourlyWage,
            'payroll_burden_multiplier' => $payrollBurdenMultiplier,
            'payroll_burden_percent' => round($payrollBurdenPercent, 0),
            'effective_hourly_rate' => round($effectiveHourlyRate, 2),
            'labor_hours' => $laborHours,
            'cleanup_hours' => $cleanupHours,
            'total_hours' => $totalHours,
            'painter_earns_base' => $basePay,
            'burden_amount' => $burdenAmount,
            'company_pays_loaded' => $loadedPay,
            'scope' => $scope,
        ];
    }

    #[Computed]
    public function materialsProfit(): array
    {
        $items = [];
        $totalCatalogCost = 0;
        $totalActualCost = 0;

        foreach ($this->materialsWithCosts['items'] as $material) {
            $product = $material['product'];
            $productId = $product ? (string) $product->id : null;
            $catalogPrice = (float) $material['price_per_gallon'];
            $actualPrice = ($productId && isset($this->actualPaintCosts[$productId]))
                ? (float) $this->actualPaintCosts[$productId]
                : null;
            $gallons = ceil($material['gallons']);

            $catalogTotal = $gallons * $catalogPrice;
            $actualTotal = $actualPrice !== null ? $gallons * $actualPrice : null;
            $profit = $actualTotal !== null ? $catalogTotal - $actualTotal : null;

            $items[] = [
                'product_id' => $productId,
                'brand' => $material['brand'],
                'name' => $material['name'],
                'sheen' => $material['sheen'],
                'gallons' => $gallons,
                'catalog_price' => $catalogPrice,
                'actual_price' => $actualPrice,
                'catalog_total' => round($catalogTotal, 2),
                'actual_total' => $actualTotal !== null ? round($actualTotal, 2) : null,
                'profit' => $profit !== null ? round($profit, 2) : null,
            ];

            $totalCatalogCost += $catalogTotal;
            if ($actualTotal !== null) {
                $totalActualCost += $actualTotal;
            }
        }

        $hasActuals = collect($items)->whereNotNull('actual_price')->isNotEmpty();
        $totalProfit = $hasActuals ? $totalCatalogCost - $totalActualCost : null;

        return [
            'items' => $items,
            'total_catalog_cost' => round($totalCatalogCost, 2),
            'total_actual_cost' => round($totalActualCost, 2),
            'total_profit' => $totalProfit !== null ? round($totalProfit, 2) : null,
            'has_actuals' => $hasActuals,
        ];
    }

    #[Computed]
    public function paintersBreakdown(): array
    {
        $totalBasePay = 0;
        $totalHours = 0;

        $painterRows = [];
        foreach ($this->painters as $index => $painter) {
            $rate = (float) ($painter['hourly_rate'] ?? 0);
            $hours = (float) ($painter['hours'] ?? 0);
            $basePay = round($rate * $hours, 2);

            $painterRows[] = [
                'index' => $index,
                'name' => $painter['name'] ?? '',
                'hourly_rate' => $rate,
                'hours' => $hours,
                'base_pay' => $basePay,
                'notes' => $painter['notes'] ?? '',
            ];

            $totalBasePay += $basePay;
            $totalHours += $hours;
        }

        $totalBurdenCost = round($totalBasePay * ($this->burdenMultiplier - 1), 2);
        $totalLaborCost = round($totalBasePay + $totalBurdenCost, 2);

        return [
            'painters' => $painterRows,
            'total_base_pay' => round($totalBasePay, 2),
            'total_burden_cost' => $totalBurdenCost,
            'total_labor_cost' => $totalLaborCost,
            'total_hours' => round($totalHours, 1),
            'burden_multiplier' => $this->burdenMultiplier,
        ];
    }

    #[Computed]
    public function profitSummary(): array
    {
        $materialsProfit = $this->materialsProfit;
        $paintersData = $this->paintersBreakdown;
        $painterPay = $this->painterPayBreakdown;
        $sundriesCost = $this->sundriesBreakdown['total_cost'];
        $recycleFeeCost = $this->materialsWithCosts['total_recycle_fee'];

        // Job price = company pays (loaded labor) + materials (catalog paint + sundries + recycle)
        $jobPrice = $painterPay['company_pays_loaded']
            + $materialsProfit['total_catalog_cost']
            + $sundriesCost
            + $recycleFeeCost;

        // Crew wages: actual painters if entered, otherwise company-wide calc
        $crewWages = count($this->painters) > 0
            ? $paintersData['total_base_pay']
            : $painterPay['painter_earns_base'];

        // Payroll burden: company pays this on top of wages (insurance, WC, taxes)
        $burdenCost = count($this->painters) > 0
            ? $paintersData['total_burden_cost']
            : $painterPay['burden_amount'];

        // Paint cost: actual if entered, otherwise catalog
        $paintCost = $materialsProfit['has_actuals']
            ? $materialsProfit['total_actual_cost']
            : $materialsProfit['total_catalog_cost'];

        $totalCosts = $crewWages + $burdenCost + $paintCost + $sundriesCost + $recycleFeeCost;
        $grossProfit = $jobPrice - $totalCosts;
        $grossMarginPercent = $jobPrice > 0 ? ($grossProfit / $jobPrice) * 100 : 0;

        return [
            'job_price' => round($jobPrice, 2),
            'crew_wages' => round($crewWages, 2),
            'burden_cost' => round($burdenCost, 2),
            'paint_cost' => round($paintCost, 2),
            'sundries_cost' => round($sundriesCost, 2),
            'recycle_fee' => round($recycleFeeCost, 2),
            'total_costs' => round($totalCosts, 2),
            'gross_profit' => round($grossProfit, 2),
            'gross_margin_percent' => round($grossMarginPercent, 1),
            'has_actuals' => count($this->painters) > 0 || $materialsProfit['has_actuals'],
        ];
    }

    #[Computed]
    public function crewScheduleGrid(): array
    {
        $totalHours = $this->jobTotals['total_hours'];
        $painterPay = $this->painterPayBreakdown;
        $baseHourlyWage = $painterPay['base_hourly_wage'];

        $grid = [];
        $workdayOptions = [6, 8, 10];

        for ($painters = 1; $painters <= 4; $painters++) {
            $hoursPerPainter = $totalHours / $painters;
            $daysAtSelectedHours = $this->hoursPerDay > 0 ? ceil($hoursPerPainter / $this->hoursPerDay * 10) / 10 : 0;

            $days = [];
            foreach ($workdayOptions as $hoursPerWorkday) {
                $daysNeeded = $hoursPerPainter / $hoursPerWorkday;
                $days[$hoursPerWorkday] = round($daysNeeded, 1);
            }

            // Calculate daily pay for a single painter (at selected hours/day)
            $dailyPayEach = round($baseHourlyWage * $this->hoursPerDay, 2);

            $grid[] = [
                'painters' => $painters,
                'hours_per_painter' => round($hoursPerPainter, 1),
                'days_needed' => $days,
                'days_at_selected_hours' => $daysAtSelectedHours,
                'daily_pay_each' => $dailyPayEach,
                'total_job_pay' => $painterPay['painter_earns_base'],
            ];
        }

        return [
            'grid' => $grid,
            'total_hours' => $totalHours,
            'base_hourly_wage' => $baseHourlyWage,
            'selected_hours_per_day' => $this->hoursPerDay,
        ];
    }

    #[Computed]
    public function areaBreakdowns(): array
    {
        $areas = [];
        $settings = CompanySetting::getInstance();
        $scope = $this->estimate->project_type ?? 'interior';

        foreach ($this->estimate->areas as $area) {
            $surfaces = [];
            $totalLaborHours = 0;

            foreach ($area->surfaces()->where('is_included', true)->orderBy('sort_order')->get() as $surface) {
                $paintProduct = $surface->getEffectivePaintProduct();
                $substrate = $area->substrate;

                $prepNotes = [];
                if ($substrate) {
                    if ($substrate->requires_wash) {
                        $prepNotes[] = 'Pressure wash';
                    }
                    if ($substrate->requires_scrape) {
                        $prepNotes[] = 'Scrape';
                    }
                    if ($substrate->requires_sand) {
                        $prepNotes[] = 'Sand';
                    }
                    if ($substrate->requires_prime) {
                        $prepNotes[] = 'Prime';
                    }
                }

                $surfaces[] = [
                    'id' => $surface->id,
                    'name' => $surface->name,
                    'quantity' => (float) $surface->quantity,
                    'unit' => $surface->unit ?? 'sqft',
                    'coats' => $surface->coats ?? 1,
                    'labor_hours' => (float) $surface->labor_hours,
                    'application_method' => $surface->application_method_label,
                    'paint_product' => $paintProduct ? [
                        'full_name' => $paintProduct->full_name,
                        'sheen' => ucfirst($paintProduct->sheen ?? ''),
                        'coverage' => $paintProduct->getCoverage($surface->application_method ?? 'brush_roll'),
                    ] : null,
                    'paint_brand' => $surface->paint_brand_label,
                    'paint_type' => $surface->paint_type_label,
                    'substrate' => $substrate?->name,
                    'prep_notes' => $prepNotes,
                ];

                $totalLaborHours += (float) $surface->labor_hours;
            }

            $cleanupHours = $settings->calculateCleanupHours($totalLaborHours, $scope);

            // Get addons (nested area templates like closets)
            $addons = [];
            foreach ($area->addons as $addon) {
                $template = $addon->nestedAreaTemplate;
                $addons[] = [
                    'name' => $template?->name ?? $addon->name ?? 'Addon',
                    'labor_hours' => (float) ($addon->labor_hours ?? 0),
                ];
            }

            $areas[] = [
                'id' => $area->id,
                'name' => $area->name,
                'scope_type' => ucfirst($area->scope_type ?? 'interior'),
                'dimensions' => $this->formatDimensions($area),
                'total_sqft' => $this->calculateTotalSqft($area),
                'surfaces' => $surfaces,
                'addons' => $addons,
                'labor_hours' => $totalLaborHours,
                'cleanup_hours' => $cleanupHours,
                'total_hours' => $totalLaborHours + $cleanupHours,
                'notes' => $area->notes,
                'substrate' => $area->substrate?->name,
                'substrate_prep' => $area->substrate?->prep_requirements,
            ];
        }

        return $areas;
    }

    #[Computed]
    public function customerInfo(): array
    {
        $deal = $this->estimate->deal;
        $customer = $deal?->customer;
        $property = $deal?->property;

        return [
            'name' => $customer?->user?->name ?? 'Unknown Customer',
            'address' => $property ? $this->formatAddress($property) : 'No address on file',
            'property_type' => $property?->property_type ? ucfirst($property->property_type) : null,
        ];
    }

    #[Computed]
    public function projectInfo(): array
    {
        return [
            'estimate_number' => $this->estimate->estimate_number,
            'title' => $this->estimate->title,
            'project_type' => ucfirst($this->estimate->project_type ?? 'Interior'),
            'status' => $this->estimate->status_label,
            'notes' => $this->estimate->notes,
            'scheduled_date' => null, // Placeholder for future feature
            'foreman' => null, // Placeholder for future feature
        ];
    }

    private function formatDimensions($area): ?string
    {
        if ($area->length && $area->width && $area->height) {
            return number_format($area->length, 0)."' × ".number_format($area->width, 0)."' × ".number_format($area->height, 0)."' H";
        }

        return null;
    }

    private function calculateTotalSqft($area): float
    {
        $total = 0;
        $total += (float) ($area->wall_sqft ?? 0);
        $total += (float) ($area->ceiling_sqft ?? 0);

        return $total;
    }

    private function formatAddress($property): string
    {
        $parts = array_filter([
            $property->address,
        ]);

        $cityState = array_filter([
            $property->city,
            $property->state,
            $property->zip,
        ]);

        if (! empty($cityState)) {
            $parts[] = implode(', ', [$property->city, $property->state]).' '.$property->zip;
        }

        return implode(', ', array_filter($parts)) ?: 'No address';
    }
}; ?>

<div>
    <div class="max-w-4xl mx-auto">
        {{-- Header --}}
        <div class="flex items-center justify-between mb-6 print:mb-4">
            <div class="flex items-center gap-4">
                <flux:button variant="ghost" :href="route('estimates.show', $estimate)" icon="arrow-left" wire:navigate class="print:hidden">
                    Back to Estimate
                </flux:button>
                <div>
                    <flux:heading size="xl" class="print:text-lg">Painter's Breakdown</flux:heading>
                    <flux:text class="text-zinc-500">{{ $this->projectInfo['estimate_number'] }}</flux:text>
                </div>
            </div>
            <div class="flex items-center gap-2 print:hidden">
                @if ($activeTab === 'work-order')
                    <flux:button icon="printer" variant="ghost" onclick="window.print()">
                        Print
                    </flux:button>
                    <flux:button icon="document-arrow-down" variant="ghost" :href="route('estimates.painter-breakdown.pdf.work-order', $estimate)">
                        PDF
                    </flux:button>
                @else
                    <flux:button icon="document-arrow-down" variant="primary" :href="route('estimates.painter-breakdown.pdf.financials', $estimate)">
                        Export Financials PDF
                    </flux:button>
                @endif
            </div>
        </div>

        {{-- Tab Navigation --}}
        <div class="flex gap-2 mb-6 print:hidden">
            <flux:button
                wire:click="setTab('work-order')"
                :variant="$activeTab === 'work-order' ? 'primary' : 'ghost'"
                icon="clipboard-document-list"
            >
                Work Order
            </flux:button>
            <flux:button
                wire:click="setTab('financials')"
                :variant="$activeTab === 'financials' ? 'primary' : 'ghost'"
                icon="calculator"
            >
                Financials
            </flux:button>
        </div>

        @if ($activeTab === 'work-order')
            {{-- WORK ORDER TAB (Existing Content) --}}

            {{-- Crew-Safe Notice --}}
            <flux:callout variant="info" class="mb-6 print:hidden">
                <flux:icon name="shield-check" class="w-5 h-5" />
                <div>
                    <strong>Crew-Safe View</strong> - This page shows only the information painters need. No pricing or margin data.
                </div>
            </flux:callout>

            {{-- Job Header Card --}}
            <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 mb-6 print:p-4 print:border-2 print:border-black print:rounded-none">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 print:gap-4">
                    <div>
                        <h3 class="text-sm font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider mb-2">Customer</h3>
                        <p class="text-lg font-bold text-zinc-900 dark:text-white">{{ $this->customerInfo['name'] }}</p>
                        <p class="text-zinc-600 dark:text-zinc-300">{{ $this->customerInfo['address'] }}</p>
                        @if ($this->customerInfo['property_type'])
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $this->customerInfo['property_type'] }}</p>
                        @endif
                    </div>
                    <div>
                        <h3 class="text-sm font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider mb-2">Project Details</h3>
                        <p class="text-lg font-bold text-zinc-900 dark:text-white">{{ $this->projectInfo['project_type'] }} Painting</p>
                        @if ($this->projectInfo['title'])
                            <p class="text-zinc-600 dark:text-zinc-300">{{ $this->projectInfo['title'] }}</p>
                        @endif
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">
                            Status: <span class="font-medium">{{ $this->projectInfo['status'] }}</span>
                        </p>
                        @if ($this->projectInfo['scheduled_date'])
                            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">
                                <flux:icon name="calendar" class="w-4 h-4 inline" />
                                Scheduled: {{ $this->projectInfo['scheduled_date'] }}
                            </p>
                        @endif
                        @if ($this->projectInfo['foreman'])
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                                <flux:icon name="user" class="w-4 h-4 inline" />
                                Foreman: {{ $this->projectInfo['foreman'] }}
                            </p>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Expand/Collapse Controls --}}
            <div class="flex items-center justify-between mb-4 print:hidden">
                <flux:heading size="lg">Areas to Paint</flux:heading>
                <div class="flex gap-2">
                    <flux:button variant="ghost" size="sm" wire:click="expandAll">Expand All</flux:button>
                    <flux:button variant="ghost" size="sm" wire:click="collapseAll">Collapse All</flux:button>
                </div>
            </div>

            {{-- Area Task Lists --}}
            <div class="space-y-4 print:space-y-6">
                @foreach ($this->areaBreakdowns as $area)
                    <div
                        class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden print:border-2 print:border-black print:rounded-none print:break-inside-avoid"
                        wire:key="area-{{ $area['id'] }}"
                    >
                        {{-- Area Header --}}
                        <div
                            class="flex items-center justify-between p-4 bg-zinc-50 dark:bg-zinc-800/50 cursor-pointer print:cursor-default print:bg-zinc-100"
                            wire:click="toggleArea({{ $area['id'] }})"
                        >
                            <div class="flex items-center gap-3">
                                <div class="print:hidden">
                                    @if ($expandedAreas[$area['id']] ?? false)
                                        <svg class="w-5 h-5 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                                        </svg>
                                    @else
                                        <svg class="w-5 h-5 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                        </svg>
                                    @endif
                                </div>
                                <div>
                                    <h3 class="text-lg font-bold text-zinc-900 dark:text-white">{{ $area['name'] }}</h3>
                                    <div class="flex items-center gap-3 text-sm text-zinc-500 dark:text-zinc-400">
                                        <span>{{ $area['scope_type'] }}</span>
                                        @if ($area['dimensions'])
                                            <span>&bull;</span>
                                            <span>{{ $area['dimensions'] }}</span>
                                        @endif
                                        @if ($area['total_sqft'] > 0)
                                            <span>&bull;</span>
                                            <span>{{ number_format($area['total_sqft']) }} sqft total</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="text-lg font-bold text-zinc-900 dark:text-white">
                                    {{ number_format($area['total_hours'], 1) }} hrs
                                </div>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                    Labor: {{ number_format($area['labor_hours'], 1) }} + Cleanup: {{ number_format($area['cleanup_hours'], 1) }}
                                </div>
                            </div>
                        </div>

                        {{-- Area Content (Surfaces) --}}
                        @if ($expandedAreas[$area['id']] ?? false)
                            <div class="border-t border-zinc-200 dark:border-zinc-700 p-4 print:block">
                                {{-- Substrate Warning --}}
                                @if ($area['substrate'])
                                    <div class="mb-4 p-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg print:rounded-none">
                                        <div class="flex items-start gap-2">
                                            <flux:icon name="exclamation-triangle" class="w-5 h-5 text-amber-600 shrink-0 mt-0.5" />
                                            <div>
                                                <p class="font-semibold text-amber-800 dark:text-amber-200">Surface Condition: {{ $area['substrate'] }}</p>
                                                @if ($area['substrate_prep'] && $area['substrate_prep'] !== 'None')
                                                    <p class="text-sm text-amber-700 dark:text-amber-300">Prep Required: {{ $area['substrate_prep'] }}</p>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                @endif

                                {{-- Area Notes --}}
                                @if ($area['notes'])
                                    <div class="mb-4 p-3 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg print:rounded-none">
                                        <p class="text-sm text-blue-800 dark:text-blue-200">
                                            <span class="font-semibold">Note:</span> {{ $area['notes'] }}
                                        </p>
                                    </div>
                                @endif

                                {{-- Surface List --}}
                                <div class="space-y-3">
                                    @foreach ($area['surfaces'] as $surface)
                                        <div
                                            class="flex items-start gap-4 p-3 border border-zinc-100 dark:border-zinc-700 rounded-lg print:rounded-none print:border-zinc-300"
                                            wire:key="surface-{{ $surface['id'] }}"
                                        >
                                            {{-- Checkbox placeholder --}}
                                            <div class="mt-1 print:hidden">
                                                <div class="w-6 h-6 border-2 border-zinc-300 dark:border-zinc-600 rounded"></div>
                                            </div>
                                            <div class="hidden print:block mt-1">
                                                <div class="w-5 h-5 border-2 border-black"></div>
                                            </div>

                                            {{-- Surface Details --}}
                                            <div class="flex-1 min-w-0">
                                                <div class="flex items-center gap-2 flex-wrap">
                                                    <span class="font-semibold text-zinc-900 dark:text-white">{{ $surface['name'] }}</span>
                                                    <span class="text-zinc-500 dark:text-zinc-400">-</span>
                                                    <span class="text-zinc-700 dark:text-zinc-300">
                                                        {{ number_format($surface['quantity']) }} {{ $surface['unit'] }}
                                                    </span>
                                                </div>

                                                <div class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                                                    <span class="inline-flex items-center gap-1">
                                                        <span class="font-medium">{{ $surface['coats'] }} {{ $surface['coats'] === 1 ? 'coat' : 'coats' }}</span>
                                                    </span>
                                                    <span class="mx-2">&bull;</span>
                                                    <span>{{ $surface['application_method'] }}</span>
                                                </div>

                                                {{-- Paint Product Info --}}
                                                @if ($surface['paint_product'])
                                                    <div class="mt-2 text-sm">
                                                        <span class="inline-flex items-center gap-1 px-2 py-1 bg-indigo-50 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300 rounded print:bg-transparent print:border print:border-indigo-500">
                                                            <flux:icon name="paint-brush" class="w-4 h-4" />
                                                            {{ $surface['paint_product']['full_name'] }}
                                                            @if ($surface['paint_product']['sheen'])
                                                                - {{ $surface['paint_product']['sheen'] }}
                                                            @endif
                                                        </span>
                                                    </div>
                                                @elseif ($surface['paint_brand'] && $surface['paint_type'])
                                                    <div class="mt-2 text-sm">
                                                        <span class="inline-flex items-center gap-1 px-2 py-1 bg-indigo-50 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300 rounded print:bg-transparent print:border print:border-indigo-500">
                                                            <flux:icon name="paint-brush" class="w-4 h-4" />
                                                            {{ $surface['paint_brand'] }} {{ $surface['paint_type'] }}
                                                        </span>
                                                    </div>
                                                @endif

                                                {{-- Prep Notes --}}
                                                @if (count($surface['prep_notes']) > 0)
                                                    <div class="mt-2 text-sm text-amber-700 dark:text-amber-300">
                                                        <span class="font-medium">Prep:</span> {{ implode(', ', $surface['prep_notes']) }}
                                                    </div>
                                                @endif
                                            </div>

                                            {{-- Hours --}}
                                            <div class="text-right shrink-0">
                                                <div class="font-semibold text-zinc-900 dark:text-white">
                                                    {{ number_format($surface['labor_hours'], 1) }} hrs
                                                </div>
                                            </div>
                                        </div>
                                    @endforeach

                                    {{-- Addons (Nested Areas) --}}
                                    @if (count($area['addons']) > 0)
                                        <div class="mt-4 pt-3 border-t border-dashed border-zinc-200 dark:border-zinc-700">
                                            <p class="text-sm font-semibold text-zinc-500 dark:text-zinc-400 mb-2">Included Addons</p>
                                            @foreach ($area['addons'] as $addon)
                                                <div class="flex items-center gap-4 p-2">
                                                    <div class="w-6 h-6 border-2 border-zinc-300 dark:border-zinc-600 rounded print:border-black"></div>
                                                    <span class="text-zinc-700 dark:text-zinc-300">{{ $addon['name'] }}</span>
                                                    @if ($addon['labor_hours'] > 0)
                                                        <span class="ml-auto text-sm font-medium text-zinc-600 dark:text-zinc-400">
                                                            {{ number_format($addon['labor_hours'], 1) }} hrs
                                                        </span>
                                                    @endif
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>

            {{-- Job Totals --}}
            <div class="mt-6 bg-zinc-900 dark:bg-zinc-950 text-white rounded-lg p-6 print:bg-white print:text-black print:border-2 print:border-black print:rounded-none">
                <h3 class="text-lg font-bold mb-4 print:text-black">Job Totals</h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div>
                        <div class="text-sm text-zinc-400 print:text-zinc-600">Labor Hours</div>
                        <div class="text-2xl font-bold">{{ number_format($this->jobTotals['labor_hours'], 1) }}</div>
                    </div>
                    <div>
                        <div class="text-sm text-zinc-400 print:text-zinc-600">Cleanup Hours</div>
                        <div class="text-2xl font-bold">{{ number_format($this->jobTotals['cleanup_hours'], 1) }}</div>
                    </div>
                    <div>
                        <div class="text-sm text-zinc-400 print:text-zinc-600">Total Hours</div>
                        <div class="text-2xl font-bold text-green-400 print:text-black">{{ number_format($this->jobTotals['total_hours'], 1) }}</div>
                    </div>
                    <div>
                        <div class="text-sm text-zinc-400 print:text-zinc-600">Areas</div>
                        <div class="text-2xl font-bold">{{ $this->jobTotals['area_count'] }}</div>
                    </div>
                </div>
            </div>

            {{-- Materials Checklist --}}
            @if (count($this->materialsNeeded) > 0)
                <div class="mt-6 bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 print:border-2 print:border-black print:rounded-none">
                    <h3 class="text-lg font-bold text-zinc-900 dark:text-white mb-4">Materials Needed</h3>
                    <div class="space-y-3">
                        @foreach ($this->materialsNeeded as $material)
                            <div class="flex items-start gap-4 p-3 bg-zinc-50 dark:bg-zinc-700/50 rounded-lg print:rounded-none print:bg-zinc-100">
                                <div class="w-6 h-6 border-2 border-zinc-300 dark:border-zinc-600 rounded print:border-black shrink-0 mt-0.5"></div>
                                <div class="flex-1">
                                    <div class="font-semibold text-zinc-900 dark:text-white">
                                        {{ $material['brand'] }} {{ $material['name'] }}
                                        @if ($material['sheen'])
                                            - {{ $material['sheen'] }}
                                        @endif
                                    </div>
                                    <div class="text-sm text-zinc-500 dark:text-zinc-400">
                                        Approx. {{ ceil($material['gallons']) }} {{ ceil($material['gallons']) === 1 ? 'gallon' : 'gallons' }}
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Special Instructions --}}
            @if ($this->projectInfo['notes'])
                <div class="mt-6 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-6 print:border-2 print:border-black print:rounded-none print:bg-yellow-50">
                    <h3 class="text-lg font-bold text-yellow-800 dark:text-yellow-200 mb-2">
                        <flux:icon name="clipboard-document-list" class="w-5 h-5 inline mr-1" />
                        Special Instructions
                    </h3>
                    <p class="text-yellow-700 dark:text-yellow-300 whitespace-pre-wrap">{{ $this->projectInfo['notes'] }}</p>
                </div>
            @endif

            {{-- Print Footer --}}
            <div class="hidden print:block mt-8 pt-4 border-t-2 border-black text-center text-sm text-zinc-600">
                <p>Work Order: {{ $this->projectInfo['estimate_number'] }} | Generated: {{ now()->format('M j, Y g:i A') }}</p>
            </div>

        @else
            {{-- FINANCIALS TAB --}}

            {{-- Warning Notice --}}
            <flux:callout variant="warning" class="mb-6">
                <flux:icon name="lock-closed" class="w-5 h-5" />
                <div>
                    <strong>For Subcontractor Owner Only</strong> - This data shows true costs and painter pay. Do not share with crew.
                </div>
            </flux:callout>

            {{-- Materials & Paint Profit Section --}}
            <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 mb-6">
                <h3 class="text-lg font-bold text-zinc-900 dark:text-white mb-4">
                    <flux:icon name="paint-brush" class="w-5 h-5 inline mr-1" />
                    Materials & Paint Profit
                </h3>

                @if (count($this->materialsProfit['items']) > 0)
                    <div class="overflow-x-auto mb-4">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                    <th class="text-left py-2 px-3 font-semibold text-zinc-600 dark:text-zinc-400">Paint Product</th>
                                    <th class="text-right py-2 px-3 font-semibold text-zinc-600 dark:text-zinc-400">Qty</th>
                                    <th class="text-right py-2 px-3 font-semibold text-zinc-600 dark:text-zinc-400">Charged/gal</th>
                                    <th class="text-right py-2 px-3 font-semibold text-zinc-600 dark:text-zinc-400">Your Cost/gal</th>
                                    <th class="text-right py-2 px-3 font-semibold text-zinc-600 dark:text-zinc-400">Charged</th>
                                    <th class="text-right py-2 px-3 font-semibold text-zinc-600 dark:text-zinc-400">Your Cost</th>
                                    <th class="text-right py-2 px-3 font-semibold text-zinc-600 dark:text-zinc-400">Profit</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($this->materialsProfit['items'] as $material)
                                    <tr class="border-b border-zinc-100 dark:border-zinc-700/50" wire:key="mat-{{ $material['product_id'] }}">
                                        <td class="py-2 px-3 text-zinc-900 dark:text-white">
                                            {{ $material['brand'] }} {{ $material['name'] }}
                                            @if ($material['sheen'])
                                                <span class="text-zinc-500">- {{ $material['sheen'] }}</span>
                                            @endif
                                        </td>
                                        <td class="py-2 px-3 text-right text-zinc-700 dark:text-zinc-300">{{ (int) $material['gallons'] }} gal</td>
                                        <td class="py-2 px-3 text-right text-zinc-700 dark:text-zinc-300">${{ number_format($material['catalog_price'], 2) }}</td>
                                        <td class="py-2 px-3 text-right">
                                            @if ($material['product_id'])
                                                <div class="flex items-center justify-end gap-1">
                                                    <span class="text-zinc-400">$</span>
                                                    <input
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        wire:model.live.debounce.500ms="actualPaintCosts.{{ $material['product_id'] }}"
                                                        placeholder="{{ number_format($material['catalog_price'], 2) }}"
                                                        class="w-20 text-right text-sm border border-zinc-300 dark:border-zinc-600 rounded px-2 py-1 bg-white dark:bg-zinc-700 text-zinc-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                                    />
                                                </div>
                                            @else
                                                <span class="text-zinc-400">-</span>
                                            @endif
                                        </td>
                                        <td class="py-2 px-3 text-right text-zinc-700 dark:text-zinc-300">${{ number_format($material['catalog_total'], 2) }}</td>
                                        <td class="py-2 px-3 text-right text-zinc-700 dark:text-zinc-300">
                                            @if ($material['actual_total'] !== null)
                                                ${{ number_format($material['actual_total'], 2) }}
                                            @else
                                                <span class="text-zinc-400">-</span>
                                            @endif
                                        </td>
                                        <td class="py-2 px-3 text-right font-medium {{ $material['profit'] !== null ? ($material['profit'] >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400') : 'text-zinc-400' }}">
                                            @if ($material['profit'] !== null)
                                                {{ $material['profit'] >= 0 ? '+' : '' }}${{ number_format($material['profit'], 2) }}
                                            @else
                                                -
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                {{-- Recycle Fee row --}}
                                <tr class="border-b border-zinc-100 dark:border-zinc-700/50">
                                    <td class="py-2 px-3 text-zinc-700 dark:text-zinc-300">Recycle Fee</td>
                                    <td class="py-2 px-3 text-right text-zinc-700 dark:text-zinc-300">{{ ceil($this->materialsWithCosts['total_gallons']) }} gal</td>
                                    <td class="py-2 px-3 text-right text-zinc-700 dark:text-zinc-300">${{ number_format($this->materialsWithCosts['recycle_fee_rate'], 2) }}</td>
                                    <td class="py-2 px-3 text-right text-zinc-400">-</td>
                                    <td class="py-2 px-3 text-right text-zinc-700 dark:text-zinc-300">${{ number_format($this->materialsWithCosts['total_recycle_fee'], 2) }}</td>
                                    <td class="py-2 px-3 text-right text-zinc-400">-</td>
                                    <td class="py-2 px-3 text-right text-zinc-400">-</td>
                                </tr>
                                {{-- Sundries row --}}
                                <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                    <td class="py-2 px-3 text-zinc-700 dark:text-zinc-300">
                                        Sundries
                                        <span class="text-xs text-zinc-500">({{ number_format($this->sundriesBreakdown['labor_hours'], 1) }} hrs × ${{ number_format($this->sundriesBreakdown['cost_per_hour'], 2) }}/hr)</span>
                                    </td>
                                    <td class="py-2 px-3 text-right text-zinc-400">-</td>
                                    <td class="py-2 px-3 text-right text-zinc-400">-</td>
                                    <td class="py-2 px-3 text-right text-zinc-400">-</td>
                                    <td class="py-2 px-3 text-right text-zinc-700 dark:text-zinc-300">${{ number_format($this->sundriesBreakdown['total_cost'], 2) }}</td>
                                    <td class="py-2 px-3 text-right text-zinc-400">-</td>
                                    <td class="py-2 px-3 text-right text-zinc-400">-</td>
                                </tr>
                            </tbody>
                            <tfoot>
                                <tr class="bg-zinc-50 dark:bg-zinc-700/50">
                                    <td colspan="4" class="py-3 px-3 font-bold text-zinc-900 dark:text-white">Total Materials & Sundries</td>
                                    <td class="py-3 px-3 text-right font-bold text-zinc-900 dark:text-white">${{ number_format($this->materialsWithCosts['total_materials_cost'] + $this->sundriesBreakdown['total_cost'], 2) }}</td>
                                    <td class="py-3 px-3 text-right font-bold text-zinc-900 dark:text-white">
                                        @if ($this->materialsProfit['has_actuals'])
                                            ${{ number_format($this->materialsProfit['total_actual_cost'], 2) }}
                                        @else
                                            -
                                        @endif
                                    </td>
                                    <td class="py-3 px-3 text-right font-bold {{ $this->materialsProfit['total_profit'] !== null ? ($this->materialsProfit['total_profit'] >= 0 ? 'text-green-600 dark:text-green-400' : 'text-red-600 dark:text-red-400') : 'text-zinc-400' }}">
                                        @if ($this->materialsProfit['total_profit'] !== null)
                                            {{ $this->materialsProfit['total_profit'] >= 0 ? '+' : '' }}${{ number_format($this->materialsProfit['total_profit'], 2) }}
                                        @else
                                            -
                                        @endif
                                    </td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @else
                    <div class="text-center py-8 text-zinc-500 dark:text-zinc-400">
                        No paint products assigned to this estimate.
                    </div>
                @endif
            </div>

            {{-- Individual Painters Section --}}
            <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 mb-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold text-zinc-900 dark:text-white">
                        <flux:icon name="user-group" class="w-5 h-5 inline mr-1" />
                        Individual Painters
                    </h3>
                    <flux:button variant="primary" size="sm" wire:click="addPainter" icon="plus">
                        Add Painter
                    </flux:button>
                </div>

                @if (count($this->painters) > 0)
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                    <th class="text-left py-2 px-3 font-semibold text-zinc-600 dark:text-zinc-400">Name</th>
                                    <th class="text-right py-2 px-3 font-semibold text-zinc-600 dark:text-zinc-400">Rate/hr</th>
                                    <th class="text-right py-2 px-3 font-semibold text-zinc-600 dark:text-zinc-400">Hours</th>
                                    <th class="text-right py-2 px-3 font-semibold text-zinc-600 dark:text-zinc-400">Pay</th>
                                    <th class="py-2 px-3 w-10"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($this->paintersBreakdown['painters'] as $painter)
                                    <tr class="border-b border-zinc-100 dark:border-zinc-700/50" wire:key="painter-{{ $painter['index'] }}">
                                        <td class="py-2 px-3">
                                            <input
                                                type="text"
                                                wire:model.live.debounce.500ms="painters.{{ $painter['index'] }}.name"
                                                placeholder="Painter name"
                                                class="w-full text-sm border border-zinc-300 dark:border-zinc-600 rounded px-2 py-1 bg-white dark:bg-zinc-700 text-zinc-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                            />
                                        </td>
                                        <td class="py-2 px-3">
                                            <div class="flex items-center justify-end gap-1">
                                                <span class="text-zinc-400">$</span>
                                                <input
                                                    type="number"
                                                    step="0.50"
                                                    min="0"
                                                    wire:model.live.debounce.500ms="painters.{{ $painter['index'] }}.hourly_rate"
                                                    class="w-20 text-right text-sm border border-zinc-300 dark:border-zinc-600 rounded px-2 py-1 bg-white dark:bg-zinc-700 text-zinc-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                                />
                                            </div>
                                        </td>
                                        <td class="py-2 px-3">
                                            <input
                                                type="number"
                                                step="0.5"
                                                min="0"
                                                wire:model.live.debounce.500ms="painters.{{ $painter['index'] }}.hours"
                                                placeholder="0"
                                                class="w-20 text-right text-sm border border-zinc-300 dark:border-zinc-600 rounded px-2 py-1 bg-white dark:bg-zinc-700 text-zinc-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500 ml-auto block"
                                            />
                                        </td>
                                        <td class="py-2 px-3 text-right text-zinc-900 dark:text-white font-medium">
                                            ${{ number_format($painter['base_pay'], 2) }}
                                        </td>
                                        <td class="py-2 px-3">
                                            <flux:button variant="ghost" size="sm" wire:click="removePainter({{ $painter['index'] }})" icon="x-mark" class="text-red-500 hover:text-red-700" />
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot>
                                <tr class="bg-zinc-50 dark:bg-zinc-700/50 border-b border-zinc-200 dark:border-zinc-600">
                                    <td class="py-3 px-3 font-bold text-zinc-900 dark:text-white">Crew Wages</td>
                                    <td class="py-3 px-3"></td>
                                    <td class="py-3 px-3 text-right font-bold text-zinc-900 dark:text-white">{{ number_format($this->paintersBreakdown['total_hours'], 1) }} hrs</td>
                                    <td class="py-3 px-3 text-right font-bold text-zinc-900 dark:text-white">${{ number_format($this->paintersBreakdown['total_base_pay'], 2) }}</td>
                                    <td class="py-3 px-3"></td>
                                </tr>
                                <tr class="bg-amber-50 dark:bg-amber-900/20 border-b border-zinc-200 dark:border-zinc-600">
                                    <td colspan="3" class="py-3 px-3 text-amber-800 dark:text-amber-300">
                                        <strong>+ Payroll Burden ({{ round(($this->burdenMultiplier - 1) * 100) }}%)</strong>
                                        <span class="text-xs ml-1">insurance, workers comp, payroll taxes</span>
                                    </td>
                                    <td class="py-3 px-3 text-right font-bold text-amber-800 dark:text-amber-300">${{ number_format($this->paintersBreakdown['total_burden_cost'], 2) }}</td>
                                    <td class="py-3 px-3"></td>
                                </tr>
                                <tr class="bg-zinc-100 dark:bg-zinc-600/50">
                                    <td colspan="3" class="py-3 px-3 font-bold text-zinc-900 dark:text-white text-base">Total Labor Cost</td>
                                    <td class="py-3 px-3 text-right font-bold text-zinc-900 dark:text-white text-base">${{ number_format($this->paintersBreakdown['total_labor_cost'], 2) }}</td>
                                    <td class="py-3 px-3"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @else
                    <div class="text-center py-8 text-zinc-500 dark:text-zinc-400">
                        <p>No painters added yet. Add your crew to track actual labor costs.</p>
                    </div>
                @endif
            </div>

            {{-- Painter Pay Calculator --}}
            <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 mb-6">
                <h3 class="text-lg font-bold text-zinc-900 dark:text-white mb-4">
                    <flux:icon name="banknotes" class="w-5 h-5 inline mr-1" />
                    Painter Pay Calculator
                </h3>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    {{-- Left Column: Rates --}}
                    <div class="space-y-3">
                        <h4 class="font-semibold text-zinc-700 dark:text-zinc-300 text-sm uppercase tracking-wider">Pay Rates ({{ ucfirst($this->painterPayBreakdown['scope']) }})</h4>

                        <div class="flex justify-between items-center py-2 border-b border-zinc-100 dark:border-zinc-700">
                            <span class="text-zinc-600 dark:text-zinc-400">Base Hourly Wage</span>
                            <span class="font-semibold text-zinc-900 dark:text-white">${{ number_format($this->painterPayBreakdown['base_hourly_wage'], 2) }}/hr</span>
                        </div>
                        <div class="flex justify-between items-center py-2 border-b border-zinc-100 dark:border-zinc-700">
                            <span class="text-zinc-600 dark:text-zinc-400">Payroll Burden</span>
                            <span class="font-semibold text-zinc-900 dark:text-white">+{{ $this->painterPayBreakdown['payroll_burden_percent'] }}%</span>
                        </div>
                        <div class="flex justify-between items-center py-2 bg-blue-50 dark:bg-blue-900/20 px-3 -mx-3 rounded">
                            <span class="font-semibold text-blue-800 dark:text-blue-200">Effective Rate (Loaded)</span>
                            <span class="font-bold text-blue-800 dark:text-blue-200">${{ number_format($this->painterPayBreakdown['effective_hourly_rate'], 2) }}/hr</span>
                        </div>
                    </div>

                    {{-- Right Column: Hours & Pay --}}
                    <div class="space-y-3">
                        <h4 class="font-semibold text-zinc-700 dark:text-zinc-300 text-sm uppercase tracking-wider">Hours & Costs</h4>

                        <div class="flex justify-between items-center py-2 border-b border-zinc-100 dark:border-zinc-700">
                            <span class="text-zinc-600 dark:text-zinc-400">Labor Hours</span>
                            <span class="font-semibold text-zinc-900 dark:text-white">{{ number_format($this->painterPayBreakdown['labor_hours'], 1) }} hrs</span>
                        </div>
                        <div class="flex justify-between items-center py-2 border-b border-zinc-100 dark:border-zinc-700">
                            <span class="text-zinc-600 dark:text-zinc-400">Cleanup Hours</span>
                            <span class="font-semibold text-zinc-900 dark:text-white">{{ number_format($this->painterPayBreakdown['cleanup_hours'], 1) }} hrs</span>
                        </div>
                        <div class="flex justify-between items-center py-2 border-b border-zinc-100 dark:border-zinc-700">
                            <span class="font-semibold text-zinc-700 dark:text-zinc-300">Total Hours</span>
                            <span class="font-bold text-zinc-900 dark:text-white">{{ number_format($this->painterPayBreakdown['total_hours'], 1) }} hrs</span>
                        </div>
                    </div>
                </div>

                {{-- Pay Summary --}}
                <div class="mt-6 pt-6 border-t border-zinc-200 dark:border-zinc-700">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg p-4">
                            <div class="text-sm text-green-700 dark:text-green-300">Painter Earns (Base)</div>
                            <div class="text-2xl font-bold text-green-800 dark:text-green-200">
                                ${{ number_format($this->painterPayBreakdown['painter_earns_base'], 2) }}
                            </div>
                            <div class="text-xs text-green-600 dark:text-green-400">
                                {{ number_format($this->painterPayBreakdown['total_hours'], 1) }} hrs × ${{ number_format($this->painterPayBreakdown['base_hourly_wage'], 2) }}
                            </div>
                        </div>
                        <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg p-4">
                            <div class="text-sm text-amber-700 dark:text-amber-300">Company Pays (Loaded)</div>
                            <div class="text-2xl font-bold text-amber-800 dark:text-amber-200">
                                ${{ number_format($this->painterPayBreakdown['company_pays_loaded'], 2) }}
                            </div>
                            <div class="text-xs text-amber-600 dark:text-amber-400">
                                {{ number_format($this->painterPayBreakdown['total_hours'], 1) }} hrs × ${{ number_format($this->painterPayBreakdown['effective_hourly_rate'], 2) }}
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Crew Size Calculator --}}
            <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 mb-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold text-zinc-900 dark:text-white">
                        <flux:icon name="user-group" class="w-5 h-5 inline mr-1" />
                        Crew Size Calculator
                    </h3>
                    <div class="flex items-center gap-2">
                        <span class="text-sm text-zinc-500 dark:text-zinc-400">Hours/Day:</span>
                        <flux:select wire:model.live="hoursPerDay" class="w-20">
                            <option value="6">6 hrs</option>
                            <option value="8">8 hrs</option>
                            <option value="10">10 hrs</option>
                        </flux:select>
                    </div>
                </div>

                <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-4">
                    Total labor cost stays the same regardless of crew size. More painters = faster completion.
                </p>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                <th class="text-left py-2 px-3 font-semibold text-zinc-600 dark:text-zinc-400">Painters</th>
                                <th class="text-center py-2 px-3 font-semibold text-zinc-600 dark:text-zinc-400">6-hr Day</th>
                                <th class="text-center py-2 px-3 font-semibold text-zinc-600 dark:text-zinc-400">8-hr Day</th>
                                <th class="text-center py-2 px-3 font-semibold text-zinc-600 dark:text-zinc-400">10-hr Day</th>
                                <th class="text-right py-2 px-3 font-semibold text-zinc-600 dark:text-zinc-400">Daily Pay Each</th>
                                <th class="text-right py-2 px-3 font-semibold text-zinc-600 dark:text-zinc-400">Total Job Pay</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($this->crewScheduleGrid['grid'] as $row)
                                <tr class="border-b border-zinc-100 dark:border-zinc-700/50 {{ $row['painters'] === $crewSize ? 'bg-blue-50 dark:bg-blue-900/20' : '' }}">
                                    <td class="py-3 px-3">
                                        <button
                                            wire:click="setCrewSize({{ $row['painters'] }})"
                                            class="flex items-center gap-2 {{ $row['painters'] === $crewSize ? 'text-blue-700 dark:text-blue-300 font-bold' : 'text-zinc-900 dark:text-white' }}"
                                        >
                                            @for ($i = 0; $i < $row['painters']; $i++)
                                                <flux:icon name="user" class="w-4 h-4" />
                                            @endfor
                                            <span>{{ $row['painters'] }} {{ $row['painters'] === 1 ? 'painter' : 'painters' }}</span>
                                        </button>
                                    </td>
                                    <td class="py-3 px-3 text-center text-zinc-700 dark:text-zinc-300">
                                        {{ $row['days_needed'][6] }} {{ $row['days_needed'][6] == 1 ? 'day' : 'days' }}
                                    </td>
                                    <td class="py-3 px-3 text-center text-zinc-700 dark:text-zinc-300">
                                        {{ $row['days_needed'][8] }} {{ $row['days_needed'][8] == 1 ? 'day' : 'days' }}
                                    </td>
                                    <td class="py-3 px-3 text-center text-zinc-700 dark:text-zinc-300">
                                        {{ $row['days_needed'][10] }} {{ $row['days_needed'][10] == 1 ? 'day' : 'days' }}
                                    </td>
                                    <td class="py-3 px-3 text-right text-zinc-700 dark:text-zinc-300">
                                        ${{ number_format($row['daily_pay_each'], 0) }}
                                    </td>
                                    <td class="py-3 px-3 text-right font-semibold text-zinc-900 dark:text-white">
                                        ${{ number_format($row['total_job_pay'], 0) }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                <div class="mt-4 p-3 bg-zinc-50 dark:bg-zinc-700/50 rounded-lg text-sm text-zinc-600 dark:text-zinc-400">
                    <strong>Note:</strong> Daily pay shown is base wage only. Add burden ({{ $this->painterPayBreakdown['payroll_burden_percent'] }}%) for true company cost per day.
                </div>
            </div>

            {{-- Job Profit Summary --}}
            <div class="bg-zinc-900 dark:bg-zinc-950 text-white rounded-lg p-6 mb-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold">
                        <flux:icon name="chart-bar" class="w-5 h-5 inline mr-1 text-white" />
                        Job Profit Summary
                    </h3>
                    @if (! $this->profitSummary['has_actuals'])
                        <span class="text-xs px-2 py-1 bg-zinc-700 rounded text-zinc-400">Using estimated costs</span>
                    @else
                        <span class="text-xs px-2 py-1 bg-blue-900 rounded text-blue-300">Using actual costs</span>
                    @endif
                </div>

                {{-- Job Price --}}
                <div class="flex justify-between items-center py-3 border-b-2 border-zinc-600 mb-2">
                    <span class="text-lg font-bold">Job Price</span>
                    <span class="text-2xl font-bold text-white">${{ number_format($this->profitSummary['job_price'], 2) }}</span>
                </div>
                <div class="text-xs text-zinc-500 mb-4">Company pays (loaded labor) + materials</div>

                <div class="space-y-2">
                    <div class="flex justify-between items-center py-2 border-b border-zinc-700">
                        <span class="text-zinc-300">Crew Wages</span>
                        <span class="text-red-300 font-medium">−${{ number_format($this->profitSummary['crew_wages'], 2) }}</span>
                    </div>
                    <div class="flex justify-between items-center py-2 border-b border-zinc-700">
                        <span class="text-zinc-300">Payroll Burden <span class="text-xs text-zinc-500">(insurance, WC, taxes)</span></span>
                        <span class="text-amber-400 font-medium">−${{ number_format($this->profitSummary['burden_cost'], 2) }}</span>
                    </div>
                    <div class="flex justify-between items-center py-2 border-b border-zinc-700">
                        <span class="text-zinc-300">Paint Cost{{ $this->materialsProfit['has_actuals'] ? ' (actual)' : ' (catalog)' }}</span>
                        <span class="text-red-300 font-medium">−${{ number_format($this->profitSummary['paint_cost'], 2) }}</span>
                    </div>
                    <div class="flex justify-between items-center py-2 border-b border-zinc-700">
                        <span class="text-zinc-300">Sundries</span>
                        <span class="text-red-300 font-medium">−${{ number_format($this->profitSummary['sundries_cost'], 2) }}</span>
                    </div>
                    <div class="flex justify-between items-center py-2 border-b border-zinc-700">
                        <span class="text-zinc-300">Recycle Fees</span>
                        <span class="text-red-300 font-medium">−${{ number_format($this->profitSummary['recycle_fee'], 2) }}</span>
                    </div>
                </div>

                {{-- Total Costs --}}
                <div class="flex justify-between items-center py-3 mt-2 border-t border-zinc-600">
                    <span class="font-bold text-zinc-400">Total Costs</span>
                    <span class="font-bold text-zinc-300">−${{ number_format($this->profitSummary['total_costs'], 2) }}</span>
                </div>

                {{-- Your Profit --}}
                <div class="flex justify-between items-center py-3 border-t-2 border-zinc-500">
                    <span class="text-lg font-bold">Your Profit</span>
                    <div class="text-right">
                        <span class="text-2xl font-bold {{ $this->profitSummary['gross_profit'] >= 0 ? 'text-green-400' : 'text-red-400' }}">
                            {{ $this->profitSummary['gross_profit'] >= 0 ? '+' : '' }}${{ number_format($this->profitSummary['gross_profit'], 2) }}
                        </span>
                        <span class="text-sm text-zinc-500 ml-1">({{ number_format($this->profitSummary['gross_margin_percent'], 1) }}%)</span>
                    </div>
                </div>
            </div>

            {{-- Save Controls --}}
            <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 mb-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-bold text-zinc-900 dark:text-white">Save Profit Data</h3>
                        @if ($this->estimate->profit_snapshot)
                            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">
                                Last snapshot: {{ \Carbon\Carbon::parse($this->estimate->profit_snapshot['captured_at'])->format('M j, Y g:i A') }}
                                — Total COGS: ${{ number_format($this->estimate->profit_snapshot['total_costs'] ?? 0, 2) }}
                            </p>
                        @else
                            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">No snapshot saved yet.</p>
                        @endif
                    </div>
                    <div class="flex gap-2">
                        <flux:button variant="ghost" wire:click="saveActuals" icon="document-check">
                            Save Actuals
                        </flux:button>
                        <flux:button variant="primary" wire:click="saveSnapshot" icon="camera">
                            Save Snapshot
                        </flux:button>
                    </div>
                </div>
            </div>
        @endif
    </div>

    {{-- Print Styles --}}
    <style>
        @media print {
            body {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }
            .print\:block { display: block !important; }
            .print\:hidden { display: none !important; }
        }
    </style>
</div>
