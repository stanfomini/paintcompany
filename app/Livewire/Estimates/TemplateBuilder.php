<?php

namespace App\Livewire\Estimates;

use App\Models\CompanySetting;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateTemplate;
use App\Models\EstimateTemplateCategory;
use App\Models\PaintProduct;
use App\Models\ProductionRate;
use App\Models\Substrate;
use App\Services\EstimateTemplateService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

class TemplateBuilder extends Component
{
    public ?Deal $deal = null;

    public ?Estimate $estimate = null;

    public bool $isEditing = false;

    public string $title = '';

    /** @var array<int, array{template_id: int, name: string, scope_type: string, quantity: float, coats: int, price: float, default_quantity: float, pricing_method: string, icon: ?string, labor_hours: float, labor_cost: float, cleanup_hours: float, cleanup_cost: float, sundries_cost: float, material_cost: float, base_total: float, with_margins: float}> */
    public array $selectedTemplates = [];

    public float $taxRate = 0;

    public float $discountAmount = 0;

    public float $depositPercent = 30;

    public string $notes = '';

    public ?string $validUntil = null;

    public string $activeTab = 'room';

    /**
     * Project scope (interior/exterior) - primary filter for the entire estimate.
     * When changed, filters all templates and removes non-matching selections.
     */
    public string $projectScope = 'interior';

    public string $scopeFilter = 'interior';

    // Modal state
    public bool $showAdjustmentModal = false;

    public ?int $adjustingTemplateIndex = null;

    public float $adjustQuantity = 0;

    public int $adjustCoats = 2;

    public bool $useSlider = true;

