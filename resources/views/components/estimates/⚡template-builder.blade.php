<?php

use App\Events\EstimateAreaUpdated;
use App\Models\CompanySetting;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateAiMessage;
use App\Models\EstimateAiThread;
use App\Models\EstimateArea;
use App\Models\EstimateAreaAddon;
use App\Models\EstimateTemplateCategory;
use App\Models\EstimateAreaSurface;
use App\Models\EstimateTemplate;
use App\Models\NestedAreaTemplate;
use App\Models\PaintProduct;
use App\Models\ProductionRate;
use App\Models\Substrate;
use App\Services\EstimateAmendmentService;
use App\Services\EstimatePackageGeneratorService;
use App\Services\EstimateTemplateService;
use App\Services\EstimateTierPricingService;
use App\Services\PythonBridgeService;
use App\Services\TemplatePricingService;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

new class extends Component
{
    use WithFileUploads;

    public ?Deal $deal = null;

    public ?Estimate $estimate = null;

    public bool $isEditing = false;

    public string $title = '';

    /** @var array<int, array{template_id: int, name: string, scope_type: string, quantity: float, coats: int, price: float, default_quantity: float, pricing_method: string, icon: ?string, labor_hours: float, labor_cost: float, cleanup_hours: float, cleanup_cost: float, sundries_cost: float, material_cost: float, base_total: float, with_margins: float}> */
    public array $selectedTemplates = [];

    /**
     * NEW: Areas array for the redesigned builder.
     * Each area contains dimensions, surfaces, and addons with full PBP breakdown.
     *
     * @var array<int, array{name: string, scope_type: string, source_template_id: ?int, length: ?float, width: float, height: float, wall_sqft: float, ceiling_sqft: float, perimeter_lnft: float, default_coats: int, surfaces: array, addons: array, labor_hours: float, labor_cost: float, cleanup_hours: float, cleanup_cost: float, sundries_cost: float, material_cost: float, addon_cost: float, base_total: float, with_margins: float, price: float}>
     */
    public array $areas = [];

    public ?float $taxRate = 0;

    public ?float $discountAmount = 0;

    public ?float $marginDiscountPercent = 0;

    public ?float $hourlyRateOverride = null;

    public ?float $depositPercent = 30;

    public string $notes = '';

    public ?string $validUntil = null;

    // Default paint product for the estimate (cascades to areas/surfaces)
    public ?int $defaultPaintProductId = null;

    /**
     * Paint selection mode: 'single' or 'category'.
     * - single: Use defaultPaintProductId for all surfaces
     * - category: Use paintByCategory for per-surface-type paint selection
     */
    public string $paintMode = 'single';

    /**
     * Paint product overrides by surface category.
     * Structure: ['walls' => 5, 'ceiling' => 7, 'trim' => 12, 'doors' => 12]
     *
     * @var array<string, int|null>
     */
    public array $paintByCategory = [];

    public string $activeTab = 'room';

    /**
     * Project scope (interior/exterior) - primary filter for the entire estimate.
     * When changed, filters all templates and removes non-matching selections.
     */
    public string $projectScope = 'interior';

    public string $scopeFilter = 'interior';


    // Tier paint pricing (Good / Better / Best)
    public bool $useTierPricing = false;

    /** @var array<string, array{mode: string, default_paint_product_id: int|null, paint_by_category: array}> */
    public array $tierPaintConfig = [
        'good' => ['mode' => 'single', 'default_paint_product_id' => null, 'paint_by_category' => []],
        'better' => ['mode' => 'single', 'default_paint_product_id' => null, 'paint_by_category' => []],
        'best' => ['mode' => 'single', 'default_paint_product_id' => null, 'paint_by_category' => []],
    ];

    // AI Chat Panel
    public bool $showAiChat = false;

    public string $aiChatInput = '';

    /** @var \Illuminate\Support\Collection<int, \App\Models\EstimateAiThread> */
    public $aiThreads;

    public ?int $activeThreadId = null;

    public int $maxConcurrentThreads = 5;

    public ?string $aiError = null;

    public string $aiProgressLabel = '';

    public $voiceRecording = null;

    public bool $canUseAiChat = false;


    protected function rules(): array
    {
        // Use areas if available, otherwise fall back to selectedTemplates
        $hasAreas = ! empty($this->areas);

        $rules = [
            'title' => ['required', 'string', 'max:255'],
            'taxRate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'discountAmount' => ['nullable', 'numeric', 'min:0'],
            'marginDiscountPercent' => ['nullable', 'numeric', 'min:0', 'max:15'],
            'hourlyRateOverride' => ['nullable', 'numeric', 'min:10', 'max:100'],
            'depositPercent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'validUntil' => ['nullable', 'date', ...($this->isEditing ? [] : ['after_or_equal:today'])],
        ];

        if ($hasAreas) {
            $rules['areas'] = ['required', 'array', 'min:1'];
            $rules['areas.*.name'] = ['required', 'string', 'max:255'];
            $rules['areas.*.price'] = ['required', 'numeric', 'min:0'];
        } else {
            $rules['selectedTemplates'] = ['required', 'array', 'min:1'];
            // template_id can be null for custom areas created via AreaBuilder
            $rules['selectedTemplates.*.template_id'] = ['nullable', 'integer', 'exists:estimate_templates,id'];
            $rules['selectedTemplates.*.quantity'] = ['required', 'numeric', 'min:0.01'];
            $rules['selectedTemplates.*.coats'] = ['required', 'integer', 'min:1', 'max:4'];
            $rules['selectedTemplates.*.price'] = ['required', 'numeric', 'min:0'];
        }

        return $rules;
    }

    protected function messages(): array
    {
        return [
            'title.required' => __('Please enter a title for the estimate.'),
            'selectedTemplates.required' => __('Please add at least one area or template.'),
            'selectedTemplates.min' => __('Please add at least one area or template.'),
            'areas.required' => __('Please add at least one area.'),
            'areas.min' => __('Please add at least one area.'),
        ];
    }

    public function mount(?Deal $deal = null, ?Estimate $estimate = null): void
    {
        $settings = CompanySetting::getInstance();

        // Load defaults from company settings
        $this->taxRate = (float) ($settings->default_tax_rate ?? 0);
        $this->depositPercent = (float) ($settings->default_deposit_percent ?? 30);

        // Set default valid until based on company settings
        $validityDays = $settings->estimate_validity_days ?? 30;
        $this->validUntil = now()->addDays($validityDays)->format('Y-m-d');

        // Default project scope to interior, sync filter
        $this->projectScope = 'interior';
        $this->scopeFilter = 'interior';

        if ($estimate && $estimate->exists) {
            $this->estimate = $estimate;
            $this->deal = $estimate->deal?->load('property');
            $this->isEditing = true;

            $this->title = $estimate->title;
            $this->taxRate = (float) $estimate->tax_rate;
            $this->discountAmount = (float) $estimate->discount_amount;
            $this->marginDiscountPercent = (float) ($estimate->margin_discount_percent ?? 0);
            $this->hourlyRateOverride = $estimate->hourly_rate_override !== null ? (float) $estimate->hourly_rate_override : null;
            $this->depositPercent = (float) $estimate->deposit_percent;
            $this->notes = $estimate->notes ?? '';
            $this->validUntil = $estimate->valid_until?->format('Y-m-d');
            $this->defaultPaintProductId = $estimate->default_paint_product_id;

            // Load existing paint category overrides
            $scope = $estimate->project_type ?? 'interior';
            $this->projectScope = $scope;
            $this->scopeFilter = $scope;
            $this->paintByCategory = ($estimate->paint_products_by_category[$scope] ?? []);

            // Determine paint mode based on saved state
            if (! empty($this->paintByCategory)) {
                $this->paintMode = 'category';
            } elseif ($this->defaultPaintProductId) {
                $this->paintMode = 'single';
            }

            // Hydrate tier pricing config
            if ($estimate->hasPaintTiers()) {
                $this->useTierPricing = true;
                foreach (['good', 'better', 'best'] as $tier) {
                    $config = $estimate->getPaintTierConfig($tier);
                    if ($config) {
                        $this->tierPaintConfig[$tier] = $config;
                    }
                }
            }

            // Load existing template usages (template mode only)
            if ($estimate->isTemplateMode()) {
                $this->loadExistingTemplateUsages();

                // Detect project scope from existing templates
                $this->detectProjectScopeFromTemplates();

                // Compute tier prices for all loaded templates (Good = card price, Better/Best from tier paints)
                if ($this->useTierPricing) {
                    $this->computeInitialTierPrices();
                }
            }
        } else {
            $this->deal = $deal?->load('property');

            // Load company default paint products for the current scope (new estimates only)
            $companyDefaults = $settings->getPaintDefaultsForScope($this->projectScope);
            if (! empty($companyDefaults)) {
                // Pre-populate paint by category with company defaults
                // User can switch to 'single' mode or override these
                $this->paintByCategory = $companyDefaults;
                $this->paintMode = 'category';
            }

            // New estimates default to tier pricing ON so AI can set Good/Better/Best immediately
            $this->useTierPricing = true;
        }

        // Check AI permission
        $this->canUseAiChat = Auth::user()?->can('ai.estimate_builder') ?? false;

        // Load existing AI threads from DB
        $this->mountAiThreads();
    }

    /**
     * Load existing AI threads from database.
     */
    protected function mountAiThreads(): void
    {
        if ($this->estimate?->id) {
            $this->aiThreads = $this->estimate->aiThreads()->with('messages')->get();
            $this->activeThreadId = $this->aiThreads->last()?->id;
        } else {
            $this->aiThreads = collect();
        }
    }

    /**
     * Detect the project scope from existing template usages.
     */
    protected function detectProjectScopeFromTemplates(): void
    {
        if (empty($this->selectedTemplates)) {
            return;
        }

        // Count templates by scope
        $interiorCount = collect($this->selectedTemplates)
            ->filter(fn ($t) => ($t['scope_type'] ?? 'interior') === 'interior')
            ->count();

        $exteriorCount = collect($this->selectedTemplates)
            ->filter(fn ($t) => ($t['scope_type'] ?? 'interior') === 'exterior')
            ->count();

        // Set scope based on majority
        $this->projectScope = $exteriorCount > $interiorCount ? 'exterior' : 'interior';
        $this->scopeFilter = $this->projectScope;
    }

    /**
     * Load existing template usages from the estimate.
     */
    protected function loadExistingTemplateUsages(): void
    {
        if (! $this->estimate) {
            return;
        }

        $this->selectedTemplates = [];

        // Collect fixed-price areas to group by nested_area_template_id
        $fixedPriceGroups = [];

        foreach ($this->estimate->templateUsages()->with(['template.templateCategory', 'template.productionRate', 'resultable' => function (MorphTo $morphTo) {
            $morphTo->morphWith([
                EstimateArea::class => ['surfaces.productionRate', 'addons', 'nestedAreaTemplate'],
            ]);
        }])->get() as $usage) {
            // Handle fixed-price usages (template_id is null, resultable is a fixed-price EstimateArea)
            if (! $usage->template_id && $usage->resultable instanceof EstimateArea && $usage->resultable->is_fixed_price) {
                $area = $usage->resultable;
                $nestedId = $area->nested_area_template_id;

                if ($nestedId && isset($fixedPriceGroups[$nestedId])) {
                    // Increment quantity for already-seen nested template
                    $fixedPriceGroups[$nestedId]['quantity']++;
                    $fixedPriceGroups[$nestedId]['price'] = $fixedPriceGroups[$nestedId]['unit_price'] * $fixedPriceGroups[$nestedId]['quantity'];
                    $fixedPriceGroups[$nestedId]['base_total'] = $fixedPriceGroups[$nestedId]['price'];
                    $fixedPriceGroups[$nestedId]['with_margins'] = $fixedPriceGroups[$nestedId]['price'];
                } else {
                    $nestedTemplate = $area->nestedAreaTemplate;
                    $fixedPriceGroups[$nestedId ?? $area->id] = [
                        'is_fixed_price' => true,
                        'nested_template_id' => $nestedId,
                        'template_id' => null,
                        'name' => $area->name,
                        'code' => $nestedTemplate?->code ?? '',
                        'scope_type' => $area->scope_type ?? 'interior',
                        'category' => $nestedTemplate?->category ?? 'other',
                        'quantity' => 1,
                        'coats' => 1,
                        'unit_price' => (float) $area->fixed_price_amount,
                        'price' => (float) $area->fixed_price_amount,
                        'labor_hours' => (float) ($area->estimated_labor_hours ?? 0),
                        'labor_cost' => (float) ($area->estimated_labor_cost ?? 0),
                        'cleanup_hours' => 0,
                        'cleanup_cost' => 0,
                        'sundries_cost' => 0,
                        'material_cost' => (float) ($area->estimated_material_cost ?? 0),
                        'base_total' => (float) $area->fixed_price_amount,
                        'with_margins' => (float) $area->fixed_price_amount,
                        'package_tiers' => $area->package_tiers,
                        'sizes' => null,
                        'selected_size' => null,
                        'surface_ratios' => null,
                        'surfaces' => null,
                        'area_data' => null,
                    ];
                }

                continue;
            }

            $template = $usage->template;

            // Handle AI-built areas (template_id is null, resultable is a regular EstimateArea)
            if (! $template && $usage->resultable instanceof EstimateArea && ! $usage->resultable->is_fixed_price) {
                $area = $usage->resultable;
                [$surfaces, $totalLaborHours, $totalLaborCost, $totalMaterialCost] = $this->buildSurfaceArrayFromArea($area);
                [$addons, $addonTotal] = $this->buildAddonArrayFromArea($area);

                $settings = CompanySetting::getInstance();
                $scopeType = $area->scope_type ?? 'interior';
                $hourlyWage = $this->getEffectiveRateForScope($scopeType);

                $cleanupHours = $settings->calculateCleanupHours($totalLaborHours, $scopeType);
                $cleanupCost = $cleanupHours * $hourlyWage;
                $sundriesCost = $settings->calculateSundriesCost($totalLaborHours, $scopeType);

                $cogsResult = $settings->calculateCOGS($totalLaborCost, $cleanupCost, $sundriesCost, $totalMaterialCost + $addonTotal);
                $cogs = $cogsResult['cogs'];
                $effectiveMargin = min(99, $settings->getMarginPercentForScope($scopeType) + ($this->marginDiscountPercent ?? 0));
                $withMargins = round($cogs / ($effectiveMargin / 100), 2);

                // Use cached values from previous save if available (avoids rounding divergence)
                if ($area->cached_customer_price !== null) {
                    $withMargins = (float) $area->cached_customer_price;
                }
                if ($area->cached_cogs !== null) {
                    $cogs = (float) $area->cached_cogs;
                }

                $this->selectedTemplates[] = [
                    'template_id' => null,
                    'name' => $area->name,
                    'scope_type' => $scopeType,
                    'quantity' => $area->wall_sqft,
                    'coats' => $area->surfaces->first()?->coats ?? 2,
                    'price' => round($withMargins, 2),
                    'default_quantity' => $area->wall_sqft,
                    'pricing_method' => 'per_sqft',
                    'icon' => null,
                    'category_type' => 'room',
                    'labor_hours' => round($totalLaborHours, 2),
                    'labor_cost' => round($totalLaborCost, 2),
                    'cleanup_hours' => round($cleanupHours, 2),
                    'cleanup_cost' => round($cleanupCost, 2),
                    'sundries_cost' => round($sundriesCost, 2),
                    'material_cost' => round($totalMaterialCost, 2),
                    'material_tax' => $cogsResult['material_tax'],
                    'labor_tax' => $cogsResult['labor_tax'],
                    'base_total' => $cogs,
                    'with_margins' => round($withMargins, 2),
                    'sizes' => null,
                    'selected_size' => null,
                    'surface_ratios' => null,
                    'surfaces' => null,
                    'service_category_id' => $area->service_category_id,
                    'area_data' => [
                        'length' => $area->length,
                        'width' => $area->width,
                        'height' => $area->height,
                        'wall_sqft' => $area->wall_sqft,
                        'ceiling_sqft' => $area->ceiling_sqft,
                        'perimeter_lnft' => $area->perimeter_lnft,
                        'measurement_type' => $area->measurement_type ?? 'dimensions',
                        'wall_count' => $area->wall_count ?? 4,
                        'direct_sqft' => $area->direct_sqft,
                        'direct_lnft' => $area->direct_lnft,
                        'direct_ceiling_sqft' => $area->direct_ceiling_sqft,
                        'surfaces' => $surfaces,
                        'addons' => $addons,
                        'package_tiers' => $area->package_tiers,
                    ],
                    ...$this->restoreTierDataFromArea($area),
                ];

                continue;
            }

            if (! $template) {
                continue;
            }

            $breakdown = $this->getTemplateCostBreakdown($template->id, (float) $usage->quantity, $usage->coats);

            $templateEntry = [
                'template_id' => $template->id,
                'name' => __($template->name),
                'scope_type' => $template->scope_type ?? 'interior',
                'quantity' => (float) $usage->quantity,
                'coats' => $usage->coats,
                'price' => $breakdown['with_margins'], // Use recalculated price with current margin settings
                'default_quantity' => (float) $template->default_quantity,
                'pricing_method' => $template->pricing_method,
                'icon' => $template->icon,
                'category_type' => $template->templateCategory?->type,
                'labor_hours' => $breakdown['labor_hours'],
                'labor_cost' => $breakdown['labor_cost'],
                'cleanup_hours' => $breakdown['cleanup_hours'],
                'cleanup_cost' => $breakdown['cleanup_cost'],
                'sundries_cost' => $breakdown['sundries_cost'],
                'material_cost' => $breakdown['material_cost'],
                'base_total' => $breakdown['base_total'],
                'with_margins' => $breakdown['with_margins'],
                'sizes' => null,
                'selected_size' => null,
                'surface_ratios' => null,
                'surfaces' => null,
                'area_data' => null,
            ];

            // If this usage has an associated EstimateArea, load the area_data
            if ($usage->resultable instanceof EstimateArea) {
                $area = $usage->resultable;
                $templateEntry['name'] = $area->name;
                $templateEntry['scope_type'] = $area->scope_type ?? 'interior';
                $templateEntry['quantity'] = $area->wall_sqft;

                // Build surfaces array and calculate totals from EstimateAreaSurface models
                $surfaces = [];
                $totalLaborHours = 0;
                $totalLaborCost = 0;
                $totalMaterialCost = 0;

                [$surfaces, $totalLaborHours, $totalLaborCost, $totalMaterialCost] = $this->buildSurfaceArrayFromArea($area);
                [$addons, $addonTotal] = $this->buildAddonArrayFromArea($area);

                // Recalculate the breakdown using PBP formula with actual surface data
                $settings = CompanySetting::getInstance();
                $scopeType = $area->scope_type ?? 'interior';
                $hourlyWage = $this->getEffectiveRateForScope($scopeType);

                $cleanupHours = $settings->calculateCleanupHours($totalLaborHours, $scopeType);
                $cleanupCost = $cleanupHours * $hourlyWage; // Effective rate = base wage × payroll burden
                $sundriesCost = $settings->calculateSundriesCost($totalLaborHours, $scopeType);

                // Calculate COGS with taxes
                $cogsResult = $settings->calculateCOGS($totalLaborCost, $cleanupCost, $sundriesCost, $totalMaterialCost + $addonTotal);
                $cogs = $cogsResult['cogs'];
                $effectiveMargin = min(99, $settings->getMarginPercentForScope($scopeType) + ($this->marginDiscountPercent ?? 0));
                $withMargins = round($cogs / ($effectiveMargin / 100), 2);

                // Use cached values from previous save if available (avoids rounding divergence)
                if ($area->cached_customer_price !== null) {
                    $withMargins = (float) $area->cached_customer_price;
                }
                if ($area->cached_cogs !== null) {
                    $cogs = (float) $area->cached_cogs;
                }

                // Update template entry with calculated values from surfaces
                $templateEntry['labor_hours'] = round($totalLaborHours, 2);
                $templateEntry['labor_cost'] = round($totalLaborCost, 2);
                $templateEntry['cleanup_hours'] = round($cleanupHours, 2);
                $templateEntry['cleanup_cost'] = round($cleanupCost, 2);
                $templateEntry['sundries_cost'] = round($sundriesCost, 2);
                $templateEntry['material_cost'] = round($totalMaterialCost, 2);
                $templateEntry['material_tax'] = $cogsResult['material_tax'];
                $templateEntry['labor_tax'] = $cogsResult['labor_tax'];
                $templateEntry['base_total'] = $cogs;
                $templateEntry['with_margins'] = round($withMargins, 2);
                $templateEntry['price'] = round($withMargins, 2); // Keep price in sync with margins

                $templateEntry['service_category_id'] = $area->service_category_id;
                $templateEntry['area_data'] = [
                    'length' => $area->length,
                    'width' => $area->width,
                    'height' => $area->height,
                    'wall_sqft' => $area->wall_sqft,
                    'ceiling_sqft' => $area->ceiling_sqft,
                    'perimeter_lnft' => $area->perimeter_lnft,
                    'measurement_type' => $area->measurement_type ?? 'dimensions',
                    'wall_count' => $area->wall_count ?? 4,
                    'direct_sqft' => $area->direct_sqft,
                    'direct_lnft' => $area->direct_lnft,
                    'direct_ceiling_sqft' => $area->direct_ceiling_sqft,
                    'surfaces' => $surfaces,
                    'addons' => $addons,
                    'package_tiers' => $area->package_tiers,
                ];

                // Restore cached tier prices from previous save
                $tierData = $this->restoreTierDataFromArea($area);
                if ($tierData['tier_prices']) {
                    $templateEntry['tier_prices'] = $tierData['tier_prices'];
                    $templateEntry['tier_cogs'] = $tierData['tier_cogs'];
                }
            }

            $this->selectedTemplates[] = $templateEntry;
        }

        // Append grouped fixed-price entries
        foreach ($fixedPriceGroups as $entry) {
            $this->selectedTemplates[] = $entry;
        }

        // Load "orphan" areas created directly (e.g. by AI builder) without template usages
        $usedAreaIds = $this->estimate->templateUsages()
            ->where('resultable_type', EstimateArea::class)
            ->pluck('resultable_id')
            ->toArray();

        $orphanAreas = $this->estimate->areas()
            ->with(['surfaces.productionRate', 'addons.nestedAreaTemplate'])
            ->whereNotIn('id', $usedAreaIds)
            ->where('is_fixed_price', false)
            ->get();

        foreach ($orphanAreas as $area) {
            [$surfaces, $totalLaborHours, $totalLaborCost, $totalMaterialCost] = $this->buildSurfaceArrayFromArea($area);
            [$addons, $addonTotal] = $this->buildAddonArrayFromArea($area);

            $settings = CompanySetting::getInstance();
            $scopeType = $area->scope_type ?? 'interior';
            $hourlyWage = $this->getEffectiveRateForScope($scopeType);

            $cleanupHours = $settings->calculateCleanupHours($totalLaborHours, $scopeType);
            $cleanupCost = $cleanupHours * $hourlyWage;
            $sundriesCost = $settings->calculateSundriesCost($totalLaborHours, $scopeType);

            $cogsResult = $settings->calculateCOGS($totalLaborCost, $cleanupCost, $sundriesCost, $totalMaterialCost + $addonTotal);
            $cogs = $cogsResult['cogs'];
            $effectiveMargin = min(99, $settings->getMarginPercentForScope($scopeType) + ($this->marginDiscountPercent ?? 0));
            $withMargins = round($cogs / ($effectiveMargin / 100), 2);

            // Use cached values from previous save if available (avoids rounding divergence)
            if ($area->cached_customer_price !== null) {
                $withMargins = (float) $area->cached_customer_price;
            }
            if ($area->cached_cogs !== null) {
                $cogs = (float) $area->cached_cogs;
            }

            $this->selectedTemplates[] = [
                'template_id' => null,
                'name' => $area->name,
                'scope_type' => $scopeType,
                'quantity' => $area->wall_sqft,
                'coats' => $area->surfaces->first()?->coats ?? 2,
                'price' => round($withMargins, 2),
                'default_quantity' => $area->wall_sqft,
                'pricing_method' => 'per_sqft',
                'icon' => null,
                'category_type' => 'room',
                'labor_hours' => round($totalLaborHours, 2),
                'labor_cost' => round($totalLaborCost, 2),
                'cleanup_hours' => round($cleanupHours, 2),
                'cleanup_cost' => round($cleanupCost, 2),
                'sundries_cost' => round($sundriesCost, 2),
                'material_cost' => round($totalMaterialCost, 2),
                'material_tax' => $cogsResult['material_tax'],
                'labor_tax' => $cogsResult['labor_tax'],
                'base_total' => $cogs,
                'with_margins' => round($withMargins, 2),
                'sizes' => null,
                'selected_size' => null,
                'surface_ratios' => null,
                'surfaces' => null,
                'service_category_id' => $area->service_category_id,
                'area_data' => [
                    'length' => $area->length,
                    'width' => $area->width,
                    'height' => $area->height,
                    'wall_sqft' => $area->wall_sqft,
                    'ceiling_sqft' => $area->ceiling_sqft,
                    'perimeter_lnft' => $area->perimeter_lnft,
                    'measurement_type' => $area->measurement_type ?? 'dimensions',
                    'wall_count' => $area->wall_count ?? 4,
                    'direct_sqft' => $area->direct_sqft,
                    'direct_lnft' => $area->direct_lnft,
                    'direct_ceiling_sqft' => $area->direct_ceiling_sqft,
                    'surfaces' => $surfaces,
                    'addons' => $addons,
                    'package_tiers' => $area->package_tiers,
                ],
                ...$this->restoreTierDataFromArea($area),
            ];
        }
    }

    /**
     * Compute tier prices for all loaded templates during initial mount.
     * Good tier = card price (guaranteed sync). Better/Best computed from tier paints.
     */
    protected function computeInitialTierPrices(): void
    {
        foreach ($this->selectedTemplates as $index => $templateData) {
            // Skip if tier prices were already restored from cached data
            if (! empty($templateData['tier_prices'])) {
                continue;
            }
            $this->computeTierPricesForTemplate($index);
        }
    }

    /**
     * Handle project scope changes.
     * Filters out templates that don't match the new scope, syncs scopeFilter,
     * and reloads paint defaults for the new scope.
     */
    public function updatedProjectScope(): void
    {
        // Clear computed caches that depend on scope
        unset($this->maxAllowableDiscount, $this->estimatedNetProfitPercent);

        // Sync the template filter with project scope
        $this->scopeFilter = $this->projectScope;

        // Filter out templates that don't match the new scope
        $this->selectedTemplates = collect($this->selectedTemplates)
            ->filter(function ($template) {
                $templateScope = $template['scope_type'] ?? 'interior';

                return $templateScope === $this->projectScope || $templateScope === 'both';
            })
            ->values()
            ->toArray();

        // Reload paint defaults for the new scope (clear overrides from old scope)
        $settings = CompanySetting::getInstance();
        $companyDefaults = $settings->getPaintDefaultsForScope($this->projectScope);

        if ($this->paintMode === 'category') {
            // Keep user overrides that still make sense, reload defaults
            $this->paintByCategory = $companyDefaults;
        }

        // Recalculate all prices with new scope's settings
        $this->recalculateAllPrices();

        $this->dispatch('scope-changed', scope: $this->projectScope);
    }

    /**
     * Get project scope options (primary selector).
     *
     * @return array<string, string>
     */
    public function getProjectScopeOptions(): array
    {
        return [
            'interior' => 'Interior',
            'exterior' => 'Exterior',
        ];
    }

    /**
     * Get scope filter options (for template filtering within scope).
     *
     * @return array<string, string>
     */
    public function getScopeFilterOptions(): array
    {
        return [
            'all' => 'All',
            'interior' => 'Interior',
            'exterior' => 'Exterior',
        ];
    }


    /**
     * Get a configured TemplatePricingService instance.
     */
    protected function pricingService(): TemplatePricingService
    {
        return app(TemplatePricingService::class)->withConfig([
            'paintMode' => $this->paintMode,
            'defaultPaintProductId' => $this->getEffectivePaintProductId(),
            'paintByCategory' => $this->paintByCategory,
            'projectScope' => $this->projectScope,
            'marginDiscountPercent' => $this->marginDiscountPercent,
            'hourlyRateOverride' => $this->hourlyRateOverride,
        ]);
    }

    protected function inferSurfaceCategory(string $surfaceType, string $scopeType): string
    {
        return $this->pricingService()->inferSurfaceCategory($surfaceType, $scopeType);
    }

    /**
     * Infer the unit for a surface when quantity_source is 'custom'.
     * Uses the production rate's rate_type or falls back to surface_category conventions.
     */
    protected function inferUnitFromSurface(array $surfaceData): string
    {
        if (! empty($surfaceData['production_rate_id'])) {
            $rate = ProductionRate::find($surfaceData['production_rate_id']);
            if ($rate) {
                return match ($rate->rate_type) {
                    'sqft_hr' => 'sqft',
                    'lnft_hr', 'cost_per_lnft' => 'lnft',
                    'units_hr', 'hours_per_unit' => 'units',
                    default => 'sqft',
                };
            }
        }

        return match ($surfaceData['surface_category'] ?? '') {
            'walls', 'ceiling', 'body', 'eaves' => 'sqft',
            'baseboard', 'fascia', 'trim', 'wood_replacement' => 'lnft',
            'doors', 'windows', 'exterior_doors' => 'units',
            default => 'sqft',
        };
    }

    /**
     * Build surface array from an EstimateArea model for loading into selectedTemplates.
     * Uses 'custom' quantity_source when saved quantity differs from dimension-derived value
     * to prevent recalculation from overwriting saved quantities.
     *
     * @return array{0: array, 1: float, 2: float, 3: float} [surfaces, totalLaborHours, totalLaborCost, totalMaterialCost]
     */
    protected function buildSurfaceArrayFromArea(EstimateArea $area): array
    {
        $surfaces = [];
        $totalLaborHours = 0;
        $totalLaborCost = 0;
        $totalMaterialCost = 0;

        foreach ($area->surfaces as $surface) {
            $surfaceCategory = $surface->surface_category
                ?? $surface->productionRate?->surface_category
                ?? $this->inferSurfaceCategory($surface->surface_type, $area->scope_type ?? 'interior');

            // Determine the natural quantity_source based on surface type/unit
            $naturalSource = match (true) {
                $surface->surface_type === 'doors' => 'door_count',
                $surface->surface_type === 'windows' => 'window_count',
                $surface->surface_type === 'ceiling' && $surface->unit === 'sqft' => 'ceiling_sqft',
                $surface->unit === 'sqft' => 'wall_sqft',
                $surface->unit === 'lnft' => 'perimeter_lnft',
                default => 'custom',
            };

            // Check if saved quantity matches what the dimension-derived source would give.
            // If not, use 'custom' source to preserve the actual saved quantity during recalculation.
            $savedQty = (float) $surface->quantity;
            $dimensionQty = match ($naturalSource) {
                'wall_sqft' => (float) $area->wall_sqft,
                'ceiling_sqft' => (float) $area->ceiling_sqft,
                'perimeter_lnft' => (float) $area->perimeter_lnft,
                default => null,
            };

            // If quantity doesn't match the dimension value, force custom source
            $quantitySource = $naturalSource;
            $quantityOverride = $surface->unit === 'units' ? $savedQty : null;
            if ($dimensionQty !== null && abs($savedQty - $dimensionQty) > 0.01) {
                $quantitySource = 'custom';
                $quantityOverride = $savedQty;
            }

            $surfaces[] = [
                'id' => $surface->id,
                'name' => $surface->name,
                'surface_type' => $surface->surface_type,
                'surface_category' => $surfaceCategory,
                'production_rate_id' => $surface->production_rate_id ? (string) $surface->production_rate_id : null,
                'quantity_source' => $quantitySource,
                'quantity' => $savedQty,
                'quantity_override' => $quantityOverride,
                'default_coats' => $surface->coats,
                'is_required' => false,
                'is_default_enabled' => $surface->is_included,
                'is_custom' => (bool) $surface->is_custom,
                'fixed_price' => null,
                'labor_hours' => (float) ($surface->labor_hours ?? 0),
                'labor_cost' => (float) ($surface->labor_cost ?? 0),
                'material_cost' => (float) ($surface->material_cost ?? 0),
                'tier_overrides' => $surface->tier_overrides,
            ];

            if ($surface->is_included) {
                $totalLaborHours += (float) ($surface->labor_hours ?? 0);
                $totalLaborCost += (float) ($surface->labor_cost ?? 0);
                $totalMaterialCost += (float) ($surface->material_cost ?? 0);
            }
        }

        return [$surfaces, $totalLaborHours, $totalLaborCost, $totalMaterialCost];
    }

    /**
     * Build addon array from an EstimateArea model.
     *
     * @return array{0: array, 1: float} [addons, addonTotal]
     */
    protected function buildAddonArrayFromArea(EstimateArea $area): array
    {
        $addons = [];
        $addonTotal = 0;

        foreach ($area->addons ?? [] as $addon) {
            $addons[] = [
                'nested_area_template_id' => $addon->nested_area_template_id,
                'name' => $addon->nestedAreaTemplate?->name ?? 'Add-on',
                'quantity' => $addon->quantity,
                'unit_price' => (float) $addon->unit_price,
                'total_price' => (float) $addon->total_price,
            ];
            $addonTotal += (float) $addon->total_price;
        }

        return [$addons, $addonTotal];
    }

    public function getTemplateCostBreakdown(int $templateId, float $quantity, int $coats, array $options = []): array
    {
        return $this->pricingService()->getTemplateCostBreakdown($templateId, $quantity, $coats, $options);
    }

    protected function calculateMaterialCostForSurface(
        float $quantity,
        int $coats,
        ?int $paintProductId,
        ?Substrate $substrate,
        CompanySetting $settings,
        ?float $fallbackMaterialCostPerUnit = null,
        float $coatMultiplier = 1.0
    ): float {
        return $this->pricingService()->calculateMaterialCostForSurface(
            $quantity, $coats, $paintProductId, $substrate, $settings, $fallbackMaterialCostPerUnit, $coatMultiplier
        );
    }


    /**
     * Add a template to the selected list.
     */
    public function addTemplate(int $templateId): void
    {
        $template = EstimateTemplate::with(['templateCategory', 'productionRate', 'substrate', 'defaultPaintProduct'])->find($templateId);

        if (! $template || ! $template->is_active) {
            return;
        }

        $metadata = $template->metadata ?? [];
        $sizes = $metadata['sizes'] ?? null;
        $defaultSize = $metadata['default_size'] ?? null;
        $surfaceRatios = $metadata['surface_ratios'] ?? null;

        $quantity = (float) $template->default_quantity;
        $coats = $template->default_coats ?? 2;
        $breakdown = $this->getTemplateCostBreakdown($templateId, $quantity, $coats);

        $this->selectedTemplates[] = [
            'template_id' => $template->id,
            'name' => __($template->name),
            'scope_type' => $template->scope_type ?? 'interior',
            'quantity' => $quantity,
            'coats' => $coats,
            'price' => $breakdown['with_margins'], // Use PBP calculated price
            'default_quantity' => $quantity,
            'pricing_method' => $template->pricing_method,
            'icon' => $template->icon,
            'category_type' => $template->templateCategory?->type,
            'labor_hours' => $breakdown['labor_hours'],
            'labor_cost' => $breakdown['labor_cost'],
            'cleanup_hours' => $breakdown['cleanup_hours'],
            'cleanup_cost' => $breakdown['cleanup_cost'],
            'sundries_cost' => $breakdown['sundries_cost'],
            'material_cost' => $breakdown['material_cost'],
            'base_total' => $breakdown['base_total'],
            'with_margins' => $breakdown['with_margins'],
            'margin_percent' => $breakdown['margin_percent'] ?? null,
            'profit' => $breakdown['profit'] ?? null,
            // Size options for quick selection
            'sizes' => $sizes,
            'selected_size' => $defaultSize,
            'surface_ratios' => $surfaceRatios,
            // Per-surface breakdown (for expandable details)
            'surfaces' => $breakdown['surfaces'] ?? null,
            // Substrate and paint product (from template defaults, can be overridden per-room)
            'substrate_id' => $breakdown['substrate_id'] ?? $template->substrate_id,
            'paint_product_id' => $breakdown['paint_product_id'] ?? $template->default_paint_product_id,
            'substrate_name' => $template->substrate ? __($template->substrate->name) : null,
            'paint_product_name' => $template->defaultPaintProduct?->name,
        ];

        $this->dispatch('template-added');
    }

    /**
     * Change the size of a selected template.
     */
    public function changeSize(int $index, string $sizeKey): void
    {
        if (! isset($this->selectedTemplates[$index])) {
            return;
        }

        $templateData = $this->selectedTemplates[$index];
        $sizes = $templateData['sizes'] ?? null;

        if (! $sizes || ! isset($sizes[$sizeKey])) {
            return;
        }

        $sizeData = $sizes[$sizeKey];

        // Get quantity from size data (could be sqft or lnft)
        $newQuantity = $sizeData['sqft'] ?? $sizeData['lnft'] ?? $templateData['quantity'];

        // Pass current substrate and paint product overrides
        $options = [
            'substrate_id' => $templateData['substrate_id'] ?? null,
            'paint_product_id' => $templateData['paint_product_id'] ?? null,
        ];

        $breakdown = $this->getTemplateCostBreakdown(
            $templateData['template_id'],
            (float) $newQuantity,
            $templateData['coats'],
            $options
        );

        $this->selectedTemplates[$index]['quantity'] = (float) $newQuantity;
        $this->selectedTemplates[$index]['selected_size'] = $sizeKey;
        $this->selectedTemplates[$index]['price'] = $breakdown['with_margins'];
        $this->selectedTemplates[$index]['labor_hours'] = $breakdown['labor_hours'];
        $this->selectedTemplates[$index]['labor_cost'] = $breakdown['labor_cost'];
        $this->selectedTemplates[$index]['cleanup_hours'] = $breakdown['cleanup_hours'];
        $this->selectedTemplates[$index]['cleanup_cost'] = $breakdown['cleanup_cost'];
        $this->selectedTemplates[$index]['sundries_cost'] = $breakdown['sundries_cost'];
        $this->selectedTemplates[$index]['material_cost'] = $breakdown['material_cost'];
        $this->selectedTemplates[$index]['base_total'] = $breakdown['base_total'];
        $this->selectedTemplates[$index]['with_margins'] = $breakdown['with_margins'];
        $this->selectedTemplates[$index]['surfaces'] = $breakdown['surfaces'] ?? null;
    }

    /**
     * Remove a template from the selected list.
     */
    public function removeTemplate(int $index): void
    {
        if (isset($this->selectedTemplates[$index])) {
            unset($this->selectedTemplates[$index]);
            $this->selectedTemplates = array_values($this->selectedTemplates);
            $this->dispatchLiveTierTotals();
        }
    }

    /**
     * Add a fixed price template (NestedAreaTemplate) to the selected list.
     * Fixed price items have simple pricing: unit_price × quantity.
     * They don't go through PBP calculations - price is used as-is.
     */
    public function addFixedPriceTemplate(int $nestedTemplateId): void
    {
        $template = NestedAreaTemplate::query()->active()->find($nestedTemplateId);

        if (! $template) {
            return;
        }

        // Snapshot labor/material from the template for internal cost tracking
        $laborHours = (float) ($template->estimated_hours ?? 0);
        $materialCost = (float) ($template->material_cost ?? 0);
        $laborCost = 0;

        if ($laborHours > 0) {
            $scope = $template->scope_type ?? 'interior';
            $settings = CompanySetting::getInstance();
            $laborCost = $laborHours * $this->getEffectiveRateForScope($scope);
        }

        $this->selectedTemplates[] = [
            'is_fixed_price' => true,
            'nested_template_id' => $template->id,
            'template_id' => null, // Required for validation (nullable)
            'name' => __($template->name),
            'code' => $template->code,
            'scope_type' => $template->scope_type ?? 'interior',
            'category' => $template->category,
            'quantity' => 1, // Default quantity
            'coats' => 1, // Required for validation (fixed price items don't use coats)
            'unit_price' => $template->getEffectivePrice(),
            'price' => $template->getEffectivePrice(), // unit_price × quantity
            // Snapshot labor/material for internal reporting (does NOT affect customer price)
            'labor_hours' => $laborHours,
            'labor_cost' => round($laborCost, 2),
            'cleanup_hours' => 0,
            'cleanup_cost' => 0,
            'sundries_cost' => 0,
            'material_cost' => $materialCost,
            'base_total' => $template->getEffectivePrice(),
            'with_margins' => $template->getEffectivePrice(),
            // No sizes, surface ratios, etc. for fixed price items
            'sizes' => null,
            'selected_size' => null,
            'surface_ratios' => null,
            'surfaces' => null,
            'area_data' => null,
        ];

        $this->dispatch('template-added');
    }

    /**
     * Update the quantity for a fixed price template.
     * Recalculates price as unit_price × quantity.
     */
    public function updateFixedPriceQuantity(int $index, int $quantity): void
    {
        if (! isset($this->selectedTemplates[$index])) {
            return;
        }

        $templateData = $this->selectedTemplates[$index];

        // Only update if it's a fixed price item
        if (empty($templateData['is_fixed_price'])) {
            return;
        }

        // Ensure quantity is at least 1
        $quantity = max(1, $quantity);

        $unitPrice = $templateData['unit_price'] ?? 0;
        $totalPrice = $unitPrice * $quantity;

        $this->selectedTemplates[$index]['quantity'] = $quantity;
        $this->selectedTemplates[$index]['price'] = $totalPrice;
        $this->selectedTemplates[$index]['base_total'] = $totalPrice;
        $this->selectedTemplates[$index]['with_margins'] = $totalPrice;
    }

    /**
     * Recalculate the price for a template at a given index using PBP formula.
     */
    public function recalculatePrice(int $index): void
    {
        if (! isset($this->selectedTemplates[$index])) {
            return;
        }

        $templateData = $this->selectedTemplates[$index];

        // Fixed-price and custom area items don't use template-based recalculation
        if (! empty($templateData['is_fixed_price']) || empty($templateData['template_id'])) {
            return;
        }

        // Pass current substrate and paint product overrides
        $options = [
            'substrate_id' => $templateData['substrate_id'] ?? null,
            'paint_product_id' => $templateData['paint_product_id'] ?? null,
        ];

        $breakdown = $this->getTemplateCostBreakdown(
            $templateData['template_id'],
            $templateData['quantity'],
            $templateData['coats'],
            $options
        );

        $this->selectedTemplates[$index]['price'] = $breakdown['with_margins'];
        $this->selectedTemplates[$index]['labor_hours'] = $breakdown['labor_hours'];
        $this->selectedTemplates[$index]['labor_cost'] = $breakdown['labor_cost'];
        $this->selectedTemplates[$index]['cleanup_hours'] = $breakdown['cleanup_hours'];
        $this->selectedTemplates[$index]['cleanup_cost'] = $breakdown['cleanup_cost'];
        $this->selectedTemplates[$index]['sundries_cost'] = $breakdown['sundries_cost'];
        $this->selectedTemplates[$index]['material_cost'] = $breakdown['material_cost'];
        $this->selectedTemplates[$index]['base_total'] = $breakdown['base_total'];
        $this->selectedTemplates[$index]['with_margins'] = $breakdown['with_margins'];
        $this->selectedTemplates[$index]['surfaces'] = $breakdown['surfaces'] ?? null;
    }

    /**
     * Get the live price preview for the adjustment modal using PBP formula.
     */
    #[Computed]
    public function adjustmentPreviewPrice(): float
    {
        if ($this->adjustingTemplateIndex === null || ! isset($this->selectedTemplates[$this->adjustingTemplateIndex])) {
            return 0;
        }

        $templateData = $this->selectedTemplates[$this->adjustingTemplateIndex];

        // Pass current substrate and paint product overrides
        $options = [
            'substrate_id' => $templateData['substrate_id'] ?? null,
            'paint_product_id' => $templateData['paint_product_id'] ?? null,
        ];

        $breakdown = $this->getTemplateCostBreakdown(
            $templateData['template_id'],
            $this->adjustQuantity,
            $this->adjustCoats,
            $options
        );

        return $breakdown['with_margins'];
    }

    /**
     * Get the live breakdown preview for the adjustment modal.
     *
     * @return array{labor_hours: float, labor_cost: float, cleanup_hours: float, cleanup_cost: float, sundries_cost: float, material_cost: float, base_total: float, with_margins: float}
     */
    #[Computed]
    public function adjustmentPreviewBreakdown(): array
    {
        if ($this->adjustingTemplateIndex === null || ! isset($this->selectedTemplates[$this->adjustingTemplateIndex])) {
            return [
                'labor_hours' => 0,
                'labor_cost' => 0,
                'cleanup_hours' => 0,
                'cleanup_cost' => 0,
                'sundries_cost' => 0,
                'material_cost' => 0,
                'base_total' => 0,
                'with_margins' => 0,
            ];
        }

        $templateData = $this->selectedTemplates[$this->adjustingTemplateIndex];

        // Pass current substrate and paint product overrides
        $options = [
            'substrate_id' => $templateData['substrate_id'] ?? null,
            'paint_product_id' => $templateData['paint_product_id'] ?? null,
        ];

        return $this->getTemplateCostBreakdown(
            $templateData['template_id'],
            $this->adjustQuantity,
            $this->adjustCoats,
            $options
        );
    }

    // ===== MARGIN DISCOUNT METHODS =====

    /**
     * Calculate the maximum allowable margin discount that keeps net profit >= 10%.
     */
    #[Computed]
    public function maxAllowableDiscount(): float
    {
        $settings = CompanySetting::getInstance();
        $globalMargin = $settings->getMarginPercentForScope($this->projectScope);

        // Calculate total allocation percent
        $allocPercent = 0;
        if ($settings->allocations()->where('is_active', true)->where('is_informational', false)->exists()) {
            $allocations = $settings->getActiveAllocations();
            foreach ($allocations as $allocation) {
                if ($allocation->type === 'percent_of_revenue') {
                    $allocPercent += (float) $allocation->value;
                }
            }
            $allocPercent = $allocPercent / 100;
        } else {
            $allocPercent = ((float) $settings->marketing_percent
                + (float) $settings->sales_percent
                + (float) $settings->production_management_percent) / 100;
        }

        // Max effective margin to keep 10% net profit:
        // netProfit% = (1 - effectiveMargin/100) - allocPercent >= 0.10
        // effectiveMargin <= (1 - allocPercent - 0.10) * 100
        $maxEffectiveMargin = (1 - $allocPercent - 0.10) * 100;
        $maxDiscount = $maxEffectiveMargin - $globalMargin;

        return min(15, max(0, round($maxDiscount, 2)));
    }

    /**
     * Get the estimated net profit percent after margin discount and allocations.
     */
    #[Computed]
    public function estimatedNetProfitPercent(): float
    {
        $settings = CompanySetting::getInstance();
        $effectiveMargin = min(99, $settings->getMarginPercentForScope($this->projectScope) + ($this->marginDiscountPercent ?? 0));

        // Gross profit % = 1 - (COGS/price) = 1 - (effectiveMargin/100)
        $grossProfitPercent = 1 - ($effectiveMargin / 100);

        // Allocation percent
        $allocPercent = 0;
        if ($settings->allocations()->where('is_active', true)->where('is_informational', false)->exists()) {
            $allocations = $settings->getActiveAllocations();
            foreach ($allocations as $allocation) {
                if ($allocation->type === 'percent_of_revenue') {
                    $allocPercent += (float) $allocation->value;
                }
            }
            $allocPercent = $allocPercent / 100;
        } else {
            $allocPercent = ((float) $settings->marketing_percent
                + (float) $settings->sales_percent
                + (float) $settings->production_management_percent) / 100;
        }

        return round(($grossProfitPercent - $allocPercent) * 100, 1);
    }

    /**
     * Handle margin discount changes - recalculate all prices.
     */
    public function updatedMarginDiscountPercent(): void
    {
        // Clamp to [0, min(15, maxAllowableDiscount)]
        $max = min(15, $this->maxAllowableDiscount);
        $this->marginDiscountPercent = max(0, min($max, $this->marginDiscountPercent ?? 0));

        // Recalculate all areas/templates with new effective margin
        $this->recalculateAllPricesWithDiscount();
    }

    protected function getEffectiveRateForScope(string $scopeType): float
    {
        return $this->pricingService()->getEffectiveRateForScope($scopeType);
    }

    /**
     * Resolve the effective paint product ID for pricing.
     * When tier pricing is active and no default paint is set, uses the "good" tier paint.
     */
    protected function getEffectivePaintProductId(): ?int
    {
        if ($this->defaultPaintProductId) {
            return $this->defaultPaintProductId;
        }

        if ($this->useTierPricing) {
            return $this->tierPaintConfig['good']['default_paint_product_id'] ?? null;
        }

        return null;
    }

    /**
     * Handle hourly rate override changes - recalculate all COGS and prices.
     */
    public function updatedHourlyRateOverride(): void
    {
        // Null or empty means "use defaults"
        if ($this->hourlyRateOverride === null || $this->hourlyRateOverride === '') {
            $this->hourlyRateOverride = null;
            $this->recalculateAllCOGSWithOverride();

            return;
        }

        // Only recalculate when value is in valid range — don't clamp mid-typing
        $value = (float) $this->hourlyRateOverride;
        if ($value >= 10 && $value <= 100) {
            $this->recalculateAllCOGSWithOverride();
        }
    }

    /**
     * Recalculate all area/template COGS when the hourly rate override changes.
     * Unlike margin discount (which only reapplies margin), this must recalculate labor costs.
     */
    protected function recalculateAllCOGSWithOverride(): void
    {
        $settings = CompanySetting::getInstance();

        // Recalculate areas
        foreach ($this->areas as $index => $area) {
            if ($area['is_fixed_price'] ?? false) {
                continue;
            }

            $scopeType = $area['scope_type'] ?? $this->projectScope;
            $effectiveRate = $this->getEffectiveRateForScope($scopeType);

            // Recalculate labor cost from hours
            $laborHours = (float) ($area['labor_hours'] ?? 0);
            $laborCost = $laborHours * $effectiveRate;

            // Recalculate cleanup cost
            $cleanupHours = $settings->calculateCleanupHours($laborHours, $scopeType);
            $cleanupCost = $cleanupHours * $effectiveRate;
            $sundriesCost = $settings->calculateSundriesCost($laborHours, $scopeType);
            $materialCost = (float) ($area['material_cost'] ?? 0);
            $addonCost = (float) ($area['addon_cost'] ?? 0);

            $cogsResult = $settings->calculateCOGS($laborCost, $cleanupCost, $sundriesCost, $materialCost + $addonCost);
            $cogs = $cogsResult['cogs'];

            $effectiveMargin = min(99, $settings->getMarginPercentForScope($scopeType) + ($this->marginDiscountPercent ?? 0));
            $withMargins = round($cogs / ($effectiveMargin / 100), 2);

            $this->areas[$index]['labor_cost'] = round($laborCost, 2);
            $this->areas[$index]['cleanup_hours'] = round($cleanupHours, 2);
            $this->areas[$index]['cleanup_cost'] = round($cleanupCost, 2);
            $this->areas[$index]['sundries_cost'] = round($sundriesCost, 2);
            $this->areas[$index]['base_total'] = round($cogs, 2);
            $this->areas[$index]['with_margins'] = $withMargins;
            $this->areas[$index]['price'] = $withMargins;
        }

        // Recalculate selectedTemplates
        foreach ($this->selectedTemplates as $index => $templateData) {
            if ($templateData['is_fixed_price'] ?? false) {
                continue;
            }

            $scopeType = $templateData['scope_type'] ?? $this->projectScope;
            $effectiveRate = $this->getEffectiveRateForScope($scopeType);

            $laborHours = (float) ($templateData['labor_hours'] ?? 0);
            $laborCost = $laborHours * $effectiveRate;

            $cleanupHours = $settings->calculateCleanupHours($laborHours, $scopeType);
            $cleanupCost = $cleanupHours * $effectiveRate;
            $sundriesCost = $settings->calculateSundriesCost($laborHours, $scopeType);
            $materialCost = (float) ($templateData['material_cost'] ?? 0);
            $addonCost = array_sum(array_column($templateData['area_data']['addons'] ?? [], 'total_price'));

            $cogsResult = $settings->calculateCOGS($laborCost, $cleanupCost, $sundriesCost, $materialCost + $addonCost);
            $cogs = $cogsResult['cogs'];

            $effectiveMargin = min(99, $settings->getMarginPercentForScope($scopeType) + ($this->marginDiscountPercent ?? 0));
            $withMargins = round($cogs / ($effectiveMargin / 100), 2);

            $this->selectedTemplates[$index]['labor_cost'] = round($laborCost, 2);
            $this->selectedTemplates[$index]['cleanup_hours'] = round($cleanupHours, 2);
            $this->selectedTemplates[$index]['cleanup_cost'] = round($cleanupCost, 2);
            $this->selectedTemplates[$index]['sundries_cost'] = round($sundriesCost, 2);
            $this->selectedTemplates[$index]['base_total'] = round($cogs, 2);
            $this->selectedTemplates[$index]['with_margins'] = $withMargins;
            $this->selectedTemplates[$index]['price'] = $withMargins;

            // Recompute tier prices with updated COGS components
            $this->computeTierPricesForTemplate($index);
        }

        // Dispatch updated tier totals to paint-selector
        $this->dispatchLiveTierTotals();
    }

    /**
     * Recalculate all prices applying the current margin discount.
     * Only reapplies margin to existing COGS - does not recalculate COGS.
     */
    protected function recalculateAllPricesWithDiscount(): void
    {
        $settings = CompanySetting::getInstance();

        // Recalculate areas
        foreach ($this->areas as $index => $area) {
            $cogs = $area['base_total'] ?? 0;
            if ($cogs <= 0) {
                continue;
            }
            $scopeType = $area['scope_type'] ?? $this->projectScope;
            $effectiveMargin = min(99, $settings->getMarginPercentForScope($scopeType) + ($this->marginDiscountPercent ?? 0));
            $withMargins = round($cogs / ($effectiveMargin / 100), 2);

            $this->areas[$index]['with_margins'] = $withMargins;
            $this->areas[$index]['price'] = $withMargins;
        }

        // Recalculate selectedTemplates
        foreach ($this->selectedTemplates as $index => $templateData) {
            // Fixed-price items don't use margin-based pricing
            if (! empty($templateData['is_fixed_price'])) {
                continue;
            }

            $cogs = $templateData['base_total'] ?? 0;
            if ($cogs <= 0) {
                continue;
            }
            $scopeType = $templateData['scope_type'] ?? $this->projectScope;
            $effectiveMargin = min(99, $settings->getMarginPercentForScope($scopeType) + ($this->marginDiscountPercent ?? 0));
            $withMargins = round($cogs / ($effectiveMargin / 100), 2);

            $this->selectedTemplates[$index]['with_margins'] = $withMargins;
            $this->selectedTemplates[$index]['price'] = $withMargins;

            // Recompute tier prices with updated margin
            $this->computeTierPricesForTemplate($index);
        }

        // Dispatch updated tier totals to paint-selector
        $this->dispatchLiveTierTotals();
    }

    /**
     * Calculate the subtotal from all areas or selected templates.
     */
    #[Computed]
    public function subtotal(): float
    {
        // Use areas if available, otherwise fall back to selectedTemplates
        if (! empty($this->areas)) {
            return array_sum(array_column($this->areas, 'price'));
        }

        return array_sum(array_column($this->selectedTemplates, 'price'));
    }

    // ===== AREA MANAGEMENT METHODS =====

    /**
     * Handle template selection from the picker child component.
     */
    #[On('add-template-from-picker')]
    public function onAddTemplateFromPicker(int $templateId): void
    {
        $this->addTemplate($templateId);
    }

    /**
     * Handle fixed price template selection from the picker child component.
     */
    #[On('add-fixed-price-from-picker')]
    public function onAddFixedPriceFromPicker(int $nestedTemplateId): void
    {
        $this->addFixedPriceTemplate($nestedTemplateId);
    }

    /**
     * Handle paint config changes from the paint selector child component.
     */
    #[On('paint-config-changed')]
    public function onPaintConfigChanged(array $config): void
    {
        $this->paintMode = $config['paintMode'] ?? $this->paintMode;
        $this->defaultPaintProductId = $config['defaultPaintProductId'] ?? $this->defaultPaintProductId;
        $this->paintByCategory = $config['paintByCategory'] ?? $this->paintByCategory;
        $this->useTierPricing = $config['useTierPricing'] ?? $this->useTierPricing;
        $this->tierPaintConfig = $config['tierPaintConfig'] ?? $this->tierPaintConfig;

        $this->recalculateAllPrices();
    }

    /**
     * Open AreaBuilder for a new blank custom area.
     */
    public function addDetailedArea(): void
    {
        $this->dispatch(
            'open-area-builder-blank',
            scopeType: $this->projectScope,
            paintMode: $this->paintMode,
            paintProductId: $this->defaultPaintProductId,
            paintByCategory: $this->paintByCategory,
            marginDiscountPercent: $this->marginDiscountPercent ?? 0,
            hourlyRateOverride: $this->hourlyRateOverride,
            useTierPricing: $this->useTierPricing,
        )->to(ref: 'area-builder');
    }

    /**
     * Open AreaBuilder to edit a selected template.
     */
    public function editSelectedTemplate(int $index): void
    {
        if (! isset($this->selectedTemplates[$index])) {
            return;
        }

        $templateData = $this->selectedTemplates[$index];

        // If we have saved area_data from a previous edit, use that
        if (! empty($templateData['area_data']) && isset($templateData['area_data']['width'])) {
            $areaData = [
                'name' => $templateData['name'],
                'scope_type' => $templateData['scope_type'],
                'source_template_id' => $templateData['template_id'],
                'default_coats' => $templateData['coats'] ?? 2,
                'length' => $templateData['area_data']['length'] ?? null,
                'width' => $templateData['area_data']['width'],
                'height' => $templateData['area_data']['height'] ?? 0,
                'measurement_type' => $templateData['area_data']['measurement_type'] ?? 'dimensions',
                'wall_count' => $templateData['area_data']['wall_count'] ?? 4,
                'direct_sqft' => $templateData['area_data']['direct_sqft'] ?? null,
                'direct_lnft' => $templateData['area_data']['direct_lnft'] ?? null,
                'direct_ceiling_sqft' => $templateData['area_data']['direct_ceiling_sqft'] ?? null,
                'service_category_id' => $templateData['service_category_id'] ?? null,
                'surfaces' => $templateData['area_data']['surfaces'] ?? [],
                'addons' => $templateData['area_data']['addons'] ?? [],
                'package_tiers' => $templateData['area_data']['package_tiers'] ?? null,
            ];
            $this->dispatch(
                'open-area-builder-edit',
                areaData: $areaData,
                editingIndex: $index,
                paintMode: $this->paintMode,
                paintProductId: $this->defaultPaintProductId,
                paintByCategory: $this->paintByCategory,
                marginDiscountPercent: $this->marginDiscountPercent ?? 0,
                hourlyRateOverride: $this->hourlyRateOverride,
                useTierPricing: $this->useTierPricing,
            )->to(ref: 'area-builder');
        } elseif ($templateData['template_id']) {
            // Open AreaBuilder with the original template, passing index for update
            $this->dispatch(
                'open-area-builder-from-template',
                templateId: $templateData['template_id'],
                editingIndex: $index,
                paintMode: $this->paintMode,
                paintProductId: $this->defaultPaintProductId,
                paintByCategory: $this->paintByCategory,
                marginDiscountPercent: $this->marginDiscountPercent ?? 0,
                hourlyRateOverride: $this->hourlyRateOverride,
                useTierPricing: $this->useTierPricing,
            )->to(ref: 'area-builder');
        }
    }

    /**
     * Handle area saved from AreaBuilder component.
     */
    #[\Livewire\Attributes\On('area-saved')]
    public function handleAreaSaved(array $areaData, ?int $editingIndex = null): void
    {
        // Convert AreaBuilder data to selectedTemplates format
        $templateEntry = [
            'template_id' => $areaData['source_template_id'],
            'name' => $areaData['name'],
            'scope_type' => $areaData['scope_type'] ?? 'interior',
            'quantity' => $areaData['wall_sqft'] ?? 0,
            'coats' => $areaData['default_coats'] ?? 2,
            'price' => $areaData['price'] ?? $areaData['with_margins'] ?? 0,
            'default_quantity' => $areaData['wall_sqft'] ?? 0,
            'pricing_method' => 'production_rate',
            'icon' => null,
            'category_type' => 'room',
            'labor_hours' => $areaData['labor_hours'] ?? 0,
            'labor_cost' => $areaData['labor_cost'] ?? 0,
            'cleanup_hours' => $areaData['cleanup_hours'] ?? 0,
            'cleanup_cost' => $areaData['cleanup_cost'] ?? 0,
            'sundries_cost' => $areaData['sundries_cost'] ?? 0,
            'material_cost' => $areaData['material_cost'] ?? 0,
            'base_total' => $areaData['base_total'] ?? 0,
            'with_margins' => $areaData['with_margins'] ?? 0,
            'service_category_id' => $areaData['service_category_id'] ?? null,
            // Store area dimensions for future editing
            'area_data' => [
                'length' => $areaData['length'],
                'width' => $areaData['width'],
                'height' => $areaData['height'],
                'wall_sqft' => $areaData['wall_sqft'],
                'ceiling_sqft' => $areaData['ceiling_sqft'],
                'perimeter_lnft' => $areaData['perimeter_lnft'],
                'measurement_type' => $areaData['measurement_type'] ?? 'dimensions',
                'wall_count' => $areaData['wall_count'] ?? 4,
                'direct_sqft' => $areaData['direct_sqft'] ?? null,
                'direct_lnft' => $areaData['direct_lnft'] ?? null,
                'direct_ceiling_sqft' => $areaData['direct_ceiling_sqft'] ?? null,
                'surfaces' => $areaData['surfaces'],
                'addons' => $areaData['addons'],
                'package_tiers' => $areaData['package_tiers'] ?? null,
            ],
            'sizes' => null,
            'selected_size' => null,
            'surface_ratios' => null,
            'surfaces' => null, // Will be set from breakdown if needed
        ];

        if ($editingIndex !== null && isset($this->selectedTemplates[$editingIndex])) {
            $this->selectedTemplates[$editingIndex] = $templateEntry;
            $targetIndex = $editingIndex;
        } else {
            $this->selectedTemplates[] = $templateEntry;
            $targetIndex = array_key_last($this->selectedTemplates);
        }

        // Compute tier prices using the stored COGS components (don't recalculate base price)
        $this->computeTierPricesForTemplate($targetIndex);

        $this->dispatch('template-added');
        $this->dispatchLiveTierTotals();
    }

    /**
     * Toggle a tier for an area card's visibility.
     * Controls which Good/Better/Best packages include this area.
     */
    public function toggleAreaTier(int $index, string $tier): void
    {
        if (! isset($this->selectedTemplates[$index])) {
            return;
        }

        $isFixedPrice = ! empty($this->selectedTemplates[$index]['is_fixed_price']);

        $currentTiers = $isFixedPrice
            ? ($this->selectedTemplates[$index]['package_tiers'] ?? ['good', 'better', 'best'])
            : ($this->selectedTemplates[$index]['area_data']['package_tiers'] ?? ['good', 'better', 'best']);

        if (in_array($tier, $currentTiers)) {
            $currentTiers = array_values(array_diff($currentTiers, [$tier]));
        } else {
            $currentTiers[] = $tier;
            sort($currentTiers);
        }

        // Must have at least one tier
        if (empty($currentTiers)) {
            $currentTiers = [$tier];
        }

        $result = count($currentTiers) === 3 ? null : $currentTiers;

        if ($isFixedPrice) {
            $this->selectedTemplates[$index]['package_tiers'] = $result;
        } else {
            $this->selectedTemplates[$index]['area_data']['package_tiers'] = $result;
        }

        $this->computeTierPricesForTemplate($index);
        $this->dispatchLiveTierTotals();
    }

    /**
     * Update area dimensions directly from the card.
     */
    public function updateAreaDimension(int $index, string $field, float|string $value): void
    {
        $value = (float) $value;

        if (! isset($this->selectedTemplates[$index]['area_data'])) {
            return;
        }

        $this->selectedTemplates[$index]['area_data'][$field] = $value;

        // Recalculate dimensions
        $areaData = &$this->selectedTemplates[$index]['area_data'];
        $measurementType = $areaData['measurement_type'] ?? 'dimensions';

        if ($measurementType === 'sqft') {
            // Direct sqft mode - user enters sqft directly
            $areaData['wall_sqft'] = $areaData['direct_sqft'] ?? 0;
            $areaData['ceiling_sqft'] = $areaData['direct_ceiling_sqft'] ?? 0;
            $areaData['perimeter_lnft'] = 0;
        } elseif ($measurementType === 'lnft') {
            // Direct lnft mode - user enters linear feet directly
            $areaData['perimeter_lnft'] = $areaData['direct_lnft'] ?? 0;
            $areaData['ceiling_sqft'] = $areaData['direct_ceiling_sqft'] ?? 0;
            $areaData['wall_sqft'] = 0;
        } else {
            // Standard dimensions mode (L x W x H)
            $length = $areaData['length'] ?? null;
            $width = $areaData['width'] ?? 0;
            $height = $areaData['height'] ?? 0;
            $wallCount = max(1, min(4, $areaData['wall_count'] ?? 4));
            $wallRatio = $wallCount / 4;

            if ($length === null || $length == 0) {
                $areaData['wall_sqft'] = $width * $height * $wallRatio;
                $areaData['ceiling_sqft'] = 0;
                $areaData['perimeter_lnft'] = $width * $wallRatio;
            } else {
                $fullPerimeter = 2 * ($length + $width);
                $areaData['perimeter_lnft'] = $fullPerimeter * $wallRatio;
                $areaData['wall_sqft'] = $fullPerimeter * $height * $wallRatio;
                $areaData['ceiling_sqft'] = $length * $width;
            }
        }

        // Update quantity to match wall_sqft
        $this->selectedTemplates[$index]['quantity'] = $areaData['wall_sqft'];

        // Recalculate the area's price
        $this->recalculateSelectedTemplatePrice($index);
        $this->dispatchLiveTierTotals();
    }

    /**
     * Recalculate selected template price based on updated dimensions.
     */
    protected function recalculateSelectedTemplatePrice(int $index): void
    {
        if (! isset($this->selectedTemplates[$index]['area_data'])) {
            return;
        }

        $templateData = $this->selectedTemplates[$index];
        $areaData = $templateData['area_data'];
        $settings = CompanySetting::getInstance();
        $scopeType = $templateData['scope_type'] ?? 'interior';
        $hourlyWage = $this->getEffectiveRateForScope($scopeType);

        $totalLaborHours = 0;
        $totalMaterialCost = 0;

        // Track per-tier material costs alongside the base calculation
        $tierMaterialCosts = ['good' => 0.0, 'better' => 0.0, 'best' => 0.0];
        $computeTiers = $this->useTierPricing;

        foreach ($areaData['surfaces'] ?? [] as $sIdx => $surface) {
            if (! ($surface['is_required'] ?? false) && ! ($surface['is_default_enabled'] ?? true)) {
                continue;
            }

            $quantity = match ($surface['quantity_source'] ?? 'wall_sqft') {
                'wall_sqft' => $areaData['wall_sqft'],
                'ceiling_sqft' => $areaData['ceiling_sqft'],
                'perimeter_lnft' => $areaData['perimeter_lnft'],
                default => $surface['quantity_override'] ?? 1,
            };

            $coats = $surface['default_coats'] ?? $templateData['coats'] ?? 2;

            $rate = $surface['production_rate_id']
                ? ProductionRate::find($surface['production_rate_id'])
                : null;

            if (($surface['fixed_price'] ?? null) !== null && $surface['fixed_price'] > 0) {
                $fixedPrice = (float) $surface['fixed_price'];
                $totalMaterialCost += $fixedPrice;
                // Fixed price surfaces are the same across all tiers
                if ($computeTiers) {
                    foreach (['good', 'better', 'best'] as $tier) {
                        $tierMaterialCosts[$tier] += $fixedPrice;
                    }
                }
                // Annotate fixed-price surface for save consistency
                $this->selectedTemplates[$index]['area_data']['surfaces'][$sIdx]['labor_hours'] = 0;
                $this->selectedTemplates[$index]['area_data']['surfaces'][$sIdx]['labor_cost'] = 0;
                $this->selectedTemplates[$index]['area_data']['surfaces'][$sIdx]['material_cost'] = round($fixedPrice, 2);
            } else {
                $productionRate = $rate?->production_rate ?? 100;
                $coatMultiplier = match ($coats) {
                    1 => 1.0,
                    2 => 1.8,
                    3 => 2.5,
                    4 => 3.1,
                    default => 1.0 + (($coats - 1) * 0.7),
                };

                $hours = ($quantity / $productionRate) * $coatMultiplier;
                $totalLaborHours += $hours;

                $materialCost = $this->calculateMaterialCostForSurface(
                    $quantity, $coats, $this->getEffectivePaintProductId(),
                    null, $settings, null, $coatMultiplier
                );
                $totalMaterialCost += $materialCost;

                // Compute material cost for each tier using that tier's paint product
                if ($computeTiers) {
                    foreach (['good', 'better', 'best'] as $tier) {
                        $tierPaintId = $this->tierPaintConfig[$tier]['default_paint_product_id'] ?? null;
                        $tierMaterialCosts[$tier] += $this->calculateMaterialCostForSurface(
                            $quantity, $coats, $tierPaintId, null, $settings, null, $coatMultiplier
                        );
                    }
                }

                // Annotate surface with computed values so save() uses exact same numbers
                $this->selectedTemplates[$index]['area_data']['surfaces'][$sIdx]['labor_hours'] = round($hours, 4);
                $this->selectedTemplates[$index]['area_data']['surfaces'][$sIdx]['labor_cost'] = round($hours * $hourlyWage, 2);
                $this->selectedTemplates[$index]['area_data']['surfaces'][$sIdx]['material_cost'] = round($materialCost, 2);
            }
        }

        $laborCost = $totalLaborHours * $hourlyWage;
        $cleanupHours = $settings->calculateCleanupHours($totalLaborHours, $scopeType);
        $cleanupCost = $cleanupHours * $hourlyWage;
        $sundriesCost = $settings->calculateSundriesCost($totalLaborHours, $scopeType);
        $addonCost = array_sum(array_column($areaData['addons'] ?? [], 'total_price'));

        $cogsResult = $settings->calculateCOGS($laborCost, $cleanupCost, $sundriesCost, $totalMaterialCost + $addonCost);
        $cogs = $cogsResult['cogs'];
        $effectiveMargin = min(99, $settings->getMarginPercentForScope($scopeType) + ($this->marginDiscountPercent ?? 0));
        $withMargins = round($cogs / ($effectiveMargin / 100), 2);

        $this->selectedTemplates[$index]['labor_hours'] = round($totalLaborHours, 2);
        $this->selectedTemplates[$index]['labor_cost'] = round($laborCost, 2);
        $this->selectedTemplates[$index]['cleanup_hours'] = round($cleanupHours, 2);
        $this->selectedTemplates[$index]['cleanup_cost'] = round($cleanupCost, 2);
        $this->selectedTemplates[$index]['sundries_cost'] = round($sundriesCost, 2);
        $this->selectedTemplates[$index]['material_cost'] = round($totalMaterialCost, 2);
        $this->selectedTemplates[$index]['material_tax'] = $cogsResult['material_tax'];
        $this->selectedTemplates[$index]['labor_tax'] = $cogsResult['labor_tax'];
        $this->selectedTemplates[$index]['base_total'] = $cogs;
        $this->selectedTemplates[$index]['with_margins'] = round($withMargins, 2);
        $this->selectedTemplates[$index]['price'] = round($withMargins, 2);

        // Compute per-tier OTD prices using the SAME labor/cleanup/sundries
        if ($computeTiers) {
            $tierPrices = [];
            $tierCogsMap = [];
            foreach (['good', 'better', 'best'] as $tier) {
                $tierCogsResult = $settings->calculateCOGS(
                    $laborCost, $cleanupCost, $sundriesCost,
                    $tierMaterialCosts[$tier] + $addonCost
                );
                $tierCogsVal = $tierCogsResult['cogs'];
                $tierCogsMap[$tier] = round($tierCogsVal, 2);
                $tierPrices[$tier] = $tierCogsVal > 0
                    ? round($tierCogsVal / ($effectiveMargin / 100), 2)
                    : 0.0;
            }
            $this->selectedTemplates[$index]['tier_prices'] = $tierPrices;
            $this->selectedTemplates[$index]['tier_cogs'] = $tierCogsMap;
        }
    }

    /**
     * Compute tier prices for a template using its ALREADY STORED COGS components.
     * Unlike recalculateSelectedTemplatePrice() which recomputes everything from scratch,
     * this method preserves the existing card price and only varies material costs per tier.
     * Good tier price = current card price (guaranteed match).
     */
    protected function computeTierPricesForTemplate(int $index): void
    {
        if (! $this->useTierPricing) {
            return;
        }

        $templateData = $this->selectedTemplates[$index];
        $cardPrice = (float) ($templateData['price'] ?? 0);

        // Fixed price items: same price across all tiers
        if (($templateData['is_fixed_price'] ?? false) || ($templateData['pricing_method'] ?? '') === 'fixed_price') {
            $this->selectedTemplates[$index]['tier_prices'] = [
                'good' => $cardPrice, 'better' => $cardPrice, 'best' => $cardPrice,
            ];

            return;
        }

        $areaData = $templateData['area_data'] ?? null;
        if (! $areaData || empty($areaData['surfaces'])) {
            // No surface data: use card price for all tiers
            $this->selectedTemplates[$index]['tier_prices'] = [
                'good' => $cardPrice, 'better' => $cardPrice, 'best' => $cardPrice,
            ];

            return;
        }

        $settings = CompanySetting::getInstance();
        $scopeType = $templateData['scope_type'] ?? 'interior';
        $effectiveMargin = min(99, $settings->getMarginPercentForScope($scopeType) + ($this->marginDiscountPercent ?? 0));

        // Use the stored non-material COGS components from the area-builder's calculation
        $laborCost = (float) ($templateData['labor_cost'] ?? 0);
        $cleanupCost = (float) ($templateData['cleanup_cost'] ?? 0);
        $sundriesCost = (float) ($templateData['sundries_cost'] ?? 0);
        $addonCost = array_sum(array_column($areaData['addons'] ?? [], 'total_price'));

        // Good tier always = card price (guaranteed sync, no recalculation divergence)
        $tierPrices = ['good' => $cardPrice];
        $tierCogsMap = ['good' => round((float) ($templateData['base_total'] ?? 0), 2)];

        // Compute only better and best tier prices (different paint products → different material costs)
        foreach (['better', 'best'] as $tier) {
            $tierPaintId = $this->tierPaintConfig[$tier]['default_paint_product_id'] ?? null;

            // Compute material cost for this tier's paint product
            $tierMaterialCost = 0.0;
            foreach ($areaData['surfaces'] as $surface) {
                if (! ($surface['is_required'] ?? false) && ! ($surface['is_default_enabled'] ?? true)) {
                    continue;
                }
                if (($surface['fixed_price'] ?? null) !== null && $surface['fixed_price'] > 0) {
                    $tierMaterialCost += (float) $surface['fixed_price'];

                    continue;
                }

                // Use tier-specific quantity override if set
                $tierQuantityOverride = $surface['tier_overrides'][$tier]['quantity_override'] ?? null;
                if ($tierQuantityOverride !== null && $tierQuantityOverride > 0) {
                    $quantity = (float) $tierQuantityOverride;
                } else {
                    $quantity = match ($surface['quantity_source'] ?? 'wall_sqft') {
                        'wall_sqft' => $areaData['wall_sqft'] ?? 0,
                        'ceiling_sqft' => $areaData['ceiling_sqft'] ?? 0,
                        'perimeter_lnft' => $areaData['perimeter_lnft'] ?? 0,
                        default => $surface['quantity_override'] ?? 1,
                    };
                }
                // Use tier-specific coats from tier_overrides when available
                $coats = $surface['tier_overrides'][$tier]['default_coats']
                    ?? $surface['default_coats']
                    ?? $templateData['coats']
                    ?? 2;
                $coatMultiplier = match ($coats) {
                    1 => 1.0, 2 => 1.8, 3 => 2.5, 4 => 3.1,
                    default => 1.0 + (($coats - 1) * 0.7),
                };

                $tierMaterialCost += $this->calculateMaterialCostForSurface(
                    $quantity, $coats, $tierPaintId, null, $settings, null, $coatMultiplier
                );
            }

            // Check if any surface has tier_overrides for this tier
            $tierLaborCost = (float) ($templateData['labor_cost'] ?? 0);
            $tierLaborHours = (float) ($templateData['labor_hours'] ?? 0);
            $hasTierLabor = false;
            foreach ($areaData['surfaces'] as $surface) {
                if (isset($surface['tier_overrides'][$tier]['labor_hours'])) {
                    $hasTierLabor = true;
                    break;
                }
            }
            if ($hasTierLabor) {
                $tierLaborCost = 0.0;
                $tierLaborHours = 0.0;
                $hourlyWage = $this->getEffectiveRateForScope($scopeType);
                foreach ($areaData['surfaces'] as $surface) {
                    if (! ($surface['is_required'] ?? false) && ! ($surface['is_default_enabled'] ?? true)) {
                        continue;
                    }
                    $sLaborHours = (float) ($surface['tier_overrides'][$tier]['labor_hours'] ?? $surface['labor_hours'] ?? 0);
                    $tierLaborHours += $sLaborHours;
                    $tierLaborCost += (float) ($surface['tier_overrides'][$tier]['labor_cost'] ?? ($sLaborHours * $hourlyWage));
                }
            }

            $tierCleanupCost = (float) ($templateData['cleanup_cost'] ?? 0);
            $tierSundriesCost = (float) ($templateData['sundries_cost'] ?? 0);
            if ($hasTierLabor) {
                $hourlyWage = $this->getEffectiveRateForScope($scopeType);
                $tierCleanupHours = $settings->calculateCleanupHours($tierLaborHours, $scopeType);
                $tierCleanupCost = $tierCleanupHours * $hourlyWage;
                $tierSundriesCost = $settings->calculateSundriesCost($tierLaborHours, $scopeType);
            }

            $tierCogsResult = $settings->calculateCOGS(
                $tierLaborCost, $tierCleanupCost, $tierSundriesCost,
                $tierMaterialCost + $addonCost
            );
            $tierCogsVal = $tierCogsResult['cogs'];
            $tierCogsMap[$tier] = round($tierCogsVal, 2);
            $tierPrices[$tier] = $tierCogsVal > 0
                ? round($tierCogsVal / ($effectiveMargin / 100), 2)
                : 0.0;
        }

        $this->selectedTemplates[$index]['tier_prices'] = $tierPrices;
        $this->selectedTemplates[$index]['tier_cogs'] = $tierCogsMap;
    }

    /**
     * Calculate live tier totals by summing pre-computed tier_prices from each template.
     * Tier prices are computed inline within recalculateSelectedTemplatePrice() to
     * guarantee consistency with the card price (same code path, same inputs).
     *
     * @return array{good: float, better: float, best: float}
     */
    protected function calculateLiveTierTotals(): array
    {
        if (! $this->useTierPricing || empty($this->selectedTemplates)) {
            $baseTotal = $this->subtotal;

            return ['good' => $baseTotal, 'better' => $baseTotal, 'best' => $baseTotal];
        }

        $totals = ['good' => 0.0, 'better' => 0.0, 'best' => 0.0];

        foreach ($this->selectedTemplates as $templateData) {
            // Determine which tiers this area belongs to
            $areaTiers = ! empty($templateData['is_fixed_price'])
                ? ($templateData['package_tiers'] ?? null)
                : ($templateData['area_data']['package_tiers'] ?? null);

            if (isset($templateData['tier_prices'])) {
                // Use pre-computed tier prices (from recalculateSelectedTemplatePrice)
                foreach (['good', 'better', 'best'] as $tier) {
                    if ($areaTiers !== null && ! in_array($tier, $areaTiers)) {
                        continue;
                    }
                    $totals[$tier] += (float) ($templateData['tier_prices'][$tier] ?? 0);
                }
            } else {
                // No tier prices computed — use card price for all tiers
                $price = (float) ($templateData['price'] ?? 0);
                foreach (['good', 'better', 'best'] as $tier) {
                    if ($areaTiers !== null && ! in_array($tier, $areaTiers)) {
                        continue;
                    }
                    $totals[$tier] += $price;
                }
            }
        }

        return $totals;
    }

    /**
     * Dispatch live tier totals to the paint-selector component.
     * Called when areas change so Good/Better/Best prices update in real-time.
     */
    protected function dispatchLiveTierTotals(): void
    {
        if (! $this->useTierPricing) {
            return;
        }

        $this->dispatch('live-tier-totals', totals: $this->calculateLiveTierTotals());
    }

    /**
     * Get total labor hours from all areas.
     */
    #[Computed]
    public function totalLaborHours(): float
    {
        if (! empty($this->areas)) {
            return array_sum(array_column($this->areas, 'labor_hours'));
        }

        return array_sum(array_column($this->selectedTemplates, 'labor_hours'));
    }



    /**
     * Recalculate prices for all selected templates/areas.
     */
    protected function recalculateAllPrices(): void
    {
        foreach ($this->selectedTemplates as $index => $templateData) {
            // Fixed-price items don't need recalculation
            if (! empty($templateData['is_fixed_price'])) {
                continue;
            }

            if (! empty($templateData['area_data'])) {
                $this->recalculateSelectedTemplatePrice($index);
            } elseif (! empty($templateData['template_id'])) {
                $this->recalculatePrice($index);
            }
        }

        $this->dispatchLiveTierTotals();
    }

    /**
     * Calculate the tax amount.
     */
    #[Computed]
    public function taxAmount(): float
    {
        return $this->subtotal * (($this->taxRate ?? 0) / 100);
    }

    /**
     * Calculate the total.
     */
    #[Computed]
    public function total(): float
    {
        return $this->subtotal + $this->taxAmount - ($this->discountAmount ?? 0);
    }

    /**
     * Calculate the deposit amount.
     */
    #[Computed]
    public function depositAmount(): float
    {
        return $this->total * (($this->depositPercent ?? 0) / 100);
    }

    /**
     * Calculate the balance due.
     */
    #[Computed]
    public function balanceDue(): float
    {
        return $this->total - $this->depositAmount;
    }

    /**
     * Save the estimate.
     */
    public function save(bool $markAsSent = false): void
    {
        $this->validate();

        // Build paint_products_by_category JSON
        $paintProductsByCategory = null;
        if (! empty($this->paintByCategory)) {
            $paintProductsByCategory = [
                $this->projectScope => array_filter($this->paintByCategory, fn ($v) => $v !== null && $v !== ''),
            ];
            // Remove if empty after filtering
            if (empty($paintProductsByCategory[$this->projectScope])) {
                $paintProductsByCategory = null;
            }
        }

        $estimateData = [
            'deal_id' => $this->deal->id,
            'title' => $this->title,
            'estimator_mode' => Estimate::MODE_TEMPLATE,
            'project_type' => $this->projectScope,
            // For template mode, the subtotal comes from template calculations (with margins)
            // areas_subtotal should match subtotal since all costs are area-based
            'items_subtotal' => 0,
            'areas_subtotal' => $this->subtotal,
            'options_subtotal' => 0,
            'subtotal' => $this->subtotal,
            'tax_rate' => $this->taxRate ?? 0,
            'tax_amount' => $this->taxAmount,
            'discount_amount' => $this->discountAmount ?? 0,
            'margin_discount_percent' => $this->marginDiscountPercent ?? 0,
            'hourly_rate_override' => $this->hourlyRateOverride,
            'total' => $this->total,
            'deposit_percent' => $this->depositPercent ?? 30,
            'deposit_amount' => $this->depositAmount,
            'balance_due' => $this->balanceDue,
            'notes' => $this->notes ?: null,
            'valid_until' => $this->validUntil ?: null,
            'default_paint_product_id' => $this->defaultPaintProductId,
            'paint_products_by_category' => $paintProductsByCategory,
        ];

        $templateService = app(EstimateTemplateService::class);

        $packageGenerator = app(EstimatePackageGeneratorService::class);

        if ($this->isEditing) {
            $this->estimate->update($estimateData);

            // Snapshot package selections while old areas/surfaces still exist
            $packageSnapshot = $packageGenerator->snapshotPackageSelections($this->estimate);

            // Remove existing template usages
            $this->estimate->templateUsages()->delete();

            // Remove existing areas and items created from templates
            $this->estimate->areas()->delete();
            $this->estimate->items()->delete();
            $this->estimate->optionalItems()->delete();

            // Apply templates (which may include area_data from AreaBuilder)
            $this->applySelectedTemplatesToEstimate($templateService);

            $this->estimate->refresh();
            $this->estimate->calculateTotals();
            $this->estimate->save();

            // Regenerate packages with new areas/surfaces, then restore selections
            $packageGenerator->clearGeneratedPackages($this->estimate);
            $packageGenerator->generateFromTemplates($this->estimate);
            $packageGenerator->restorePackageSelections($this->estimate, $packageSnapshot);

            if ($markAsSent && $this->estimate->status === 'draft') {
                $this->estimate->markAsSent();
            }

            // Create amendment if editing a sent estimate
            if ($this->estimate->status === 'sent') {
                $amendmentService = app(EstimateAmendmentService::class);

                // Capture snapshot if missing (for estimates sent before amendment system)
                if (! $this->estimate->sent_snapshot) {
                    $this->estimate->sent_snapshot = $amendmentService->captureSnapshot($this->estimate);
                    $this->estimate->save();
                    session()->flash('success', __('Estimate updated successfully.'));
                } else {
                    $amendment = $amendmentService->createAmendment($this->estimate, auth()->user());
                    if ($amendment) {
                        session()->flash('success', __('Estimate updated. Amendment #:number created — customer will be notified.', ['number' => $amendment->amendment_number]));
                    } else {
                        session()->flash('success', __('Estimate updated (no material changes detected).'));
                    }
                }
            } else {
                session()->flash('success', __('Estimate updated successfully.'));
            }

            $this->dispatch('estimate-updated');
        } else {
            $estimateData['created_by'] = auth()->id();
            $estimateData['status'] = 'draft'; // Always create as draft first

            $this->estimate = Estimate::create($estimateData);

            // Apply templates (which may include area_data from AreaBuilder)
            $this->applySelectedTemplatesToEstimate($templateService);

            $this->estimate->refresh();
            $this->estimate->calculateTotals();
            $this->estimate->save();

            // Generate packages from templates
            $packageGenerator->generateFromTemplates($this->estimate);

            // Now mark as sent if requested (this generates the public token)
            if ($markAsSent) {
                $this->estimate->markAsSent();
            }

            $this->dispatch('estimate-created');
            session()->flash('success', __('Estimate created successfully.'));
        }

        // Save tier pricing config if enabled
        if ($this->useTierPricing) {
            foreach (['good', 'better', 'best'] as $tier) {
                $this->estimate->setPaintTier($tier, $this->tierPaintConfig[$tier]);
            }
            $this->estimate->save();
            app(EstimateTierPricingService::class)->recalculateTierCosts($this->estimate);

            // Recalculate totals now that tier material costs are populated
            $this->estimate->refresh();
            $this->estimate->calculateTotals();
            $this->estimate->save();

            // Recalculate packages with tier pricing
            foreach ($this->estimate->packages as $package) {
                $package->calculateTotals();
                $package->save();
            }
        }

        broadcast(new EstimateAreaUpdated(
            $this->estimate->id,
            0,
            $this->isEditing ? 'updated' : 'created',
            auth()->user()?->name ?? 'System'
        ))->toOthers();

        $this->redirect(route('estimates.show', $this->estimate), navigate: true);
    }

    /**
     * Apply areas from the new builder flow to the estimate.
     */
    protected function applyAreasToEstimate(): void
    {
        $settings = CompanySetting::getInstance();

        foreach ($this->areas as $sortOrder => $areaData) {
            // Create the EstimateArea with default paint product if set
            $areaPaintProductId = $areaData['default_paint_product_id'] ?? $this->getEffectivePaintProductId();

            $area = EstimateArea::create([
                'estimate_id' => $this->estimate->id,
                'name' => $areaData['name'],
                'scope_type' => $areaData['scope_type'] ?? 'interior',
                'length' => $areaData['length'] ?? 0,
                'width' => $areaData['width'] ?? 0,
                'height' => $areaData['height'] ?? 0,
                'wall_sqft' => $areaData['wall_sqft'] ?? 0,
                'ceiling_sqft' => $areaData['ceiling_sqft'] ?? 0,
                'perimeter_lnft' => $areaData['perimeter_lnft'] ?? 0,
                'measurement_type' => $areaData['measurement_type'] ?? 'dimensions',
                'wall_count' => $areaData['wall_count'] ?? 4,
                'direct_sqft' => $areaData['direct_sqft'] ?? null,
                'direct_lnft' => $areaData['direct_lnft'] ?? null,
                'direct_ceiling_sqft' => $areaData['direct_ceiling_sqft'] ?? null,
                'service_category_id' => $areaData['service_category_id'] ?? null,
                'default_paint_product_id' => $areaPaintProductId,
                'cached_cogs' => $areaData['base_total'] ?? null,
                'cached_customer_price' => $areaData['price'] ?? null,
                'cached_tier_prices' => $this->buildCachedTierPrices($areaData),
                'sort_order' => $sortOrder,
            ]);

            // Create surfaces for the area
            foreach ($areaData['surfaces'] ?? [] as $surfaceSortOrder => $surfaceData) {
                if (! ($surfaceData['is_required'] ?? false) && ! ($surfaceData['is_default_enabled'] ?? true)) {
                    continue;
                }

                $isCustom = (bool) ($surfaceData['is_custom'] ?? false);

                if ($isCustom) {
                    // Custom surface: use user-entered values directly
                    $laborHours = (float) ($surfaceData['labor_hours'] ?? 0);
                    $materialCost = (float) ($surfaceData['material_cost'] ?? 0);
                    $scopeType = $areaData['scope_type'] ?? 'interior';
                    $hourlyWage = $this->getEffectiveRateForScope($scopeType);
                    $laborCost = $laborHours * $hourlyWage;
                    $productionRateValue = 0;
                    $materialCostPerUnit = 0;
                    $paintGallons = null;
                    $paintCost = null;
                    $paintProductId = null;
                    $coats = $surfaceData['default_coats'] ?? 2;
                    $applicationMethod = $surfaceData['application_method'] ?? 'brush_roll';
                    $quantity = (float) ($surfaceData['quantity_override'] ?? 0);
                } else {
                    $rate = $surfaceData['production_rate_id']
                        ? ProductionRate::find($surfaceData['production_rate_id'])
                        : null;

                    // Calculate quantity based on source
                    $quantity = match ($surfaceData['quantity_source'] ?? 'wall_sqft') {
                        'wall_sqft' => $areaData['wall_sqft'],
                        'ceiling_sqft' => $areaData['ceiling_sqft'],
                        'perimeter_lnft' => $areaData['perimeter_lnft'],
                        default => $surfaceData['quantity_override'] ?? 1,
                    };

                    // Calculate labor hours and costs
                    $coats = $surfaceData['default_coats'] ?? $areaData['default_coats'] ?? 2;
                    $productionRateValue = $rate?->production_rate ?? 100;
                    $coatMultiplier = $rate ? $rate->getCoatMultiplier($coats) : ($coats === 1 ? 1.0 : ($coats === 2 ? 1.8 : 2.5));
                    $laborHours = ($quantity / $productionRateValue) * $coatMultiplier;

                    $scopeType = $areaData['scope_type'] ?? 'interior';
                    $hourlyWage = $this->getEffectiveRateForScope($scopeType);
                    $laborCost = $laborHours * $hourlyWage;

                    // Get effective paint product ID (cascade: surface → area → estimate)
                    $paintProductId = $surfaceData['paint_product_id']
                        ?? $areaPaintProductId;

                    // Calculate material cost using paint product if available
                    $materialCostPerUnit = 0.0;
                    $paintGallons = null;
                    $paintCost = null;
                    $materialCost = 0.0;
                    $applicationMethod = $surfaceData['application_method'] ?? $areaData['application_method'] ?? 'brush_roll';

                    if ($paintProductId) {
                        $paintProduct = PaintProduct::find($paintProductId);
                        if ($paintProduct) {
                            $recycleFee = $settings->recycle_fee_per_gallon ?? 0.54;
                            $costCalc = $paintProduct->calculateCost(
                                (float) $quantity,
                                $coats,
                                $applicationMethod,
                                (float) $recycleFee
                            );
                            $paintGallons = $costCalc['gallons_rounded'];
                            $paintCost = $costCalc['paint_cost'];
                            $materialCost = $costCalc['total_cost'];

                            if ($quantity > 0) {
                                $materialCostPerUnit = $materialCost / ($quantity * $coatMultiplier);
                            }
                        }
                    }
                }

                EstimateAreaSurface::create([
                    'area_id' => $area->id,
                    'production_rate_id' => $isCustom ? null : ($surfaceData['production_rate_id'] ?? null),
                    'name' => $surfaceData['name'],
                    'surface_type' => $surfaceData['surface_type'] ?? 'other',
                    'surface_category' => $surfaceData['surface_category'] ?? null,
                    'quantity' => $quantity,
                    'unit' => match ($surfaceData['quantity_source'] ?? 'wall_sqft') {
                        'wall_sqft', 'ceiling_sqft' => 'sqft',
                        'perimeter_lnft' => 'lnft',
                        default => 'units',
                    },
                    'coats' => $coats,
                    'application_method' => $applicationMethod,
                    'production_rate_value' => $productionRateValue,
                    'hourly_labor_rate' => $hourlyWage,
                    'material_cost_per_unit' => $materialCostPerUnit,
                    'labor_hours' => round($laborHours, 2),
                    'labor_cost' => round($laborCost, 2),
                    'material_cost' => round($materialCost, 2),
                    'paint_product_id' => $paintProductId,
                    'paint_gallons' => $paintGallons,
                    'paint_cost' => $paintCost ? round($paintCost, 2) : null,
                    'total' => round($laborCost + $materialCost, 2),
                    'is_included' => true,
                    'is_custom' => $isCustom,
                    'sale_price' => $isCustom && ($surfaceData['sale_price'] ?? '') !== '' ? (float) $surfaceData['sale_price'] : null,
                    'crew_price' => $isCustom && ($surfaceData['crew_price'] ?? '') !== '' ? (float) $surfaceData['crew_price'] : null,
                    'sort_order' => $surfaceSortOrder,
                ]);
            }

            // Create addons for the area
            foreach ($areaData['addons'] ?? [] as $addonData) {
                EstimateAreaAddon::create([
                    'estimate_area_id' => $area->id,
                    'nested_area_template_id' => $addonData['nested_area_template_id'],
                    'quantity' => $addonData['quantity'],
                    'unit_price' => $addonData['unit_price'],
                    'total_price' => $addonData['total_price'],
                ]);
            }
        }
    }

    /**
     * Apply selected templates to the estimate.
     * Handles both regular templates and those with area_data from AreaBuilder.
     */
    protected function applySelectedTemplatesToEstimate(EstimateTemplateService $templateService): void
    {
        $lastArea = null;

        foreach ($this->selectedTemplates as $sortOrder => $templateData) {
            // If this is a fixed-price template (closet, half bath, etc.), create as fixed-price area
            if (! empty($templateData['is_fixed_price'])) {
                $this->createFixedPriceArea($templateData, $sortOrder);

                continue;
            }

            // If this template has area_data (from AreaBuilder), create as detailed area
            if (! empty($templateData['area_data'])) {
                $this->createAreaFromTemplateData($templateData, $sortOrder);

                continue;
            }

            // Otherwise, use the standard template service
            $template = EstimateTemplate::with('templateCategory')->find($templateData['template_id']);

            if (! $template) {
                continue;
            }

            $usage = $templateService->applyTemplateToEstimate(
                $this->estimate,
                $template,
                $templateData['quantity'],
                $templateData['coats'],
                $lastArea
            );

            // If this created an area, store it for surface templates
            if ($template->templateCategory?->type === EstimateTemplateCategory::TYPE_ROOM && $usage->resultable) {
                $lastArea = $usage->resultable;
            }
        }
    }

    /**
     * Restore tier_prices and tier_cogs arrays from an area's cached_tier_prices JSON.
     *
     * @return array{tier_prices: array|null, tier_cogs: array|null}
     */
    protected function restoreTierDataFromArea(EstimateArea $area): array
    {
        $cached = $area->cached_tier_prices;
        if (! $cached || ! is_array($cached)) {
            return ['tier_prices' => null, 'tier_cogs' => null];
        }

        $tierPrices = [];
        $tierCogs = [];
        foreach (['good', 'better', 'best'] as $tier) {
            if (isset($cached[$tier])) {
                $tierPrices[$tier] = (float) ($cached[$tier]['price'] ?? 0);
                $tierCogs[$tier] = (float) ($cached[$tier]['cogs'] ?? 0);
            }
        }

        return [
            'tier_prices' => ! empty($tierPrices) ? $tierPrices : null,
            'tier_cogs' => ! empty($tierCogs) ? $tierCogs : null,
        ];
    }

    /**
     * Build cached tier prices JSON from template/area data.
     * Combines tier_prices (OTD) and tier_cogs into a structure for EstimateArea.
     *
     * @return array<string, array{cogs: float, price: float}>|null
     */
    protected function buildCachedTierPrices(array $data): ?array
    {
        $tierPrices = $data['tier_prices'] ?? null;
        $tierCogs = $data['tier_cogs'] ?? null;

        if (! $tierPrices) {
            return null;
        }

        $cached = [];
        foreach (['good', 'better', 'best'] as $tier) {
            if (isset($tierPrices[$tier])) {
                $cached[$tier] = [
                    'cogs' => (float) ($tierCogs[$tier] ?? 0),
                    'price' => (float) $tierPrices[$tier],
                ];
            }
        }

        return ! empty($cached) ? $cached : null;
    }

    /**
     * Create fixed-price EstimateArea records from a fixed-price template entry.
     * Creates one area per unit of quantity (e.g., 2 closets = 2 separate areas).
     */
    protected function createFixedPriceArea(array $templateData, int $sortOrder): void
    {
        $quantity = max(1, (int) ($templateData['quantity'] ?? 1));
        $unitPrice = (float) ($templateData['unit_price'] ?? $templateData['price'] ?? 0);
        $nestedTemplateId = $templateData['nested_template_id'] ?? null;
        $scopeType = $templateData['scope_type'] ?? 'interior';

        for ($i = 0; $i < $quantity; $i++) {
            $area = EstimateArea::create([
                'estimate_id' => $this->estimate->id,
                'name' => $templateData['name'],
                'scope_type' => $scopeType,
                'nested_area_template_id' => $nestedTemplateId,
                'is_fixed_price' => true,
                'fixed_price_amount' => $unitPrice,
                'estimated_labor_hours' => $templateData['labor_hours'] ?? null,
                'estimated_material_cost' => $templateData['material_cost'] ?? null,
                'package_tiers' => $templateData['package_tiers'] ?? null,
                'sort_order' => $sortOrder,
            ]);

            $this->estimate->templateUsages()->create([
                'template_id' => null,
                'quantity' => 1,
                'coats' => 1,
                'calculated_price' => $unitPrice,
                'resultable_type' => EstimateArea::class,
                'resultable_id' => $area->id,
                'sort_order' => $sortOrder,
            ]);
        }
    }

    /**
     * Create an EstimateArea from template data that includes area_data (from AreaBuilder).
     */
    protected function createAreaFromTemplateData(array $templateData, int $sortOrder): void
    {
        $areaData = $templateData['area_data'];

        // Create the EstimateArea
        $area = EstimateArea::create([
            'estimate_id' => $this->estimate->id,
            'name' => $templateData['name'],
            'scope_type' => $templateData['scope_type'] ?? 'interior',
            'length' => $areaData['length'] ?? 0,
            'width' => $areaData['width'] ?? 0,
            'height' => $areaData['height'] ?? 0,
            'wall_sqft' => $areaData['wall_sqft'] ?? 0,
            'ceiling_sqft' => $areaData['ceiling_sqft'] ?? 0,
            'perimeter_lnft' => $areaData['perimeter_lnft'] ?? 0,
            'measurement_type' => $areaData['measurement_type'] ?? 'dimensions',
            'wall_count' => $areaData['wall_count'] ?? 4,
            'direct_sqft' => $areaData['direct_sqft'] ?? null,
            'direct_lnft' => $areaData['direct_lnft'] ?? null,
            'direct_ceiling_sqft' => $areaData['direct_ceiling_sqft'] ?? null,
            'service_category_id' => $templateData['service_category_id'] ?? null,
            'cached_cogs' => $templateData['base_total'] ?? null,
            'cached_customer_price' => $templateData['price'] ?? null,
            'cached_tier_prices' => $this->buildCachedTierPrices($templateData),
            'package_tiers' => $areaData['package_tiers'] ?? null,
            'sort_order' => $sortOrder,
        ]);

        // Create surfaces for the area (including disabled ones so they persist on reload)
        foreach ($areaData['surfaces'] ?? [] as $surfaceSortOrder => $surfaceData) {
            $isIncluded = ($surfaceData['is_required'] ?? false) || ($surfaceData['is_default_enabled'] ?? true);
            $isCustom = (bool) ($surfaceData['is_custom'] ?? false);

            if ($isCustom) {
                // Custom surface: use user-entered values directly
                $laborHours = (float) ($surfaceData['labor_hours'] ?? 0);
                $materialCost = (float) ($surfaceData['material_cost'] ?? 0);
                $settings = CompanySetting::getInstance();
                $scopeType = $templateData['scope_type'] ?? 'interior';
                $hourlyWage = $this->getEffectiveRateForScope($scopeType);
                $laborCost = $laborHours * $hourlyWage;
                $productionRateValue = 0;
                $materialCostPerUnit = 0;
                $paintGallons = null;
                $paintCost = null;
                $paintProductId = null;
                $coats = $surfaceData['default_coats'] ?? $templateData['coats'] ?? 2;
                $applicationMethod = $surfaceData['application_method'] ?? 'brush_roll';
                $quantity = (float) ($surfaceData['quantity_override'] ?? 0);
            } else {
                $rate = $surfaceData['production_rate_id']
                    ? ProductionRate::find($surfaceData['production_rate_id'])
                    : null;

                // Calculate quantity based on source
                $quantity = match ($surfaceData['quantity_source'] ?? 'wall_sqft') {
                    'wall_sqft' => $areaData['wall_sqft'],
                    'ceiling_sqft' => $areaData['ceiling_sqft'],
                    'perimeter_lnft' => $areaData['perimeter_lnft'],
                    default => $surfaceData['quantity_override'] ?? 1,
                };

                $coats = $surfaceData['default_coats'] ?? $templateData['coats'] ?? 2;
                $productionRateValue = $rate?->production_rate ?? 100;
                $settings = CompanySetting::getInstance();
                $scopeType = $templateData['scope_type'] ?? 'interior';
                $hourlyWage = $this->getEffectiveRateForScope($scopeType);
                $applicationMethod = $surfaceData['application_method'] ?? $areaData['application_method'] ?? 'brush_roll';

                // Use pre-computed values from template-builder/area-builder live calculation
                // when available. This ensures saved data matches exactly what was displayed.
                if (isset($surfaceData['labor_hours']) && $surfaceData['labor_hours'] !== null) {
                    $laborHours = (float) $surfaceData['labor_hours'];
                    $laborCost = (float) ($surfaceData['labor_cost'] ?? ($laborHours * $hourlyWage));
                    $materialCost = (float) ($surfaceData['material_cost'] ?? 0);
                } else {
                    // Fallback: recalculate using consistent coat multiplier
                    $coatMultiplier = match ($coats) {
                        1 => 1.0,
                        2 => 1.8,
                        3 => 2.5,
                        4 => 3.1,
                        default => 1.0 + (($coats - 1) * 0.7),
                    };
                    $laborHours = ($quantity / $productionRateValue) * $coatMultiplier;
                    $laborCost = $laborHours * $hourlyWage;
                    $materialCost = 0.0;
                }

                // Get effective paint product ID (cascade: surface → area → template → estimate → good tier)
                $paintProductId = $surfaceData['paint_product_id']
                    ?? $areaData['default_paint_product_id']
                    ?? $templateData['paint_product_id']
                    ?? $this->getEffectivePaintProductId();

                $materialCostPerUnit = 0.0;
                $paintGallons = null;
                $paintCost = null;

                // Compute paint product details (gallons, cost) for ordering/audit fields
                if ($paintProductId) {
                    $paintProduct = PaintProduct::find($paintProductId);
                    if ($paintProduct) {
                        $recycleFee = $settings->recycle_fee_per_gallon ?? 0.54;
                        $costCalc = $paintProduct->calculateCost(
                            (float) $quantity,
                            $coats,
                            $applicationMethod,
                            (float) $recycleFee
                        );
                        $paintGallons = $costCalc['gallons_rounded'];
                        $paintCost = $costCalc['paint_cost'];

                        // If no pre-computed material cost, use PaintProduct calculation
                        if (! isset($surfaceData['material_cost']) || $surfaceData['material_cost'] === null) {
                            $materialCost = $costCalc['total_cost'];
                        }

                        $coatMultiplier = match ($coats) {
                            1 => 1.0, 2 => 1.8, 3 => 2.5, 4 => 3.1,
                            default => 1.0 + (($coats - 1) * 0.7),
                        };
                        if ($quantity > 0 && $coatMultiplier > 0) {
                            $materialCostPerUnit = $materialCost / ($quantity * $coatMultiplier);
                        }
                    }
                }
            }

            EstimateAreaSurface::create([
                'area_id' => $area->id,
                'production_rate_id' => $isCustom ? null : ($surfaceData['production_rate_id'] ?: null),
                'name' => $surfaceData['name'],
                'surface_type' => $surfaceData['surface_type'] ?? 'other',
                'surface_category' => $surfaceData['surface_category'] ?? null,
                'quantity' => $quantity,
                'unit' => match ($surfaceData['quantity_source'] ?? 'wall_sqft') {
                    'wall_sqft', 'ceiling_sqft' => 'sqft',
                    'perimeter_lnft' => 'lnft',
                    'door_count', 'window_count' => 'units',
                    'custom' => $this->inferUnitFromSurface($surfaceData),
                    default => 'units',
                },
                'coats' => $coats,
                'application_method' => $applicationMethod,
                'production_rate_value' => $productionRateValue,
                'hourly_labor_rate' => $hourlyWage,
                'material_cost_per_unit' => $materialCostPerUnit,
                'labor_hours' => round($laborHours, 2),
                'labor_cost' => round($laborCost, 2),
                'material_cost' => round($materialCost, 2),
                'paint_product_id' => $paintProductId,
                'paint_gallons' => $paintGallons,
                'paint_cost' => $paintCost ? round($paintCost, 2) : null,
                'total' => round($laborCost + $materialCost, 2),
                'is_included' => $isIncluded,
                'is_custom' => $isCustom,
                'sale_price' => $isCustom && ($surfaceData['sale_price'] ?? '') !== '' ? (float) $surfaceData['sale_price'] : null,
                'crew_price' => $isCustom && ($surfaceData['crew_price'] ?? '') !== '' ? (float) $surfaceData['crew_price'] : null,
                'tier_overrides' => ! empty($surfaceData['tier_overrides']) ? $surfaceData['tier_overrides'] : null,
                'sort_order' => $surfaceSortOrder,
            ]);
        }

        // Create addons for the area
        foreach ($areaData['addons'] ?? [] as $addonData) {
            EstimateAreaAddon::create([
                'estimate_area_id' => $area->id,
                'nested_area_template_id' => $addonData['nested_area_template_id'],
                'quantity' => $addonData['quantity'],
                'unit_price' => $addonData['unit_price'],
                'total_price' => $addonData['total_price'],
            ]);
        }

        // Also create a template usage record for tracking
        if ($templateData['template_id']) {
            $template = EstimateTemplate::find($templateData['template_id']);
            if ($template) {
                $this->estimate->templateUsages()->create([
                    'template_id' => $template->id,
                    'quantity' => $templateData['quantity'],
                    'coats' => $templateData['coats'],
                    'calculated_price' => $templateData['price'],
                    'resultable_type' => EstimateArea::class,
                    'resultable_id' => $area->id,
                ]);
            }
        }
    }

    /**
     * Apply selected templates to the estimate using the service (legacy method).
     */
    protected function applyTemplatesToEstimate(EstimateTemplateService $templateService): void
    {
        $lastArea = null;

        foreach ($this->selectedTemplates as $templateData) {
            $template = EstimateTemplate::with('templateCategory')->find($templateData['template_id']);

            if (! $template) {
                continue;
            }

            $usage = $templateService->applyTemplateToEstimate(
                $this->estimate,
                $template,
                $templateData['quantity'],
                $templateData['coats'],
                $lastArea
            );

            // If this created an area, store it for surface templates
            if ($template->templateCategory?->type === EstimateTemplateCategory::TYPE_ROOM && $usage->resultable) {
                $lastArea = $usage->resultable;
            }
        }
    }

    /**
     * Save as draft.
     */
    public function saveAsDraft(): void
    {
        $this->save(false);
    }

    /**
     * Save and send.
     */
    public function saveAndSend(): void
    {
        $this->save(true);
    }

    // =========================================================================
    // AI CHAT METHODS (Multi-Thread)
    // =========================================================================

    /**
     * Toggle the AI chat panel.
     */
    public function toggleAiChat(): void
    {
        $this->showAiChat = ! $this->showAiChat;
    }

    /**
     * Create a new AI thread.
     */
    public function createAiThread(?string $name = null): void
    {
        if (! $this->estimate?->id) {
            $this->save(false);
            if (! $this->estimate?->id) {
                $this->aiError = 'Please save the estimate first.';
                return;
            }
        }

        $thread = EstimateAiThread::create([
            'estimate_id' => $this->estimate->id,
            'name' => $name ?: 'Thread ' . ($this->aiThreads->count() + 1),
        ]);

        $this->aiThreads->push($thread->load('messages'));
        $this->activeThreadId = $thread->id;
    }

    /**
     * Switch to a different AI thread tab.
     */
    public function switchThread(int $threadId): void
    {
        $this->activeThreadId = $threadId;
        $this->aiError = null;
    }

    /**
     * Rename an AI thread.
     */
    public function renameThread(int $threadId, string $name): void
    {
        $thread = $this->aiThreads->firstWhere('id', $threadId);
        if (! $thread) {
            return;
        }

        $thread->update(['name' => $name]);
        $this->refreshThread($threadId);
    }

    /**
     * Delete an AI thread and its messages.
     */
    public function deleteThread(int $threadId): void
    {
        $thread = $this->aiThreads->firstWhere('id', $threadId);
        if (! $thread || $thread->is_processing) {
            return;
        }

        $thread->delete();
        $this->aiThreads = $this->aiThreads->reject(fn ($t) => $t->id === $threadId)->values();

        if ($this->activeThreadId === $threadId) {
            $this->activeThreadId = $this->aiThreads->last()?->id;
        }
    }

    /**
     * Send a message to the AI agent (dispatches async job).
     */
    public function sendAiChatMessage(): void
    {
        if (empty(trim($this->aiChatInput))) {
            return;
        }

        $this->authorize('ai.estimate_builder');

        // Ensure estimate is saved first
        if (! $this->estimate?->id) {
            $this->save(false);
            if (! $this->estimate?->id) {
                $this->aiError = 'Please save the estimate first.';
                return;
            }
        }

        // Auto-create thread if none exists
        if (! $this->activeThreadId) {
            $this->createAiThread();
        }

        $thread = $this->aiThreads->firstWhere('id', $this->activeThreadId);
        if (! $thread) {
            return;
        }

        // Block if THIS thread is already processing
        if ($thread->is_processing) {
            $this->aiError = 'This thread is still processing. Switch to another thread or wait.';
            return;
        }

        // Cap total concurrent across all threads
        $processingCount = $this->aiThreads->where('is_processing', true)->count();
        if ($processingCount >= $this->maxConcurrentThreads) {
            $this->aiError = 'Too many requests in progress. Please wait for one to finish.';
            return;
        }

        $userMessage = trim($this->aiChatInput);
        $this->aiChatInput = '';
        $this->aiError = null;

        // Save user message to DB
        $thread->messages()->create([
            'role' => 'user',
            'content' => $userMessage,
        ]);

        // Build conversation history from this thread's messages (excluding the one we just added)
        $history = $thread->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->orderBy('created_at')
            ->get()
            ->map(fn ($m) => ['role' => $m->role, 'content' => $m->content])
            ->all();

        // Remove the last message — it goes as userMessage param
        array_pop($history);

        // Generate cache key and mark thread as processing
        $cacheKey = 'ai-estimate-chat:' . $this->estimate->id . ':' . uniqid('', true);
        $thread->markProcessing($cacheKey);

        \Illuminate\Support\Facades\Cache::put($cacheKey, ['status' => 'processing'], now()->addMinutes(10));

        \App\Jobs\ProcessEstimateAiChat::dispatch(
            estimateId: $this->estimate->id,
            threadId: $thread->id,
            cacheKey: $cacheKey,
            userMessage: $userMessage,
            history: $history,
        );

        // Refresh the thread in our local collection
        $this->refreshThread($thread->id);
    }

    /**
     * Poll all processing threads for AI responses.
     */
    public function pollAiResponse(): void
    {
        $processingThreads = $this->aiThreads->where('is_processing', true);

        if ($processingThreads->isEmpty()) {
            $this->aiProgressLabel = '';

            return;
        }

        $anyCompleted = false;

        foreach ($processingThreads as $thread) {
            if (! $thread->cache_key) {
                continue;
            }

            // Check for progress updates
            $progress = \Illuminate\Support\Facades\Cache::get("{$thread->cache_key}:progress");
            if ($progress && $thread->id === $this->activeThreadId) {
                $this->aiProgressLabel = $progress['label'] ?? 'Working...';
            }

            $result = \Illuminate\Support\Facades\Cache::get($thread->cache_key);
            if (! $result || $result['status'] === 'processing') {
                continue;
            }

            // Job wrote the message to DB already — just refresh
            \Illuminate\Support\Facades\Cache::forget($thread->cache_key);
            \Illuminate\Support\Facades\Cache::forget("{$thread->cache_key}:progress");
            $this->refreshThread($thread->id);
            $anyCompleted = true;
        }

        if ($anyCompleted) {
            $this->aiProgressLabel = '';
            $this->refreshEstimateFromDb();
        }
    }

    /**
     * Reload a single thread from DB.
     */
    protected function refreshThread(int $threadId): void
    {
        $fresh = EstimateAiThread::with('messages')->find($threadId);
        if (! $fresh) {
            return;
        }

        $this->aiThreads = $this->aiThreads->map(
            fn ($t) => $t->id === $threadId ? $fresh : $t
        );
    }

    /**
     * Process a voice recording through transcription and AI.
     */
    public function processVoiceRecording(): void
    {
        if (! $this->voiceRecording) {
            return;
        }

        $this->authorize('ai.estimate_builder');
        $this->aiError = null;

        try {
            $python = app(PythonBridgeService::class);
            $result = $python->transcribeAudioFile($this->voiceRecording->getRealPath());

            $transcript = $result['text'] ?? '';
            if (empty(trim($transcript))) {
                $this->aiError = 'Could not transcribe the recording. Please try again.';
                return;
            }

            // Show transcript as user message and feed to AI
            $this->aiChatInput = $transcript;
            $this->voiceRecording = null;
            $this->sendAiChatMessage();
        } catch (\Throwable $e) {
            $this->aiError = 'Transcription error: ' . $e->getMessage();
        }
    }

    /**
     * Clear the active AI thread's messages.
     */
    public function clearAiThread(): void
    {
        $thread = $this->aiThreads->firstWhere('id', $this->activeThreadId);
        if (! $thread || $thread->is_processing) {
            return;
        }

        $thread->messages()->delete();
        $this->refreshThread($thread->id);
        $this->aiError = null;
    }

    /**
     * Refresh estimate data from database after AI changes.
     */
    protected function refreshEstimateFromDb(): void
    {
        if (! $this->estimate?->id) {
            return;
        }

        $this->estimate->refresh();
        $this->estimate->load(['areas.surfaces.productionRate', 'areas.addons.nestedAreaTemplate']);

        // Rebuild the areas array from DB
        $this->areas = [];
        foreach ($this->estimate->areas()->whereNull('parent_area_id')->orderBy('sort_order')->get() as $area) {
            $this->areas[] = $this->buildAreaDataFromModel($area);
        }

        // Update totals
        $this->estimate->calculateTotals();
        $this->estimate->calculateDepositAndBalance();
        $this->estimate->save();
    }

    /**
     * Build area data array from an EstimateArea model.
     */
    protected function buildAreaDataFromModel(EstimateArea $area): array
    {
        $settings = CompanySetting::getInstance();
        $scope = $area->scope_type ?? 'interior';
        $effectiveRate = $settings->getEffectiveHourlyRateForScope($scope);

        $surfaces = [];
        $totalLaborHours = 0;
        $totalMaterialCost = 0;

        foreach ($area->surfaces as $surface) {
            $laborHours = (float) ($surface->labor_hours ?? 0);
            $materialCost = (float) ($surface->material_cost ?? 0);
            $totalLaborHours += $laborHours;
            $totalMaterialCost += $materialCost;

            $surfaces[] = [
                'id' => $surface->id,
                'name' => $surface->name,
                'surface_type' => $surface->surface_type,
                'surface_category' => $surface->surface_category ?? $surface->getSurfaceCategory(),
                'quantity' => (float) $surface->quantity,
                'unit' => $surface->unit,
                'coats' => $surface->coats ?? 2,
                'production_rate_id' => $surface->production_rate_id,
                'production_rate_value' => (float) ($surface->production_rate_value ?? 200),
                'labor_hours' => $laborHours,
                'labor_cost' => (float) ($surface->labor_cost ?? 0),
                'material_cost' => $materialCost,
                'paint_product_id' => $surface->paint_product_id,
                'total' => (float) ($surface->total ?? 0),
                'is_required' => true,
                'is_default_enabled' => true,
            ];
        }

        $cleanupHours = $settings->calculateCleanupHours($totalLaborHours, $scope);
        $cleanupCost = $cleanupHours * $effectiveRate;
        $sundriesCost = $settings->calculateSundriesCost($totalLaborHours, $scope);
        $laborCost = $totalLaborHours * $effectiveRate;
        $addonCost = (float) $area->addons()->sum('total_price');

        $baseTotalResult = $settings->calculateCOGS($laborCost, $cleanupCost, $sundriesCost, $totalMaterialCost + $addonCost);
        $cogs = $baseTotalResult['cogs'];
        $margin = $settings->getMarginPercentForScope($scope) + ($this->marginDiscountPercent ?? 0);
        $withMargins = $margin > 0 ? round($cogs / ($margin / 100), 2) : $cogs;

        return [
            'name' => $area->name,
            'scope_type' => $scope,
            'source_template_id' => null,
            'length' => (float) ($area->length ?? 0),
            'width' => (float) ($area->width ?? 0),
            'height' => (float) ($area->height ?? 8),
            'wall_count' => $area->wall_count ?? 4,
            'wall_sqft' => (float) ($area->wall_sqft ?? 0),
            'ceiling_sqft' => (float) ($area->ceiling_sqft ?? 0),
            'perimeter_lnft' => (float) ($area->perimeter_lnft ?? 0),
            'default_coats' => 2,
            'substrate_id' => $area->substrate_id,
            'surfaces' => $surfaces,
            'addons' => $area->addons->map(fn ($a) => [
                'id' => $a->id,
                'template_id' => $a->nested_area_template_id,
                'name' => $a->name,
                'quantity' => $a->quantity,
                'unit_price' => (float) $a->unit_price,
                'total_price' => (float) $a->total_price,
            ])->toArray(),
            'labor_hours' => round($totalLaborHours, 2),
            'labor_cost' => round($laborCost, 2),
            'cleanup_hours' => round($cleanupHours, 2),
            'cleanup_cost' => round($cleanupCost, 2),
            'sundries_cost' => round($sundriesCost, 2),
            'material_cost' => round($totalMaterialCost, 2),
            'addon_cost' => round($addonCost, 2),
            'base_total' => round($cogs, 2),
            'with_margins' => round($withMargins, 2),
            'price' => round($withMargins, 2),
        ];
    }
}; ?>

