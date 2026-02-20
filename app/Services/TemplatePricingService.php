<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\EstimateTemplate;
use App\Models\PaintProduct;
use App\Models\ProductionRate;
use App\Models\Substrate;

class TemplatePricingService
{
    /**
     * Paint config passed from the component.
     *
     * @var array{paintMode: string, defaultPaintProductId: int|null, paintByCategory: array<string, int|null>, projectScope: string, marginDiscountPercent: float, hourlyRateOverride: float|null}
     */
    protected array $config = [
        'paintMode' => 'single',
        'defaultPaintProductId' => null,
        'paintByCategory' => [],
        'projectScope' => 'interior',
        'marginDiscountPercent' => 0,
        'hourlyRateOverride' => null,
    ];

    /**
     * In-memory cache for paint products to avoid repeated queries.
     *
     * @var array<int, PaintProduct|null>
     */
    protected array $paintProductCache = [];

    public function withConfig(array $config): static
    {
        $this->config = array_merge($this->config, $config);

        return $this;
    }

    /**
     * Infer surface category from surface type and scope.
     */
    public function inferSurfaceCategory(string $surfaceType, string $scopeType): string
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

    /**
     * Get cost breakdown for a template.
     *
     * @param  array<string, mixed>  $options  Optional overrides: substrate_id, paint_product_id
     * @return array{labor_hours: float, labor_cost: float, cleanup_hours: float, cleanup_cost: float, sundries_cost: float, material_cost: float, base_total: float, with_margins: float}
     */
    public function getTemplateCostBreakdown(int $templateId, float $quantity, int $coats, array $options = []): array
    {
        $template = EstimateTemplate::with(['productionRate', 'substrate', 'defaultPaintProduct'])->find($templateId);
        $settings = CompanySetting::getInstance();

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

        if ($surfaceRatios && is_array($surfaceRatios)) {
            return $this->calculateSurfaceBasedBreakdown($template, $surfaceRatios, $quantity, $coats, $settings, $options);
        }

        return $this->calculateSimpleBreakdown($template, $quantity, $coats, $settings, $options);
    }

