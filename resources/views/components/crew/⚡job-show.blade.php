<?php

use App\Models\ChatThread;
use App\Models\CompanySetting;
use App\Models\Job;
use App\Models\JobPrepPlan;
use App\Models\JobPrepTask;
use App\Services\ChatService;
use App\Services\JobDispatchService;
use App\Services\JobPrepService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Component;

new #[Layout('components.layouts.crew', ['title' => 'Job Details'])] class extends Component
{
    public Job $job;

    public string $activeTab = 'work-order';

    public array $expandedAreas = [];

    public int $crewSize = 2;

    public float $hoursPerDay = 8;

    public int $activePrepDay = 1;

    public string $crewCustomTaskTitle = '';

    public ?float $crewCustomTaskHours = null;

    public bool $showCrewTaskForm = false;

    /** @var array<int|string, float> Actual paint costs keyed by paint_product_id */
    public array $actualPaintCosts = [];

    /** @var array<int, array{name: string, hourly_rate: float, hours: float, notes: string}> */
    public array $painters = [];

    public float $burdenMultiplier = 1.35;

    public function mount(Job $job): void
    {
        $this->ensureCrewHasAccess($job);
        $this->job = $job->load([
            'deal.customer.user',
            'deal.property',
            'deal.chatThread',
            'estimate.areas.surfaces.productionRate',
            'estimate.areas.surfaces.paintProduct.brand',
            'estimate.areas.addons.nestedAreaTemplate',
            'estimate.areas.substrate',
            'colors',
            'prepPlan.days.tasks',
        ]);

        // Expand all areas by default
        if ($this->job->estimate) {
            foreach ($this->job->estimate->areas as $area) {
                $this->expandedAreas[$area->id] = true;
            }

            // Load existing actuals from estimate
            $this->actualPaintCosts = $this->job->estimate->actual_paint_costs ?? [];
            $this->painters = $this->job->estimate->actual_painters ?? [];
        }

        $settings = CompanySetting::getInstance();
        $this->burdenMultiplier = (float) $settings->payroll_burden_multiplier;
        $this->crewSize = $this->job->required_crew_size ?? 2;
    }

    private function ensureCrewHasAccess(Job $job): void
    {
        $user = Auth::guard('crew')->user();
        $hasAccess = $job->crews()->where('crew_user_id', $user->id)->exists();

        if (! $hasAccess) {
            abort(404);
        }
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    /** Backward-compatible alias used by tests */
    public function setStage(string $stage): void
    {
        $this->activeTab = $stage;
    }

    public function toggleArea(int $areaId): void
    {
        $this->expandedAreas[$areaId] = ! ($this->expandedAreas[$areaId] ?? false);
    }

    public function expandAll(): void
    {
        if ($this->job->estimate) {
            foreach ($this->job->estimate->areas as $area) {
                $this->expandedAreas[$area->id] = true;
            }
        }
    }

    public function collapseAll(): void
    {
        $this->expandedAreas = [];
    }

    public function setCrewSize(int $size): void
    {
        $this->crewSize = max(1, min(10, $size));
    }

    public function setHoursPerDay(float $hours): void
    {
        $this->hoursPerDay = max(4, min(12, $hours));
    }

    public function addPainter(): void
    {
        $settings = CompanySetting::getInstance();
        $scope = $this->job->estimate?->project_type ?? 'interior';

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
        if (! $this->job->estimate) {
            return;
        }

        $this->job->estimate->actual_paint_costs = $this->actualPaintCosts;
        $this->job->estimate->actual_painters = $this->painters;
        $this->job->estimate->save();

        session()->flash('success', 'Actuals saved successfully.');
    }

    public function saveSnapshot(): void
    {
        if (! $this->job->estimate) {
            return;
        }

        $this->saveActuals();

        $costs = $this->profitSummary;
        $this->job->estimate->profit_snapshot = array_merge($costs, [
            'captured_at' => now()->toIso8601String(),
            'painters' => $this->painters,
            'paint_costs' => $this->actualPaintCosts,
        ]);
        $this->job->estimate->save();

        session()->flash('success', 'Profit snapshot saved successfully.');
    }

    public function acceptJob(): void
    {
        $user = Auth::guard('crew')->user();

        if (app(JobDispatchService::class)->acceptJob($this->job, $user)) {
            // Add crew to the deal chat thread
            if ($this->job->deal) {
                $thread = $this->job->deal->chatThread;

                if ($thread && ! $thread->hasParticipant($user)) {
                    $thread->addParticipant($user);

                    // Post a system message that crew joined
                    $thread->messages()->create([
                        'user_id' => null,
                        'type' => 'system',
                        'content' => $user->name.' (Crew) has joined the conversation.',
                    ]);
                }
            }

            $this->job->refresh();
            session()->flash('success', 'Job accepted! You now have full access to job details.');
        } else {
            session()->flash('error', 'Unable to accept this job.');
        }
    }

    public function rejectJob(): void
    {
        $user = Auth::guard('crew')->user();

        if (app(JobDispatchService::class)->rejectJob($this->job, $user)) {
            $this->job->refresh();
            session()->flash('success', 'Job declined.');
            $this->redirect(route('crew.jobs.available'));
        } else {
            session()->flash('error', 'Unable to decline this job.');
        }
    }

    #[Computed]
    public function prepPlan(): ?JobPrepPlan
    {
        return $this->job->prepPlan?->load(['days.tasks.completedByUser']);
    }

    #[Computed]
    public function prepProgress(): array
    {
        $plan = $this->prepPlan;

        if (! $plan) {
            return ['total' => 0, 'completed' => 0, 'percent' => 0.0, 'per_day' => []];
        }

        return app(JobPrepService::class)->getProgress($plan);
    }

    public function approvePrepPlan(): void
    {
        $plan = $this->prepPlan;

        if (! $plan || $plan->crew_approved) {
            return;
        }

        app(JobPrepService::class)->approvePlan($plan, Auth::guard('crew')->user(), 'crew');
        unset($this->prepPlan);
        $this->job->refresh();
    }

    public function completeTask(int $taskId): void
    {
        $task = JobPrepTask::findOrFail($taskId);
        app(JobPrepService::class)->completeTask($task, Auth::guard('crew')->user());
        unset($this->prepPlan, $this->prepProgress);
    }

    public function addCrewCustomTask(): void
    {
        if (empty(trim($this->crewCustomTaskTitle))) {
            return;
        }

        $plan = $this->prepPlan;

        if (! $plan) {
            return;
        }

        $day = $plan->days()->where('day_number', $this->activePrepDay)->first();

        if (! $day) {
            return;
        }

        app(JobPrepService::class)->addCustomTask($day, $this->crewCustomTaskTitle, null, $this->crewCustomTaskHours);
        $this->reset('crewCustomTaskTitle', 'crewCustomTaskHours', 'showCrewTaskForm');
        unset($this->prepPlan, $this->prepProgress);
    }

    public function setActivePrepDay(int $day): void
    {
        $this->activePrepDay = $day;
    }

    #[Computed]
    public function crewStatus(): ?string
    {
        $user = Auth::guard('crew')->user();
        $pivot = $this->job->crews()->where('crew_user_id', $user->id)->first();

        return $pivot?->pivot->status;
    }

    #[Computed]
    public function hasAccepted(): bool
    {
        return $this->crewStatus === 'accepted';
    }

    #[Computed]
    public function jobTotals(): array
    {
        if (! $this->job->estimate) {
            return ['labor_hours' => 0, 'cleanup_hours' => 0, 'total_hours' => 0, 'area_count' => 0];
        }

        $settings = CompanySetting::getInstance();
        $scope = $this->job->estimate->project_type ?? 'interior';
        $laborHours = 0;
        $cleanupHours = 0;

        foreach ($this->job->estimate->areas as $area) {
            $areaLaborHours = (float) $area->surfaces()->where('is_included', true)->sum('labor_hours');
            $laborHours += $areaLaborHours;
            $cleanupHours += $settings->calculateCleanupHours($areaLaborHours, $scope);
        }

        return [
            'labor_hours' => $laborHours,
            'cleanup_hours' => $cleanupHours,
            'total_hours' => $laborHours + $cleanupHours,
            'area_count' => $this->job->estimate->areas->count(),
        ];
    }

    #[Computed]
    public function crewSchedule(): array
    {
        $totalHours = $this->jobTotals['total_hours'];
        $requiredCrew = $this->job->required_crew_size;
        $hoursPerDay = 8;

        $hoursPerPerson = $totalHours / max(1, $requiredCrew);
        $daysNeeded = ceil($hoursPerPerson / $hoursPerDay * 10) / 10;

        return [
            'total_hours' => $totalHours,
            'crew_size' => $requiredCrew,
            'hours_per_person' => round($hoursPerPerson, 1),
            'estimated_days' => $daysNeeded,
        ];
    }

    #[Computed]
    public function materialsNeeded(): array
    {
        if (! $this->job->estimate) {
            return [];
        }

        $materials = [];

        foreach ($this->job->estimate->areas as $area) {
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
                            'gallons' => 0,
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
                }
            }
        }

        return array_values($materials);
    }

    #[Computed]
    public function materialsWithCosts(): array
    {
        if (! $this->job->estimate) {
            return ['items' => [], 'total_gallons' => 0, 'total_paint_cost' => 0, 'recycle_fee_rate' => 0, 'total_recycle_fee' => 0, 'total_materials_cost' => 0];
        }

        $settings = CompanySetting::getInstance();
        $materials = [];
        $totalGallons = 0;
        $totalPaintCost = 0;

        foreach ($this->job->estimate->areas as $area) {
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

        $recycleFeeRate = (float) ($settings->recycle_fee_per_gallon ?? 0);
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
        $scope = $this->job->estimate?->project_type ?? 'interior';
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
        $scope = $this->job->estimate?->project_type ?? 'interior';
        $jobTotals = $this->jobTotals;

        $baseHourlyWage = $settings->getHourlyWageForScope($scope);
        $payrollBurdenMultiplier = (float) ($settings->payroll_burden_multiplier ?? 1.35);
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
        if (! $this->job->estimate) {
            return [];
        }

        $areas = [];
        $settings = CompanySetting::getInstance();
        $scope = $this->job->estimate->project_type ?? 'interior';

        foreach ($this->job->estimate->areas as $area) {
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
                    ] : null,
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
                'surfaces' => $surfaces,
                'addons' => $addons,
                'labor_hours' => $totalLaborHours,
                'cleanup_hours' => $cleanupHours,
                'total_hours' => $totalLaborHours + $cleanupHours,
                'notes' => $area->notes,
                'substrate' => $area->substrate?->name,
            ];
        }

        return $areas;
    }

    #[Computed]
    public function customerDisplay(): array
    {
        $customer = $this->job->deal?->customer;
        $property = $this->job->deal?->property;

        if ($this->hasAccepted) {
            // Full details after accepting
            return [
                'name' => $customer?->user?->name ?? 'Customer',
                'location' => $property ? $this->formatFullAddress($property) : 'No address on file',
            ];
        }

        // Limited info before accepting - no last name, only city/zip
        $firstName = $customer?->user?->name ? explode(' ', $customer->user->name)[0] : 'Customer';

        $location = 'Location not available';
        if ($property) {
            $parts = array_filter([$property->city, $property->zip]);
            $location = implode(', ', $parts) ?: 'Location not available';
        }

        return [
            'name' => $firstName,
            'location' => $location,
        ];
    }

    private function formatFullAddress($property): string
    {
        $parts = [];

        if ($property->address) {
            $parts[] = $property->address;
        }

        $cityState = array_filter([
            $property->city,
            $property->state,
        ]);

        if (! empty($cityState)) {
            $parts[] = implode(', ', $cityState).' '.$property->zip;
        }

        return implode(', ', $parts) ?: 'No address';
    }

    #[Computed]
    public function chatThread(): ?ChatThread
    {
        return $this->job->deal?->chatThread;
    }

    #[Computed]
    public function chatMessages()
    {
        $thread = $this->chatThread;
        if (! $thread) {
            return collect();
        }

        $user = Auth::guard('crew')->user();

        return app(ChatService::class)->getMessagesForThread($thread, $user, 30);
    }

    #[On('message-sent')]
    public function onMessageSent(): void
    {
        unset($this->chatMessages);
    }

    #[Computed]
    public function referenceTabs(): array
    {
        return [
            ['key' => 'work-order', 'label' => 'Work Order', 'icon' => 'clipboard-document-list'],
            ['key' => 'financials', 'label' => 'Financials', 'icon' => 'calculator'],
            ['key' => 'colors', 'label' => 'Colors', 'icon' => 'paint-brush'],
            ['key' => 'materials', 'label' => 'Materials', 'icon' => 'shopping-cart'],
            ['key' => 'photos', 'label' => 'Photos', 'icon' => 'photo'],
        ];
    }
}; ?>

