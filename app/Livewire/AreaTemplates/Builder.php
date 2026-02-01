<?php

namespace App\Livewire\AreaTemplates;

use App\Models\AreaTemplateSurface;
use App\Models\CompanySetting;
use App\Models\EstimateTemplate;
use App\Models\EstimateTemplateCategory;
use App\Models\NestedAreaTemplate;
use App\Models\PaintProduct;
use App\Models\ProductionRate;
use App\Models\Substrate;
use App\Services\AreaTemplateService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Builder extends Component
{
    public ?EstimateTemplate $template = null;

    public bool $isEditing = false;

    // Basic Info
    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('nullable|string|max:1000')]
    public string $description = '';

    #[Validate('nullable|string|max:50')]
    public string $icon = 'squares-2x2';

    #[Validate('required|in:interior,exterior,both')]
    public string $scopeType = 'interior';

    #[Validate('required|in:private,company')]
    public string $visibility = 'company';

    #[Validate('boolean')]
    public bool $isActive = true;

    #[Validate('required|exists:estimate_template_categories,id')]
    public ?int $templateCategoryId = null;

    // Defaults Section
    #[Validate('nullable|exists:substrates,id')]
    public ?int $defaultSubstrateId = null;

    #[Validate('nullable|exists:paint_products,id')]
    public ?int $defaultPaintProductId = null;

    #[Validate('required|integer|min:1|max:5')]
    public int $defaultCoats = 2;

    // Dimension-based sizing (replaces fixed sizes)
    // Length is optional for exterior (bidding one side at a time)
    public ?float $roomLength = null;

    public float $roomWidth = 12;

    public float $roomHeight = 9;

    // Surfaces Section with production rate type selection
    /** @var array<int, array{id: ?int, name: string, surface_type: string, surface_category: string, production_rate_id: ?int, quantity_source: string, quantity_override: ?float, default_coats: int, is_required: bool, is_default_enabled: bool, fixed_price: ?float}> */
    public array $surfaces = [];

    // Nested Options Section
    /** @var array<int, array{id: ?int, nested_area_template_id: ?int, name: string, is_default_included: bool, max_quantity: ?int}> */
    public array $nestedOptions = [];

    // Live Preview Settings
    public int $previewCoats = 2;

    // Icons available for selection
    public const AVAILABLE_ICONS = [
        'squares-2x2' => 'Grid',
        'home' => 'Home',
        'building-office' => 'Office',
        'building-storefront' => 'Storefront',
        'cube' => 'Cube',
        'paint-brush' => 'Paint Brush',
        'wrench-screwdriver' => 'Tools',
        'sun' => 'Sun',
        'moon' => 'Moon',
        'star' => 'Star',
        'heart' => 'Heart',
        'fire' => 'Fire',
        'bolt' => 'Bolt',
        'sparkles' => 'Sparkles',
        'rectangle-group' => 'Rectangles',
    ];

    // Quantity sources for surfaces
    public const QUANTITY_SOURCES = [
        'wall_sqft' => 'Wall Square Feet',
        'ceiling_sqft' => 'Ceiling Square Feet',
        'perimeter_lnft' => 'Perimeter Linear Feet',
        'door_count' => 'Door Count',
        'window_count' => 'Window Count',
        'custom' => 'Custom Value',
    ];

    public function mount(?EstimateTemplate $template = null): void
    {
        if ($template && $template->exists) {
            $this->template = $template->load(['surfaces.productionRate', 'nestedOptions.nestedAreaTemplate', 'substrate', 'defaultPaintProduct', 'createdBy']);
            $this->isEditing = true;

            // Load basic info
            $this->name = $template->name;
            $this->description = $template->description ?? '';
            $this->icon = $template->icon ?? 'squares-2x2';
            $this->scopeType = $template->scope_type ?? 'interior';
            $this->visibility = $template->visibility === EstimateTemplate::VISIBILITY_SYSTEM
                ? 'company'
                : ($template->visibility ?? 'company');
            $this->isActive = $template->is_active;

            // Load defaults
            $this->templateCategoryId = $template->template_category_id;
            $this->defaultSubstrateId = $template->substrate_id;
            $this->defaultPaintProductId = $template->default_paint_product_id;
            $this->defaultCoats = $template->default_coats ?? 2;

            // Load dimensions from metadata
            $metadata = $template->metadata ?? [];
            $storedLength = $metadata['dimensions']['length'] ?? null;
            // Keep null/0 as null for exterior, use stored value or default for interior
            if ($storedLength === null || $storedLength === 0 || $storedLength === '0' || $storedLength === '') {
                $this->roomLength = ($this->scopeType === 'interior') ? 12.0 : null;
            } else {
                $this->roomLength = (float) $storedLength;
            }
            $this->roomWidth = (float) ($metadata['dimensions']['width'] ?? 12);
            $this->roomHeight = (float) ($metadata['dimensions']['height'] ?? 9);

            // Load surfaces
            $this->loadSurfacesFromTemplate();

            // Load nested options
            $this->loadNestedOptionsFromTemplate();

            // Set preview defaults
            $this->previewCoats = $this->defaultCoats;
        } else {
            // Initialize with defaults for new template
            $this->addDefaultSurfaces();

            // Set default dimensions (interior default)
            $this->roomLength = 12.0;
            $this->roomWidth = 12.0;
            $this->roomHeight = 9.0;

            // Set default category to first room category
            $defaultCategory = EstimateTemplateCategory::query()
                ->byType(EstimateTemplateCategory::TYPE_ROOM)
                ->active()
                ->first();

            if ($defaultCategory) {
                $this->templateCategoryId = $defaultCategory->id;
            }
        }
    }

    /**
     * Load surfaces from template.
     */
    protected function loadSurfacesFromTemplate(): void
    {
        $this->surfaces = [];

        foreach ($this->template->surfaces as $surface) {
            // Determine surface_category from production rate if available
            $surfaceCategory = $surface->productionRate?->surface_category ?? $this->mapSurfaceTypeToCategory($surface->surface_type);

            // Determine quantity source from calculation method
            $quantitySource = $this->mapCalculationMethodToSource($surface);

            $this->surfaces[] = [
                'id' => $surface->id,
                'name' => $surface->name,
                'surface_type' => $surface->surface_type,
                'surface_category' => $surfaceCategory,
                'production_rate_id' => $surface->production_rate_id,
                'quantity_source' => $quantitySource,
                'quantity_override' => $surface->calculation_method === AreaTemplateSurface::CALC_METHOD_FIXED_COUNT
                    ? (float) $surface->calculation_value
                    : null,
                'default_coats' => $surface->default_coats,
                'is_required' => $surface->is_required,
                'is_default_enabled' => $surface->is_default_enabled,
                'fixed_price' => $surface->fixed_price ? (float) $surface->fixed_price : null,
            ];
        }
    }

    /**
     * Map surface type to production rate surface category.
     */
    protected function mapSurfaceTypeToCategory(string $surfaceType): string
    {
        return match ($surfaceType) {
            AreaTemplateSurface::SURFACE_TYPE_WALLS => 'walls',
            AreaTemplateSurface::SURFACE_TYPE_CEILING => 'ceiling',
            AreaTemplateSurface::SURFACE_TYPE_TRIM => 'baseboard',
            AreaTemplateSurface::SURFACE_TYPE_DOORS => 'doors',
            AreaTemplateSurface::SURFACE_TYPE_WINDOWS => 'windows',
            default => 'walls',
        };
    }

    /**
     * Map calculation method to quantity source.
     */
    protected function mapCalculationMethodToSource(AreaTemplateSurface $surface): string
    {
        if ($surface->calculation_method === AreaTemplateSurface::CALC_METHOD_FIXED_COUNT) {
            return match ($surface->surface_type) {
                AreaTemplateSurface::SURFACE_TYPE_DOORS => 'door_count',
                AreaTemplateSurface::SURFACE_TYPE_WINDOWS => 'window_count',
                default => 'custom',
            };
        }

        return match ($surface->surface_type) {
            AreaTemplateSurface::SURFACE_TYPE_WALLS => 'wall_sqft',
            AreaTemplateSurface::SURFACE_TYPE_CEILING => 'ceiling_sqft',
            AreaTemplateSurface::SURFACE_TYPE_TRIM => 'perimeter_lnft',
            AreaTemplateSurface::SURFACE_TYPE_DOORS => 'door_count',
            AreaTemplateSurface::SURFACE_TYPE_WINDOWS => 'window_count',
            default => 'wall_sqft',
        };
    }

    /**
     * Load nested options from template.
     */
    protected function loadNestedOptionsFromTemplate(): void
    {
        $this->nestedOptions = [];

        foreach ($this->template->nestedOptions as $option) {
            $this->nestedOptions[] = [
                'id' => $option->id,
                'nested_area_template_id' => $option->nested_area_template_id,
                'name' => $option->name ?? '',
                'is_default_included' => $option->is_default_included,
                'max_quantity' => $option->max_quantity,
            ];
        }
    }

    /**
     * Add default surfaces for a new template.
     */
    protected function addDefaultSurfaces(): void
    {
        // Add walls surface
        $this->surfaces[] = [
            'id' => null,
            'name' => 'Walls',
            'surface_type' => AreaTemplateSurface::SURFACE_TYPE_WALLS,
            'surface_category' => 'walls',
            'production_rate_id' => null,
            'quantity_source' => 'wall_sqft',
            'quantity_override' => null,
            'default_coats' => 2,
            'is_required' => true,
            'is_default_enabled' => true,
            'fixed_price' => null,
        ];

        // Add ceiling surface
        $this->surfaces[] = [
            'id' => null,
            'name' => 'Ceiling',
            'surface_type' => AreaTemplateSurface::SURFACE_TYPE_CEILING,
            'surface_category' => 'ceiling',
            'production_rate_id' => null,
            'quantity_source' => 'ceiling_sqft',
            'quantity_override' => null,
            'default_coats' => 2,
            'is_required' => false,
            'is_default_enabled' => true,
            'fixed_price' => null,
        ];

        // Add baseboards surface
        $this->surfaces[] = [
            'id' => null,
            'name' => 'Baseboards',
            'surface_type' => AreaTemplateSurface::SURFACE_TYPE_TRIM,
            'surface_category' => 'baseboard',
            'production_rate_id' => null,
            'quantity_source' => 'perimeter_lnft',
            'quantity_override' => null,
            'default_coats' => 2,
            'is_required' => false,
            'is_default_enabled' => true,
            'fixed_price' => null,
        ];
    }

    // ===== COMPUTED DIMENSIONS =====

    /**
     * Calculate wall square footage.
     * Interior: 2 × (L + W) × H (all walls)
     * Exterior: W × H (single side when L is empty)
     */
    #[Computed]
    public function wallSqft(): float
    {
        if ($this->roomLength === null || $this->roomLength == 0) {
            // Exterior single side: just width × height
            return $this->roomWidth * $this->roomHeight;
        }

        // Interior room: all 4 walls
        return 2 * ($this->roomLength + $this->roomWidth) * $this->roomHeight;
    }

    /**
     * Calculate ceiling/floor square footage: L × W.
     * Returns 0 if length is not set (exterior single side).
     */
    #[Computed]
    public function ceilingSqft(): float
    {
        if ($this->roomLength === null || $this->roomLength == 0) {
            return 0;
        }

        return $this->roomLength * $this->roomWidth;
    }

    /**
     * Calculate perimeter linear feet.
     * Interior: 2 × (L + W)
     * Exterior: just W (single side when L is empty)
     */
    #[Computed]
    public function perimeterLnft(): float
    {
        if ($this->roomLength === null || $this->roomLength == 0) {
            // Exterior single side: just the width
            return $this->roomWidth;
        }

        return 2 * ($this->roomLength + $this->roomWidth);
    }

    /**
     * Get quantity for a surface based on its source.
     */
    public function getSurfaceQuantity(array $surface): float
    {
        if ($surface['quantity_source'] === 'custom' && $surface['quantity_override'] !== null) {
            return (float) $surface['quantity_override'];
        }

        return match ($surface['quantity_source']) {
            'wall_sqft' => $this->wallSqft,
            'ceiling_sqft' => $this->ceilingSqft,
            'perimeter_lnft' => $this->perimeterLnft,
            'door_count' => (float) ($surface['quantity_override'] ?? 1),
            'window_count' => (float) ($surface['quantity_override'] ?? 1),
            default => $this->wallSqft,
        };
    }

    // ===== SURFACE MANAGEMENT =====

    /**
     * Add a new surface.
     */
    public function addSurface(): void
    {
        // Default category based on scope
        $defaultCategory = $this->scopeType === 'exterior' ? 'body' : 'walls';
        $defaultQuantitySource = $this->scopeType === 'exterior' ? 'wall_sqft' : 'wall_sqft';

        $this->surfaces[] = [
            'id' => null,
            'name' => '',
            'surface_type' => AreaTemplateSurface::SURFACE_TYPE_OTHER,
            'surface_category' => $defaultCategory,
            'production_rate_id' => null,
            'quantity_source' => $defaultQuantitySource,
            'quantity_override' => null,
            'default_coats' => 2,
            'is_required' => false,
            'is_default_enabled' => true,
            'fixed_price' => null,
        ];
    }

    /**
     * Remove a surface.
     */
    public function removeSurface(int $index): void
    {
        if (count($this->surfaces) <= 1) {
            session()->flash('error', 'At least one surface is required.');

            return;
        }

        unset($this->surfaces[$index]);
        $this->surfaces = array_values($this->surfaces);
    }

    /**
     * Move a surface up in order.
     */
    public function moveSurfaceUp(int $index): void
    {
        if ($index <= 0) {
            return;
        }

        $temp = $this->surfaces[$index - 1];
        $this->surfaces[$index - 1] = $this->surfaces[$index];
        $this->surfaces[$index] = $temp;
    }

    /**
     * Move a surface down in order.
     */
    public function moveSurfaceDown(int $index): void
    {
        if ($index >= count($this->surfaces) - 1) {
            return;
        }

        $temp = $this->surfaces[$index + 1];
        $this->surfaces[$index + 1] = $this->surfaces[$index];
        $this->surfaces[$index] = $temp;
    }

    /**
     * When surface category changes, reset production rate.
     *
     * Each surface category has its own set of production rate types
     * from the PBP spreadsheets. When the category changes, we must
     * reset the production rate so the user selects a valid type.
     */
    public function updatedSurfaces($value, $key): void
    {
        // Only handle surface_category changes
        if (! preg_match('/^(\d+)\.surface_category$/', $key, $matches)) {
            return;
        }

        $index = (int) $matches[1];
        $category = $this->surfaces[$index]['surface_category'];

        // Reset production rate when category changes (each category has its own types)
        $this->surfaces[$index]['production_rate_id'] = null;

        // Map category to surface_type and default quantity_source
        // Interior categories: prep, walls, ceiling, baseboard, doors
        // Exterior categories: prep, body, eaves, fascia, windows, trim, exterior_doors, wood_replacement
        [$surfaceType, $quantitySource] = match ($category) {
            // Interior categories
            'walls' => [AreaTemplateSurface::SURFACE_TYPE_WALLS, 'wall_sqft'],
            'ceiling' => [AreaTemplateSurface::SURFACE_TYPE_CEILING, 'ceiling_sqft'],
            'baseboard' => [AreaTemplateSurface::SURFACE_TYPE_TRIM, 'perimeter_lnft'],
            'doors' => [AreaTemplateSurface::SURFACE_TYPE_DOORS, 'door_count'],
            // Shared category
            'prep' => [AreaTemplateSurface::SURFACE_TYPE_OTHER, 'wall_sqft'],
            // Exterior categories
            'body' => [AreaTemplateSurface::SURFACE_TYPE_WALLS, 'wall_sqft'],
            'eaves' => [AreaTemplateSurface::SURFACE_TYPE_TRIM, 'perimeter_lnft'],
            'fascia' => [AreaTemplateSurface::SURFACE_TYPE_TRIM, 'perimeter_lnft'],
            'windows' => [AreaTemplateSurface::SURFACE_TYPE_WINDOWS, 'window_count'],
            'trim' => [AreaTemplateSurface::SURFACE_TYPE_TRIM, 'perimeter_lnft'],
            'exterior_doors' => [AreaTemplateSurface::SURFACE_TYPE_DOORS, 'door_count'],
            'wood_replacement' => [AreaTemplateSurface::SURFACE_TYPE_OTHER, 'perimeter_lnft'],
            default => [AreaTemplateSurface::SURFACE_TYPE_OTHER, 'wall_sqft'],
        };

        $this->surfaces[$index]['surface_type'] = $surfaceType;
        $this->surfaces[$index]['quantity_source'] = $quantitySource;

        // Clear cached production rates to refresh dropdown
        unset($this->productionRatesByCategory);
    }

    // ===== NESTED OPTIONS MANAGEMENT =====

    /**
     * Add a new nested option.
     */
    public function addNestedOption(): void
    {
        $this->nestedOptions[] = [
            'id' => null,
            'nested_area_template_id' => null,
            'name' => '',
            'is_default_included' => false,
            'max_quantity' => null,
        ];
    }

    /**
     * Remove a nested option.
     */
    public function removeNestedOption(int $index): void
    {
        unset($this->nestedOptions[$index]);
        $this->nestedOptions = array_values($this->nestedOptions);
    }

    // ===== COMPUTED PROPERTIES =====

    /**
     * Get available template categories for selection.
     *
     * @return Collection<int, EstimateTemplateCategory>
     */
    #[Computed]
    public function availableCategories(): Collection
    {
        return EstimateTemplateCategory::query()
            ->active()
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Get available substrates for selection.
     *
     * @return Collection<int, Substrate>
     */
    #[Computed]
    public function availableSubstrates(): Collection
    {
        return Substrate::query()
            ->active()
            ->forScope($this->scopeType)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Get available paint products for selection.
     *
     * @return Collection<int, PaintProduct>
     */
    #[Computed]
    public function availablePaintProducts(): Collection
    {
        return PaintProduct::query()
            ->active()
            ->forScope($this->scopeType)
            ->with('brand')
            ->orderBy('paint_brand_id')
            ->orderBy('name')
            ->get();
    }

    /**
     * Get surface categories for current scope.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function surfaceCategories(): array
    {
        return ProductionRate::getCategoriesForScope($this->scopeType);
    }

    /**
     * Get production rates grouped by surface category.
     *
     * @return Collection<string, Collection<int, ProductionRate>>
     */
    #[Computed]
    public function productionRatesByCategory(): Collection
    {
        return ProductionRate::query()
            ->active()
            ->where('scope', $this->scopeType)
            ->orderBy('surface_category')
            ->orderBy('sort_order')
            ->get()
            ->groupBy('surface_category');
    }

    /**
     * Get production rates for a specific surface category.
     *
     * @return Collection<int, ProductionRate>
     */
    public function getProductionRatesForCategory(string $category): Collection
    {
        return $this->productionRatesByCategory[$category] ?? collect();
    }

    /**
     * Get available nested area templates for selection.
     *
     * @return Collection<int, NestedAreaTemplate>
     */
    #[Computed]
    public function availableNestedTemplates(): Collection
    {
        $query = NestedAreaTemplate::query()
            ->active()
            ->ordered();

        if ($this->scopeType !== 'both') {
            $query->forScope($this->scopeType);
        }

        return $query->get();
    }

    /**
     * Calculate the live preview using spreadsheet-based rates.
     *
     * @return array{surfaces: array, nested: array, totals: array}|null
     */
    #[Computed]
    public function previewCalculation(): ?array
    {
        if (empty($this->surfaces)) {
            return null;
        }

        try {
            $surfaceBreakdowns = [];
            $totalLaborHours = 0;
            $totalMaterialCost = 0;

            // Get company settings
            $settings = CompanySetting::getInstance();
            $hourlyRate = $settings->getEffectiveHourlyRateForScope($this->scopeType);

            foreach ($this->surfaces as $index => $surface) {
                // Skip disabled surfaces (unless required)
                if (! $surface['is_required'] && ! $surface['is_default_enabled']) {
                    continue;
                }

                // Get quantity based on source
                $quantity = $this->getSurfaceQuantity($surface);

                // Get production rate
                $rate = $surface['production_rate_id']
                    ? ProductionRate::find($surface['production_rate_id'])
                    : null;

                // Calculate hours and cost
                if ($surface['fixed_price'] !== null && $surface['fixed_price'] > 0) {
                    $hours = 0;
                    $cost = (float) $surface['fixed_price'];
                    $totalMaterialCost += $cost;
                } else {
                    // Use rate's calculation method
                    if ($rate) {
                        $hours = $rate->calculateHoursForQuantity($quantity, $this->previewCoats);
                    } else {
                        // Fallback: basic calculation
                        $productionRate = 100; // Default 100 units/hr
                        $coatMultiplier = $this->getCoatMultiplier($this->previewCoats);
                        $hours = ($quantity / $productionRate) * $coatMultiplier;
                    }

                    // Estimate material cost
                    $materialCostPerUnit = $rate ? (float) ($rate->material_cost_per_unit ?? 0.15) : 0.15;
                    $coatMultiplier = $this->getCoatMultiplier($this->previewCoats);
                    $totalMaterialCost += $quantity * $materialCostPerUnit * $coatMultiplier;
                }

                $totalLaborHours += $hours;

                // Get unit type for display
                $unitType = match ($surface['quantity_source']) {
                    'wall_sqft', 'ceiling_sqft' => 'sqft',
                    'perimeter_lnft' => 'lnft',
                    'door_count', 'window_count', 'custom' => 'units',
                    default => 'sqft',
                };

                $surfaceBreakdowns[] = [
                    'name' => $surface['name'] ?: 'Surface '.($index + 1),
                    'quantity' => round($quantity, 1),
                    'unit' => $unitType,
                    'hours' => round($hours, 2),
                    'rate_name' => $rate?->name ?? 'No rate selected',
                    'rate_value' => $rate ? number_format($rate->production_rate, 0).' '.$rate->rate_type_label : '-',
                ];
            }

            // Calculate nested options
            $nestedBreakdowns = [];
            $nestedTotal = 0;

            foreach ($this->nestedOptions as $option) {
                if (! $option['is_default_included']) {
                    continue;
                }

                if ($option['nested_area_template_id']) {
                    $nestedTemplate = NestedAreaTemplate::find($option['nested_area_template_id']);
                    if ($nestedTemplate) {
                        $price = (float) $nestedTemplate->fixed_price;
                        $nestedTotal += $price;

                        $nestedBreakdowns[] = [
                            'name' => $option['name'] ?: $nestedTemplate->name,
                            'quantity' => 1,
                            'price' => round($price, 2),
                        ];
                    }
                }
            }

            // Calculate costs using PBP formula
            $laborCost = $totalLaborHours * $hourlyRate;
            // Cleanup ratio = labor hours per 1 cleanup hour (e.g., 16 means 1 hr cleanup per 16 hrs work)
            $cleanupHours = $settings->calculateCleanupHours($totalLaborHours, $this->scopeType);
            $cleanupCost = $cleanupHours * $hourlyRate;
            $sundriesCost = $settings->getSundriesCostForScope($this->scopeType);

            $subtotal = $laborCost + $cleanupCost + $sundriesCost + $totalMaterialCost + $nestedTotal;
            $withMargins = $settings->calculateWithMarginsForScope($subtotal, $this->scopeType);

            return [
                'dimensions' => [
                    'length' => $this->roomLength,
                    'width' => $this->roomWidth,
                    'height' => $this->roomHeight,
                    'wall_sqft' => round($this->wallSqft, 0),
                    'ceiling_sqft' => round($this->ceilingSqft, 0),
                    'perimeter_lnft' => round($this->perimeterLnft, 0),
                ],
                'surfaces' => $surfaceBreakdowns,
                'nested' => $nestedBreakdowns,
                'totals' => [
                    'labor_hours' => round($totalLaborHours, 2),
                    'cleanup_hours' => round($cleanupHours, 2),
                    'total_hours' => round($totalLaborHours + $cleanupHours, 2),
                    'labor_cost' => round($laborCost, 2),
                    'cleanup_cost' => round($cleanupCost, 2),
                    'sundries_cost' => round($sundriesCost, 2),
                    'material_cost' => round($totalMaterialCost, 2),
                    'nested_cost' => round($nestedTotal, 2),
                    'subtotal' => round($subtotal, 2),
                    'with_margins' => round($withMargins, 2),
                    'hourly_rate' => round($hourlyRate, 2),
                ],
            ];
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Get coat multiplier.
     */
    protected function getCoatMultiplier(int $coats): float
    {
        return match ($coats) {
            1 => 1.0,
            2 => 1.8,
            3 => 2.5,
            4 => 3.1,
            default => 1.0 + (($coats - 1) * 0.7),
        };
    }

    // ===== LIFECYCLE HOOKS =====

    /**
     * When scope type changes, reset scope-specific selections.
     */
    public function updatedScopeType(): void
    {
        // Clear cached computed properties
        unset($this->availableSubstrates, $this->availablePaintProducts, $this->availableNestedTemplates, $this->productionRatesByCategory, $this->surfaceCategories);

        // Get valid categories for new scope
        $validCategories = ProductionRate::getCategoriesForScope($this->scopeType);
        $defaultCategory = array_key_first($validCategories) ?? 'walls';

        // Reset surface categories and production rates for new scope
        foreach ($this->surfaces as $index => $surface) {
            // Reset production rate
            $this->surfaces[$index]['production_rate_id'] = null;

            // Reset surface category to first valid one for new scope if current is invalid
            if (! isset($validCategories[$surface['surface_category']])) {
                $this->surfaces[$index]['surface_category'] = $defaultCategory;

                // Also update quantity source based on new category
                $this->surfaces[$index]['quantity_source'] = match ($defaultCategory) {
                    'walls', 'body' => 'wall_sqft',
                    'ceiling' => 'ceiling_sqft',
                    'baseboard', 'trim', 'fascia', 'eaves' => 'perimeter_lnft',
                    'doors', 'exterior_doors' => 'door_count',
                    'windows' => 'window_count',
                    default => 'wall_sqft',
                };
            }
        }

        // For exterior, clear length if switching from interior
        if ($this->scopeType === 'exterior') {
            $this->roomLength = null;
        } elseif ($this->roomLength === null) {
            // For interior, set default length if empty
            $this->roomLength = 12;
        }
    }

    // ===== VALIDATION =====

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'icon' => ['nullable', 'string', 'max:50'],
            'scopeType' => ['required', 'in:interior,exterior,both'],
            'visibility' => ['required', 'in:private,company'],
            'isActive' => ['boolean'],
            'templateCategoryId' => ['required', 'exists:estimate_template_categories,id'],
            'defaultSubstrateId' => ['nullable', 'exists:substrates,id'],
            'defaultPaintProductId' => ['nullable', 'exists:paint_products,id'],
            'defaultCoats' => ['required', 'integer', 'min:1', 'max:5'],
            // Length is optional for exterior (bidding one side at a time)
            'roomLength' => $this->scopeType === 'interior'
                ? ['required', 'numeric', 'min:1', 'max:200']
                : ['nullable', 'numeric', 'min:0', 'max:200'],
            'roomWidth' => ['required', 'numeric', 'min:1', 'max:200'],
            'roomHeight' => ['required', 'numeric', 'min:1', 'max:50'],
            'surfaces' => ['required', 'array', 'min:1'],
            'surfaces.*.name' => ['required', 'string', 'max:255'],
            'surfaces.*.surface_type' => ['required', 'in:'.implode(',', AreaTemplateSurface::SURFACE_TYPES)],
            'surfaces.*.surface_category' => ['required', 'string', 'max:50'],
            'surfaces.*.production_rate_id' => ['nullable', 'exists:production_rates,id'],
            'surfaces.*.quantity_source' => ['required', 'in:'.implode(',', array_keys(self::QUANTITY_SOURCES))],
            'surfaces.*.quantity_override' => ['nullable', 'numeric', 'min:0'],
            'surfaces.*.default_coats' => ['required', 'integer', 'min:1', 'max:5'],
            'surfaces.*.is_required' => ['boolean'],
            'surfaces.*.is_default_enabled' => ['boolean'],
            'surfaces.*.fixed_price' => ['nullable', 'numeric', 'min:0'],
            'nestedOptions' => ['array'],
            'nestedOptions.*.nested_area_template_id' => ['nullable', 'exists:nested_area_templates,id'],
            'nestedOptions.*.name' => ['nullable', 'string', 'max:255'],
            'nestedOptions.*.is_default_included' => ['boolean'],
            'nestedOptions.*.max_quantity' => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function messages(): array
    {
        return [
            'name.required' => 'Template name is required.',
            'surfaces.required' => 'At least one surface is required.',
            'surfaces.min' => 'At least one surface is required.',
            'surfaces.*.name.required' => 'Each surface must have a name.',
        ];
    }

    // ===== ACTIONS =====

    /**
     * Save the template.
     */
    public function save(): void
    {
        $this->validate();

        // Build metadata with dimensions
        $metadata = [
            'dimensions' => [
                'length' => $this->roomLength,
                'width' => $this->roomWidth,
                'height' => $this->roomHeight,
            ],
            'computed' => [
                'wall_sqft' => $this->wallSqft,
                'ceiling_sqft' => $this->ceilingSqft,
                'perimeter_lnft' => $this->perimeterLnft,
            ],
        ];

        $data = [
            'name' => $this->name,
            'description' => $this->description ?: null,
            'icon' => $this->icon,
            'scope_type' => $this->scopeType,
            'visibility' => $this->visibility,
            'is_active' => $this->isActive,
            'template_category_id' => $this->templateCategoryId,
            'substrate_id' => $this->defaultSubstrateId,
            'default_paint_product_id' => $this->defaultPaintProductId,
            'default_coats' => $this->defaultCoats,
            'default_quantity' => $this->ceilingSqft, // Use ceiling sqft as default room size
            'metadata' => $metadata,
            'surfaces' => $this->prepareSurfacesForSave(),
            'nested_options' => $this->prepareNestedOptionsForSave(),
        ];

        $service = app(AreaTemplateService::class);

        if ($this->isEditing) {
            // Check if user can modify
            if (! $this->template->canBeModifiedBy(auth()->user())) {
                session()->flash('error', 'You do not have permission to modify this template.');

                return;
            }

            $service->updateTemplate($this->template, $data);
            session()->flash('success', 'Template updated successfully.');
        } else {
            $service->createTemplate($data, auth()->user());
            session()->flash('success', 'Template created successfully.');
        }

        $this->redirect(route('area-templates.index'), navigate: true);
    }

    /**
     * Prepare surfaces data for saving.
     */
    protected function prepareSurfacesForSave(): array
    {
        return array_map(function ($surface, $index) {
            // Determine calculation method and value based on quantity source
            [$calcMethod, $calcValue] = match ($surface['quantity_source']) {
                'wall_sqft' => [AreaTemplateSurface::CALC_METHOD_RATIO, 1.0],
                'ceiling_sqft' => [AreaTemplateSurface::CALC_METHOD_RATIO, 1.0],
                'perimeter_lnft' => [AreaTemplateSurface::CALC_METHOD_PER_LNFT, 1.0],
                'door_count', 'window_count' => [AreaTemplateSurface::CALC_METHOD_FIXED_COUNT, $surface['quantity_override'] ?? 1],
                'custom' => [AreaTemplateSurface::CALC_METHOD_FIXED_COUNT, $surface['quantity_override'] ?? 1],
                default => [AreaTemplateSurface::CALC_METHOD_RATIO, 1.0],
            };

            // Determine unit type based on quantity source
            $unitType = match ($surface['quantity_source']) {
                'wall_sqft', 'ceiling_sqft' => AreaTemplateSurface::UNIT_TYPE_SQFT,
                'perimeter_lnft' => AreaTemplateSurface::UNIT_TYPE_LNFT,
                default => AreaTemplateSurface::UNIT_TYPE_UNITS,
            };

            return [
                'name' => $surface['name'],
                'surface_type' => $surface['surface_type'],
                'calculation_method' => $surface['fixed_price'] ? AreaTemplateSurface::CALC_METHOD_FIXED_PRICE : $calcMethod,
                'calculation_value' => $surface['fixed_price'] ? 1.0 : (float) $calcValue,
                'unit_type' => $unitType,
                'production_rate_id' => $surface['production_rate_id'],
                'default_coats' => (int) $surface['default_coats'],
                'is_required' => (bool) $surface['is_required'],
                'is_default_enabled' => (bool) $surface['is_default_enabled'],
                'fixed_price' => $surface['fixed_price'] ? (float) $surface['fixed_price'] : null,
                'sort_order' => $index,
            ];
        }, $this->surfaces, array_keys($this->surfaces));
    }

    /**
     * Prepare nested options data for saving.
     */
    protected function prepareNestedOptionsForSave(): array
    {
        return array_filter(
            array_map(function ($option, $index) {
                if (! $option['nested_area_template_id']) {
                    return null;
                }

                return [
                    'nested_area_template_id' => $option['nested_area_template_id'],
                    'name' => $option['name'] ?: null,
                    'is_default_included' => (bool) $option['is_default_included'],
                    'max_quantity' => $option['max_quantity'] ? (int) $option['max_quantity'] : null,
                    'sort_order' => $index,
                ];
            }, $this->nestedOptions, array_keys($this->nestedOptions))
        );
    }

    /**
     * Cancel and go back.
     */
    public function cancel(): void
    {
        $this->redirect(route('area-templates.index'), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.area-templates.builder', [
            'surfaceTypes' => AreaTemplateSurface::SURFACE_TYPES,
            'quantitySources' => self::QUANTITY_SOURCES,
            'scopeOptions' => [
                EstimateTemplate::SCOPE_TYPE_INTERIOR => 'Interior',
                EstimateTemplate::SCOPE_TYPE_EXTERIOR => 'Exterior',
                EstimateTemplate::SCOPE_TYPE_BOTH => 'Both',
            ],
            'visibilityOptions' => [
                EstimateTemplate::VISIBILITY_PRIVATE => 'Private (Only You)',
                EstimateTemplate::VISIBILITY_COMPANY => 'Company (All Users)',
            ],
            'availableIcons' => self::AVAILABLE_ICONS,
        ])->layout('layouts.app', [
            'title' => $this->isEditing ? 'Edit Area Template: '.$this->template->name : 'Create Area Template',
        ]);
    }
}
