<?php

namespace App\Services;

use App\Events\EstimateAreaUpdated;
use App\Models\CompanySetting;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaAddon;
use App\Models\EstimateAreaSurface;
use App\Models\EstimateCostItem;
use App\Models\EstimateTemplate;
use App\Models\EstimateTemplateCategory;
use App\Models\NestedAreaTemplate;
use App\Models\NestedTemplateSurface;
use App\Models\PaintProduct;
use App\Models\ProductionRate;
use App\Models\ProductionRateCategory;
use App\Models\Substrate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EstimateAiService
{
    // =========================================================================
    // DISCOVERY METHODS
    // =========================================================================

    /**
     * Search for matching templates by name/keywords.
     *
     * @return array<int, array{id: int, name: string, scope_type: string, category: string|null, sizes: array, surfaces: array, description: string|null}>
     */
    public function searchTemplates(string $query, ?string $scopeType = null): array
    {
        $q = EstimateTemplate::query()
            ->active()
            ->configurable()
            ->with(['templateCategory', 'surfaces.productionRate'])
            ->where(function ($builder) use ($query) {
                $builder->where('name', 'like', "%{$query}%")
                    ->orWhere('description', 'like', "%{$query}%")
                    ->orWhereHas('templateCategory', function ($catQ) use ($query) {
                        $catQ->where('name', 'like', "%{$query}%");
                    });
            });

        if ($scopeType) {
            $q->byScope($scopeType);
        }

        return $q->limit(10)->get()->map(function (EstimateTemplate $template) {
            $metadata = $template->metadata ?? [];
            $dimensions = $metadata['dimensions'] ?? [];

            return [
                'id' => $template->id,
                'name' => $template->name,
                'scope_type' => $template->scope_type,
                'category' => $template->templateCategory?->name,
                'sizes' => $dimensions,
                'surfaces' => $template->surfaces->map(fn ($s) => [
                    'name' => $s->name,
                    'surface_type' => $s->surface_type,
                    'production_rate' => $s->productionRate?->name,
                    'production_rate_id' => $s->production_rate_id,
                ])->toArray(),
                'surface_ratios' => $metadata['surface_ratios'] ?? null,
                'description' => $template->description,
            ];
        })->toArray();
    }

    /**
     * List production rates, optionally filtered by scope and category.
     *
     * @return array<int, array{id: int, name: string, surface_category: string, scope: string, production_rate: float, unit_type: string, rate_type: string, default_coats: int}>
     */
    public function listProductionRates(?string $scopeType = null, ?string $category = null): array
    {
        $q = ProductionRate::query()->orderBy('scope')->orderBy('surface_category')->orderBy('name');

        if ($scopeType) {
            $q->where('scope', $scopeType);
        }

        if ($category) {
            $q->where('surface_category', $category);
        }

        return $q->get()->map(fn (ProductionRate $rate) => [
            'id' => $rate->id,
            'name' => $rate->name,
            'surface_category' => $rate->surface_category,
            'scope' => $rate->scope,
            'production_rate' => (float) $rate->production_rate,
            'unit_type' => $rate->unit_type,
            'rate_type' => $rate->rate_type,
            'default_coats' => $rate->default_coats ?? 2,
        ])->toArray();
    }

    /**
     * List paint products, optionally filtered.
     *
     * @return array<int, array{id: int, brand_name: string, name: string, sheen: string|null, quality_tier: string|null, price_per_gallon: float, coverage_sqft: float, scope_type: string}>
     */
    public function listPaintProducts(?string $scopeType = null, ?string $sheen = null, ?string $qualityTier = null): array
    {
        $q = PaintProduct::query()->with('brand')->where('is_active', true)->orderBy('name');

        if ($scopeType) {
            $q->where(fn ($b) => $b->where('scope_type', $scopeType)->orWhere('scope_type', 'both'));
        }

        if ($sheen) {
            $q->where('sheen', $sheen);
        }

        if ($qualityTier) {
            $q->where('quality_tier', $qualityTier);
        }

        return $q->limit(50)->get()->map(fn (PaintProduct $p) => [
            'id' => $p->id,
            'brand_name' => $p->brand?->name ?? 'Unknown',
            'name' => $p->name,
            'sheen' => $p->sheen,
            'quality_tier' => $p->quality_tier,
            'price_per_gallon' => (float) $p->price_per_gallon,
            'coverage_sqft' => (float) ($p->coverage_sqft_brush_roll ?? 400),
            'scope_type' => $p->scope_type,
        ])->toArray();
    }

    /**
     * List substrates (surface conditions).
     *
     * @return array<int, array{id: int, name: string, category: string, prep_time_multiplier: float, paint_time_multiplier: float, material_multiplier: float}>
     */
    public function listSubstrates(?string $scopeType = null): array
    {
        $q = Substrate::query()->where('is_active', true)->orderBy('name');

        if ($scopeType) {
            $q->where(fn ($b) => $b->where('scope_type', $scopeType)->orWhere('scope_type', 'both'));
        }

        return $q->get()->map(fn (Substrate $s) => [
            'id' => $s->id,
            'name' => $s->name,
            'category' => $s->category,
            'scope_type' => $s->scope_type,
            'prep_time_multiplier' => (float) $s->prep_time_multiplier,
            'paint_time_multiplier' => (float) $s->paint_time_multiplier,
            'material_multiplier' => (float) $s->material_multiplier,
        ])->toArray();
    }

    /**
     * List nested area templates (fixed-price addons).
     *
     * @return array<int, array{id: int, name: string, category: string, fixed_price: float|null, estimated_hours: float|null, scope_type: string}>
     */
    public function listNestedTemplates(?string $scopeType = null, ?string $category = null): array
    {
        $q = NestedAreaTemplate::query()->where('is_active', true)->orderBy('name');

        if ($scopeType) {
            $q->where(fn ($b) => $b->where('scope_type', $scopeType)->orWhere('scope_type', 'both'));
        }

        if ($category) {
            $q->where('category', $category);
        }

        return $q->get()->map(fn (NestedAreaTemplate $t) => [
            'id' => $t->id,
            'name' => $t->name,
            'category' => $t->category,
            'fixed_price' => $t->getEffectivePrice(),
            'estimated_hours' => (float) ($t->estimated_hours ?? 0),
            'scope_type' => $t->scope_type,
        ])->toArray();
    }

    /**
     * Get company pricing config by scope.
     *
     * @return array{interior: array, exterior: array, formula_explanation: string}
     */
    public function getPricingConfig(): array
    {
        $settings = CompanySetting::getInstance();

        $buildScope = function (string $scope) use ($settings) {
            return [
                'wage' => $settings->getHourlyWageForScope($scope),
                'effective_rate' => $settings->getEffectiveHourlyRateForScope($scope),
                'payroll_burden_multiplier' => (float) $settings->payroll_burden_multiplier,
                'margin_percent' => $settings->getMarginPercentForScope($scope),
                'cleanup_hours_ratio' => $settings->getCleanupHoursRatioForScope($scope),
                'sundries_per_hour' => $settings->getSundriesCostForScope($scope),
                'material_tax_rate' => (float) ($settings->material_tax_rate ?? 0),
                'labor_tax_rate' => (float) ($settings->labor_tax_rate ?? 0),
                'recycle_fee_per_gallon' => (float) ($settings->recycle_fee_per_gallon ?? 0.54),
            ];
        };

        return [
            'interior' => $buildScope('interior'),
            'exterior' => $buildScope('exterior'),
            'formula_explanation' => 'OTD = COGS / (margin% / 100). COGS = labor + cleanup + sundries + materials + taxes.',
        ];
    }

    // =========================================================================
    // ESTIMATE-LEVEL METHODS
    // =========================================================================

    /**
     * Get full estimate state with areas, surfaces, and pricing.
     */
    public function getEstimateState(Estimate $estimate): array
    {
        $estimate->load([
            'areas.surfaces.productionRate',
            'areas.surfaces.paintProduct',
            'areas.substrate',
            'areas.addons.nestedAreaTemplate',
            'areas.childAreas',
        ]);

        return [
            'id' => $estimate->id,
            'title' => $estimate->title,
            'status' => $estimate->status,
            'project_type' => $estimate->project_type,
            'estimator_mode' => $estimate->estimator_mode,
            'areas' => $estimate->areas->where('parent_area_id', null)->map(function (EstimateArea $area) {
                return [
                    'id' => $area->id,
                    'name' => $area->name,
                    'scope_type' => $area->scope_type,
                    'dimensions' => [
                        'length' => (float) ($area->length ?? 0),
                        'width' => (float) ($area->width ?? 0),
                        'height' => (float) ($area->height ?? 0),
                        'wall_count' => $area->wall_count ?? 4,
                    ],
                    'calculated' => [
                        'wall_sqft' => (float) ($area->wall_sqft ?? 0),
                        'ceiling_sqft' => (float) ($area->ceiling_sqft ?? 0),
                        'perimeter_lnft' => (float) ($area->perimeter_lnft ?? 0),
                    ],
                    'surfaces' => $area->surfaces->map(fn (EstimateAreaSurface $s) => [
                        'id' => $s->id,
                        'name' => $s->name,
                        'surface_type' => $s->surface_type,
                        'surface_category' => $s->surface_category ?? $s->getSurfaceCategory(),
                        'quantity' => (float) $s->quantity,
                        'unit' => $s->unit,
                        'coats' => $s->coats,
                        'production_rate' => $s->productionRate?->name,
                        'production_rate_id' => $s->production_rate_id,
                        'labor_hours' => (float) ($s->labor_hours ?? 0),
                        'labor_cost' => (float) ($s->labor_cost ?? 0),
                        'material_cost' => (float) ($s->material_cost ?? 0),
                        'paint_product' => $s->paintProduct?->name,
                        'total' => (float) ($s->total ?? 0),
                    ])->toArray(),
                    'addons' => $area->addons->map(fn ($a) => [
                        'id' => $a->id,
                        'name' => $a->nestedAreaTemplate?->name ?? $a->name,
                        'quantity' => $a->quantity,
                        'unit_price' => (float) $a->unit_price,
                        'total_price' => (float) $a->total_price,
                    ])->toArray(),
                    'is_fixed_price' => $area->is_fixed_price,
                    'package_tiers' => $area->package_tiers,
                    'cogs' => $area->getFullCOGS(),
                    'price' => $area->getCustomerPrice(),
                ];
            })->values()->toArray(),
            'totals' => [
                'subtotal' => (float) ($estimate->subtotal ?? 0),
                'tax_amount' => (float) ($estimate->tax_amount ?? 0),
                'total' => (float) ($estimate->total ?? 0),
                'areas_count' => $estimate->areas->where('parent_area_id', null)->count(),
            ],
        ];
    }

    /**
     * Update estimate-level settings.
     */
    public function updateEstimateSettings(Estimate $estimate, array $params): array
    {
        $fillable = ['title', 'project_type', 'notes', 'valid_until'];
        $numericFields = ['tax_rate', 'deposit_percent', 'margin_discount_percent'];

        foreach ($fillable as $field) {
            if (array_key_exists($field, $params)) {
                $estimate->{$field} = $params[$field];
            }
        }

        foreach ($numericFields as $field) {
            if (array_key_exists($field, $params)) {
                $modelField = $field === 'tax_rate' ? 'default_tax_rate' : $field;
                if ($field === 'tax_rate') {
                    $modelField = 'tax_rate';
                }
                $estimate->{$field} = $params[$field];
            }
        }

        $estimate->save();

        return ['success' => true, 'estimate_id' => $estimate->id];
    }

    /**
     * Recalculate all totals on the estimate.
     */
    public function recalculate(Estimate $estimate): array
    {
        $estimate->calculateTotals();
        $estimate->calculateDepositAndBalance();
        $estimate->save();

        return [
            'subtotal' => (float) ($estimate->subtotal ?? 0),
            'tax_amount' => (float) ($estimate->tax_amount ?? 0),
            'total' => (float) ($estimate->total ?? 0),
            'deposit_amount' => (float) ($estimate->deposit_amount ?? 0),
            'balance_due' => (float) ($estimate->balance_due ?? 0),
            'areas_count' => $estimate->areas()->whereNull('parent_area_id')->count(),
        ];
    }

    // =========================================================================
    // AREA CREATION METHODS
    // =========================================================================

    /**
     * Create area from a template (quick path).
     * Loads template → applies size → creates surfaces → calculates pricing.
     */
    public function createAreaFromTemplate(
        Estimate $estimate,
        int $templateId,
        string $size = 'medium',
        int $coats = 2,
        ?int $substrateId = null
    ): array {
        $template = EstimateTemplate::with(['surfaces.productionRate', 'substrate', 'templateCategory'])
            ->findOrFail($templateId);

        $metadata = $template->metadata ?? [];
        $dimensions = $metadata['dimensions'] ?? [];
        $surfaceRatios = $metadata['surface_ratios'] ?? [];
        $sizeConfig = $dimensions[$size] ?? $dimensions['medium'] ?? [];

        $scopeType = $template->scope_type ?? $estimate->project_type ?? 'interior';

        // Create area with dimensions from template
        $area = EstimateArea::create([
            'estimate_id' => $estimate->id,
            'name' => $template->name,
            'scope_type' => $scopeType,
            'length' => $sizeConfig['length'] ?? null,
            'width' => $sizeConfig['width'] ?? null,
            'height' => $sizeConfig['height'] ?? 8,
            'wall_count' => $sizeConfig['wall_count'] ?? 4,
            'substrate_id' => $substrateId ?? $template->substrate_id,
            'measurement_type' => 'dimensions',
            'sort_order' => $estimate->areas()->max('sort_order') + 1,
        ]);

        // Create surfaces from template surfaces or surface_ratios
        $surfaces = [];
        if ($template->hasModelSurfaces()) {
            foreach ($template->surfaces as $templateSurface) {
                $surface = $this->createSurfaceFromTemplate($area, $templateSurface, $coats);
                $surfaces[] = $surface;
            }
        } elseif (! empty($surfaceRatios)) {
            $surfaces = $this->createSurfacesFromRatios($area, $surfaceRatios, $coats);
        }

        // Calculate pricing using TemplatePricingService for cached values
        $pricingService = app(TemplatePricingService::class);
        $roomSqft = (float) ($sizeConfig['sqft'] ?? ($area->length * $area->width));
        $breakdown = $pricingService->getTemplateCostBreakdown($templateId, $roomSqft, $coats, [
            'substrate_id' => $substrateId ?? $template->substrate_id,
        ]);

        // Cache pricing on area
        $area->cached_cogs = $breakdown['base_total'];
        $area->cached_customer_price = $breakdown['with_margins'];
        $area->save();

        // Recalculate estimate totals
        $estimate->calculateTotals();
        $estimate->calculateDepositAndBalance();
        $estimate->save();

        return [
            'area_id' => $area->id,
            'name' => $area->name,
            'scope_type' => $scopeType,
            'size' => $size,
            'dimensions' => [
                'length' => (float) ($area->length ?? 0),
                'width' => (float) ($area->width ?? 0),
                'height' => (float) ($area->height ?? 0),
                'wall_sqft' => (float) ($area->wall_sqft ?? 0),
                'ceiling_sqft' => (float) ($area->ceiling_sqft ?? 0),
                'perimeter_lnft' => (float) ($area->perimeter_lnft ?? 0),
            ],
            'surfaces' => collect($surfaces)->map(fn ($s) => [
                'id' => $s->id,
                'name' => $s->name,
                'quantity' => (float) $s->quantity,
                'unit' => $s->unit,
                'labor_hours' => (float) ($s->labor_hours ?? 0),
            ])->toArray(),
            'cogs' => round($breakdown['base_total'], 2),
            'price' => round($breakdown['with_margins'], 2),
        ];
    }

    /**
     * Create custom area with manual dimensions (no template).
     */
    public function createCustomArea(
        Estimate $estimate,
        string $name,
        string $scopeType,
        float $length,
        float $width,
        float $height,
        int $wallCount = 4,
        ?int $substrateId = null
    ): array {
        $area = EstimateArea::create([
            'estimate_id' => $estimate->id,
            'name' => $name,
            'scope_type' => $scopeType,
            'length' => $length,
            'width' => $width,
            'height' => $height,
            'wall_count' => $wallCount,
            'substrate_id' => $substrateId,
            'measurement_type' => 'dimensions',
            'sort_order' => $estimate->areas()->max('sort_order') + 1,
        ]);

        return [
            'area_id' => $area->id,
            'name' => $area->name,
            'scope_type' => $scopeType,
            'wall_sqft' => (float) ($area->wall_sqft ?? 0),
            'ceiling_sqft' => (float) ($area->ceiling_sqft ?? 0),
            'perimeter_lnft' => (float) ($area->perimeter_lnft ?? 0),
            'surfaces' => [],
            'message' => 'Area created with dimensions. Add surfaces using add_surface_to_area.',
        ];
    }

    // =========================================================================
    // SURFACE-LEVEL METHODS
    // =========================================================================

    /**
     * Add a surface to an area with auto-calculated quantity from area dimensions.
     */
    public function addSurfaceToArea(
        int $areaId,
        string $surfaceCategory,
        int $productionRateId,
        int $coats = 2,
        ?float $quantityOverride = null
    ): array {
        $area = EstimateArea::findOrFail($areaId);
        $rate = ProductionRate::findOrFail($productionRateId);

        // Auto-calculate quantity from area dimensions based on category
        $quantity = $quantityOverride ?? $this->getQuantityForCategory($area, $surfaceCategory);
        $unit = $this->getUnitForCategory($surfaceCategory);
        $surfaceType = $this->getSurfaceTypeForCategory($surfaceCategory);
        $surfaceName = $this->getSurfaceNameForCategory($surfaceCategory);

        $surface = EstimateAreaSurface::create([
            'area_id' => $area->id,
            'name' => $surfaceName,
            'surface_type' => $surfaceType,
            'surface_category' => $surfaceCategory,
            'quantity' => $quantity,
            'unit' => $unit,
            'coats' => $coats,
            'production_rate_id' => $rate->id,
            'production_rate_value' => $rate->production_rate,
            'sort_order' => $area->surfaces()->max('sort_order') + 1,
        ]);

        // Clear cached pricing on the area
        $area->update(['cached_cogs' => null, 'cached_customer_price' => null]);

        return [
            'surface_id' => $surface->id,
            'name' => $surface->name,
            'category' => $surfaceCategory,
            'quantity' => (float) $surface->quantity,
            'unit' => $surface->unit,
            'production_rate_name' => $rate->name,
            'labor_hours' => (float) ($surface->labor_hours ?? 0),
            'labor_cost' => (float) ($surface->labor_cost ?? 0),
            'material_cost' => (float) ($surface->material_cost ?? 0),
            'total' => (float) ($surface->total ?? 0),
        ];
    }

    /**
     * Update a surface's configuration.
     */
    public function updateSurface(
        int $surfaceId,
        ?int $productionRateId = null,
        ?int $coats = null,
        ?int $paintProductId = null,
        ?float $quantityOverride = null
    ): array {
        $surface = EstimateAreaSurface::findOrFail($surfaceId);

        $updates = [];
        if ($productionRateId !== null) {
            $rate = ProductionRate::findOrFail($productionRateId);
            $updates['production_rate_id'] = $rate->id;
            $updates['production_rate_value'] = $rate->production_rate;
        }
        if ($coats !== null) {
            $updates['coats'] = $coats;
        }
        if ($paintProductId !== null) {
            $updates['paint_product_id'] = $paintProductId;
        }
        if ($quantityOverride !== null) {
            $updates['quantity'] = $quantityOverride;
        }

        // Clear calculated fields to trigger recalculation in model boot
        $updates['labor_hours'] = null;
        $updates['labor_cost'] = null;
        $updates['material_cost'] = null;
        $updates['total'] = null;

        $surface->update($updates);
        $surface->refresh();

        // Clear cached pricing on parent area
        $surface->area?->update(['cached_cogs' => null, 'cached_customer_price' => null]);

        return [
            'surface_id' => $surface->id,
            'name' => $surface->name,
            'quantity' => (float) $surface->quantity,
            'unit' => $surface->unit,
            'coats' => $surface->coats,
            'production_rate_id' => $surface->production_rate_id,
            'labor_hours' => (float) ($surface->labor_hours ?? 0),
            'labor_cost' => (float) ($surface->labor_cost ?? 0),
            'material_cost' => (float) ($surface->material_cost ?? 0),
            'total' => (float) ($surface->total ?? 0),
        ];
    }

    /**
     * Remove a surface from an area.
     */
    public function removeSurface(int $surfaceId): array
    {
        $surface = EstimateAreaSurface::findOrFail($surfaceId);
        $areaId = $surface->area_id;
        $name = $surface->name;
        $surface->delete();

        // Clear cached pricing
        EstimateArea::where('id', $areaId)->update(['cached_cogs' => null, 'cached_customer_price' => null]);

        return ['success' => true, 'removed' => $name, 'area_id' => $areaId];
    }

    /**
     * Add a fixed-price addon (nested area template) to an area.
     */
    public function addAddonToArea(int $areaId, int $nestedTemplateId, int $quantity = 1): array
    {
        $area = EstimateArea::findOrFail($areaId);
        $template = NestedAreaTemplate::findOrFail($nestedTemplateId);

        $unitPrice = $template->getEffectivePrice();

        $addon = EstimateAreaAddon::create([
            'estimate_area_id' => $area->id,
            'nested_area_template_id' => $template->id,
            'name' => $template->name,
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total_price' => $unitPrice * $quantity,
        ]);

        // Clear cached pricing
        $area->update(['cached_cogs' => null, 'cached_customer_price' => null]);

        return [
            'addon_id' => $addon->id,
            'name' => $template->name,
            'unit_price' => round($unitPrice, 2),
            'quantity' => $quantity,
            'total_price' => round($unitPrice * $quantity, 2),
        ];
    }

    // =========================================================================
    // AREA MANAGEMENT METHODS
    // =========================================================================

    /**
     * Remove an area and all its surfaces/addons.
     */
    public function removeArea(int $areaId): array
    {
        $area = EstimateArea::findOrFail($areaId);
        $estimateId = $area->estimate_id;
        $name = $area->name;

        // Delete surfaces, addons, child areas
        $area->surfaces()->delete();
        $area->addons()->delete();
        $area->childAreas()->each(function ($child) {
            $child->surfaces()->delete();
            $child->addons()->delete();
            $child->delete();
        });
        $area->delete();

        // Recalculate estimate
        $estimate = Estimate::find($estimateId);
        if ($estimate) {
            $estimate->calculateTotals();
            $estimate->calculateDepositAndBalance();
            $estimate->save();
        }

        return ['success' => true, 'removed' => $name, 'estimate_id' => $estimateId];
    }

    /**
     * Update area dimensions (triggers surface quantity recalculation).
     */
    public function updateAreaDimensions(
        int $areaId,
        ?float $length = null,
        ?float $width = null,
        ?float $height = null,
        ?int $wallCount = null
    ): array {
        $area = EstimateArea::findOrFail($areaId);

        if ($length !== null) {
            $area->length = $length;
        }
        if ($width !== null) {
            $area->width = $width;
        }
        if ($height !== null) {
            $area->height = $height;
        }
        if ($wallCount !== null) {
            $area->wall_count = $wallCount;
        }

        // Clear cached pricing
        $area->cached_cogs = null;
        $area->cached_customer_price = null;
        $area->save();

        // Recalculate surface quantities based on new dimensions
        foreach ($area->surfaces as $surface) {
            if ($surface->surface_category) {
                $newQuantity = $this->getQuantityForCategory($area->fresh(), $surface->surface_category);
                $surface->update([
                    'quantity' => $newQuantity,
                    'labor_hours' => null,
                    'labor_cost' => null,
                    'material_cost' => null,
                    'total' => null,
                ]);
            }
        }

        $area->refresh();

        return [
            'area_id' => $area->id,
            'name' => $area->name,
            'wall_sqft' => (float) ($area->wall_sqft ?? 0),
            'ceiling_sqft' => (float) ($area->ceiling_sqft ?? 0),
            'perimeter_lnft' => (float) ($area->perimeter_lnft ?? 0),
            'surfaces_updated' => $area->surfaces()->count(),
        ];
    }

    /**
     * Preview pricing breakdown for an area.
     */
    public function previewAreaPricing(int $areaId): array
    {
        $area = EstimateArea::with(['surfaces.productionRate', 'addons', 'substrate'])->findOrFail($areaId);
        $settings = CompanySetting::getInstance();
        $scope = $area->scope_type ?? 'interior';
        $effectiveRate = $settings->getEffectiveHourlyRateForScope($scope);

        $surfaces = $area->surfaces->where('is_included', true)->map(fn ($s) => [
            'name' => $s->name,
            'quantity' => (float) $s->quantity,
            'unit' => $s->unit,
            'rate' => $s->productionRate?->name,
            'hours' => (float) ($s->labor_hours ?? 0),
            'labor_cost' => (float) ($s->labor_cost ?? 0),
            'material_cost' => (float) ($s->material_cost ?? 0),
        ])->values()->toArray();

        $laborHours = collect($surfaces)->sum('hours');
        $laborCost = collect($surfaces)->sum('labor_cost');
        $materialCost = collect($surfaces)->sum('material_cost');

        $cleanupHours = $settings->calculateCleanupHours($laborHours, $scope);
        $cleanupCost = $cleanupHours * $effectiveRate;
        $sundriesCost = $settings->calculateSundriesCost($laborHours, $scope);
        $addonCost = (float) $area->addons()->sum('total_price');

        $cogs = $area->getFullCOGS(null, true);
        $price = $area->getCustomerPrice();

        return [
            'area_id' => $area->id,
            'name' => $area->name,
            'surfaces' => $surfaces,
            'labor_hours' => round($laborHours, 2),
            'cleanup_hours' => round($cleanupHours, 2),
            'cleanup_cost' => round($cleanupCost, 2),
            'sundries_cost' => round($sundriesCost, 2),
            'materials' => round($materialCost, 2),
            'addon_cost' => round($addonCost, 2),
            'cogs' => round($cogs, 2),
            'price' => round($price, 2),
        ];
    }

    // =========================================================================
    // PACKAGE TIER METHODS
    // =========================================================================

    /**
     * Set which package tiers an area is included in (good/better/best).
     *
     * Passing null or an empty array means the area is included in ALL tiers.
     * Invalid tier names are silently filtered out.
     *
     * @param  array<string>|null  $tiers
     * @return array{area_id: int, package_tiers: array<string>|null, included_in: array<string>}
     */
    public function setAreaPackageTiers(int $areaId, ?array $tiers, ?Estimate $estimate = null): array
    {
        $area = $estimate
            ? $estimate->areas()->findOrFail($areaId)
            : EstimateArea::findOrFail($areaId);

        $allTiers = ['good', 'better', 'best'];

        // Null or empty array → include in all tiers (package_tiers = null)
        if ($tiers === null || count($tiers) === 0) {
            $filteredTiers = null;
        } else {
            $filteredTiers = array_values(array_filter($tiers, fn ($t) => in_array($t, $allTiers, true)));
            if (count($filteredTiers) === 0) {
                $filteredTiers = null;
            }
        }

        $area->update([
            'package_tiers' => $filteredTiers,
            'cached_cogs' => null,
            'cached_customer_price' => null,
            'cached_tier_prices' => null,
        ]);

        $area->refresh();

        event(new EstimateAreaUpdated(
            estimateId: $area->estimate_id,
            areaId: $area->id,
            action: 'package_tiers_updated',
        ));

        return [
            'area_id' => $area->id,
            'package_tiers' => $area->package_tiers,
            'included_in' => $area->package_tiers === null ? $allTiers : $area->package_tiers,
        ];
    }

    /**
     * Update per-tier configuration overrides for a surface.
     *
     * Stores tier-specific coats, production rate, labor hours, and material cost
     * in the surface's tier_overrides JSON column, and dual-writes flat columns
     * for material_cost_{tier} and paint_product_id_{tier}.
     *
     * @return array{tier: string, tier_data: array<string, mixed>, area_tier_cogs: float, area_tier_price: float}
     *
     * @throws \InvalidArgumentException if $tier is not good/better/best
     */
    public function updateSurfaceTierOverrides(
        int $surfaceId,
        string $tier,
        ?int $coats = null,
        ?int $productionRateId = null,
        ?float $laborHours = null,
        ?int $paintProductId = null,
        ?float $materialCost = null,
        ?Estimate $estimate = null,
    ): array {
        $validTiers = ['good', 'better', 'best'];

        if (! in_array($tier, $validTiers, true)) {
            throw new \InvalidArgumentException("Invalid tier: {$tier}");
        }

        $surface = EstimateAreaSurface::with(['area', 'productionRate'])->findOrFail($surfaceId);

        if ($estimate !== null && (int) $surface->area->estimate_id !== (int) $estimate->id) {
            throw new \InvalidArgumentException('Surface does not belong to this estimate.');
        }

        $existingOverrides = $surface->tier_overrides ?? [];

        // Create _base snapshot if this is the first tier override
        if (! isset($existingOverrides['_base'])) {
            $currentRate = $surface->productionRate;
            $existingOverrides['_base'] = [
                'production_rate_id' => $surface->production_rate_id,
                'default_coats' => $currentRate?->default_coats ?? $surface->coats ?? 2,
                'labor_hours' => round((float) $surface->labor_hours, 4),
                'labor_cost' => round((float) $surface->labor_cost, 4),
                'material_cost' => round((float) $surface->material_cost, 4),
            ];
        }

        // Resolve the production rate to use for this tier
        $tierData = $existingOverrides[$tier] ?? [];
        $rateToUse = null;

        if ($productionRateId !== null) {
            $rateToUse = ProductionRate::findOrFail($productionRateId);
            $tierData['production_rate_id'] = $rateToUse->id;
        } else {
            $existingRateId = $tierData['production_rate_id'] ?? $surface->production_rate_id;
            $rateToUse = $existingRateId ? ProductionRate::find($existingRateId) : $surface->productionRate;
            $tierData['production_rate_id'] = $existingRateId;
        }

        // Resolve coats
        $resolvedCoats = $coats ?? $tierData['default_coats'] ?? $rateToUse?->default_coats ?? $surface->coats ?? 2;
        $tierData['default_coats'] = $resolvedCoats;

        // Compute labor hours
        if ($laborHours !== null) {
            $tierData['labor_hours'] = (float) $laborHours;
        } else {
            // Apply substrate multipliers from the area, just like the surface saving hook does
            $area = $surface->area;
            $substrate = $area?->substrate;
            $prepMultiplier = $substrate ? (float) ($substrate->prep_time_multiplier ?? 1.0) : 1.0;
            $paintMultiplier = $substrate ? (float) ($substrate->paint_time_multiplier ?? 1.0) : 1.0;

            if ($rateToUse && $surface->quantity && (float) $surface->quantity > 0) {
                $coatMultiplier = $rateToUse->getCoatMultiplier($resolvedCoats);
                $rateValue = (float) ($rateToUse->rate_type === 'hours_per_unit'
                    ? $rateToUse->production_rate
                    : $rateToUse->production_rate);

                if ($rateToUse->rate_type === 'hours_per_unit') {
                    $rawHours = (float) $surface->quantity * $rateValue * $coatMultiplier;
                } else {
                    $rawHours = ((float) $surface->quantity / $rateValue) * $coatMultiplier;
                }

                $tierData['labor_hours'] = round($rawHours * $prepMultiplier * $paintMultiplier, 4);
            } else {
                $tierData['labor_hours'] = round((float) $surface->labor_hours, 4);
            }
        }

        // Compute labor cost
        $scopeType = $surface->area?->scope_type ?? 'interior';
        $settings = CompanySetting::getInstance();
        $effectiveRate = $settings->getEffectiveHourlyRateForScope($scopeType);
        $tierData['labor_cost'] = round($tierData['labor_hours'] * $effectiveRate, 4);

        // Resolve material cost
        $flatMaterialCostColumn = "material_cost_{$tier}";
        $flatPaintProductColumn = "paint_product_id_{$tier}";

        if ($materialCost !== null) {
            $tierData['material_cost'] = $materialCost;
        } elseif ($paintProductId !== null) {
            $product = PaintProduct::findOrFail($paintProductId);
            $costCalc = $product->calculateCost(
                (float) $surface->quantity,
                $resolvedCoats,
                $surface->application_method ?? 'brush_roll',
                (float) ($settings->recycle_fee_per_gallon ?? 0.54)
            );
            $tierData['material_cost'] = $costCalc['total_cost'];
        } else {
            $tierData['material_cost'] = $tierData['material_cost'] ?? round((float) $surface->material_cost, 4);
        }

        if ($paintProductId !== null) {
            $tierData['paint_product_id'] = $paintProductId;
        }

        $existingOverrides[$tier] = $tierData;

        // Build update array — dual-write flat columns for material cost / paint product
        $updateData = ['tier_overrides' => $existingOverrides];

        $updateData[$flatMaterialCostColumn] = $tierData['material_cost'];

        if ($paintProductId !== null) {
            $updateData[$flatPaintProductColumn] = $paintProductId;
        }

        $surface->update($updateData);
        $surface->refresh();

        // Clear cached area pricing
        $area = $surface->area;
        $area->update([
            'cached_cogs' => null,
            'cached_customer_price' => null,
            'cached_tier_prices' => null,
        ]);

        event(new EstimateAreaUpdated(
            estimateId: $area->estimate_id,
            areaId: $area->id,
            action: 'surface_tier_overrides_updated',
        ));

        $area->refresh();
        $areaTierCogs = $area->getFullCOGS($tier, true);
        $areaTierPrice = $area->getCustomerPrice($tier);

        return [
            'tier' => $tier,
            'tier_data' => $tierData,
            'area_tier_cogs' => round($areaTierCogs, 2),
            'area_tier_price' => round($areaTierPrice, 2),
        ];
    }

    // =========================================================================
    // PAINT CONFIGURATION METHODS
    // =========================================================================

    /**
     * Set paint for entire estimate.
     */
    public function setEstimatePaintConfig(
        Estimate $estimate,
        string $mode,
        ?int $paintProductId = null,
        ?array $categoryOverrides = null
    ): array {
        if ($mode === 'single' && $paintProductId) {
            $estimate->default_paint_product_id = $paintProductId;
            $estimate->paint_products_by_category = null;
        } elseif ($mode === 'category' && $categoryOverrides) {
            $estimate->paint_products_by_category = $categoryOverrides;
        }

        $estimate->save();

        // Propagate to all surfaces
        $this->propagatePaintToSurfaces($estimate, $mode, $paintProductId, $categoryOverrides);

        // Recalculate
        $estimate->calculateTotals();
        $estimate->calculateDepositAndBalance();
        $estimate->save();

        return [
            'success' => true,
            'mode' => $mode,
            'paint_product_id' => $paintProductId,
            'category_overrides' => $categoryOverrides,
            'new_total' => (float) ($estimate->total ?? 0),
        ];
    }

    /**
     * Set paint for a specific area.
     */
    public function setAreaPaintProduct(
        int $areaId,
        ?int $paintProductId = null,
        ?array $categoryOverrides = null
    ): array {
        $area = EstimateArea::with('surfaces')->findOrFail($areaId);

        if ($paintProductId) {
            $area->default_paint_product_id = $paintProductId;
        }

        $area->cached_cogs = null;
        $area->cached_customer_price = null;
        $area->save();

        // Update surfaces
        foreach ($area->surfaces as $surface) {
            $pid = $paintProductId;
            if ($categoryOverrides) {
                $cat = $surface->surface_category ?? $surface->getSurfaceCategory();
                $pid = $categoryOverrides[$cat] ?? $paintProductId;
            }
            if ($pid) {
                $surface->update([
                    'paint_product_id' => $pid,
                    'labor_hours' => null,
                    'labor_cost' => null,
                    'material_cost' => null,
                    'total' => null,
                ]);
            }
        }

        return [
            'success' => true,
            'area_id' => $area->id,
            'paint_product_id' => $paintProductId,
        ];
    }

    /**
     * Configure tier pricing (good/better/best).
     */
    public function setTierPricing(
        Estimate $estimate,
        string $tier,
        ?int $paintProductId = null,
        ?array $categoryOverrides = null
    ): array {
        $tiers = $estimate->paint_tiers ?? [];
        $tiers[$tier] = [
            'default_paint_product_id' => $paintProductId,
            'paint_by_category' => $categoryOverrides,
        ];
        $estimate->paint_tiers = $tiers;
        $estimate->save();

        // Update surface tier material costs
        foreach ($estimate->areas as $area) {
            foreach ($area->surfaces as $surface) {
                $pid = $paintProductId;
                if ($categoryOverrides) {
                    $cat = $surface->surface_category ?? $surface->getSurfaceCategory();
                    $pid = $categoryOverrides[$cat] ?? $paintProductId;
                }
                if ($pid) {
                    $tierField = "paint_product_id_{$tier}";
                    $materialField = "material_cost_{$tier}";
                    $product = PaintProduct::find($pid);
                    $materialCost = 0;
                    if ($product) {
                        $costCalc = $product->calculateCost(
                            (float) $surface->quantity,
                            $surface->coats ?? 2,
                            'brush_roll',
                            (float) (CompanySetting::getInstance()->recycle_fee_per_gallon ?? 0.54)
                        );
                        $materialCost = $costCalc['total_cost'];
                    }
                    $surface->update([
                        $tierField => $pid,
                        $materialField => $materialCost,
                    ]);
                }
            }
        }

        return [
            'success' => true,
            'tier' => $tier,
            'paint_product_id' => $paintProductId,
        ];
    }

    // =========================================================================
    // ESTIMATE SUMMARY
    // =========================================================================

    /**
     * Get human-readable estimate summary with per-area breakdown.
     */
    public function getEstimateSummary(Estimate $estimate, ?string $tier = null): array
    {
        $estimate->load(['areas.surfaces', 'areas.addons']);

        $areas = $estimate->areas->where('parent_area_id', null)->map(function (EstimateArea $area) use ($tier) {
            return [
                'name' => $area->name,
                'scope' => $area->scope_type,
                'surfaces_count' => $area->surfaces->count(),
                'cogs' => round($area->getFullCOGS($tier), 2),
                'price' => round($area->getCustomerPrice($tier), 2),
            ];
        })->values()->toArray();

        $totalCogs = collect($areas)->sum('cogs');
        $totalPrice = collect($areas)->sum('price');

        return [
            'title' => $estimate->title,
            'tier' => $tier,
            'areas' => $areas,
            'total_cogs' => round($totalCogs, 2),
            'total_price' => round($totalPrice, 2),
            'tax_amount' => (float) ($estimate->tax_amount ?? 0),
            'grand_total' => (float) ($estimate->total ?? 0),
        ];
    }

    // =========================================================================
    // PRIVATE HELPER METHODS
    // =========================================================================

    /**
     * Create a surface from a template surface definition.
     */
    protected function createSurfaceFromTemplate(EstimateArea $area, $templateSurface, int $coats): EstimateAreaSurface
    {
        $quantity = $this->getQuantityForCategory($area, $templateSurface->surface_type ?? 'walls');

        return EstimateAreaSurface::create([
            'area_id' => $area->id,
            'name' => $templateSurface->name,
            'surface_type' => $templateSurface->surface_type,
            'surface_category' => $templateSurface->surface_category ?? $templateSurface->surface_type,
            'quantity' => $quantity,
            'unit' => $templateSurface->unit ?? $this->getUnitForCategory($templateSurface->surface_type ?? 'walls'),
            'coats' => $coats,
            'production_rate_id' => $templateSurface->production_rate_id,
            'production_rate_value' => $templateSurface->productionRate?->production_rate ?? 200,
            'sort_order' => $templateSurface->sort_order ?? 0,
        ]);
    }

    /**
     * Create surfaces from surface_ratios in template metadata.
     *
     * @return EstimateAreaSurface[]
     */
    protected function createSurfacesFromRatios(EstimateArea $area, array $surfaceRatios, int $coats): array
    {
        $surfaces = [];
        $sortOrder = 0;

        foreach ($surfaceRatios as $surfaceName => $config) {
            $category = app(TemplatePricingService::class)->mapSurfaceToCategory($surfaceName);
            $quantity = $this->getQuantityForCategory($area, $category);
            $unit = $this->getUnitForCategory($category);

            $rateId = $config['rate_id'] ?? null;
            $rate = $rateId ? ProductionRate::find($rateId) : null;

            $surfaces[] = EstimateAreaSurface::create([
                'area_id' => $area->id,
                'name' => ucfirst(str_replace('_', ' ', $surfaceName)),
                'surface_type' => $this->getSurfaceTypeForCategory($category),
                'surface_category' => $category,
                'quantity' => $quantity,
                'unit' => $unit,
                'coats' => $coats,
                'production_rate_id' => $rateId,
                'production_rate_value' => $rate?->production_rate ?? 200,
                'sort_order' => $sortOrder++,
            ]);
        }

        return $surfaces;
    }

    /**
     * Auto-calculate quantity from area dimensions based on surface category.
     */
    protected function getQuantityForCategory(EstimateArea $area, string $category): float
    {
        return match ($category) {
            'walls', 'wall', 'body', 'siding' => max(0, (float) ($area->wall_sqft ?? 0)),
            'ceiling', 'eaves' => max(0, (float) ($area->ceiling_sqft ?? 0)),
            'baseboard', 'trim', 'fascia', 'crown', 'molding' => max(0, (float) ($area->perimeter_lnft ?? $area->baseboard_lnft ?? 0)),
            'doors', 'exterior_doors' => 1.0,
            'windows' => 1.0,
            default => max(0, (float) ($area->wall_sqft ?? 0)),
        };
    }

    /**
     * Get the unit type for a surface category.
     */
    protected function getUnitForCategory(string $category): string
    {
        return match ($category) {
            'walls', 'wall', 'ceiling', 'body', 'siding', 'eaves' => 'sqft',
            'baseboard', 'trim', 'fascia', 'crown', 'molding' => 'lnft',
            'doors', 'exterior_doors', 'windows' => 'unit',
            default => 'sqft',
        };
    }

    /**
     * Get the surface_type field value for a category.
     */
    protected function getSurfaceTypeForCategory(string $category): string
    {
        return match ($category) {
            'walls', 'wall' => 'walls',
            'ceiling' => 'ceiling',
            'baseboard', 'trim', 'crown', 'molding' => 'trim',
            'doors', 'exterior_doors' => 'doors',
            'windows' => 'windows',
            'body', 'siding' => 'body',
            'fascia' => 'fascia',
            'eaves' => 'eaves',
            default => 'walls',
        };
    }

    /**
     * Get human-readable surface name for a category.
     */
    protected function getSurfaceNameForCategory(string $category): string
    {
        return match ($category) {
            'walls', 'wall' => 'Walls',
            'ceiling' => 'Ceiling',
            'baseboard' => 'Baseboards',
            'trim' => 'Trim',
            'doors' => 'Doors',
            'exterior_doors' => 'Exterior Doors',
            'windows' => 'Windows',
            'body', 'siding' => 'Body/Siding',
            'fascia' => 'Fascia',
            'eaves' => 'Eaves/Soffits',
            'crown' => 'Crown Molding',
            'molding' => 'Molding',
            default => ucfirst($category),
        };
    }

    /**
     * Propagate paint configuration to all surfaces on an estimate.
     */
    protected function propagatePaintToSurfaces(
        Estimate $estimate,
        string $mode,
        ?int $paintProductId,
        ?array $categoryOverrides
    ): void {
        foreach ($estimate->areas as $area) {
            foreach ($area->surfaces as $surface) {
                $pid = $paintProductId;
                if ($mode === 'category' && $categoryOverrides) {
                    $cat = $surface->surface_category ?? $surface->getSurfaceCategory();
                    $pid = $categoryOverrides[$cat] ?? $paintProductId;
                }
                if ($pid) {
                    $surface->update([
                        'paint_product_id' => $pid,
                        'labor_hours' => null,
                        'labor_cost' => null,
                        'material_cost' => null,
                        'total' => null,
                    ]);
                }
            }
            $area->update(['cached_cogs' => null, 'cached_customer_price' => null]);
        }
    }

    // =========================================================================
    // COST ITEM METHODS
    // =========================================================================

    /**
     * Add a cost item (material, rental, equipment, service) to an area or surface.
     *
     * @return array{success: true, cost_item: array}
     */
    public function addCostItem(
        Estimate $estimate,
        string $targetType,
        int $targetId,
        string $name,
        string $costType = 'material',
        float $quantity = 1,
        string $unit = 'each',
        float $unitCost = 0,
        float $unitPrice = 0,
        ?string $tier = null,
        ?string $notes = null,
    ): array {
        if (! in_array($targetType, ['area', 'surface'], strict: true)) {
            throw new \InvalidArgumentException("targetType must be 'area' or 'surface', got: {$targetType}");
        }

        $data = [
            'name' => $name,
            'cost_type' => $costType,
            'quantity' => $quantity,
            'unit' => $unit,
            'unit_cost' => $unitCost,
            'unit_price' => $unitPrice,
            'tier' => $tier,
            'notes' => $notes,
        ];

        if ($targetType === 'area') {
            $area = $estimate->areas()->findOrFail($targetId);
            $item = $area->costItems()->create($data);
        } else {
            $surface = EstimateAreaSurface::findOrFail($targetId);
            if ((int) $surface->area->estimate_id !== (int) $estimate->id) {
                throw new \InvalidArgumentException('Surface does not belong to this estimate.');
            }
            $item = $surface->costItems()->create($data);
        }

        return ['success' => true, 'cost_item' => $item->toArray()];
    }

    /**
     * Remove a cost item by ID.
     *
     * @return array{success: true}
     */
    public function removeCostItem(Estimate $estimate, int $costItemId): array
    {
        $item = EstimateCostItem::findOrFail($costItemId);
        $costable = $item->costable;

        if ($costable instanceof EstimateArea) {
            if ((int) $costable->estimate_id !== (int) $estimate->id) {
                throw new \InvalidArgumentException('Cost item does not belong to this estimate.');
            }
        } elseif ($costable instanceof EstimateAreaSurface) {
            if ((int) $costable->area->estimate_id !== (int) $estimate->id) {
                throw new \InvalidArgumentException('Cost item does not belong to this estimate.');
            }
        }

        $item->delete();

        return ['success' => true];
    }

    /**
     * List all cost items on a given area or surface.
     *
     * @return array{cost_items: array}
     */
    public function listCostItems(Estimate $estimate, string $targetType, int $targetId): array
    {
        if (! in_array($targetType, ['area', 'surface'], strict: true)) {
            throw new \InvalidArgumentException("targetType must be 'area' or 'surface', got: {$targetType}");
        }

        if ($targetType === 'area') {
            $area = $estimate->areas()->findOrFail($targetId);
            $items = $area->costItems()->get()->toArray();
        } else {
            $surface = EstimateAreaSurface::findOrFail($targetId);
            if ((int) $surface->area->estimate_id !== (int) $estimate->id) {
                throw new \InvalidArgumentException('Surface does not belong to this estimate.');
            }
            $items = $surface->costItems()->get()->toArray();
        }

        return ['cost_items' => $items];
    }

    // =========================================================================
    // PRODUCTION RATE METHODS
    // =========================================================================

    /**
     * Create a new production rate.
     *
     * @return array{success: true, production_rate: array}
     *
     * @throws \InvalidArgumentException if surface_category or rate_type is invalid
     */
    public function createProductionRate(
        string $name,
        string $scope,
        string $surfaceCategory,
        float $productionRate,
        string $rateType = 'sqft_hr',
        string $unitType = 'sqft',
        int $defaultCoats = 2,
        ?string $description = null,
    ): array {
        // Enforce snake_case naming convention for freeform categories
        if (! preg_match('/^[a-z][a-z0-9_]*$/', $surfaceCategory)) {
            throw new \InvalidArgumentException("Invalid surface_category: {$surfaceCategory}. Must be lowercase snake_case (e.g., walls, trim, epoxy_floor).");
        }

        $validRateTypes = array_keys(ProductionRate::RATE_TYPES);

        if (! in_array($rateType, $validRateTypes, strict: true)) {
            $valid = implode(', ', $validRateTypes);
            throw new \InvalidArgumentException("Invalid rate_type: {$rateType}. Valid values: {$valid}");
        }

        $categoryType = $scope === ProductionRate::SCOPE_EXTERIOR
            ? ProductionRateCategory::TYPE_EXTERIOR
            : ProductionRateCategory::TYPE_INTERIOR;

        $category = ProductionRateCategory::firstOrCreate(
            ['name' => ucfirst($scope).' Painting', 'type' => $categoryType],
            ['is_active' => true, 'sort_order' => 0],
        );

        $rate = ProductionRate::create([
            'category_id' => $category->id,
            'name' => $name,
            'scope' => $scope,
            'surface_category' => $surfaceCategory,
            'production_rate' => $productionRate,
            'rate_type' => $rateType,
            'unit_type' => $unitType,
            'default_coats' => $defaultCoats,
            'description' => $description,
            'is_active' => true,
        ]);

        return ['success' => true, 'production_rate' => $rate->toArray()];
    }

    // =========================================================================
    // ESTIMATE ITEM METHODS
    // =========================================================================

    /**
     * Create a line item on the estimate.
     *
     * @return array{success: true, item: array}
     */
    public function createEstimateItem(
        Estimate $estimate,
        string $description,
        float $quantity,
        string $unit = 'each',
        float $unitPrice = 0,
        ?float $cost = null,
    ): array {
        $data = [
            'description' => $description,
            'quantity' => $quantity,
            'unit' => $unit,
            'unit_price' => $unitPrice,
        ];

        if ($cost !== null) {
            $data['cost'] = $cost;
        }

        $item = $estimate->items()->create($data);

        return ['success' => true, 'item' => $item->toArray()];
    }

    // =========================================================================
    // TEMPLATE METHODS
    // =========================================================================

    /**
     * Create a new nested area template (closets, half baths, prep work).
     *
     * @param  array<int, array{name: string, surface_type: string, production_rate_id: int, calculation_method: string, calculation_value: float}>|null  $surfaces
     * @return array{success: true, template: array}
     */
    public function createNestedTemplate(
        string $name,
        string $scopeType = 'interior',
        string $category = 'other',
        ?float $fixedPrice = null,
        ?float $estimatedHours = null,
        ?float $materialCost = null,
        ?string $description = null,
        ?array $surfaces = null,
        bool $includesWalls = false,
        bool $includesCeiling = false,
        bool $includesTrim = false,
        bool $includesDoor = false,
    ): array {
        // fixed_price is NOT NULL in the schema — default to 0 when not provided
        $fixedPrice = $fixedPrice ?? 0.0;
        $baseCode = strtoupper(Str::slug($name, '-'));
        $code = $baseCode;
        $counter = 1;

        while (NestedAreaTemplate::where('code', $code)->exists()) {
            $code = $baseCode.'-'.$counter;
            $counter++;
        }

        $template = NestedAreaTemplate::create([
            'name' => $name,
            'code' => $code,
            'scope_type' => $scopeType,
            'category' => $category,
            'fixed_price' => $fixedPrice,
            'estimated_hours' => $estimatedHours,
            'material_cost' => $materialCost,
            'description' => $description,
            'includes_walls' => $includesWalls,
            'includes_ceiling' => $includesCeiling,
            'includes_trim' => $includesTrim,
            'includes_door' => $includesDoor,
            'is_active' => true,
        ]);

        if (! empty($surfaces)) {
            foreach ($surfaces as $index => $surfaceData) {
                NestedTemplateSurface::create([
                    'nested_area_template_id' => $template->id,
                    'name' => $surfaceData['name'] ?? ucfirst($surfaceData['surface_type'] ?? 'Surface'),
                    'surface_type' => $surfaceData['surface_type'] ?? 'other',
                    'calculation_method' => $surfaceData['calculation_method'] ?? 'ratio',
                    'calculation_value' => $surfaceData['calculation_value'] ?? 1.0,
                    'production_rate_id' => $surfaceData['production_rate_id'] ?? null,
                    'default_coats' => $surfaceData['default_coats'] ?? 2,
                    'is_required' => $surfaceData['is_required'] ?? false,
                    'is_default_enabled' => $surfaceData['is_default_enabled'] ?? true,
                    'sort_order' => $surfaceData['sort_order'] ?? $index,
                ]);
            }
        }

        return ['success' => true, 'template' => $template->fresh(['surfaces'])->toArray()];
    }

    /**
     * Create a new room/area template (EstimateTemplate).
     *
     * @param  array<int, array{name: string, surface_type: string, production_rate_id?: int, calculation_method?: string, calculation_value?: float}>|null  $surfaces
     * @param  array<string, array{length: float, width: float, height: float}>|null  $sizes  S/M/L dimension sets stored in metadata
     * @return array{success: true, template: array}
     */
    public function createTemplate(
        string $name,
        string $scopeType,
        string $category = 'room',
        string $pricingMethod = 'production_rate',
        ?array $sizes = null,
        ?array $surfaces = null,
        ?string $description = null,
        ?float $basePrice = null,
        ?float $perSqftRate = null,
    ): array {
        $categoryType = match ($category) {
            'surface' => EstimateTemplateCategory::TYPE_SURFACE,
            'item' => EstimateTemplateCategory::TYPE_ITEM,
            'addon' => EstimateTemplateCategory::TYPE_ADDON,
            default => EstimateTemplateCategory::TYPE_ROOM,
        };

        $templateCategory = EstimateTemplateCategory::firstOrCreate(
            ['type' => $categoryType, 'name' => ucfirst($category)],
            ['is_active' => true, 'sort_order' => 0],
        );

        $metadata = [];
        if (! empty($sizes)) {
            $metadata['dimensions'] = $sizes;
        }

        $template = EstimateTemplate::create([
            'template_category_id' => $templateCategory->id,
            'name' => $name,
            'description' => $description,
            'scope_type' => $scopeType,
            'pricing_method' => $pricingMethod,
            'base_price' => $basePrice,
            'per_sqft_rate' => $perSqftRate,
            'metadata' => ! empty($metadata) ? $metadata : null,
            'is_active' => true,
            'is_configurable' => true,
            'visibility' => EstimateTemplate::VISIBILITY_COMPANY,
        ]);

        if (! empty($surfaces)) {
            foreach ($surfaces as $index => $surfaceData) {
                $template->surfaces()->create([
                    'name' => $surfaceData['name'] ?? ucfirst($surfaceData['surface_type'] ?? 'Surface'),
                    'surface_type' => $surfaceData['surface_type'] ?? 'other',
                    'calculation_method' => $surfaceData['calculation_method'] ?? 'ratio',
                    'calculation_value' => $surfaceData['calculation_value'] ?? 1.0,
                    'production_rate_id' => $surfaceData['production_rate_id'] ?? null,
                    'default_coats' => $surfaceData['default_coats'] ?? 2,
                    'is_required' => $surfaceData['is_required'] ?? false,
                    'is_default_enabled' => $surfaceData['is_default_enabled'] ?? true,
                    'sort_order' => $surfaceData['sort_order'] ?? $index,
                ]);
            }
        }

        return ['success' => true, 'template' => $template->fresh(['surfaces', 'templateCategory'])->toArray()];
    }

    // =========================================================================
    // AI CONTEXT METHODS
    // =========================================================================

    /**
     * Persist internal AI session notes on the estimate.
     *
     * @return array{success: true}
     */
    public function updateAiContext(Estimate $estimate, string $context): array
    {
        $estimate->update(['ai_context' => $context]);

        return ['success' => true];
    }

    /**
     * Write a customer-facing proposal summary on the estimate.
     *
     * @return array{success: true}
     */
    public function updateAiSummary(Estimate $estimate, string $summary): array
    {
        $estimate->update(['ai_summary' => $summary]);

        return ['success' => true];
    }

    /**
     * Return customer, property, and deal context for this estimate.
     *
     * @return array{customer: array|null, property: array|null, deal: array|null}
     */
    public function getProjectContext(Estimate $estimate): array
    {
        $estimate->load(['deal.customer', 'deal.property']);

        $deal = $estimate->deal;
        $customer = $deal?->customer;
        $property = $deal?->property;

        return [
            'customer' => $customer ? [
                'id' => $customer->id,
                'first_name' => $customer->first_name,
                'last_name' => $customer->last_name,
                'email' => $customer->email,
                'phone' => $customer->phone,
                'address' => $customer->address,
                'city' => $customer->city,
                'state' => $customer->state,
                'zip' => $customer->zip,
            ] : null,
            'property' => $property ? [
                'id' => $property->id,
                'address' => $property->address,
                'city' => $property->city,
                'state' => $property->state,
                'zip' => $property->zip,
                'type' => $property->type,
                'square_footage' => $property->square_footage,
                'year_built' => $property->year_built,
                'listing_status' => $property->listing_status,
                'listing_price' => $property->listing_price,
            ] : null,
            'deal' => $deal ? [
                'id' => $deal->id,
                'stage' => $deal->stage,
                'title' => $deal->title ?? null,
            ] : null,
        ];
    }

    // =========================================================================
    // BATCH EXECUTION — EXECUTE-PLAN MEGA-TOOL
    // =========================================================================

    /**
     * Execute a structured estimate plan in a single DB transaction.
     *
     * Creates multiple areas (from templates or custom), adds surfaces, addons,
     * cost items, line items, configures paint and tier pricing — all atomically.
     * If anything fails, the entire plan is rolled back.
     *
     * @param  array{
     *     estimate_settings?: array{title?: string, project_type?: string},
     *     paint_config?: array{mode: string, paint_product_id?: int, category_overrides?: array},
     *     tier_config?: array<string, array{paint_product_id?: int, default_coats?: int}>,
     *     create_first?: array{production_rates?: array},
     *     areas?: array,
     *     line_items?: array,
     * }  $plan
     * @return array{success: bool, areas_created: int, surfaces_created: int, tier_overrides_set: int, errors: array, area_results: array, estimate_total: float}
     */
    public function executePlan(Estimate $estimate, array $plan): array
    {
        $errors = [];
        $areaResults = [];
        $surfacesCreated = 0;
        $tierOverridesSet = 0;
        $refMap = [];

        return DB::transaction(function () use ($estimate, $plan, &$errors, &$areaResults, &$surfacesCreated, &$tierOverridesSet, &$refMap) {

            // ── Step 1: Create new production rates from create_first ──
            foreach ($plan['create_first']['production_rates'] ?? [] as $rateDef) {
                try {
                    $ref = $rateDef['ref'] ?? null;
                    $result = $this->createProductionRate(
                        name: $rateDef['name'],
                        scope: $rateDef['scope'],
                        surfaceCategory: $rateDef['surface_category'],
                        productionRate: (float) $rateDef['production_rate'],
                        rateType: $rateDef['rate_type'] ?? 'sqft_hr',
                        unitType: $rateDef['unit_type'] ?? 'sqft',
                        defaultCoats: $rateDef['default_coats'] ?? 2,
                        description: $rateDef['description'] ?? null,
                    );
                    if ($ref && isset($result['production_rate']['id'])) {
                        $refMap[$ref] = $result['production_rate']['id'];
                    }
                } catch (\Throwable $e) {
                    $errors[] = "Failed to create production rate '{$rateDef['name']}': {$e->getMessage()}";
                }
            }

            // ── Step 2: Update estimate settings ──
            if (! empty($plan['estimate_settings'])) {
                try {
                    $this->updateEstimateSettings($estimate, $plan['estimate_settings']);
                    $estimate->refresh();
                } catch (\Throwable $e) {
                    $errors[] = "Failed to update estimate settings: {$e->getMessage()}";
                }
            }

            // ── Step 3: Set paint config ──
            if (! empty($plan['paint_config'])) {
                try {
                    $pc = $plan['paint_config'];
                    $this->setEstimatePaintConfig(
                        $estimate->fresh(),
                        $pc['mode'] ?? 'single',
                        isset($pc['paint_product_id']) ? (int) $pc['paint_product_id'] : null,
                        $pc['category_overrides'] ?? null,
                    );
                    $estimate->refresh();
                } catch (\Throwable $e) {
                    $errors[] = "Failed to set paint config: {$e->getMessage()}";
                }
            }

            // ── Step 4: Set tier pricing (paint per tier) ──
            $tierConfig = $plan['tier_config'] ?? [];
            foreach ($tierConfig as $tier => $config) {
                try {
                    $this->setTierPricing(
                        $estimate->fresh(),
                        $tier,
                        isset($config['paint_product_id']) ? (int) $config['paint_product_id'] : null,
                        $config['category_overrides'] ?? null,
                    );
                } catch (\Throwable $e) {
                    $errors[] = "Failed to set tier pricing for '{$tier}': {$e->getMessage()}";
                }
            }
            $estimate->refresh();

            // ── Step 5: Create areas ──
            foreach ($plan['areas'] ?? [] as $index => $areaDef) {
                $areaResult = ['index' => $index, 'errors' => []];

                try {
                    $area = null;

                    if (($areaDef['source'] ?? '') === 'template') {
                        $result = $this->createAreaFromTemplate(
                            $estimate->fresh(),
                            (int) $areaDef['template_id'],
                            $areaDef['size'] ?? 'medium',
                            $areaDef['coats'] ?? 2,
                            isset($areaDef['substrate_id']) ? (int) $areaDef['substrate_id'] : null,
                        );
                        $area = EstimateArea::find($result['area_id']);
                        $areaResult['area_id'] = $result['area_id'];
                        $areaResult['name'] = $result['name'];
                        $areaResult['surfaces_created'] = count($result['surfaces'] ?? []);
                        $surfacesCreated += $areaResult['surfaces_created'];

                        if (! empty($areaDef['name'])) {
                            $area->update(['name' => $areaDef['name']]);
                            $areaResult['name'] = $areaDef['name'];
                        }
                    } else {
                        $dims = $areaDef['dimensions'] ?? [];
                        $result = $this->createCustomArea(
                            $estimate->fresh(),
                            $areaDef['name'] ?? 'Area '.($index + 1),
                            $areaDef['scope_type'] ?? $estimate->project_type ?? 'interior',
                            (float) ($dims['length'] ?? 0),
                            (float) ($dims['width'] ?? 0),
                            (float) ($dims['height'] ?? 9),
                            (int) ($dims['wall_count'] ?? 4),
                            isset($areaDef['substrate_id']) ? (int) $areaDef['substrate_id'] : null,
                        );
                        $area = EstimateArea::find($result['area_id']);
                        $areaResult['area_id'] = $result['area_id'];
                        $areaResult['name'] = $result['name'] ?? $areaDef['name'];

                        // Add surfaces for custom areas
                        $createdSurfaces = 0;
                        foreach ($areaDef['surfaces'] ?? [] as $surfDef) {
                            try {
                                $rateId = $surfDef['production_rate_id'];
                                if (is_string($rateId) && str_starts_with($rateId, 'ref:')) {
                                    $refKey = substr($rateId, 4);
                                    $rateId = $refMap[$refKey] ?? null;
                                    if (! $rateId) {
                                        $areaResult['errors'][] = "Unresolved rate ref '{$refKey}' for surface '{$surfDef['name']}'";

                                        continue;
                                    }
                                }

                                $surfResult = $this->addSurfaceToArea(
                                    $area->id,
                                    $surfDef['surface_category'],
                                    (int) $rateId,
                                    $surfDef['coats'] ?? 2,
                                    isset($surfDef['quantity_override']) ? (float) $surfDef['quantity_override'] : null,
                                );

                                if (! empty($surfDef['name']) && isset($surfResult['surface_id'])) {
                                    EstimateAreaSurface::where('id', $surfResult['surface_id'])
                                        ->update(['name' => $surfDef['name']]);
                                }

                                $createdSurfaces++;
                            } catch (\Throwable $e) {
                                $areaResult['errors'][] = "Surface '{$surfDef['name']}': {$e->getMessage()}";
                            }
                        }
                        $areaResult['surfaces_created'] = $createdSurfaces;
                        $surfacesCreated += $createdSurfaces;
                    }

                    // Add addons
                    foreach ($areaDef['addons'] ?? [] as $addonDef) {
                        try {
                            $this->addAddonToArea(
                                $area->id,
                                (int) $addonDef['nested_template_id'],
                                $addonDef['quantity'] ?? 1,
                            );
                        } catch (\Throwable $e) {
                            $areaResult['errors'][] = "Addon: {$e->getMessage()}";
                        }
                    }

                    // Add cost items
                    foreach ($areaDef['cost_items'] ?? [] as $costDef) {
                        try {
                            $this->addCostItem(
                                $estimate,
                                'area',
                                $area->id,
                                $costDef['name'],
                                $costDef['cost_type'] ?? 'material',
                                $costDef['quantity'] ?? 1,
                                $costDef['unit'] ?? 'each',
                                $costDef['unit_cost'] ?? 0,
                                $costDef['unit_price'] ?? 0,
                                $costDef['tier'] ?? null,
                                $costDef['notes'] ?? null,
                            );
                        } catch (\Throwable $e) {
                            $areaResult['errors'][] = "Cost item '{$costDef['name']}': {$e->getMessage()}";
                        }
                    }

                    // Set package tiers
                    if (isset($areaDef['package_tiers'])) {
                        try {
                            $this->setAreaPackageTiers($area->id, $areaDef['package_tiers'], $estimate);
                        } catch (\Throwable $e) {
                            $areaResult['errors'][] = "Package tiers: {$e->getMessage()}";
                        }
                    }

                    // ── Tier overrides for all surfaces on this area ──
                    if (! empty($tierConfig) && $area) {
                        $area->refresh();
                        $area->load('surfaces');

                        foreach ($area->surfaces as $surface) {
                            foreach ($tierConfig as $tier => $tConfig) {
                                try {
                                    $this->updateSurfaceTierOverrides(
                                        surfaceId: $surface->id,
                                        tier: $tier,
                                        coats: $tConfig['default_coats'] ?? null,
                                        paintProductId: isset($tConfig['paint_product_id']) ? (int) $tConfig['paint_product_id'] : null,
                                        estimate: $estimate,
                                    );
                                    $tierOverridesSet++;
                                } catch (\Throwable $e) {
                                    $areaResult['errors'][] = "Tier override {$tier}/{$surface->name}: {$e->getMessage()}";
                                }
                            }
                        }
                    }

                    $area->refresh();
                    $areaResult['price'] = $area->getCustomerPrice();

                } catch (\Throwable $e) {
                    $areaResult['errors'][] = "Area creation failed: {$e->getMessage()}";
                    $errors[] = "Area #{$index}: {$e->getMessage()}";
                }

                if (! empty($areaResult['errors'])) {
                    $errors = array_merge($errors, $areaResult['errors']);
                }

                $areaResults[] = $areaResult;
            }

            // ── Step 6: Create standalone line items ──
            foreach ($plan['line_items'] ?? [] as $itemDef) {
                try {
                    $this->createEstimateItem(
                        $estimate->fresh(),
                        $itemDef['description'],
                        $itemDef['quantity'] ?? 1,
                        $itemDef['unit'] ?? 'each',
                        $itemDef['unit_price'] ?? 0,
                        $itemDef['cost'] ?? null,
                    );
                } catch (\Throwable $e) {
                    $errors[] = "Line item '{$itemDef['description']}': {$e->getMessage()}";
                }
            }

            // ── Step 7: Update existing areas ──
            $areasUpdated = 0;
            $surfacesUpdated = 0;
            foreach ($plan['update_areas'] ?? [] as $index => $updateDef) {
                try {
                    $areaId = $updateDef['area_id'] ?? null;
                    if (! $areaId) {
                        $errors[] = "update_areas[{$index}]: missing area_id";

                        continue;
                    }

                    $area = $estimate->areas()->findOrFail($areaId);

                    // Update area-level fields
                    $areaUpdates = [];
                    if (isset($updateDef['name'])) {
                        $areaUpdates['name'] = $updateDef['name'];
                    }
                    if (isset($updateDef['substrate_id'])) {
                        $areaUpdates['substrate_id'] = $updateDef['substrate_id'];
                    }
                    if (! empty($areaUpdates)) {
                        $area->update($areaUpdates);
                    }

                    // Update dimensions if provided
                    if (isset($updateDef['dimensions'])) {
                        $dims = $updateDef['dimensions'];
                        $this->updateAreaDimensions(
                            $area->id,
                            $dims['length'] ?? null,
                            $dims['width'] ?? null,
                            $dims['height'] ?? null,
                            isset($dims['wall_count']) ? (int) $dims['wall_count'] : null,
                        );
                    }

                    // Remove surfaces
                    foreach ($updateDef['remove_surface_ids'] ?? [] as $removeSurfaceId) {
                        try {
                            $surface = $area->surfaces()->findOrFail($removeSurfaceId);
                            $this->removeSurface($surface->id);
                        } catch (\Throwable $e) {
                            $errors[] = "Remove surface {$removeSurfaceId}: {$e->getMessage()}";
                        }
                    }

                    // Update existing surfaces
                    foreach ($updateDef['update_surfaces'] ?? [] as $surfUpdate) {
                        try {
                            $surfaceId = $surfUpdate['surface_id'] ?? null;
                            if (! $surfaceId) {
                                $errors[] = 'update_surfaces: missing surface_id';

                                continue;
                            }

                            $surface = $area->surfaces()->findOrFail($surfaceId);

                            // Direct field updates (name, surface_category, application_method)
                            $directUpdates = [];
                            if (isset($surfUpdate['name'])) {
                                $directUpdates['name'] = $surfUpdate['name'];
                            }
                            if (isset($surfUpdate['surface_category'])) {
                                $directUpdates['surface_category'] = $surfUpdate['surface_category'];
                            }
                            if (isset($surfUpdate['application_method'])) {
                                $directUpdates['application_method'] = $surfUpdate['application_method'];
                            }
                            if (! empty($directUpdates)) {
                                $surface->update($directUpdates);
                            }

                            // Use updateSurface() for fields that trigger recalculation
                            $hasCalcUpdate = isset($surfUpdate['production_rate_id'])
                                || isset($surfUpdate['coats'])
                                || isset($surfUpdate['paint_product_id'])
                                || isset($surfUpdate['quantity_override']);

                            if ($hasCalcUpdate) {
                                $rateId = $surfUpdate['production_rate_id'] ?? null;
                                if (is_string($rateId) && str_starts_with($rateId, 'ref:')) {
                                    $rateId = $refMap[substr($rateId, 4)] ?? null;
                                }

                                $this->updateSurface(
                                    $surface->id,
                                    $rateId ? (int) $rateId : null,
                                    isset($surfUpdate['coats']) ? (int) $surfUpdate['coats'] : null,
                                    isset($surfUpdate['paint_product_id']) ? (int) $surfUpdate['paint_product_id'] : null,
                                    isset($surfUpdate['quantity_override']) ? (float) $surfUpdate['quantity_override'] : null,
                                );
                            }

                            $surfacesUpdated++;
                        } catch (\Throwable $e) {
                            $errors[] = "Update surface {$surfUpdate['surface_id']}: {$e->getMessage()}";
                        }
                    }

                    // Add new surfaces to existing area
                    foreach ($updateDef['add_surfaces'] ?? [] as $surfDef) {
                        try {
                            $rateId = $surfDef['production_rate_id'];
                            if (is_string($rateId) && str_starts_with($rateId, 'ref:')) {
                                $rateId = $refMap[substr($rateId, 4)] ?? null;
                                if (! $rateId) {
                                    $errors[] = "Unresolved rate ref for surface '{$surfDef['name']}'";

                                    continue;
                                }
                            }

                            $surfResult = $this->addSurfaceToArea(
                                $area->id,
                                $surfDef['surface_category'],
                                (int) $rateId,
                                $surfDef['coats'] ?? 2,
                                isset($surfDef['quantity_override']) ? (float) $surfDef['quantity_override'] : null,
                            );

                            if (! empty($surfDef['name']) && isset($surfResult['surface_id'])) {
                                EstimateAreaSurface::where('id', $surfResult['surface_id'])
                                    ->update(['name' => $surfDef['name']]);
                            }

                            $surfacesCreated++;
                        } catch (\Throwable $e) {
                            $errors[] = "Add surface to area {$area->name}: {$e->getMessage()}";
                        }
                    }

                    // Add addons to existing area
                    foreach ($updateDef['addons'] ?? [] as $addonDef) {
                        try {
                            $this->addAddonToArea(
                                $area->id,
                                (int) $addonDef['nested_template_id'],
                                $addonDef['quantity'] ?? 1,
                            );
                        } catch (\Throwable $e) {
                            $errors[] = "Addon on {$area->name}: {$e->getMessage()}";
                        }
                    }

                    // Add cost items to existing area
                    foreach ($updateDef['cost_items'] ?? [] as $costDef) {
                        try {
                            $this->addCostItem(
                                $estimate,
                                'area',
                                $area->id,
                                $costDef['name'],
                                $costDef['cost_type'] ?? 'material',
                                $costDef['quantity'] ?? 1,
                                $costDef['unit'] ?? 'each',
                                $costDef['unit_cost'] ?? 0,
                                $costDef['unit_price'] ?? 0,
                                $costDef['tier'] ?? null,
                                $costDef['notes'] ?? null,
                            );
                        } catch (\Throwable $e) {
                            $errors[] = "Cost item on {$area->name}: {$e->getMessage()}";
                        }
                    }

                    // Set package tiers on existing area
                    if (isset($updateDef['package_tiers'])) {
                        try {
                            $this->setAreaPackageTiers($area->id, $updateDef['package_tiers'], $estimate);
                        } catch (\Throwable $e) {
                            $errors[] = "Package tiers on {$area->name}: {$e->getMessage()}";
                        }
                    }

                    $areasUpdated++;
                } catch (\Throwable $e) {
                    $errors[] = "update_areas[{$index}]: {$e->getMessage()}";
                }
            }

            // ── Step 8: Apply tier overrides to existing surfaces ──
            if (! empty($tierConfig) && ! empty($plan['apply_tiers_to_existing'])) {
                $estimate->refresh();
                $estimate->load('areas.surfaces');

                foreach ($estimate->areas as $area) {
                    foreach ($area->surfaces as $surface) {
                        // Skip if this surface already got tier overrides from new area creation
                        $existingOverrides = $surface->tier_overrides ?? [];
                        $allTiersPresent = isset($existingOverrides['good'])
                            && isset($existingOverrides['better'])
                            && isset($existingOverrides['best']);

                        if ($allTiersPresent) {
                            continue;
                        }

                        foreach ($tierConfig as $tier => $tConfig) {
                            if (isset($existingOverrides[$tier])) {
                                continue;
                            }

                            try {
                                $this->updateSurfaceTierOverrides(
                                    surfaceId: $surface->id,
                                    tier: $tier,
                                    coats: $tConfig['default_coats'] ?? null,
                                    paintProductId: isset($tConfig['paint_product_id']) ? (int) $tConfig['paint_product_id'] : null,
                                    estimate: $estimate,
                                );
                                $tierOverridesSet++;
                            } catch (\Throwable $e) {
                                $errors[] = "Tier override {$tier}/{$surface->name}: {$e->getMessage()}";
                            }
                        }
                    }
                }
            }

            // ── Step 9: Final recalculation ──
            $estimate = $estimate->fresh();
            $estimate->calculateTotals();
            $estimate->calculateDepositAndBalance();
            $estimate->save();

            EstimateAreaUpdated::dispatch($estimate->id, 0, 'batch-plan-executed', 'AI Builder');

            return [
                'success' => empty($errors),
                'areas_created' => count($areaResults),
                'areas_updated' => $areasUpdated,
                'surfaces_created' => $surfacesCreated,
                'surfaces_updated' => $surfacesUpdated,
                'tier_overrides_set' => $tierOverridesSet,
                'errors' => $errors,
                'area_results' => $areaResults,
                'estimate_total' => (float) ($estimate->total ?? 0),
            ];
        });
    }
}
