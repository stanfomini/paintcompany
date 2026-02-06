<?php

namespace App\Http\Controllers;

use App\Models\CompanySetting;
use App\Models\Estimate;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;

class PainterBreakdownExportController extends Controller
{
    public function workOrder(Estimate $estimate): Response
    {
        $estimate->load([
            'areas.surfaces.productionRate',
            'areas.surfaces.paintProduct.brand',
            'areas.addons.nestedAreaTemplate',
            'areas.substrate',
            'deal.property',
            'deal.customer.user',
        ]);

        $settings = CompanySetting::getInstance();
        $scope = $estimate->project_type ?? 'interior';

        $areaBreakdowns = $this->getAreaBreakdowns($estimate, $settings, $scope);
        $jobTotals = $this->getJobTotals($estimate, $settings, $scope);
        $materialsNeeded = $this->getMaterialsNeeded($estimate);
        $customerInfo = $this->getCustomerInfo($estimate);
        $projectInfo = $this->getProjectInfo($estimate);

        $pdf = Pdf::loadView('pdf.painter-work-order', [
            'estimate' => $estimate,
            'areaBreakdowns' => $areaBreakdowns,
            'jobTotals' => $jobTotals,
            'materialsNeeded' => $materialsNeeded,
            'customerInfo' => $customerInfo,
            'projectInfo' => $projectInfo,
        ]);

        $filename = 'work-order-'.$estimate->estimate_number.'.pdf';

        return $pdf->download($filename);
    }

    public function financials(Estimate $estimate): Response
    {
        $estimate->load([
            'areas.surfaces.productionRate',
            'areas.surfaces.paintProduct.brand',
            'areas.addons.nestedAreaTemplate',
            'areas.substrate',
            'deal.property',
            'deal.customer.user',
        ]);

        $settings = CompanySetting::getInstance();
        $scope = $estimate->project_type ?? 'interior';

        $jobTotals = $this->getJobTotals($estimate, $settings, $scope);
        $materialsWithCosts = $this->getMaterialsWithCosts($estimate, $settings);
        $sundriesBreakdown = $this->getSundriesBreakdown($jobTotals, $settings, $scope);
        $painterPayBreakdown = $this->getPainterPayBreakdown($jobTotals, $settings, $scope);
        $crewScheduleGrid = $this->getCrewScheduleGrid($jobTotals, $painterPayBreakdown);
        $customerInfo = $this->getCustomerInfo($estimate);
        $projectInfo = $this->getProjectInfo($estimate);
        $profitData = $this->getProfitData($estimate, $materialsWithCosts, $sundriesBreakdown, $painterPayBreakdown, $settings);

        $pdf = Pdf::loadView('pdf.painter-financials', [
            'estimate' => $estimate,
            'jobTotals' => $jobTotals,
            'materialsWithCosts' => $materialsWithCosts,
            'sundriesBreakdown' => $sundriesBreakdown,
            'painterPayBreakdown' => $painterPayBreakdown,
            'crewScheduleGrid' => $crewScheduleGrid,
            'customerInfo' => $customerInfo,
            'projectInfo' => $projectInfo,
            'profitData' => $profitData,
        ]);

        $filename = 'financials-'.$estimate->estimate_number.'.pdf';

        return $pdf->download($filename);
    }

