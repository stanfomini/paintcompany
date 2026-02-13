<?php

use App\Models\AreaTemplateSurface;
use App\Models\CompanySetting;
use App\Models\EstimateTemplate;
use App\Models\EstimateTemplateCategory;
use App\Models\NestedAreaTemplate;
use App\Models\PaintProduct;
use App\Models\ProductionRate;
use App\Models\Substrate;
use App\Services\AreaTemplateService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Validate;
use Livewire\Component;

new class extends Component
{
    public function boot(): void
    {
        if (! auth()->user()?->can('production_rates.manage')) {
            abort(403);
        }
    }

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
            session()->flash('error', __('At least one surface is required.'));

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
     * Get available icons for template selection.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function availableIcons(): array
    {
        return self::AVAILABLE_ICONS;
    }

    /**
     * Get scope options for dropdown.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function scopeOptions(): array
    {
        return [
            'interior' => 'Interior',
            'exterior' => 'Exterior',
            'both' => 'Both',
        ];
    }

    /**
     * Get visibility options for dropdown.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function visibilityOptions(): array
    {
        return [
            'private' => 'Private',
            'company' => 'Company',
        ];
    }

    /**
     * Get quantity source options for dropdowns.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function quantitySources(): array
    {
        return self::QUANTITY_SOURCES;
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
            $withMargins = $settings->calculateOTDFromCOGS($subtotal, $this->scopeType);

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
            'name.required' => __('Template name is required.'),
            'surfaces.required' => __('At least one surface is required.'),
            'surfaces.min' => __('At least one surface is required.'),
            'surfaces.*.name.required' => __('Each surface must have a name.'),
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
                session()->flash('error', __('You do not have permission to modify this template.'));

                return;
            }

            $service->updateTemplate($this->template, $data);
            session()->flash('success', __('Template updated successfully.'));
        } else {
            $service->createTemplate($data, auth()->user());
            session()->flash('success', __('Template created successfully.'));
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
}; ?>

<div>
    {{-- Header --}}
    <div class="max-w-7xl mx-auto mb-6">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <flux:button variant="ghost" wire:click="cancel" icon="arrow-left">
                    {{ __('Back') }}
                </flux:button>
                <flux:heading size="xl">
                    @if ($isEditing)
                        {{ __('Edit Area Template: :name', ['name' => __($template->name)]) }}
                    @else
                        {{ __('Create Area Template') }}
                    @endif
                </flux:heading>
            </div>
        </div>
    </div>

    @if (session('error'))
        <div class="max-w-7xl mx-auto mb-4">
            <flux:callout variant="danger">
                {{ session('error') }}
            </flux:callout>
        </div>
    @endif

    <div class="max-w-7xl mx-auto">
        <div class="lg:grid lg:grid-cols-12 lg:gap-6">
            {{-- Main Form Column --}}
            <div class="lg:col-span-8 space-y-6">
                {{-- Basic Info Section --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900">
                    <div class="p-4 border-b border-zinc-200 dark:border-zinc-700">
                        <flux:heading size="sm">{{ __('Basic Information') }}</flux:heading>
                    </div>
                    <div class="p-4 space-y-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <flux:field>
                                <flux:label>{{ __('Template Name') }}</flux:label>
                                <flux:input wire:model.live.debounce.300ms="name" :placeholder="__('e.g., Living Room')" />
                                <flux:error name="name" />
                            </flux:field>

                            <flux:field>
                                <flux:label>{{ __('Icon') }}</flux:label>
                                <flux:select wire:model="icon">
                                    @foreach ($this->availableIcons as $iconKey => $iconLabel)
                                        <option value="{{ $iconKey }}">{{ $iconLabel }}</option>
                                    @endforeach
                                </flux:select>
                                <flux:error name="icon" />
                            </flux:field>
                        </div>

                        <flux:field>
                            <flux:label>{{ __('Description (Optional)') }}</flux:label>
                            <flux:textarea wire:model.blur="description" rows="2" :placeholder="__('Brief description of this template...')" />
                            <flux:error name="description" />
                        </flux:field>

                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <flux:field>
                                <flux:label>{{ __('Category') }}</flux:label>
                                <flux:select wire:model="templateCategoryId">
                                    @foreach ($this->availableCategories as $category)
                                        <option value="{{ $category->id }}">{{ __($category->name) }}</option>
                                    @endforeach
                                </flux:select>
                                <flux:error name="templateCategoryId" />
                            </flux:field>

                            <flux:field>
                                <flux:label>{{ __('Scope Type') }}</flux:label>
                                <flux:select wire:model.live="scopeType">
                                    @foreach ($this->scopeOptions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </flux:select>
                                <flux:error name="scopeType" />
                            </flux:field>

                            <flux:field>
                                <flux:label>{{ __('Visibility') }}</flux:label>
                                <flux:select wire:model="visibility">
                                    @foreach ($this->visibilityOptions as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </flux:select>
                                <flux:error name="visibility" />
                            </flux:field>

                            <flux:field>
                                <flux:label>{{ __('Status') }}</flux:label>
                                <div class="flex items-center gap-2 pt-2">
                                    <flux:switch wire:model="isActive" />
                                    <span class="text-sm text-zinc-600 dark:text-zinc-400">
                                        {{ $isActive ? __('Active') : __('Inactive') }}
                                    </span>
                                </div>
                            </flux:field>
                        </div>
                    </div>
                </div>

                {{-- Dimensions Section --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900">
                    <div class="p-4 border-b border-zinc-200 dark:border-zinc-700">
                        <flux:heading size="sm">
                            @if ($scopeType === 'exterior')
                                {{ __('Surface Dimensions') }}
                            @else
                                {{ __('Room Dimensions') }}
                            @endif
                        </flux:heading>
                        <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                            @if ($scopeType === 'exterior')
                                {{ __('Enter width and height for the surface being painted (length optional for multi-wall areas)') }}
                            @else
                                {{ __('Enter room dimensions to calculate wall sqft, ceiling sqft, and perimeter') }}
                            @endif
                        </flux:text>
                    </div>
                    <div class="p-4">
                        <div class="grid gap-4 sm:grid-cols-3">
                            <flux:field>
                                <flux:label>
                                    {{ __('Length (ft)') }}
                                    @if ($scopeType === 'exterior')
                                        <span class="text-zinc-400 text-xs font-normal">{{ __('(optional)') }}</span>
                                    @endif
                                </flux:label>
                                <flux:input
                                    wire:model.live.debounce.200ms="roomLength"
                                    type="number"
                                    step="0.5"
                                    min="0"
                                    max="200"
                                    placeholder="{{ $scopeType === 'exterior' ? __('Leave empty for single side') : '12' }}"
                                />
                                <flux:error name="roomLength" />
                            </flux:field>

                            <flux:field>
                                <flux:label>{{ __('Width (ft)') }}</flux:label>
                                <flux:input wire:model.live.debounce.200ms="roomWidth" type="number" step="0.5" min="1" max="200" placeholder="12" />
                                <flux:error name="roomWidth" />
                            </flux:field>

                            <flux:field>
                                <flux:label>{{ __('Height (ft)') }}</flux:label>
                                <flux:input wire:model.live.debounce.200ms="roomHeight" type="number" step="0.5" min="1" max="50" placeholder="9" />
                                <flux:error name="roomHeight" />
                            </flux:field>
                        </div>

                        {{-- Calculated Values --}}
                        <div class="mt-4 p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                            <div class="text-xs font-medium text-blue-700 dark:text-blue-400 mb-2">{{ __('Calculated Quantities') }}</div>
                            @if ($scopeType === 'exterior' && (!$roomLength || $roomLength == 0))
                                {{-- Exterior single side mode --}}
                                <div class="grid grid-cols-2 gap-4 text-sm">
                                    <div>
                                        <span class="text-zinc-600 dark:text-zinc-400">{{ __('Surface Sqft:') }}</span>
                                        <span class="font-semibold text-zinc-900 dark:text-zinc-100 ml-1">{{ number_format($this->wallSqft, 0) }}</span>
                                        <div class="text-xs text-zinc-500">{{ __('W x H (single side)') }}</div>
                                    </div>
                                    <div>
                                        <span class="text-zinc-600 dark:text-zinc-400">{{ __('Linear Feet:') }}</span>
                                        <span class="font-semibold text-zinc-900 dark:text-zinc-100 ml-1">{{ number_format($this->perimeterLnft, 0) }} {{ __('lnft') }}</span>
                                        <div class="text-xs text-zinc-500">{{ __('Width only') }}</div>
                                    </div>
                                </div>
                            @else
                                {{-- Interior or exterior with length --}}
                                <div class="grid grid-cols-3 gap-4 text-sm">
                                    <div>
                                        <span class="text-zinc-600 dark:text-zinc-400">{{ __('Wall Sqft:') }}</span>
                                        <span class="font-semibold text-zinc-900 dark:text-zinc-100 ml-1">{{ number_format($this->wallSqft, 0) }}</span>
                                        <div class="text-xs text-zinc-500">2 x (L + W) x H</div>
                                    </div>
                                    <div>
                                        <span class="text-zinc-600 dark:text-zinc-400">{{ __('Ceiling Sqft:') }}</span>
                                        <span class="font-semibold text-zinc-900 dark:text-zinc-100 ml-1">{{ number_format($this->ceilingSqft, 0) }}</span>
                                        <div class="text-xs text-zinc-500">L x W</div>
                                    </div>
                                    <div>
                                        <span class="text-zinc-600 dark:text-zinc-400">{{ __('Perimeter:') }}</span>
                                        <span class="font-semibold text-zinc-900 dark:text-zinc-100 ml-1">{{ number_format($this->perimeterLnft, 0) }} {{ __('lnft') }}</span>
                                        <div class="text-xs text-zinc-500">2 x (L + W)</div>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>
                </div>

                {{-- Surfaces Section --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900">
                    <div class="p-4 border-b border-zinc-200 dark:border-zinc-700 flex items-center justify-between">
                        <div>
                            <flux:heading size="sm">{{ __('Surfaces') }}</flux:heading>
                            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                                {{ __('Define surfaces and select production rate types from the spreadsheet') }}
                            </flux:text>
                        </div>
                        <flux:button wire:click="addSurface" size="sm" icon="plus">
                            {{ __('Add Surface') }}
                        </flux:button>
                    </div>
                    <div class="p-4">
                        @if (count($surfaces) === 0)
                            <div class="text-center py-8 text-zinc-500 dark:text-zinc-400">
                                <p>{{ __('No surfaces defined. Add at least one surface.') }}</p>
                            </div>
                        @else
                            <div class="space-y-4">
                                @foreach ($surfaces as $index => $surface)
                                    <div wire:key="surface-{{ $index }}" class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">
                                        {{-- Surface Header --}}
                                        <div class="p-3 bg-zinc-50 dark:bg-zinc-800/50 flex items-center justify-between">
                                            <div class="flex items-center gap-3">
                                                <div class="flex flex-col gap-0.5">
                                                    <button
                                                        wire:click="moveSurfaceUp({{ $index }})"
                                                        type="button"
                                                        class="p-0.5 rounded hover:bg-zinc-200 dark:hover:bg-zinc-700 {{ $index === 0 ? 'opacity-30 cursor-not-allowed' : '' }}"
                                                        {{ $index === 0 ? 'disabled' : '' }}
                                                    >
                                                        <flux:icon name="chevron-up" class="w-3 h-3" />
                                                    </button>
                                                    <button
                                                        wire:click="moveSurfaceDown({{ $index }})"
                                                        type="button"
                                                        class="p-0.5 rounded hover:bg-zinc-200 dark:hover:bg-zinc-700 {{ $index === count($surfaces) - 1 ? 'opacity-30 cursor-not-allowed' : '' }}"
                                                        {{ $index === count($surfaces) - 1 ? 'disabled' : '' }}
                                                    >
                                                        <flux:icon name="chevron-down" class="w-3 h-3" />
                                                    </button>
                                                </div>
                                                <span class="font-medium text-zinc-900 dark:text-zinc-100">
                                                    {{ $surface['name'] ?: 'Surface '.($index + 1) }}
                                                </span>
                                                @if ($surface['is_required'])
                                                    <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300">{{ __('Required') }}</span>
                                                @endif
                                                @if ($surface['production_rate_id'])
                                                    @php $selectedRate = \App\Models\ProductionRate::find($surface['production_rate_id']); @endphp
                                                    @if ($selectedRate)
                                                        <span class="px-2 py-0.5 text-xs font-medium rounded-full bg-green-100 dark:bg-green-900/50 text-green-700 dark:text-green-300">
                                                            {{ number_format($selectedRate->production_rate, 0) }} {{ $selectedRate->rate_type_label }}
                                                        </span>
                                                    @endif
                                                @endif
                                            </div>
                                            <flux:button
                                                wire:click="removeSurface({{ $index }})"
                                                variant="ghost"
                                                size="sm"
                                                icon="trash"
                                                class="text-red-500 hover:text-red-700 dark:text-red-400"
                                            />
                                        </div>

                                        {{-- Surface Details --}}
                                        <div class="p-4 space-y-4">
                                            <div class="grid gap-4 sm:grid-cols-2">
                                                <flux:field>
                                                    <flux:label>{{ __('Surface Name') }}</flux:label>
                                                    <flux:input wire:model.live.debounce.300ms="surfaces.{{ $index }}.name" :placeholder="__('e.g., Walls')" />
                                                    @error("surfaces.{$index}.name") <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                                </flux:field>

                                                <flux:field>
                                                    <flux:label>{{ __('Surface Category') }}</flux:label>
                                                    <flux:select wire:model.live="surfaces.{{ $index }}.surface_category">
                                                        @foreach ($this->surfaceCategories as $catKey => $catLabel)
                                                            <option value="{{ $catKey }}">{{ $catLabel }}</option>
                                                        @endforeach
                                                    </flux:select>
                                                </flux:field>
                                            </div>

                                            <div class="grid gap-4 sm:grid-cols-2">
                                                <flux:field>
                                                    <flux:label>
                                                        {{ __('Production Rate Type') }}
                                                        <span class="text-xs text-zinc-500">{{ __('(from spreadsheet)') }}</span>
                                                    </flux:label>
                                                    <flux:select wire:model.live="surfaces.{{ $index }}.production_rate_id">
                                                        <option value="">{{ __('-- Select Type --') }}</option>
                                                        @foreach ($this->getProductionRatesForCategory($surface['surface_category']) as $rate)
                                                            <option value="{{ $rate->id }}">
                                                                {{ __($rate->name) }} ({{ number_format($rate->production_rate, 0) }} {{ $rate->rate_type_label }})
                                                            </option>
                                                        @endforeach
                                                    </flux:select>
                                                    @if ($this->getProductionRatesForCategory($surface['surface_category'])->isEmpty())
                                                        <p class="text-xs text-amber-600 dark:text-amber-400">{{ __('No rates found for this category. Run the SpreadsheetProductionRateSeeder.') }}</p>
                                                    @endif
                                                </flux:field>

                                                <flux:field>
                                                    <flux:label>{{ __('Quantity Source') }}</flux:label>
                                                    <flux:select wire:model.live="surfaces.{{ $index }}.quantity_source">
                                                        @foreach ($this->quantitySources as $srcKey => $srcLabel)
                                                            <option value="{{ $srcKey }}">{{ $srcLabel }}</option>
                                                        @endforeach
                                                    </flux:select>
                                                </flux:field>
                                            </div>

                                            @if (in_array($surface['quantity_source'], ['door_count', 'window_count', 'custom']))
                                                <flux:field>
                                                    <flux:label>
                                                        @if ($surface['quantity_source'] === 'door_count')
                                                            {{ __('Number of Doors') }}
                                                        @elseif ($surface['quantity_source'] === 'window_count')
                                                            {{ __('Number of Windows') }}
                                                        @else
                                                            {{ __('Custom Quantity') }}
                                                        @endif
                                                    </flux:label>
                                                    <flux:input wire:model.live.debounce.200ms="surfaces.{{ $index }}.quantity_override" type="number" step="1" min="0" placeholder="1" />
                                                </flux:field>
                                            @endif

                                            <div class="grid gap-4 sm:grid-cols-2">
                                                <flux:field>
                                                    <flux:label>{{ __('Default Coats') }}</flux:label>
                                                    <flux:select wire:model.live="surfaces.{{ $index }}.default_coats">
                                                        @for ($i = 1; $i <= 5; $i++)
                                                            <option value="{{ $i }}">{{ $i }} coat{{ $i > 1 ? 's' : '' }}</option>
                                                        @endfor
                                                    </flux:select>
                                                </flux:field>

                                                <flux:field>
                                                    <flux:label>{{ __('Fixed Price Override (Optional)') }}</flux:label>
                                                    <div class="flex items-center gap-2">
                                                        <span class="text-zinc-500">$</span>
                                                        <flux:input wire:model.live.debounce.300ms="surfaces.{{ $index }}.fixed_price" type="number" step="0.01" min="0" :placeholder="__('Leave empty to calculate')" />
                                                    </div>
                                                </flux:field>
                                            </div>

                                            <div class="flex items-center gap-6 pt-2">
                                                <label class="flex items-center gap-2">
                                                    <flux:checkbox wire:model.live="surfaces.{{ $index }}.is_required" />
                                                    <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ __('Required (cannot be disabled)') }}</span>
                                                </label>
                                                <label class="flex items-center gap-2">
                                                    <flux:checkbox wire:model.live="surfaces.{{ $index }}.is_default_enabled" />
                                                    <span class="text-sm text-zinc-700 dark:text-zinc-300">{{ __('Enabled by default') }}</span>
                                                </label>
                                            </div>

                                            {{-- Quantity Preview --}}
                                            <div class="mt-2 p-2 bg-zinc-100 dark:bg-zinc-800 rounded text-sm">
                                                <span class="text-zinc-600 dark:text-zinc-400">{{ __('Calculated quantity:') }}</span>
                                                <span class="font-semibold text-zinc-900 dark:text-zinc-100 ml-1">
                                                    {{ number_format($this->getSurfaceQuantity($surface), 1) }}
                                                    @switch($surface['quantity_source'])
                                                        @case('wall_sqft')
                                                        @case('ceiling_sqft')
                                                            sqft
                                                            @break
                                                        @case('perimeter_lnft')
                                                            lnft
                                                            @break
                                                        @default
                                                            units
                                                    @endswitch
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                        @error('surfaces') <p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>
                </div>

                {{-- Nested Options Section --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900">
                    <div class="p-4 border-b border-zinc-200 dark:border-zinc-700 flex items-center justify-between">
                        <div>
                            <flux:heading size="sm">{{ __('Fixed Price Options (Optional)') }}</flux:heading>
                            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                                {{ __('Add fixed-price items like closets, pantries, or exterior features') }}
                            </flux:text>
                        </div>
                        <flux:button wire:click="addNestedOption" size="sm" icon="plus">
                            {{ __('Add Option') }}
                        </flux:button>
                    </div>
                    <div class="p-4">
                        @if (count($nestedOptions) === 0)
                            <div class="text-center py-6 text-zinc-500 dark:text-zinc-400">
                                <p class="text-sm">{{ __('No nested options. Click "Add Option" to include fixed-price items.') }}</p>
                            </div>
                        @else
                            <div class="space-y-3">
                                @foreach ($nestedOptions as $index => $option)
                                    <div wire:key="nested-{{ $index }}" class="flex items-start gap-3 p-3 bg-zinc-50 dark:bg-zinc-800/50 rounded-lg">
                                        <div class="flex-1 grid gap-3 sm:grid-cols-4">
                                            <flux:field class="sm:col-span-2">
                                                <flux:label class="text-xs">{{ __('Nested Template') }}</flux:label>
                                                <flux:select wire:model.live="nestedOptions.{{ $index }}.nested_area_template_id" size="sm">
                                                    <option value="">{{ __('-- Select Template --') }}</option>
                                                    @foreach ($this->availableNestedTemplates as $nestedTemplate)
                                                        <option value="{{ $nestedTemplate->id }}">
                                                            {{ $nestedTemplate->name }} (${{ number_format($nestedTemplate->fixed_price, 2) }})
                                                        </option>
                                                    @endforeach
                                                </flux:select>
                                            </flux:field>

                                            <flux:field>
                                                <flux:label class="text-xs">{{ __('Name Override') }}</flux:label>
                                                <flux:input wire:model.blur="nestedOptions.{{ $index }}.name" :placeholder="__('Optional')" size="sm" />
                                            </flux:field>

                                            <flux:field>
                                                <flux:label class="text-xs">{{ __('Max Quantity') }}</flux:label>
                                                <flux:input wire:model.blur="nestedOptions.{{ $index }}.max_quantity" type="number" min="1" :placeholder="__('Unlimited')" size="sm" />
                                            </flux:field>
                                        </div>

                                        <div class="flex items-center gap-2 pt-5">
                                            <label class="flex items-center gap-1.5">
                                                <flux:checkbox wire:model.live="nestedOptions.{{ $index }}.is_default_included" />
                                                <span class="text-xs text-zinc-600 dark:text-zinc-400">{{ __('Default') }}</span>
                                            </label>
                                            <flux:button
                                                wire:click="removeNestedOption({{ $index }})"
                                                variant="ghost"
                                                size="sm"
                                                icon="trash"
                                                class="text-red-500 hover:text-red-700 dark:text-red-400"
                                            />
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>

                {{-- Defaults Section --}}
                <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900">
                    <div class="p-4 border-b border-zinc-200 dark:border-zinc-700">
                        <flux:heading size="sm">{{ __('Default Settings') }}</flux:heading>
                        <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                            {{ __('Default substrate, paint, and coats for this template') }}
                        </flux:text>
                    </div>
                    <div class="p-4">
                        <div class="grid gap-4 sm:grid-cols-3">
                            <flux:field>
                                <flux:label>{{ __('Default Substrate') }}</flux:label>
                                <flux:select wire:model="defaultSubstrateId">
                                    <option value="">{{ __('-- None --') }}</option>
                                    @foreach ($this->availableSubstrates as $substrate)
                                        <option value="{{ $substrate->id }}">{{ __($substrate->name) }}</option>
                                    @endforeach
                                </flux:select>
                                <flux:error name="defaultSubstrateId" />
                            </flux:field>

                            <flux:field>
                                <flux:label>{{ __('Default Paint Product') }}</flux:label>
                                <flux:select wire:model="defaultPaintProductId">
                                    <option value="">{{ __('-- None --') }}</option>
                                    @foreach ($this->availablePaintProducts as $product)
                                        <option value="{{ $product->id }}">
                                            {{ $product->brand?->name }} {{ $product->name }}
                                        </option>
                                    @endforeach
                                </flux:select>
                                <flux:error name="defaultPaintProductId" />
                            </flux:field>

                            <flux:field>
                                <flux:label>{{ __('Default Coats') }}</flux:label>
                                <flux:select wire:model.live="defaultCoats">
                                    @for ($i = 1; $i <= 5; $i++)
                                        <option value="{{ $i }}">{{ $i }} coat{{ $i > 1 ? 's' : '' }}</option>
                                    @endfor
                                </flux:select>
                                <flux:error name="defaultCoats" />
                            </flux:field>
                        </div>
                    </div>
                </div>

                {{-- Edit Mode Info --}}
                @if ($isEditing && $template)
                    <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900">
                        <div class="p-4 border-b border-zinc-200 dark:border-zinc-700">
                            <flux:heading size="sm">{{ __('Template Information') }}</flux:heading>
                        </div>
                        <div class="p-4">
                            <dl class="grid gap-4 sm:grid-cols-3 text-sm">
                                <div>
                                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Created By') }}</dt>
                                    <dd class="font-medium text-zinc-900 dark:text-zinc-100">
                                        {{ $template->createdBy?->name ?? __('System') }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Created At') }}</dt>
                                    <dd class="font-medium text-zinc-900 dark:text-zinc-100">
                                        {{ $template->created_at->format('M j, Y g:i A') }}
                                    </dd>
                                </div>
                                <div>
                                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Last Updated') }}</dt>
                                    <dd class="font-medium text-zinc-900 dark:text-zinc-100">
                                        {{ $template->updated_at->format('M j, Y g:i A') }}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                    </div>
                @endif
            </div>

            {{-- Preview Sidebar --}}
            <div class="lg:col-span-4 mt-6 lg:mt-0">
                <div class="sticky top-6 space-y-4">
                    {{-- Live Preview Card --}}
                    <div class="border-2 border-blue-200 dark:border-blue-800 rounded-lg bg-gradient-to-br from-blue-50 to-white dark:from-blue-950/30 dark:to-zinc-900">
                        <div class="p-4 border-b border-blue-200 dark:border-blue-800">
                            <flux:heading size="sm" class="text-blue-800 dark:text-blue-200">{{ __('Live Calculator') }}</flux:heading>
                            <flux:text size="sm" class="text-blue-600 dark:text-blue-400">
                                {{ __('Real-time pricing preview') }}
                            </flux:text>
                        </div>

                        <div class="p-4 space-y-4">
                            {{-- Preview Controls --}}
                            <flux:field>
                                <flux:label class="text-xs">{{ __('Preview Coats') }}</flux:label>
                                <flux:select wire:model.live="previewCoats" size="sm">
                                    @for ($i = 1; $i <= 4; $i++)
                                        <option value="{{ $i }}">{{ $i }} coat{{ $i > 1 ? 's' : '' }}</option>
                                    @endfor
                                </flux:select>
                            </flux:field>

                            {{-- Preview Results --}}
                            @if ($this->previewCalculation)
                                @php $preview = $this->previewCalculation; @endphp

                                {{-- Dimensions Summary --}}
                                <div class="space-y-2">
                                    <div class="text-xs font-medium text-blue-700 dark:text-blue-400">{{ __('Room Dimensions') }}</div>
                                    <div class="grid grid-cols-3 gap-2 text-xs">
                                        <div class="text-center p-2 bg-blue-100 dark:bg-blue-900/30 rounded">
                                            <div class="font-bold text-lg text-blue-800 dark:text-blue-200">{{ $preview['dimensions']['wall_sqft'] }}</div>
                                            <div class="text-blue-600 dark:text-blue-400">{{ __('Wall sqft') }}</div>
                                        </div>
                                        <div class="text-center p-2 bg-blue-100 dark:bg-blue-900/30 rounded">
                                            <div class="font-bold text-lg text-blue-800 dark:text-blue-200">{{ $preview['dimensions']['ceiling_sqft'] }}</div>
                                            <div class="text-blue-600 dark:text-blue-400">{{ __('Ceiling sqft') }}</div>
                                        </div>
                                        <div class="text-center p-2 bg-blue-100 dark:bg-blue-900/30 rounded">
                                            <div class="font-bold text-lg text-blue-800 dark:text-blue-200">{{ $preview['dimensions']['perimeter_lnft'] }}</div>
                                            <div class="text-blue-600 dark:text-blue-400">{{ __('Perimeter lnft') }}</div>
                                        </div>
                                    </div>
                                </div>

                                {{-- Surfaces Breakdown --}}
                                @if (count($preview['surfaces']) > 0)
                                    <div class="space-y-2">
                                        <div class="text-xs font-medium text-blue-700 dark:text-blue-400">{{ __('Surfaces') }}</div>
                                        @foreach ($preview['surfaces'] as $surface)
                                            <div class="p-2 bg-white/50 dark:bg-zinc-800/50 rounded text-xs border border-blue-100 dark:border-blue-800">
                                                <div class="flex justify-between items-center">
                                                    <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ $surface['name'] }}</span>
                                                    <span class="text-zinc-500 dark:text-zinc-400">
                                                        {{ number_format($surface['quantity'], 0) }} {{ $surface['unit'] }}
                                                    </span>
                                                </div>
                                                <div class="flex justify-between items-center mt-1 text-zinc-500 dark:text-zinc-400">
                                                    <span>{{ $surface['rate_name'] }}</span>
                                                    <span class="font-medium">{{ number_format($surface['hours'], 2) }} hrs</span>
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif

                                {{-- Nested Breakdown --}}
                                @if (count($preview['nested']) > 0)
                                    <div class="space-y-2">
                                        <div class="text-xs font-medium text-blue-700 dark:text-blue-400">{{ __('Nested Items') }}</div>
                                        @foreach ($preview['nested'] as $nested)
                                            <div class="flex justify-between items-center text-xs py-1 border-b border-blue-100 dark:border-blue-900">
                                                <span class="text-zinc-700 dark:text-zinc-300">{{ $nested['name'] }}</span>
                                                <span class="text-zinc-500 dark:text-zinc-400">${{ number_format($nested['price'], 2) }}</span>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif

                                {{-- Hours Summary --}}
                                <div class="space-y-2 pt-2 border-t border-blue-200 dark:border-blue-800">
                                    <div class="text-xs font-medium text-blue-700 dark:text-blue-400">{{ __('Hours') }}</div>
                                    <div class="grid grid-cols-3 gap-2 text-xs text-center">
                                        <div>
                                            <span class="text-zinc-600 dark:text-zinc-400">{{ __('Labor') }}</span>
                                            <div class="font-semibold">{{ number_format($preview['totals']['labor_hours'], 1) }}</div>
                                        </div>
                                        <div>
                                            <span class="text-zinc-600 dark:text-zinc-400">{{ __('Cleanup') }}</span>
                                            <div class="font-semibold">{{ number_format($preview['totals']['cleanup_hours'], 1) }}</div>
                                        </div>
                                        <div>
                                            <span class="text-zinc-600 dark:text-zinc-400">{{ __('Total') }}</span>
                                            <div class="font-semibold text-blue-600 dark:text-blue-400">{{ number_format($preview['totals']['total_hours'], 1) }}</div>
                                        </div>
                                    </div>
                                </div>

                                {{-- Cost Breakdown --}}
                                <div class="space-y-2 pt-2 border-t border-blue-200 dark:border-blue-800">
                                    <div class="text-xs font-medium text-blue-700 dark:text-blue-400">{{ __('Cost Breakdown') }}</div>
                                    <div class="space-y-1 text-xs">
                                        <div class="flex justify-between">
                                            <span class="text-zinc-600 dark:text-zinc-400">Labor ({{ $preview['totals']['labor_hours'] }} hrs x ${{ number_format($preview['totals']['hourly_rate'], 2) }}/hr):</span>
                                            <span class="font-medium">${{ number_format($preview['totals']['labor_cost'], 2) }}</span>
                                        </div>
                                        <div class="flex justify-between">
                                            <span class="text-zinc-600 dark:text-zinc-400">{{ __('Cleanup:') }}</span>
                                            <span class="font-medium">${{ number_format($preview['totals']['cleanup_cost'], 2) }}</span>
                                        </div>
                                        <div class="flex justify-between">
                                            <span class="text-zinc-600 dark:text-zinc-400">{{ __('Sundries:') }}</span>
                                            <span class="font-medium">${{ number_format($preview['totals']['sundries_cost'], 2) }}</span>
                                        </div>
                                        <div class="flex justify-between">
                                            <span class="text-zinc-600 dark:text-zinc-400">{{ __('Materials:') }}</span>
                                            <span class="font-medium">${{ number_format($preview['totals']['material_cost'], 2) }}</span>
                                        </div>
                                        @if ($preview['totals']['nested_cost'] > 0)
                                            <div class="flex justify-between">
                                                <span class="text-zinc-600 dark:text-zinc-400">{{ __('Nested Items:') }}</span>
                                                <span class="font-medium">${{ number_format($preview['totals']['nested_cost'], 2) }}</span>
                                            </div>
                                        @endif
                                    </div>
                                </div>

                                {{-- Totals --}}
                                <div class="space-y-2 pt-2 border-t border-blue-200 dark:border-blue-800">
                                    <div class="flex justify-between text-sm">
                                        <span class="text-zinc-600 dark:text-zinc-400">{{ __('Base Total:') }}</span>
                                        <span class="font-medium">${{ number_format($preview['totals']['subtotal'], 2) }}</span>
                                    </div>
                                    <div class="flex justify-between pt-2 border-t border-blue-200 dark:border-blue-800">
                                        <span class="text-lg font-bold text-blue-800 dark:text-blue-200">{{ __('With Margins:') }}</span>
                                        <span class="text-2xl font-bold text-blue-600 dark:text-blue-400">
                                            ${{ number_format($preview['totals']['with_margins'], 2) }}
                                        </span>
                                    </div>
                                </div>
                            @else
                                <div class="text-center py-8 text-zinc-500 dark:text-zinc-400">
                                    <flux:icon name="calculator" class="w-8 h-8 mx-auto mb-2 text-zinc-300 dark:text-zinc-600" />
                                    <p class="text-sm">{{ __('Add surfaces to see pricing preview') }}</p>
                                </div>
                            @endif
                        </div>
                    </div>

                    {{-- Actions --}}
                    <div class="space-y-2">
                        <flux:button wire:click="save" variant="primary" wire:loading.attr="disabled" class="w-full">
                            <span wire:loading.remove wire:target="save">
                                {{ $isEditing ? __('Update Template') : __('Create Template') }}
                            </span>
                            <span wire:loading wire:target="save">{{ __('Saving...') }}</span>
                        </flux:button>
                        <flux:button wire:click="cancel" variant="ghost" class="w-full">
                            {{ __('Cancel') }}
                        </flux:button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
