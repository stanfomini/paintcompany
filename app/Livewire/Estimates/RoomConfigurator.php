<?php

namespace App\Livewire\Estimates;

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

class RoomConfigurator extends Component
{
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
                $materialCost = $quantity * ($rate->material_cost_per_unit ?? 0) * $coats;

                // Add cleanup, sundries, then apply margins
                $cleanupHours = $settings->calculateCleanupHours($laborHours, $scopeType);
                $cleanupCost = $cleanupHours * $effectiveRate;
                $sundriesCost = $settings->calculateSundriesCost($laborHours, $scopeType);
                $baseTotal = $laborCost + $cleanupCost + $sundriesCost + $materialCost;
                $total = $settings->calculateWithMarginsForScope($baseTotal, $scopeType);
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
                'material_cost_per_unit' => $rate?->material_cost_per_unit,
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
            $materialCost = $quantity * ($rate?->material_cost_per_unit ?? 0.15) * $coats;
            $baseTotal = $laborCost + $materialCost;
            $total = $settings->calculateWithMarginsForScope($baseTotal, $scopeType);

            $area->surfaces()->create([
                'name' => 'Walls',
                'surface_type' => AreaTemplateSurface::SURFACE_TYPE_WALLS,
                'quantity' => $quantity,
                'unit' => 'sqft',
                'coats' => $coats,
                'production_rate_id' => $rate?->id,
                'production_rate_value' => $rate?->production_rate ?? 200, // Default 200 sqft/hr
                'hourly_labor_rate' => $effectiveRate,
                'material_cost_per_unit' => $rate?->material_cost_per_unit ?? 0.15,
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
            $materialCost = $quantity * ($rate?->material_cost_per_unit ?? 0.15) * $coats;
            $baseTotal = $laborCost + $materialCost;
            $total = $settings->calculateWithMarginsForScope($baseTotal, $scopeType);

            $area->surfaces()->create([
                'name' => 'Ceiling',
                'surface_type' => AreaTemplateSurface::SURFACE_TYPE_CEILING,
                'quantity' => $quantity,
                'unit' => 'sqft',
                'coats' => $coats,
                'production_rate_id' => $rate?->id,
                'production_rate_value' => $rate?->production_rate ?? 150, // Default 150 sqft/hr
                'hourly_labor_rate' => $effectiveRate,
                'material_cost_per_unit' => $rate?->material_cost_per_unit ?? 0.15,
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
            $materialCost = $quantity * ($rate?->material_cost_per_unit ?? 0.05) * $coats;
            $baseTotal = $laborCost + $materialCost;
            $total = $settings->calculateWithMarginsForScope($baseTotal, $scopeType);

            $area->surfaces()->create([
                'name' => 'Baseboards',
                'surface_type' => AreaTemplateSurface::SURFACE_TYPE_TRIM,
                'quantity' => $quantity,
                'unit' => 'lnft',
                'coats' => $coats,
                'production_rate_id' => $rate?->id,
                'production_rate_value' => $rate?->production_rate ?? 30, // Default 30 lnft/hr
                'hourly_labor_rate' => $effectiveRate,
                'material_cost_per_unit' => $rate?->material_cost_per_unit ?? 0.05,
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
            $materialCost = $quantity * ($rate->material_cost_per_unit ?? 0) * $coats;
            $baseTotal = $laborCost + $materialCost;
            $total = $settings->calculateWithMarginsForScope($baseTotal, $scopeType);

            $surface->update([
                'production_rate_id' => $rateId,
                'production_rate_value' => $rate->production_rate,
                'hourly_labor_rate' => $effectiveRate,
                'material_cost_per_unit' => $rate->material_cost_per_unit,
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
            $materialCost = $quantity * ($rate->material_cost_per_unit ?? 0) * $coats;

            // Add cleanup and sundries
            $cleanupHours = $settings->calculateCleanupHours($laborHours, $scopeType);
            $cleanupCost = $cleanupHours * $effectiveRate;
            $sundriesCost = $settings->calculateSundriesCost($laborHours, $scopeType);

            $baseTotal = $laborCost + $cleanupCost + $sundriesCost + $materialCost;
            $total = $settings->calculateWithMarginsForScope($baseTotal, $scopeType);

            $surface->update([
                'production_rate_value' => $rate->production_rate,
                'hourly_labor_rate' => $effectiveRate,
                'material_cost_per_unit' => $rate->material_cost_per_unit,
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

    public function render(): View
    {
        return view('livewire.estimates.room-configurator');
    }
}