    private function getJobTotals(Estimate $estimate, CompanySetting $settings, string $scope): array
    {
        $laborHours = 0;
        $cleanupHours = 0;
        $areaCount = 0;

        foreach ($estimate->areas as $area) {
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

    private function getMaterialsNeeded(Estimate $estimate): array
    {
        $materials = [];

        foreach ($estimate->areas as $area) {
            foreach ($area->surfaces()->where('is_included', true)->get() as $surface) {
                $paintProduct = $surface->getEffectivePaintProduct();

                if ($paintProduct) {
                    $key = $paintProduct->id;

                    if (! isset($materials[$key])) {
                        $materials[$key] = [
                            'brand' => $paintProduct->brand?->name ?? 'Unknown',
                            'name' => $paintProduct->name,
                            'sheen' => ucfirst($paintProduct->sheen ?? ''),
                            'gallons' => 0,
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
                }
            }
        }

        return array_values($materials);
    }

    private function getMaterialsWithCosts(Estimate $estimate, CompanySetting $settings): array
    {
        $materials = [];
        $totalGallons = 0;
        $totalPaintCost = 0;

        foreach ($estimate->areas as $area) {
            foreach ($area->surfaces()->where('is_included', true)->get() as $surface) {
                $paintProduct = $surface->getEffectivePaintProduct();

                if ($paintProduct) {
                    $key = $paintProduct->id;

                    if (! isset($materials[$key])) {
                        $materials[$key] = [
                            'brand' => $paintProduct->brand?->name ?? 'Unknown',
                            'name' => $paintProduct->name,
                            'sheen' => ucfirst($paintProduct->sheen ?? ''),
                            'price_per_gallon' => (float) $paintProduct->price_per_gallon,
                            'gallons' => 0,
                            'paint_cost' => 0,
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
                }
            }
        }

        $materials = array_values($materials);

        foreach ($materials as $material) {
            $totalGallons += $material['gallons'];
            $totalPaintCost += $material['paint_cost'];
        }

        $recycleFeeRate = (float) $settings->recycle_fee_per_gallon;
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

    private function getSundriesBreakdown(array $jobTotals, CompanySetting $settings, string $scope): array
    {
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

    private function getPainterPayBreakdown(array $jobTotals, CompanySetting $settings, string $scope): array
    {
        $baseHourlyWage = $settings->getHourlyWageForScope($scope);
        $payrollBurdenMultiplier = (float) $settings->payroll_burden_multiplier;
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

    private function getCrewScheduleGrid(array $jobTotals, array $painterPayBreakdown): array
    {
        $totalHours = $jobTotals['total_hours'];
        $baseHourlyWage = $painterPayBreakdown['base_hourly_wage'];
        $hoursPerDay = 8;

        $grid = [];
        $workdayOptions = [6, 8, 10];

        for ($painters = 1; $painters <= 4; $painters++) {
            $hoursPerPainter = $totalHours / $painters;
            $daysAtSelectedHours = $hoursPerDay > 0 ? ceil($hoursPerPainter / $hoursPerDay * 10) / 10 : 0;

            $days = [];
            foreach ($workdayOptions as $hoursPerWorkday) {
                $daysNeeded = $hoursPerPainter / $hoursPerWorkday;
                $days[$hoursPerWorkday] = round($daysNeeded, 1);
            }

            $dailyPayEach = round($baseHourlyWage * $hoursPerDay, 2);

            $grid[] = [
                'painters' => $painters,
                'hours_per_painter' => round($hoursPerPainter, 1),
                'days_needed' => $days,
                'days_at_selected_hours' => $daysAtSelectedHours,
                'daily_pay_each' => $dailyPayEach,
                'total_job_pay' => $painterPayBreakdown['painter_earns_base'],
            ];
        }

        return [
            'grid' => $grid,
            'total_hours' => $totalHours,
            'base_hourly_wage' => $baseHourlyWage,
            'selected_hours_per_day' => $hoursPerDay,
        ];
    }

    private function getAreaBreakdowns(Estimate $estimate, CompanySetting $settings, string $scope): array
    {
        $areas = [];

        foreach ($estimate->areas as $area) {
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
                    'name' => $surface->name,
                    'quantity' => (float) $surface->quantity,
                    'unit' => $surface->unit ?? 'sqft',
                    'coats' => $surface->coats ?? 1,
                    'labor_hours' => (float) $surface->labor_hours,
                    'application_method' => $surface->application_method_label,
                    'paint_product' => $paintProduct ? [
                        'full_name' => $paintProduct->full_name,
                        'sheen' => ucfirst($paintProduct->sheen ?? ''),
                    ] : null,
                    'paint_brand' => $surface->paint_brand_label,
                    'paint_type' => $surface->paint_type_label,
                    'prep_notes' => $prepNotes,
                ];

                $totalLaborHours += (float) $surface->labor_hours;
            }

            $cleanupHours = $settings->calculateCleanupHours($totalLaborHours, $scope);

            $addons = [];
            foreach ($area->addons as $addon) {
                $template = $addon->nestedAreaTemplate;
                $addons[] = [
                    'name' => $template?->name ?? $addon->name ?? 'Addon',
                    'labor_hours' => (float) ($addon->labor_hours ?? 0),
                ];
            }

            $areas[] = [
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

    private function getCustomerInfo(Estimate $estimate): array
    {
        $deal = $estimate->deal;
        $customer = $deal?->customer;
        $property = $deal?->property;

        return [
            'name' => $customer?->user?->name ?? 'Unknown Customer',
            'address' => $property ? $this->formatAddress($property) : 'No address on file',
            'property_type' => $property?->property_type ? ucfirst($property->property_type) : null,
        ];
    }

    private function getProjectInfo(Estimate $estimate): array
    {
        return [
            'estimate_number' => $estimate->estimate_number,
            'title' => $estimate->title,
            'project_type' => ucfirst($estimate->project_type ?? 'Interior'),
            'status' => $estimate->status_label,
            'notes' => $estimate->notes,
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

    private function getProfitData(Estimate $estimate, array $materialsWithCosts, array $sundriesBreakdown, array $painterPayBreakdown, CompanySetting $settings): array
    {
        $actualPaintCosts = $estimate->actual_paint_costs ?? [];
        $actualPainters = $estimate->actual_painters ?? [];
        $burdenMultiplier = (float) $settings->payroll_burden_multiplier;

        // Calculate materials profit
        $materialsProfit = [];
        $totalCatalogCost = 0;
        $totalActualCost = 0;
        $hasPaintActuals = false;

        foreach ($materialsWithCosts['items'] as $material) {
            $productId = null;
            foreach ($estimate->areas as $area) {
                foreach ($area->surfaces()->where('is_included', true)->get() as $surface) {
                    $pp = $surface->getEffectivePaintProduct();
                    if ($pp && $pp->name === $material['name']) {
                        $productId = (string) $pp->id;
                        break 2;
                    }
                }
            }

            $catalogPrice = (float) $material['price_per_gallon'];
            $actualPrice = ($productId && isset($actualPaintCosts[$productId]))
                ? (float) $actualPaintCosts[$productId]
                : null;
            $gallons = ceil($material['gallons']);

            $catalogTotal = $gallons * $catalogPrice;
            $actualTotal = $actualPrice !== null ? $gallons * $actualPrice : null;
            $profit = $actualTotal !== null ? $catalogTotal - $actualTotal : null;

            if ($actualPrice !== null) {
                $hasPaintActuals = true;
            }

            $materialsProfit[] = [
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

        // Calculate painters breakdown (simple: name, rate, hours, pay)
        $paintersBreakdown = [];
        $totalBasePay = 0;
        $totalHours = 0;

        foreach ($actualPainters as $painter) {
            $rate = (float) ($painter['hourly_rate'] ?? 0);
            $hours = (float) ($painter['hours'] ?? 0);
            $basePay = round($rate * $hours, 2);

            $paintersBreakdown[] = [
                'name' => $painter['name'] ?? '',
                'hourly_rate' => $rate,
                'hours' => $hours,
                'base_pay' => $basePay,
            ];

            $totalBasePay += $basePay;
            $totalHours += $hours;
        }

        $totalBurdenCost = round($totalBasePay * ($burdenMultiplier - 1), 2);
        $totalLaborCost = round($totalBasePay + $totalBurdenCost, 2);

        // Job price = company pays (loaded labor) + materials (catalog paint + sundries + recycle)
        $sundriesCost = $sundriesBreakdown['total_cost'];
        $recycleFeeCost = $materialsWithCosts['total_recycle_fee'];

        $jobPrice = $painterPayBreakdown['company_pays_loaded']
            + $totalCatalogCost
            + $sundriesCost
            + $recycleFeeCost;

        $crewWages = count($actualPainters) > 0 ? $totalBasePay : $painterPayBreakdown['painter_earns_base'];
        $crewBurden = count($actualPainters) > 0 ? $totalBurdenCost : $painterPayBreakdown['burden_amount'];
        $paintCost = $hasPaintActuals ? $totalActualCost : $totalCatalogCost;

        $totalCosts = $crewWages + $crewBurden + $paintCost + $sundriesCost + $recycleFeeCost;
        $grossProfit = $jobPrice - $totalCosts;
        $grossMarginPercent = $jobPrice > 0 ? ($grossProfit / $jobPrice) * 100 : 0;

        return [
            'has_actuals' => count($actualPainters) > 0 || $hasPaintActuals,
            'materials_profit' => $materialsProfit,
            'total_catalog_cost' => round($totalCatalogCost, 2),
            'has_paint_actuals' => $hasPaintActuals,
            'painters' => $paintersBreakdown,
            'total_base_pay' => round($totalBasePay, 2),
            'total_hours' => round($totalHours, 1),
            'total_burden_cost' => round($totalBurdenCost, 2),
            'total_labor_cost' => round($totalLaborCost, 2),
            'burden_multiplier' => $burdenMultiplier,
            'profit_summary' => [
                'job_price' => round($jobPrice, 2),
                'crew_wages' => round($crewWages, 2),
                'burden_cost' => round($crewBurden, 2),
                'paint_cost' => round($paintCost, 2),
                'sundries_cost' => round($sundriesCost, 2),
                'recycle_fee' => round($recycleFeeCost, 2),
                'total_costs' => round($totalCosts, 2),
                'gross_profit' => round($grossProfit, 2),
                'gross_margin_percent' => round($grossMarginPercent, 1),
            ],
            'snapshot' => $estimate->profit_snapshot,
        ];
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
}