<div>
    <div class="max-w-4xl mx-auto px-4 sm:px-0">
        {{-- 1. Header --}}
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6">
            <div class="flex items-center gap-3 sm:gap-4">
                <flux:button variant="ghost" :href="$this->hasAccepted ? route('crew.jobs.mine') : route('crew.jobs.available')" icon="arrow-left" class="shrink-0">
                    <span class="hidden sm:inline">Back</span>
                </flux:button>
                <div class="min-w-0">
                    <flux:heading size="xl" class="truncate">{{ $job->title }}</flux:heading>
                    <flux:text class="text-zinc-500">{{ $job->job_number }}</flux:text>
                </div>
            </div>
            <div class="flex items-center gap-2 sm:gap-3">
                @php
                    $stageColor = match ($job->stage) {
                        'estimate_accepted' => 'blue',
                        'scheduling_colors' => 'purple',
                        'job_prep' => 'yellow',
                        'in_progress' => 'orange',
                        'completed' => 'green',
                        default => 'zinc',
                    };
                @endphp
                <flux:badge size="sm" color="{{ $stageColor }}" class="whitespace-nowrap">{{ $job->stage_label }}</flux:badge>
            </div>
        </div>

        {{-- 2. Accept/Reject Banner --}}
        @if ($this->crewStatus === 'dispatched')
            <div class="border-2 border-blue-300 dark:border-blue-700 bg-blue-50 dark:bg-blue-900/30 rounded-lg p-4 sm:p-6 mb-6">
                <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-4">
                    <div>
                        <flux:heading size="sm" class="mb-2">Review & Accept This Job</flux:heading>
                        <flux:text class="text-zinc-600 dark:text-zinc-300 text-sm sm:text-base">
                            Review the job details below. Once accepted, you'll have access to the full work order, address, and group chat.
                        </flux:text>
                    </div>
                    <div class="flex gap-2 sm:gap-3 shrink-0">
                        <flux:button wire:click="rejectJob" variant="ghost" class="flex-1 sm:flex-none"
                            wire:confirm="Are you sure you want to decline this job?">
                            Decline
                        </flux:button>
                        <flux:button wire:click="acceptJob" variant="primary" icon="check" class="flex-1 sm:flex-none">
                            Accept Job
                        </flux:button>
                    </div>
                </div>
            </div>
        @endif

        {{-- 3. Flash Messages --}}
        @if (session('success'))
            <flux:callout variant="success" class="mb-4">
                {{ session('success') }}
            </flux:callout>
        @endif

        @if (session('error'))
            <flux:callout variant="danger" class="mb-4">
                {{ session('error') }}
            </flux:callout>
        @endif

        {{-- 4. Progress Timeline --}}
        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 sm:p-6 mb-6">
            <flux:heading size="sm" class="mb-4">Progress</flux:heading>
            <div class="flex items-center justify-between">
                @foreach (App\Models\Job::STAGES as $stageKey => $stageLabel)
                    @php
                        $stageNum = App\Models\Job::STAGE_ORDER[$stageKey];
                        $currentNum = $job->stage_number;
                        $isActive = $stageKey === $job->stage;
                        $isCompleted = $stageNum < $currentNum;
                        $isFuture = $stageNum > $currentNum;
                    @endphp
                    <div class="flex-1 flex flex-col items-center relative" wire:key="stage-{{ $stageKey }}">
                        @if (! $loop->first)
                            <div class="absolute top-4 -left-1/2 w-full h-0.5 {{ $isCompleted || $isActive ? 'bg-green-500' : 'bg-zinc-300 dark:bg-zinc-600' }}"></div>
                        @endif

                        <div class="relative z-10 flex items-center justify-center w-8 h-8 rounded-full border-2
                            {{ $isCompleted ? 'bg-green-500 border-green-500 text-white' : '' }}
                            {{ $isActive ? 'bg-blue-500 border-blue-500 text-white' : '' }}
                            {{ $isFuture ? 'bg-white dark:bg-zinc-800 border-zinc-300 dark:border-zinc-600 text-zinc-400' : '' }}
                        ">
                            @if ($isCompleted)
                                <flux:icon name="check" class="w-4 h-4" />
                            @else
                                <span class="text-xs font-bold">{{ $stageNum }}</span>
                            @endif
                        </div>

                        <flux:text class="mt-2 text-xs text-center leading-tight {{ $isActive ? 'font-semibold text-blue-600 dark:text-blue-400' : 'text-zinc-500' }}">
                            {{ $stageLabel }}
                        </flux:text>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- 5. Job Info Card (always visible) --}}
        <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 sm:p-6 mb-6">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6">
                <div>
                    <h3 class="text-sm font-semibold text-zinc-500 uppercase tracking-wider mb-3">Job Details</h3>
                    <dl class="space-y-2 sm:space-y-3">
                        <div class="flex justify-between gap-2">
                            <dt class="text-zinc-500">Customer</dt>
                            <dd class="font-medium text-right">{{ $this->customerDisplay['name'] }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-zinc-500">Location</dt>
                            <dd class="font-medium text-right text-sm sm:text-base">{{ $this->customerDisplay['location'] }}</dd>
                        </div>
                        @if ($job->scheduled_date)
                            <div class="flex justify-between gap-2">
                                <dt class="text-zinc-500">Start Date</dt>
                                <dd class="font-medium text-blue-600 dark:text-blue-400 text-right">
                                    {{ $job->scheduled_date->format('M j, Y') }}
                                </dd>
                            </div>
                        @endif
                        <div class="flex justify-between gap-2">
                            <dt class="text-zinc-500">Crew Size</dt>
                            <dd class="font-medium text-right">{{ $job->required_crew_size }} {{ Str::plural('painter', $job->required_crew_size) }}</dd>
                        </div>
                    </dl>
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-zinc-500 uppercase tracking-wider mb-3">Time Estimate</h3>
                    <dl class="space-y-2 sm:space-y-3">
                        <div class="flex justify-between gap-2">
                            <dt class="text-zinc-500">Total Hours</dt>
                            <dd class="font-bold text-lg">{{ number_format($this->crewSchedule['total_hours'], 1) }} hrs</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-zinc-500">With {{ $this->crewSchedule['crew_size'] }} Painters</dt>
                            <dd class="font-medium">~{{ $this->crewSchedule['hours_per_person'] }} hrs each</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-zinc-500">Estimated Duration</dt>
                            <dd class="font-bold text-green-600 dark:text-green-400">
                                {{ $this->crewSchedule['estimated_days'] }} {{ Str::plural('day', ceil($this->crewSchedule['estimated_days'])) }}
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>
        </div>

        @if (! $this->hasAccepted)
            <flux:callout variant="info" class="mb-6">
                <flux:icon name="lock-closed" class="w-5 h-5" />
                <div class="text-sm sm:text-base">
                    <strong>Limited Preview</strong> - Accept this job to view the full work order, complete address, financials, and access the group chat.
                </div>
            </flux:callout>
        @endif

        {{-- 6. Stage-Specific Primary Content --}}

        {{-- STAGE: estimate_accepted --}}
        @if ($job->stage === 'estimate_accepted')
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 sm:p-8 text-center mb-6">
                <flux:icon name="check-circle" class="w-12 h-12 mx-auto text-green-500 mb-3" />
                <flux:heading size="lg">Job Confirmed</flux:heading>
                <flux:text class="text-zinc-500 mt-2 max-w-md mx-auto">
                    This painting project has been confirmed. The team will begin scheduling and color selection soon.
                </flux:text>
                @if ($job->estimate)
                    <div class="mt-3">
                        <flux:badge size="sm" color="green">Estimate: {{ $job->estimate->estimate_number }}</flux:badge>
                    </div>
                @endif
            </div>

        {{-- STAGE: scheduling_colors --}}
        @elseif ($job->stage === 'scheduling_colors')
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 sm:p-6 mb-6">
                <flux:heading size="sm" class="mb-4">Scheduling & Colors</flux:heading>

                <div class="space-y-4">
                    {{-- Scheduled Date --}}
                    <div class="flex items-center justify-between p-3 bg-zinc-50 dark:bg-zinc-700/50 rounded-lg">
                        <div class="flex items-center gap-2">
                            <flux:icon name="calendar" class="w-5 h-5 text-zinc-500" />
                            <span class="font-medium text-zinc-700 dark:text-zinc-300">Scheduled Date</span>
                        </div>
                        <div class="text-right">
                            @if ($job->scheduled_date)
                                <span class="font-bold text-blue-600 dark:text-blue-400">{{ $job->scheduled_date->format('M j, Y') }}</span>
                            @else
                                <flux:badge size="sm" color="yellow">Pending</flux:badge>
                            @endif
                        </div>
                    </div>

                    {{-- Confirmation Status --}}
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div class="flex items-center justify-between p-3 bg-zinc-50 dark:bg-zinc-700/50 rounded-lg">
                            <span class="text-sm text-zinc-600 dark:text-zinc-400">Company Confirmed</span>
                            @if ($job->company_confirmed_date)
                                <flux:badge size="sm" color="green" icon="check">Confirmed</flux:badge>
                            @else
                                <flux:badge size="sm" color="yellow">Pending</flux:badge>
                            @endif
                        </div>
                        <div class="flex items-center justify-between p-3 bg-zinc-50 dark:bg-zinc-700/50 rounded-lg">
                            <span class="text-sm text-zinc-600 dark:text-zinc-400">Customer Confirmed</span>
                            @if ($job->customer_confirmed_date)
                                <flux:badge size="sm" color="green" icon="check">Confirmed</flux:badge>
                            @else
                                <flux:badge size="sm" color="yellow">Pending</flux:badge>
                            @endif
                        </div>
                    </div>

                    {{-- Selected Colors --}}
                    @if ($job->colors->count() > 0)
                        <div>
                            <h4 class="text-sm font-semibold text-zinc-500 mb-2">Selected Colors</h4>
                            <div class="grid gap-2 grid-cols-1 sm:grid-cols-2">
                                @foreach ($job->colors as $color)
                                    <div class="flex items-center gap-3 p-2 border border-zinc-200 dark:border-zinc-700 rounded-lg">
                                        @if ($color->hex_value)
                                            <div class="w-8 h-8 rounded border border-zinc-300 shrink-0" style="background-color: {{ $color->hex_value }}"></div>
                                        @else
                                            <div class="w-8 h-8 rounded border border-zinc-300 bg-zinc-100 dark:bg-zinc-700 flex items-center justify-center shrink-0">
                                                <flux:icon name="paint-brush" class="w-4 h-4 text-zinc-400" />
                                            </div>
                                        @endif
                                        <div class="min-w-0">
                                            <flux:text class="font-medium text-sm truncate">{{ $color->name }}</flux:text>
                                            @if ($color->brand)
                                                <flux:text class="text-xs text-zinc-500 truncate">{{ $color->brand }}</flux:text>
                                            @endif
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if (! $job->company_confirmed_date || ! $job->customer_confirmed_date)
                        <flux:callout variant="info">
                            <flux:text class="text-sm">Waiting for all confirmations before moving to job prep.</flux:text>
                        </flux:callout>
                    @endif
                </div>
            </div>

        {{-- STAGE: job_prep or in_progress (prep plan is primary content) --}}
        @elseif (in_array($job->stage, ['job_prep', 'in_progress']) && $this->hasAccepted)
            @if ($this->prepPlan)
                @php
                    $plan = $this->prepPlan;
                    $progress = $this->prepProgress;
                @endphp

                {{-- Plan Summary --}}
                <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 sm:p-6 mb-6">
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
                        <flux:heading size="lg">Prep Plan</flux:heading>
                        <div class="flex items-center gap-2">
                            @if ($plan->crew_approved)
                                <flux:badge color="green">Crew Approved</flux:badge>
                            @else
                                <flux:badge color="yellow">Pending Crew Approval</flux:badge>
                            @endif
                            @if ($plan->team_approved)
                                <flux:badge color="green">Team Approved</flux:badge>
                            @endif
                        </div>
                    </div>

                    <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 sm:gap-4">
                        <div class="text-center p-3 bg-zinc-50 dark:bg-zinc-700/50 rounded-lg">
                            <div class="text-xl font-bold text-zinc-900 dark:text-white">{{ $plan->crew_size }}</div>
                            <div class="text-xs text-zinc-500">Crew Size</div>
                        </div>
                        <div class="text-center p-3 bg-zinc-50 dark:bg-zinc-700/50 rounded-lg">
                            <div class="text-xl font-bold text-zinc-900 dark:text-white">{{ $plan->hours_per_day }}h</div>
                            <div class="text-xs text-zinc-500">Hours/Day</div>
                        </div>
                        <div class="text-center p-3 bg-zinc-50 dark:bg-zinc-700/50 rounded-lg">
                            <div class="text-xl font-bold text-zinc-900 dark:text-white">{{ $plan->total_days }}</div>
                            <div class="text-xs text-zinc-500">Total {{ Str::plural('Day', $plan->total_days) }}</div>
                        </div>
                        <div class="text-center p-3 bg-zinc-50 dark:bg-zinc-700/50 rounded-lg">
                            <div class="text-xl font-bold text-zinc-900 dark:text-white">{{ number_format($plan->total_labor_hours + $plan->total_cleanup_hours, 1) }}</div>
                            <div class="text-xs text-zinc-500">Total Hours</div>
                        </div>
                    </div>
                </div>

                {{-- Overall Progress Bar --}}
                @if ($progress['total'] > 0)
                    <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 sm:p-6 mb-6">
                        <div class="flex items-center justify-between mb-2">
                            <flux:text class="font-semibold text-zinc-700 dark:text-zinc-300">Overall Progress</flux:text>
                            <flux:text class="text-sm text-zinc-500">{{ $progress['completed'] }}/{{ $progress['total'] }} tasks ({{ number_format($progress['percent'], 0) }}%)</flux:text>
                        </div>
                        <div class="w-full bg-zinc-200 dark:bg-zinc-700 rounded-full h-3">
                            <div
                                class="h-3 rounded-full transition-all duration-300 {{ $progress['percent'] >= 100 ? 'bg-green-500' : 'bg-blue-500' }}"
                                style="width: {{ min($progress['percent'], 100) }}%"
                            ></div>
                        </div>
                    </div>
                @endif

                {{-- Day Tabs --}}
                <div class="flex gap-2 mb-4 overflow-x-auto pb-2 -mx-4 px-4 sm:mx-0 sm:px-0 scrollbar-hide">
                    @foreach ($plan->days as $day)
                        @php
                            $dayProgress = collect($progress['per_day'])->firstWhere('day_number', $day->day_number);
                            $dayPercent = $dayProgress['percent'] ?? 0;
                        @endphp
                        <flux:button
                            wire:click="setActivePrepDay({{ $day->day_number }})"
                            :variant="$activePrepDay === $day->day_number ? 'primary' : 'ghost'"
                            size="sm"
                            class="whitespace-nowrap shrink-0"
                        >
                            {{ $day->label }}
                            @if ($dayPercent >= 100)
                                <flux:icon name="check-circle" class="w-4 h-4 ml-1 text-green-400" />
                            @elseif ($dayPercent > 0)
                                <span class="ml-1 text-xs opacity-75">{{ number_format($dayPercent, 0) }}%</span>
                            @endif
                        </flux:button>
                    @endforeach
                </div>

                {{-- Task List for Active Day --}}
                @foreach ($plan->days as $day)
                    @if ($day->day_number === $activePrepDay)
                        <div class="space-y-3" wire:key="prep-day-{{ $day->id }}">
                            @php
                                $dayProgress = collect($progress['per_day'])->firstWhere('day_number', $day->day_number);
                                $dayHours = $dayProgress['hours'] ?? 0;
                            @endphp

                            <div class="flex items-center justify-between mb-2">
                                <flux:text class="text-sm text-zinc-500">
                                    {{ $day->tasks->count() }} {{ Str::plural('task', $day->tasks->count()) }} &middot; {{ number_format($dayHours, 1) }} hrs estimated
                                </flux:text>
                            </div>

                            @forelse ($day->tasks as $task)
                                @php
                                    $typeColor = match ($task->task_type) {
                                        'surface' => 'blue',
                                        'addon' => 'amber',
                                        'custom' => 'zinc',
                                        default => 'zinc',
                                    };
                                    $isCompleted = $task->status === 'completed';
                                @endphp
                                <div
                                    class="flex items-start gap-3 p-3 sm:p-4 bg-white dark:bg-zinc-800 border rounded-lg {{ $isCompleted ? 'border-green-200 dark:border-green-800 bg-green-50/50 dark:bg-green-900/10' : 'border-zinc-200 dark:border-zinc-700' }}"
                                    wire:key="prep-task-{{ $task->id }}"
                                >
                                    {{-- Completion Checkbox (only in in_progress stage) --}}
                                    @if ($job->stage === 'in_progress' && ! $isCompleted)
                                        <button
                                            wire:click="completeTask({{ $task->id }})"
                                            class="mt-0.5 shrink-0 w-5 h-5 sm:w-6 sm:h-6 border-2 border-zinc-300 dark:border-zinc-600 rounded hover:border-green-500 hover:bg-green-50 dark:hover:bg-green-900/20 transition-colors"
                                            wire:loading.attr="disabled"
                                            wire:target="completeTask({{ $task->id }})"
                                        ></button>
                                    @elseif ($isCompleted)
                                        <div class="mt-0.5 shrink-0 w-5 h-5 sm:w-6 sm:h-6 bg-green-500 rounded flex items-center justify-center">
                                            <flux:icon name="check" class="w-3 h-3 sm:w-4 sm:h-4 text-white" />
                                        </div>
                                    @else
                                        <div class="mt-0.5 shrink-0 w-5 h-5 sm:w-6 sm:h-6 border-2 border-zinc-200 dark:border-zinc-700 rounded"></div>
                                    @endif

                                    <div class="flex-1 min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <span class="font-semibold text-sm sm:text-base {{ $isCompleted ? 'text-zinc-500 line-through' : 'text-zinc-900 dark:text-white' }}">
                                                {{ $task->title }}
                                            </span>
                                            <flux:badge size="sm" color="{{ $typeColor }}">{{ $task->task_type }}</flux:badge>
                                        </div>
                                        @if ($task->area_name)
                                            <flux:text class="text-xs sm:text-sm text-zinc-500 mt-1">{{ $task->area_name }}</flux:text>
                                        @endif
                                        @if ($isCompleted && $task->completedByUser)
                                            <flux:text class="text-xs text-green-600 dark:text-green-400 mt-1">
                                                Completed by {{ $task->completedByUser->name }} {{ $task->completed_at?->diffForHumans() }}
                                            </flux:text>
                                        @endif
                                    </div>

                                    @if ($task->estimated_hours)
                                        <div class="shrink-0 text-right">
                                            <flux:badge size="sm" color="zinc">{{ number_format($task->estimated_hours, 1) }}h</flux:badge>
                                        </div>
                                    @endif
                                </div>
                            @empty
                                <div class="text-center py-8 text-zinc-500 dark:text-zinc-400">
                                    No tasks assigned to this day.
                                </div>
                            @endforelse

                            {{-- Add Custom Task (only in job_prep stage) --}}
                            @if ($job->stage === 'job_prep')
                                @if ($showCrewTaskForm)
                                    <div class="bg-zinc-50 dark:bg-zinc-700/50 border border-zinc-200 dark:border-zinc-700 rounded-lg p-4">
                                        <div class="space-y-3">
                                            <div>
                                                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Task Title</label>
                                                <input
                                                    type="text"
                                                    wire:model="crewCustomTaskTitle"
                                                    placeholder="e.g. Move furniture, Cover floors"
                                                    class="w-full text-sm border border-zinc-300 dark:border-zinc-600 rounded-lg px-3 py-2 bg-white dark:bg-zinc-700 text-zinc-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                                />
                                            </div>
                                            <div>
                                                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300 mb-1">Estimated Hours (optional)</label>
                                                <input
                                                    type="number"
                                                    step="0.5"
                                                    min="0"
                                                    wire:model="crewCustomTaskHours"
                                                    placeholder="0.0"
                                                    class="w-32 text-sm border border-zinc-300 dark:border-zinc-600 rounded-lg px-3 py-2 bg-white dark:bg-zinc-700 text-zinc-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                                />
                                            </div>
                                            <div class="flex gap-2">
                                                <flux:button variant="primary" size="sm" wire:click="addCrewCustomTask" icon="plus">
                                                    Add Task
                                                </flux:button>
                                                <flux:button variant="ghost" size="sm" wire:click="$set('showCrewTaskForm', false)">
                                                    Cancel
                                                </flux:button>
                                            </div>
                                        </div>
                                    </div>
                                @else
                                    <flux:button variant="ghost" size="sm" wire:click="$set('showCrewTaskForm', true)" icon="plus" class="w-full justify-center border-dashed border border-zinc-300 dark:border-zinc-600">
                                        Add Custom Task
                                    </flux:button>
                                @endif
                            @endif
                        </div>
                    @endif
                @endforeach

                {{-- Approve Button (only in job_prep stage, if not yet approved) --}}
                @if ($job->stage === 'job_prep' && ! $plan->crew_approved)
                    <div class="mt-6 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg p-4 sm:p-6">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                            <div>
                                <flux:heading size="sm" class="text-blue-800 dark:text-blue-200">Ready to approve?</flux:heading>
                                <flux:text class="text-sm text-blue-700 dark:text-blue-300 mt-1">
                                    Review all tasks across each day, then approve the plan so work can begin.
                                </flux:text>
                            </div>
                            <flux:button
                                variant="primary"
                                wire:click="approvePrepPlan"
                                wire:confirm="Are you sure you want to approve this prep plan?"
                                icon="check"
                                class="shrink-0"
                            >
                                Approve Prep Plan
                            </flux:button>
                        </div>
                    </div>
                @elseif ($plan->crew_approved)
                    <div class="mt-6 bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg p-4">
                        <div class="flex items-center gap-3">
                            <flux:icon name="check-circle" class="w-6 h-6 text-green-600 dark:text-green-400 shrink-0" />
                            <div>
                                <flux:text class="font-semibold text-green-800 dark:text-green-200">Crew Approved</flux:text>
                                @if ($plan->crew_approved_at)
                                    <flux:text class="text-sm text-green-700 dark:text-green-300">
                                        Approved {{ $plan->crew_approved_at->diffForHumans() }}
                                    </flux:text>
                                @endif
                            </div>
                        </div>
                    </div>
                @endif
            @else
                <div class="text-center py-12 border border-zinc-200 dark:border-zinc-700 rounded-lg">
                    <flux:icon name="clipboard-document-check" class="w-12 h-12 mx-auto text-zinc-300 dark:text-zinc-600 mb-4" />
                    <flux:heading>No Prep Plan Yet</flux:heading>
                    <flux:text class="text-zinc-500 mt-2">A prep plan will appear here once created by the team.</flux:text>
                </div>
            @endif

        {{-- STAGE: completed --}}
        @elseif ($job->stage === 'completed')
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-6 sm:p-8 text-center mb-6">
                <flux:icon name="trophy" class="w-12 h-12 mx-auto text-green-500 mb-3" />
                <flux:heading size="lg">Job Completed</flux:heading>
                <flux:text class="text-zinc-500 mt-2 max-w-md mx-auto">
                    This job has been completed. Great work!
                </flux:text>
                @if ($job->completed_at)
                    <flux:text class="text-sm text-zinc-400 mt-2">
                        Completed {{ $job->completed_at->format('M j, Y') }}
                    </flux:text>
                @endif
            </div>
        @endif

        @if ($job->description)
            <div class="bg-yellow-50 dark:bg-yellow-900/20 border border-yellow-200 dark:border-yellow-800 rounded-lg p-4 mb-6">
                <h3 class="font-semibold text-yellow-800 dark:text-yellow-200 mb-1">Notes</h3>
                <p class="text-yellow-700 dark:text-yellow-300 text-sm sm:text-base">{{ $job->description }}</p>
            </div>
        @endif

        {{-- 7. Reference Tabs (only visible after accepting) --}}
        @if ($this->hasAccepted)
            <div class="mt-6">
                <div class="flex gap-2 mb-4 overflow-x-auto pb-2 -mx-4 px-4 sm:mx-0 sm:px-0 scrollbar-hide">
                    @foreach ($this->referenceTabs as $tab)
                        @php
                            $isActive = $activeTab === $tab['key'];
                        @endphp
                        <flux:button
                            wire:click="setTab('{{ $tab['key'] }}')"
                            :variant="$isActive ? 'primary' : 'ghost'"
                            :icon="$tab['icon']"
                            class="whitespace-nowrap shrink-0"
                            size="sm"
                        >
                            <span class="hidden sm:inline">{{ $tab['label'] }}</span>
                            <span class="sm:hidden">{{ Str::limit($tab['label'], 8) }}</span>
                        </flux:button>
                    @endforeach
                </div>

            {{-- WORK ORDER TAB --}}
            @if ($activeTab === 'work-order')
            {{-- Expand/Collapse Controls --}}
            <div class="flex items-center justify-between mb-4">
                <flux:heading size="lg">Areas to Paint</flux:heading>
                <div class="flex gap-2">
                    <flux:button variant="ghost" size="sm" wire:click="expandAll">Expand All</flux:button>
                    <flux:button variant="ghost" size="sm" wire:click="collapseAll">Collapse All</flux:button>
                </div>
            </div>

            <div class="space-y-4">
                @foreach ($this->areaBreakdowns as $area)
                    <div
                        class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden"
                        wire:key="area-{{ $area['id'] }}"
                    >
                        {{-- Area Header --}}
                        <div
                            class="flex items-center justify-between p-3 sm:p-4 bg-zinc-50 dark:bg-zinc-800/50 cursor-pointer"
                            wire:click="toggleArea({{ $area['id'] }})"
                        >
                            <div class="flex items-center gap-2 sm:gap-3 min-w-0">
                                <div class="shrink-0">
                                    @if ($expandedAreas[$area['id']] ?? false)
                                        <flux:icon name="chevron-down" class="w-5 h-5 text-zinc-400" />
                                    @else
                                        <flux:icon name="chevron-right" class="w-5 h-5 text-zinc-400" />
                                    @endif
                                </div>
                                <div class="min-w-0">
                                    <h3 class="text-base sm:text-lg font-bold text-zinc-900 dark:text-white truncate">{{ $area['name'] }}</h3>
                                    <div class="text-xs sm:text-sm text-zinc-500">{{ $area['scope_type'] }}</div>
                                </div>
                            </div>
                            <div class="text-right shrink-0 ml-2">
                                <div class="text-base sm:text-lg font-bold text-zinc-900 dark:text-white">
                                    {{ number_format($area['total_hours'], 1) }} hrs
                                </div>
                                <div class="text-xs text-zinc-500 hidden sm:block">
                                    Labor: {{ number_format($area['labor_hours'], 1) }} + Cleanup: {{ number_format($area['cleanup_hours'], 1) }}
                                </div>
                            </div>
                        </div>

                        {{-- Area Content --}}
                        @if ($expandedAreas[$area['id']] ?? false)
                            <div class="border-t border-zinc-200 dark:border-zinc-700 p-3 sm:p-4">
                                @if ($area['substrate'])
                                    <div class="mb-4 p-3 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg">
                                        <p class="font-semibold text-amber-800 dark:text-amber-200 text-sm sm:text-base">
                                            Surface Condition: {{ $area['substrate'] }}
                                        </p>
                                    </div>
                                @endif

                                @if ($area['notes'])
                                    <div class="mb-4 p-3 bg-blue-50 dark:bg-blue-900/20 border border-blue-200 dark:border-blue-800 rounded-lg">
                                        <p class="text-sm text-blue-800 dark:text-blue-200">
                                            <span class="font-semibold">Note:</span> {{ $area['notes'] }}
                                        </p>
                                    </div>
                                @endif

                                <div class="space-y-3">
                                    @foreach ($area['surfaces'] as $surface)
                                        <div class="flex items-start gap-3 sm:gap-4 p-3 border border-zinc-100 dark:border-zinc-700 rounded-lg">
                                            <div class="mt-1 shrink-0">
                                                <div class="w-5 h-5 sm:w-6 sm:h-6 border-2 border-zinc-300 dark:border-zinc-600 rounded"></div>
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <div class="flex flex-wrap items-center gap-1 sm:gap-2">
                                                    <span class="font-semibold text-zinc-900 dark:text-white text-sm sm:text-base">{{ $surface['name'] }}</span>
                                                    <span class="text-zinc-500">-</span>
                                                    <span class="text-zinc-700 dark:text-zinc-300 text-sm sm:text-base">
                                                        {{ number_format($surface['quantity']) }} {{ $surface['unit'] }}
                                                    </span>
                                                </div>
                                                <div class="mt-1 text-xs sm:text-sm text-zinc-600 dark:text-zinc-400">
                                                    <span class="font-medium">{{ $surface['coats'] }} {{ $surface['coats'] === 1 ? 'coat' : 'coats' }}</span>
                                                    <span class="mx-1 sm:mx-2">&bull;</span>
                                                    <span>{{ $surface['application_method'] }}</span>
                                                </div>
                                                @if ($surface['paint_product'])
                                                    <div class="mt-2 text-xs sm:text-sm">
                                                        <span class="inline-flex items-center gap-1 px-2 py-1 bg-indigo-50 dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300 rounded">
                                                            <flux:icon name="paint-brush" class="w-3 h-3 sm:w-4 sm:h-4" />
                                                            <span class="truncate">{{ $surface['paint_product']['full_name'] }}</span>
                                                            @if ($surface['paint_product']['sheen'])
                                                                - {{ $surface['paint_product']['sheen'] }}
                                                            @endif
                                                        </span>
                                                    </div>
                                                @endif
                                                @if (count($surface['prep_notes']) > 0)
                                                    <div class="mt-2 text-xs sm:text-sm text-amber-700 dark:text-amber-300">
                                                        <span class="font-medium">Prep:</span> {{ implode(', ', $surface['prep_notes']) }}
                                                    </div>
                                                @endif
                                            </div>
                                            <div class="text-right shrink-0">
                                                <div class="font-semibold text-zinc-900 dark:text-white text-sm sm:text-base">
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
                                                <div class="flex items-center gap-3 sm:gap-4 p-2">
                                                    <div class="w-5 h-5 sm:w-6 sm:h-6 border-2 border-zinc-300 dark:border-zinc-600 rounded shrink-0"></div>
                                                    <span class="text-zinc-700 dark:text-zinc-300 text-sm">{{ $addon['name'] }}</span>
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
            <div class="mt-6 bg-zinc-900 dark:bg-zinc-950 text-white rounded-lg p-4 sm:p-6">
                <h3 class="text-lg font-bold mb-4">Job Totals</h3>
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 sm:gap-4">
                    <div>
                        <div class="text-xs sm:text-sm text-zinc-400">Labor Hours</div>
                        <div class="text-xl sm:text-2xl font-bold">{{ number_format($this->jobTotals['labor_hours'], 1) }}</div>
                    </div>
                    <div>
                        <div class="text-xs sm:text-sm text-zinc-400">Cleanup Hours</div>
                        <div class="text-xl sm:text-2xl font-bold">{{ number_format($this->jobTotals['cleanup_hours'], 1) }}</div>
                    </div>
                    <div>
                        <div class="text-xs sm:text-sm text-zinc-400">Total Hours</div>
                        <div class="text-xl sm:text-2xl font-bold text-green-400">{{ number_format($this->jobTotals['total_hours'], 1) }}</div>
                    </div>
                    <div>
                        <div class="text-xs sm:text-sm text-zinc-400">Areas</div>
                        <div class="text-xl sm:text-2xl font-bold">{{ $this->jobTotals['area_count'] }}</div>
                    </div>
                </div>
            </div>
        @endif

        {{-- FINANCIALS STAGE --}}
        @if ($activeTab === 'financials')
            {{-- Warning Notice --}}
            <flux:callout variant="warning" class="mb-6">
                <flux:icon name="lock-closed" class="w-5 h-5" />
                <div class="text-sm sm:text-base">
                    <strong>For Crew Lead Only</strong> - This data shows true costs and crew pay. Do not share with employees.
                </div>
            </flux:callout>

            {{-- Materials & Paint Profit Section --}}
            <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 sm:p-6 mb-6">
                <h3 class="text-lg font-bold text-zinc-900 dark:text-white mb-4">
                    <flux:icon name="paint-brush" class="w-5 h-5 inline mr-1" />
                    Materials & Paint Profit
                </h3>

                @if (count($this->materialsProfit['items']) > 0)
                    {{-- Mobile Cards View --}}
                    <div class="sm:hidden space-y-3 mb-4">
                        @foreach ($this->materialsProfit['items'] as $material)
                            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-3" wire:key="mat-mobile-{{ $material['product_id'] }}">
                                <div class="font-medium text-zinc-900 dark:text-white mb-2">
                                    {{ $material['brand'] }} {{ $material['name'] }}
                                    @if ($material['sheen'])
                                        <span class="text-zinc-500">- {{ $material['sheen'] }}</span>
                                    @endif
                                </div>
                                <div class="grid grid-cols-2 gap-2 text-sm">
                                    <div>
                                        <span class="text-zinc-500">Qty:</span>
                                        <span class="font-medium">{{ (int) $material['gallons'] }} gal</span>
                                    </div>
                                    <div>
                                        <span class="text-zinc-500">Charged:</span>
                                        <span class="font-medium">${{ number_format($material['catalog_total'], 2) }}</span>
                                    </div>
                                    <div>
                                        <span class="text-zinc-500">Your Cost/gal:</span>
                                        @if ($material['product_id'])
                                            <input
                                                type="number"
                                                step="0.01"
                                                min="0"
                                                wire:model.live.debounce.500ms="actualPaintCosts.{{ $material['product_id'] }}"
                                                placeholder="${{ number_format($material['catalog_price'], 2) }}"
                                                class="w-20 text-sm border border-zinc-300 dark:border-zinc-600 rounded px-2 py-1 bg-white dark:bg-zinc-700 text-zinc-900 dark:text-white"
                                            />
                                        @else
                                            <span class="text-zinc-400">-</span>
                                        @endif
                                    </div>
                                    <div>
                                        <span class="text-zinc-500">Profit:</span>
                                        <span class="font-medium {{ $material['profit'] !== null ? ($material['profit'] >= 0 ? 'text-green-600' : 'text-red-600') : 'text-zinc-400' }}">
                                            @if ($material['profit'] !== null)
                                                {{ $material['profit'] >= 0 ? '+' : '' }}${{ number_format($material['profit'], 2) }}
                                            @else
                                                -
                                            @endif
                                        </span>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>

                    {{-- Desktop Table View --}}
                    <div class="hidden sm:block overflow-x-auto mb-4">
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

                    {{-- Mobile Summary Card --}}
                    <div class="sm:hidden bg-zinc-50 dark:bg-zinc-700/50 rounded-lg p-3 mt-4">
                        <div class="flex justify-between mb-2">
                            <span class="text-zinc-600 dark:text-zinc-400">Total Materials</span>
                            <span class="font-bold text-zinc-900 dark:text-white">${{ number_format($this->materialsWithCosts['total_materials_cost'] + $this->sundriesBreakdown['total_cost'], 2) }}</span>
                        </div>
                        @if ($this->materialsProfit['total_profit'] !== null)
                            <div class="flex justify-between">
                                <span class="text-zinc-600 dark:text-zinc-400">Paint Profit</span>
                                <span class="font-bold {{ $this->materialsProfit['total_profit'] >= 0 ? 'text-green-600' : 'text-red-600' }}">
                                    {{ $this->materialsProfit['total_profit'] >= 0 ? '+' : '' }}${{ number_format($this->materialsProfit['total_profit'], 2) }}
                                </span>
                            </div>
                        @endif
                    </div>
                @else
                    <div class="text-center py-8 text-zinc-500 dark:text-zinc-400">
                        No paint products assigned to this job.
                    </div>
                @endif
            </div>

            {{-- Individual Painters Section --}}
            <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 sm:p-6 mb-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold text-zinc-900 dark:text-white">
                        <flux:icon name="user-group" class="w-5 h-5 inline mr-1" />
                        Individual Painters
                    </h3>
                    <flux:button variant="primary" size="sm" wire:click="addPainter" icon="plus">
                        Add
                    </flux:button>
                </div>

                @if (count($this->painters) > 0)
                    {{-- Mobile Cards --}}
                    <div class="sm:hidden space-y-3">
                        @foreach ($this->paintersBreakdown['painters'] as $painter)
                            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-3" wire:key="painter-mobile-{{ $painter['index'] }}">
                                <div class="flex items-center justify-between mb-2">
                                    <input
                                        type="text"
                                        wire:model.live.debounce.500ms="painters.{{ $painter['index'] }}.name"
                                        placeholder="Painter name"
                                        class="flex-1 text-sm font-medium border border-zinc-300 dark:border-zinc-600 rounded px-2 py-1 bg-white dark:bg-zinc-700 text-zinc-900 dark:text-white mr-2"
                                    />
                                    <flux:button variant="ghost" size="sm" wire:click="removePainter({{ $painter['index'] }})" icon="x-mark" class="text-red-500 shrink-0" />
                                </div>
                                <div class="grid grid-cols-3 gap-2 text-sm">
                                    <div>
                                        <label class="text-xs text-zinc-500">Rate/hr</label>
                                        <div class="flex items-center gap-1">
                                            <span class="text-zinc-400">$</span>
                                            <input
                                                type="number"
                                                step="0.50"
                                                min="0"
                                                wire:model.live.debounce.500ms="painters.{{ $painter['index'] }}.hourly_rate"
                                                class="w-full text-sm border border-zinc-300 dark:border-zinc-600 rounded px-2 py-1 bg-white dark:bg-zinc-700 text-zinc-900 dark:text-white"
                                            />
                                        </div>
                                    </div>
                                    <div>
                                        <label class="text-xs text-zinc-500">Hours</label>
                                        <input
                                            type="number"
                                            step="0.5"
                                            min="0"
                                            wire:model.live.debounce.500ms="painters.{{ $painter['index'] }}.hours"
                                            placeholder="0"
                                            class="w-full text-sm border border-zinc-300 dark:border-zinc-600 rounded px-2 py-1 bg-white dark:bg-zinc-700 text-zinc-900 dark:text-white"
                                        />
                                    </div>
                                    <div>
                                        <label class="text-xs text-zinc-500">Pay</label>
                                        <div class="font-bold text-zinc-900 dark:text-white py-1">
                                            ${{ number_format($painter['base_pay'], 2) }}
                                        </div>
                                    </div>
                                </div>
                            </div>
                        @endforeach

                        {{-- Mobile Totals --}}
                        <div class="bg-zinc-50 dark:bg-zinc-700/50 rounded-lg p-3 space-y-2">
                            <div class="flex justify-between">
                                <span class="text-zinc-600 dark:text-zinc-400">Crew Wages</span>
                                <span class="font-bold text-zinc-900 dark:text-white">${{ number_format($this->paintersBreakdown['total_base_pay'], 2) }}</span>
                            </div>
                            <div class="flex justify-between text-amber-700 dark:text-amber-300">
                                <span>+ Payroll Burden ({{ round(($this->burdenMultiplier - 1) * 100) }}%)</span>
                                <span class="font-bold">${{ number_format($this->paintersBreakdown['total_burden_cost'], 2) }}</span>
                            </div>
                            <div class="flex justify-between pt-2 border-t border-zinc-200 dark:border-zinc-600">
                                <span class="font-bold text-zinc-900 dark:text-white">Total Labor Cost</span>
                                <span class="font-bold text-zinc-900 dark:text-white">${{ number_format($this->paintersBreakdown['total_labor_cost'], 2) }}</span>
                            </div>
                        </div>
                    </div>

                    {{-- Desktop Table --}}
                    <div class="hidden sm:block overflow-x-auto">
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
            <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 sm:p-6 mb-6">
                <h3 class="text-lg font-bold text-zinc-900 dark:text-white mb-4">
                    <flux:icon name="banknotes" class="w-5 h-5 inline mr-1" />
                    Pay Calculator
                </h3>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 sm:gap-6">
                    {{-- Left Column: Rates --}}
                    <div class="space-y-3">
                        <h4 class="font-semibold text-zinc-700 dark:text-zinc-300 text-sm uppercase tracking-wider">Pay Rates ({{ ucfirst($this->painterPayBreakdown['scope']) }})</h4>

                        <div class="flex justify-between items-center py-2 border-b border-zinc-100 dark:border-zinc-700">
                            <span class="text-zinc-600 dark:text-zinc-400 text-sm">Base Hourly Wage</span>
                            <span class="font-semibold text-zinc-900 dark:text-white">${{ number_format($this->painterPayBreakdown['base_hourly_wage'], 2) }}/hr</span>
                        </div>
                        <div class="flex justify-between items-center py-2 border-b border-zinc-100 dark:border-zinc-700">
                            <span class="text-zinc-600 dark:text-zinc-400 text-sm">Payroll Burden</span>
                            <span class="font-semibold text-zinc-900 dark:text-white">+{{ $this->painterPayBreakdown['payroll_burden_percent'] }}%</span>
                        </div>
                        <div class="flex justify-between items-center py-2 bg-blue-50 dark:bg-blue-900/20 px-3 -mx-3 rounded">
                            <span class="font-semibold text-blue-800 dark:text-blue-200 text-sm">Effective Rate (Loaded)</span>
                            <span class="font-bold text-blue-800 dark:text-blue-200">${{ number_format($this->painterPayBreakdown['effective_hourly_rate'], 2) }}/hr</span>
                        </div>
                    </div>

                    {{-- Right Column: Hours & Pay --}}
                    <div class="space-y-3">
                        <h4 class="font-semibold text-zinc-700 dark:text-zinc-300 text-sm uppercase tracking-wider">Hours & Costs</h4>

                        <div class="flex justify-between items-center py-2 border-b border-zinc-100 dark:border-zinc-700">
                            <span class="text-zinc-600 dark:text-zinc-400 text-sm">Labor Hours</span>
                            <span class="font-semibold text-zinc-900 dark:text-white">{{ number_format($this->painterPayBreakdown['labor_hours'], 1) }} hrs</span>
                        </div>
                        <div class="flex justify-between items-center py-2 border-b border-zinc-100 dark:border-zinc-700">
                            <span class="text-zinc-600 dark:text-zinc-400 text-sm">Cleanup Hours</span>
                            <span class="font-semibold text-zinc-900 dark:text-white">{{ number_format($this->painterPayBreakdown['cleanup_hours'], 1) }} hrs</span>
                        </div>
                        <div class="flex justify-between items-center py-2 border-b border-zinc-100 dark:border-zinc-700">
                            <span class="font-semibold text-zinc-700 dark:text-zinc-300 text-sm">Total Hours</span>
                            <span class="font-bold text-zinc-900 dark:text-white">{{ number_format($this->painterPayBreakdown['total_hours'], 1) }} hrs</span>
                        </div>
                    </div>
                </div>

                {{-- Pay Summary --}}
                <div class="mt-6 pt-6 border-t border-zinc-200 dark:border-zinc-700">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 sm:gap-4">
                        <div class="bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800 rounded-lg p-3 sm:p-4">
                            <div class="text-sm text-green-700 dark:text-green-300">Painter Earns (Base)</div>
                            <div class="text-xl sm:text-2xl font-bold text-green-800 dark:text-green-200">
                                ${{ number_format($this->painterPayBreakdown['painter_earns_base'], 2) }}
                            </div>
                            <div class="text-xs text-green-600 dark:text-green-400">
                                {{ number_format($this->painterPayBreakdown['total_hours'], 1) }} hrs × ${{ number_format($this->painterPayBreakdown['base_hourly_wage'], 2) }}
                            </div>
                        </div>
                        <div class="bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg p-3 sm:p-4">
                            <div class="text-sm text-amber-700 dark:text-amber-300">Company Pays (Loaded)</div>
                            <div class="text-xl sm:text-2xl font-bold text-amber-800 dark:text-amber-200">
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
            <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 sm:p-6 mb-6">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 mb-4">
                    <h3 class="text-lg font-bold text-zinc-900 dark:text-white">
                        <flux:icon name="user-group" class="w-5 h-5 inline mr-1" />
                        Crew Size Calculator
                    </h3>
                    <div class="flex items-center gap-2">
                        <span class="text-sm text-zinc-500 dark:text-zinc-400">Hours/Day:</span>
                        <select wire:model.live="hoursPerDay" class="text-sm border border-zinc-300 dark:border-zinc-600 rounded px-2 py-1.5 bg-white dark:bg-zinc-700 text-zinc-900 dark:text-white">
                            <option value="6">6 hrs</option>
                            <option value="8">8 hrs</option>
                            <option value="10">10 hrs</option>
                        </select>
                    </div>
                </div>

                <p class="text-sm text-zinc-500 dark:text-zinc-400 mb-4">
                    Total labor cost stays the same regardless of crew size. More painters = faster completion.
                </p>

                {{-- Mobile Cards --}}
                <div class="sm:hidden space-y-3">
                    @foreach ($this->crewScheduleGrid['grid'] as $row)
                        <div
                            wire:click="setCrewSize({{ $row['painters'] }})"
                            class="border rounded-lg p-3 cursor-pointer {{ $row['painters'] === $crewSize ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/20' : 'border-zinc-200 dark:border-zinc-700' }}"
                        >
                            <div class="flex items-center justify-between mb-2">
                                <div class="flex items-center gap-1">
                                    @for ($i = 0; $i < $row['painters']; $i++)
                                        <flux:icon name="user" class="w-4 h-4 {{ $row['painters'] === $crewSize ? 'text-blue-600' : 'text-zinc-500' }}" />
                                    @endfor
                                    <span class="ml-1 font-medium {{ $row['painters'] === $crewSize ? 'text-blue-700 dark:text-blue-300' : 'text-zinc-900 dark:text-white' }}">
                                        {{ $row['painters'] }} {{ $row['painters'] === 1 ? 'painter' : 'painters' }}
                                    </span>
                                </div>
                                @if ($row['painters'] === $crewSize)
                                    <flux:icon name="check-circle" class="w-5 h-5 text-blue-600" />
                                @endif
                            </div>
                            <div class="grid grid-cols-3 gap-2 text-sm text-center">
                                <div>
                                    <div class="text-xs text-zinc-500">6hr day</div>
                                    <div class="font-medium">{{ $row['days_needed'][6] }}d</div>
                                </div>
                                <div>
                                    <div class="text-xs text-zinc-500">8hr day</div>
                                    <div class="font-medium">{{ $row['days_needed'][8] }}d</div>
                                </div>
                                <div>
                                    <div class="text-xs text-zinc-500">10hr day</div>
                                    <div class="font-medium">{{ $row['days_needed'][10] }}d</div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>

                {{-- Desktop Table --}}
                <div class="hidden sm:block overflow-x-auto">
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
            <div class="bg-zinc-900 dark:bg-zinc-950 text-white rounded-lg p-4 sm:p-6 mb-6">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-2 mb-4">
                    <h3 class="text-lg font-bold">
                        <flux:icon name="chart-bar" class="w-5 h-5 inline mr-1 text-white" />
                        Job Profit Summary
                    </h3>
                    @if (! $this->profitSummary['has_actuals'])
                        <span class="text-xs px-2 py-1 bg-zinc-700 rounded text-zinc-400 self-start sm:self-auto">Using estimated costs</span>
                    @else
                        <span class="text-xs px-2 py-1 bg-blue-900 rounded text-blue-300 self-start sm:self-auto">Using actual costs</span>
                    @endif
                </div>

                {{-- Job Price --}}
                <div class="flex justify-between items-center py-3 border-b-2 border-zinc-600 mb-2">
                    <span class="text-base sm:text-lg font-bold">Job Price</span>
                    <span class="text-xl sm:text-2xl font-bold text-white">${{ number_format($this->profitSummary['job_price'], 2) }}</span>
                </div>
                <div class="text-xs text-zinc-500 mb-4">Company pays (loaded labor) + materials</div>

                <div class="space-y-2">
                    <div class="flex justify-between items-center py-2 border-b border-zinc-700">
                        <span class="text-zinc-300 text-sm">Crew Wages</span>
                        <span class="text-red-300 font-medium">−${{ number_format($this->profitSummary['crew_wages'], 2) }}</span>
                    </div>
                    <div class="flex justify-between items-center py-2 border-b border-zinc-700">
                        <span class="text-zinc-300 text-sm">Payroll Burden</span>
                        <span class="text-amber-400 font-medium">−${{ number_format($this->profitSummary['burden_cost'], 2) }}</span>
                    </div>
                    <div class="flex justify-between items-center py-2 border-b border-zinc-700">
                        <span class="text-zinc-300 text-sm">Paint Cost</span>
                        <span class="text-red-300 font-medium">−${{ number_format($this->profitSummary['paint_cost'], 2) }}</span>
                    </div>
                    <div class="flex justify-between items-center py-2 border-b border-zinc-700">
                        <span class="text-zinc-300 text-sm">Sundries</span>
                        <span class="text-red-300 font-medium">−${{ number_format($this->profitSummary['sundries_cost'], 2) }}</span>
                    </div>
                    <div class="flex justify-between items-center py-2 border-b border-zinc-700">
                        <span class="text-zinc-300 text-sm">Recycle Fees</span>
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
                    <span class="text-base sm:text-lg font-bold">Your Profit</span>
                    <div class="text-right">
                        <span class="text-xl sm:text-2xl font-bold {{ $this->profitSummary['gross_profit'] >= 0 ? 'text-green-400' : 'text-red-400' }}">
                            {{ $this->profitSummary['gross_profit'] >= 0 ? '+' : '' }}${{ number_format($this->profitSummary['gross_profit'], 2) }}
                        </span>
                        <span class="text-sm text-zinc-500 ml-1">({{ number_format($this->profitSummary['gross_margin_percent'], 1) }}%)</span>
                    </div>
                </div>
            </div>

            {{-- Save Controls --}}
            <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 sm:p-6 mb-6">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-bold text-zinc-900 dark:text-white">Save Profit Data</h3>
                        @if ($this->job->estimate?->profit_snapshot)
                            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">
                                Last snapshot: {{ \Carbon\Carbon::parse($this->job->estimate->profit_snapshot['captured_at'])->format('M j, Y g:i A') }}
                            </p>
                        @else
                            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">No snapshot saved yet.</p>
                        @endif
                    </div>
                    <div class="flex gap-2">
                        <flux:button variant="ghost" wire:click="saveActuals" icon="document-check" class="flex-1 sm:flex-none">
                            Save Actuals
                        </flux:button>
                        <flux:button variant="primary" wire:click="saveSnapshot" icon="camera" class="flex-1 sm:flex-none">
                            Save Snapshot
                        </flux:button>
                    </div>
                </div>
            </div>
        @endif

        {{-- COLORS STAGE --}}
        @if ($activeTab === 'colors')
            @if ($job->colors->count() > 0)
                <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 sm:p-6">
                    <flux:heading size="sm" class="mb-4">Paint Colors</flux:heading>
                    <div class="grid gap-3 sm:gap-4 grid-cols-1 sm:grid-cols-2 md:grid-cols-3">
                        @foreach ($job->colors as $color)
                            <div class="flex items-center gap-3 sm:gap-4 p-3 sm:p-4 rounded-lg border border-zinc-200 dark:border-zinc-700">
                                @if ($color->hex_value)
                                    <div class="w-12 h-12 sm:w-16 sm:h-16 rounded-lg border border-zinc-300 shrink-0" style="background-color: {{ $color->hex_value }}"></div>
                                @else
                                    <div class="w-12 h-12 sm:w-16 sm:h-16 rounded-lg border border-zinc-300 bg-zinc-100 dark:bg-zinc-700 flex items-center justify-center shrink-0">
                                        <flux:icon name="paint-brush" class="w-5 h-5 sm:w-6 sm:h-6 text-zinc-400" />
                                    </div>
                                @endif
                                <div class="min-w-0">
                                    <flux:text class="font-bold text-base sm:text-lg truncate">{{ $color->name }}</flux:text>
                                    @if ($color->brand)
                                        <flux:text class="text-sm text-zinc-500 truncate">{{ $color->brand }}</flux:text>
                                    @endif
                                    @if ($color->code)
                                        <flux:text class="text-xs text-zinc-400 font-mono">{{ $color->code }}</flux:text>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @else
                <div class="text-center py-12 border border-zinc-200 dark:border-zinc-700 rounded-lg">
                    <flux:icon name="paint-brush" class="w-12 h-12 mx-auto text-zinc-300 dark:text-zinc-600 mb-4" />
                    <flux:heading>No Colors Assigned Yet</flux:heading>
                    <flux:text class="text-zinc-500 mt-2">Paint colors will appear here once confirmed.</flux:text>
                </div>
            @endif
        @endif

        {{-- MATERIALS STAGE --}}
        @if ($activeTab === 'materials')
            @if (count($this->materialsNeeded) > 0)
                <div class="bg-white dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 sm:p-6">
                    <flux:heading size="sm" class="mb-4">Materials Needed</flux:heading>
                    <div class="space-y-3">
                        @foreach ($this->materialsNeeded as $material)
                            <div class="flex items-center gap-3 sm:gap-4 p-3 sm:p-4 bg-zinc-50 dark:bg-zinc-700/50 rounded-lg">
                                <div class="w-8 h-8 sm:w-10 sm:h-10 border-2 border-zinc-300 dark:border-zinc-600 rounded shrink-0"></div>
                                <div class="flex-1 min-w-0">
                                    <div class="font-semibold text-zinc-900 dark:text-white text-sm sm:text-base truncate">
                                        {{ $material['brand'] }} {{ $material['name'] }}
                                        @if ($material['sheen'])
                                            - {{ $material['sheen'] }}
                                        @endif
                                    </div>
                                </div>
                                <div class="text-right shrink-0">
                                    <div class="font-bold text-base sm:text-lg">{{ ceil($material['gallons']) }}</div>
                                    <div class="text-xs text-zinc-500">{{ ceil($material['gallons']) === 1 ? 'gallon' : 'gallons' }}</div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @else
                <div class="text-center py-12 border border-zinc-200 dark:border-zinc-700 rounded-lg">
                    <flux:icon name="shopping-cart" class="w-12 h-12 mx-auto text-zinc-300 dark:text-zinc-600 mb-4" />
                    <flux:heading>No Materials Listed</flux:heading>
                    <flux:text class="text-zinc-500 mt-2">Material requirements will appear here.</flux:text>
                </div>
            @endif
        @endif

        @if ($activeTab === 'photos')
            @if ($job->deal?->property)
                <livewire:shared.photo-gallery
                    :property="$job->deal->property"
                    :job="$job"
                    context="job-crew"
                    :key="'crew-photos-'.$job->id"
                />
            @else
                <div class="text-center py-12 border border-zinc-200 dark:border-zinc-700 rounded-lg">
                    <flux:icon name="photo" class="w-12 h-12 mx-auto text-zinc-300 dark:text-zinc-600 mb-4" />
                    <flux:heading>No Photos</flux:heading>
                    <flux:text class="text-zinc-500 mt-2">No property is linked to this job.</flux:text>
                </div>
            @endif
        @endif
            </div>
        @endif {{-- end hasAccepted reference tabs --}}

        {{-- 8. Embedded Chat --}}
        @if ($this->hasAccepted && $this->chatThread)
            <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg mt-6 overflow-hidden" wire:poll.10s>
                <div class="p-4 border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800">
                    <div class="flex items-center gap-2">
                        <flux:icon name="chat-bubble-left-right" class="w-5 h-5 text-zinc-500" />
                        <flux:heading size="sm">Job Conversation</flux:heading>
                    </div>
                    <flux:text class="text-xs text-zinc-400 mt-1">Discuss scheduling, tasks, or any questions about this job.</flux:text>
                </div>

                <div class="max-h-80 overflow-y-auto p-4 space-y-3 bg-white dark:bg-zinc-900" x-data x-init="$el.scrollTop = $el.scrollHeight">
                    @forelse ($this->chatMessages as $message)
                        @php
                            $isOwnMessage = $message->user_id === auth('crew')->id();
                            $isSystem = $message->type === 'system';
                        @endphp

                        <div wire:key="msg-{{ $message->id }}" class="{{ $isOwnMessage ? 'flex justify-end' : 'flex justify-start' }}">
                            @if ($isSystem)
                                <div class="w-full text-center py-1">
                                    <flux:text class="text-xs text-zinc-400 italic">{{ $message->content }}</flux:text>
                                </div>
                            @else
                                <div class="flex items-start gap-2 max-w-[80%] {{ $isOwnMessage ? 'flex-row-reverse' : '' }}">
                                    @if ($message->user)
                                        <flux:avatar size="xs" :initials="$message->user->initials()" class="bg-zinc-200 dark:bg-zinc-700 shrink-0" />
                                    @else
                                        <div class="w-6 h-6 rounded-full bg-zinc-200 dark:bg-zinc-700 flex items-center justify-center shrink-0">
                                            <flux:icon name="user" class="w-3 h-3 text-zinc-500" />
                                        </div>
                                    @endif

                                    <div class="{{ $isOwnMessage ? 'text-right' : 'text-left' }}">
                                        <div class="flex items-center gap-1 mb-0.5 {{ $isOwnMessage ? 'flex-row-reverse' : '' }}">
                                            <flux:text class="text-xs font-medium text-zinc-500">{{ $message->user?->name ?? 'Unknown' }}</flux:text>
                                            <flux:text class="text-xs text-zinc-400">{{ $message->created_at->diffForHumans() }}</flux:text>
                                        </div>
                                        <div class="rounded-lg px-3 py-2 text-sm {{ $isOwnMessage ? 'bg-blue-600 text-white' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100' }}">
                                            <flux:text class="whitespace-pre-wrap break-words">{{ $message->content }}</flux:text>
                                        </div>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @empty
                        <div class="text-center py-6">
                            <flux:icon name="chat-bubble-left" class="mx-auto h-8 w-8 text-zinc-300 dark:text-zinc-600 mb-2" />
                            <flux:text class="text-sm text-zinc-500">No messages yet. Send a message to discuss this job.</flux:text>
                        </div>
                    @endforelse
                </div>

                <div class="border-t border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800 p-3">
                    <livewire:crew.chat.message-composer :thread="$this->chatThread" :key="'job-composer-'.$this->chatThread->id" />
                </div>
            </div>
        @endif
    </div>

    {{-- Hide scrollbar but allow scrolling --}}
    <style>
        .scrollbar-hide::-webkit-scrollbar {
            display: none;
        }
        .scrollbar-hide {
            -ms-overflow-style: none;
            scrollbar-width: none;
        }
    </style>
</div>
