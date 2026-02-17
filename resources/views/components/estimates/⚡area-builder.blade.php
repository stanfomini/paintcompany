<?php

use App\Models\AreaTemplateSurface;
use App\Models\CompanySetting;
use App\Models\EstimateTemplate;
use App\Models\NestedAreaTemplate;
use App\Models\PaintProduct;
use App\Models\ProductionRate;
use App\Models\ServiceCategory;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Area Builder for Estimates - Cloned from AreaTemplates/Builder.
 *
 * This component allows building detailed areas with dimensions, surfaces,
 * and fixed-cost add-ons (nested areas) directly within an estimate.
 */
new class extends Component
{
    // Area being edited (index in parent's areas array, or null for new)
    public ?int $editingIndex = null;

    public bool $isOpen = false;

    // Basic Info
    public string $name = '';

    public string $scopeType = 'interior';

    // Dimension-based sizing
    public ?float $roomLength = null;

    public ?float $roomWidth = 12;

    public ?float $roomHeight = 9;

    // Default coats for preview
    public int $defaultCoats = 2;

    // Surfaces Section with production rate type selection
    /** @var array<int, array{id: ?int, name: string, surface_type: string, surface_category: string, production_rate_id: ?int, quantity_source: string, quantity_override: ?float, default_coats: int, is_required: bool, is_default_enabled: bool, fixed_price: ?float}> */
    public array $surfaces = [];

    // Fixed Cost Add-ons (nested area templates)
    /** @var array<int, array{nested_area_template_id: int, name: string, quantity: int, unit_price: float, total_price: float}> */
    public array $addons = [];

    // For adding new addon
    public ?int $newAddonTemplateId = null;

    public int $newAddonQuantity = 1;

    // Source template ID (if loaded from template)
    public ?int $sourceTemplateId = null;

    // Paint product configuration from parent
    public string $paintMode = 'single';

    public ?int $defaultPaintProductId = null;

    /** @var array<string, int|null> Paint product IDs by surface category */
    public array $paintByCategory = [];

    // Measurement mode and direct input alternatives
    public string $measurementType = 'dimensions';

    public int $wallCount = 4;

    public ?float $directSqft = null;

    public ?float $directLnft = null;

    public ?float $directCeilingSqft = null;

    // Margin discount from parent estimate (commercial discount)
    public float $marginDiscountPercent = 0;

    // Hourly rate override from parent estimate
    public ?float $hourlyRateOverride = null;

    // Service category
    public ?int $serviceCategoryId = null;

    public bool $showNewCategoryForm = false;

    public string $newCategoryName = '';

    // Quantity sources for surfaces
    public const QUANTITY_SOURCES = [
        'wall_sqft' => 'Wall Square Feet',
        'ceiling_sqft' => 'Ceiling Square Feet',
        'perimeter_lnft' => 'Perimeter Linear Feet',
        'door_count' => 'Door Count',
        'window_count' => 'Window Count',
        'custom' => 'Custom Value',
    ];

    public function getTranslatedQuantitySources(): array
    {
        return [
            'wall_sqft' => __('Wall Square Feet'),
            'ceiling_sqft' => __('Ceiling Square Feet'),
            'perimeter_lnft' => __('Perimeter Linear Feet'),
            'door_count' => __('Door Count'),
            'window_count' => __('Window Count'),
            'custom' => __('Custom Value'),
        ];
    }

    public function mount(): void
    {
        // Initialize with default surfaces
        $this->addDefaultSurfaces();

        // Set default dimensions (interior default)
        $this->roomLength = 12.0;
        $this->roomWidth = 12.0;
        $this->roomHeight = 9.0;
    }

    /**
     * Open the builder with a template.
     */
    #[On('open-area-builder-from-template')]
    public function openFromTemplate(
        int $templateId,
        ?int $editingIndex = null,
        string $paintMode = 'single',
        ?int $paintProductId = null,
        array $paintByCategory = [],
        float $marginDiscountPercent = 0,
        ?float $hourlyRateOverride = null,
    ): void {
        $this->marginDiscountPercent = $marginDiscountPercent;
        $this->hourlyRateOverride = $hourlyRateOverride;
        $template = EstimateTemplate::with(['surfaces.productionRate', 'nestedOptions.nestedAreaTemplate'])
            ->find($templateId);

        if (! $template) {
            return;
        }

        $this->reset(['surfaces', 'addons', 'newAddonTemplateId', 'newAddonQuantity', 'measurementType', 'wallCount', 'directSqft', 'directLnft', 'directCeilingSqft', 'serviceCategoryId', 'showNewCategoryForm', 'newCategoryName']);

        $this->editingIndex = $editingIndex;
        $this->sourceTemplateId = $template->id;
        $this->name = $template->name;
        $this->scopeType = $template->scope_type ?? 'interior';
        $this->defaultCoats = $template->default_coats ?? 2;

        // Clear computed properties to ensure they reload for the new scope
        unset($this->surfaceCategories, $this->productionRatesByCategory, $this->availableNestedTemplates);

        // Set paint product configuration
        $this->paintMode = $paintMode;
        $this->defaultPaintProductId = $paintProductId;
        $this->paintByCategory = $paintByCategory;

        // Load dimensions from metadata
        $metadata = $template->metadata ?? [];
        $storedLength = $metadata['dimensions']['length'] ?? null;
        if ($storedLength === null || $storedLength === 0 || $storedLength === '0' || $storedLength === '') {
            $this->roomLength = ($this->scopeType === 'interior') ? 12.0 : null;
        } else {
            $this->roomLength = (float) $storedLength;
        }
        $this->roomWidth = (float) ($metadata['dimensions']['width'] ?? 12);
        $this->roomHeight = (float) ($metadata['dimensions']['height'] ?? 9);

        // Load surfaces from template
        $this->loadSurfacesFromTemplate($template);

        // Load default nested options as addons
        $this->loadNestedOptionsFromTemplate($template);

        $this->isOpen = true;
    }

    /**
     * Open the builder for a new blank area.
     */
    #[On('open-area-builder-blank')]
    public function openBlank(
        string $scopeType = 'interior',
        string $paintMode = 'single',
        ?int $paintProductId = null,
        array $paintByCategory = [],
        float $marginDiscountPercent = 0,
        ?float $hourlyRateOverride = null,
    ): void {
        $this->marginDiscountPercent = $marginDiscountPercent;
        $this->hourlyRateOverride = $hourlyRateOverride;
        $this->reset(['surfaces', 'addons', 'newAddonTemplateId', 'newAddonQuantity', 'measurementType', 'wallCount', 'directSqft', 'directLnft', 'directCeilingSqft', 'serviceCategoryId', 'showNewCategoryForm', 'newCategoryName']);

        $this->editingIndex = null;
        $this->sourceTemplateId = null;
        $this->name = '';
        $this->scopeType = $scopeType;
        $this->defaultCoats = 2;

        // Clear computed properties to ensure they reload for the new scope
        unset($this->surfaceCategories, $this->productionRatesByCategory, $this->availableNestedTemplates);

        // Set paint product configuration
        $this->paintMode = $paintMode;
        $this->defaultPaintProductId = $paintProductId;
        $this->paintByCategory = $paintByCategory;

        // Set default dimensions
        $this->roomLength = ($scopeType === 'interior') ? 12.0 : null;
        $this->roomWidth = 12.0;
        $this->roomHeight = 9.0;

        // Add default surfaces
        $this->addDefaultSurfaces();

        $this->isOpen = true;
    }

    /**
     * Open the builder to edit an existing area.
     */
    #[On('open-area-builder-edit')]
    public function openForEdit(
        array $areaData,
        int $editingIndex,
        string $paintMode = 'single',
        ?int $paintProductId = null,
        array $paintByCategory = [],
        float $marginDiscountPercent = 0,
        ?float $hourlyRateOverride = null,
    ): void {
        $this->marginDiscountPercent = $marginDiscountPercent;
        $this->hourlyRateOverride = $hourlyRateOverride;
        $this->reset(['surfaces', 'addons', 'newAddonTemplateId', 'newAddonQuantity', 'measurementType', 'wallCount', 'directSqft', 'directLnft', 'directCeilingSqft', 'serviceCategoryId', 'showNewCategoryForm', 'newCategoryName']);

        $this->editingIndex = $editingIndex;
        $this->sourceTemplateId = $areaData['source_template_id'] ?? null;
        $this->name = $areaData['name'] ?? '';
        $this->scopeType = $areaData['scope_type'] ?? 'interior';
        $this->defaultCoats = $areaData['default_coats'] ?? 2;

        // Clear computed properties to ensure they reload for the new scope
        unset($this->surfaceCategories, $this->productionRatesByCategory, $this->availableNestedTemplates);

        // Set paint product configuration
        $this->paintMode = $paintMode;
        $this->defaultPaintProductId = $paintProductId;
        $this->paintByCategory = $paintByCategory;

        $this->roomLength = $areaData['length'] ?? 12.0;
        $this->roomWidth = $areaData['width'] ?? 12.0;
        $this->roomHeight = $areaData['height'] ?? 9.0;

        // Load new measurement/category fields
        $this->measurementType = $areaData['measurement_type'] ?? 'dimensions';
        $this->wallCount = $areaData['wall_count'] ?? 4;
        $this->directSqft = $areaData['direct_sqft'] ?? null;
        $this->directLnft = $areaData['direct_lnft'] ?? null;
        $this->directCeilingSqft = $areaData['direct_ceiling_sqft'] ?? null;
        $this->serviceCategoryId = $areaData['service_category_id'] ?? null;

        // Load surfaces (ensure is_custom, labor_hours, material_cost, sale_price, crew_price exist on each)
        $loadedSurfaces = $areaData['surfaces'] ?? [];
        foreach ($loadedSurfaces as $i => $surfaceData) {
            $loadedSurfaces[$i]['is_custom'] = $surfaceData['is_custom'] ?? false;
            $loadedSurfaces[$i]['labor_hours'] = $surfaceData['labor_hours'] ?? null;
            $loadedSurfaces[$i]['material_cost'] = $surfaceData['material_cost'] ?? null;
            $loadedSurfaces[$i]['sale_price'] = $surfaceData['sale_price'] ?? null;
            $loadedSurfaces[$i]['crew_price'] = $surfaceData['crew_price'] ?? null;
        }
        $this->surfaces = $loadedSurfaces;

        // Load addons
        $this->addons = $areaData['addons'] ?? [];

        $this->isOpen = true;
    }

    /**
     * Load surfaces from a template.
     */
    protected function loadSurfacesFromTemplate(EstimateTemplate $template): void
    {
        $this->surfaces = [];

        // If template has model-based surfaces, use those
        if ($template->surfaces->isNotEmpty()) {
            foreach ($template->surfaces as $surface) {
                $surfaceCategory = $surface->productionRate?->surface_category
                    ?? $this->mapSurfaceTypeToCategory($surface->surface_type);

                $quantitySource = $this->mapCalculationMethodToSource($surface);

                $this->surfaces[] = [
                    'id' => null,
                    'name' => $surface->name,
                    'surface_type' => $surface->surface_type,
                    'surface_category' => $surfaceCategory,
                    'production_rate_id' => $surface->production_rate_id,
                    'quantity_source' => $quantitySource,
                    'quantity_override' => $surface->calculation_method === AreaTemplateSurface::CALC_METHOD_FIXED_COUNT
                        ? (float) $surface->calculation_value
                        : null,
                    'default_coats' => $surface->default_coats ?? $this->defaultCoats,
                    'is_required' => $surface->is_required,
                    'is_default_enabled' => $surface->is_default_enabled,
                    'fixed_price' => $surface->fixed_price ? (float) $surface->fixed_price : null,
                    'is_custom' => false,
                    'labor_hours' => null,
                    'material_cost' => null,
                    'sale_price' => null,
                    'crew_price' => null,
                ];
            }

            return;
        }

        // Fall back to JSON default_surfaces field
        $defaultSurfaces = $template->default_surfaces ?? ['walls', 'ceiling'];

        foreach ($defaultSurfaces as $surfaceName) {
            $normalized = strtolower($surfaceName);

            [$surfaceType, $surfaceCategory, $quantitySource] = match ($normalized) {
                'walls', 'wall' => [AreaTemplateSurface::SURFACE_TYPE_WALLS, 'walls', 'wall_sqft'],
                'ceiling', 'ceilings' => [AreaTemplateSurface::SURFACE_TYPE_CEILING, 'ceiling', 'ceiling_sqft'],
                'trim', 'baseboards', 'baseboard' => [AreaTemplateSurface::SURFACE_TYPE_TRIM, 'baseboard', 'perimeter_lnft'],
                'doors', 'door' => [AreaTemplateSurface::SURFACE_TYPE_DOORS, 'doors', 'door_count'],
                'windows', 'window' => [AreaTemplateSurface::SURFACE_TYPE_WINDOWS, 'windows', 'window_count'],
                default => [AreaTemplateSurface::SURFACE_TYPE_OTHER, 'walls', 'wall_sqft'],
            };

            $this->surfaces[] = [
                'id' => null,
                'name' => ucfirst($normalized),
                'surface_type' => $surfaceType,
                'surface_category' => $surfaceCategory,
                'production_rate_id' => null,
                'quantity_source' => $quantitySource,
                'quantity_override' => null,
                'default_coats' => $this->defaultCoats,
                'is_required' => $normalized === 'walls',
                'is_default_enabled' => true,
                'fixed_price' => null,
                'is_custom' => false,
                'labor_hours' => null,
                'material_cost' => null,
                'sale_price' => null,
                'crew_price' => null,
            ];
        }
    }

    /**
     * Load nested options from template as addons.
     */
    protected function loadNestedOptionsFromTemplate(EstimateTemplate $template): void
    {
        $this->addons = [];

        foreach ($template->nestedOptions as $option) {
            if (! $option->is_default_included || ! $option->nested_area_template_id) {
                continue;
            }

            $nestedTemplate = $option->nestedAreaTemplate;
            if (! $nestedTemplate) {
                continue;
            }

            $this->addons[] = [
                'nested_area_template_id' => $nestedTemplate->id,
                'name' => $option->name ?: $nestedTemplate->name,
                'quantity' => 1,
                'unit_price' => (float) $nestedTemplate->fixed_price,
                'total_price' => (float) $nestedTemplate->fixed_price,
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
     * Add default surfaces for a new area.
     */
    protected function addDefaultSurfaces(): void
    {
        $this->surfaces = [];

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
            'is_custom' => false,
            'labor_hours' => null,
            'material_cost' => null,
            'sale_price' => null,
            'crew_price' => null,
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
            'is_custom' => false,
            'labor_hours' => null,
            'material_cost' => null,
            'sale_price' => null,
            'crew_price' => null,
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
            'is_custom' => false,
            'labor_hours' => null,
            'material_cost' => null,
            'sale_price' => null,
            'crew_price' => null,
        ];
    }

    // ===== COMPUTED DIMENSIONS =====

    /**
     * Calculate wall square footage based on measurement type.
     */
    #[Computed]
    public function wallSqft(): float
    {
        if ($this->measurementType === 'sqft') {
            return (float) ($this->directSqft ?? 0);
        }

        if ($this->measurementType === 'lnft') {
            $height = $this->roomHeight ?? 9;

            return (float) ($this->directLnft ?? 0) * $height;
        }

        // dimensions mode
        $width = $this->roomWidth ?? 12;
        $height = $this->roomHeight ?? 9;

        if ($this->roomLength === null || $this->roomLength == 0) {
            return $width * $height;
        }

        $fullPerimeter = 2 * ($this->roomLength + $width) * $height;

        return $fullPerimeter * ($this->wallCount / 4);
    }

    /**
     * Calculate ceiling/floor square footage based on measurement type.
     */
    #[Computed]
    public function ceilingSqft(): float
    {
        if ($this->measurementType === 'sqft') {
            return (float) ($this->directCeilingSqft ?? 0);
        }

        if ($this->measurementType === 'lnft') {
            return 0;
        }

        // dimensions mode
        if ($this->roomLength === null || $this->roomLength == 0) {
            return 0;
        }

        $width = $this->roomWidth ?? 12;

        return $this->roomLength * $width;
    }

    /**
     * Calculate perimeter linear feet based on measurement type.
     */
    #[Computed]
    public function perimeterLnft(): float
    {
        if ($this->measurementType === 'lnft') {
            return (float) ($this->directLnft ?? 0);
        }

        if ($this->measurementType === 'sqft') {
            // Estimate perimeter from sqft: assume square room
            $sqft = (float) ($this->directSqft ?? 0);
            if ($sqft <= 0) {
                return 0;
            }
            $side = sqrt($sqft);

            return $side * 4;
        }

        // dimensions mode
        $width = $this->roomWidth ?? 12;

        if ($this->roomLength === null || $this->roomLength == 0) {
            return $width;
        }

        return 2 * ($this->roomLength + $width);
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
        $defaultCategory = $this->scopeType === 'exterior' ? 'body' : 'walls';

        $this->surfaces[] = [
            'id' => null,
            'name' => '',
            'surface_type' => AreaTemplateSurface::SURFACE_TYPE_OTHER,
            'surface_category' => $defaultCategory,
            'production_rate_id' => null,
            'quantity_source' => 'wall_sqft',
            'quantity_override' => null,
            'default_coats' => 2,
            'is_required' => false,
            'is_default_enabled' => true,
            'fixed_price' => null,
            'is_custom' => false,
            'labor_hours' => null,
            'material_cost' => null,
            'sale_price' => null,
            'crew_price' => null,
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
     * When surface category changes, reset production rate.
     */
    public function updatedSurfaces($value, $key): void
    {
        if (! preg_match('/^(\d+)\.surface_category$/', $key, $matches)) {
            return;
        }

        $index = (int) $matches[1];
        $category = $this->surfaces[$index]['surface_category'];

        $this->surfaces[$index]['production_rate_id'] = null;

        [$surfaceType, $quantitySource] = match ($category) {
            'walls' => [AreaTemplateSurface::SURFACE_TYPE_WALLS, 'wall_sqft'],
            'ceiling' => [AreaTemplateSurface::SURFACE_TYPE_CEILING, 'ceiling_sqft'],
            'baseboard' => [AreaTemplateSurface::SURFACE_TYPE_TRIM, 'perimeter_lnft'],
            'doors' => [AreaTemplateSurface::SURFACE_TYPE_DOORS, 'door_count'],
            'prep' => [AreaTemplateSurface::SURFACE_TYPE_OTHER, 'wall_sqft'],
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

        unset($this->productionRatesByCategory);
    }

    // ===== ADDON MANAGEMENT =====

    /**
     * Add a fixed cost addon.
     */
    public function addAddon(): void
    {
        if (! $this->newAddonTemplateId) {
            return;
        }

        $template = NestedAreaTemplate::find($this->newAddonTemplateId);
        if (! $template) {
            return;
        }

        $quantity = max(1, $this->newAddonQuantity);

        $this->addons[] = [
            'nested_area_template_id' => $template->id,
            'name' => __($template->name),
            'quantity' => $quantity,
            'unit_price' => (float) $template->fixed_price,
            'total_price' => (float) $template->fixed_price * $quantity,
        ];

        // Reset
        $this->newAddonTemplateId = null;
        $this->newAddonQuantity = 1;
    }

    /**
     * Update addon quantity.
     */
    public function updateAddonQuantity(int $index, int $quantity): void
    {
        if (! isset($this->addons[$index])) {
            return;
        }

        $quantity = max(1, $quantity);
        $this->addons[$index]['quantity'] = $quantity;
        $this->addons[$index]['total_price'] = $this->addons[$index]['unit_price'] * $quantity;
    }

    /**
     * Remove an addon.
     */
    public function removeAddon(int $index): void
    {
        unset($this->addons[$index]);
        $this->addons = array_values($this->addons);
    }

    // ===== COMPUTED PROPERTIES =====

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
     * Get available nested area templates for addons.
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
     * Calculate total price for all addons.
     */
    #[Computed]
    public function addonsTotalPrice(): float
    {
        return array_sum(array_column($this->addons, 'total_price'));
    }

    /**
     * Calculate the live preview using PBP formula.
     *
     * PBP Formula:
     * 1. COGS = Labor + Cleanup + Sundries + Materials + Material Tax + Labor Tax
     * 2. OTD = COGS × (1 + markup%)
     *
     * Note: Payroll burden is NOT applied to hourly wage - it's factored into the markup.
     *
     * @return array{surfaces: array, addons: array, totals: array}|null
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

            $settings = CompanySetting::getInstance();
            // Use effective hourly rate (base wage × payroll burden) for COGS
            $hourlyWage = $this->getEffectiveRateForScope($this->scopeType);

            foreach ($this->surfaces as $index => $surface) {
                if (! $surface['is_required'] && ! $surface['is_default_enabled']) {
                    continue;
                }

                $isCustom = $surface['is_custom'] ?? false;

                if ($isCustom) {
                    // Custom surface: use labor_hours and material_cost directly
                    $hours = (float) ($surface['labor_hours'] ?? 0);
                    $materialCost = (float) ($surface['material_cost'] ?? 0);
                    $totalLaborHours += $hours;
                    $totalMaterialCost += $materialCost;

                    $surfaceBreakdowns[] = [
                        'name' => $surface['name'] ?: 'Surface '.($index + 1),
                        'quantity' => 0,
                        'unit' => 'custom',
                        'hours' => round($hours, 2),
                        'rate_name' => 'Custom',
                        'rate_value' => '-',
                    ];

                    continue;
                }

                $quantity = $this->getSurfaceQuantity($surface);
                $coats = $surface['default_coats'] ?? $this->defaultCoats;

                $rate = $surface['production_rate_id']
                    ? ProductionRate::find($surface['production_rate_id'])
                    : null;

                $coatMultiplier = $this->getCoatMultiplier($coats);

                if ($surface['fixed_price'] !== null && $surface['fixed_price'] > 0) {
                    $hours = 0;
                    $cost = (float) $surface['fixed_price'];
                    $totalMaterialCost += $cost;
                } else {
                    if ($rate) {
                        $hours = $rate->calculateHoursForQuantity($quantity, $coats);
                    } else {
                        $productionRate = 100;
                        $hours = ($quantity / $productionRate) * $coatMultiplier;
                    }

                    // Calculate material cost using paint product (if available) or fallback
                    $fallbackCostPerUnit = $rate ? (float) ($rate->material_cost_per_unit ?? 0.15) : 0.15;
                    $surfaceCategory = $surface['surface_category'] ?? 'walls';
                    $totalMaterialCost += $this->calculateMaterialCostForSurface(
                        $quantity,
                        $coats,
                        $surfaceCategory,
                        $fallbackCostPerUnit,
                        $coatMultiplier
                    );
                }

                $totalLaborHours += $hours;

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

            // Calculate costs using base hourly wage (payroll burden is in markup)
            $laborCost = $totalLaborHours * $hourlyWage;
            $cleanupHours = $settings->calculateCleanupHours($totalLaborHours, $this->scopeType);
            $cleanupCost = $cleanupHours * $hourlyWage;
            $sundriesCost = $settings->calculateSundriesCost($totalLaborHours, $this->scopeType);

            // Add addon costs to materials
            $addonTotal = $this->addonsTotalPrice;
            $totalMaterialWithAddons = $totalMaterialCost + $addonTotal;

            // Calculate COGS with taxes
            $cogsResult = $settings->calculateCOGS($laborCost, $cleanupCost, $sundriesCost, $totalMaterialWithAddons);
            $cogs = $cogsResult['cogs'];

            // Apply scope-specific margin with discount: OTD = COGS / (margin% / 100)
            $marginPercent = min(99, $settings->getMarginPercentForScope($this->scopeType) + $this->marginDiscountPercent);
            $withMargins = round($cogs / ($marginPercent / 100), 2);

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
                'addons' => $this->addons,
                'totals' => [
                    'labor_hours' => round($totalLaborHours, 2),
                    'cleanup_hours' => round($cleanupHours, 2),
                    'total_hours' => round($totalLaborHours + $cleanupHours, 2),
                    'labor_cost' => round($laborCost, 2),
                    'cleanup_cost' => round($cleanupCost, 2),
                    'sundries_cost' => round($sundriesCost, 2),
                    'material_cost' => round($totalMaterialCost, 2),
                    'material_tax' => $cogsResult['material_tax'],
                    'labor_tax' => $cogsResult['labor_tax'],
                    'addon_cost' => round($addonTotal, 2),
                    'subtotal' => round($cogs, 2), // Alias for backward compatibility
                    'cogs' => round($cogs, 2),
                    'margin_percent' => $marginPercent,
                    'with_margins' => round($withMargins, 2),
                    'hourly_wage' => round($hourlyWage, 2),
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

    /**
     * Calculate material cost for a surface using paint product.
     *
     * @param  float  $quantity  The surface quantity (sqft, lnft, etc.)
     * @param  int  $coats  Number of coats
     * @param  string  $surfaceCategory  Surface category for paint-by-category lookup
     * @param  float|null  $fallbackCostPerUnit  Fallback cost per unit if no paint product
     * @param  float  $coatMultiplier  Pre-calculated coat multiplier
     * @return float Material cost
     */
    protected function calculateMaterialCostForSurface(
        float $quantity,
        int $coats,
        string $surfaceCategory,
        ?float $fallbackCostPerUnit,
        float $coatMultiplier
    ): float {
        // Determine paint product ID based on paint mode
        $paintProductId = null;

        if ($this->paintMode === 'category') {
            // Look up by surface category
            $paintProductId = $this->paintByCategory[$surfaceCategory] ?? null;
        }

        // Fall back to default paint product
        if (! $paintProductId && $this->defaultPaintProductId) {
            $paintProductId = $this->defaultPaintProductId;
        }

        // If we have a paint product, calculate from coverage and price
        if ($paintProductId) {
            $paintProduct = PaintProduct::find($paintProductId);
            if ($paintProduct) {
                $coverage = $paintProduct->getCoverage('brush_roll');
                if ($coverage <= 0) {
                    $coverage = 400; // Fallback coverage
                }

                $pricePerGallon = (float) ($paintProduct->price_per_gallon ?? 50);
                $gallonsNeeded = ($quantity / $coverage) * $coatMultiplier;

                return $gallonsNeeded * $pricePerGallon;
            }
        }

        // Fall back to cost per unit from production rate
        $costPerUnit = $fallbackCostPerUnit ?? 0.15;

        return $quantity * $costPerUnit * $coatMultiplier;
    }

    // ===== SERVICE CATEGORIES =====

    /**
     * Get available service categories.
     *
     * @return Collection<int, ServiceCategory>
     */
    #[Computed]
    public function serviceCategories(): Collection
    {
        return ServiceCategory::query()->active()->ordered()->get();
    }

    /**
     * Get effective hourly rate for scope, using estimate override if set.
     */
    #[Computed]
    public function effectiveRate(): float
    {
        return $this->getEffectiveRateForScope($this->scopeType);
    }

    /**
     * Get the effective hourly rate for a scope, using estimate override if set.
     */
    protected function getEffectiveRateForScope(string $scopeType): float
    {
        $settings = CompanySetting::getInstance();

        if ($this->hourlyRateOverride !== null && $this->hourlyRateOverride > 0) {
            return $this->hourlyRateOverride * (float) $settings->payroll_burden_multiplier;
        }

        return $settings->getEffectiveHourlyRateForScope($scopeType);
    }

    /**
     * Create a new service category inline.
     */
    public function createServiceCategory(): void
    {
        $this->validate([
            'newCategoryName' => ['required', 'string', 'max:255'],
        ]);

        $category = ServiceCategory::create([
            'name' => $this->newCategoryName,
            'slug' => Str::slug($this->newCategoryName),
            'is_active' => true,
        ]);

        $this->serviceCategoryId = $category->id;
        $this->newCategoryName = '';
        $this->showNewCategoryForm = false;

        unset($this->serviceCategories);
    }

    /**
     * Handle service category selection changes.
     */
    public function updatedServiceCategoryId(): void
    {
        if ($this->serviceCategoryId === 0) {
            $this->serviceCategoryId = null;
        }
    }

    /**
     * Handle measurement type changes - clear computed dimensions.
     */
    public function updatedMeasurementType(): void
    {
        unset($this->wallSqft, $this->ceilingSqft, $this->perimeterLnft);
    }

    // ===== LIFECYCLE HOOKS =====

    /**
     * When scope type changes, reset scope-specific selections.
     */
    public function updatedScopeType(): void
    {
        unset($this->availableNestedTemplates, $this->productionRatesByCategory, $this->surfaceCategories);

        $validCategories = ProductionRate::getCategoriesForScope($this->scopeType);
        $defaultCategory = array_key_first($validCategories) ?? 'walls';

        foreach ($this->surfaces as $index => $surface) {
            $this->surfaces[$index]['production_rate_id'] = null;

            if (! isset($validCategories[$surface['surface_category']])) {
                $this->surfaces[$index]['surface_category'] = $defaultCategory;
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

        // Clear addons that don't match new scope
        $validAddonIds = $this->availableNestedTemplates->pluck('id')->toArray();
        $this->addons = array_values(array_filter($this->addons, function ($addon) use ($validAddonIds) {
            return in_array($addon['nested_area_template_id'], $validAddonIds);
        }));

        if ($this->scopeType === 'exterior') {
            $this->roomLength = null;
        } elseif ($this->roomLength === null) {
            $this->roomLength = 12;
        }
    }

    // ===== ACTIONS =====

    /**
     * Save the area and dispatch to parent.
     */
    public function saveArea(): void
    {
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'scopeType' => ['required', 'in:interior,exterior'],
            'surfaces' => ['required', 'array', 'min:1'],
            'surfaces.*.name' => ['required', 'string', 'max:255'],
        ];

        if ($this->measurementType === 'dimensions') {
            $rules['roomWidth'] = ['required', 'numeric', 'min:1', 'max:200'];
            $rules['roomHeight'] = ['required', 'numeric', 'min:1', 'max:50'];
        } elseif ($this->measurementType === 'sqft') {
            $rules['directSqft'] = ['required', 'numeric', 'min:0'];
        } elseif ($this->measurementType === 'lnft') {
            $rules['directLnft'] = ['required', 'numeric', 'min:0'];
            $rules['roomHeight'] = ['required', 'numeric', 'min:1', 'max:50'];
        }

        $this->validate($rules);

        $preview = $this->previewCalculation;

        $areaData = [
            'name' => $this->name,
            'scope_type' => $this->scopeType,
            'source_template_id' => $this->sourceTemplateId,
            'length' => $this->roomLength,
            'width' => $this->roomWidth,
            'height' => $this->roomHeight,
            'wall_sqft' => $this->wallSqft,
            'ceiling_sqft' => $this->ceilingSqft,
            'perimeter_lnft' => $this->perimeterLnft,
            'default_coats' => $this->defaultCoats,
            'measurement_type' => $this->measurementType,
            'wall_count' => $this->wallCount,
            'direct_sqft' => $this->directSqft,
            'direct_lnft' => $this->directLnft,
            'direct_ceiling_sqft' => $this->directCeilingSqft,
            'service_category_id' => $this->serviceCategoryId,
            'surfaces' => $this->surfaces,
            'addons' => $this->addons,
            'labor_hours' => $preview['totals']['labor_hours'] ?? 0,
            'labor_cost' => $preview['totals']['labor_cost'] ?? 0,
            'cleanup_hours' => $preview['totals']['cleanup_hours'] ?? 0,
            'cleanup_cost' => $preview['totals']['cleanup_cost'] ?? 0,
            'sundries_cost' => $preview['totals']['sundries_cost'] ?? 0,
            'material_cost' => $preview['totals']['material_cost'] ?? 0,
            'addon_cost' => $preview['totals']['addon_cost'] ?? 0,
            'base_total' => $preview['totals']['subtotal'] ?? 0,
            'with_margins' => $preview['totals']['with_margins'] ?? 0,
            'price' => $preview['totals']['with_margins'] ?? 0,
        ];

        $this->dispatch('area-saved', areaData: $areaData, editingIndex: $this->editingIndex);

        $this->closeBuilder();
    }

    /**
     * Close the builder without saving.
     */
    public function closeBuilder(): void
    {
        $this->isOpen = false;
        $this->editingIndex = null;
        $this->measurementType = 'dimensions';
        $this->wallCount = 4;
        $this->directSqft = null;
        $this->directLnft = null;
        $this->directCeilingSqft = null;
        $this->serviceCategoryId = null;
        $this->showNewCategoryForm = false;
        $this->newCategoryName = '';
    }
}; ?>

<div>
    <flux:modal wire:model="isOpen" variant="flyout" class="max-w-6xl w-full">
        <div class="flex flex-col h-full max-h-[90vh]">
            {{-- Header --}}
            <div class="p-4 border-b border-zinc-200 dark:border-zinc-700">
                <flux:heading size="lg">
                    @if ($editingIndex !== null)
                        {{ __('Edit Area:') }} {{ $name ?: __('Untitled') }}
                    @else
                        {{ __('Add Custom Area') }}
                    @endif
                </flux:heading>
                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400 mt-1">
                    {{ __('Define dimensions, surfaces, and add-ons for this area') }}
                </flux:text>
            </div>

            {{-- Scrollable Content --}}
            <div class="flex-1 overflow-y-auto">
                <div class="lg:grid lg:grid-cols-12 lg:gap-4 p-4">
                    {{-- Main Form Column --}}
                    <div class="lg:col-span-7 space-y-4">
                        {{-- Basic Info Section --}}
                        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900">
                            <div class="p-3 border-b border-zinc-200 dark:border-zinc-700">
                                <flux:heading size="sm">{{ __('Basic Information') }}</flux:heading>
                            </div>
                            <div class="p-3 space-y-3">
                                <div class="grid gap-3 sm:grid-cols-2">
                                    <flux:field>
                                        <flux:label>{{ __('Area Name') }}</flux:label>
                                        <flux:input wire:model.blur="name" :placeholder="__('e.g., Living Room')" />
                                        <flux:error name="name" />
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
                                </div>

                                {{-- Service Category --}}
                                <flux:field>
                                    <flux:label>{{ __('Service Category') }} <span class="text-zinc-400 text-xs font-normal">({{ __('optional') }})</span></flux:label>
                                    @if ($showNewCategoryForm)
                                        <div class="flex items-center gap-2">
                                            <flux:input wire:model.blur="newCategoryName" :placeholder="__('Category name')" size="sm" class="flex-1" />
                                            <flux:button wire:click="createServiceCategory" size="sm" variant="primary">{{ __('Save') }}</flux:button>
                                            <flux:button wire:click="$set('showNewCategoryForm', false)" size="sm" variant="ghost">{{ __('Cancel') }}</flux:button>
                                        </div>
                                        <flux:error name="newCategoryName" />
                                    @else
                                        <div class="flex items-center gap-2">
                                            <flux:select wire:model.live="serviceCategoryId" size="sm" class="flex-1">
                                                <option value="">{{ __('-- None --') }}</option>
                                                @foreach ($this->serviceCategories as $cat)
                                                    <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                                                @endforeach
                                            </flux:select>
                                            <flux:button wire:click="$set('showNewCategoryForm', true)" size="sm" variant="ghost" icon="plus">{{ __('New') }}</flux:button>
                                        </div>
                                    @endif
                                </flux:field>
                            </div>
                        </div>

                        {{-- Dimensions Section --}}
                        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900">
                            <div class="p-3 border-b border-zinc-200 dark:border-zinc-700">
                                <flux:heading size="sm">
                                    @if ($scopeType === 'exterior')
                                        {{ __('Surface Dimensions') }}
                                    @else
                                        {{ __('Room Dimensions') }}
                                    @endif
                                </flux:heading>
                            </div>
                            <div class="p-3 space-y-3">
                                {{-- Measurement Mode Selector --}}
                                <div>
                                    <flux:label class="mb-1.5">{{ __('Measurement Mode') }}</flux:label>
                                    <div class="flex gap-1">
                                        @foreach (['dimensions' => __('Dimensions'), 'sqft' => __('Sqft'), 'lnft' => __('Linear Ft')] as $mode => $modeLabel)
                                            <button
                                                wire:click="$set('measurementType', '{{ $mode }}')"
                                                type="button"
                                                class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors {{ $measurementType === $mode ? 'bg-blue-600 text-white' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-200 dark:hover:bg-zinc-700' }}"
                                            >
                                                {{ $modeLabel }}
                                            </button>
                                        @endforeach
                                    </div>
                                </div>

                                {{-- Conditional Inputs Based on Mode --}}
                                {{-- wire:key prevents DOM morphing from confusing inputs when switching modes --}}
                                @if ($measurementType === 'dimensions')
                                    <div wire:key="measurement-mode-dimensions" class="space-y-3">
                                        <div class="grid gap-3 sm:grid-cols-3">
                                            <flux:field>
                                                <flux:label>
                                                    {{ __('Length (ft)') }}
                                                    @if ($scopeType === 'exterior')
                                                        <span class="text-zinc-400 text-xs font-normal">({{ __('optional') }})</span>
                                                    @endif
                                                </flux:label>
                                                <flux:input
                                                    wire:model.blur="roomLength"
                                                    type="number"
                                                    step="0.5"
                                                    min="0"
                                                    max="200"
                                                    :placeholder="$scopeType === 'exterior' ? __('Single side') : '12'"
                                                />
                                                <flux:error name="roomLength" />
                                            </flux:field>

                                            <flux:field>
                                                <flux:label>{{ __('Width (ft)') }}</flux:label>
                                                <flux:input wire:model.blur="roomWidth" type="number" step="0.5" min="1" max="200" placeholder="12" />
                                                <flux:error name="roomWidth" />
                                            </flux:field>

                                            <flux:field>
                                                <flux:label>{{ __('Height (ft)') }}</flux:label>
                                                <flux:input wire:model.blur="roomHeight" type="number" step="0.5" min="1" max="50" placeholder="9" />
                                                <flux:error name="roomHeight" />
                                            </flux:field>
                                        </div>

                                        {{-- Wall Count Pills --}}
                                        @if ($scopeType === 'interior')
                                            <div>
                                                <flux:label class="mb-1.5">{{ __('Wall Count') }}</flux:label>
                                                <div class="flex gap-1">
                                                    @for ($w = 1; $w <= 4; $w++)
                                                        <button
                                                            wire:click="$set('wallCount', {{ $w }})"
                                                            type="button"
                                                            class="px-3 py-1.5 text-xs font-medium rounded-md transition-colors {{ $wallCount === $w ? 'bg-blue-600 text-white' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-200 dark:hover:bg-zinc-700' }}"
                                                        >
                                                            {{ $w }} {{ $w > 1 ? __('walls') : __('wall') }}
                                                        </button>
                                                    @endfor
                                                </div>
                                                @if ($wallCount < 4)
                                                    <p class="text-xs text-amber-600 dark:text-amber-400 mt-1">
                                                        {{ __('Wall sqft scaled to :count/4 of full perimeter', ['count' => $wallCount]) }}
                                                    </p>
                                                @endif
                                            </div>
                                        @endif
                                    </div>
                                @elseif ($measurementType === 'sqft')
                                    <div wire:key="measurement-mode-sqft" class="grid gap-3 sm:grid-cols-3">
                                        <flux:field>
                                            <flux:label>{{ __('Wall Sqft') }}</flux:label>
                                            <flux:input wire:model.blur="directSqft" type="number" step="1" min="0" placeholder="0" />
                                        </flux:field>
                                        <flux:field>
                                            <flux:label>{{ __('Ceiling Sqft') }}</flux:label>
                                            <flux:input wire:model.blur="directCeilingSqft" type="number" step="1" min="0" placeholder="0" />
                                        </flux:field>
                                        <flux:field>
                                            <flux:label>{{ __('Height (ft)') }}</flux:label>
                                            <flux:input wire:model.blur="roomHeight" type="number" step="0.5" min="1" max="50" placeholder="9" />
                                            <flux:error name="roomHeight" />
                                        </flux:field>
                                    </div>
                                @elseif ($measurementType === 'lnft')
                                    <div wire:key="measurement-mode-lnft" class="grid gap-3 sm:grid-cols-2">
                                        <flux:field>
                                            <flux:label>{{ __('Linear Feet') }}</flux:label>
                                            <flux:input wire:model.blur="directLnft" type="number" step="1" min="0" placeholder="0" />
                                        </flux:field>
                                        <flux:field>
                                            <flux:label>{{ __('Height (ft)') }}</flux:label>
                                            <flux:input wire:model.blur="roomHeight" type="number" step="0.5" min="1" max="50" placeholder="9" />
                                            <flux:error name="roomHeight" />
                                        </flux:field>
                                    </div>
                                @endif

                                {{-- Calculated Values --}}
                                <div class="p-2 bg-blue-50 dark:bg-blue-900/20 rounded-lg">
                                    <div class="text-xs font-medium text-blue-700 dark:text-blue-400 mb-1">{{ __('Calculated Quantities') }}</div>
                                    <div class="grid grid-cols-3 gap-3 text-xs">
                                        <div>
                                            <span class="text-zinc-600 dark:text-zinc-400">{{ __('Wall Sqft:') }}</span>
                                            <span class="font-semibold text-zinc-900 dark:text-zinc-100 ml-1">{{ number_format($this->wallSqft, 0) }}</span>
                                        </div>
                                        <div>
                                            <span class="text-zinc-600 dark:text-zinc-400">{{ __('Ceiling Sqft:') }}</span>
                                            <span class="font-semibold text-zinc-900 dark:text-zinc-100 ml-1">{{ number_format($this->ceilingSqft, 0) }}</span>
                                        </div>
                                        <div>
                                            <span class="text-zinc-600 dark:text-zinc-400">{{ __('Perimeter:') }}</span>
                                            <span class="font-semibold text-zinc-900 dark:text-zinc-100 ml-1">{{ number_format($this->perimeterLnft, 0) }} {{ __('lnft') }}</span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Surfaces Section --}}
                        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900">
                            <div class="p-3 border-b border-zinc-200 dark:border-zinc-700 flex items-center justify-between">
                                <div>
                                    <flux:heading size="sm">{{ __('Surfaces') }}</flux:heading>
                                    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                                        {{ __('Define surfaces with production rate types') }}
                                    </flux:text>
                                </div>
                                <flux:button wire:click="addSurface" size="sm" icon="plus">
                                    {{ __('Add') }}
                                </flux:button>
                            </div>
                            <div class="p-3">
                                @if (count($surfaces) === 0)
                                    <div class="text-center py-6 text-zinc-500 dark:text-zinc-400">
                                        <p class="text-sm">{{ __('No surfaces defined. Add at least one surface.') }}</p>
                                    </div>
                                @else
                                    <div class="space-y-3">
                                        @foreach ($surfaces as $index => $surface)
                                            <div wire:key="surface-{{ $index }}" class="border border-zinc-200 dark:border-zinc-700 rounded-lg overflow-hidden">
                                                {{-- Surface Header --}}
                                                <div class="p-2 bg-zinc-50 dark:bg-zinc-800/50 flex items-center justify-between">
                                                    <div class="flex items-center gap-2">
                                                        <span class="font-medium text-sm text-zinc-900 dark:text-zinc-100">
                                                            {{ $surface['name'] ?: __('Surface') . ' ' . ($index + 1) }}
                                                        </span>
                                                        @if ($surface['is_required'])
                                                            <span class="px-1.5 py-0.5 text-xs font-medium rounded bg-red-100 dark:bg-red-900/50 text-red-700 dark:text-red-300">{{ __('Req') }}</span>
                                                        @endif
                                                        @if ($surface['production_rate_id'])
                                                            @php $selectedRate = \App\Models\ProductionRate::find($surface['production_rate_id']); @endphp
                                                            @if ($selectedRate)
                                                                <span class="px-1.5 py-0.5 text-xs font-medium rounded bg-green-100 dark:bg-green-900/50 text-green-700 dark:text-green-300">
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
                                                <div class="p-3 space-y-3">
                                                    <div class="grid gap-3 sm:grid-cols-2">
                                                        <flux:field>
                                                            <flux:label class="text-xs">{{ __('Surface Name') }}</flux:label>
                                                            <flux:input wire:model.blur="surfaces.{{ $index }}.name" :placeholder="__('e.g., Walls')" size="sm" />
                                                            @error("surfaces.{$index}.name") <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                                                        </flux:field>

                                                        <flux:field>
                                                            <flux:label class="text-xs">{{ __('Surface Category') }}</flux:label>
                                                            <flux:select wire:model.live="surfaces.{{ $index }}.surface_category" size="sm">
                                                                @foreach ($this->surfaceCategories as $catKey => $catLabel)
                                                                    <option value="{{ $catKey }}">{{ $catLabel }}</option>
                                                                @endforeach
                                                            </flux:select>
                                                        </flux:field>
                                                    </div>

                                                    {{-- Custom Surface Toggle --}}
                                                    <div class="flex items-center gap-4 pt-1">
                                                        <label class="flex items-center gap-1.5">
                                                            <flux:checkbox wire:model.live="surfaces.{{ $index }}.is_custom" />
                                                            <span class="text-xs text-zinc-700 dark:text-zinc-300">{{ __('Custom pricing') }}</span>
                                                        </label>
                                                    </div>

                                                    @if ($surface['is_custom'] ?? false)
                                                        {{-- Custom Surface Fields --}}
                                                        <div class="grid gap-3 sm:grid-cols-3">
                                                            <flux:field>
                                                                <flux:label class="text-xs">{{ __('Labor Hours') }}</flux:label>
                                                                <flux:input wire:model.blur="surfaces.{{ $index }}.labor_hours" type="number" step="0.25" min="0" placeholder="0" size="sm" />
                                                            </flux:field>
                                                            <flux:field>
                                                                <flux:label class="text-xs">{{ __('Material Cost ($)') }}</flux:label>
                                                                <flux:input wire:model.blur="surfaces.{{ $index }}.material_cost" type="number" step="0.01" min="0" placeholder="0" size="sm" />
                                                            </flux:field>
                                                            <flux:field>
                                                                <flux:label class="text-xs">{{ __('Hourly Rate') }}</flux:label>
                                                                <div class="text-sm font-medium text-zinc-700 dark:text-zinc-300 py-1">
                                                                    ${{ number_format($this->effectiveRate, 2) }}/hr
                                                                </div>
                                                            </flux:field>
                                                        </div>
                                                        <div class="grid gap-3 sm:grid-cols-2 mt-2">
                                                            <flux:field>
                                                                <flux:label class="text-xs">{{ __('Sale Price ($)') }}</flux:label>
                                                                <flux:input wire:model.blur="surfaces.{{ $index }}.sale_price" type="number" step="0.01" min="0" :placeholder="__('Auto (margin)')" size="sm" />
                                                                <flux:description class="text-[10px]">{{ __('Customer price for this surface. Leave empty to auto-calculate from margin.') }}</flux:description>
                                                            </flux:field>
                                                            <flux:field>
                                                                <flux:label class="text-xs">{{ __('Crew Rate ($/hr)') }}</flux:label>
                                                                <flux:input wire:model.blur="surfaces.{{ $index }}.crew_price" type="number" step="0.01" min="0" :placeholder="__('Auto (base wage)')" size="sm" />
                                                                <flux:description class="text-[10px]">{{ __('Hourly rate for crew. Leave empty to use base wage.') }}</flux:description>
                                                            </flux:field>
                                                        </div>
                                                        @if (($surface['sale_price'] ?? null) && ($surface['labor_hours'] ?? 0) > 0)
                                                            @php
                                                                $surfLaborCost = (float) ($surface['labor_hours'] ?? 0) * $this->effectiveRate;
                                                                $surfMaterialCost = (float) ($surface['material_cost'] ?? 0);
                                                                $surfCogs = $surfLaborCost + $surfMaterialCost;
                                                                $surfProfit = (float) $surface['sale_price'] - $surfCogs;
                                                            @endphp
                                                            <div class="mt-1 p-2 bg-{{ $surfProfit >= 0 ? 'green' : 'red' }}-50 dark:bg-{{ $surfProfit >= 0 ? 'green' : 'red' }}-900/20 rounded text-xs">
                                                                <span class="text-zinc-600 dark:text-zinc-400">{{ __('Profit:') }}</span>
                                                                <span class="font-semibold {{ $surfProfit >= 0 ? 'text-green-700 dark:text-green-400' : 'text-red-700 dark:text-red-400' }}">
                                                                    ${{ number_format($surfProfit, 2) }}
                                                                    @if ((float) $surface['sale_price'] > 0)
                                                                        ({{ number_format(($surfProfit / (float) $surface['sale_price']) * 100, 1) }}%)
                                                                    @endif
                                                                </span>
                                                            </div>
                                                        @endif
                                                    @else
                                                        {{-- Standard Production Rate Fields --}}
                                                        <div class="grid gap-3 sm:grid-cols-2">
                                                            <flux:field>
                                                                <flux:label class="text-xs">{{ __('Production Rate Type') }}</flux:label>
                                                                <flux:select wire:model.live="surfaces.{{ $index }}.production_rate_id" size="sm">
                                                                    <option value="">{{ __('-- Select Type --') }}</option>
                                                                    @foreach ($this->getProductionRatesForCategory($surface['surface_category']) as $rate)
                                                                        <option value="{{ $rate->id }}">
                                                                            {{ __($rate->name) }} ({{ number_format($rate->production_rate, 0) }} {{ $rate->rate_type_label }})
                                                                        </option>
                                                                    @endforeach
                                                                </flux:select>
                                                                @if ($this->getProductionRatesForCategory($surface['surface_category'])->isEmpty())
                                                                    <p class="text-xs text-amber-600 dark:text-amber-400">{{ __('No rates for this category.') }}</p>
                                                                @endif
                                                            </flux:field>

                                                            <flux:field>
                                                                <flux:label class="text-xs">{{ __('Quantity Source') }}</flux:label>
                                                                <flux:select wire:model.live="surfaces.{{ $index }}.quantity_source" size="sm">
                                                                    @foreach ($this->quantitySources as $srcKey => $srcLabel)
                                                                        <option value="{{ $srcKey }}">{{ $srcLabel }}</option>
                                                                    @endforeach
                                                                </flux:select>
                                                            </flux:field>
                                                        </div>

                                                        @if (in_array($surface['quantity_source'], ['door_count', 'window_count', 'custom']))
                                                            <flux:field>
                                                                <flux:label class="text-xs">
                                                                    @if ($surface['quantity_source'] === 'door_count')
                                                                        {{ __('Number of Doors') }}
                                                                    @elseif ($surface['quantity_source'] === 'window_count')
                                                                        {{ __('Number of Windows') }}
                                                                    @else
                                                                        {{ __('Custom Quantity') }}
                                                                    @endif
                                                                </flux:label>
                                                                <flux:input wire:model.blur="surfaces.{{ $index }}.quantity_override" type="number" step="1" min="0" placeholder="1" size="sm" />
                                                            </flux:field>
                                                        @endif

                                                        <div class="grid gap-3 sm:grid-cols-2">
                                                            <flux:field>
                                                                <flux:label class="text-xs">{{ __('Coats') }}</flux:label>
                                                                <flux:select wire:model.live="surfaces.{{ $index }}.default_coats" size="sm">
                                                                    @for ($i = 1; $i <= 5; $i++)
                                                                        <option value="{{ $i }}">{{ $i }} {{ $i > 1 ? __('coats') : __('coat') }}</option>
                                                                    @endfor
                                                                </flux:select>
                                                            </flux:field>

                                                            <flux:field>
                                                                <flux:label class="text-xs">{{ __('Fixed Price (Optional)') }}</flux:label>
                                                                <div class="flex items-center gap-1">
                                                                    <span class="text-zinc-500 text-sm">$</span>
                                                                    <flux:input wire:model.blur="surfaces.{{ $index }}.fixed_price" type="number" step="0.01" min="0" :placeholder="__('Auto-calc')" size="sm" />
                                                                </div>
                                                            </flux:field>
                                                        </div>
                                                    @endif

                                                    <div class="flex items-center gap-4 pt-1">
                                                        <label class="flex items-center gap-1.5">
                                                            <flux:checkbox wire:model.live="surfaces.{{ $index }}.is_required" />
                                                            <span class="text-xs text-zinc-700 dark:text-zinc-300">{{ __('Required') }}</span>
                                                        </label>
                                                        <label class="flex items-center gap-1.5">
                                                            <flux:checkbox wire:model.live="surfaces.{{ $index }}.is_default_enabled" />
                                                            <span class="text-xs text-zinc-700 dark:text-zinc-300">{{ __('Enabled') }}</span>
                                                        </label>
                                                    </div>

                                                    {{-- Quantity Preview --}}
                                                    @if (!($surface['is_custom'] ?? false))
                                                        <div class="mt-1 p-1.5 bg-zinc-100 dark:bg-zinc-800 rounded text-xs">
                                                            <span class="text-zinc-600 dark:text-zinc-400">{{ __('Quantity:') }}</span>
                                                            <span class="font-semibold text-zinc-900 dark:text-zinc-100 ml-1">
                                                                {{ number_format($this->getSurfaceQuantity($surface), 1) }}
                                                                @switch($surface['quantity_source'])
                                                                    @case('wall_sqft')
                                                                    @case('ceiling_sqft')
                                                                        {{ __('sqft') }}
                                                                        @break
                                                                    @case('perimeter_lnft')
                                                                        {{ __('lnft') }}
                                                                        @break
                                                                    @default
                                                                        {{ __('units') }}
                                                                @endswitch
                                                            </span>
                                                        </div>
                                                    @endif
                                                </div>
                                            </div>
                                        @endforeach
                                    </div>
                                @endif
                                @error('surfaces') <p class="mt-2 text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        {{-- Fixed Cost Add-ons Section --}}
                        <div class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900">
                            <div class="p-3 border-b border-zinc-200 dark:border-zinc-700">
                                <flux:heading size="sm">{{ __('Fixed Price Items') }}</flux:heading>
                                <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                                    {{ __('Add fixed-price items like closets, pantries, and exterior features') }}
                                </flux:text>
                            </div>
                            <div class="p-3">
                                {{-- Add New Addon --}}
                                <div class="flex items-end gap-2 mb-3">
                                    <flux:field class="flex-1">
                                        <flux:label class="text-xs">{{ __('Select Add-on') }}</flux:label>
                                        <flux:select wire:model="newAddonTemplateId" size="sm">
                                            <option value="">{{ __('-- Choose Add-on --') }}</option>
                                            @foreach ($this->availableNestedTemplates as $template)
                                                <option value="{{ $template->id }}">
                                                    {{ __($template->name) }} (${{ number_format($template->fixed_price, 2) }})
                                                </option>
                                            @endforeach
                                        </flux:select>
                                    </flux:field>
                                    <flux:field class="w-20">
                                        <flux:label class="text-xs">{{ __('Qty') }}</flux:label>
                                        <flux:input wire:model="newAddonQuantity" type="number" min="1" size="sm" />
                                    </flux:field>
                                    <flux:button wire:click="addAddon" size="sm" icon="plus" class="mb-0.5">
                                        {{ __('Add') }}
                                    </flux:button>
                                </div>

                                {{-- Addon List --}}
                                @if (count($addons) === 0)
                                    <div class="text-center py-4 text-zinc-500 dark:text-zinc-400 border border-dashed border-zinc-200 dark:border-zinc-700 rounded">
                                        <p class="text-xs">{{ __('No add-ons added yet.') }}</p>
                                    </div>
                                @else
                                    <div class="space-y-2">
                                        @foreach ($addons as $index => $addon)
                                            <div wire:key="addon-{{ $index }}" class="flex items-center justify-between p-2 bg-zinc-50 dark:bg-zinc-800/50 rounded">
                                                <div class="flex items-center gap-3">
                                                    <span class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $addon['name'] }}</span>
                                                    <span class="text-xs text-zinc-500 dark:text-zinc-400">${{ number_format($addon['unit_price'], 2) }} ea</span>
                                                </div>
                                                <div class="flex items-center gap-2">
                                                    <flux:input
                                                        wire:change="updateAddonQuantity({{ $index }}, $event.target.value)"
                                                        type="number"
                                                        min="1"
                                                        value="{{ $addon['quantity'] }}"
                                                        class="w-16"
                                                        size="sm"
                                                    />
                                                    <span class="text-sm font-semibold text-zinc-900 dark:text-zinc-100 w-20 text-right">
                                                        ${{ number_format($addon['total_price'], 2) }}
                                                    </span>
                                                    <flux:button
                                                        wire:click="removeAddon({{ $index }})"
                                                        variant="ghost"
                                                        size="sm"
                                                        icon="x-mark"
                                                        class="text-red-500 hover:text-red-700 dark:text-red-400"
                                                    />
                                                </div>
                                            </div>
                                        @endforeach
                                        <div class="flex justify-end pt-2 border-t border-zinc-200 dark:border-zinc-700">
                                            <span class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                                                {{ __('Add-ons Total:') }} ${{ number_format($this->addonsTotalPrice, 2) }}
                                            </span>
                                        </div>
                                    </div>
                                @endif
                            </div>
                        </div>
                    </div>

                    {{-- Preview Sidebar --}}
                    <div class="lg:col-span-5 mt-4 lg:mt-0">
                        <div class="sticky top-0 space-y-4">
                            {{-- Live Preview Card --}}
                            <div class="border-2 border-blue-200 dark:border-blue-800 rounded-lg bg-gradient-to-br from-blue-50 to-white dark:from-blue-950/30 dark:to-zinc-900">
                                <div class="p-3 border-b border-blue-200 dark:border-blue-800">
                                    <flux:heading size="sm" class="text-blue-800 dark:text-blue-200">{{ __('Live Calculator') }}</flux:heading>
                                    <flux:text size="sm" class="text-blue-600 dark:text-blue-400">
                                        {{ __('Real-time pricing preview') }}
                                    </flux:text>
                                </div>

                                <div class="p-3 space-y-3">
                                    {{-- Preview Results --}}
                                    @if ($this->previewCalculation)
                                        @php $preview = $this->previewCalculation; @endphp

                                        {{-- Dimensions Summary --}}
                                        <div class="space-y-1">
                                            <div class="text-xs font-medium text-blue-700 dark:text-blue-400">{{ __('Dimensions') }}</div>
                                            <div class="grid grid-cols-3 gap-1 text-xs">
                                                <div class="text-center p-1.5 bg-blue-100 dark:bg-blue-900/30 rounded">
                                                    <div class="font-bold text-blue-800 dark:text-blue-200">{{ $preview['dimensions']['wall_sqft'] }}</div>
                                                    <div class="text-blue-600 dark:text-blue-400 text-[10px]">{{ __('Wall sqft') }}</div>
                                                </div>
                                                <div class="text-center p-1.5 bg-blue-100 dark:bg-blue-900/30 rounded">
                                                    <div class="font-bold text-blue-800 dark:text-blue-200">{{ $preview['dimensions']['ceiling_sqft'] }}</div>
                                                    <div class="text-blue-600 dark:text-blue-400 text-[10px]">{{ __('Ceiling sqft') }}</div>
                                                </div>
                                                <div class="text-center p-1.5 bg-blue-100 dark:bg-blue-900/30 rounded">
                                                    <div class="font-bold text-blue-800 dark:text-blue-200">{{ $preview['dimensions']['perimeter_lnft'] }}</div>
                                                    <div class="text-blue-600 dark:text-blue-400 text-[10px]">{{ __('Perimeter lnft') }}</div>
                                                </div>
                                            </div>
                                        </div>

                                        {{-- Surfaces Breakdown --}}
                                        @if (count($preview['surfaces']) > 0)
                                            <div class="space-y-1">
                                                <div class="text-xs font-medium text-blue-700 dark:text-blue-400">{{ __('Surfaces') }}</div>
                                                @foreach ($preview['surfaces'] as $surface)
                                                    <div class="p-1.5 bg-white/50 dark:bg-zinc-800/50 rounded text-xs border border-blue-100 dark:border-blue-800">
                                                        <div class="flex justify-between items-center">
                                                            <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ $surface['name'] }}</span>
                                                            <span class="text-zinc-500 dark:text-zinc-400">
                                                                {{ number_format($surface['quantity'], 0) }} {{ $surface['unit'] }}
                                                            </span>
                                                        </div>
                                                        <div class="flex justify-between items-center mt-0.5 text-zinc-500 dark:text-zinc-400 text-[10px]">
                                                            <span>{{ $surface['rate_name'] }}</span>
                                                            <span class="font-medium">{{ number_format($surface['hours'], 2) }} hrs</span>
                                                        </div>
                                                    </div>
                                                @endforeach
                                            </div>
                                        @endif

                                        {{-- Addons Breakdown --}}
                                        @if (count($preview['addons']) > 0)
                                            <div class="space-y-1">
                                                <div class="text-xs font-medium text-blue-700 dark:text-blue-400">{{ __('Add-ons') }}</div>
                                                @foreach ($preview['addons'] as $addon)
                                                    <div class="flex justify-between items-center text-xs py-1 border-b border-blue-100 dark:border-blue-900">
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
                                        @endif

                                        {{-- Hours Summary --}}
                                        <div class="space-y-1 pt-2 border-t border-blue-200 dark:border-blue-800">
                                            <div class="text-xs font-medium text-blue-700 dark:text-blue-400">{{ __('Hours') }}</div>
                                            <div class="grid grid-cols-3 gap-1 text-xs text-center">
                                                <div>
                                                    <span class="text-zinc-600 dark:text-zinc-400 text-[10px]">{{ __('Labor') }}</span>
                                                    <div class="font-semibold">{{ number_format($preview['totals']['labor_hours'], 1) }}</div>
                                                </div>
                                                <div>
                                                    <span class="text-zinc-600 dark:text-zinc-400 text-[10px]">{{ __('Cleanup') }}</span>
                                                    <div class="font-semibold">{{ number_format($preview['totals']['cleanup_hours'], 1) }}</div>
                                                </div>
                                                <div>
                                                    <span class="text-zinc-600 dark:text-zinc-400 text-[10px]">{{ __('Total') }}</span>
                                                    <div class="font-semibold text-blue-600 dark:text-blue-400">{{ number_format($preview['totals']['total_hours'], 1) }}</div>
                                                </div>
                                            </div>
                                        </div>

                                        {{-- COGS Breakdown --}}
                                        <div class="space-y-1 pt-2 border-t border-blue-200 dark:border-blue-800">
                                            <div class="text-xs font-medium text-blue-700 dark:text-blue-400">{{ __('COGS Breakdown') }}</div>
                                            <div class="space-y-0.5 text-xs">
                                                <div class="flex justify-between">
                                                    <span class="text-zinc-600 dark:text-zinc-400">{{ __('Labor:') }}</span>
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
                                                @if (($preview['totals']['material_tax'] ?? 0) > 0)
                                                    <div class="flex justify-between">
                                                        <span class="text-zinc-600 dark:text-zinc-400">{{ __('Material Tax:') }}</span>
                                                        <span class="font-medium">${{ number_format($preview['totals']['material_tax'], 2) }}</span>
                                                    </div>
                                                @endif
                                                @if (($preview['totals']['labor_tax'] ?? 0) > 0)
                                                    <div class="flex justify-between">
                                                        <span class="text-zinc-600 dark:text-zinc-400">{{ __('Labor Tax:') }}</span>
                                                        <span class="font-medium">${{ number_format($preview['totals']['labor_tax'], 2) }}</span>
                                                    </div>
                                                @endif
                                                @if ($preview['totals']['addon_cost'] > 0)
                                                    <div class="flex justify-between">
                                                        <span class="text-zinc-600 dark:text-zinc-400">{{ __('Add-ons:') }}</span>
                                                        <span class="font-medium">${{ number_format($preview['totals']['addon_cost'], 2) }}</span>
                                                    </div>
                                                @endif
                                            </div>
                                        </div>

                                        {{-- COGS & OTD Totals --}}
                                        @php
                                            $marginPct = $preview['totals']['margin_percent'] ?? ($scopeType === 'exterior' ? 41 : 50);
                                        @endphp
                                        <div class="space-y-1 pt-2 border-t border-blue-200 dark:border-blue-800">
                                            <div class="flex justify-between text-xs">
                                                <span class="text-zinc-600 dark:text-zinc-400">{{ __('COGS:') }}</span>
                                                <span class="font-medium">${{ number_format($preview['totals']['cogs'] ?? $preview['totals']['subtotal'], 2) }}</span>
                                            </div>
                                            <div class="flex justify-between text-xs">
                                                <span class="text-zinc-600 dark:text-zinc-400">{{ __('Margin') }} ({{ $marginPct }}%):</span>
                                                <span class="font-medium">
                                                    +${{ number_format(($preview['totals']['with_margins'] - ($preview['totals']['cogs'] ?? $preview['totals']['subtotal'])), 2) }}
                                                </span>
                                            </div>
                                            <div class="mt-2 p-2 bg-green-50 dark:bg-green-900/20 rounded border border-green-200 dark:border-green-800">
                                                <div class="text-[10px] text-green-600 dark:text-green-400 mb-1">
                                                    OTD = COGS / {{ $marginPct }}%
                                                </div>
                                                <div class="flex justify-between items-center">
                                                    <span class="text-sm font-bold text-green-800 dark:text-green-200">{{ __('OTD Price:') }}</span>
                                                    <span class="text-xl font-bold text-green-600 dark:text-green-400">
                                                        ${{ number_format($preview['totals']['with_margins'], 2) }}
                                                    </span>
                                                </div>
                                            </div>
                                        </div>
                                    @else
                                        <div class="text-center py-6 text-zinc-500 dark:text-zinc-400">
                                            <flux:icon name="calculator" class="w-6 h-6 mx-auto mb-2 text-zinc-300 dark:text-zinc-600" />
                                            <p class="text-xs">{{ __('Add surfaces to see pricing preview') }}</p>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Footer --}}
            <div class="p-4 border-t border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800/50 flex items-center justify-end gap-3">
                <flux:button wire:click="closeBuilder" variant="ghost">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button wire:click="saveArea" variant="primary" wire:loading.attr="disabled">
                    <span wire:loading.remove wire:target="saveArea">
                        @if ($editingIndex !== null)
                            {{ __('Update Area') }}
                        @else
                            {{ __('Add Area') }}
                        @endif
                    </span>
                    <span wire:loading wire:target="saveArea">{{ __('Saving...') }}</span>
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
