<?php

use App\Models\CompanySetting;
use App\Models\EstimatePackage;
use App\Models\Job;
use App\Models\PaintProduct;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
    public function boot(): void
    {
        if (auth('crew')->check()) {
            return;
        }

        if (! auth()->user()?->can('financials.view_painter_breakdown')) {
            abort(403);
        }
    }

    public Job $job;

    public function getListeners(): array
    {
        $channelPrefix = auth('crew')->check() ? 'crew.job' : 'job';

        return [
            "echo-private:{$channelPrefix}.{$this->job->id},.task.updated" => 'onJobDataChanged',
            "echo-private:{$channelPrefix}.{$this->job->id},.stage.changed" => 'onJobDataChanged',
        ];
    }

    public function onJobDataChanged(): void
    {
        $this->job = $this->job->fresh([
            'estimate.areas.surfaces.productionRate',
            'estimate.areas.surfaces.paintProduct.brand',
            'estimate.areas.addons.nestedAreaTemplate',
            'estimate.areas.substrate',
            'estimate.deal.property',
            'estimate.deal.customer.user',
            'crews.crewProfile',
        ]);
    }

    public array $expandedAreas = [];

    public string $activeTab = 'work-order';

    public int $crewSize = 2;

    public float $hoursPerDay = 8;

    /** @var array<int|string, float> Actual paint costs keyed by paint_product_id */
    public array $actualPaintCosts = [];

    /** @var array<int, array{name: string, hourly_rate: float, hours: float, notes: string}> */
    public array $painters = [];

    public float $burdenMultiplier = 1.35;

    /** Package ID — auto-detected from selected/signed package */
    public ?int $viewingPackageId = null;

    public function mount(Job $job): void
    {
        $this->job = $job->load([
            'estimate.areas.surfaces.productionRate',
            'estimate.areas.surfaces.paintProduct.brand',
            'estimate.areas.addons.nestedAreaTemplate',
            'estimate.areas.substrate',
            'estimate.deal.property',
            'estimate.deal.customer.user',
            'crews.crewProfile',
        ]);

        $estimate = $this->job->estimate;

        // Expand all areas by default
        if ($estimate) {
            foreach ($estimate->areas as $area) {
                $this->expandedAreas[$area->id] = true;
            }
        }

        // Load existing actuals from estimate
        $this->actualPaintCosts = $estimate?->actual_paint_costs ?? [];
        $this->painters = $estimate?->actual_painters ?? [];

        $settings = CompanySetting::getInstance();
        $this->burdenMultiplier = (float) $settings->payroll_burden_multiplier;

        // Auto-detect the selected/signed package (no tier selector for jobs)
        if ($estimate) {
            $selectedPackage = $estimate->packages()->where('is_selected', true)->first()
                ?? $estimate->packages()->where('is_recommended', true)->first()
                ?? $estimate->packages()->orderBy('sort_order')->first();
            $this->viewingPackageId = $selectedPackage?->id;
        }
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

    /**
     * Get the set of included surface IDs and area IDs for the selected package.
     * Returns null if no package is selected (show everything).
     *
     * @return array{surface_ids: array<int>, area_ids: array<int>}|null
     */
    protected function getViewingPackageFilter(): ?array
    {
        if (! $this->viewingPackageId) {
            return null;
        }

        $package = EstimatePackage::find($this->viewingPackageId);
        if (! $package) {
            return null;
        }

        $items = $package->items()
            ->where('is_included', true)
            ->where('customer_approved', true)
            ->get();

        $surfaceIds = $items
            ->where('itemable_type', \App\Models\EstimateAreaSurface::class)
            ->pluck('itemable_id')
            ->toArray();

        $areaIds = $items
            ->where('itemable_type', \App\Models\EstimateArea::class)
            ->pluck('itemable_id')
            ->toArray();

        return [
            'surface_ids' => $surfaceIds,
            'area_ids' => $areaIds,
        ];
    }

    public function toggleArea(int $areaId): void
    {
        $this->expandedAreas[$areaId] = ! ($this->expandedAreas[$areaId] ?? false);
    }

    public function expandAll(): void
    {
        $estimate = $this->job->estimate;
        if ($estimate) {
            foreach ($estimate->areas as $area) {
                $this->expandedAreas[$area->id] = true;
            }
        }
    }

    public function collapseAll(): void
    {
        $this->expandedAreas = [];
    }

    public function addPainter(): void
    {
        $estimate = $this->job->estimate;
        $scope = $estimate?->project_type ?? 'interior';

        $this->painters[] = [
            'name' => '',
            'hourly_rate' => $estimate?->getOverrideBaseWage($scope) ?? CompanySetting::getInstance()->getHourlyWageForScope($scope),
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
        $estimate = $this->job->estimate;
        if (! $estimate) {
            return;
        }

        $estimate->actual_paint_costs = $this->actualPaintCosts;
        $estimate->actual_painters = $this->painters;
        $estimate->save();
    }

    public function saveSnapshot(): void
    {
        $this->saveActuals();

        $estimate = $this->job->estimate;
        if (! $estimate) {
            return;
        }

        $costs = $this->profitSummary;
        $estimate->profit_snapshot = array_merge($costs, [
            'captured_at' => now()->toIso8601String(),
            'painters' => $this->painters,
            'paint_costs' => $this->actualPaintCosts,
            'crew_bonus' => $this->crewBonusInfo,
        ]);
        $estimate->save();
    }

    #[Computed]
    public function jobTotals(): array
    {
        $estimate = $this->job->estimate;
        if (! $estimate) {
            return ['labor_hours' => 0, 'cleanup_hours' => 0, 'total_hours' => 0, 'area_count' => 0];
        }

        $laborHours = 0;
        $cleanupHours = 0;
        $areaCount = 0;

        $settings = CompanySetting::getInstance();
        $scope = $estimate->project_type ?? 'interior';
        $packageFilter = $this->getViewingPackageFilter();

        foreach ($estimate->areas as $area) {
            if ($area->is_fixed_price) {
                if ($packageFilter && ! in_array($area->id, $packageFilter['area_ids'])) {
                    continue;
                }
                $areaLaborHours = (float) ($area->estimated_labor_hours ?? 0);
            } else {
                $filteredSurfaces = $area->surfaces->where('is_included', true);
                if ($packageFilter) {
                    $filteredSurfaces = $filteredSurfaces->whereIn('id', $packageFilter['surface_ids']);
                }
                $areaLaborHours = (float) $filteredSurfaces->sum('labor_hours');

                if ($areaLaborHours === 0.0 && $packageFilter) {
                    continue;
                }
            }
            $laborHours += $areaLaborHours;

            $addonHours = (float) $area->addons->sum('labor_hours');
            $laborHours += $addonHours;

            $cleanupHours += $settings->calculateCleanupHours($areaLaborHours + $addonHours, $scope);
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
        $estimate = $this->job->estimate;
        if (! $estimate) {
            return [];
        }

        $materials = [];
        $customMaterials = [];
        $packageFilter = $this->getViewingPackageFilter();

        foreach ($estimate->areas as $area) {
            $filteredSurfaces = $area->surfaces->where('is_included', true);
            if ($packageFilter) {
                $filteredSurfaces = $filteredSurfaces->whereIn('id', $packageFilter['surface_ids']);
            }
            foreach ($filteredSurfaces as $surface) {
                if ($surface->is_custom && (float) ($surface->material_cost ?? 0) > 0) {
                    $customMaterials[] = [
                        'is_custom' => true,
                        'product' => null,
                        'brand' => 'Custom',
                        'name' => $surface->name,
                        'sheen' => null,
                        'gallons' => null,
                        'material_cost' => (float) $surface->material_cost,
                        'surfaces' => ["{$area->name} - {$surface->name}"],
                    ];

                    continue;
                }

                $paintProduct = $surface->getEffectivePaintProduct();

                if ($paintProduct) {
                    $key = $paintProduct->id;

                    if (! isset($materials[$key])) {
                        $materials[$key] = [
                            'is_custom' => false,
                            'product' => $paintProduct,
                            'sheen' => ucfirst($paintProduct->sheen),
                            'brand' => $paintProduct->brand?->name ?? 'Unknown',
                            'name' => $paintProduct->name,
                            'gallons' => 0,
                            'material_cost' => null,
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
                    $materials[$key]['surfaces'][] = "{$area->name} - {$surface->name}";
                } elseif ($surface->paint_brand && $surface->paint_type) {
                    $key = "{$surface->paint_brand}_{$surface->paint_type}";

                    if (! isset($materials[$key])) {
                        $materials[$key] = [
                            'is_custom' => false,
                            'product' => null,
                            'brand' => $surface->paint_brand_label,
                            'name' => $surface->paint_type_label,
                            'sheen' => null,
                            'gallons' => 0,
                            'material_cost' => null,
                            'surfaces' => [],
                        ];
                    }

                    $materials[$key]['gallons'] += (float) ($surface->paint_gallons ?? 1);
                    $materials[$key]['surfaces'][] = "{$area->name} - {$surface->name}";
                }
            }
        }

        return array_merge(array_values($materials), $customMaterials);
    }

    #[Computed]
    public function materialsWithCosts(): array
    {
        $estimate = $this->job->estimate;
        if (! $estimate) {
            return [
                'items' => [],
                'custom_materials' => [],
                'custom_material_cost' => 0,
                'total_gallons' => 0,
                'total_paint_cost' => 0,
                'recycle_fee_rate' => 0,
                'total_recycle_fee' => 0,
                'material_tax_rate' => 0,
                'material_tax_amount' => 0,
                'total_materials_cost' => 0,
            ];
        }

        $settings = CompanySetting::getInstance();
        $materials = [];
        $customMaterials = [];
        $totalGallons = 0;
        $totalPaintCost = 0;
        $packageFilter = $this->getViewingPackageFilter();

        foreach ($estimate->areas as $area) {
            $filteredSurfaces = $area->surfaces->where('is_included', true);
            if ($packageFilter) {
                $filteredSurfaces = $filteredSurfaces->whereIn('id', $packageFilter['surface_ids']);
            }
            foreach ($filteredSurfaces as $surface) {
                if ($surface->is_custom && (float) ($surface->material_cost ?? 0) > 0) {
                    $customMaterials[] = [
                        'surface_id' => $surface->id,
                        'area_name' => $area->name,
                        'surface_name' => $surface->name,
                        'material_cost' => (float) $surface->material_cost,
                    ];

                    continue;
                }

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
                } elseif ($surface->paint_brand && $surface->paint_type) {
                    $key = "{$surface->paint_brand}_{$surface->paint_type}";
                    $gallons = (float) ($surface->paint_gallons ?? 1);
                    $perGallon = $gallons > 0 ? (float) ($surface->paint_cost ?? 0) / $gallons : 0;

                    if (! isset($materials[$key])) {
                        $materials[$key] = [
                            'product' => null,
                            'legacy_key' => $key,
                            'brand' => $surface->paint_brand_label,
                            'name' => $surface->paint_type_label,
                            'sheen' => null,
                            'price_per_gallon' => round($perGallon, 2),
                            'gallons' => 0,
                            'paint_cost' => 0,
                            'surfaces' => [],
                        ];
                    }

                    $materials[$key]['gallons'] += $gallons;
                    $materials[$key]['paint_cost'] = round($materials[$key]['gallons'] * $materials[$key]['price_per_gallon'], 2);
                    $materials[$key]['surfaces'][] = "{$area->name} - {$surface->name}";
                }
            }
        }

        $materials = array_values($materials);

        foreach ($materials as $material) {
            $totalGallons += $material['gallons'];
            $totalPaintCost += $material['paint_cost'];
        }

        $customMaterialCost = 0;
        foreach ($customMaterials as $cm) {
            $customMaterialCost += $cm['material_cost'];
        }
        $totalPaintCost += $customMaterialCost;

        // Add fixed-price area material costs
        $fixedPriceMaterialCost = 0;
        foreach ($estimate->areas->where('is_fixed_price', true) as $area) {
            if ($packageFilter && ! in_array($area->id, $packageFilter['area_ids'])) {
                continue;
            }
            $fixedPriceMaterialCost += (float) ($area->estimated_material_cost ?? 0);
        }
        $totalPaintCost += $fixedPriceMaterialCost;

        $recycleFeeRate = (float) ($settings->recycle_fee_per_gallon);
        $totalRecycleFee = round($totalGallons * $recycleFeeRate, 2);

        $materialTaxRate = (float) ($settings->material_tax_rate ?? 0);
        $materialTaxAmount = round($totalPaintCost * ($materialTaxRate / 100), 2);

        return [
            'items' => $materials,
            'custom_materials' => $customMaterials,
            'custom_material_cost' => round($customMaterialCost, 2),
            'total_gallons' => $totalGallons,
            'total_paint_cost' => round($totalPaintCost, 2),
            'recycle_fee_rate' => $recycleFeeRate,
            'total_recycle_fee' => $totalRecycleFee,
            'material_tax_rate' => $materialTaxRate,
            'material_tax_amount' => $materialTaxAmount,
            'total_materials_cost' => round($totalPaintCost + $totalRecycleFee + $materialTaxAmount, 2),
        ];
    }

    #[Computed]
    public function sundriesBreakdown(): array
    {
        $estimate = $this->job->estimate;
        $scope = $estimate?->project_type ?? 'interior';
        $settings = CompanySetting::getInstance();
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
        $estimate = $this->job->estimate;
        $scope = $estimate?->project_type ?? 'interior';
        $settings = CompanySetting::getInstance();
        $jobTotals = $this->jobTotals;

        $baseHourlyWage = $estimate?->getOverrideBaseWage($scope) ?? $settings->getHourlyWageForScope($scope);
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
            $productId = $product ? (string) $product->id : ($material['legacy_key'] ?? null);
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
                'is_custom' => false,
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

        // Custom surface materials
        foreach ($this->materialsWithCosts['custom_materials'] as $cm) {
            $surfaceKey = 'custom_' . $cm['surface_id'];
            $actualCost = isset($this->actualPaintCosts[$surfaceKey])
                ? (float) $this->actualPaintCosts[$surfaceKey]
                : null;

            $items[] = [
                'product_id' => $surfaceKey,
                'is_custom' => true,
                'brand' => 'Custom',
                'name' => $cm['area_name'] . ' - ' . $cm['surface_name'],
                'sheen' => null,
                'gallons' => null,
                'catalog_price' => null,
                'actual_price' => $actualCost,
                'catalog_total' => round($cm['material_cost'], 2),
                'actual_total' => $actualCost !== null ? round($actualCost, 2) : null,
                'profit' => $actualCost !== null ? round($cm['material_cost'] - $actualCost, 2) : null,
            ];

            $totalCatalogCost += $cm['material_cost'];
            if ($actualCost !== null) {
                $totalActualCost += $actualCost;
            }
        }

        $hasActuals = collect($items)->filter(fn ($i) => $i['actual_price'] !== null || $i['actual_total'] !== null)->isNotEmpty();
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
        $estimate = $this->job->estimate;
        $materialsProfit = $this->materialsProfit;
        $paintersData = $this->paintersBreakdown;
        $painterPay = $this->painterPayBreakdown;
        $sundriesCost = $this->sundriesBreakdown['total_cost'];
        $recycleFeeCost = $this->materialsWithCosts['total_recycle_fee'];
        $materialTax = $this->materialsWithCosts['material_tax_amount'];

        // Calculate material COGS from surfaces
        $packageFilter = $this->getViewingPackageFilter();
        $materialCOGS = 0.0;
        if ($estimate) {
            foreach ($estimate->areas as $area) {
                if ($area->is_fixed_price) {
                    if ($packageFilter && ! in_array($area->id, $packageFilter['area_ids'])) {
                        continue;
                    }
                    $materialCOGS += (float) ($area->estimated_material_cost ?? 0);
                } else {
                    $filteredSurfaces = $area->surfaces->where('is_included', true);
                    if ($packageFilter) {
                        $filteredSurfaces = $filteredSurfaces->whereIn('id', $packageFilter['surface_ids']);
                    }
                    $materialCOGS += (float) $filteredSurfaces->sum('material_cost');
                }
            }
        }

        // Crew wages: actual painters if entered, otherwise company-wide calc
        $crewWages = count($this->painters) > 0
            ? $paintersData['total_base_pay']
            : $painterPay['painter_earns_base'];

        $burdenCost = count($this->painters) > 0
            ? $paintersData['total_burden_cost']
            : $painterPay['burden_amount'];

        $paintCost = $materialsProfit['has_actuals']
            ? $materialsProfit['total_actual_cost']
            : $materialCOGS;

        // Job price = total COGS
        $jobPrice = $painterPay['company_pays_loaded']
            + $materialCOGS
            + $sundriesCost
            + $recycleFeeCost
            + $materialTax;

        $totalCosts = $crewWages + $burdenCost + $paintCost + $sundriesCost + $recycleFeeCost + $materialTax;
        $grossProfit = $jobPrice - $totalCosts;
        $grossMarginPercent = $jobPrice > 0 ? ($grossProfit / $jobPrice) * 100 : 0;

        return [
            'job_price' => round($jobPrice, 2),
            'crew_wages' => round($crewWages, 2),
            'burden_cost' => round($burdenCost, 2),
            'paint_cost' => round($paintCost, 2),
            'sundries_cost' => round($sundriesCost, 2),
            'recycle_fee' => round($recycleFeeCost, 2),
            'material_tax' => round($materialTax, 2),
            'total_costs' => round($totalCosts, 2),
            'gross_profit' => round($grossProfit, 2),
            'gross_margin_percent' => round($grossMarginPercent, 1),
            'has_actuals' => count($this->painters) > 0 || $materialsProfit['has_actuals'],
        ];
    }

    #[Computed]
    public function crewBonusInfo(): ?array
    {
        $crew = $this->job->acceptedCrew()->with('crewProfile')->first();
        if (! $crew) {
            return null;
        }

        if (! $crew->crewProfile || ! ($crew->crewProfile->bonus_percent > 0)) {
            return null;
        }

        $estimate = $this->job->estimate;
        $customerPrice = $estimate?->selectedPackage?->total
            ?? $estimate?->packages()->where('is_selected', true)->value('total')
            ?? $this->profitSummary['job_price'];
        $bonusAmount = $customerPrice * ($crew->crewProfile->bonus_percent / 100);

        return [
            'crew_name' => $crew->name,
            'bonus_percent' => (float) $crew->crewProfile->bonus_percent,
            'bonus_amount' => round($bonusAmount, 2),
            'customer_price' => round($customerPrice, 2),
            'profit_after_bonus' => round($this->profitSummary['gross_profit'] - $bonusAmount, 2),
            'margin_after_bonus' => $customerPrice > 0
                ? round(($this->profitSummary['gross_profit'] - $bonusAmount) / $customerPrice * 100, 1)
                : 0,
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
        $estimate = $this->job->estimate;
        if (! $estimate) {
            return [];
        }

        $areas = [];
        $settings = CompanySetting::getInstance();
        $scope = $estimate->project_type ?? 'interior';
        $packageFilter = $this->getViewingPackageFilter();

        foreach ($estimate->areas as $area) {
            $addons = [];
            foreach ($area->addons as $addon) {
                $template = $addon->nestedAreaTemplate;
                $addons[] = [
                    'name' => $template ? __($template->name) : ($addon->name ?? __('Addon')),
                    'labor_hours' => (float) ($addon->labor_hours ?? 0),
                ];
            }

            if ($area->is_fixed_price) {
                if ($packageFilter && ! in_array($area->id, $packageFilter['area_ids'])) {
                    continue;
                }
                $laborHours = (float) ($area->estimated_labor_hours ?? 0);
                $materialCost = (float) ($area->estimated_material_cost ?? 0);
                $addonHours = collect($addons)->sum('labor_hours');
                $cleanupHours = $settings->calculateCleanupHours($laborHours + $addonHours, $scope);

                $areas[] = [
                    'id' => $area->id,
                    'name' => $area->name,
                    'scope_type' => ucfirst($area->scope_type ?? 'interior'),
                    'is_fixed_price' => true,
                    'template_name' => $area->nestedAreaTemplate ? __($area->nestedAreaTemplate->name) : null,
                    'dimensions' => null,
                    'total_sqft' => 0,
                    'surfaces' => [],
                    'addons' => $addons,
                    'labor_hours' => $laborHours,
                    'material_cost' => $materialCost,
                    'cleanup_hours' => $cleanupHours,
                    'total_hours' => $laborHours + $addonHours + $cleanupHours,
                    'notes' => $area->notes,
                    'substrate' => $area->substrate ? __($area->substrate->name) : null,
                    'substrate_prep' => $area->substrate?->prep_requirements,
                ];
            } else {
                $surfaces = [];
                $totalLaborHours = 0;

                $filteredSurfaces = $area->surfaces->where('is_included', true);
                if ($packageFilter) {
                    $filteredSurfaces = $filteredSurfaces->whereIn('id', $packageFilter['surface_ids']);
                }
                foreach ($filteredSurfaces->sortBy('sort_order') as $surface) {
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
                        'is_custom' => $surface->is_custom,
                        'quantity' => (float) $surface->quantity,
                        'unit' => $surface->unit ?? 'sqft',
                        'coats' => $surface->coats ?? 1,
                        'labor_hours' => (float) $surface->labor_hours,
                        'material_cost' => (float) $surface->material_cost,
                        'crew_payment' => $surface->getCrewPayment(),
                        'crew_rate' => $surface->getEffectiveCrewRate(),
                        'has_custom_crew_rate' => $surface->is_custom && $surface->crew_price !== null,
                        'application_method' => $surface->application_method_label,
                        'paint_product' => $paintProduct ? [
                            'full_name' => $paintProduct->full_name,
                            'sheen' => ucfirst($paintProduct->sheen ?? ''),
                            'coverage' => $paintProduct->getCoverage($surface->application_method ?? 'brush_roll'),
                        ] : null,
                        'paint_brand' => $surface->paint_brand_label,
                        'paint_type' => $surface->paint_type_label,
                        'substrate' => $substrate ? __($substrate->name) : null,
                        'prep_notes' => $prepNotes,
                    ];

                    $totalLaborHours += (float) $surface->labor_hours;
                }

                if ($packageFilter && empty($surfaces)) {
                    continue;
                }

                $addonHours = collect($addons)->sum('labor_hours');
                $cleanupHours = $settings->calculateCleanupHours($totalLaborHours + $addonHours, $scope);

                $areas[] = [
                    'id' => $area->id,
                    'name' => $area->name,
                    'scope_type' => ucfirst($area->scope_type ?? 'interior'),
                    'is_fixed_price' => false,
                    'dimensions' => $this->formatDimensions($area),
                    'total_sqft' => $this->calculateTotalSqft($area),
                    'surfaces' => $surfaces,
                    'addons' => $addons,
                    'labor_hours' => $totalLaborHours,
                    'cleanup_hours' => $cleanupHours,
                    'total_hours' => $totalLaborHours + $addonHours + $cleanupHours,
                    'notes' => $area->notes,
                    'substrate' => $area->substrate ? __($area->substrate->name) : null,
                    'substrate_prep' => $area->substrate?->prep_requirements,
                ];
            }
        }

        return $areas;
    }

    #[Computed]
    public function customerInfo(): array
    {
        $deal = $this->job->estimate?->deal ?? $this->job->deal;
        $customer = $deal?->customer;
        $property = $deal?->property;

        return [
            'name' => $customer?->user?->name ?? $customer?->full_name ?? 'Unknown Customer',
            'address' => $property ? $this->formatAddress($property) : 'No address on file',
            'property_type' => $property?->property_type ? ucfirst($property->property_type) : null,
        ];
    }

    #[Computed]
    public function projectInfo(): array
    {
        $estimate = $this->job->estimate;

        return [
            'job_number' => $this->job->job_number ?? "Job #{$this->job->id}",
            'estimate_number' => $estimate?->estimate_number,
            'title' => $estimate?->title ?? $this->job->title,
            'project_type' => ucfirst($estimate?->project_type ?? 'Interior'),
            'status' => $this->job->status_label ?? ucfirst($this->job->stage ?? 'Active'),
            'scheduled_date' => $this->job->scheduled_date?->format('M j, Y'),
            'foreman' => null,
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
                <flux:button variant="ghost" :href="auth('crew')->check() ? route('crew.jobs.show', $job) : route('jobs.show', $job)" icon="arrow-left" wire:navigate class="print:hidden">
                    <span class="hidden sm:inline">{{ __('Back to Job') }}</span>
                </flux:button>
                <div>
                    <flux:heading size="xl" class="print:text-lg">{{ __("Painter's Breakdown") }}</flux:heading>
                    <flux:text class="text-zinc-500">
                        {{ $this->projectInfo['job_number'] }}
                        @if ($this->projectInfo['estimate_number'])
                            <span class="text-zinc-400">&mdash;</span> {{ $this->projectInfo['estimate_number'] }}
                        @endif
                    </flux:text>
                </div>
            </div>
            <div class="flex items-center gap-2 print:hidden">
                @if ($activeTab === 'work-order')
                    <flux:button icon="printer" variant="ghost" onclick="window.print()">
                        <span class="hidden sm:inline">{{ __('Print') }}</span>
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
                {{ __('Work Order') }}
            </flux:button>
            <flux:button
                wire:click="setTab('financials')"
                :variant="$activeTab === 'financials' ? 'primary' : 'ghost'"
                icon="calculator"
            >
                {{ __('Financials') }}
            </flux:button>
        </div>

        @if ($activeTab === 'work-order')
            {{-- WORK ORDER TAB --}}

            {{-- Crew-Safe Notice --}}
            <flux:callout variant="info" class="mb-6 print:hidden">
                <flux:icon name="shield-check" class="w-5 h-5" />
                <div>
                    <strong>{{ __('Crew-Safe View') }}</strong> - {{ __('This page shows only the information painters need. No pricing or margin data.') }}
                </div>
            </flux:callout>

            {{-- Job Header Card --}}
            <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 mb-6 print:p-4 print:border-2 print:border-black print:rounded-none">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 print:gap-4">
                    <div>
                        <h3 class="text-sm font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider mb-2">{{ __('Customer') }}</h3>
                        <p class="text-lg font-bold text-zinc-900 dark:text-white">{{ $this->customerInfo['name'] }}</p>
                        <p class="text-zinc-600 dark:text-zinc-300">{{ $this->customerInfo['address'] }}</p>
                        @if ($this->customerInfo['property_type'])
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ $this->customerInfo['property_type'] }}</p>
                        @endif
                    </div>
                    <div>
                        <h3 class="text-sm font-semibold text-zinc-500 dark:text-zinc-400 uppercase tracking-wider mb-2">{{ __('Project Details') }}</h3>
                        <p class="text-lg font-bold text-zinc-900 dark:text-white">{{ $this->projectInfo['project_type'] }} {{ __('Painting') }}</p>
                        @if ($this->projectInfo['title'])
                            <p class="text-zinc-600 dark:text-zinc-300">{{ $this->projectInfo['title'] }}</p>
                        @endif
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">
                            {{ __('Status') }}: <span class="font-medium">{{ $this->projectInfo['status'] }}</span>
                        </p>
                        @if ($this->projectInfo['scheduled_date'])
                            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">
                                <flux:icon name="calendar" class="w-4 h-4 inline" />
                                {{ __('Scheduled') }}: {{ $this->projectInfo['scheduled_date'] }}
                            </p>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Expand/Collapse Controls --}}
            <div class="flex items-center justify-between mb-4 print:hidden">
                <flux:heading size="lg">{{ __('Areas to Paint') }}</flux:heading>
                <div class="flex gap-2">
                    <flux:button variant="ghost" size="sm" wire:click="expandAll">{{ __('Expand All') }}</flux:button>
                    <flux:button variant="ghost" size="sm" wire:click="collapseAll">{{ __('Collapse All') }}</flux:button>
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
                                            <span>{{ number_format($area['total_sqft']) }} {{ __('sqft total') }}</span>
                                        @endif
                                    </div>
                                </div>
                            </div>
                            <div class="text-right">
                                <div class="text-lg font-bold text-zinc-900 dark:text-white">
                                    {{ number_format($area['total_hours'], 1) }} hrs
                                </div>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ __('Labor') }}: {{ number_format($area['labor_hours'], 1) }} + {{ __('Cleanup') }}: {{ number_format($area['cleanup_hours'], 1) }}
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
                                                <p class="font-semibold text-amber-800 dark:text-amber-200">{{ __('Surface Condition') }}: {{ $area['substrate'] }}</p>
                                                @if ($area['substrate_prep'] && $area['substrate_prep'] !== 'None')
                                                    <p class="text-sm text-amber-700 dark:text-amber-300">{{ __('Prep Required') }}: {{ $area['substrate_prep'] }}</p>
                                                @endif
                                            </div>
                                        </div>
                                    </div>
                                @endif

                                {{-- Area Notes --}}
                                @if ($area['notes'])
                                    <div class="mb-4 p-3 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg print:rounded-none">
                                        <p class="text-sm text-blue-800 dark:text-blue-200">
                                            <span class="font-semibold">{{ __('Note') }}:</span> {{ $area['notes'] }}
                                        </p>
                                    </div>
                                @endif

                                {{-- Fixed-Price Area Summary --}}
                                @if ($area['is_fixed_price'] ?? false)
                                    <div class="p-4 bg-purple-50 dark:bg-purple-900/20 border border-purple-200 dark:border-purple-800 rounded-lg print:rounded-none">
                                        <div class="flex items-center gap-2 mb-2">
                                            <span class="px-2 py-0.5 text-xs font-medium bg-purple-100 text-purple-700 dark:bg-purple-900 dark:text-purple-300 rounded">{{ __('Fixed Price') }}</span>
                                            @if ($area['template_name'] ?? null)
                                                <span class="text-sm text-zinc-600 dark:text-zinc-400">{{ $area['template_name'] }}</span>
                                            @endif
                                        </div>
                                        <div class="grid grid-cols-2 gap-4 text-sm">
                                            <div>
                                                <span class="text-zinc-500 dark:text-zinc-400">{{ __('Labor') }}:</span>
                                                <span class="font-semibold text-zinc-900 dark:text-white">{{ number_format($area['labor_hours'], 1) }} {{ __('hrs') }}</span>
                                            </div>
                                            @if (($area['material_cost'] ?? 0) > 0)
                                                <div>
                                                    <span class="text-zinc-500 dark:text-zinc-400">{{ __('Materials') }}:</span>
                                                    <span class="font-semibold text-zinc-900 dark:text-white">${{ number_format($area['material_cost'], 2) }}</span>
                                                </div>
                                            @endif
                                        </div>
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
                                                    @if ($surface['is_custom'])
                                                        <span class="px-1.5 py-0.5 text-xs font-medium bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300 rounded">{{ __('Custom') }}</span>
                                                    @else
                                                        <span class="text-zinc-500 dark:text-zinc-400">-</span>
                                                        <span class="text-zinc-700 dark:text-zinc-300">
                                                            {{ number_format($surface['quantity']) }} {{ $surface['unit'] }}
                                                        </span>
                                                    @endif
                                                </div>

                                                @if ($surface['is_custom'])
                                                    <div class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                                                        @if ($surface['material_cost'] > 0)
                                                            <span class="font-medium">{{ __('Materials') }}: ${{ number_format($surface['material_cost'], 2) }}</span>
                                                        @endif
                                                    </div>
                                                @else
                                                    <div class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                                                        <span class="inline-flex items-center gap-1">
                                                            <span class="font-medium">{{ $surface['coats'] }} {{ $surface['coats'] === 1 ? __('coat') : __('coats') }}</span>
                                                        </span>
                                                        <span class="mx-2">&bull;</span>
                                                        <span>{{ $surface['application_method'] }}</span>
                                                    </div>
                                                @endif

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
                                                        <span class="font-medium">{{ __('Prep') }}:</span> {{ implode(', ', $surface['prep_notes']) }}
                                                    </div>
                                                @endif
                                            </div>

                                            {{-- Hours & Crew Payment --}}
                                            <div class="text-right shrink-0">
                                                <div class="font-semibold text-zinc-900 dark:text-white">
                                                    {{ number_format($surface['labor_hours'], 1) }} hrs
                                                </div>
                                                @if ($surface['has_custom_crew_rate'] ?? false)
                                                    <div class="text-xs text-amber-600 dark:text-amber-400">
                                                        ${{ number_format($surface['crew_rate'], 2) }}/hr = ${{ number_format($surface['crew_payment'], 2) }}
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                    @endforeach

                                    {{-- Addons (Nested Areas) --}}
                                    @if (count($area['addons']) > 0)
                                        <div class="mt-4 pt-3 border-t border-dashed border-zinc-200 dark:border-zinc-700">
                                            <p class="text-sm font-semibold text-zinc-500 dark:text-zinc-400 mb-2">{{ __('Included Addons') }}</p>
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
                <h3 class="text-lg font-bold mb-4 print:text-black">{{ __('Job Totals') }}</h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div>
                        <div class="text-sm text-zinc-400 print:text-zinc-600">{{ __('Labor Hours') }}</div>
                        <div class="text-2xl font-bold">{{ number_format($this->jobTotals['labor_hours'], 1) }}</div>
                    </div>
                    <div>
                        <div class="text-sm text-zinc-400 print:text-zinc-600">{{ __('Cleanup Hours') }}</div>
                        <div class="text-2xl font-bold">{{ number_format($this->jobTotals['cleanup_hours'], 1) }}</div>
                    </div>
                    <div>
                        <div class="text-sm text-zinc-400 print:text-zinc-600">{{ __('Total Hours') }}</div>
                        <div class="text-2xl font-bold text-green-400 print:text-black">{{ number_format($this->jobTotals['total_hours'], 1) }}</div>
                    </div>
                    <div>
                        <div class="text-sm text-zinc-400 print:text-zinc-600">{{ __('Areas') }}</div>
                        <div class="text-2xl font-bold">{{ $this->jobTotals['area_count'] }}</div>
                    </div>
                </div>
            </div>

            {{-- Materials Checklist --}}
            @if (count($this->materialsNeeded) > 0)
                <div class="mt-6 bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 print:border-2 print:border-black print:rounded-none">
                    <h3 class="text-lg font-bold text-zinc-900 dark:text-white mb-4">{{ __('Materials Needed') }}</h3>
                    <div class="space-y-3">
                        @foreach ($this->materialsNeeded as $material)
                            <div class="flex items-start gap-4 p-3 bg-zinc-50 dark:bg-zinc-700/50 rounded-lg print:rounded-none print:bg-zinc-100">
                                <div class="w-6 h-6 border-2 border-zinc-300 dark:border-zinc-600 rounded print:border-black shrink-0 mt-0.5"></div>
                                <div class="flex-1">
                                    @if ($material['is_custom'] ?? false)
                                        <div class="font-semibold text-zinc-900 dark:text-white">
                                            <span class="px-1.5 py-0.5 text-xs font-medium bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300 rounded mr-1">{{ __('Custom') }}</span>
                                            {{ $material['name'] }}
                                        </div>
                                        <div class="text-sm text-zinc-500 dark:text-zinc-400">
                                            {{ __('Material budget') }}: ${{ number_format($material['material_cost'], 2) }}
                                        </div>
                                    @else
                                        <div class="font-semibold text-zinc-900 dark:text-white">
                                            {{ $material['brand'] }} {{ $material['name'] }}
                                            @if ($material['sheen'])
                                                - {{ $material['sheen'] }}
                                            @endif
                                        </div>
                                        <div class="text-sm text-zinc-500 dark:text-zinc-400">
                                            {{ __('Approx.') }} {{ ceil($material['gallons']) }} {{ ceil($material['gallons']) === 1 ? __('gallon') : __('gallons') }}
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- Special Instructions --}}
            @if ($this->projectInfo['title'])
                @php $estimate = $this->job->estimate; @endphp
                @if ($estimate?->notes)
                    <div class="mt-6 bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-6 print:border-2 print:border-black print:rounded-none print:bg-yellow-50">
                        <h3 class="text-lg font-bold text-yellow-800 dark:text-yellow-200 mb-2">
                            <flux:icon name="clipboard-document-list" class="w-5 h-5 inline mr-1" />
                            {{ __('Special Instructions') }}
                        </h3>
                        <p class="text-yellow-700 dark:text-yellow-300 whitespace-pre-wrap">{{ $estimate->notes }}</p>
                    </div>
                @endif
            @endif

            {{-- Print Footer --}}
            <div class="hidden print:block mt-8 pt-4 border-t-2 border-black text-center text-sm text-zinc-600">
                <p>Work Order: {{ $this->projectInfo['job_number'] }} | Generated: {{ now()->format('M j, Y g:i A') }}</p>
            </div>

        @else
            {{-- FINANCIALS TAB --}}

            {{-- Warning Notice --}}
            <flux:callout variant="warning" class="mb-6">
                <flux:icon name="lock-closed" class="w-5 h-5" />
                <div>
                    <strong>{{ __('For Subcontractor Owner Only') }}</strong> - {{ __('This data shows true costs and painter pay. Do not share with crew.') }}
                </div>
            </flux:callout>

            {{-- Materials & Paint Profit Section --}}
            <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 mb-6">
                <h3 class="text-lg font-bold text-zinc-900 dark:text-white mb-4">
                    <flux:icon name="paint-brush" class="w-5 h-5 inline mr-1" />
                    {{ __('Materials & Paint Profit') }}
                </h3>

                @if (count($this->materialsProfit['items']) > 0)
                    <div class="overflow-x-auto mb-4">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                    <th class="text-left py-2 px-3 font-semibold text-zinc-600 dark:text-zinc-400">{{ __('Paint Product') }}</th>
                                    <th class="text-right py-2 px-3 font-semibold text-zinc-600 dark:text-zinc-400">{{ __('Qty') }}</th>
                                    <th class="text-right py-2 px-3 font-semibold text-zinc-600 dark:text-zinc-400">{{ __('Charged/gal') }}</th>
                                    <th class="text-right py-2 px-3 font-semibold text-zinc-600 dark:text-zinc-400">{{ __('Your Cost/gal') }}</th>
                                    <th class="text-right py-2 px-3 font-semibold text-zinc-600 dark:text-zinc-400">{{ __('Charged') }}</th>
                                    <th class="text-right py-2 px-3 font-semibold text-zinc-600 dark:text-zinc-400">{{ __('Your Cost') }}</th>
                                    <th class="text-right py-2 px-3 font-semibold text-zinc-600 dark:text-zinc-400">{{ __('Profit') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($this->materialsProfit['items'] as $material)
                                    <tr class="border-b border-zinc-100 dark:border-zinc-700/50" wire:key="mat-{{ $material['product_id'] }}">
                                        <td class="py-2 px-3 text-zinc-900 dark:text-white">
                                            @if ($material['is_custom'] ?? false)
                                                <span class="px-1.5 py-0.5 text-xs font-medium bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300 rounded mr-1">{{ __('Custom') }}</span>
                                                {{ $material['name'] }}
                                            @else
                                                {{ $material['brand'] }} {{ $material['name'] }}
                                                @if ($material['sheen'])
                                                    <span class="text-zinc-500">- {{ $material['sheen'] }}</span>
                                                @endif
                                            @endif
                                        </td>
                                        <td class="py-2 px-3 text-right text-zinc-700 dark:text-zinc-300">
                                            @if ($material['gallons'] !== null)
                                                {{ (int) $material['gallons'] }} gal
                                            @else
                                                <span class="text-zinc-400">{{ __('lump sum') }}</span>
                                            @endif
                                        </td>
                                        <td class="py-2 px-3 text-right text-zinc-700 dark:text-zinc-300">
                                            @if ($material['catalog_price'] !== null)
                                                ${{ number_format($material['catalog_price'], 2) }}
                                            @else
                                                <span class="text-zinc-400">-</span>
                                            @endif
                                        </td>
                                        <td class="py-2 px-3 text-right">
                                            @if ($material['product_id'])
                                                <div class="flex items-center justify-end gap-1">
                                                    <span class="text-zinc-400">$</span>
                                                    <input
                                                        type="number"
                                                        step="0.01"
                                                        min="0"
                                                        wire:model.live.debounce.500ms="actualPaintCosts.{{ $material['product_id'] }}"
                                                        placeholder="{{ $material['is_custom'] ? number_format($material['catalog_total'], 2) : number_format($material['catalog_price'], 2) }}"
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
                                    <td class="py-2 px-3 text-zinc-700 dark:text-zinc-300">{{ __('Recycle Fee') }}</td>
                                    <td class="py-2 px-3 text-right text-zinc-700 dark:text-zinc-300">{{ ceil($this->materialsWithCosts['total_gallons']) }} gal</td>
                                    <td class="py-2 px-3 text-right text-zinc-700 dark:text-zinc-300">${{ number_format($this->materialsWithCosts['recycle_fee_rate'], 2) }}</td>
                                    <td class="py-2 px-3 text-right text-zinc-400">-</td>
                                    <td class="py-2 px-3 text-right text-zinc-700 dark:text-zinc-300">${{ number_format($this->materialsWithCosts['total_recycle_fee'], 2) }}</td>
                                    <td class="py-2 px-3 text-right text-zinc-400">-</td>
                                    <td class="py-2 px-3 text-right text-zinc-400">-</td>
                                </tr>
                                {{-- Material Tax row --}}
                                @if ($this->materialsWithCosts['material_tax_rate'] > 0)
                                    <tr class="border-b border-zinc-100 dark:border-zinc-700/50">
                                        <td class="py-2 px-3 text-zinc-700 dark:text-zinc-300">
                                            {{ __('Material Tax') }}
                                            <span class="text-xs text-zinc-500">({{ number_format($this->materialsWithCosts['material_tax_rate'], 1) }}%)</span>
                                        </td>
                                        <td class="py-2 px-3 text-right text-zinc-400">-</td>
                                        <td class="py-2 px-3 text-right text-zinc-400">-</td>
                                        <td class="py-2 px-3 text-right text-zinc-400">-</td>
                                        <td class="py-2 px-3 text-right text-zinc-700 dark:text-zinc-300">${{ number_format($this->materialsWithCosts['material_tax_amount'], 2) }}</td>
                                        <td class="py-2 px-3 text-right text-zinc-400">-</td>
                                        <td class="py-2 px-3 text-right text-zinc-400">-</td>
                                    </tr>
                                @endif
                                {{-- Sundries row --}}
                                <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                    <td class="py-2 px-3 text-zinc-700 dark:text-zinc-300">
                                        {{ __('Sundries') }}
                                        <span class="text-xs text-zinc-500">({{ number_format($this->sundriesBreakdown['labor_hours'], 1) }} hrs x ${{ number_format($this->sundriesBreakdown['cost_per_hour'], 2) }}/hr)</span>
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
                                    <td colspan="4" class="py-3 px-3 font-bold text-zinc-900 dark:text-white">{{ __('Total Materials & Sundries') }}</td>
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
                        {{ __('No paint products assigned to this job.') }}
                    </div>
                @endif
            </div>

            {{-- Individual Painters Section --}}
            <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 mb-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold text-zinc-900 dark:text-white">
                        <flux:icon name="user-group" class="w-5 h-5 inline mr-1" />
                        {{ __('Individual Painters') }}
                    </h3>
                    <flux:button variant="primary" size="sm" wire:click="addPainter" icon="plus">
                        {{ __('Add Painter') }}
                    </flux:button>
                </div>

                @if (count($this->painters) > 0)
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                    <th class="text-left py-2 px-3 font-semibold text-zinc-600 dark:text-zinc-400">{{ __('Name') }}</th>
                                    <th class="text-right py-2 px-3 font-semibold text-zinc-600 dark:text-zinc-400">{{ __('Rate/hr') }}</th>
                                    <th class="text-right py-2 px-3 font-semibold text-zinc-600 dark:text-zinc-400">{{ __('Hours') }}</th>
                                    <th class="text-right py-2 px-3 font-semibold text-zinc-600 dark:text-zinc-400">{{ __('Pay') }}</th>
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
                                                placeholder="{{ __('Painter name') }}"
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
                                    <td class="py-3 px-3 font-bold text-zinc-900 dark:text-white">{{ __('Crew Wages') }}</td>
                                    <td class="py-3 px-3"></td>
                                    <td class="py-3 px-3 text-right font-bold text-zinc-900 dark:text-white">{{ number_format($this->paintersBreakdown['total_hours'], 1) }} hrs</td>
                                    <td class="py-3 px-3 text-right font-bold text-zinc-900 dark:text-white">${{ number_format($this->paintersBreakdown['total_base_pay'], 2) }}</td>
                                    <td class="py-3 px-3"></td>
                                </tr>
                                <tr class="bg-amber-50 dark:bg-amber-900/20 border-b border-zinc-200 dark:border-zinc-600">
                                    <td colspan="3" class="py-3 px-3 text-amber-800 dark:text-amber-300">
                                        <strong>+ {{ __('Payroll Burden') }} ({{ round(($this->burdenMultiplier - 1) * 100) }}%)</strong>
                                        <span class="text-xs ml-1">{{ __('insurance, workers comp, payroll taxes') }}</span>
                                    </td>
                                    <td class="py-3 px-3 text-right font-bold text-amber-800 dark:text-amber-300">${{ number_format($this->paintersBreakdown['total_burden_cost'], 2) }}</td>
                                    <td class="py-3 px-3"></td>
                                </tr>
                                <tr class="bg-zinc-100 dark:bg-zinc-600/50">
                                    <td colspan="3" class="py-3 px-3 font-bold text-zinc-900 dark:text-white text-base">{{ __('Total Labor Cost') }}</td>
                                    <td class="py-3 px-3 text-right font-bold text-zinc-900 dark:text-white text-base">${{ number_format($this->paintersBreakdown['total_labor_cost'], 2) }}</td>
                                    <td class="py-3 px-3"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                @else
                    <div class="text-center py-8 text-zinc-500 dark:text-zinc-400">
                        <p>{{ __('No painters added yet. Add your crew to track actual labor costs.') }}</p>
                    </div>
                @endif
            </div>

            {{-- Painter Pay Calculator --}}
            <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 mb-6">
                <h3 class="text-lg font-bold text-zinc-900 dark:text-white mb-4">
                    <flux:icon name="banknotes" class="w-5 h-5 inline mr-1" />
                    {{ __('Painter Pay Calculator') }}
                </h3>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    {{-- Left Column: Rates --}}
                    <div class="space-y-3">
                        <h4 class="font-semibold text-zinc-700 dark:text-zinc-300 text-sm uppercase tracking-wider">{{ __('Pay Rates') }} ({{ ucfirst($this->painterPayBreakdown['scope']) }})</h4>

                        <div class="flex justify-between items-center py-2 border-b border-zinc-100 dark:border-zinc-700">
                            <span class="text-zinc-600 dark:text-zinc-400">{{ __('Base Hourly Wage') }}</span>
                            <span class="font-semibold text-zinc-900 dark:text-white">${{ number_format($this->painterPayBreakdown['base_hourly_wage'], 2) }}/hr</span>
                        </div>
                        <div class="flex justify-between items-center py-2 border-b border-zinc-100 dark:border-zinc-700">
                            <span class="text-zinc-600 dark:text-zinc-400">{{ __('Payroll Burden') }}</span>
                            <span class="font-semibold text-zinc-900 dark:text-white">+{{ $this->painterPayBreakdown['payroll_burden_percent'] }}%</span>
                        </div>
                        <div class="flex justify-between items-center py-2 bg-blue-50 dark:bg-blue-900/20 px-3 -mx-3 rounded">
                            <span class="font-semibold text-blue-800 dark:text-blue-200">{{ __('Effective Rate (Loaded)') }}</span>
                            <span class="font-bold text-blue-800 dark:text-blue-200">${{ number_format($this->painterPayBreakdown['effective_hourly_rate'], 2) }}/hr</span>
                        </div>
                    </div>

                    {{-- Right Column: Hours & Pay --}}
                    <div class="space-y-3">
                        <h4 class="font-semibold text-zinc-700 dark:text-zinc-300 text-sm uppercase tracking-wider">{{ __('Hours & Costs') }}</h4>

                        <div class="flex justify-between items-center py-2 border-b border-zinc-100 dark:border-zinc-700">
                            <span class="text-zinc-600 dark:text-zinc-400">{{ __('Labor Hours') }}</span>
                            <span class="font-semibold text-zinc-900 dark:text-white">{{ number_format($this->painterPayBreakdown['labor_hours'], 1) }} {{ __('hrs') }}</span>
                        </div>
                        <div class="flex justify-between items-center py-2 border-b border-zinc-100 dark:border-zinc-700">
                            <span class="text-zinc-600 dark:text-zinc-400">{{ __('Cleanup Hours') }}</span>
                            <span class="font-semibold text-zinc-900 dark:text-white">{{ number_format($this->painterPayBreakdown['cleanup_hours'], 1) }} hrs</span>
                        </div>
                        <div class="flex justify-between items-center py-2 border-b border-zinc-100 dark:border-zinc-700">
                            <span class="font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Total Hours') }}</span>
                            <span class="font-bold text-zinc-900 dark:text-white">{{ number_format($this->painterPayBreakdown['total_hours'], 1) }} {{ __('hrs') }}</span>
                        </div>
                    </div>
                </div>

                {{-- Pay Summary --}}
                <div class="mt-6 pt-6 border-t border-zinc-200 dark:border-zinc-700">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg p-4">
                            <div class="text-sm text-green-700 dark:text-green-300">{{ __('Painter Earns (Base)') }}</div>
                            <div class="text-2xl font-bold text-green-800 dark:text-green-200">
                                ${{ number_format($this->painterPayBreakdown['painter_earns_base'], 2) }}
                            </div>
                            <div class="text-xs text-green-600 dark:text-green-400">
                                {{ number_format($this->painterPayBreakdown['total_hours'], 1) }} hrs x ${{ number_format($this->painterPayBreakdown['base_hourly_wage'], 2) }}
                            </div>
                        </div>
                        <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg p-4">
                            <div class="text-sm text-amber-700 dark:text-amber-300">{{ __('Company Pays (Loaded)') }}</div>
                            <div class="text-2xl font-bold text-amber-800 dark:text-amber-200">
                                ${{ number_format($this->painterPayBreakdown['company_pays_loaded'], 2) }}
                            </div>
                            <div class="text-xs text-amber-600 dark:text-amber-400">
                                {{ number_format($this->painterPayBreakdown['total_hours'], 1) }} hrs x ${{ number_format($this->painterPayBreakdown['effective_hourly_rate'], 2) }}
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
                        {{ __('Crew Size Calculator') }}
                    </h3>
                    <div class="flex items-center gap-2">
                        <span class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('Hours/Day') }}:</span>
                        <flux:select wire:model.live="hoursPerDay" class="w-20">
                            <option value="6">6 hrs</option>
                            <option value="8">8 hrs</option>
                            <option value="10">10 hrs</option>
                        </flux:select>
                    </div>
                </div>

                <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-4">
                    {{ __('Total labor cost stays the same regardless of crew size. More painters = faster completion.') }}
                </p>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-zinc-200 dark:border-zinc-700">
                                <th class="text-left py-2 px-3 font-semibold text-zinc-600 dark:text-zinc-400">{{ __('Painters') }}</th>
                                <th class="text-center py-2 px-3 font-semibold text-zinc-600 dark:text-zinc-400">{{ __('6-hr Day') }}</th>
                                <th class="text-center py-2 px-3 font-semibold text-zinc-600 dark:text-zinc-400">{{ __('8-hr Day') }}</th>
                                <th class="text-center py-2 px-3 font-semibold text-zinc-600 dark:text-zinc-400">{{ __('10-hr Day') }}</th>
                                <th class="text-right py-2 px-3 font-semibold text-zinc-600 dark:text-zinc-400">{{ __('Daily Pay Each') }}</th>
                                <th class="text-right py-2 px-3 font-semibold text-zinc-600 dark:text-zinc-400">{{ __('Total Job Pay') }}</th>
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
                                            <span>{{ $row['painters'] }} {{ $row['painters'] === 1 ? __('painter') : __('painters') }}</span>
                                        </button>
                                    </td>
                                    <td class="py-3 px-3 text-center text-zinc-700 dark:text-zinc-300">
                                        {{ $row['days_needed'][6] }} {{ $row['days_needed'][6] == 1 ? __('day') : __('days') }}
                                    </td>
                                    <td class="py-3 px-3 text-center text-zinc-700 dark:text-zinc-300">
                                        {{ $row['days_needed'][8] }} {{ $row['days_needed'][8] == 1 ? __('day') : __('days') }}
                                    </td>
                                    <td class="py-3 px-3 text-center text-zinc-700 dark:text-zinc-300">
                                        {{ $row['days_needed'][10] }} {{ $row['days_needed'][10] == 1 ? __('day') : __('days') }}
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
                    <strong>{{ __('Note') }}:</strong> {{ __('Daily pay shown is base wage only. Add burden (:percent%) for true company cost per day.', ['percent' => $this->painterPayBreakdown['payroll_burden_percent']]) }}
                </div>
            </div>

            {{-- Job Profit Summary --}}
            <div class="bg-zinc-900 dark:bg-zinc-950 text-white rounded-lg p-6 mb-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold">
                        <flux:icon name="chart-bar" class="w-5 h-5 inline mr-1 text-white" />
                        {{ __('Job Profit Summary') }}
                    </h3>
                    @if (! $this->profitSummary['has_actuals'])
                        <span class="text-xs px-2 py-1 bg-zinc-700 rounded text-zinc-400">{{ __('Using estimated costs') }}</span>
                    @else
                        <span class="text-xs px-2 py-1 bg-blue-900 rounded text-blue-300">{{ __('Using actual costs') }}</span>
                    @endif
                </div>

                {{-- Job Price --}}
                <div class="flex justify-between items-center py-3 border-b-2 border-zinc-600 mb-2">
                    <span class="text-lg font-bold">{{ __('Job Price') }}</span>
                    <span class="text-2xl font-bold text-white">${{ number_format($this->profitSummary['job_price'], 2) }}</span>
                </div>
                <div class="text-xs text-zinc-500 mb-4">{{ __('Company pays (loaded labor) + materials') }}</div>

                <div class="space-y-2">
                    <div class="flex justify-between items-center py-2 border-b border-zinc-700">
                        <span class="text-zinc-300">{{ __('Crew Wages') }}</span>
                        <span class="text-red-300 font-medium">-${{ number_format($this->profitSummary['crew_wages'], 2) }}</span>
                    </div>
                    <div class="flex justify-between items-center py-2 border-b border-zinc-700">
                        <span class="text-zinc-300">{{ __('Payroll Burden') }} <span class="text-xs text-zinc-500">({{ __('insurance, WC, taxes') }})</span></span>
                        <span class="text-amber-400 font-medium">-${{ number_format($this->profitSummary['burden_cost'], 2) }}</span>
                    </div>
                    <div class="flex justify-between items-center py-2 border-b border-zinc-700">
                        <span class="text-zinc-300">{{ __('Paint Cost') }}{{ $this->materialsProfit['has_actuals'] ? ' (' . __('actual') . ')' : ' (' . __('catalog') . ')' }}</span>
                        <span class="text-red-300 font-medium">-${{ number_format($this->profitSummary['paint_cost'], 2) }}</span>
                    </div>
                    <div class="flex justify-between items-center py-2 border-b border-zinc-700">
                        <span class="text-zinc-300">{{ __('Sundries') }}</span>
                        <span class="text-red-300 font-medium">-${{ number_format($this->profitSummary['sundries_cost'], 2) }}</span>
                    </div>
                    <div class="flex justify-between items-center py-2 border-b border-zinc-700">
                        <span class="text-zinc-300">{{ __('Recycle Fees') }}</span>
                        <span class="text-red-300 font-medium">-${{ number_format($this->profitSummary['recycle_fee'], 2) }}</span>
                    </div>
                    @if ($this->profitSummary['material_tax'] > 0)
                        <div class="flex justify-between items-center py-2 border-b border-zinc-700">
                            <span class="text-zinc-300">{{ __('Material Tax') }} <span class="text-xs text-zinc-500">({{ number_format($this->materialsWithCosts['material_tax_rate'], 1) }}%)</span></span>
                            <span class="text-red-300 font-medium">-${{ number_format($this->profitSummary['material_tax'], 2) }}</span>
                        </div>
                    @endif
                </div>

                {{-- Total Costs --}}
                <div class="flex justify-between items-center py-3 mt-2 border-t border-zinc-600">
                    <span class="font-bold text-zinc-400">{{ __('Total Costs') }}</span>
                    <span class="font-bold text-zinc-300">-${{ number_format($this->profitSummary['total_costs'], 2) }}</span>
                </div>

                {{-- Your Profit --}}
                <div class="flex justify-between items-center py-3 border-t-2 border-zinc-500">
                    <span class="text-lg font-bold">{{ __('Your Profit') }}</span>
                    <div class="text-right">
                        <span class="text-2xl font-bold {{ $this->profitSummary['gross_profit'] >= 0 ? 'text-green-400' : 'text-red-400' }}">
                            {{ $this->profitSummary['gross_profit'] >= 0 ? '+' : '' }}${{ number_format($this->profitSummary['gross_profit'], 2) }}
                        </span>
                        <span class="text-sm text-zinc-500 ml-1">({{ number_format($this->profitSummary['gross_margin_percent'], 1) }}%)</span>
                    </div>
                </div>

                @if ($this->crewBonusInfo)
                    {{-- Crew Lead Bonus --}}
                    <div class="mt-4 pt-4 border-t border-dashed border-zinc-600">
                        <div class="flex justify-between items-center py-2">
                            <span class="text-amber-300 font-semibold text-lg">
                                {{ __('Crew Lead Bonus') }}
                                <span class="text-sm text-zinc-400">({{ $this->crewBonusInfo['crew_name'] }} - {{ number_format($this->crewBonusInfo['bonus_percent'], 1) }}%)</span>
                            </span>
                            <span class="text-2xl font-bold text-green-400">${{ number_format($this->crewBonusInfo['bonus_amount'], 2) }}</span>
                        </div>

                        <div class="mt-3 p-3 rounded-lg bg-zinc-800/50 border border-zinc-700">
                            <div class="text-sm font-medium text-amber-300 mb-2">{{ __('How to Earn This Bonus:') }}</div>
                            <ul class="text-xs text-zinc-400 space-y-1.5 list-disc list-inside">
                                <li>{{ __('Actively use and engage with the job software throughout the project') }}</li>
                                <li>{{ __('Be active in the Job Chat - communicate with the team and customer') }}</li>
                                <li>{{ __('Upload, view, and manage team and customer photos and communications') }}</li>
                                <li>{{ __('Participate in Job Prep to help build a plan the team can follow') }}</li>
                                <li>{{ __('Follow the plan as outlined during prep') }}</li>
                                <li>{{ __('Do not promote personal business on the job site') }}</li>
                                <li>{{ __('Dress appropriately and maintain a professional appearance') }}</li>
                                <li>{{ __('Ensure all touchups are completed before job closeout') }}</li>
                            </ul>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Save Controls --}}
            <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 mb-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-lg font-bold text-zinc-900 dark:text-white">{{ __('Save Profit Data') }}</h3>
                        @php $estimate = $this->job->estimate; @endphp
                        @if ($estimate?->profit_snapshot)
                            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">
                                Last snapshot: {{ \Carbon\Carbon::parse($estimate->profit_snapshot['captured_at'])->format('M j, Y g:i A') }}
                                — Total COGS: ${{ number_format($estimate->profit_snapshot['total_costs'] ?? 0, 2) }}
                            </p>
                        @else
                            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">{{ __('No snapshot saved yet.') }}</p>
                        @endif
                    </div>
                    <div class="flex gap-2">
                        <flux:button variant="ghost" wire:click="saveActuals" icon="document-check">
                            {{ __('Save Actuals') }}
                        </flux:button>
                        <flux:button variant="primary" wire:click="saveSnapshot" icon="camera">
                            {{ __('Save Snapshot') }}
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