    protected function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'selectedTemplates' => ['required', 'array', 'min:1'],
            'selectedTemplates.*.template_id' => ['required', 'integer', 'exists:estimate_templates,id'],
            'selectedTemplates.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'selectedTemplates.*.coats' => ['required', 'integer', 'min:1', 'max:4'],
            'selectedTemplates.*.price' => ['required', 'numeric', 'min:0'],
            'taxRate' => ['required', 'numeric', 'min:0', 'max:100'],
            'discountAmount' => ['required', 'numeric', 'min:0'],
            'depositPercent' => ['required', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'validUntil' => ['nullable', 'date', 'after_or_equal:today'],
        ];
    }

    protected function messages(): array
    {
        return [
            'title.required' => 'Please enter a title for the estimate.',
            'selectedTemplates.required' => 'Please select at least one template.',
            'selectedTemplates.min' => 'Please select at least one template.',
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

        if ($estimate && $estimate->exists && $estimate->isTemplateMode()) {
            $this->estimate = $estimate;
            $this->deal = $estimate->deal;
            $this->isEditing = true;

            $this->title = $estimate->title;
            $this->taxRate = (float) $estimate->tax_rate;
            $this->discountAmount = (float) $estimate->discount_amount;
            $this->depositPercent = (float) $estimate->deposit_percent;
            $this->notes = $estimate->notes ?? '';
            $this->validUntil = $estimate->valid_until?->format('Y-m-d');

            // Load existing template usages
            $this->loadExistingTemplateUsages();

            // Detect project scope from existing templates
            $this->detectProjectScopeFromTemplates();
        } else {
            $this->deal = $deal;
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

        foreach ($this->estimate->templateUsages()->with(['template.templateCategory', 'template.productionRate'])->get() as $usage) {
            $template = $usage->template;
            if (! $template) {
                continue;
            }

            $breakdown = $this->getTemplateCostBreakdown($template->id, (float) $usage->quantity, $usage->coats);

            $this->selectedTemplates[] = [
                'template_id' => $template->id,
                'name' => $template->name,
                'scope_type' => $template->scope_type ?? 'interior',
                'quantity' => (float) $usage->quantity,
                'coats' => $usage->coats,
                'price' => (float) $usage->calculated_price,
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
            ];
        }
    }

    /**
     * Handle project scope changes.
     * Filters out templates that don't match the new scope and syncs scopeFilter.
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
     * Get quick add templates for the current project scope.
     * Returns common room templates for one-click adding.
     *
     * @return \Illuminate\Support\Collection<int, EstimateTemplate>
     */
    public function getQuickAddTemplatesProperty(): Collection
    {
        $quickAddNames = $this->projectScope === 'exterior'
            ? ['House Exterior', 'Garage', 'Deck', 'Fence', 'Porch']
            : ['Bedroom', 'Bathroom', 'Kitchen', 'Living Room', 'Dining Room', 'Hallway'];

        $templates = EstimateTemplate::query()
            ->active()
            ->whereIn('name', $quickAddNames)
            ->whereIn('scope_type', [$this->projectScope, EstimateTemplate::SCOPE_TYPE_BOTH])
            ->with('productionRate')
            ->get();

        // Sort in the order specified by quickAddNames (database-agnostic)
        $order = array_flip($quickAddNames);

        return $templates->sortBy(fn ($t) => $order[$t->name] ?? 999);
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
     * 8. with_margins = base_total × (1+profit%) × (1+overhead%) × (1+scope_markup%)
     *
     * @param  array<string, mixed>  $options  Optional overrides: substrate_id, paint_product_id
     * @return array{labor_hours: float, labor_cost: float, cleanup_hours: float, cleanup_cost: float, sundries_cost: float, material_cost: float, base_total: float, with_margins: float, surfaces?: array, substrate_id?: int, paint_product_id?: int}
     */
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
        $effectiveHourlyRate = $settings->getEffectiveHourlyRateForScope($scopeType);

        // Get substrate from options or template default
        $substrateId = $options['substrate_id'] ?? $template->substrate_id;
        $paintProductId = $options['paint_product_id'] ?? $template->default_paint_product_id;

        $surfaceOptions = [
            'substrate_id' => $substrateId,
            'paint_product_id' => $paintProductId,
        ];

        $totalLaborHours = 0;
        $totalLaborCost = 0;
        $totalMaterialCost = 0;
        $surfaces = [];

        foreach ($surfaceRatios as $surfaceName => $surfaceConfig) {
            $surfaceResult = $this->calculateSurfaceCost(
                $surfaceName,
                $surfaceConfig,
                $roomSqft,
                $coats,
                $effectiveHourlyRate,
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
        $cleanupCost = $cleanupHours * $effectiveHourlyRate;
        $sundriesCost = $settings->calculateSundriesCost($totalLaborHours, $scopeType);

        // Calculate base total (before margins)
        $baseTotal = $totalLaborCost + $cleanupCost + $sundriesCost + $totalMaterialCost;

        // Apply scope-specific margins
        $withMargins = $settings->calculateWithMarginsForScope($baseTotal, $scopeType);

        return [
            'labor_hours' => round($totalLaborHours, 2),
            'labor_cost' => round($totalLaborCost, 2),
            'cleanup_hours' => round($cleanupHours, 2),
            'cleanup_cost' => round($cleanupCost, 2),
            'sundries_cost' => round($sundriesCost, 2),
            'material_cost' => round($totalMaterialCost, 2),
            'base_total' => round($baseTotal, 2),
            'with_margins' => round($withMargins, 2),
            'surfaces' => $surfaces,
            'substrate_id' => $substrateId,
            'paint_product_id' => $paintProductId,
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

        // Get fallback material cost from production rate
        $fallbackMaterialCost = $rate ? (float) ($rate->material_cost_per_unit ?? 0) : null;

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
        // Try to get paint product
        $paintProduct = $paintProductId ? PaintProduct::find($paintProductId) : null;

        if (! $paintProduct) {
            // Fallback to production rate's material_cost_per_unit if available
            if ($fallbackMaterialCostPerUnit !== null && $fallbackMaterialCostPerUnit > 0) {
                return $quantity * $fallbackMaterialCostPerUnit * $coatMultiplier;
            }

            // Last resort: per-sqft approximation
            return $quantity * 0.15 * $coats;
        }

        // Calculate gallons needed
        $coverage = (float) ($paintProduct->coverage_sqft_per_gallon ?? 400);
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
     * Now includes substrate multipliers and paint product pricing.
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
        // Handle templates without production rates (fixed price, per sqft, etc.)
        if (! $template->productionRate) {
            $price = $template->calculatePrice($quantity, $coats);

            return [
                'labor_hours' => 0,
                'labor_cost' => 0,
                'cleanup_hours' => 0,
                'cleanup_cost' => 0,
                'sundries_cost' => 0,
                'material_cost' => 0,
                'base_total' => $price,
                'with_margins' => $price,
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

        // 2. Get scope-specific effective hourly rate (wage × payroll burden)
        $effectiveHourlyRate = $settings->getEffectiveHourlyRateForScope($scopeType);

        // 3. Calculate labor cost
        $laborCost = $laborHours * $effectiveHourlyRate;

        // 4. Calculate cleanup hours and cost
        $cleanupHours = $settings->calculateCleanupHours($laborHours, $scopeType);
        $cleanupCost = $cleanupHours * $effectiveHourlyRate;

        // 5. Calculate sundries cost
        $sundriesCost = $settings->calculateSundriesCost($laborHours, $scopeType);

        // 6. Calculate material cost from PaintProduct if available, else use production rate fallback
        $fallbackMaterialCost = (float) ($rate->material_cost_per_unit ?? 0);
        $materialCost = $this->calculateMaterialCostForSurface(
            $quantity,
            $coats,
            $paintProductId,
            $substrate,
            $settings,
            $fallbackMaterialCost,
            $coatMultiplier
        );

        // 7. Calculate base total (before margins)
        $baseTotal = $laborCost + $cleanupCost + $sundriesCost + $materialCost;

        // 8. Apply scope-specific margins (profit × overhead × markup)
        $withMargins = $settings->calculateWithMarginsForScope($baseTotal, $scopeType);

        return [
            'labor_hours' => round($laborHours, 2),
            'labor_cost' => round($laborCost, 2),
            'cleanup_hours' => round($cleanupHours, 2),
            'cleanup_cost' => round($cleanupCost, 2),
            'sundries_cost' => round($sundriesCost, 2),
            'material_cost' => round($materialCost, 2),
            'base_total' => round($baseTotal, 2),
            'with_margins' => round($withMargins, 2),
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
            'name' => $template->name,
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
            // Size options for quick selection
            'sizes' => $sizes,
            'selected_size' => $defaultSize,
            'surface_ratios' => $surfaceRatios,
            // Per-surface breakdown (for expandable details)
            'surfaces' => $breakdown['surfaces'] ?? null,
            // Substrate and paint product (from template defaults, can be overridden per-room)
            'substrate_id' => $breakdown['substrate_id'] ?? $template->substrate_id,
            'paint_product_id' => $breakdown['paint_product_id'] ?? $template->default_paint_product_id,
            'substrate_name' => $template->substrate?->name,
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
     * Open the adjustment modal for a template.
     */
    public function openAdjustment(int $index): void
    {
        if (! isset($this->selectedTemplates[$index])) {
            return;
        }

        $this->adjustingTemplateIndex = $index;
        $this->adjustQuantity = $this->selectedTemplates[$index]['quantity'];
        $this->adjustCoats = $this->selectedTemplates[$index]['coats'];
        $this->showAdjustmentModal = true;
    }

    /**
     * Close the adjustment modal.
     */
    public function closeAdjustment(): void
    {
        $this->showAdjustmentModal = false;
        $this->adjustingTemplateIndex = null;
        $this->adjustQuantity = 0;
        $this->adjustCoats = 2;
    }

    /**
     * Save the adjustment for the current template.
     */
    public function saveAdjustment(): void
    {
        if ($this->adjustingTemplateIndex === null || ! isset($this->selectedTemplates[$this->adjustingTemplateIndex])) {
            $this->closeAdjustment();

            return;
        }

        $this->validate([
            'adjustQuantity' => ['required', 'numeric', 'min:0.01'],
            'adjustCoats' => ['required', 'integer', 'min:1', 'max:4'],
        ]);

        $templateData = $this->selectedTemplates[$this->adjustingTemplateIndex];
        $template = EstimateTemplate::find($templateData['template_id']);

        if (! $template) {
            $this->closeAdjustment();

            return;
        }

        // Pass current substrate and paint product overrides
        $options = [
            'substrate_id' => $templateData['substrate_id'] ?? null,
            'paint_product_id' => $templateData['paint_product_id'] ?? null,
        ];

        $breakdown = $this->getTemplateCostBreakdown($templateData['template_id'], $this->adjustQuantity, $this->adjustCoats, $options);

        $this->selectedTemplates[$this->adjustingTemplateIndex]['quantity'] = $this->adjustQuantity;
        $this->selectedTemplates[$this->adjustingTemplateIndex]['coats'] = $this->adjustCoats;
        $this->selectedTemplates[$this->adjustingTemplateIndex]['price'] = $breakdown['with_margins']; // Use PBP calculated price
        $this->selectedTemplates[$this->adjustingTemplateIndex]['labor_hours'] = $breakdown['labor_hours'];
        $this->selectedTemplates[$this->adjustingTemplateIndex]['labor_cost'] = $breakdown['labor_cost'];
        $this->selectedTemplates[$this->adjustingTemplateIndex]['cleanup_hours'] = $breakdown['cleanup_hours'];
        $this->selectedTemplates[$this->adjustingTemplateIndex]['cleanup_cost'] = $breakdown['cleanup_cost'];
        $this->selectedTemplates[$this->adjustingTemplateIndex]['sundries_cost'] = $breakdown['sundries_cost'];
        $this->selectedTemplates[$this->adjustingTemplateIndex]['material_cost'] = $breakdown['material_cost'];
        $this->selectedTemplates[$this->adjustingTemplateIndex]['base_total'] = $breakdown['base_total'];
        $this->selectedTemplates[$this->adjustingTemplateIndex]['with_margins'] = $breakdown['with_margins'];
        $this->selectedTemplates[$this->adjustingTemplateIndex]['surfaces'] = $breakdown['surfaces'] ?? null;

        $this->closeAdjustment();
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

    /**
     * Calculate the subtotal from all selected templates.
     */
    public function getSubtotalProperty(): float
    {
        return array_sum(array_column($this->selectedTemplates, 'price'));
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

        $estimateData = [
            'deal_id' => $this->deal->id,
            'title' => $this->title,
            'estimator_mode' => Estimate::MODE_TEMPLATE,
            'subtotal' => $this->subtotal,
            'tax_rate' => $this->taxRate,
            'tax_amount' => $this->taxAmount,
            'discount_amount' => $this->discountAmount,
            'total' => $this->total,
            'deposit_percent' => $this->depositPercent,
            'deposit_amount' => $this->depositAmount,
            'balance_due' => $this->balanceDue,
            'notes' => $this->notes ?: null,
            'valid_until' => $this->validUntil ?: null,
        ];

        $templateService = app(EstimateTemplateService::class);

        if ($this->isEditing) {
            $this->estimate->update($estimateData);

            // Remove existing template usages
            $this->estimate->templateUsages()->delete();

            // Remove existing areas and items created from templates
            $this->estimate->areas()->delete();
            $this->estimate->items()->delete();
            $this->estimate->optionalItems()->delete();

            // Apply templates
            $this->applyTemplatesToEstimate($templateService);

            if ($markAsSent && $this->estimate->status === 'draft') {
                $this->estimate->markAsSent();
            }

            $this->dispatch('estimate-updated');
            session()->flash('success', 'Estimate updated successfully.');
        } else {
            $estimateData['created_by'] = auth()->id();
            $estimateData['status'] = 'draft'; // Always create as draft first

            $this->estimate = Estimate::create($estimateData);

            // Apply templates
            $this->applyTemplatesToEstimate($templateService);

            // Now mark as sent if requested (this generates the public token)
            if ($markAsSent) {
                $this->estimate->markAsSent();
            }

            $this->dispatch('estimate-created');
            session()->flash('success', 'Estimate created successfully.');
        }

        $this->redirect(route('estimates.show', $this->estimate), navigate: true);
    }

    /**
     * Apply selected templates to the estimate using the service.
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

    public function render(): View
    {
        $pageTitle = $this->isEditing
            ? 'Edit Template Estimate: '.$this->estimate->estimate_number
            : 'Create Template Estimate';

        return view('livewire.estimates.template-builder', [
            'categoryTypes' => EstimateTemplateCategory::TYPES,
        ])->layout('layouts.app', ['title' => $pageTitle]);
    }
}