    /**
     * Map a surface name to its category for paint product resolution.
     *
     * @return string Category key (walls, ceiling, trim, doors, body, fascia, eaves)
     */
    public function mapSurfaceToCategory(string $surfaceName): string
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
            default => 'walls',
        };
    }

    /**
     * Resolve the paint product ID for a specific surface.
     * Priority depends on paintMode:
     * - single: surface override -> defaultPaintProductId -> company category default
     * - category: surface override -> paintByCategory -> company category default -> defaultPaintProductId
     *
     * @param  array<string, mixed>  $options  Optional overrides: paint_product_id
     */
    public function resolvePaintForSurface(string $surfaceName, array $options): ?int
    {
        if (! empty($options['paint_product_id'])) {
            return (int) $options['paint_product_id'];
        }

        $category = $this->mapSurfaceToCategory($surfaceName);
        $settings = CompanySetting::getInstance();
        $paintMode = $this->config['paintMode'];
        $defaultPaintProductId = $this->config['defaultPaintProductId'];
        $paintByCategory = $this->config['paintByCategory'];
        $projectScope = $this->config['projectScope'];

        if ($paintMode === 'single') {
            if ($defaultPaintProductId) {
                return $defaultPaintProductId;
            }

            $companyDefault = $settings->getDefaultPaintForCategory($category, $projectScope);
            if ($companyDefault) {
                return $companyDefault;
            }
        } else {
            if (! empty($paintByCategory[$category])) {
                return (int) $paintByCategory[$category];
            }

            $companyDefault = $settings->getDefaultPaintForCategory($category, $projectScope);
            if ($companyDefault) {
                return $companyDefault;
            }

            if ($defaultPaintProductId) {
                return $defaultPaintProductId;
            }
        }

        return null;
    }

    /**
     * Calculate breakdown for templates with surface_ratios (rooms).
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
        $hourlyWage = $this->getEffectiveRateForScope($scopeType);

        $substrateId = $options['substrate_id'] ?? $template->substrate_id;

        $totalLaborHours = 0;
        $totalLaborCost = 0;
        $totalMaterialCost = 0;
        $surfaces = [];

        foreach ($surfaceRatios as $surfaceName => $surfaceConfig) {
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
                $hourlyWage,
                $settings,
                $surfaceOptions
            );

            $totalLaborHours += $surfaceResult['labor_hours'];
            $totalLaborCost += $surfaceResult['labor_cost'];
            $totalMaterialCost += $surfaceResult['material_cost'];
            $surfaces[$surfaceName] = $surfaceResult;
        }

        $cleanupHours = $settings->calculateCleanupHours($totalLaborHours, $scopeType);
        $cleanupCost = $cleanupHours * $hourlyWage;
        $sundriesCost = $settings->calculateSundriesCost($totalLaborHours, $scopeType);

        $cogsResult = $settings->calculateCOGS($totalLaborCost, $cleanupCost, $sundriesCost, $totalMaterialCost);
        $cogs = $cogsResult['cogs'];

        $effectiveMargin = min(99, $settings->getMarginPercentForScope($scopeType) + $this->config['marginDiscountPercent']);
        $withMargins = round($cogs / ($effectiveMargin / 100), 2);

        $primaryPaintProductId = $options['paint_product_id'] ?? $this->config['defaultPaintProductId'] ?? $template->default_paint_product_id;

        return [
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
            'surfaces' => $surfaces,
            'substrate_id' => $substrateId,
            'paint_product_id' => $primaryPaintProductId,
        ];
    }

    /**
     * Calculate cost for a single surface based on its config.
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

        $quantity = $this->calculateSurfaceQuantity($config, $roomSqft);
        $unit = $type === 'unit' ? 'units' : ($type === 'lnft' ? 'lnft' : 'sqft');

        $rate = null;
        if (isset($config['rate_id'])) {
            $rate = ProductionRate::find($config['rate_id']);
        }

        if (! $rate) {
            $productionRate = 200;
        } else {
            $productionRate = $rate->production_rate;
        }

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

        $substrateMultiplier = $prepMultiplier * $paintMultiplier;

        $coatMultiplier = $rate ? $rate->getCoatMultiplier($coats) : ($coats > 1 ? 1.8 : 1.0);

        $laborHours = ($quantity / $productionRate) * $prepMultiplier * $paintMultiplier * $coatMultiplier;
        $laborCost = $laborHours * $hourlyRate;

        $fallbackMaterialCost = null;

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
     * Formula: (sqft x coats / coverage) x paint_price x material_multiplier + recycle_fee
     *
     * @param  float|null  $fallbackMaterialCostPerUnit  From production rate, used if no paint product
     */
    public function calculateMaterialCostForSurface(
        float $quantity,
        int $coats,
        ?int $paintProductId,
        ?Substrate $substrate,
        CompanySetting $settings,
        ?float $fallbackMaterialCostPerUnit = null,
        float $coatMultiplier = 1.0
    ): float {
        $paintProduct = null;
        if ($paintProductId) {
            if (! array_key_exists($paintProductId, $this->paintProductCache)) {
                $this->paintProductCache[$paintProductId] = PaintProduct::find($paintProductId);
            }
            $paintProduct = $this->paintProductCache[$paintProductId];
        }

        if (! $paintProduct) {
            return 0.0;
        }

        // Use PaintProduct::calculateCost() for consistency with the save flow
        $costCalc = $paintProduct->calculateCost(
            $quantity,
            $coats,
            'brush_roll',
            (float) ($settings->recycle_fee_per_gallon ?? 0.54)
        );

        $materialCost = $costCalc['total_cost'];

        // Apply substrate material multiplier if present
        $materialMultiplier = $substrate ? (float) ($substrate->material_multiplier ?? 1.0) : 1.0;
        if ($materialMultiplier !== 1.0) {
            $materialCost *= $materialMultiplier;
        }

        return $materialCost;
    }

    /**
     * Calculate surface quantity from room sqft using surface config.
     *
     * @param  array<string, mixed>  $config
     */
    public function calculateSurfaceQuantity(array $config, float $roomSqft): float
    {
        $type = $config['type'] ?? 'sqft';

        if (isset($config['ratio'])) {
            return $roomSqft * (float) $config['ratio'];
        }

        if (isset($config['per_100sqft'])) {
            return ($roomSqft / 100) * (float) $config['per_100sqft'];
        }

        if (isset($config['per_lnft'])) {
            return $roomSqft * (float) $config['per_lnft'];
        }

        if (isset($config['count'])) {
            return (float) $config['count'];
        }

        return $roomSqft;
    }

    /**
     * Simple calculation for templates without surface ratios.
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
        if (! $template->productionRate) {
            $scopeType = $template->scope_type ?? 'interior';

            $hasCosting = $template->estimated_labor_hours !== null
                || $template->labor_cost !== null
                || $template->material_cost !== null;

            if ($hasCosting) {
                $breakdown = $template->calculateFixedPrice($scopeType);
                $multiplier = $quantity > 1 ? $quantity : 1;

                return [
                    'labor_hours' => $breakdown['labor_hours'] * $multiplier,
                    'labor_cost' => $breakdown['labor_cost'] * $multiplier,
                    'cleanup_hours' => 0,
                    'cleanup_cost' => 0,
                    'sundries_cost' => 0,
                    'material_cost' => $breakdown['material_cost'] * $multiplier,
                    'material_tax' => 0,
                    'labor_tax' => 0,
                    'base_total' => $breakdown['cogs'] * $multiplier,
                    'with_margins' => $breakdown['final_price'] * $multiplier,
                    'margin_percent' => $breakdown['margin_percent'],
                    'profit' => $breakdown['profit'] * $multiplier,
                    'substrate_id' => null,
                    'paint_product_id' => null,
                ];
            }

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

        $substrateId = $options['substrate_id'] ?? $template->substrate_id;
        $paintProductId = $options['paint_product_id'] ?? $template->default_paint_product_id;

        $substrate = $substrateId ? Substrate::find($substrateId) : null;
        $prepMultiplier = $substrate ? (float) ($substrate->prep_time_multiplier ?? 1.0) : 1.0;
        $paintMultiplier = $substrate ? (float) ($substrate->paint_time_multiplier ?? 1.0) : 1.0;

        $coatMultiplier = $rate->getCoatMultiplier($coats);

        $laborHours = ($quantity / $rate->production_rate) * $prepMultiplier * $paintMultiplier * $coatMultiplier;

        $hourlyWage = $this->getEffectiveRateForScope($scopeType);

        $laborCost = $laborHours * $hourlyWage;

        $cleanupHours = $settings->calculateCleanupHours($laborHours, $scopeType);
        $cleanupCost = $cleanupHours * $hourlyWage;

        $sundriesCost = $settings->calculateSundriesCost($laborHours, $scopeType);

        $materialCost = $this->calculateMaterialCostForSurface(
            $quantity,
            $coats,
            $paintProductId,
            $substrate,
            $settings,
            null,
            $coatMultiplier
        );

        $cogsResult = $settings->calculateCOGS($laborCost, $cleanupCost, $sundriesCost, $materialCost);
        $cogs = $cogsResult['cogs'];

        $marginPercent = min(99, $settings->getMarginPercentForScope($scopeType) + $this->config['marginDiscountPercent']);
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
            'base_total' => $cogs,
            'with_margins' => round($withMargins, 2),
            'margin_percent' => $marginPercent,
            'profit' => $profit,
            'substrate_id' => $substrateId,
            'paint_product_id' => $paintProductId,
        ];
    }

    /**
     * Get the effective hourly rate for a scope, using estimate override if set.
     * Returns effective rate = base wage x payroll burden multiplier.
     */
    public function getEffectiveRateForScope(string $scopeType): float
    {
        $settings = CompanySetting::getInstance();
        $hourlyRateOverride = $this->config['hourlyRateOverride'];

        if ($hourlyRateOverride !== null && $hourlyRateOverride > 0) {
            return $hourlyRateOverride * (float) $settings->payroll_burden_multiplier;
        }

        return $settings->getEffectiveHourlyRateForScope($scopeType);
    }
}