<div>
    {{-- Mobile Sticky Header with Total --}}
    <div class="lg:hidden fixed top-0 left-0 right-0 z-40 bg-white dark:bg-zinc-900 border-b border-zinc-200 dark:border-zinc-700 px-4 py-3 shadow-sm">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                @if ($isEditing)
                    <flux:button variant="ghost" :href="route('estimates.show', $estimate)" icon="arrow-left" wire:navigate size="sm" />
                @else
                    <flux:button variant="ghost" :href="route('deals.show', $deal)" icon="arrow-left" wire:navigate size="sm" />
                @endif
                <div>
                    <div class="font-semibold text-sm">{{ __('Estimate Builder') }}</div>
                    <div class="text-xs text-zinc-500">{{ count($areas) > 0 ? count($areas) . ' areas' : count($selectedTemplates) . ' items' }}</div>
                </div>
            </div>
            <div class="text-right">
                <div class="text-xs text-zinc-500">{{ __('Total') }}</div>
                <div class="text-lg font-bold text-blue-600 dark:text-blue-400">${{ number_format($this->total, 2) }}</div>
            </div>
        </div>
    </div>

    {{-- Mobile Bottom Action Bar --}}
    <div class="lg:hidden fixed bottom-0 left-0 right-0 z-40 bg-white dark:bg-zinc-900 border-t border-zinc-200 dark:border-zinc-700 p-4 shadow-lg">
        <div class="flex gap-3">
            <flux:button wire:click="saveAsDraft" wire:loading.attr="disabled" class="flex-1" size="sm">
                <span wire:loading.remove wire:target="saveAsDraft">{{ __('Draft') }}</span>
                <span wire:loading wire:target="saveAsDraft">...</span>
            </flux:button>
            <flux:button wire:click="saveAndSend" variant="primary" wire:loading.attr="disabled" class="flex-1" size="sm">
                <span wire:loading.remove wire:target="saveAndSend">{{ __('Save & Send') }}</span>
                <span wire:loading wire:target="saveAndSend">...</span>
            </flux:button>
        </div>
    </div>

    {{-- Main Content --}}
    <div class="max-w-7xl mx-auto pt-16 pb-24 lg:pt-0 lg:pb-0">
        {{-- Desktop Header --}}
        <div class="hidden lg:flex items-center justify-between mb-6">
            <div class="flex items-center gap-4">
                @if ($isEditing)
                    <flux:button variant="ghost" :href="route('estimates.show', $estimate)" icon="arrow-left" wire:navigate>
                        {{ __('Back') }}
                    </flux:button>
                @else
                    <flux:button variant="ghost" :href="route('deals.show', $deal)" icon="arrow-left" wire:navigate>
                        {{ __('Back to Deal') }}
                    </flux:button>
                @endif
                <flux:heading size="xl">
                    @if ($isEditing)
                        {{ __('Edit Template Estimate:') }} {{ $estimate->estimate_number }}
                    @else
                        {{ __('Template Builder') }}
                    @endif
                </flux:heading>
            </div>
            @if ($canUseAiChat)
                <div class="relative">
                    <flux:button wire:click="toggleAiChat" variant="{{ $showAiChat ? 'primary' : 'ghost' }}" icon="sparkles" size="sm">
                        {{ __('AI Builder') }}
                    </flux:button>
                    @if ($aiThreads->where('is_processing', true)->count() > 0)
                        <span class="absolute -top-1.5 -right-1.5 flex items-center justify-center w-5 h-5 text-xs font-bold text-white bg-purple-600 rounded-full animate-pulse">
                            {{ $aiThreads->where('is_processing', true)->count() }}
                        </span>
                    @endif
                </div>
            @endif
        </div>

        @if (session('error'))
            <flux:callout variant="danger" class="mb-4 mx-4 lg:mx-0">
                {{ session('error') }}
            </flux:callout>
        @endif

        <div class="lg:grid lg:grid-cols-12 lg:gap-6">
            {{-- Main Column --}}
            <div class="lg:col-span-8 space-y-4 px-4 lg:px-0">
                {{-- Project Scope Selector (Primary) - At Top --}}
                <div class="border-2 border-blue-200 dark:border-blue-800 rounded-lg p-4 bg-gradient-to-r from-blue-50 to-white dark:from-blue-950/30 dark:to-zinc-900">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                        <div>
                            <flux:heading size="sm" class="text-blue-800 dark:text-blue-200">{{ __('Project Type') }}</flux:heading>
                            <flux:text size="sm" class="text-blue-600 dark:text-blue-400">{{ __('Select interior or exterior for this estimate') }}</flux:text>
                        </div>
                        <div class="flex gap-2">
                            @foreach ($this->getProjectScopeOptions() as $value => $label)
                                <button
                                    wire:click="$set('projectScope', '{{ $value }}')"
                                    type="button"
                                    class="px-6 py-2.5 text-sm font-semibold rounded-lg transition-all {{ $projectScope === $value ? ($value === 'interior' ? 'bg-blue-600 text-white shadow-md ring-2 ring-blue-300 dark:ring-blue-700' : 'bg-green-600 text-white shadow-md ring-2 ring-green-300 dark:ring-green-700') : 'bg-white dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 border border-zinc-300 dark:border-zinc-600 hover:border-blue-400 dark:hover:border-blue-500' }}"
                                >
                                    @if ($value === 'interior')
                                        <flux:icon name="home" class="w-4 h-4 inline mr-1.5 -mt-0.5" />
                                    @else
                                        <flux:icon name="sun" class="w-4 h-4 inline mr-1.5 -mt-0.5" />
                                    @endif
                                    {{ $label }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                </div>

                {{-- Project & Title - Collapsed Card --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 bg-white dark:bg-zinc-900">
                    <div class="flex flex-wrap items-center gap-x-6 gap-y-2 text-sm mb-4">
                        <div>
                            <span class="text-zinc-500 dark:text-zinc-400">{{ __('Deal') }}:</span>
                            <span class="font-medium ml-1">{{ $deal->title }}</span>
                        </div>
                        <div>
                            <span class="text-zinc-500 dark:text-zinc-400">{{ __('Customer') }}:</span>
                            <span class="font-medium ml-1">{{ $deal->customer?->full_name ?? '-' }}</span>
                        </div>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <flux:field>
                            <flux:label>{{ __('Estimate Title') }}</flux:label>
                            <flux:input wire:model="title" :placeholder="__('e.g., Interior Painting - Living Room')" />
                            <flux:error name="title" />
                        </flux:field>
                        <flux:field>
                            <flux:label>{{ __('Valid Until') }}</flux:label>
                            <flux:input wire:model="validUntil" type="date" />
                            <flux:error name="validUntil" />
                        </flux:field>
                    </div>
                </div>

                <livewire:estimates.paint-selector :project-scope="$projectScope" :estimate="$estimate" :initial-tier-totals="$this->useTierPricing ? $this->calculateLiveTierTotals() : null" />

                {{-- ADD AREAS Section (New Design) --}}
                <div class="border-2 border-green-200 dark:border-green-800 rounded-lg p-4 bg-gradient-to-r from-green-50 to-white dark:from-green-950/30 dark:to-zinc-900">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                        <div>
                            <flux:heading size="sm" class="text-green-800 dark:text-green-200">{{ __('Add Areas') }}</flux:heading>
                            <flux:text size="sm" class="text-green-600 dark:text-green-400">{{ __('Add rooms with full dimension and surface control') }}</flux:text>
                        </div>
                        <div class="flex gap-2">
                            <flux:button wire:click="$dispatch('open-template-picker')" variant="primary" icon="squares-2x2">
                                {{ __('+ From Template') }}
                            </flux:button>
                            <flux:button wire:click="addDetailedArea" icon="plus">
                                {{ __('+ Custom Area') }}
                            </flux:button>
                        </div>
                    </div>
                </div>

                {{-- AREAS LIST (New Design) --}}
                @if (count($areas) > 0)
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900">
                        <div class="p-4 border-b border-zinc-200 dark:border-zinc-700 flex items-center justify-between">
                            <flux:heading size="sm">{{ __('Areas') }}</flux:heading>
                            <span class="px-2.5 py-1 text-xs font-semibold rounded-full bg-green-100 dark:bg-green-900/50 text-green-700 dark:text-green-300">
                                {{ count($areas) }} {{ Str::plural('area', count($areas)) }}
                            </span>
                        </div>
                        <div class="p-4 space-y-3">
                            @foreach ($areas as $index => $area)
                                <div wire:key="area-{{ $index }}" x-data="{ expanded: false }" class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden group">
                                    {{-- Area Header with Quick Inputs --}}
                                    <div class="p-3 bg-zinc-50 dark:bg-zinc-800/50">
                                        <div class="flex items-start justify-between gap-3">
                                            <div class="flex-1">
                                                <div class="flex items-center gap-2 mb-2">
                                                    <span class="font-semibold text-zinc-900 dark:text-zinc-100">{{ $area['name'] }}</span>
                                                    <span class="px-2 py-0.5 text-xs font-medium rounded-full {{ $area['scope_type'] === 'interior' ? 'bg-blue-100 dark:bg-blue-900/50 text-blue-700 dark:text-blue-300' : 'bg-green-100 dark:bg-green-900/50 text-green-700 dark:text-green-300' }}">
                                                        {{ ucfirst($area['scope_type']) }}
                                                    </span>
                                                    @if (!empty($area['measurement_type']) && $area['measurement_type'] !== 'dimensions')
                                                        <span class="px-1.5 py-0.5 text-[10px] font-medium rounded bg-amber-100 dark:bg-amber-900/50 text-amber-700 dark:text-amber-300">
                                                            {{ strtoupper($area['measurement_type']) }}
                                                        </span>
                                                    @endif
                                                    @if (!empty($area['service_category_id']))
                                                        <span class="px-1.5 py-0.5 text-[10px] font-medium rounded bg-purple-100 dark:bg-purple-900/50 text-purple-700 dark:text-purple-300">
                                                            {{ __('Service') }}
                                                        </span>
                                                    @endif
                                                </div>

                                                {{-- Quick Dimension Inputs --}}
                                                <div class="flex flex-wrap items-center gap-3 text-sm">
                                                    <div class="flex items-center gap-1">
                                                        <span class="text-zinc-500 dark:text-zinc-400">L:</span>
                                                        <input
                                                            type="number"
                                                            value="{{ $area['length'] }}"
                                                            wire:change="updateAreaDimension({{ $index }}, 'length', $event.target.value)"
                                                            class="w-14 px-1.5 py-0.5 text-sm border border-zinc-300 dark:border-zinc-600 rounded bg-white dark:bg-zinc-700 text-center"
                                                            step="0.5"
                                                            min="0"
                                                        />
                                                    </div>
                                                    <span class="text-zinc-400">×</span>
                                                    <div class="flex items-center gap-1">
                                                        <span class="text-zinc-500 dark:text-zinc-400">W:</span>
                                                        <input
                                                            type="number"
                                                            value="{{ $area['width'] }}"
                                                            wire:change="updateAreaDimension({{ $index }}, 'width', $event.target.value)"
                                                            class="w-14 px-1.5 py-0.5 text-sm border border-zinc-300 dark:border-zinc-600 rounded bg-white dark:bg-zinc-700 text-center"
                                                            step="0.5"
                                                            min="1"
                                                        />
                                                    </div>
                                                    <span class="text-zinc-400">×</span>
                                                    <div class="flex items-center gap-1">
                                                        <span class="text-zinc-500 dark:text-zinc-400">H:</span>
                                                        <input
                                                            type="number"
                                                            value="{{ $area['height'] }}"
                                                            wire:change="updateAreaDimension({{ $index }}, 'height', $event.target.value)"
                                                            class="w-14 px-1.5 py-0.5 text-sm border border-zinc-300 dark:border-zinc-600 rounded bg-white dark:bg-zinc-700 text-center"
                                                            step="0.5"
                                                            min="1"
                                                        />
                                                    </div>
                                                    <span class="text-zinc-400 dark:text-zinc-500">→</span>
                                                    <span class="text-zinc-600 dark:text-zinc-400">{{ number_format($area['wall_sqft'], 0) }} sqft</span>
                                                </div>

                                                {{-- Surface and Addon Summary --}}
                                                <div class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">
                                                    <span>{{ __('Surfaces') }}: {{ count($area['surfaces'] ?? []) }}</span>
                                                    @if (count($area['addons'] ?? []) > 0)
                                                        <span class="mx-1">•</span>
                                                        <span>{{ __('Add-ons') }}: {{ count($area['addons']) }}</span>
                                                    @endif
                                                    @if (($area['labor_hours'] ?? 0) > 0)
                                                        <span class="mx-1">•</span>
                                                        <span class="text-blue-600 dark:text-blue-400">{{ number_format($area['labor_hours'], 1) }} hrs</span>
                                                    @endif
                                                </div>
                                            </div>

                                            {{-- Price and Actions --}}
                                            <div class="flex items-start gap-2">
                                                <div class="text-right">
                                                    <div class="text-lg font-bold text-blue-600 dark:text-blue-400">
                                                        ${{ number_format($area['price'] ?? 0, 2) }}
                                                    </div>
                                                    @if (($area['base_total'] ?? 0) > 0 && ($area['base_total'] ?? 0) != ($area['price'] ?? 0))
                                                        <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                                            {{ __('Base') }}: ${{ number_format($area['base_total'], 2) }}
                                                        </div>
                                                    @endif
                                                </div>
                                                <div class="flex items-center gap-1">
                                                    <button
                                                        @click="expanded = !expanded"
                                                        type="button"
                                                        class="p-1.5 rounded-lg hover:bg-zinc-200 dark:hover:bg-zinc-600 transition-colors"
                                                        title="Show details"
                                                    >
                                                        <svg x-show="!expanded" class="w-4 h-4 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                                        <svg x-show="expanded" class="w-4 h-4 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>
                                                    </button>
                                                    <flux:button
                                                        wire:click="editSelectedTemplate({{ $index }})"
                                                        variant="ghost"
                                                        size="sm"
                                                        icon="pencil"
                                                        class="opacity-60 group-hover:opacity-100"
                                                    />
                                                    <flux:button
                                                        wire:click="removeTemplate({{ $index }})"
                                                        variant="ghost"
                                                        size="sm"
                                                        icon="trash"
                                                        class="text-red-500 hover:text-red-700 dark:text-red-400 opacity-60 group-hover:opacity-100"
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    {{-- Expanded Details --}}
                                    <div x-show="expanded" x-collapse class="border-t border-zinc-200 dark:border-zinc-700">
                                        <div class="p-3 bg-blue-50/50 dark:bg-blue-950/20 text-xs space-y-3">
                                            {{-- Surfaces Breakdown --}}
                                            @if (count($area['surfaces'] ?? []) > 0)
                                                <div>
                                                    <div class="text-xs font-medium text-blue-700 dark:text-blue-400 mb-1">{{ __('Surfaces') }}</div>
                                                    <div class="space-y-1">
                                                        @foreach ($area['surfaces'] as $surface)
                                                            @if ($surface['is_default_enabled'] ?? true)
                                                                <div class="flex justify-between items-center py-0.5">
                                                                    <span class="text-zinc-700 dark:text-zinc-300">{{ $surface['name'] }}</span>
                                                                    <span class="text-zinc-500 dark:text-zinc-400">{{ $surface['default_coats'] ?? 2 }} {{ __('coats') }}</span>
                                                                </div>
                                                            @endif
                                                        @endforeach
                                                    </div>
                                                </div>
                                            @endif

                                            {{-- Addons Breakdown --}}
                                            @if (count($area['addons'] ?? []) > 0)
                                                <div>
                                                    <div class="text-xs font-medium text-blue-700 dark:text-blue-400 mb-1">{{ __('Add-ons') }}</div>
                                                    <div class="space-y-1">
                                                        @foreach ($area['addons'] as $addon)
                                                            <div class="flex justify-between items-center py-0.5">
                                                                <span class="text-zinc-700 dark:text-zinc-300">
                                                                    {{ $addon['name'] }}
                                                                    @if ($addon['quantity'] > 1)
                                                                        <span class="text-zinc-500">(x{{ $addon['quantity'] }})</span>
                                                                    @endif
                                                                </span>
                                                                <span class="text-zinc-500 dark:text-zinc-400">${{ number_format($addon['total_price'], 2) }}</span>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                </div>
                                            @endif

                                            {{-- COGS Summary --}}
                                            <div class="pt-2 border-t border-blue-200 dark:border-blue-800 space-y-2">
                                                <div class="text-xs font-medium text-blue-700 dark:text-blue-400">{{ __('COGS Breakdown') }}</div>
                                                <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                                                    <div>
                                                        <span class="text-zinc-500 dark:text-zinc-400">{{ __('Labor') }}:</span>
                                                        <span class="font-medium ml-1">${{ number_format($area['labor_cost'] ?? 0, 2) }}</span>
                                                    </div>
                                                    <div>
                                                        <span class="text-zinc-500 dark:text-zinc-400">{{ __('Cleanup') }}:</span>
                                                        <span class="font-medium ml-1">${{ number_format($area['cleanup_cost'] ?? 0, 2) }}</span>
                                                    </div>
                                                    <div>
                                                        <span class="text-zinc-500 dark:text-zinc-400">{{ __('Sundries') }}:</span>
                                                        <span class="font-medium ml-1">${{ number_format($area['sundries_cost'] ?? 0, 2) }}</span>
                                                    </div>
                                                    <div>
                                                        <span class="text-zinc-500 dark:text-zinc-400">{{ __('Materials') }}:</span>
                                                        <span class="font-medium ml-1">${{ number_format($area['material_cost'] ?? 0, 2) }}</span>
                                                    </div>
                                                    @if (($area['material_tax'] ?? 0) > 0)
                                                    <div>
                                                        <span class="text-zinc-500 dark:text-zinc-400">{{ __('Mat. Tax') }}:</span>
                                                        <span class="font-medium ml-1">${{ number_format($area['material_tax'] ?? 0, 2) }}</span>
                                                    </div>
                                                    @endif
                                                </div>
                                                @php
                                                    $marginPercent = \App\Models\CompanySetting::getInstance()->getMarginPercentForScope($area['scope_type'] ?? 'interior');
                                                @endphp
                                                <div class="flex justify-between items-center pt-1 border-t border-blue-100 dark:border-blue-900">
                                                    <span class="text-zinc-600 dark:text-zinc-400">OTD = COGS / {{ number_format($marginPercent/100, 2) }}</span>
                                                    <span class="font-bold text-blue-600 dark:text-blue-400">${{ number_format($area['price'] ?? 0, 2) }}</span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                {{-- Selected Templates --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900">
                    <div class="p-4 border-b border-zinc-200 dark:border-zinc-700 flex items-center justify-between">
                        <flux:heading size="sm">{{ __('Your Estimate') }}</flux:heading>
                        @if (count($selectedTemplates) > 0)
                            <span class="px-2.5 py-1 text-xs font-semibold rounded-full bg-blue-100 dark:bg-blue-900/50 text-blue-700 dark:text-blue-300">
                                {{ count($selectedTemplates) }} {{ Str::plural('item', count($selectedTemplates)) }}
                            </span>
                        @endif
                    </div>

                    <div class="p-4">
                        @if (count($selectedTemplates) === 0)
                            <div class="text-center py-8 text-zinc-500 dark:text-zinc-400">
                                <div class="w-16 h-16 mx-auto mb-3 rounded-full bg-zinc-100 dark:bg-zinc-800 flex items-center justify-center">
                                    <flux:icon name="inbox" class="w-8 h-8 text-zinc-400 dark:text-zinc-500" />
                                </div>
                                <p class="font-medium">{{ __('No items yet') }}</p>
                                <p class="text-sm mt-1">{{ __('Tap templates above to build your estimate') }}</p>
                            </div>
                        @else
                            <div class="space-y-3">
                                @foreach ($selectedTemplates as $index => $templateData)
                                    @if (!empty($templateData['is_fixed_price']))
                                        {{-- Fixed Price Item - Simplified Card --}}
                                        <div wire:key="selected-template-{{ $index }}" class="border border-emerald-200 dark:border-emerald-800/50 rounded-lg overflow-hidden group bg-emerald-50/50 dark:bg-emerald-950/20">
                                            <div class="flex items-center gap-3 p-3">
                                                <div class="flex-shrink-0 w-10 h-10 bg-emerald-100 dark:bg-emerald-900/50 rounded-lg flex items-center justify-center">
                                                    <flux:icon name="currency-dollar" class="w-5 h-5 text-emerald-600 dark:text-emerald-400" />
                                                </div>
                                                <div class="flex-1 min-w-0">
                                                    <div class="font-medium text-zinc-900 dark:text-zinc-100 truncate">
                                                        {{ $templateData['name'] }}
                                                    </div>
                                                    <div class="flex items-center gap-2 text-sm text-zinc-500 dark:text-zinc-400">
                                                        <span class="text-xs font-mono text-emerald-600 dark:text-emerald-400">{{ $templateData['code'] ?? 'FIXED' }}</span>
                                                        <span class="text-zinc-300 dark:text-zinc-600">·</span>
                                                        <span>${{ number_format($templateData['unit_price'] ?? 0, 2) }} {{ __('each') }}</span>
                                                    </div>
                                                </div>
                                                <div class="flex items-center gap-3">
                                                    @if ($useTierPricing)
                                                        <div class="flex gap-0.5">
                                                            @foreach (['good' => 'G', 'better' => 'B', 'best' => 'B+'] as $tier => $label)
                                                                @php $inTier = ($templateData['package_tiers'] ?? null) === null || in_array($tier, $templateData['package_tiers'] ?? []); @endphp
                                                                <button wire:click="toggleAreaTier({{ $index }}, '{{ $tier }}')" type="button"
                                                                    class="{{ $inTier ? 'bg-blue-100 text-blue-700 border-blue-300 dark:bg-blue-900/50 dark:text-blue-300' : 'bg-zinc-100 text-zinc-400 border-zinc-200 dark:bg-zinc-800 dark:text-zinc-500' }} w-7 h-6 text-[10px] font-bold rounded border transition-colors flex items-center justify-center">
                                                                    {{ $label }}
                                                                </button>
                                                            @endforeach
                                                        </div>
                                                    @endif
                                                    {{-- Quantity Spinner --}}
                                                    <div class="flex items-center gap-1">
                                                        <button
                                                            wire:click="updateFixedPriceQuantity({{ $index }}, {{ max(1, ($templateData['quantity'] ?? 1) - 1) }})"
                                                            type="button"
                                                            class="p-1 rounded bg-zinc-200 dark:bg-zinc-700 hover:bg-zinc-300 dark:hover:bg-zinc-600 transition-colors"
                                                            {{ ($templateData['quantity'] ?? 1) <= 1 ? 'disabled' : '' }}
                                                        >
                                                            <svg class="w-4 h-4 text-zinc-600 dark:text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4"/></svg>
                                                        </button>
                                                        <span class="w-8 text-center font-semibold text-zinc-900 dark:text-zinc-100">{{ $templateData['quantity'] ?? 1 }}</span>
                                                        <button
                                                            wire:click="updateFixedPriceQuantity({{ $index }}, {{ ($templateData['quantity'] ?? 1) + 1 }})"
                                                            type="button"
                                                            class="p-1 rounded bg-zinc-200 dark:bg-zinc-700 hover:bg-zinc-300 dark:hover:bg-zinc-600 transition-colors"
                                                        >
                                                            <svg class="w-4 h-4 text-zinc-600 dark:text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                                        </button>
                                                    </div>
                                                    {{-- Total Price --}}
                                                    <div class="text-right min-w-[80px]">
                                                        <div class="font-bold text-lg text-emerald-600 dark:text-emerald-400">
                                                            ${{ number_format($templateData['price'] ?? 0, 2) }}
                                                        </div>
                                                    </div>
                                                    {{-- Remove Button (no edit button for fixed price items) --}}
                                                    <flux:button
                                                        wire:click="removeTemplate({{ $index }})"
                                                        variant="ghost"
                                                        size="sm"
                                                        icon="trash"
                                                        class="text-red-500 hover:text-red-700 dark:text-red-400 opacity-60 group-hover:opacity-100"
                                                    />
                                                </div>
                                            </div>
                                        </div>
                                    @else
                                    {{-- Regular Template/Area Item - Full Card --}}
                                    <div wire:key="selected-template-{{ $index }}" x-data="{ showBreakdown: false }" class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden group">
                                        {{-- Main row --}}
                                        <div class="flex items-center gap-3 p-3 bg-zinc-50 dark:bg-zinc-800/50">
                                            <div class="flex-shrink-0 w-10 h-10 bg-white dark:bg-zinc-700 rounded-lg flex items-center justify-center shadow-sm">
                                                <flux:icon name="check-circle" class="w-5 h-5 text-green-500" />
                                            </div>
                                            <div class="flex-1 min-w-0">
                                                <div class="flex items-center gap-2 font-medium text-zinc-900 dark:text-zinc-100 truncate">
                                                    {{ $templateData['name'] }}
                                                    @if (!empty($templateData['area_data']['measurement_type']) && $templateData['area_data']['measurement_type'] !== 'dimensions')
                                                        <span class="px-1.5 py-0.5 text-[10px] font-medium rounded bg-amber-100 dark:bg-amber-900/50 text-amber-700 dark:text-amber-300">
                                                            {{ strtoupper($templateData['area_data']['measurement_type']) }}
                                                        </span>
                                                    @endif
                                                    @if (!empty($templateData['service_category_id']))
                                                        <span class="px-1.5 py-0.5 text-[10px] font-medium rounded bg-purple-100 dark:bg-purple-900/50 text-purple-700 dark:text-purple-300">
                                                            {{ __('Service') }}
                                                        </span>
                                                    @endif
                                                    @if ($useTierPricing)
                                                        <div class="flex gap-0.5 ml-1">
                                                            @foreach (['good' => 'G', 'better' => 'B', 'best' => 'B+'] as $tier => $label)
                                                                @php $inTier = ($templateData['area_data']['package_tiers'] ?? null) === null || in_array($tier, $templateData['area_data']['package_tiers'] ?? []); @endphp
                                                                <button wire:click="toggleAreaTier({{ $index }}, '{{ $tier }}')" type="button"
                                                                    class="{{ $inTier ? 'bg-blue-100 text-blue-700 border-blue-300 dark:bg-blue-900/50 dark:text-blue-300' : 'bg-zinc-100 text-zinc-400 border-zinc-200 dark:bg-zinc-800 dark:text-zinc-500' }} w-7 h-6 text-[10px] font-bold rounded border transition-colors flex items-center justify-center">
                                                                    {{ $label }}
                                                                </button>
                                                            @endforeach
                                                        </div>
                                                    @endif
                                                </div>
                                                <div class="flex flex-wrap items-center gap-x-2 text-sm text-zinc-500 dark:text-zinc-400">
                                                    {{-- Dimension inputs if area_data exists --}}
                                                    @if (!empty($templateData['area_data']) && isset($templateData['area_data']['width']))
                                                        @php
                                                            $areaData = $templateData['area_data'];
                                                            $hasLength = isset($areaData['length']) && $areaData['length'] > 0;
                                                        @endphp
                                                        @if ($hasLength)
                                                            <span class="text-xs text-zinc-400">L:</span>
                                                            <input
                                                                type="number"
                                                                value="{{ $areaData['length'] }}"
                                                                wire:change="updateAreaDimension({{ $index }}, 'length', $event.target.value)"
                                                                class="w-12 text-sm bg-white dark:bg-zinc-700 border border-zinc-300 dark:border-zinc-600 rounded px-1 py-0.5 text-center"
                                                                min="1"
                                                                step="1"
                                                            />
                                                            <span class="text-xs text-zinc-400">×</span>
                                                        @endif
                                                        <span class="text-xs text-zinc-400">W:</span>
                                                        <input
                                                            type="number"
                                                            value="{{ $areaData['width'] }}"
                                                            wire:change="updateAreaDimension({{ $index }}, 'width', $event.target.value)"
                                                            class="w-12 text-sm bg-white dark:bg-zinc-700 border border-zinc-300 dark:border-zinc-600 rounded px-1 py-0.5 text-center"
                                                            min="1"
                                                            step="1"
                                                        />
                                                        <span class="text-xs text-zinc-400">× H:</span>
                                                        <input
                                                            type="number"
                                                            value="{{ $areaData['height'] ?? 0 }}"
                                                            wire:change="updateAreaDimension({{ $index }}, 'height', $event.target.value)"
                                                            class="w-12 text-sm bg-white dark:bg-zinc-700 border border-zinc-300 dark:border-zinc-600 rounded px-1 py-0.5 text-center"
                                                            min="1"
                                                            step="1"
                                                        />
                                                        <span class="text-zinc-300 dark:text-zinc-600">→</span>
                                                        <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ number_format($areaData['wall_sqft'] ?? 0, 0) }} sqft</span>
                                                    {{-- Size dropdown if template has sizes --}}
                                                    @elseif (!empty($templateData['sizes']))
                                                        <select
                                                            wire:change="changeSize({{ $index }}, $event.target.value)"
                                                            class="text-sm bg-white dark:bg-zinc-700 border border-zinc-300 dark:border-zinc-600 rounded-md py-0.5 px-2 font-medium text-blue-600 dark:text-blue-400 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                                        >
                                                            @foreach ($templateData['sizes'] as $sizeKey => $sizeData)
                                                                <option value="{{ $sizeKey }}" {{ ($templateData['selected_size'] ?? '') === $sizeKey ? 'selected' : '' }}>
                                                                    {{ $sizeData['label'] ?? ucfirst($sizeKey) }}
                                                                </option>
                                                            @endforeach
                                                        </select>
                                                    @else
                                                        <span>{{ number_format($templateData['quantity'], 0) }} sqft</span>
                                                    @endif
                                                    @if (($templateData['coats'] ?? 0) > 1)
                                                        <span class="text-zinc-300 dark:text-zinc-600">·</span>
                                                        <span>{{ $templateData['coats'] }} coats</span>
                                                    @endif
                                                    @if (!empty($templateData['labor_hours']) && $templateData['labor_hours'] > 0)
                                                        <span class="text-zinc-300 dark:text-zinc-600">·</span>
                                                        <span class="text-blue-600 dark:text-blue-400">{{ number_format($templateData['labor_hours'], 1) }} hrs</span>
                                                    @endif
                                                </div>
                                            </div>
                                            <div class="flex-shrink-0 text-right">
                                                <div class="font-bold text-lg text-blue-600 dark:text-blue-400">
                                                    ${{ number_format($templateData['price'], 2) }}
                                                </div>
                                                @if (!empty($templateData['base_total']) && $templateData['base_total'] > 0 && $templateData['base_total'] != $templateData['price'])
                                                    <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                                        {{ __('Base') }}: ${{ number_format($templateData['base_total'], 2) }}
                                                    </div>
                                                @endif
                                            </div>
                                            <div class="flex-shrink-0 flex items-center gap-1">
                                                @if (!empty($templateData['labor_hours']) && $templateData['labor_hours'] > 0)
                                                    <button
                                                        @click="showBreakdown = !showBreakdown"
                                                        type="button"
                                                        class="p-1.5 rounded-lg hover:bg-zinc-200 dark:hover:bg-zinc-600 transition-colors"
                                                        title="Show PBP breakdown"
                                                    >
                                                        <svg x-show="!showBreakdown" class="w-4 h-4 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                                        <svg x-show="showBreakdown" class="w-4 h-4 text-zinc-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 15l7-7 7 7"/></svg>
                                                    </button>
                                                @endif
                                                <flux:button
                                                    wire:click="editSelectedTemplate({{ $index }})"
                                                    variant="ghost"
                                                    size="sm"
                                                    icon="pencil"
                                                    class="opacity-60 group-hover:opacity-100"
                                                />
                                                <flux:button
                                                    wire:click="removeTemplate({{ $index }})"
                                                    variant="ghost"
                                                    size="sm"
                                                    icon="trash"
                                                    class="text-red-500 hover:text-red-700 dark:text-red-400 opacity-60 group-hover:opacity-100"
                                                />
                                            </div>
                                        </div>

                                        {{-- PBP Breakdown (expandable) --}}
                                        @if (!empty($templateData['labor_hours']) && $templateData['labor_hours'] > 0)
                                            <div x-show="showBreakdown" x-collapse class="border-t border-zinc-200 dark:border-zinc-700">
                                                <div class="p-3 bg-blue-50/50 dark:bg-blue-950/20 text-xs">
                                                    {{-- Per-Surface Breakdown (check both surfaces and area_data.surfaces) --}}
                                                    @php
                                                        $surfaces = $templateData['surfaces'] ?? $templateData['area_data']['surfaces'] ?? [];
                                                    @endphp
                                                    @if (!empty($surfaces))
                                                        <div class="text-xs font-medium text-blue-700 dark:text-blue-400 mb-2">{{ __('Surfaces Breakdown') }}</div>
                                                        <div class="space-y-1 mb-3">
                                                            @foreach ($surfaces as $surfaceKey => $surface)
                                                                <div class="flex justify-between items-center py-1 {{ !$loop->last ? 'border-b border-blue-100 dark:border-blue-900' : '' }}">
                                                                    <div class="flex items-center gap-2">
                                                                        <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ $surface['name'] ?? 'Surface' }}</span>
                                                                        <span class="text-zinc-500 dark:text-zinc-400">
                                                                            @if (($surface['unit'] ?? '') === 'fixed' || ($surface['quantity_source'] ?? '') === 'custom')
                                                                                ({{ $surface['quantity_override'] ?? $surface['quantity'] ?? 'fixed' }})
                                                                            @elseif (!empty($surface['quantity']))
                                                                                {{ number_format($surface['quantity'], 0) }} {{ $surface['unit'] ?? 'sqft' }}
                                                                            @elseif (!empty($surface['quantity_source']))
                                                                                {{ $surface['quantity_source'] }}
                                                                            @endif
                                                                        </span>
                                                                    </div>
                                                                    <div class="text-right text-zinc-600 dark:text-zinc-400">
                                                                        @if (!empty($surface['labor_hours']) && $surface['labor_hours'] > 0)
                                                                            {{ number_format($surface['labor_hours'], 1) }} hrs
                                                                        @endif
                                                                        @if (!empty($surface['material_cost']) && $surface['material_cost'] > 0 && empty($surface['labor_hours']))
                                                                            ${{ number_format($surface['material_cost'], 2) }}
                                                                        @endif
                                                                    </div>
                                                                </div>
                                                            @endforeach
                                                        </div>
                                                    @endif

                                                    <div class="text-xs font-medium text-blue-700 dark:text-blue-400 mb-2">{{ __('COGS Breakdown') }}</div>
                                                    @php
                                                        $isFixedTemplate = ($templateData['pricing_method'] ?? '') === 'fixed';
                                                        $hasCleanup = ($templateData['cleanup_hours'] ?? 0) > 0;
                                                        $hasSundries = ($templateData['sundries_cost'] ?? 0) > 0;
                                                    @endphp
                                                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
                                                        <div>
                                                            <div class="text-zinc-500 dark:text-zinc-400">{{ __('Labor') }}</div>
                                                            <div class="font-medium text-zinc-900 dark:text-zinc-100">
                                                                {{ number_format($templateData['labor_hours'], 1) }} {{ __('hrs') }} × ${{ number_format($this->getEffectiveRateForScope($templateData['scope_type'] ?? 'interior'), 2) }}
                                                            </div>
                                                            <div class="text-zinc-600 dark:text-zinc-400">= ${{ number_format($templateData['labor_cost'] ?? 0, 2) }}</div>
                                                        </div>
                                                        @if ($hasCleanup)
                                                        <div>
                                                            <div class="text-zinc-500 dark:text-zinc-400">{{ __('Cleanup') }}</div>
                                                            <div class="font-medium text-zinc-900 dark:text-zinc-100">
                                                                {{ number_format($templateData['cleanup_hours'] ?? 0, 2) }} hrs
                                                            </div>
                                                            <div class="text-zinc-600 dark:text-zinc-400">= ${{ number_format($templateData['cleanup_cost'] ?? 0, 2) }}</div>
                                                        </div>
                                                        @endif
                                                        @if ($hasSundries)
                                                        <div>
                                                            <div class="text-zinc-500 dark:text-zinc-400">{{ __('Sundries') }}</div>
                                                            <div class="font-medium text-zinc-900 dark:text-zinc-100">
                                                                ${{ number_format($templateData['sundries_cost'] ?? 0, 2) }}
                                                            </div>
                                                        </div>
                                                        @endif
                                                        @if (($templateData['material_cost'] ?? 0) > 0)
                                                        <div>
                                                            <div class="text-zinc-500 dark:text-zinc-400">{{ __('Materials') }}</div>
                                                            <div class="font-medium text-zinc-900 dark:text-zinc-100">
                                                                ${{ number_format($templateData['material_cost'] ?? 0, 2) }}
                                                            </div>
                                                        </div>
                                                        @endif
                                                        @if (($templateData['material_tax'] ?? 0) > 0)
                                                        <div>
                                                            <div class="text-zinc-500 dark:text-zinc-400">{{ __('Material Tax') }} ({{ number_format(\App\Models\CompanySetting::getInstance()->getMaterialTaxRate(), 1) }}%)</div>
                                                            <div class="font-medium text-zinc-900 dark:text-zinc-100">
                                                                ${{ number_format($templateData['material_tax'] ?? 0, 2) }}
                                                            </div>
                                                        </div>
                                                        @endif
                                                        @if (($templateData['labor_tax'] ?? 0) > 0)
                                                        <div>
                                                            <div class="text-zinc-500 dark:text-zinc-400">{{ __('Labor Tax') }}</div>
                                                            <div class="font-medium text-zinc-900 dark:text-zinc-100">
                                                                ${{ number_format($templateData['labor_tax'] ?? 0, 2) }}
                                                            </div>
                                                        </div>
                                                        @endif
                                                    </div>
                                                    <div class="mt-3 pt-2 border-t border-blue-200 dark:border-blue-800 space-y-2">
                                                        <div class="flex justify-between items-center">
                                                            <div class="text-zinc-600 dark:text-zinc-400 font-medium">
                                                                {{ __('COGS (Cost of Goods Sold)') }}
                                                            </div>
                                                            <div class="font-medium text-zinc-900 dark:text-zinc-100">
                                                                ${{ number_format($templateData['base_total'] ?? 0, 2) }}
                                                            </div>
                                                        </div>
                                                        @php
                                                            // Use per-template margin if set, otherwise use scope margin
                                                            $marginPercent = $templateData['margin_percent']
                                                                ?? \App\Models\CompanySetting::getInstance()->getMarginPercentForScope($templateData['scope_type'] ?? 'interior');
                                                            $profit = $templateData['profit'] ?? (($templateData['with_margins'] ?? 0) - ($templateData['base_total'] ?? 0));
                                                        @endphp
                                                        <div class="flex justify-between items-center">
                                                            <div class="text-zinc-600 dark:text-zinc-400">
                                                                <span class="font-medium">OTD</span> = COGS / {{ number_format($marginPercent, 0) }}%
                                                                @if (!empty($templateData['margin_percent']))
                                                                    <span class="text-blue-500 text-xs">(custom)</span>
                                                                @endif
                                                            </div>
                                                            <div class="font-bold text-blue-600 dark:text-blue-400 text-sm">
                                                                ${{ number_format($templateData['base_total'] ?? 0, 2) }} / {{ number_format($marginPercent/100, 2) }} = ${{ number_format($templateData['with_margins'] ?? $templateData['price'], 2) }}
                                                            </div>
                                                        </div>
                                                        @if ($profit > 0)
                                                            <div class="flex justify-between items-center text-sm">
                                                                <div class="text-green-600 dark:text-green-400">
                                                                    {{ __('Profit') }} ({{ number_format($marginPercent, 0) }}% {{ __('of sale') }})
                                                                </div>
                                                                <div class="font-medium text-green-600 dark:text-green-400">
                                                                    ${{ number_format($profit, 2) }}
                                                                </div>
                                                            </div>
                                                        @endif
                                                    </div>
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                    @endif {{-- End of is_fixed_price check --}}
                                @endforeach
                            </div>
                        @endif

                        @error('selectedTemplates')
                            <p class="mt-3 text-sm text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>
                </div>

                {{-- Notes - Collapsible on Mobile --}}
                <details class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900 group">
                    <summary class="p-4 cursor-pointer list-none flex items-center justify-between">
                        <flux:heading size="sm">{{ __('Notes (Optional)') }}</flux:heading>
                        <flux:icon name="chevron-down" class="w-5 h-5 text-zinc-400 transition-transform group-open:rotate-180" />
                    </summary>
                    <div class="px-4 pb-4">
                        <flux:textarea wire:model="notes" rows="3" :placeholder="__('Additional notes for the customer...')" />
                        <flux:error name="notes" />
                    </div>
                </details>

                {{-- Photos - Available when editing an estimate with a property --}}
                @if ($estimate && $deal?->property)
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 bg-white dark:bg-zinc-900">
                        <livewire:shared.photo-gallery
                            :property="$deal->property"
                            :estimate="$estimate"
                            context="estimate"
                            :compact="true"
                            :key="'builder-photos-'.$estimate->id"
                        />
                    </div>
                @endif
            </div>

            {{-- Desktop Sidebar --}}
            <div class="hidden lg:block lg:col-span-4">
                <div class="sticky top-6 space-y-4">
                    {{-- Pricing Options --}}
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg p-4 bg-white dark:bg-zinc-900">
                        <flux:heading size="sm" class="mb-4">{{ __('Pricing') }}</flux:heading>
                        <div class="space-y-3">
                            <flux:field>
                                <flux:label>{{ __('Tax Rate') }}</flux:label>
                                <div class="flex items-center gap-2">
                                    <flux:input wire:model.blur="taxRate" type="number" step="0.01" min="0" max="100" placeholder="0" class="flex-1" />
                                    <span class="text-zinc-500 dark:text-zinc-400 text-sm font-medium">%</span>
                                </div>
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Discount') }}</flux:label>
                                <div class="flex items-center gap-2">
                                    <span class="text-zinc-500 dark:text-zinc-400 text-sm font-medium">$</span>
                                    <flux:input wire:model.blur="discountAmount" type="number" step="0.01" min="0" placeholder="0" class="flex-1" />
                                </div>
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Margin Discount') }}</flux:label>
                                <div class="flex items-center gap-2">
                                    <flux:input wire:model.blur="marginDiscountPercent" type="number" step="0.5" min="0" max="{{ $this->maxAllowableDiscount }}" placeholder="0" class="flex-1" />
                                    <span class="text-zinc-500 dark:text-zinc-400 text-sm font-medium">%</span>
                                </div>
                                @if ($marginDiscountPercent > 0)
                                    <div class="mt-1 space-y-1">
                                        <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                            {{ __('Effective margin') }}: {{ number_format(\App\Models\CompanySetting::getInstance()->getMarginPercentForScope($projectScope) + $marginDiscountPercent, 1) }}%
                                            <span class="text-zinc-400 dark:text-zinc-500">({{ __('was') }} {{ number_format(\App\Models\CompanySetting::getInstance()->getMarginPercentForScope($projectScope), 1) }}%)</span>
                                        </p>
                                        @if ($this->estimatedNetProfitPercent < 12 && $this->estimatedNetProfitPercent >= 10)
                                            <p class="text-xs text-amber-600 dark:text-amber-400">
                                                {{ __('Net profit: :percent% — approaching floor', ['percent' => number_format($this->estimatedNetProfitPercent, 1)]) }}
                                            </p>
                                        @elseif ($this->estimatedNetProfitPercent <= 10)
                                            <p class="text-xs text-red-600 dark:text-red-400 font-medium">
                                                {{ __('Net profit: :percent% — at minimum floor', ['percent' => number_format($this->estimatedNetProfitPercent, 1)]) }}
                                            </p>
                                        @else
                                            <p class="text-xs text-green-600 dark:text-green-400">
                                                {{ __('Est. net profit: :percent%', ['percent' => number_format($this->estimatedNetProfitPercent, 1)]) }}
                                            </p>
                                        @endif
                                    </div>
                                @endif
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Hourly Rate Override') }}</flux:label>
                                <div class="flex items-center gap-2">
                                    <span class="text-zinc-500 dark:text-zinc-400 text-sm font-medium">$</span>
                                    <flux:input wire:model.blur="hourlyRateOverride" type="number" step="0.50" min="10" max="100" placeholder="{{ number_format(\App\Models\CompanySetting::getInstance()->getHourlyWageForScope($projectScope), 2) }}" class="flex-1" />
                                    <span class="text-zinc-500 dark:text-zinc-400 text-sm font-medium">/hr</span>
                                </div>
                                @if ($hourlyRateOverride !== null && $hourlyRateOverride > 0)
                                    @php
                                        $defaultWage = \App\Models\CompanySetting::getInstance()->getHourlyWageForScope($projectScope);
                                        $burdenMultiplier = (float) \App\Models\CompanySetting::getInstance()->payroll_burden_multiplier;
                                        $overrideEffective = $hourlyRateOverride * $burdenMultiplier;
                                        $defaultEffective = $defaultWage * $burdenMultiplier;
                                    @endphp
                                    <div class="mt-1 space-y-1">
                                        <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                            {{ __('Effective rate') }}: ${{ number_format($overrideEffective, 2) }}/hr
                                            <span class="text-zinc-400 dark:text-zinc-500">({{ __('default') }} ${{ number_format($defaultEffective, 2) }}/hr)</span>
                                        </p>
                                        @if ($hourlyRateOverride < $defaultWage)
                                            <p class="text-xs text-amber-600 dark:text-amber-400">
                                                {{ __('Below default rate — competitive bid') }}
                                            </p>
                                        @elseif ($hourlyRateOverride > $defaultWage)
                                            <p class="text-xs text-blue-600 dark:text-blue-400">
                                                {{ __('Above default rate — premium bid') }}
                                            </p>
                                        @endif
                                    </div>
                                @endif
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Deposit Required') }}</flux:label>
                                <div class="flex items-center gap-2">
                                    <flux:input wire:model.blur="depositPercent" type="number" step="1" min="0" max="100" placeholder="30" class="flex-1" />
                                    <span class="text-zinc-500 dark:text-zinc-400 text-sm font-medium">%</span>
                                </div>
                            </flux:field>
                        </div>
                    </div>

                    {{-- Summary Card --}}
                    <div class="border-2 border-blue-200 dark:border-blue-800 rounded-lg p-4 bg-gradient-to-br from-blue-50 to-white dark:from-blue-950/30 dark:to-zinc-900">
                        <flux:heading size="sm" class="mb-4">{{ __('Summary') }}</flux:heading>
                        <dl class="space-y-2 text-sm">
                            <div class="flex justify-between">
                                <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Subtotal') }} ({{ count($areas) > 0 ? count($areas) . ' ' . __('areas') : count($selectedTemplates) . ' ' . __('items') }})</dt>
                                <dd class="font-medium">${{ number_format($this->subtotal, 2) }}</dd>
                            </div>
                            @if ($this->totalLaborHours > 0)
                                <div class="flex justify-between">
                                    <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Total Hours') }}</dt>
                                    <dd class="font-medium text-blue-600 dark:text-blue-400">{{ number_format($this->totalLaborHours, 1) }} hrs</dd>
                                </div>
                            @endif
                            @if ($taxRate > 0)
                                <div class="flex justify-between">
                                    <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Tax') }} ({{ number_format($taxRate ?? 0, 1) }}%)</dt>
                                    <dd>${{ number_format($this->taxAmount, 2) }}</dd>
                                </div>
                            @endif
                            @if ($discountAmount > 0)
                                <div class="flex justify-between">
                                    <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Discount') }}</dt>
                                    <dd class="text-red-600 dark:text-red-400">-${{ number_format($discountAmount, 2) }}</dd>
                                </div>
                            @endif
                            <div class="flex justify-between pt-3 border-t border-blue-200 dark:border-blue-800">
                                <dt class="text-lg font-bold">{{ __('Total') }}</dt>
                                <dd class="text-2xl font-bold text-blue-600 dark:text-blue-400">${{ number_format($this->total, 2) }}</dd>
                            </div>
                            <div class="pt-3 border-t border-blue-100 dark:border-blue-900 space-y-1">
                                <div class="flex justify-between text-zinc-600 dark:text-zinc-400">
                                    <dt>{{ __('Deposit') }} ({{ number_format($depositPercent ?? 0, 0) }}%)</dt>
                                    <dd class="font-medium text-zinc-900 dark:text-zinc-100">${{ number_format($this->depositAmount, 2) }}</dd>
                                </div>
                                <div class="flex justify-between text-zinc-600 dark:text-zinc-400">
                                    <dt>{{ __('Balance Due') }}</dt>
                                    <dd class="font-medium text-zinc-900 dark:text-zinc-100">${{ number_format($this->balanceDue, 2) }}</dd>
                                </div>
                            </div>
                        </dl>
                    </div>

                    {{-- AI Build Button (child component) --}}
                    @if (config('ai-build.enabled') && $isEditing)
                        <livewire:estimates.ai-builder :estimate="$estimate" />
                    @endif

                    {{-- Actions --}}
                    <div class="space-y-2">
                        <flux:button wire:click="saveAndSend" variant="primary" wire:loading.attr="disabled" class="w-full">
                            <span wire:loading.remove wire:target="saveAndSend">{{ __('Save & Send to Customer') }}</span>
                            <span wire:loading wire:target="saveAndSend">{{ __('Sending...') }}</span>
                        </flux:button>
                        <flux:button wire:click="saveAsDraft" wire:loading.attr="disabled" class="w-full">
                            <span wire:loading.remove wire:target="saveAsDraft">{{ __('Save as Draft') }}</span>
                            <span wire:loading wire:target="saveAsDraft">{{ __('Saving...') }}</span>
                        </flux:button>
                        @if ($isEditing)
                            <flux:button variant="ghost" :href="route('estimates.show', $estimate)" wire:navigate class="w-full">
                                {{ __('Cancel') }}
                            </flux:button>
                        @else
                            <flux:button variant="ghost" :href="route('deals.show', $deal)" wire:navigate class="w-full">
                                {{ __('Cancel') }}
                            </flux:button>
                        @endif
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Template Picker (Child Component) --}}
    <livewire:estimates.template-picker :project-scope="$projectScope" />


    {{-- Area Builder Component (Flyout) --}}
    <livewire:estimates.area-builder wire:key="area-builder" wire:ref="area-builder" />

    {{-- AI Chat Panel (Sliding Right Panel) — Multi-Thread --}}
    @if ($canUseAiChat)
        @php
            $activeThread = $aiThreads->firstWhere('id', $activeThreadId);
            $activeMessages = $activeThread?->messages ?? collect();
            $activeIsProcessing = $activeThread?->is_processing ?? false;
        @endphp
        <div
            x-data="{
                isRecording: false,
                mediaRecorder: null,
                audioChunks: [],
                renamingThreadId: null,
                renameValue: '',
                async startRecording() {
                    try {
                        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
                        this.mediaRecorder = new MediaRecorder(stream);
                        this.audioChunks = [];
                        this.mediaRecorder.ondataavailable = (e) => this.audioChunks.push(e.data);
                        this.mediaRecorder.onstop = () => {
                            const blob = new Blob(this.audioChunks, { type: 'audio/webm' });
                            const file = new File([blob], 'voice-recording.webm', { type: 'audio/webm' });
                            @this.upload('voiceRecording', file, () => {
                                @this.call('processVoiceRecording');
                            });
                            stream.getTracks().forEach(t => t.stop());
                        };
                        this.mediaRecorder.start();
                        this.isRecording = true;
                    } catch (err) {
                        console.error('Microphone access denied:', err);
                    }
                },
                stopRecording() {
                    if (this.mediaRecorder && this.isRecording) {
                        this.mediaRecorder.stop();
                        this.isRecording = false;
                    }
                },
                scrollToBottom() {
                    this.$nextTick(() => {
                        const el = this.$refs.chatMessages;
                        if (el) el.scrollTop = el.scrollHeight;
                    });
                },
                startRename(threadId, currentName) {
                    this.renamingThreadId = threadId;
                    this.renameValue = currentName || '';
                    this.$nextTick(() => this.$refs.renameInput?.focus());
                },
                finishRename() {
                    if (this.renamingThreadId && this.renameValue.trim()) {
                        $wire.renameThread(this.renamingThreadId, this.renameValue.trim());
                    }
                    this.renamingThreadId = null;
                    this.renameValue = '';
                }
            }"
            x-effect="scrollToBottom()"
            x-init="
                setInterval(() => {
                    const threads = $wire.aiThreads || [];
                    const anyProcessing = threads.some(t => t.is_processing);
                    if (anyProcessing) {
                        $wire.pollAiResponse();
                    }
                }, 3000);
            "
            class="fixed inset-y-0 right-0 z-50 w-full sm:w-[28rem] transform transition-transform duration-300 ease-in-out"
            :class="$wire.showAiChat ? 'translate-x-0' : 'translate-x-full'"
            x-show="$wire.showAiChat"
            x-cloak
        >
            <div class="h-full flex flex-col bg-white dark:bg-zinc-900 border-l border-zinc-200 dark:border-zinc-700 shadow-2xl">
                {{-- Header --}}
                <div class="flex items-center justify-between px-4 py-3 border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800">
                    <div class="flex items-center gap-2">
                        <flux:icon name="sparkles" class="w-5 h-5 text-purple-500" />
                        <flux:heading size="sm">{{ __('AI Estimate Builder') }}</flux:heading>
                    </div>
                    <div class="flex items-center gap-1">
                        @if ($activeThread)
                            <flux:button wire:click="clearAiThread" variant="ghost" size="xs" icon="trash" title="{{ __('Clear thread') }}" />
                            <flux:button wire:click="deleteThread({{ $activeThreadId }})" variant="ghost" size="xs" icon="x-circle" title="{{ __('Delete thread') }}" />
                        @endif
                        <flux:button wire:click="toggleAiChat" variant="ghost" size="xs" icon="x-mark" />
                    </div>
                </div>

                {{-- Thread Tabs --}}
                <div class="flex items-center border-b border-zinc-200 dark:border-zinc-700 bg-zinc-50/50 dark:bg-zinc-800/50 overflow-x-auto">
                    @foreach ($aiThreads as $thread)
                        <button
                            wire:key="thread-tab-{{ $thread->id }}"
                            wire:click="switchThread({{ $thread->id }})"
                            @dblclick="startRename({{ $thread->id }}, '{{ addslashes($thread->name) }}')"
                            class="relative flex items-center gap-1.5 px-3 py-2 text-xs font-medium whitespace-nowrap border-b-2 transition-colors shrink-0
                                {{ $thread->id === $activeThreadId
                                    ? 'border-purple-500 text-purple-700 dark:text-purple-300 bg-white dark:bg-zinc-900'
                                    : 'border-transparent text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-300 hover:bg-zinc-100 dark:hover:bg-zinc-800' }}"
                        >
                            <template x-if="renamingThreadId === {{ $thread->id }}">
                                <input
                                    x-ref="renameInput"
                                    x-model="renameValue"
                                    @blur="finishRename()"
                                    @keydown.enter.prevent="finishRename()"
                                    @keydown.escape.prevent="renamingThreadId = null"
                                    class="w-20 bg-transparent border-b border-purple-400 text-xs outline-none"
                                    @click.stop
                                />
                            </template>
                            <template x-if="renamingThreadId !== {{ $thread->id }}">
                                <span class="max-w-[8rem] truncate">{{ $thread->name }}</span>
                            </template>
                            @if ($thread->is_processing)
                                <svg class="animate-spin h-3 w-3 text-purple-500 shrink-0" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                </svg>
                            @endif
                        </button>
                    @endforeach
                    {{-- New Thread Button --}}
                    <button
                        wire:click="createAiThread"
                        class="flex items-center gap-1 px-3 py-2 text-xs text-zinc-400 hover:text-purple-600 dark:hover:text-purple-400 transition-colors shrink-0"
                        title="{{ __('New thread') }}"
                    >
                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    </button>
                </div>

                {{-- Messages --}}
                <div x-ref="chatMessages" class="flex-1 overflow-y-auto p-4 space-y-4">
                    @if ($activeMessages->isEmpty() && ! $activeIsProcessing)
                        <div class="text-center text-zinc-400 dark:text-zinc-500 mt-8">
                            <flux:icon name="sparkles" class="w-12 h-12 mx-auto mb-3 text-purple-300" />
                            <p class="text-sm font-medium">{{ __('AI Estimate Builder') }}</p>
                            <p class="text-xs mt-1">{{ __('Describe the rooms and I\'ll build your estimate.') }}</p>
                            <p class="text-xs mt-3 text-zinc-400">{{ __('Try: "Add a medium living room, large master bedroom, and small bathroom"') }}</p>
                            @if ($aiThreads->isEmpty())
                                <p class="text-xs mt-2 text-zinc-400">{{ __('A thread will be created automatically when you send your first message.') }}</p>
                            @endif
                        </div>
                    @endif

                    @foreach ($activeMessages as $msg)
                        <div wire:key="msg-{{ $msg->id }}" class="flex {{ $msg->role === 'user' ? 'justify-end' : 'justify-start' }}">
                            <div class="max-w-[85%] rounded-xl px-4 py-2.5 text-sm
                                {{ $msg->role === 'user'
                                    ? 'bg-blue-600 text-white'
                                    : ($msg->role === 'error'
                                        ? 'bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 text-red-600 dark:text-red-400'
                                        : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100') }}">
                                {!! nl2br(e($msg->content)) !!}
                            </div>
                        </div>
                    @endforeach

                    @if ($activeIsProcessing)
                        <div class="flex justify-start">
                            <div class="bg-zinc-100 dark:bg-zinc-800 rounded-xl px-4 py-3">
                                <div class="flex items-center gap-2 text-sm text-zinc-500">
                                    <svg class="animate-spin h-4 w-4" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                    </svg>
                                    {{ $aiProgressLabel ?: __('Building estimate...') }}
                                </div>
                            </div>
                        </div>
                    @endif

                    @if ($aiError)
                        <div class="bg-red-50 dark:bg-red-900/20 border border-red-200 dark:border-red-800 rounded-lg p-3 text-sm text-red-600 dark:text-red-400">
                            {{ $aiError }}
                        </div>
                    @endif
                </div>

                {{-- Input --}}
                <div class="border-t border-zinc-200 dark:border-zinc-700 p-3">
                    <form wire:submit.prevent="sendAiChatMessage" class="flex items-end gap-2">
                        <div class="flex-1">
                            <textarea
                                wire:model="aiChatInput"
                                placeholder="{{ __('Describe rooms, dimensions, paint...') }}"
                                class="w-full rounded-lg border border-zinc-300 dark:border-zinc-600 bg-white dark:bg-zinc-800 text-sm px-3 py-2 resize-none focus:ring-2 focus:ring-purple-500 focus:border-transparent dark:text-zinc-100"
                                rows="2"
                                @keydown.enter.prevent="if (!event.shiftKey) { $wire.sendAiChatMessage(); }"
                                @if($activeIsProcessing) disabled @endif
                            ></textarea>
                        </div>
                        <div class="flex flex-col gap-1">
                            {{-- Voice recording button --}}
                            <button
                                type="button"
                                @click="isRecording ? stopRecording() : startRecording()"
                                :class="isRecording ? 'bg-red-500 text-white animate-pulse' : 'bg-zinc-100 dark:bg-zinc-700 text-zinc-600 dark:text-zinc-300 hover:bg-zinc-200 dark:hover:bg-zinc-600'"
                                class="p-2 rounded-lg transition-colors"
                                @if($activeIsProcessing) disabled @endif
                                title="{{ __('Voice recording') }}"
                            >
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11a7 7 0 01-14 0m14 0a7 7 0 00-14 0m14 0v1a7 7 0 01-14 0v-1m7 8v3m-4 0h8M12 1a3 3 0 00-3 3v7a3 3 0 006 0V4a3 3 0 00-3-3z" />
                                </svg>
                            </button>
                            {{-- Send button --}}
                            <button
                                type="submit"
                                class="p-2 rounded-lg bg-purple-600 text-white hover:bg-purple-700 transition-colors disabled:opacity-50"
                                @if($activeIsProcessing) disabled @endif
                            >
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                                </svg>
                            </button>
                        </div>
                    </form>
                    <p class="mt-1.5 text-xs text-zinc-400 dark:text-zinc-500">{{ __('Each thread remembers context. Use + to start a new area.') }}</p>
                </div>
            </div>
        </div>

        {{-- Backdrop --}}
        <div
            x-show="$wire.showAiChat"
            x-cloak
            @click="$wire.toggleAiChat()"
            class="fixed inset-0 z-40 bg-black/20 sm:hidden"
        ></div>
    @endif
</div>
