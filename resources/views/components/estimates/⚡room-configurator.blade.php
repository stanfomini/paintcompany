<?php

use App\Models\AreaTemplateSurface;
use App\Models\CompanySetting;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\EstimateTemplate;
use App\Models\NestedAreaTemplate;
use App\Models\ProductionRate;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

new class extends Component {
    public Estimate $estimate;

    // UI State
    public bool $showQuickAddDrawer = false;

    public ?int $expandedAreaId = null;

    public bool $mobileMode = false;

    // Quick Add Form
    public string $newRoomName = '';

    public string $newRoomScope = 'interior';

    public ?float $newLength = 12;

    public ?float $newWidth = 12;

    public ?float $newHeight = 9;

    public ?int $selectedTemplateId = null;

    // Nested Item Modal State
    public bool $showNestedItemModal = false;

    public ?int $targetAreaIdForNested = null;

    public ?int $selectedNestedTemplateId = null;

    public bool $addAsStandalone = false;

    // Area configuration state (for expanded areas)
    /** @var array<int, array{surfaces: array, nested_items: array}> */
    public array $areaConfigs = [];

    public function mount(Estimate $estimate): void
    {
        $this->estimate = $estimate->load([
            'areas.surfaces.productionRate',
            'areas.childAreas.nestedAreaTemplate',
            'areas.nestedAreaTemplate',
        ]);

        $this->loadAreasIntoConfig();

        // First area expanded by default (if any)
        $firstArea = $this->estimate->areas()->topLevel()->first();
        if ($firstArea) {
            $this->expandedAreaId = $firstArea->id;
        }
    }

    /**
     * Load areas into the configuration state.
     */
    protected function loadAreasIntoConfig(): void
    {
        $this->areaConfigs = [];

        foreach ($this->estimate->areas()->topLevel()->get() as $area) {
            $this->areaConfigs[$area->id] = $this->buildAreaConfig($area);
        }
    }

    /**
     * Build configuration array for an area.
     */
    protected function buildAreaConfig(EstimateArea $area): array
    {
        $surfaces = [];
        foreach ($area->surfaces as $surface) {
            $surfaces[$surface->id] = [
                'is_included' => $surface->is_included,
                'production_rate_id' => $surface->production_rate_id,
                'coats' => $surface->coats,
                'quantity' => (float) $surface->quantity,
            ];
        }

        $nestedItems = [];
        foreach ($area->childAreas as $child) {
            $nestedItems[$child->id] = [
                'quantity' => 1,
                'price' => (float) $child->fixed_price_amount,
            ];
        }

        return [
            'surfaces' => $surfaces,
            'nested_items' => $nestedItems,
        ];
    }

    // ===== COMPUTED PROPERTIES =====

    /**
     * Get all regular areas for this estimate (excluding standalone nested areas).
     *
     * @return Collection<int, EstimateArea>
     */
    #[Computed]
    public function areas(): Collection
    {
        return $this->estimate->areas()
            ->topLevel()
            ->whereNull('nested_area_template_id') // Exclude standalone nested areas
            ->with(['surfaces.productionRate', 'childAreas.nestedAreaTemplate', 'nestedAreaTemplate', 'substrate'])
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Get standalone nested areas (areas that are from nested templates, not attached to a room).
     *
     * @return Collection<int, EstimateArea>
     */
    #[Computed]
    public function standaloneNestedAreas(): Collection
    {
        return $this->estimate->areas()
            ->topLevel()
            ->whereNotNull('nested_area_template_id')
            ->with('nestedAreaTemplate')
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Get available estimate templates grouped by category.
     *
     * @return Collection<string, Collection<int, EstimateTemplate>>
     */
    #[Computed]
    public function availableTemplates(): Collection
    {
        return EstimateTemplate::query()
            ->active()
            ->visibleTo(auth()->user())
            ->with(['surfaces.productionRate', 'templateCategory'])
            ->get()
            ->groupBy(fn ($t) => $t->templateCategory?->name ?? 'Other');
    }

    /**
     * Get available nested area templates.
     *
     * @return Collection<int, NestedAreaTemplate>
     */
    #[Computed]
    public function nestedTemplates(): Collection
    {
        return NestedAreaTemplate::active()->ordered()->get();
    }

    /**
     * Get nested templates grouped by category.
     *
     * @return Collection<string, Collection<int, NestedAreaTemplate>>
     */
    #[Computed]
    public function nestedTemplatesByCategory(): Collection
    {
        return $this->nestedTemplates->groupBy('category');
    }

    /**
     * Get interior nested templates.
     *
     * @return Collection<int, NestedAreaTemplate>
     */
    #[Computed]
    public function interiorNestedTemplates(): Collection
    {
        return $this->nestedTemplates->filter(
            fn ($t) => $t->scope_type === NestedAreaTemplate::SCOPE_INTERIOR
        );
    }

    /**
     * Get production rates grouped by category for a given scope.
     */
    public function getProductionRatesForScope(string $scope): Collection
    {
        return ProductionRate::query()
            ->active()
            ->where('scope', $scope)
            ->orderBy('surface_category')
            ->orderBy('sort_order')
            ->get()
            ->groupBy('surface_category');
    }

    /**
     * Get surface categories for a given scope.
     */
    public function getSurfaceCategoriesForScope(string $scope): array
    {
        return ProductionRate::getCategoriesForScope($scope);
    }

    /**
     * Calculate total for all areas.
     */
    #[Computed]
    public function areasTotal(): float
    {
        $total = 0;

        // Regular areas (already filtered to exclude standalone nested)
        foreach ($this->areas as $area) {
            $total += $this->calculateAreaTotal($area);
        }

        // Add standalone nested areas
        foreach ($this->standaloneNestedAreas as $nested) {
            $total += (float) $nested->fixed_price_amount;
        }

        return $total;
    }

    /**
     * Calculate total for a single area including surfaces and nested items.
     */
    public function calculateAreaTotal(EstimateArea $area): float
    {
        $total = 0;

        // Sum included surfaces
        foreach ($area->surfaces as $surface) {
            if ($surface->is_included) {
                $total += (float) $surface->total;
            }
        }

        // Add nested items
        foreach ($area->childAreas as $child) {
            $total += (float) $child->fixed_price_amount;
        }

        return $total;
    }

    // ===== ROOM MANAGEMENT =====

    /**
     * Open the quick add drawer.
     */
    public function openQuickAddDrawer(): void
    {
        $this->resetQuickAddForm();
        $this->showQuickAddDrawer = true;
    }

    /**
     * Close the quick add drawer.
     */
    public function closeQuickAddDrawer(): void
    {
        $this->showQuickAddDrawer = false;
        $this->resetQuickAddForm();
    }

    /**
     * Reset quick add form.
     */
    protected function resetQuickAddForm(): void
    {
        $this->newRoomName = '';
        $this->newRoomScope = 'interior';
        $this->newLength = 12;
        $this->newWidth = 12;
        $this->newHeight = 9;
        $this->selectedTemplateId = null;
    }

    /**
     * Quick add from template.
     */
    public function addFromTemplate(int $templateId): void
    {
        $template = EstimateTemplate::with(['surfaces.productionRate'])->findOrFail($templateId);

        // Extract dimensions from metadata if available
        $metadata = $template->metadata ?? [];
        $length = $metadata['dimensions']['length'] ?? 12;
        $width = $metadata['dimensions']['width'] ?? 12;
        $height = $metadata['dimensions']['height'] ?? 9;

        // Create the area
        $area = $this->estimate->areas()->create([
            'name' => $template->name,
            'scope_type' => $template->scope_type === 'both' ? 'interior' : $template->scope_type,
            'length' => $length,
            'width' => $width,
            'height' => $height,
            'substrate_id' => $template->substrate_id,
            'sort_order' => $this->estimate->areas()->count(),
        ]);

        // Add surfaces from template
        $this->addSurfacesFromTemplate($area, $template);

        // Recalculate and refresh
        $this->recalculateArea($area);
        $this->loadAreasIntoConfig();
        $this->expandedAreaId = $area->id;

        // Clear computed cache
        unset($this->areas);

        $this->dispatch('area-added');
    }

    /**
     * Add surfaces from a template to an area.
     */
    protected function addSurfacesFromTemplate(EstimateArea $area, EstimateTemplate $template): void
    {
        $settings = CompanySetting::getInstance();
        $scopeType = $area->scope_type ?? 'interior';

        foreach ($template->surfaces as $index => $templateSurface) {
            // Skip disabled surfaces
            if (! $templateSurface->is_default_enabled && ! $templateSurface->is_required) {
                continue;
            }

            // Calculate quantity based on area dimensions
            $quantity = $this->calculateSurfaceQuantity($area, $templateSurface);

            // Get production rate
            $rate = $templateSurface->productionRate;
            $coats = $templateSurface->default_coats;

            // Calculate using PBP formula
            $laborHours = 0;
            $laborCost = 0;
            $materialCost = 0;
            $total = 0;

            if ($templateSurface->fixed_price) {
                $total = (float) $templateSurface->fixed_price;
            } elseif ($rate) {
                $laborHours = $rate->calculateHoursForQuantity($quantity, $coats);
                $effectiveRate = $settings->getEffectiveHourlyRateForScope($scopeType);
                $laborCost = $laborHours * $effectiveRate;
                $materialCost = 0.0; // Material costs come from PaintProduct, not ProductionRate

                // Add cleanup, sundries - store COGS, not OTD (margins applied at Estimate level)
                $cleanupHours = $settings->calculateCleanupHours($laborHours, $scopeType);
                $cleanupCost = $cleanupHours * $effectiveRate;
                $sundriesCost = $settings->calculateSundriesCost($laborHours, $scopeType);
                $total = $laborCost + $cleanupCost + $sundriesCost + $materialCost;
            }

            $area->surfaces()->create([
                'name' => $templateSurface->name,
                'surface_type' => $templateSurface->surface_type,
                'quantity' => $quantity,
                'unit' => $templateSurface->unit_type ?? 'sqft',
                'coats' => $coats,
                'production_rate_id' => $templateSurface->production_rate_id,
                'production_rate_value' => $rate?->production_rate,
                'hourly_labor_rate' => $settings->getEffectiveHourlyRateForScope($scopeType),
                'material_cost_per_unit' => 0.0, // Material costs come from PaintProduct
                'labor_hours' => round($laborHours, 2),
                'labor_cost' => round($laborCost, 2),
                'material_cost' => round($materialCost, 2),
                'total' => round($total, 2),
                'is_included' => $templateSurface->is_default_enabled || $templateSurface->is_required,
                'sort_order' => $index,
            ]);
        }
    }

    /**
     * Calculate surface quantity based on area dimensions and surface type.
     */
    protected function calculateSurfaceQuantity(EstimateArea $area, AreaTemplateSurface $templateSurface): float
    {
        // For fixed count surfaces, use the calculation value
        if ($templateSurface->calculation_method === AreaTemplateSurface::CALC_METHOD_FIXED_COUNT) {
            return (float) $templateSurface->calculation_value;
        }

        // Calculate based on surface type
        return match ($templateSurface->surface_type) {
            AreaTemplateSurface::SURFACE_TYPE_WALLS => (float) $area->wall_sqft,
            AreaTemplateSurface::SURFACE_TYPE_CEILING => (float) $area->ceiling_sqft,
            AreaTemplateSurface::SURFACE_TYPE_TRIM => (float) $area->perimeter_lnft,
            AreaTemplateSurface::SURFACE_TYPE_DOORS => 1.0, // Default 1 door
            AreaTemplateSurface::SURFACE_TYPE_WINDOWS => 1.0, // Default 1 window
            default => (float) $area->wall_sqft,
        };
    }

    /**
     * Add a custom room.
     */
    public function addCustomRoom(): void
    {
        $this->validate([
            'newRoomName' => ['required', 'string', 'max:255'],
            'newLength' => ['required', 'numeric', 'min:1'],
            'newWidth' => ['required', 'numeric', 'min:1'],
            'newHeight' => ['required', 'numeric', 'min:1'],
        ]);

        // Create the area
        $area = $this->estimate->areas()->create([
            'name' => $this->newRoomName,
            'scope_type' => $this->newRoomScope,
            'length' => $this->newLength,
            'width' => $this->newWidth,
            'height' => $this->newHeight,
            'sort_order' => $this->estimate->areas()->count(),
        ]);

        // Add default surfaces for the scope
        $this->addDefaultSurfaces($area);

        // Recalculate
        $this->recalculateArea($area);
        $this->loadAreasIntoConfig();
        $this->expandedAreaId = $area->id;
        $this->closeQuickAddDrawer();

        // Clear computed cache
        unset($this->areas);

        $this->dispatch('area-added');
    }

    /**
     * Add default surfaces to an area based on scope.
     */
    protected function addDefaultSurfaces(EstimateArea $area): void
    {
        $settings = CompanySetting::getInstance();
        $scopeType = $area->scope_type ?? 'interior';
        $effectiveRate = $settings->getEffectiveHourlyRateForScope($scopeType);

        // Get default production rates for each surface type
        $categories = ProductionRate::getCategoriesForScope($scopeType);
        $sortOrder = 0;

        // Add walls
        if (isset($categories['walls']) || isset($categories['body'])) {
            $category = isset($categories['walls']) ? 'walls' : 'body';
            $rate = ProductionRate::where('scope', $scopeType)
                ->where('surface_category', $category)
                ->first();

            $quantity = (float) $area->wall_sqft;
            $coats = 2;
            $laborHours = $rate ? $rate->calculateHoursForQuantity($quantity, $coats) : 0;
            $laborCost = $laborHours * $effectiveRate;
            $materialCost = 0.0; // Material costs come from PaintProduct, not ProductionRate
            $total = $laborCost + $materialCost;  // Store COGS, not OTD

            $area->surfaces()->create([
                'name' => 'Walls',
                'surface_type' => AreaTemplateSurface::SURFACE_TYPE_WALLS,
                'quantity' => $quantity,
                'unit' => 'sqft',
                'coats' => $coats,
                'production_rate_id' => $rate?->id,
                'production_rate_value' => $rate?->production_rate ?? 200, // Default 200 sqft/hr
                'hourly_labor_rate' => $effectiveRate,
                'material_cost_per_unit' => 0.0, // Material costs come from PaintProduct
                'labor_hours' => round($laborHours, 2),
                'labor_cost' => round($laborCost, 2),
                'material_cost' => round($materialCost, 2),
                'total' => round($total, 2),
                'is_included' => true,
                'sort_order' => $sortOrder++,
            ]);
        }

        // Add ceiling (interior only)
        if (isset($categories['ceiling'])) {
            $rate = ProductionRate::where('scope', $scopeType)
                ->where('surface_category', 'ceiling')
                ->first();

            $quantity = (float) $area->ceiling_sqft;
            $coats = 2;
            $laborHours = $rate ? $rate->calculateHoursForQuantity($quantity, $coats) : 0;
            $laborCost = $laborHours * $effectiveRate;
            $materialCost = 0.0; // Material costs come from PaintProduct, not ProductionRate
            $total = $laborCost + $materialCost;  // Store COGS, not OTD

            $area->surfaces()->create([
                'name' => 'Ceiling',
                'surface_type' => AreaTemplateSurface::SURFACE_TYPE_CEILING,
                'quantity' => $quantity,
                'unit' => 'sqft',
                'coats' => $coats,
                'production_rate_id' => $rate?->id,
                'production_rate_value' => $rate?->production_rate ?? 150, // Default 150 sqft/hr
                'hourly_labor_rate' => $effectiveRate,
                'material_cost_per_unit' => 0.0, // Material costs come from PaintProduct
                'labor_hours' => round($laborHours, 2),
                'labor_cost' => round($laborCost, 2),
                'material_cost' => round($materialCost, 2),
                'total' => round($total, 2),
                'is_included' => true,
                'sort_order' => $sortOrder++,
            ]);
        }

        // Add baseboards (interior only)
        if (isset($categories['baseboard'])) {
            $rate = ProductionRate::where('scope', $scopeType)
                ->where('surface_category', 'baseboard')
                ->first();

            $quantity = (float) $area->perimeter_lnft;
            $coats = 2;
            $laborHours = $rate ? $rate->calculateHoursForQuantity($quantity, $coats) : 0;
            $laborCost = $laborHours * $effectiveRate;
            $materialCost = 0.0; // Material costs come from PaintProduct, not ProductionRate
            $total = $laborCost + $materialCost;  // Store COGS, not OTD

            $area->surfaces()->create([
                'name' => 'Baseboards',
                'surface_type' => AreaTemplateSurface::SURFACE_TYPE_TRIM,
                'quantity' => $quantity,
                'unit' => 'lnft',
                'coats' => $coats,
                'production_rate_id' => $rate?->id,
                'production_rate_value' => $rate?->production_rate ?? 30, // Default 30 lnft/hr
                'hourly_labor_rate' => $effectiveRate,
                'material_cost_per_unit' => 0.0, // Material costs come from PaintProduct
                'labor_hours' => round($laborHours, 2),
                'labor_cost' => round($laborCost, 2),
                'material_cost' => round($materialCost, 2),
                'total' => round($total, 2),
                'is_included' => true,
                'sort_order' => $sortOrder++,
            ]);
        }
    }

    /**
     * Delete an area.
     */
    public function deleteArea(int $areaId): void
    {
        $area = EstimateArea::findOrFail($areaId);

        // Delete child areas first
        $area->childAreas()->delete();

        // Delete surfaces
        $area->surfaces()->delete();

        // Delete the area
        $area->delete();

        // Clear from config
        unset($this->areaConfigs[$areaId]);

        // Clear expanded if this was expanded
        if ($this->expandedAreaId === $areaId) {
            $this->expandedAreaId = null;
        }

        // Clear computed cache
        unset($this->areas, $this->standaloneNestedAreas);

        $this->dispatch('area-deleted');
    }

    // ===== AREA DIMENSION UPDATES =====

    /**
     * Update area dimensions.
     */
    public function updateAreaDimension(int $areaId, string $field, float $value): void
    {
        $area = EstimateArea::findOrFail($areaId);

        // Update the dimension
        $area->update([$field => $value]);

        // Refresh the area to get computed sqft values
        $area->refresh();

        // Update surface quantities based on new dimensions
        $this->updateSurfaceQuantities($area);

        // Recalculate
        $this->recalculateArea($area);
        $this->areaConfigs[$areaId] = $this->buildAreaConfig($area);

        // Clear computed cache
        unset($this->areas);

        $this->dispatch('area-updated');
    }

    /**
     * Update surface quantities based on area dimensions.
     */
    protected function updateSurfaceQuantities(EstimateArea $area): void
    {
        foreach ($area->surfaces as $surface) {
            $newQuantity = match ($surface->surface_type) {
                AreaTemplateSurface::SURFACE_TYPE_WALLS => (float) $area->wall_sqft,
                AreaTemplateSurface::SURFACE_TYPE_CEILING => (float) $area->ceiling_sqft,
                AreaTemplateSurface::SURFACE_TYPE_TRIM => (float) $area->perimeter_lnft,
                default => $surface->quantity, // Keep for doors, windows, etc.
            };

            if ($newQuantity !== (float) $surface->quantity) {
                $surface->update(['quantity' => $newQuantity]);
            }
        }
    }

    // ===== SURFACE MANAGEMENT =====

    /**
     * Toggle surface inclusion.
     */
    public function toggleSurface(int $areaId, int $surfaceId): void
    {
        $surface = EstimateAreaSurface::findOrFail($surfaceId);
        $surface->update(['is_included' => ! $surface->is_included]);

        // Update config
        if (isset($this->areaConfigs[$areaId]['surfaces'][$surfaceId])) {
            $this->areaConfigs[$areaId]['surfaces'][$surfaceId]['is_included'] = $surface->is_included;
        }

        // Clear computed cache
        unset($this->areas);

        $this->dispatch('surface-toggled');
    }

    /**
     * Update surface production rate.
     */
    public function updateSurfaceRate(int $areaId, int $surfaceId, ?int $rateId): void
    {
        $surface = EstimateAreaSurface::with('area')->findOrFail($surfaceId);
        $area = $surface->area;

        if ($rateId) {
            $rate = ProductionRate::findOrFail($rateId);
            $settings = CompanySetting::getInstance();
            $scopeType = $area->scope_type ?? 'interior';

            // Recalculate surface with new rate
            $quantity = (float) $surface->quantity;
            $coats = $surface->coats;
            $laborHours = $rate->calculateHoursForQuantity($quantity, $coats);
            $effectiveRate = $settings->getEffectiveHourlyRateForScope($scopeType);
            $laborCost = $laborHours * $effectiveRate;
            $materialCost = 0.0; // Material costs come from PaintProduct, not ProductionRate
            $total = $laborCost + $materialCost;  // Store COGS, not OTD

            $surface->update([
                'production_rate_id' => $rateId,
                'production_rate_value' => $rate->production_rate,
                'hourly_labor_rate' => $effectiveRate,
                'material_cost_per_unit' => 0.0, // Material costs come from PaintProduct
                'labor_hours' => round($laborHours, 2),
                'labor_cost' => round($laborCost, 2),
                'material_cost' => round($materialCost, 2),
                'total' => round($total, 2),
            ]);
        }

        // Update config
        if (isset($this->areaConfigs[$areaId]['surfaces'][$surfaceId])) {
            $this->areaConfigs[$areaId]['surfaces'][$surfaceId]['production_rate_id'] = $rateId;
        }

        // Clear computed cache
        unset($this->areas);

        $this->dispatch('surface-updated');
    }

    // ===== NESTED ITEMS =====

    /**
     * Open nested item modal.
     */
    public function openNestedItemModal(?int $areaId = null): void
    {
        $this->targetAreaIdForNested = $areaId;
        $this->selectedNestedTemplateId = null;
        $this->addAsStandalone = $areaId === null;
        $this->showNestedItemModal = true;
    }

    /**
     * Close nested item modal.
     */
    public function closeNestedItemModal(): void
    {
        $this->showNestedItemModal = false;
        $this->targetAreaIdForNested = null;
        $this->selectedNestedTemplateId = null;
        $this->addAsStandalone = false;
    }

    /**
     * Add a nested item.
     */
    public function addNestedItem(): void
    {
        if (! $this->selectedNestedTemplateId) {
            return;
        }

        $template = NestedAreaTemplate::findOrFail($this->selectedNestedTemplateId);

        if ($this->addAsStandalone || ! $this->targetAreaIdForNested) {
            // Add as standalone area
            $this->estimate->areas()->create([
                'name' => $template->name,
                'nested_area_template_id' => $template->id,
                'is_fixed_price' => true,
                'fixed_price_amount' => $template->fixed_price,
                'scope_type' => $template->scope_type,
                'sort_order' => $this->estimate->areas()->count(),
            ]);
        } else {
            // Add as child of existing area
            $parentArea = EstimateArea::findOrFail($this->targetAreaIdForNested);

            $parentArea->childAreas()->create([
                'estimate_id' => $this->estimate->id,
                'name' => $template->name,
                'nested_area_template_id' => $template->id,
                'is_fixed_price' => true,
                'fixed_price_amount' => $template->fixed_price,
                'scope_type' => $template->scope_type,
                'sort_order' => $parentArea->childAreas()->count(),
            ]);

            // Update area config
            $this->areaConfigs[$this->targetAreaIdForNested] = $this->buildAreaConfig($parentArea->fresh(['childAreas']));
        }

        $this->closeNestedItemModal();

        // Clear computed cache
        unset($this->areas, $this->standaloneNestedAreas);

        $this->dispatch('nested-item-added');
    }

    /**
     * Remove a nested item from an area.
     */
    public function removeNestedItem(int $parentAreaId, int $nestedAreaId): void
    {
        $nestedArea = EstimateArea::findOrFail($nestedAreaId);
        $nestedArea->delete();

        // Update config
        if (isset($this->areaConfigs[$parentAreaId]['nested_items'][$nestedAreaId])) {
            unset($this->areaConfigs[$parentAreaId]['nested_items'][$nestedAreaId]);
        }

        // Clear computed cache
        unset($this->areas);

        $this->dispatch('nested-item-removed');
    }

    /**
     * Remove a standalone nested area.
     */
    public function removeStandaloneNested(int $areaId): void
    {
        $area = EstimateArea::findOrFail($areaId);
        $area->delete();

        // Clear computed cache
        unset($this->standaloneNestedAreas);

        $this->dispatch('area-deleted');
    }

    // ===== ACCORDION CONTROL =====

    /**
     * Toggle area expansion.
     */
    public function toggleAreaExpansion(int $areaId): void
    {
        if ($this->mobileMode) {
            // Accordion mode: only one area open at a time
            $this->expandedAreaId = $this->expandedAreaId === $areaId ? null : $areaId;
        } else {
            // Desktop: toggle individual expansion (currently all expanded by default)
            $this->expandedAreaId = $this->expandedAreaId === $areaId ? null : $areaId;
        }
    }

    /**
     * Check if an area is expanded.
     */
    public function isAreaExpanded(int $areaId): bool
    {
        if ($this->mobileMode) {
            return $this->expandedAreaId === $areaId;
        }

        // Desktop: all expanded by default, or use expandedAreaId for specific
        return true;
    }

    // ===== RECALCULATION =====

    /**
     * Recalculate an area's surfaces using PBP formula.
     */
    protected function recalculateArea(EstimateArea $area): void
    {
        $settings = CompanySetting::getInstance();
        $scopeType = $area->scope_type ?? 'interior';
        $effectiveRate = $settings->getEffectiveHourlyRateForScope($scopeType);

        foreach ($area->surfaces as $surface) {
            if (! $surface->production_rate_id) {
                continue;
            }

            $rate = $surface->productionRate;
            if (! $rate) {
                continue;
            }

            $quantity = (float) $surface->quantity;
            $coats = $surface->coats;
            $laborHours = $rate->calculateHoursForQuantity($quantity, $coats);
            $laborCost = $laborHours * $effectiveRate;
            $materialCost = 0.0; // Material costs come from PaintProduct, not ProductionRate

            // Add cleanup and sundries - store COGS, not OTD (margins applied at Estimate level)
            $cleanupHours = $settings->calculateCleanupHours($laborHours, $scopeType);
            $cleanupCost = $cleanupHours * $effectiveRate;
            $sundriesCost = $settings->calculateSundriesCost($laborHours, $scopeType);

            $total = $laborCost + $cleanupCost + $sundriesCost + $materialCost;

            $surface->update([
                'production_rate_value' => $rate->production_rate,
                'hourly_labor_rate' => $effectiveRate,
                'material_cost_per_unit' => 0.0, // Material costs come from PaintProduct
                'labor_hours' => round($laborHours, 2),
                'labor_cost' => round($laborCost, 2),
                'material_cost' => round($materialCost, 2),
                'total' => round($total, 2),
            ]);
        }
    }

    /**
     * Refresh from parent component.
     */
    #[On('refresh-areas')]
    public function refreshAreas(): void
    {
        $this->estimate->refresh();
        $this->loadAreasIntoConfig();
        unset($this->areas, $this->standaloneNestedAreas);
    }
}; ?>

<div>
    {{-- Header with Total --}}
    <div class="flex items-center justify-between mb-4">
        <div>
            <flux:heading size="lg">Estimate Areas</flux:heading>
            <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                Click to add rooms from templates or create custom areas
            </flux:text>
        </div>
        <div class="flex items-center gap-3">
            <div class="text-right">
                <div class="text-xs text-zinc-500 dark:text-zinc-400">Areas Total</div>
                <div class="text-xl font-bold text-zinc-900 dark:text-zinc-100">
                    ${{ number_format($this->areasTotal, 2) }}
                </div>
            </div>
            <flux:button wire:click="openQuickAddDrawer" icon="plus">
                Add Room
            </flux:button>
        </div>
    </div>

    {{-- Quick Add Template Buttons --}}
    @if ($this->availableTemplates->isNotEmpty())
        <div class="mb-6">
            <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-2">Quick Add from Templates</div>
            <div class="flex flex-wrap gap-2">
                @foreach ($this->availableTemplates->flatten() as $template)
                    <flux:button
                        wire:key="quick-tpl-{{ $template->id }}"
                        wire:click="addFromTemplate({{ $template->id }})"
                        wire:loading.attr="disabled"
                        size="sm"
                        variant="ghost"
                        class="border border-zinc-200 dark:border-zinc-700"
                    >
                        <flux:icon name="{{ $template->icon ?? 'squares-2x2' }}" class="w-4 h-4" />
                        {{ $template->name }}
                    </flux:button>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Areas List --}}
    <div class="space-y-4">
        @forelse ($this->areas as $area)
            <div wire:key="area-{{ $area->id }}" class="border border-zinc-200 dark:border-zinc-700 rounded-lg bg-white dark:bg-zinc-900 overflow-hidden">
                {{-- Area Header --}}
                <div
                    class="p-4 bg-zinc-50 dark:bg-zinc-800/50 cursor-pointer flex items-center justify-between"
                    wire:click="toggleAreaExpansion({{ $area->id }})"
                >
                    <div class="flex items-center gap-3">
                        <flux:icon
                            name="chevron-down"
                            class="w-5 h-5 text-zinc-400 transition-transform {{ $this->isAreaExpanded($area->id) ? '' : '-rotate-90' }}"
                        />
                        <div>
                            <div class="font-medium text-zinc-900 dark:text-zinc-100">{{ $area->name }}</div>
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                {{ number_format($area->length) }}' x {{ number_format($area->width) }}' x {{ number_format($area->height) }}'
                                &bull;
                                {{ number_format($area->wall_sqft) }} sqft walls
                            </div>
                        </div>
                    </div>
                    <div class="flex items-center gap-4">
                        <div class="text-right">
                            <div class="text-lg font-bold text-zinc-900 dark:text-zinc-100">
                                ${{ number_format($this->calculateAreaTotal($area), 2) }}
                            </div>
                        </div>
                        <flux:button
                            wire:click.stop="deleteArea({{ $area->id }})"
                            wire:confirm="Are you sure you want to delete this area?"
                            variant="ghost"
                            size="sm"
                            class="text-red-500 hover:text-red-700"
                        >
                            <flux:icon name="trash" class="w-4 h-4" />
                        </flux:button>
                    </div>
                </div>

                {{-- Area Expanded Content --}}
                @if ($this->isAreaExpanded($area->id))
                    <div class="p-4 border-t border-zinc-200 dark:border-zinc-700">
                        {{-- Dimensions --}}
                        <div class="mb-4">
                            <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-2">Dimensions</div>
                            <div class="grid grid-cols-3 gap-3">
                                <flux:field>
                                    <flux:label class="text-xs">Length (ft)</flux:label>
                                    <flux:input
                                        wire:change="updateAreaDimension({{ $area->id }}, 'length', $event.target.value)"
                                        value="{{ $area->length }}"
                                        type="number"
                                        step="0.5"
                                        min="1"
                                        size="sm"
                                    />
                                </flux:field>
                                <flux:field>
                                    <flux:label class="text-xs">Width (ft)</flux:label>
                                    <flux:input
                                        wire:change="updateAreaDimension({{ $area->id }}, 'width', $event.target.value)"
                                        value="{{ $area->width }}"
                                        type="number"
                                        step="0.5"
                                        min="1"
                                        size="sm"
                                    />
                                </flux:field>
                                <flux:field>
                                    <flux:label class="text-xs">Height (ft)</flux:label>
                                    <flux:input
                                        wire:change="updateAreaDimension({{ $area->id }}, 'height', $event.target.value)"
                                        value="{{ $area->height }}"
                                        type="number"
                                        step="0.5"
                                        min="1"
                                        size="sm"
                                    />
                                </flux:field>
                            </div>
                            <div class="mt-2 p-2 bg-blue-50 dark:bg-blue-900/20 rounded text-xs">
                                <span class="text-blue-700 dark:text-blue-400">
                                    Wall: {{ number_format($area->wall_sqft, 0) }} sqft |
                                    Ceiling: {{ number_format($area->ceiling_sqft, 0) }} sqft |
                                    Perimeter: {{ number_format($area->perimeter_lnft, 0) }} lnft
                                </span>
                            </div>
                        </div>

                        {{-- Surfaces --}}
                        <div class="mb-4">
                            <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-2">Surfaces</div>
                            <div class="space-y-2">
                                @foreach ($area->surfaces as $surface)
                                    <div
                                        wire:key="surface-{{ $surface->id }}"
                                        class="flex items-center gap-3 p-3 rounded-lg {{ $surface->is_included ? 'bg-green-50 dark:bg-green-900/20 border border-green-200 dark:border-green-800' : 'bg-zinc-50 dark:bg-zinc-800/50 border border-zinc-200 dark:border-zinc-700' }}"
                                    >
                                        <flux:checkbox
                                            wire:click="toggleSurface({{ $area->id }}, {{ $surface->id }})"
                                            :checked="$surface->is_included"
                                        />
                                        <div class="flex-1 min-w-0">
                                            <div class="flex items-center gap-2">
                                                <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $surface->name }}</span>
                                                <span class="text-xs text-zinc-500">
                                                    {{ number_format($surface->quantity, 0) }} {{ $surface->unit }}
                                                </span>
                                            </div>
                                            <div class="text-xs text-zinc-500 dark:text-zinc-400 mt-1">
                                                @if ($surface->productionRate)
                                                    {{ $surface->productionRate->name }}
                                                    ({{ number_format($surface->productionRate->production_rate, 0) }} {{ $surface->productionRate->rate_type_label }})
                                                @else
                                                    <span class="text-amber-600">No rate selected</span>
                                                @endif
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-3">
                                            @php
                                                $ratesByCategory = $this->getProductionRatesForScope($area->scope_type);
                                                $surfaceCategory = $surface->productionRate?->surface_category ?? 'walls';
                                                $availableRates = $ratesByCategory[$surfaceCategory] ?? collect();
                                            @endphp
                                            <select
                                                wire:change="updateSurfaceRate({{ $area->id }}, {{ $surface->id }}, $event.target.value)"
                                                class="w-48 text-sm rounded-md border-zinc-300 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100"
                                            >
                                                <option value="">-- Select Rate --</option>
                                                @foreach ($availableRates as $rate)
                                                    <option wire:key="rate-{{ $surface->id }}-{{ $rate->id }}" value="{{ $rate->id }}" {{ $surface->production_rate_id == $rate->id ? 'selected' : '' }}>
                                                        {{ $rate->name }} ({{ number_format($rate->production_rate, 0) }})
                                                    </option>
                                                @endforeach
                                            </select>
                                            <div class="text-right w-20">
                                                <span class="font-semibold {{ $surface->is_included ? 'text-green-700 dark:text-green-400' : 'text-zinc-400' }}">
                                                    ${{ number_format($surface->total, 2) }}
                                                </span>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        {{-- Nested Items in this Area --}}
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Nested Items</div>
                                <flux:button
                                    wire:click="openNestedItemModal({{ $area->id }})"
                                    size="xs"
                                    variant="ghost"
                                >
                                    <flux:icon name="plus" class="w-3 h-3" />
                                    Add Fixed Price
                                </flux:button>
                            </div>
                            @if ($area->childAreas->count() > 0)
                                <div class="space-y-2">
                                    @foreach ($area->childAreas as $child)
                                        <div class="flex items-center justify-between p-2 bg-purple-50 dark:bg-purple-900/20 rounded border border-purple-200 dark:border-purple-800">
                                            <div class="flex items-center gap-2">
                                                <flux:icon name="squares-plus" class="w-4 h-4 text-purple-500" />
                                                <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $child->name }}</span>
                                                @if ($child->nestedAreaTemplate)
                                                    <span class="text-xs text-purple-600 dark:text-purple-400">
                                                        ({{ $child->nestedAreaTemplate->included_items }})
                                                    </span>
                                                @endif
                                            </div>
                                            <div class="flex items-center gap-2">
                                                <span class="font-semibold text-purple-700 dark:text-purple-400">
                                                    ${{ number_format($child->fixed_price_amount, 2) }}
                                                </span>
                                                <flux:button
                                                    wire:click="removeNestedItem({{ $area->id }}, {{ $child->id }})"
                                                    variant="ghost"
                                                    size="xs"
                                                    class="text-red-500"
                                                >
                                                    <flux:icon name="x-mark" class="w-3 h-3" />
                                                </flux:button>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <div class="text-xs text-zinc-400 dark:text-zinc-500 italic">
                                    No fixed price items. Click "Add Fixed Price" to include items.
                                </div>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
        @empty
            <div class="text-center py-12 border-2 border-dashed border-zinc-200 dark:border-zinc-700 rounded-lg">
                <flux:icon name="squares-2x2" class="w-12 h-12 mx-auto text-zinc-300 dark:text-zinc-600 mb-3" />
                <flux:heading size="sm" class="text-zinc-500 dark:text-zinc-400">No areas added yet</flux:heading>
                <flux:text size="sm" class="text-zinc-400 dark:text-zinc-500 mb-4">
                    Click a template button above or add a custom room
                </flux:text>
                <flux:button wire:click="openQuickAddDrawer" icon="plus">
                    Add First Room
                </flux:button>
            </div>
        @endforelse
    </div>

    {{-- Standalone Nested Items Section --}}
    @if ($this->standaloneNestedAreas->count() > 0)
        <div class="mt-6">
            <div class="text-xs font-medium text-zinc-500 dark:text-zinc-400 mb-2">Standalone Items</div>
            <div class="space-y-2">
                @foreach ($this->standaloneNestedAreas as $nested)
                    <div class="flex items-center justify-between p-3 bg-purple-50 dark:bg-purple-900/20 rounded-lg border border-purple-200 dark:border-purple-800">
                        <div class="flex items-center gap-3">
                            <flux:icon name="squares-plus" class="w-5 h-5 text-purple-500" />
                            <div>
                                <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $nested->name }}</span>
                                @if ($nested->nestedAreaTemplate)
                                    <div class="text-xs text-purple-600 dark:text-purple-400">
                                        {{ $nested->nestedAreaTemplate->included_items }}
                                    </div>
                                @endif
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <span class="text-lg font-bold text-purple-700 dark:text-purple-400">
                                ${{ number_format($nested->fixed_price_amount, 2) }}
                            </span>
                            <flux:button
                                wire:click="removeStandaloneNested({{ $nested->id }})"
                                variant="ghost"
                                size="sm"
                                class="text-red-500"
                            >
                                <flux:icon name="trash" class="w-4 h-4" />
                            </flux:button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Add Standalone Nested Button --}}
    <div class="mt-4 flex justify-center">
        <flux:button wire:click="openNestedItemModal" size="sm" variant="ghost">
            <flux:icon name="plus" class="w-4 h-4" />
            Add Standalone Item (Laundry, Pantry, etc.)
        </flux:button>
    </div>

    {{-- Quick Add Drawer --}}
    @if ($showQuickAddDrawer)
        <flux:modal wire:model="showQuickAddDrawer" class="max-w-md">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">Add Custom Room</flux:heading>
                    <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                        Enter room details or select a template to start from
                    </flux:text>
                </div>

                <flux:field>
                    <flux:label>Room Name</flux:label>
                    <flux:input wire:model="newRoomName" placeholder="e.g., Guest Bedroom" />
                    @error('newRoomName') <flux:error>{{ $message }}</flux:error> @enderror
                </flux:field>

                <flux:field>
                    <flux:label>Scope</flux:label>
                    <flux:select wire:model="newRoomScope">
                        <option value="interior">Interior</option>
                        <option value="exterior">Exterior</option>
                    </flux:select>
                </flux:field>

                <div class="grid grid-cols-3 gap-3">
                    <flux:field>
                        <flux:label>Length (ft)</flux:label>
                        <flux:input wire:model="newLength" type="number" step="0.5" min="1" placeholder="12" />
                        @error('newLength') <flux:error>{{ $message }}</flux:error> @enderror
                    </flux:field>
                    <flux:field>
                        <flux:label>Width (ft)</flux:label>
                        <flux:input wire:model="newWidth" type="number" step="0.5" min="1" placeholder="12" />
                        @error('newWidth') <flux:error>{{ $message }}</flux:error> @enderror
                    </flux:field>
                    <flux:field>
                        <flux:label>Height (ft)</flux:label>
                        <flux:input wire:model="newHeight" type="number" step="0.5" min="1" placeholder="9" />
                        @error('newHeight') <flux:error>{{ $message }}</flux:error> @enderror
                    </flux:field>
                </div>

                {{-- Calculated Preview --}}
                @if ($newLength > 0 && $newWidth > 0 && $newHeight > 0)
                    <div class="p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg text-sm">
                        <div class="text-xs font-medium text-blue-700 dark:text-blue-400 mb-1">Calculated</div>
                        <div class="grid grid-cols-3 gap-2 text-xs">
                            <div>
                                <span class="text-zinc-600 dark:text-zinc-400">Walls:</span>
                                <span class="font-semibold">{{ number_format(2 * ($newLength + $newWidth) * $newHeight, 0) }} sqft</span>
                            </div>
                            <div>
                                <span class="text-zinc-600 dark:text-zinc-400">Ceiling:</span>
                                <span class="font-semibold">{{ number_format($newLength * $newWidth, 0) }} sqft</span>
                            </div>
                            <div>
                                <span class="text-zinc-600 dark:text-zinc-400">Perimeter:</span>
                                <span class="font-semibold">{{ number_format(2 * ($newLength + $newWidth), 0) }} lnft</span>
                            </div>
                        </div>
                    </div>
                @endif

                {{-- Or Start from Template --}}
                @if ($this->availableTemplates->isNotEmpty())
                    <div>
                        <div class="relative my-4">
                            <div class="absolute inset-0 flex items-center">
                                <div class="w-full border-t border-zinc-200 dark:border-zinc-700"></div>
                            </div>
                            <div class="relative flex justify-center text-sm">
                                <span class="bg-white dark:bg-zinc-900 px-2 text-zinc-500">or start from template</span>
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            @foreach ($this->availableTemplates->flatten()->take(6) as $template)
                                <flux:button
                                    wire:key="drawer-tpl-{{ $template->id }}"
                                    wire:click="addFromTemplate({{ $template->id }})"
                                    size="sm"
                                    variant="ghost"
                                    class="justify-start"
                                >
                                    <flux:icon name="{{ $template->icon ?? 'squares-2x2' }}" class="w-4 h-4" />
                                    {{ $template->name }}
                                </flux:button>
                            @endforeach
                        </div>
                    </div>
                @endif

                <div class="flex gap-3 pt-4 border-t border-zinc-200 dark:border-zinc-700">
                    <flux:button wire:click="closeQuickAddDrawer" variant="ghost" class="flex-1">
                        Cancel
                    </flux:button>
                    <flux:button wire:click="addCustomRoom" variant="primary" class="flex-1">
                        Add Room
                    </flux:button>
                </div>
            </div>
        </flux:modal>
    @endif

    {{-- Nested Item Modal --}}
    @if ($showNestedItemModal)
        <flux:modal wire:model="showNestedItemModal" class="max-w-md">
            <div class="space-y-6">
                <div>
                    <flux:heading size="lg">Add Nested Item</flux:heading>
                    @if ($this->nestedTemplatesByCategory->isNotEmpty())
                        <flux:text size="sm" class="text-zinc-500 dark:text-zinc-400">
                            Select a fixed-price item like a closet or half bath
                        </flux:text>
                    @endif
                </div>

                <flux:field>
                    <flux:label>Select Item</flux:label>
                    <select
                        wire:model="selectedNestedTemplateId"
                        class="w-full rounded-md border-zinc-300 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100"
                    >
                        <option value="">-- Choose an item --</option>
                        @foreach ($this->nestedTemplatesByCategory as $category => $templates)
                            <optgroup wire:key="optgroup-{{ $category }}" label="{{ ucfirst($category) }}">
                                @foreach ($templates as $template)
                                    <option wire:key="nested-opt-{{ $template->id }}" value="{{ $template->id }}">
                                        {{ $template->name }} - ${{ number_format($template->fixed_price, 2) }}
                                    </option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </flux:field>

                @if ($selectedNestedTemplateId)
                    @php $selectedTemplate = $this->nestedTemplates->find($selectedNestedTemplateId); @endphp
                    @if ($selectedTemplate)
                        <div class="p-3 bg-purple-50 dark:bg-purple-900/20 rounded-lg">
                            <div class="flex justify-between items-center mb-2">
                                <span class="font-medium text-zinc-900 dark:text-zinc-100">{{ $selectedTemplate->name }}</span>
                                <span class="text-lg font-bold text-purple-600 dark:text-purple-400">
                                    ${{ number_format($selectedTemplate->fixed_price, 2) }}
                                </span>
                            </div>
                            @if ($selectedTemplate->description)
                                <div class="text-xs text-zinc-600 dark:text-zinc-400 mb-2">
                                    {{ $selectedTemplate->description }}
                                </div>
                            @endif
                            <div class="text-xs text-purple-600 dark:text-purple-400">
                                Includes: {{ $selectedTemplate->included_items }}
                            </div>
                        </div>
                    @endif
                @endif

                @if (! $targetAreaIdForNested)
                    <div class="p-3 bg-amber-50 dark:bg-amber-900/20 rounded-lg text-sm text-amber-700 dark:text-amber-300">
                        <flux:icon name="information-circle" class="w-4 h-4 inline mr-1" />
                        This item will be added as a standalone line item, not nested within a room.
                    </div>
                @endif

                <div class="flex gap-3 pt-4 border-t border-zinc-200 dark:border-zinc-700">
                    <flux:button wire:click="closeNestedItemModal" variant="ghost" class="flex-1">
                        Cancel
                    </flux:button>
                    <flux:button
                        wire:click="addNestedItem"
                        variant="primary"
                        class="flex-1"
                        :disabled="!$selectedNestedTemplateId"
                    >
                        Add Item
                    </flux:button>
                </div>
            </div>
        </flux:modal>
    @endif
</div>
