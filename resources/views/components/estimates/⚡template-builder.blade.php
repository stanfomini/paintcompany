<?php

use App\Models\CompanySetting;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaAddon;
use App\Models\EstimateAreaSurface;
use App\Models\EstimateTemplate;
use App\Models\EstimateTemplateCategory;
use App\Models\NestedAreaTemplate;
use App\Models\PaintProduct;
use App\Models\ProductionRate;
use App\Models\Substrate;
use App\Services\EstimateAmendmentService;
use App\Services\EstimatePackageGeneratorService;
use App\Services\EstimateTemplateService;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component
{
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

    public float $taxRate = 0;

    public float $discountAmount = 0;

    public float $marginDiscountPercent = 0;

    public float $depositPercent = 30;

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

    // Template picker modal state
    public bool $showTemplatePicker = false;

    // AI Build state
    public bool $aiBuildInProgress = false;

    public ?string $aiBuildStatus = null;

    public ?string $aiBuildMessage = null;

    protected function rules(): array
    {
        // Use areas if available, otherwise fall back to selectedTemplates
        $hasAreas = ! empty($this->areas);

        $rules = [
            'title' => ['required', 'string', 'max:255'],
            'taxRate' => ['required', 'numeric', 'min:0', 'max:100'],
            'discountAmount' => ['required', 'numeric', 'min:0'],
            'marginDiscountPercent' => ['required', 'numeric', 'min:0', 'max:15'],
            'depositPercent' => ['required', 'numeric', 'min:0', 'max:100'],
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

            // Load existing template usages (template mode only)
            if ($estimate->isTemplateMode()) {
                $this->loadExistingTemplateUsages();

                // Detect project scope from existing templates
                $this->detectProjectScopeFromTemplates();
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
        }

        // Restore AI build state from cache (survives page refresh)
        if ($this->estimate) {
            $cached = \Illuminate\Support\Facades\Cache::get("ai-build-estimate:{$this->estimate->id}");
            if ($cached && in_array($cached['status'] ?? '', ['building', 'sending'])) {
                // Ignore stale build state older than 10 minutes
                $startedAt = isset($cached['started_at']) ? \Carbon\Carbon::parse($cached['started_at']) : null;
                if ($startedAt && $startedAt->diffInMinutes(now()) > 10) {
                    \Illuminate\Support\Facades\Cache::forget("ai-build-estimate:{$this->estimate->id}");
                } else {
                    $this->aiBuildInProgress = true;
                    $this->aiBuildStatus = $cached['status'];
                    $this->aiBuildMessage = $cached['message'] ?? 'AI is building your estimate...';
                }
            }
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
                $surfaces = [];
                $totalLaborHours = 0;
                $totalLaborCost = 0;
                $totalMaterialCost = 0;

                foreach ($area->surfaces as $surface) {
                    $surfaceCategory = $surface->productionRate?->surface_category
                        ?? $this->inferSurfaceCategory($surface->surface_type, $area->scope_type ?? 'interior');

                    $surfaces[] = [
                        'id' => $surface->id,
                        'name' => $surface->name,
                        'surface_type' => $surface->surface_type,
                        'surface_category' => $surfaceCategory,
                        'production_rate_id' => $surface->production_rate_id ? (string) $surface->production_rate_id : null,
                        'quantity_source' => match (true) {
                            $surface->surface_type === 'doors' => 'door_count',
                            $surface->surface_type === 'windows' => 'window_count',
                            $surface->surface_type === 'ceiling' && $surface->unit === 'sqft' => 'ceiling_sqft',
                            $surface->unit === 'sqft' => 'wall_sqft',
                            $surface->unit === 'lnft' => 'perimeter_lnft',
                            default => 'custom',
                        },
                        'quantity' => (float) $surface->quantity,
                        'quantity_override' => $surface->unit === 'units' ? (float) $surface->quantity : null,
                        'default_coats' => $surface->coats,
                        'is_required' => false,
                        'is_default_enabled' => $surface->is_included,
                        'is_custom' => (bool) $surface->is_custom,
                        'fixed_price' => null,
                        'labor_hours' => (float) ($surface->labor_hours ?? 0),
                        'labor_cost' => (float) ($surface->labor_cost ?? 0),
                        'material_cost' => (float) ($surface->material_cost ?? 0),
                    ];

                    if ($surface->is_included) {
                        $totalLaborHours += (float) ($surface->labor_hours ?? 0);
                        $totalLaborCost += (float) ($surface->labor_cost ?? 0);
                        $totalMaterialCost += (float) ($surface->material_cost ?? 0);
                    }
                }

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

                $settings = CompanySetting::getInstance();
                $scopeType = $area->scope_type ?? 'interior';
                $hourlyWage = $settings->getEffectiveHourlyRateForScope($scopeType);

                $cleanupHours = $settings->calculateCleanupHours($totalLaborHours, $scopeType);
                $cleanupCost = $cleanupHours * $hourlyWage;
                $sundriesCost = $settings->calculateSundriesCost($totalLaborHours, $scopeType);

                $cogsResult = $settings->calculateCOGS($totalLaborCost, $cleanupCost, $sundriesCost, $totalMaterialCost + $addonTotal);
                $cogs = $cogsResult['cogs'];
                $effectiveMargin = min(99, $settings->getMarginPercentForScope($scopeType) + $this->marginDiscountPercent);
                $withMargins = round($cogs / ($effectiveMargin / 100), 2);

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
                    ],
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

                foreach ($area->surfaces as $surface) {
                    $surfaceCategory = $surface->productionRate?->surface_category
                        ?? $this->inferSurfaceCategory($surface->surface_type, $area->scope_type ?? 'interior');

                    $surfaces[] = [
                        'id' => $surface->id,
                        'name' => $surface->name,
                        'surface_type' => $surface->surface_type,
                        'surface_category' => $surfaceCategory,
                        'production_rate_id' => $surface->production_rate_id ? (string) $surface->production_rate_id : null,
                        'quantity_source' => match (true) {
                            $surface->surface_type === 'doors' => 'door_count',
                            $surface->surface_type === 'windows' => 'window_count',
                            $surface->surface_type === 'ceiling' && $surface->unit === 'sqft' => 'ceiling_sqft',
                            $surface->unit === 'sqft' => 'wall_sqft',
                            $surface->unit === 'lnft' => 'perimeter_lnft',
                            default => 'custom',
                        },
                        'quantity' => (float) $surface->quantity,
                        'quantity_override' => $surface->unit === 'units' ? (float) $surface->quantity : null,
                        'default_coats' => $surface->coats,
                        'is_required' => false,
                        'is_default_enabled' => $surface->is_included,
                        'is_custom' => (bool) $surface->is_custom,
                        'fixed_price' => null,
                        'labor_hours' => (float) ($surface->labor_hours ?? 0),
                        'labor_cost' => (float) ($surface->labor_cost ?? 0),
                        'material_cost' => (float) ($surface->material_cost ?? 0),
                    ];

                    // Sum up totals from surfaces
                    if ($surface->is_included) {
                        $totalLaborHours += (float) ($surface->labor_hours ?? 0);
                        $totalLaborCost += (float) ($surface->labor_cost ?? 0);
                        $totalMaterialCost += (float) ($surface->material_cost ?? 0);
                    }
                }

                // Build addons array and sum addon costs
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

                // Recalculate the breakdown using PBP formula with actual surface data
                $settings = CompanySetting::getInstance();
                $scopeType = $area->scope_type ?? 'interior';
                $hourlyWage = $settings->getEffectiveHourlyRateForScope($scopeType);

                $cleanupHours = $settings->calculateCleanupHours($totalLaborHours, $scopeType);
                $cleanupCost = $cleanupHours * $hourlyWage; // Effective rate = base wage × payroll burden
                $sundriesCost = $settings->calculateSundriesCost($totalLaborHours, $scopeType);

                // Calculate COGS with taxes
                $cogsResult = $settings->calculateCOGS($totalLaborCost, $cleanupCost, $sundriesCost, $totalMaterialCost + $addonTotal);
                $cogs = $cogsResult['cogs'];
                $effectiveMargin = min(99, $settings->getMarginPercentForScope($scopeType) + $this->marginDiscountPercent);
                $withMargins = round($cogs / ($effectiveMargin / 100), 2);

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
                ];
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
            $surfaces = [];
            $totalLaborHours = 0;
            $totalLaborCost = 0;
            $totalMaterialCost = 0;

            foreach ($area->surfaces as $surface) {
                $surfaceCategory = $surface->productionRate?->surface_category
                    ?? $this->inferSurfaceCategory($surface->surface_type, $area->scope_type ?? 'interior');

                $surfaces[] = [
                    'id' => $surface->id,
                    'name' => $surface->name,
                    'surface_type' => $surface->surface_type,
                    'surface_category' => $surfaceCategory,
                    'production_rate_id' => $surface->production_rate_id ? (string) $surface->production_rate_id : null,
                    'quantity_source' => match (true) {
                        $surface->surface_type === 'doors' => 'door_count',
                        $surface->surface_type === 'windows' => 'window_count',
                        $surface->surface_type === 'ceiling' && $surface->unit === 'sqft' => 'ceiling_sqft',
                        $surface->unit === 'sqft' => 'wall_sqft',
                        $surface->unit === 'lnft' => 'perimeter_lnft',
                        default => 'custom',
                    },
                    'quantity' => (float) $surface->quantity,
                    'quantity_override' => $surface->unit === 'units' ? (float) $surface->quantity : null,
                    'default_coats' => $surface->coats,
                    'is_required' => false,
                    'is_default_enabled' => $surface->is_included,
                    'is_custom' => (bool) $surface->is_custom,
                    'fixed_price' => null,
                    'labor_hours' => (float) ($surface->labor_hours ?? 0),
                    'labor_cost' => (float) ($surface->labor_cost ?? 0),
                    'material_cost' => (float) ($surface->material_cost ?? 0),
                ];

                if ($surface->is_included) {
                    $totalLaborHours += (float) ($surface->labor_hours ?? 0);
                    $totalLaborCost += (float) ($surface->labor_cost ?? 0);
                    $totalMaterialCost += (float) ($surface->material_cost ?? 0);
                }
            }

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

            $settings = CompanySetting::getInstance();
            $scopeType = $area->scope_type ?? 'interior';
            $hourlyWage = $settings->getEffectiveHourlyRateForScope($scopeType);

            $cleanupHours = $settings->calculateCleanupHours($totalLaborHours, $scopeType);
            $cleanupCost = $cleanupHours * $hourlyWage;
            $sundriesCost = $settings->calculateSundriesCost($totalLaborHours, $scopeType);

            $cogsResult = $settings->calculateCOGS($totalLaborCost, $cleanupCost, $sundriesCost, $totalMaterialCost + $addonTotal);
            $cogs = $cogsResult['cogs'];
            $effectiveMargin = min(99, $settings->getMarginPercentForScope($scopeType) + $this->marginDiscountPercent);
            $withMargins = round($cogs / ($effectiveMargin / 100), 2);

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
                ],
            ];
        }
    }

    /**
     * Handle project scope changes.
     * Filters out templates that don't match the new scope, syncs scopeFilter,
     * and reloads paint defaults for the new scope.
     */
    public function updatedProjectScope(): void
    {
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

        $this->dispatch('scope-changed');
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
     * Get templates grouped by category.
     *
     * @return Collection<string, Collection<int, EstimateTemplate>>
     */
    public function getTemplatesByCategoryProperty(): Collection
    {
        $categories = EstimateTemplateCategory::query()
            ->active()
            ->with(['templates' => function ($q) {
                $q->active()->with('productionRate')->orderBy('sort_order');

                // Filter by scope type if not 'all'
                if ($this->scopeFilter === 'interior') {
                    $q->whereIn('scope_type', [EstimateTemplate::SCOPE_TYPE_INTERIOR, EstimateTemplate::SCOPE_TYPE_BOTH]);
                } elseif ($this->scopeFilter === 'exterior') {
                    $q->whereIn('scope_type', [EstimateTemplate::SCOPE_TYPE_EXTERIOR, EstimateTemplate::SCOPE_TYPE_BOTH]);
                }
            }])
            ->orderBy('sort_order')
            ->get();

        return $categories->mapWithKeys(function (EstimateTemplateCategory $category) {
            return [$category->type => $category->templates];
        });
    }

    /**
     * Get categories for tabs.
     *
     * @return Collection<int, EstimateTemplateCategory>
     */
    public function getCategoriesProperty(): Collection
    {
        return EstimateTemplateCategory::query()
            ->active()
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Calculate detailed cost breakdown for a template using PBP formula.
     *
     * For templates with surface_ratios in metadata, calculates each surface
     * (walls, ceiling, baseboards, doors, etc.) using their specific production rates.
     *
     * PBP Formula per surface:
     * 1. labor_hours = (quantity / production_rate) × prep_multiplier × paint_multiplier × coat_multiplier
     * 2. labor_cost = labor_hours × scope_effective_hourly_rate
     * 3. cleanup_hours = labor_hours / cleanup_ratio
     * 4. cleanup_cost = cleanup_hours × scope_effective_hourly_rate
     * 5. sundries_cost = labor_hours × scope_sundries_rate
     * 6. material_cost = (sqft × coats / coverage) × paint_price × material_multiplier + recycle_fee
     * 7. base_total = labor_cost + cleanup_cost + sundries_cost + material_cost
     * 8. with_margins = COGS / (margin% / 100) where margin% is scope-specific from CompanySetting
     *
     * @param  array<string, mixed>  $options  Optional overrides: substrate_id, paint_product_id
     * @return array{labor_hours: float, labor_cost: float, cleanup_hours: float, cleanup_cost: float, sundries_cost: float, material_cost: float, base_total: float, with_margins: float, surfaces?: array, substrate_id?: int, paint_product_id?: int}
     */

    /**
     * Infer the surface category from surface_type and scope when no production rate is set.
     */
    protected function inferSurfaceCategory(string $surfaceType, string $scopeType): string
    {
        if ($scopeType === 'exterior') {
            return match ($surfaceType) {
                'walls' => 'body',
                'ceiling' => 'eaves',
                'trim' => 'trim',
                'doors' => 'exterior_doors',
                'windows' => 'windows',
                default => 'body',
            };
        }

        return match ($surfaceType) {
            'walls' => 'walls',
            'ceiling' => 'ceiling',
            'trim' => 'baseboard',
            'doors' => 'doors',
            'windows' => 'windows',
            default => 'walls',
        };
    }

    public function getTemplateCostBreakdown(int $templateId, float $quantity, int $coats, array $options = []): array
    {
        $template = EstimateTemplate::with(['productionRate', 'substrate', 'defaultPaintProduct'])->find($templateId);
        $settings = CompanySetting::getInstance();

        // Handle templates without production rates (fixed price, per sqft, etc.)
        if (! $template) {
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

        $metadata = $template->metadata ?? [];
        $surfaceRatios = $metadata['surface_ratios'] ?? null;

        // If template has surface_ratios, calculate each surface separately
        if ($surfaceRatios && is_array($surfaceRatios)) {
            return $this->calculateSurfaceBasedBreakdown($template, $surfaceRatios, $quantity, $coats, $settings, $options);
        }

        // Fallback to simple calculation for templates without surface ratios
        return $this->calculateSimpleBreakdown($template, $quantity, $coats, $settings, $options);
    }

    /**
     * Map a surface name to its category for paint product resolution.
     *
     * @return string Category key (walls, ceiling, trim, doors, body, fascia, eaves)
     */
    protected function mapSurfaceToCategory(string $surfaceName): string
    {
        $name = strtolower($surfaceName);

        return match (true) {
            str_contains($name, 'wall') => 'walls',
            str_contains($name, 'ceiling') => 'ceiling',
            str_contains($name, 'baseboard'), str_contains($name, 'crown'), str_contains($name, 'trim'), str_contains($name, 'molding') => 'trim',
            str_contains($name, 'door') => 'doors',
            str_contains($name, 'body'), str_contains($name, 'siding') => 'body',
            str_contains($name, 'fascia') => 'fascia',
            str_contains($name, 'eave'), str_contains($name, 'soffit') => 'eaves',
            default => 'walls', // Default to walls
        };
    }

    /**
     * Resolve the paint product ID for a specific surface.
     * Priority depends on paintMode:
     * - single: surface override → defaultPaintProductId → company category default
     * - category: surface override → paintByCategory → company category default → defaultPaintProductId
     *
     * @param  array<string, mixed>  $options  Optional overrides: paint_product_id
     */
    protected function resolvePaintForSurface(string $surfaceName, array $options): ?int
    {
        // 1. Direct surface override (highest priority - always wins)
        if (! empty($options['paint_product_id'])) {
            return (int) $options['paint_product_id'];
        }

        $category = $this->mapSurfaceToCategory($surfaceName);
        $settings = CompanySetting::getInstance();

        if ($this->paintMode === 'single') {
            // Single mode: use default paint for everything
            // 2a. Estimate default paint
            if ($this->defaultPaintProductId) {
                return $this->defaultPaintProductId;
            }

            // 3a. Company default for this category
            $companyDefault = $settings->getDefaultPaintForCategory($category, $this->projectScope);
            if ($companyDefault) {
                return $companyDefault;
            }
        } else {
            // Category mode: use per-category selections
            // 2b. Category-level override from paintByCategory
            if (! empty($this->paintByCategory[$category])) {
                return (int) $this->paintByCategory[$category];
            }

            // 3b. Company default for this category
            $companyDefault = $settings->getDefaultPaintForCategory($category, $this->projectScope);
            if ($companyDefault) {
                return $companyDefault;
            }

            // 4b. Fall back to estimate default paint
            if ($this->defaultPaintProductId) {
                return $this->defaultPaintProductId;
            }
        }

        return null;
    }

    /**
     * Calculate breakdown for templates with surface_ratios (rooms).
     * Each surface is calculated individually using its specific production rate.
     *
     * Now includes substrate multipliers and paint product pricing.
     *
     * @param  array<string, array>  $surfaceRatios
     * @param  array<string, mixed>  $options  Optional overrides: substrate_id, paint_product_id
     * @return array{labor_hours: float, labor_cost: float, cleanup_hours: float, cleanup_cost: float, sundries_cost: float, material_cost: float, base_total: float, with_margins: float, surfaces: array, substrate_id: ?int, paint_product_id: ?int}
     */
    protected function calculateSurfaceBasedBreakdown(
        EstimateTemplate $template,
        array $surfaceRatios,
        float $roomSqft,
        int $coats,
        CompanySetting $settings,
        array $options = []
    ): array {
        $scopeType = $template->scope_type ?? 'interior';
        // Use effective hourly rate (base wage × payroll burden) for COGS
        $hourlyWage = $settings->getEffectiveHourlyRateForScope($scopeType);

        // Get substrate from options or template default
        $substrateId = $options['substrate_id'] ?? $template->substrate_id;

        $totalLaborHours = 0;
        $totalLaborCost = 0;
        $totalMaterialCost = 0;
        $surfaces = [];

        foreach ($surfaceRatios as $surfaceName => $surfaceConfig) {
            // Resolve paint product for this specific surface using cascade
            $surfacePaintProductId = $this->resolvePaintForSurface($surfaceName, $options);

            $surfaceOptions = [
                'substrate_id' => $substrateId,
                'paint_product_id' => $surfacePaintProductId,
            ];

            $surfaceResult = $this->calculateSurfaceCost(
                $surfaceName,
                $surfaceConfig,
                $roomSqft,
                $coats,
                $hourlyWage, // Effective rate (base wage × payroll burden)
                $settings,
                $surfaceOptions
            );

            $totalLaborHours += $surfaceResult['labor_hours'];
            $totalLaborCost += $surfaceResult['labor_cost'];
            $totalMaterialCost += $surfaceResult['material_cost'];
            $surfaces[$surfaceName] = $surfaceResult;
        }

        // Calculate cleanup and sundries based on total labor hours
        $cleanupHours = $settings->calculateCleanupHours($totalLaborHours, $scopeType);
        $cleanupCost = $cleanupHours * $hourlyWage; // Effective rate = base wage × payroll burden
        $sundriesCost = $settings->calculateSundriesCost($totalLaborHours, $scopeType);

        // Calculate COGS with taxes (material tax and labor tax if applicable)
        $cogsResult = $settings->calculateCOGS($totalLaborCost, $cleanupCost, $sundriesCost, $totalMaterialCost);
        $cogs = $cogsResult['cogs'];

        // Apply scope-specific margin (with discount if applicable): OTD = COGS / (margin% / 100)
        $effectiveMargin = min(99, $settings->getMarginPercentForScope($scopeType) + $this->marginDiscountPercent);
        $withMargins = round($cogs / ($effectiveMargin / 100), 2);

        // Get primary paint product ID (for backward compatibility, use first resolved or template default)
        $primaryPaintProductId = $options['paint_product_id'] ?? $this->defaultPaintProductId ?? $template->default_paint_product_id;

        return [
            'labor_hours' => round($totalLaborHours, 2),
            'labor_cost' => round($totalLaborCost, 2),
            'cleanup_hours' => round($cleanupHours, 2),
            'cleanup_cost' => round($cleanupCost, 2),
            'sundries_cost' => round($sundriesCost, 2),
            'material_cost' => round($totalMaterialCost, 2),
            'material_tax' => $cogsResult['material_tax'],
            'labor_tax' => $cogsResult['labor_tax'],
            'base_total' => $cogs, // COGS = labor + cleanup + sundries + materials + taxes
            'with_margins' => round($withMargins, 2),
            'surfaces' => $surfaces,
            'substrate_id' => $substrateId,
            'paint_product_id' => $primaryPaintProductId,
        ];
    }

    /**
     * Calculate cost for a single surface based on its config.
     *
     * Now includes substrate multipliers and paint product pricing.
     *
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $options  Optional: substrate_id, paint_product_id overrides
     * @return array{name: string, quantity: float, unit: string, labor_hours: float, labor_cost: float, material_cost: float, substrate_multiplier: float}
     */
    protected function calculateSurfaceCost(
        string $surfaceName,
        array $config,
        float $roomSqft,
        int $coats,
        float $hourlyRate,
        CompanySetting $settings,
        array $options = []
    ): array {
        $type = $config['type'] ?? 'sqft';

        // Handle fixed-price surfaces (e.g., closets)
        if ($type === 'fixed') {
            return [
                'name' => ucfirst(str_replace('_', ' ', $surfaceName)),
                'quantity' => 1,
                'unit' => 'fixed',
                'labor_hours' => 0,
                'labor_cost' => 0,
                'material_cost' => (float) ($config['price'] ?? 0),
                'substrate_multiplier' => 1.0,
            ];
        }

        // Calculate surface quantity based on config
        $quantity = $this->calculateSurfaceQuantity($config, $roomSqft);
        $unit = $type === 'unit' ? 'units' : ($type === 'lnft' ? 'lnft' : 'sqft');

        // Get production rate for this surface
        $rate = null;
        if (isset($config['rate_id'])) {
            $rate = ProductionRate::find($config['rate_id']);
        }

        if (! $rate) {
            // Fallback: estimate labor at 200 sqft/hr for walls
            $productionRate = 200;
        } else {
            $productionRate = $rate->production_rate;
        }

        // Get substrate multipliers (prep and paint time adjustments)
        $substrate = null;
        $prepMultiplier = 1.0;
        $paintMultiplier = 1.0;

        if (isset($options['substrate_id'])) {
            $substrate = Substrate::find($options['substrate_id']);
        }

        if ($substrate) {
            $prepMultiplier = (float) ($substrate->prep_time_multiplier ?? 1.0);
            $paintMultiplier = (float) ($substrate->paint_time_multiplier ?? 1.0);
        }

        // Combined substrate multiplier for display
        $substrateMultiplier = $prepMultiplier * $paintMultiplier;

        // Get coat multiplier
        $coatMultiplier = $rate ? $rate->getCoatMultiplier($coats) : ($coats > 1 ? 1.8 : 1.0);

        // Calculate labor hours with substrate multipliers applied
        // Formula: (quantity / rate) × prep_multiplier × paint_multiplier × coat_multiplier
        $laborHours = ($quantity / $productionRate) * $prepMultiplier * $paintMultiplier * $coatMultiplier;
        $laborCost = $laborHours * $hourlyRate;

        // No fallback material cost - material_cost_per_unit was removed from ProductionRate
        // Material costs now come exclusively from PaintProduct selection
        $fallbackMaterialCost = null;

        // Calculate material cost from PaintProduct if available, else use production rate fallback
        $materialCost = $this->calculateMaterialCostForSurface(
            $quantity,
            $coats,
            $options['paint_product_id'] ?? null,
            $substrate,
            $settings,
            $fallbackMaterialCost,
            $coatMultiplier
        );

        return [
            'name' => ucfirst(str_replace('_', ' ', $surfaceName)),
            'quantity' => round($quantity, 1),
            'unit' => $unit,
            'labor_hours' => round($laborHours, 2),
            'labor_cost' => round($laborCost, 2),
            'material_cost' => round($materialCost, 2),
            'substrate_multiplier' => round($substrateMultiplier, 2),
        ];
    }

    /**
     * Calculate material cost for a surface using PaintProduct pricing.
     *
     * Formula: (sqft × coats / coverage) × paint_price × material_multiplier + recycle_fee
     *
     * Falls back to production rate's material_cost_per_unit if no paint product is linked.
     *
     * @param  float|null  $fallbackMaterialCostPerUnit  From production rate, used if no paint product
     */
    protected function calculateMaterialCostForSurface(
        float $quantity,
        int $coats,
        ?int $paintProductId,
        ?Substrate $substrate,
        CompanySetting $settings,
        ?float $fallbackMaterialCostPerUnit = null,
        float $coatMultiplier = 1.0
    ): float {
        // Try to get paint product (with in-memory cache to avoid repeated queries)
        static $paintProductCache = [];
        $paintProduct = null;
        if ($paintProductId) {
            if (! array_key_exists($paintProductId, $paintProductCache)) {
                $paintProductCache[$paintProductId] = PaintProduct::find($paintProductId);
            }
            $paintProduct = $paintProductCache[$paintProductId];
        }

        if (! $paintProduct) {
            // No fallback - material_cost_per_unit was removed from ProductionRate
            // Material costs must come from PaintProduct selection
            // If no paint product is configured, material cost is 0
            return 0.0;
        }

        // Calculate gallons needed using proper coverage method
        // Use brush_roll coverage as default (most common application method)
        $coverage = $paintProduct->getCoverage('brush_roll');
        if ($coverage <= 0) {
            $coverage = 400; // Fallback
        }
        $totalSqft = $quantity * $coats;
        $gallonsNeeded = $totalSqft / $coverage;

        // Apply material multiplier from substrate (e.g., rough surfaces need more paint)
        $materialMultiplier = $substrate ? (float) ($substrate->material_multiplier ?? 1.0) : 1.0;
        $gallonsNeeded = $gallonsNeeded * $materialMultiplier;

        // Round up to nearest gallon for ordering
        $gallonsRounded = (int) ceil($gallonsNeeded);

        // Calculate paint cost
        $pricePerGallon = (float) ($paintProduct->price_per_gallon ?? 50.00);
        $paintCost = $gallonsRounded * $pricePerGallon;

        // Add recycle fee
        $recycleFee = $gallonsRounded * (float) ($settings->recycle_fee_per_gallon ?? 0.54);

        return $paintCost + $recycleFee;
    }

    /**
     * Calculate surface quantity from room sqft using surface config.
     *
     * @param  array<string, mixed>  $config
     */
    protected function calculateSurfaceQuantity(array $config, float $roomSqft): float
    {
        $type = $config['type'] ?? 'sqft';

        // Ratio-based (e.g., walls = 1.0 × roomSqft, ceiling = 0.9 × roomSqft)
        if (isset($config['ratio'])) {
            return $roomSqft * (float) $config['ratio'];
        }

        // Per 100 sqft (e.g., baseboards = 28 lnft per 100 sqft)
        if (isset($config['per_100sqft'])) {
            return ($roomSqft / 100) * (float) $config['per_100sqft'];
        }

        // Per linear foot (e.g., fence = 12 sqft per lnft)
        if (isset($config['per_lnft'])) {
            return $roomSqft * (float) $config['per_lnft'];
        }

        // Fixed count (e.g., doors = 1)
        if (isset($config['count'])) {
            return (float) $config['count'];
        }

        return $roomSqft;
    }

    /**
     * Simple calculation for templates without surface ratios.
     *
     * PBP Formula:
     * 1. COGS = Labor + Cleanup + Sundries + Materials + Material Tax + Labor Tax
     * 2. OTD = COGS × (1 + markup%)
     *
     * Note: Payroll burden is NOT applied to the hourly wage - it's factored into the markup.
     *
     * @param  array<string, mixed>  $options  Optional overrides: substrate_id, paint_product_id
     * @return array{labor_hours: float, labor_cost: float, cleanup_hours: float, cleanup_cost: float, sundries_cost: float, material_cost: float, base_total: float, with_margins: float, substrate_id: ?int, paint_product_id: ?int}
     */
    protected function calculateSimpleBreakdown(
        EstimateTemplate $template,
        float $quantity,
        int $coats,
        CompanySetting $settings,
        array $options = []
    ): array {
        // Handle fixed-price templates with full cost tracking
        if (! $template->productionRate) {
            $scopeType = $template->scope_type ?? 'interior';

            // Check if template has cost tracking data
            $hasCosting = $template->estimated_labor_hours !== null
                || $template->labor_cost !== null
                || $template->material_cost !== null;

            if ($hasCosting) {
                // Use the new cost calculation method
                $breakdown = $template->calculateFixedPrice($scopeType);

                // For fixed templates, quantity is typically 1 (or ignored)
                // but support quantity multiplier for items like "per room" charges
                $multiplier = $quantity > 1 ? $quantity : 1;

                return [
                    'labor_hours' => $breakdown['labor_hours'] * $multiplier,
                    'labor_cost' => $breakdown['labor_cost'] * $multiplier,
                    'cleanup_hours' => 0, // Fixed items don't have cleanup
                    'cleanup_cost' => 0,
                    'sundries_cost' => 0, // Included in material_cost if applicable
                    'material_cost' => $breakdown['material_cost'] * $multiplier,
                    'material_tax' => 0, // Can add if needed
                    'labor_tax' => 0,
                    'base_total' => $breakdown['cogs'] * $multiplier,
                    'with_margins' => $breakdown['final_price'] * $multiplier,
                    'margin_percent' => $breakdown['margin_percent'],
                    'profit' => $breakdown['profit'] * $multiplier,
                    'substrate_id' => null,
                    'paint_product_id' => null,
                ];
            }

            // Legacy fixed-price without cost tracking
            $price = $template->calculatePrice($quantity, $coats);

            return [
                'labor_hours' => 0,
                'labor_cost' => 0,
                'cleanup_hours' => 0,
                'cleanup_cost' => 0,
                'sundries_cost' => 0,
                'material_cost' => 0,
                'material_tax' => 0,
                'labor_tax' => 0,
                'base_total' => $price,
                'with_margins' => $price,
                'margin_percent' => null,
                'profit' => null,
                'substrate_id' => null,
                'paint_product_id' => null,
            ];
        }

        $rate = $template->productionRate;
        $scopeType = $template->scope_type ?? 'interior';

        // Get substrate from options or template default
        $substrateId = $options['substrate_id'] ?? $template->substrate_id;
        $paintProductId = $options['paint_product_id'] ?? $template->default_paint_product_id;

        // Get substrate multipliers
        $substrate = $substrateId ? Substrate::find($substrateId) : null;
        $prepMultiplier = $substrate ? (float) ($substrate->prep_time_multiplier ?? 1.0) : 1.0;
        $paintMultiplier = $substrate ? (float) ($substrate->paint_time_multiplier ?? 1.0) : 1.0;
        $materialMultiplier = $substrate ? (float) ($substrate->material_multiplier ?? 1.0) : 1.0;

        // Get coat multiplier (1 coat = 1.0, 2 coats = 1.8, 3 coats = 2.5)
        $coatMultiplier = $rate->getCoatMultiplier($coats);

        // 1. Calculate labor hours with substrate multipliers
        $laborHours = ($quantity / $rate->production_rate) * $prepMultiplier * $paintMultiplier * $coatMultiplier;

        // 2. Get effective hourly rate (base wage × payroll burden)
        $hourlyWage = $settings->getEffectiveHourlyRateForScope($scopeType);

        // 3. Calculate labor cost
        $laborCost = $laborHours * $hourlyWage;

        // 4. Calculate cleanup hours and cost
        $cleanupHours = $settings->calculateCleanupHours($laborHours, $scopeType);
        $cleanupCost = $cleanupHours * $hourlyWage;

        // 5. Calculate sundries cost
        $sundriesCost = $settings->calculateSundriesCost($laborHours, $scopeType);

        // 6. Calculate material cost from PaintProduct if available
        // No fallback - material_cost_per_unit was removed from ProductionRate
        $materialCost = $this->calculateMaterialCostForSurface(
            $quantity,
            $coats,
            $paintProductId,
            $substrate,
            $settings,
            null, // No fallback material cost
            $coatMultiplier
        );

        // 7. Calculate COGS with taxes
        $cogsResult = $settings->calculateCOGS($laborCost, $cleanupCost, $sundriesCost, $materialCost);
        $cogs = $cogsResult['cogs'];

        // 8. Apply scope-specific margin (with discount): OTD = COGS / (margin% / 100)
        $marginPercent = min(99, $settings->getMarginPercentForScope($scopeType) + $this->marginDiscountPercent);
        $withMargins = round($cogs / ($marginPercent / 100), 2);
        $profit = round($withMargins - $cogs, 2);

        return [
            'labor_hours' => round($laborHours, 2),
            'labor_cost' => round($laborCost, 2),
            'cleanup_hours' => round($cleanupHours, 2),
            'cleanup_cost' => round($cleanupCost, 2),
            'sundries_cost' => round($sundriesCost, 2),
            'material_cost' => round($materialCost, 2),
            'material_tax' => $cogsResult['material_tax'],
            'labor_tax' => $cogsResult['labor_tax'],
            'base_total' => $cogs, // COGS = labor + cleanup + sundries + materials + taxes
            'with_margins' => round($withMargins, 2),
            'margin_percent' => $marginPercent,
            'profit' => $profit,
            'substrate_id' => $substrateId,
            'paint_product_id' => $paintProductId,
        ];
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
            $laborCost = $laborHours * $settings->getEffectiveHourlyRateForScope($scope);
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
    public function getAdjustmentPreviewPriceProperty(): float
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
    public function getAdjustmentPreviewBreakdownProperty(): array
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
        $effectiveMargin = min(99, $settings->getMarginPercentForScope($this->projectScope) + $this->marginDiscountPercent);

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
        $this->marginDiscountPercent = max(0, min($max, $this->marginDiscountPercent));

        // Recalculate all areas/templates with new effective margin
        $this->recalculateAllPricesWithDiscount();
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
            $effectiveMargin = min(99, $settings->getMarginPercentForScope($scopeType) + $this->marginDiscountPercent);
            $withMargins = round($cogs / ($effectiveMargin / 100), 2);

            $this->areas[$index]['with_margins'] = $withMargins;
            $this->areas[$index]['price'] = $withMargins;
        }

        // Recalculate selectedTemplates
        foreach ($this->selectedTemplates as $index => $templateData) {
            $cogs = $templateData['base_total'] ?? 0;
            if ($cogs <= 0) {
                continue;
            }
            $scopeType = $templateData['scope_type'] ?? $this->projectScope;
            $effectiveMargin = min(99, $settings->getMarginPercentForScope($scopeType) + $this->marginDiscountPercent);
            $withMargins = round($cogs / ($effectiveMargin / 100), 2);

            $this->selectedTemplates[$index]['with_margins'] = $withMargins;
            $this->selectedTemplates[$index]['price'] = $withMargins;
        }
    }

    /**
     * Calculate the subtotal from all areas or selected templates.
     */
    public function getSubtotalProperty(): float
    {
        // Use areas if available, otherwise fall back to selectedTemplates
        if (! empty($this->areas)) {
            return array_sum(array_column($this->areas, 'price'));
        }

        return array_sum(array_column($this->selectedTemplates, 'price'));
    }

    // ===== AREA MANAGEMENT METHODS =====

    /**
     * Open template picker modal.
     */
    public function openTemplatePicker(): void
    {
        $this->showTemplatePicker = true;
    }

    /**
     * Close template picker modal.
     */
    public function closeTemplatePicker(): void
    {
        $this->showTemplatePicker = false;
    }

    /**
     * Add area template directly to the estimate.
     */
    public function addAreaFromTemplate(int $templateId): void
    {
        $this->showTemplatePicker = false;
        $this->addTemplate($templateId);
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
            marginDiscountPercent: $this->marginDiscountPercent,
        );
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
        if (! empty($templateData['area_data'])) {
            $areaData = [
                'name' => $templateData['name'],
                'scope_type' => $templateData['scope_type'],
                'source_template_id' => $templateData['template_id'],
                'default_coats' => $templateData['coats'] ?? 2,
                'length' => $templateData['area_data']['length'],
                'width' => $templateData['area_data']['width'],
                'height' => $templateData['area_data']['height'],
                'measurement_type' => $templateData['area_data']['measurement_type'] ?? 'dimensions',
                'wall_count' => $templateData['area_data']['wall_count'] ?? 4,
                'direct_sqft' => $templateData['area_data']['direct_sqft'] ?? null,
                'direct_lnft' => $templateData['area_data']['direct_lnft'] ?? null,
                'direct_ceiling_sqft' => $templateData['area_data']['direct_ceiling_sqft'] ?? null,
                'service_category_id' => $templateData['service_category_id'] ?? null,
                'surfaces' => $templateData['area_data']['surfaces'] ?? [],
                'addons' => $templateData['area_data']['addons'] ?? [],
            ];
            $this->dispatch(
                'open-area-builder-edit',
                areaData: $areaData,
                editingIndex: $index,
                paintMode: $this->paintMode,
                paintProductId: $this->defaultPaintProductId,
                paintByCategory: $this->paintByCategory,
                marginDiscountPercent: $this->marginDiscountPercent,
            );
        } elseif ($templateData['template_id']) {
            // Open AreaBuilder with the original template, passing index for update
            $this->dispatch(
                'open-area-builder-from-template',
                templateId: $templateData['template_id'],
                editingIndex: $index,
                paintMode: $this->paintMode,
                paintProductId: $this->defaultPaintProductId,
                paintByCategory: $this->paintByCategory,
                marginDiscountPercent: $this->marginDiscountPercent,
            );
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
            ],
            'sizes' => null,
            'selected_size' => null,
            'surface_ratios' => null,
            'surfaces' => null, // Will be set from breakdown if needed
        ];

        if ($editingIndex !== null && isset($this->selectedTemplates[$editingIndex])) {
            // Update existing template entry
            $this->selectedTemplates[$editingIndex] = $templateEntry;
        } else {
            // Add new template entry
            $this->selectedTemplates[] = $templateEntry;
        }

        $this->dispatch('template-added');
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
            $width = $areaData['width'];
            $height = $areaData['height'];
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
        // Use base hourly wage (payroll burden is factored into markup, not COGS)
        $hourlyWage = $settings->getEffectiveHourlyRateForScope($scopeType);

        $totalLaborHours = 0;
        $totalMaterialCost = 0;

        foreach ($areaData['surfaces'] ?? [] as $surface) {
            if (! ($surface['is_required'] ?? false) && ! ($surface['is_default_enabled'] ?? true)) {
                continue;
            }

            // Get quantity based on source
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
                $totalMaterialCost += (float) $surface['fixed_price'];
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

                // Calculate material cost from paint product if available
                // No fallback - material_cost_per_unit was removed from ProductionRate
                $materialCost = $this->calculateMaterialCostForSurface(
                    $quantity,
                    $coats,
                    $this->defaultPaintProductId,
                    null, // substrate
                    $settings,
                    null, // No fallback material cost
                    $coatMultiplier
                );
                $totalMaterialCost += $materialCost;
            }
        }

        $laborCost = $totalLaborHours * $hourlyWage;
        $cleanupHours = $settings->calculateCleanupHours($totalLaborHours, $scopeType);
        $cleanupCost = $cleanupHours * $hourlyWage;
        $sundriesCost = $settings->calculateSundriesCost($totalLaborHours, $scopeType);
        $addonCost = array_sum(array_column($areaData['addons'] ?? [], 'total_price'));

        // Calculate COGS with taxes
        $cogsResult = $settings->calculateCOGS($laborCost, $cleanupCost, $sundriesCost, $totalMaterialCost + $addonCost);
        $cogs = $cogsResult['cogs'];
        $effectiveMargin = min(99, $settings->getMarginPercentForScope($scopeType) + $this->marginDiscountPercent);
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
     * Get available templates for the template picker.
     *
     * @return Collection<int, EstimateTemplate>
     */
    #[Computed]
    public function pickerTemplates(): Collection
    {
        return EstimateTemplate::query()
            ->active()
            ->configurable() // Only area templates (is_configurable = true)
            ->whereIn('scope_type', [$this->projectScope, EstimateTemplate::SCOPE_TYPE_BOTH])
            ->with(['templateCategory', 'surfaces'])
            ->orderBy('name')
            ->get();
    }

    /**
     * Get fixed price templates (NestedAreaTemplates) for the picker.
     * These are simple fixed-cost add-ons like closets, pantries, exterior features.
     *
     * @return Collection<int, NestedAreaTemplate>
     */
    #[Computed]
    public function fixedPriceTemplates(): Collection
    {
        return NestedAreaTemplate::query()
            ->active()
            ->forScope($this->projectScope)
            ->ordered()
            ->get();
    }

    /**
     * Get fixed price templates grouped by category.
     *
     * @return Collection<string, Collection<int, NestedAreaTemplate>>
     */
    #[Computed]
    public function fixedPriceTemplatesByCategory(): Collection
    {
        return $this->fixedPriceTemplates->groupBy('category');
    }

    /**
     * Get available paint products for the project scope.
     *
     * @return Collection<int, PaintProduct>
     */
    #[Computed]
    public function availablePaintProducts(): Collection
    {
        return PaintProduct::query()
            ->active()
            ->forScope($this->projectScope)
            ->with('brand')
            ->orderBy('paint_brand_id')
            ->orderBy('name')
            ->get();
    }

    /**
     * Get the selected default paint product.
     */
    #[Computed]
    public function selectedPaintProduct(): ?PaintProduct
    {
        if (! $this->defaultPaintProductId) {
            return null;
        }

        return PaintProduct::with('brand')->find($this->defaultPaintProductId);
    }

    /**
     * Get surface categories for the current project scope.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function surfaceCategoriesForScope(): array
    {
        return CompanySetting::getSurfaceCategoriesForScope($this->projectScope);
    }

    /**
     * Get paint summary by category showing current selection for each category.
     *
     * @return array<string, array{name: string, price: float|null, product: PaintProduct|null, is_override: bool, is_company_default: bool}>
     */
    #[Computed]
    public function paintSummaryByCategory(): array
    {
        $categories = $this->surfaceCategoriesForScope;
        $settings = CompanySetting::getInstance();
        $companyDefaults = $settings->getPaintDefaultsForScope($this->projectScope);

        // Collect all paint product IDs first, then batch-load
        $productIds = [];
        $categoryMeta = [];

        foreach ($categories as $key => $label) {
            $paintProductId = $this->paintByCategory[$key] ?? null;
            $isOverride = $paintProductId !== null && $paintProductId !== '';

            $isCompanyDefault = false;
            if (! $isOverride) {
                $paintProductId = $companyDefaults[$key] ?? null;
                $isCompanyDefault = $paintProductId !== null;
            }

            if ($paintProductId) {
                $productIds[] = $paintProductId;
            }

            $categoryMeta[$key] = [
                'label' => $label,
                'paint_product_id' => $paintProductId ?: null,
                'is_override' => $isOverride,
                'is_company_default' => $isCompanyDefault,
            ];
        }

        // Single query to load all paint products with brands
        $products = ! empty($productIds)
            ? PaintProduct::with('brand')->whereIn('id', array_unique($productIds))->get()->keyBy('id')
            : collect();

        $summary = [];
        foreach ($categoryMeta as $key => $meta) {
            $product = $meta['paint_product_id'] ? $products->get($meta['paint_product_id']) : null;

            $summary[$key] = [
                'label' => $meta['label'],
                'name' => $product?->full_name ?? 'Not set',
                'price' => $product?->price_per_gallon,
                'product' => $product,
                'is_override' => $meta['is_override'],
                'is_company_default' => $meta['is_company_default'],
                'paint_product_id' => $meta['paint_product_id'],
            ];
        }

        return $summary;
    }

    /**
     * Get the count of paint category overrides.
     */
    #[Computed]
    public function paintOverrideCount(): int
    {
        return count(array_filter($this->paintByCategory, fn ($v) => $v !== null && $v !== ''));
    }

    /**
     * Handle paint mode changes.
     * Recalculates all prices when switching between single and category modes.
     */
    public function updatedPaintMode(): void
    {
        $this->recalculateAllPrices();
    }

    /**
     * Handle default paint product changes.
     * Recalculates all prices when the default paint is changed.
     */
    public function updatedDefaultPaintProductId(): void
    {
        $this->recalculateAllPrices();
    }

    /**
     * Update paint product for a specific category.
     */
    public function updatePaintForCategory(string $category, ?int $paintProductId): void
    {
        if ($paintProductId === null || $paintProductId === 0 || $paintProductId === '') {
            unset($this->paintByCategory[$category]);
        } else {
            $this->paintByCategory[$category] = (int) $paintProductId;
        }

        // Recalculate all templates/areas that might be affected
        $this->recalculateAllPrices();
    }

    /**
     * Reset a category to use company default (remove estimate override).
     */
    public function resetCategoryToDefault(string $category): void
    {
        unset($this->paintByCategory[$category]);
        $this->recalculateAllPrices();
    }

    /**
     * Recalculate prices for all selected templates/areas.
     */
    protected function recalculateAllPrices(): void
    {
        foreach ($this->selectedTemplates as $index => $templateData) {
            if (! empty($templateData['area_data'])) {
                $this->recalculateSelectedTemplatePrice($index);
            } else {
                $this->recalculatePrice($index);
            }
        }
    }

    /**
     * Calculate the tax amount.
     */
    public function getTaxAmountProperty(): float
    {
        return $this->subtotal * ($this->taxRate / 100);
    }

    /**
     * Calculate the total.
     */
    public function getTotalProperty(): float
    {
        return $this->subtotal + $this->taxAmount - $this->discountAmount;
    }

    /**
     * Calculate the deposit amount.
     */
    public function getDepositAmountProperty(): float
    {
        return $this->total * ($this->depositPercent / 100);
    }

    /**
     * Calculate the balance due.
     */
    public function getBalanceDueProperty(): float
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
            'tax_rate' => $this->taxRate,
            'tax_amount' => $this->taxAmount,
            'discount_amount' => $this->discountAmount,
            'margin_discount_percent' => $this->marginDiscountPercent,
            'total' => $this->total,
            'deposit_percent' => $this->depositPercent,
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

            // Remove existing template usages
            $this->estimate->templateUsages()->delete();

            // Remove existing areas and items created from templates
            $this->estimate->areas()->delete();
            $this->estimate->items()->delete();
            $this->estimate->optionalItems()->delete();

            // Apply templates (which may include area_data from AreaBuilder)
            $this->applySelectedTemplatesToEstimate($templateService);

            // Note: Estimate totals are already set from $this->subtotal which includes margins
            // calculated by the template system. Surfaces store COGS (labor + material).
            // We don't call calculateTotals() here because the template-based calculation
            // is the source of truth for template-mode estimates.

            // Regenerate packages with new areas/surfaces
            $packageGenerator->clearGeneratedPackages($this->estimate);
            $packageGenerator->generateFromTemplates($this->estimate);

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

            // Note: Estimate totals are already set from $this->subtotal which includes margins
            // calculated by the template system. Surfaces store COGS (labor + material).
            // We don't call calculateTotals() here because the template-based calculation
            // is the source of truth for template-mode estimates.

            // Generate packages from templates
            $packageGenerator->generateFromTemplates($this->estimate);

            // Now mark as sent if requested (this generates the public token)
            if ($markAsSent) {
                $this->estimate->markAsSent();
            }

            $this->dispatch('estimate-created');
            session()->flash('success', __('Estimate created successfully.'));
        }

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
            $areaPaintProductId = $areaData['default_paint_product_id'] ?? $this->defaultPaintProductId;

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
                    $hourlyWage = $settings->getEffectiveHourlyRateForScope($scopeType);
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
                    $hourlyWage = $settings->getEffectiveHourlyRateForScope($scopeType);
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
                $hourlyWage = $settings->getEffectiveHourlyRateForScope($scopeType);
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

                // Calculate labor hours and costs for this surface
                $coats = $surfaceData['default_coats'] ?? $templateData['coats'] ?? 2;
                $productionRateValue = $rate?->production_rate ?? 100;
                $coatMultiplier = $rate ? $rate->getCoatMultiplier($coats) : ($coats === 1 ? 1.0 : ($coats === 2 ? 1.8 : 2.5));
                $laborHours = ($quantity / $productionRateValue) * $coatMultiplier;

                $settings = CompanySetting::getInstance();
                $scopeType = $templateData['scope_type'] ?? 'interior';
                $hourlyWage = $settings->getEffectiveHourlyRateForScope($scopeType);
                $laborCost = $laborHours * $hourlyWage;

                // Get effective paint product ID (cascade: surface → area → template → estimate)
                $paintProductId = $surfaceData['paint_product_id']
                    ?? $areaData['default_paint_product_id']
                    ?? $templateData['paint_product_id']
                    ?? $this->defaultPaintProductId;

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
                'production_rate_id' => $isCustom ? null : ($surfaceData['production_rate_id'] ?: null),
                'name' => $surfaceData['name'],
                'surface_type' => $surfaceData['surface_type'] ?? 'other',
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
                'is_included' => $isIncluded,
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

    public function triggerAiBuild(): void
    {
        if (! config('ai-build.enabled')) {
            $this->aiBuildMessage = 'AI Build is not currently available.';

            return;
        }

        if (! $this->estimate) {
            return;
        }

        $this->aiBuildInProgress = true;
        $this->aiBuildStatus = 'sending';
        $this->aiBuildMessage = null;

        try {
            $response = \Illuminate\Support\Facades\Http::withHeaders([
                'X-AI-Build-Secret' => config('ai-build.api_secret'),
            ])->post(config('ai-build.dev_server_url').'/webhooks/ai-build', [
                'estimate_id' => $this->estimate->id,
            ]);

            if ($response->successful()) {
                $this->aiBuildStatus = 'building';
                $this->aiBuildMessage = 'AI is building your estimate. This may take a few minutes...';

                // Persist build state to cache so it survives page refresh
                \Illuminate\Support\Facades\Cache::put(
                    "ai-build-estimate:{$this->estimate->id}",
                    ['status' => 'building', 'message' => $this->aiBuildMessage, 'started_at' => now()->toISOString()],
                    now()->addMinutes(15)
                );
            } else {
                $this->aiBuildInProgress = false;
                $this->aiBuildStatus = 'failed';
                $this->aiBuildMessage = $response->json('error', 'Failed to start AI build.');
            }
        } catch (\Exception $e) {
            $this->aiBuildInProgress = false;
            $this->aiBuildStatus = 'failed';
            $this->aiBuildMessage = 'Could not connect to AI build service.';
        }
    }

    /**
     * Poll for AI build status updates (runs every 5s while build is in progress).
     */
    public function pollAiBuildStatus(): void
    {
        if (! $this->estimate) {
            return;
        }

        $cached = \Illuminate\Support\Facades\Cache::get("ai-build-estimate:{$this->estimate->id}");

        if (! $cached) {
            // Build finished or was never started
            $this->aiBuildInProgress = false;

            return;
        }

        $this->aiBuildInProgress = true;
        $this->aiBuildStatus = $cached['status'] ?? 'building';
        $this->aiBuildMessage = $cached['message'] ?? 'AI is working...';

        // If final status, clear cache and reload the page to show new areas
        if (in_array($cached['status'] ?? '', ['completed', 'partial', 'failed', 'nothing_to_build', 'unreadable_blueprints'])) {
            \Illuminate\Support\Facades\Cache::forget("ai-build-estimate:{$this->estimate->id}");
            $this->aiBuildInProgress = false;

            if (in_array($cached['status'], ['completed', 'partial'])) {
                $this->redirect(route('estimates.edit', $this->estimate), navigate: true);
            }
        }
    }

    #[\Livewire\Attributes\On('echo-private:estimate.{estimate.id},ai.build.completed')]
    public function onAiBuildCompleted(array $data): void
    {
        $this->aiBuildStatus = $data['status'];

        // Progress update — keep spinner going, just update the message
        if ($data['status'] === 'building') {
            $this->aiBuildInProgress = true;
            $this->aiBuildMessage = $data['summary'] ?? 'AI is working...';

            return;
        }

        // Final status — stop spinner
        $this->aiBuildInProgress = false;

        // Clear the cache since we got the final status via Reverb
        if ($this->estimate) {
            \Illuminate\Support\Facades\Cache::forget("ai-build-estimate:{$this->estimate->id}");
        }

        $this->aiBuildMessage = match ($data['status']) {
            'completed' => "AI built {$data['areas_created']} areas with {$data['surfaces_created']} surfaces! Refreshing...",
            'partial' => "AI built {$data['areas_created']} areas but had some issues. Please review.",
            'nothing_to_build' => 'No blueprints, photos, or notes found. Upload materials first.',
            'unreadable_blueprints' => "Couldn't extract text from the blueprints. They may need manual entry.",
            'failed' => $data['error_message'] ?? 'AI build encountered an error.',
            default => 'Build finished with unknown status.',
        };

        if (in_array($data['status'], ['completed', 'partial'])) {
            $this->redirect(route('estimates.edit', $this->estimate), navigate: true);
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

                {{-- Unified Paint Selection --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900">
                    <div class="p-4 border-b border-zinc-100 dark:border-zinc-800">
                        <div class="flex items-center justify-between">
                            <div>
                                <flux:heading size="sm">{{ __('Paint Selection') }}</flux:heading>
                                <flux:text size="sm" class="text-zinc-500">{{ __('Choose paint products for this estimate') }}</flux:text>
                            </div>
                            @if ($paintMode === 'category' && $this->paintOverrideCount > 0)
                                <span class="px-2.5 py-1 text-xs font-semibold rounded-full bg-blue-100 dark:bg-blue-900/50 text-blue-700 dark:text-blue-300">
                                    {{ $this->paintOverrideCount }} {{ __('custom') }}
                                </span>
                            @endif
                        </div>
                    </div>

                    <div class="p-4">
                        {{-- Paint Mode Toggle --}}
                        <div class="flex flex-col sm:flex-row gap-3 mb-4">
                            <label class="flex items-center gap-3 p-3 rounded-lg border-2 cursor-pointer transition-all {{ $paintMode === 'single' ? 'border-blue-500 bg-blue-50 dark:bg-blue-950/30' : 'border-zinc-200 dark:border-zinc-700 hover:border-zinc-300 dark:hover:border-zinc-600' }}">
                                <input
                                    type="radio"
                                    wire:model.live="paintMode"
                                    value="single"
                                    class="text-blue-600 focus:ring-blue-500"
                                />
                                <div>
                                    <div class="font-medium text-zinc-900 dark:text-zinc-100">{{ __('Same paint for all surfaces') }}</div>
                                    <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Use one paint product across the entire estimate') }}</div>
                                </div>
                            </label>
                            <label class="flex items-center gap-3 p-3 rounded-lg border-2 cursor-pointer transition-all {{ $paintMode === 'category' ? 'border-blue-500 bg-blue-50 dark:bg-blue-950/30' : 'border-zinc-200 dark:border-zinc-700 hover:border-zinc-300 dark:hover:border-zinc-600' }}">
                                <input
                                    type="radio"
                                    wire:model.live="paintMode"
                                    value="category"
                                    class="text-blue-600 focus:ring-blue-500"
                                />
                                <div>
                                    <div class="font-medium text-zinc-900 dark:text-zinc-100">{{ __('Different paint per surface type') }}</div>
                                    <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('Customize paint for walls, ceiling, trim, doors') }}</div>
                                </div>
                            </label>
                        </div>

                        {{-- Single Paint Mode --}}
                        @if ($paintMode === 'single')
                            <div class="space-y-3">
                                <flux:select wire:model.live="defaultPaintProductId" :placeholder="__('Select paint product...')">
                                    <flux:select.option value="">{{ __('No default (use production rate material cost)') }}</flux:select.option>
                                    @foreach ($this->availablePaintProducts as $product)
                                        <flux:select.option value="{{ $product->id }}">
                                            {{ $product->brand?->name ?? 'Other' }} - {{ $product->name }} ({{ ucfirst($product->sheen) }}) - ${{ number_format($product->price_per_gallon, 2) }}/gal
                                        </flux:select.option>
                                    @endforeach
                                </flux:select>

                                @if ($this->selectedPaintProduct)
                                    <div class="p-3 bg-blue-50 dark:bg-blue-950/30 rounded-lg text-sm">
                                        <div class="flex items-center gap-3">
                                            <div class="flex-1">
                                                <span class="font-medium text-blue-800 dark:text-blue-200">{{ $this->selectedPaintProduct->full_name }}</span>
                                                <span class="text-blue-600 dark:text-blue-400 ml-2">{{ ucfirst($this->selectedPaintProduct->sheen) }}</span>
                                            </div>
                                            <div class="text-right text-blue-700 dark:text-blue-300">
                                                <div>Coverage: {{ $this->selectedPaintProduct->coverage_sqft_brush_roll }} sqft/gal</div>
                                                <div>${{ number_format($this->selectedPaintProduct->price_per_gallon, 2) }}/gallon</div>
                                            </div>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        @else
                            {{-- Category Paint Mode --}}
                            <div class="space-y-3">
                                <div class="grid sm:grid-cols-2 gap-3">
                                    @foreach ($this->paintSummaryByCategory as $categoryKey => $categoryData)
                                        <div class="p-3 bg-zinc-50 dark:bg-zinc-800/50 rounded-lg">
                                            <div class="flex items-center justify-between mb-2">
                                                <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $categoryData['label'] }}</span>
                                                @if ($categoryData['is_override'])
                                                    <span class="text-xs text-blue-600 dark:text-blue-400">({{ __('Custom') }})</span>
                                                @elseif ($categoryData['is_company_default'])
                                                    <span class="text-xs text-green-600 dark:text-green-400">({{ __('Default') }})</span>
                                                @endif
                                            </div>
                                            <select
                                                wire:change="updatePaintForCategory('{{ $categoryKey }}', $event.target.value)"
                                                class="w-full text-sm bg-white dark:bg-zinc-700 border border-zinc-300 dark:border-zinc-600 rounded-md py-1.5 px-2 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                                            >
                                                <option value="">
                                                    @php
                                                        $companyDefault = \App\Models\CompanySetting::getInstance()->getDefaultPaintForCategory($categoryKey, $projectScope);
                                                    @endphp
                                                    @if ($companyDefault)
                                                        {{ __('Company Default') }}
                                                    @else
                                                        {{ __('Not Set') }}
                                                    @endif
                                                </option>
                                                @foreach ($this->availablePaintProducts as $product)
                                                    <option value="{{ $product->id }}" {{ ($categoryData['paint_product_id'] ?? null) == $product->id && $categoryData['is_override'] ? 'selected' : '' }}>
                                                        {{ $product->brand?->name ?? 'Other' }} - {{ $product->name }} (${{ number_format($product->price_per_gallon, 2) }})
                                                    </option>
                                                @endforeach
                                            </select>
                                            @if ($categoryData['price'])
                                                <div class="text-xs text-zinc-500 dark:text-zinc-400 mt-1">
                                                    ${{ number_format($categoryData['price'], 2) }}/gal · {{ $categoryData['product']?->coverage_sqft_brush_roll ?? 400 }} sqft coverage
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>

                                @if ($this->paintOverrideCount > 0)
                                    <div class="pt-2 border-t border-zinc-100 dark:border-zinc-800">
                                        <button
                                            wire:click="$set('paintByCategory', [])"
                                            type="button"
                                            class="text-sm text-zinc-500 dark:text-zinc-400 hover:text-red-600 dark:hover:text-red-400"
                                        >
                                            {{ __('Reset to company defaults') }}
                                        </button>
                                    </div>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>

                {{-- ADD AREAS Section (New Design) --}}
                <div class="border-2 border-green-200 dark:border-green-800 rounded-lg p-4 bg-gradient-to-r from-green-50 to-white dark:from-green-950/30 dark:to-zinc-900">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                        <div>
                            <flux:heading size="sm" class="text-green-800 dark:text-green-200">{{ __('Add Areas') }}</flux:heading>
                            <flux:text size="sm" class="text-green-600 dark:text-green-400">{{ __('Add rooms with full dimension and surface control') }}</flux:text>
                        </div>
                        <div class="flex gap-2">
                            <flux:button wire:click="openTemplatePicker" variant="primary" icon="squares-2x2">
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
                                                        wire:click="editArea({{ $index }})"
                                                        variant="ghost"
                                                        size="sm"
                                                        icon="pencil"
                                                        class="opacity-60 group-hover:opacity-100"
                                                    />
                                                    <flux:button
                                                        wire:click="removeArea({{ $index }})"
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
                                                </div>
                                                <div class="flex flex-wrap items-center gap-x-2 text-sm text-zinc-500 dark:text-zinc-400">
                                                    {{-- Dimension inputs if area_data exists --}}
                                                    @if (!empty($templateData['area_data']))
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
                                                            value="{{ $areaData['height'] }}"
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
                                                                {{ number_format($templateData['labor_hours'], 1) }} {{ __('hrs') }} × ${{ number_format(\App\Models\CompanySetting::getInstance()->getEffectiveHourlyRateForScope($templateData['scope_type'] ?? 'interior'), 2) }}
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
                                    <flux:input wire:model.live="taxRate" type="number" step="0.01" min="0" max="100" placeholder="0" class="flex-1" />
                                    <span class="text-zinc-500 dark:text-zinc-400 text-sm font-medium">%</span>
                                </div>
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Discount') }}</flux:label>
                                <div class="flex items-center gap-2">
                                    <span class="text-zinc-500 dark:text-zinc-400 text-sm font-medium">$</span>
                                    <flux:input wire:model.live="discountAmount" type="number" step="0.01" min="0" placeholder="0" class="flex-1" />
                                </div>
                            </flux:field>
                            <flux:field>
                                <flux:label>{{ __('Margin Discount') }}</flux:label>
                                <div class="flex items-center gap-2">
                                    <flux:input wire:model.live.debounce.300ms="marginDiscountPercent" type="number" step="0.5" min="0" max="{{ $this->maxAllowableDiscount }}" placeholder="0" class="flex-1" />
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
                                <flux:label>{{ __('Deposit Required') }}</flux:label>
                                <div class="flex items-center gap-2">
                                    <flux:input wire:model.live="depositPercent" type="number" step="1" min="0" max="100" placeholder="30" class="flex-1" />
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
                                    <dt class="text-zinc-600 dark:text-zinc-400">{{ __('Tax') }} ({{ number_format($taxRate, 1) }}%)</dt>
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
                                    <dt>{{ __('Deposit') }} ({{ number_format($depositPercent, 0) }}%)</dt>
                                    <dd class="font-medium text-zinc-900 dark:text-zinc-100">${{ number_format($this->depositAmount, 2) }}</dd>
                                </div>
                                <div class="flex justify-between text-zinc-600 dark:text-zinc-400">
                                    <dt>{{ __('Balance Due') }}</dt>
                                    <dd class="font-medium text-zinc-900 dark:text-zinc-100">${{ number_format($this->balanceDue, 2) }}</dd>
                                </div>
                            </div>
                        </dl>
                    </div>

                    {{-- AI Build Button --}}
                    @if (config('ai-build.enabled') && $isEditing)
                        <div class="mb-4 p-3 bg-gradient-to-r from-purple-50 to-blue-50 dark:from-purple-950/30 dark:to-blue-950/30 rounded-lg border border-purple-200 dark:border-purple-800">
                            @if ($aiBuildInProgress)
                                <div wire:poll.5s="pollAiBuildStatus" class="flex items-center gap-2 text-sm text-purple-700 dark:text-purple-300">
                                    <flux:icon name="arrow-path" class="w-4 h-4 animate-spin" />
                                    <span>{{ $aiBuildMessage ?? 'AI is working...' }}</span>
                                </div>
                            @elseif ($aiBuildMessage)
                                <div class="text-sm mb-2 {{ $aiBuildStatus === 'failed' || str_contains($aiBuildStatus ?? '', 'nothing') ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}">
                                    {{ $aiBuildMessage }}
                                </div>
                                <flux:button wire:click="triggerAiBuild" size="sm" class="w-full" variant="primary">
                                    <flux:icon name="sparkles" class="w-4 h-4 mr-1" />
                                    {{ __('Retry AI Build') }}
                                </flux:button>
                            @else
                                <flux:button wire:click="triggerAiBuild" wire:loading.attr="disabled" size="sm" class="w-full" variant="primary">
                                    <flux:icon name="sparkles" class="w-4 h-4 mr-1" />
                                    <span wire:loading.remove wire:target="triggerAiBuild">{{ $estimate->areas()->count() > 0 ? __('AI Rebuild from Blueprints') : __('AI Build from Blueprints') }}</span>
                                    <span wire:loading wire:target="triggerAiBuild">{{ __('Starting...') }}</span>
                                </flux:button>
                                <p class="text-xs text-purple-600 dark:text-purple-400 mt-1 text-center">
                                    {{ __('Reads blueprints & photos to auto-build estimate') }}
                                </p>
                            @endif
                        </div>
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

    {{-- Area Template Picker Modal --}}
    <flux:modal wire:model="showTemplatePicker" name="template-picker-modal" class="max-w-3xl" x-data="{ pickerTab: 'areas' }">
        <div class="p-6">
            <flux:heading size="lg" class="mb-4">
                {{ __('Add to Estimate') }}
            </flux:heading>

            {{-- Tabs for Area Templates vs Fixed Price --}}
            <div class="flex gap-2 mb-4 border-b border-zinc-200 dark:border-zinc-700">
                <button
                    @click="pickerTab = 'areas'"
                    type="button"
                    :class="pickerTab === 'areas' ? 'border-blue-500 text-blue-600 dark:text-blue-400' : 'border-transparent text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-300'"
                    class="px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors"
                >
                    {{ __('Area Templates') }}
                    <span class="ml-1.5 px-1.5 py-0.5 text-xs rounded-full bg-zinc-100 dark:bg-zinc-800">
                        {{ $this->pickerTemplates->count() }}
                    </span>
                </button>
                <button
                    @click="pickerTab = 'fixed'"
                    type="button"
                    :class="pickerTab === 'fixed' ? 'border-blue-500 text-blue-600 dark:text-blue-400' : 'border-transparent text-zinc-500 dark:text-zinc-400 hover:text-zinc-700 dark:hover:text-zinc-300'"
                    class="px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors"
                >
                    {{ __('Fixed Price') }}
                    <span class="ml-1.5 px-1.5 py-0.5 text-xs rounded-full bg-zinc-100 dark:bg-zinc-800">
                        {{ $this->fixedPriceTemplates->count() }}
                    </span>
                </button>
            </div>

            {{-- Area Templates Tab --}}
            <div x-show="pickerTab === 'areas'" x-cloak>
                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400 mb-4">
                    {{ __('Choose an area template with full dimension and surface control.') }}
                </flux:text>

                @if ($this->pickerTemplates->isEmpty())
                    <div class="text-center py-8 text-zinc-500 dark:text-zinc-400">
                        <flux:icon name="squares-2x2" class="w-12 h-12 mx-auto mb-3 text-zinc-300 dark:text-zinc-600" />
                        <p class="font-medium">{{ __('No area templates available') }}</p>
                        <p class="text-sm mt-1">{{ __('Create area templates in Area Templates first, or add a custom area.') }}</p>
                    </div>
                @else
                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-3 max-h-[50vh] overflow-y-auto">
                        @foreach ($this->pickerTemplates as $template)
                            <button
                                wire:click="addAreaFromTemplate({{ $template->id }})"
                                wire:key="picker-template-{{ $template->id }}"
                                type="button"
                                class="p-4 border-2 border-zinc-200 dark:border-zinc-700 rounded-xl hover:border-blue-500 hover:shadow-md dark:hover:bg-zinc-800/50 transition-all text-left group active:scale-[0.98]"
                            >
                                <div class="flex items-center gap-3 mb-2">
                                    <div class="flex-shrink-0 w-10 h-10 bg-gradient-to-br from-blue-100 to-blue-50 dark:from-blue-900/30 dark:to-blue-800/20 rounded-xl flex items-center justify-center group-hover:from-blue-200 group-hover:to-blue-100 transition-colors">
                                        <flux:icon name="{{ $template->icon ?? 'squares-2x2' }}" class="w-5 h-5 text-blue-600 dark:text-blue-400" />
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="font-semibold text-sm text-zinc-900 dark:text-zinc-100 truncate">
                                            {{ __($template->name) }}
                                        </div>
                                        <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                            {{ __($template->templateCategory?->name ?? 'Room') }}
                                        </div>
                                    </div>
                                </div>
                                @if ($template->description)
                                    <p class="text-xs text-zinc-500 dark:text-zinc-400 line-clamp-2">
                                        {{ $template->description }}
                                    </p>
                                @endif
                            </button>
                        @endforeach
                    </div>
                @endif
            </div>

            {{-- Fixed Price Tab --}}
            <div x-show="pickerTab === 'fixed'" x-cloak>
                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400 mb-4">
                    {{ __('Add fixed-price items like closets, pantries, and exterior features.') }}
                </flux:text>

                @if ($this->fixedPriceTemplates->isEmpty())
                    <div class="text-center py-8 text-zinc-500 dark:text-zinc-400">
                        <flux:icon name="currency-dollar" class="w-12 h-12 mx-auto mb-3 text-zinc-300 dark:text-zinc-600" />
                        <p class="font-medium">{{ __('No fixed price templates available') }}</p>
                        <p class="text-sm mt-1">{{ __('Create fixed price templates in Production Rates first.') }}</p>
                    </div>
                @else
                    <div class="max-h-[50vh] overflow-y-auto space-y-4">
                        @foreach ($this->fixedPriceTemplatesByCategory as $category => $templates)
                            <div>
                                <div class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 mb-2">
                                    {{ ucfirst($category) }}
                                </div>
                                <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                                    @foreach ($templates as $template)
                                        <button
                                            wire:click="addFixedPriceTemplate({{ $template->id }})"
                                            wire:key="fixed-price-{{ $template->id }}"
                                            type="button"
                                            class="p-3 border-2 border-zinc-200 dark:border-zinc-700 rounded-lg hover:border-emerald-500 hover:shadow-md dark:hover:bg-zinc-800/50 transition-all text-left group active:scale-[0.98]"
                                        >
                                            <div class="flex items-center justify-between gap-2">
                                                <div class="min-w-0">
                                                    <div class="font-medium text-sm text-zinc-900 dark:text-zinc-100 truncate">
                                                        {{ __($template->name) }}
                                                    </div>
                                                    <div class="text-xs text-zinc-500 dark:text-zinc-400 font-mono">
                                                        {{ $template->code }}
                                                    </div>
                                                </div>
                                                <div class="flex-shrink-0 text-right">
                                                    <div class="font-bold text-emerald-600 dark:text-emerald-400">
                                                        {{ $template->formatted_price }}
                                                    </div>
                                                </div>
                                            </div>
                                        </button>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            <div class="flex justify-end gap-3 mt-6 pt-4 border-t border-zinc-200 dark:border-zinc-700">
                <flux:button wire:click="closeTemplatePicker" variant="ghost">
                    {{ __('Cancel') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>

    {{-- Area Builder Component (Flyout) --}}
    <livewire:estimates.area-builder />
</div>
