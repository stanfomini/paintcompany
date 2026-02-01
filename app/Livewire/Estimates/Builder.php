<?php

namespace App\Livewire\Estimates;

use App\Models\CompanySetting;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\EstimateItem;
use App\Models\NestedAreaTemplate;
use App\Models\PaintBrand;
use App\Models\PaintProduct;
use App\Models\ProductionRate;
use App\Models\ProductionRateCategory;
use App\Models\Substrate;
use App\Services\PaintCalculatorService;
use App\Services\PaintingEstimateCalculatorService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use Livewire\Component;

class Builder extends Component
{
    public ?Estimate $estimate = null;

    public ?Deal $deal = null;

    public string $title = '';

    public string $description = '';

    public float $taxRate = 0;

    public float $discountAmount = 0;

    public string $notes = '';

    public string $terms = '';

    public ?string $validUntil = null;

    /** @var array<int, array{id: int|null, description: string, details: string, quantity: float, unit: string, unit_price: float}> */
    public array $items = [];

    public bool $isEditing = false;

    /** @var array<int, array{id: int|null, name: string, length: float, width: float, height: float, wall_sqft: float, ceiling_sqft: float, floor_sqft: float, notes: string, surfaces: array}> */
    public array $areas = [];

    // Area modal state
    public bool $showAreaModal = false;

    public ?int $editingAreaId = null;

    public string $areaName = '';

    public float $areaLength = 0;

    public float $areaWidth = 0;

    public float $areaHeight = 0;

    public string $areaNotes = '';

    public string $areaScopeType = 'interior';

    public ?int $areaSubstrateId = null;

    // Nested area template modal state
    public bool $showNestedAreaModal = false;

    public ?int $parentAreaIdForNested = null;

    public ?int $selectedTemplateId = null;

    // Surface modal state
    public bool $showSurfaceModal = false;

    public ?int $editingAreaIdForSurface = null;

    public ?int $editingSurfaceId = null;

    public string $surfaceName = '';

    public string $surfaceType = 'wall';

    public float $surfaceQuantity = 0;

    public string $surfaceUnit = 'sqft';

    public int $surfaceCoats = 2;

    public ?int $surfaceRateId = null;

    // Paint configuration for surfaces
    public string $surfaceApplicationMethod = 'brush_roll';

    public ?int $surfacePaintProductId = null;

    // Legacy paint fields (kept for backward compatibility)
    public string $surfacePaintBrand = 'sherwin_williams';

    public string $surfacePaintType = 'superpaint';

    // Production rate filtering
    public string $ratesScopeFilter = 'all';

    protected function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'taxRate' => ['required', 'numeric', 'min:0', 'max:100'],
            'discountAmount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'terms' => ['nullable', 'string', 'max:5000'],
            'validUntil' => ['nullable', 'date', 'after_or_equal:today'],
            'items' => ['array'],
            'items.*.description' => ['required', 'string', 'max:255'],
            'items.*.details' => ['nullable', 'string', 'max:1000'],
            'items.*.quantity' => ['required', 'numeric', 'min:0.01'],
            'items.*.unit' => ['required', 'string', 'max:50'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
        ];
    }

    protected function areaRules(): array
    {
        return [
            'areaName' => ['required', 'string', 'max:255'],
            'areaLength' => ['required', 'numeric', 'min:0.01'],
            'areaWidth' => ['required', 'numeric', 'min:0.01'],
            'areaHeight' => ['required', 'numeric', 'min:0.01'],
            'areaNotes' => ['nullable', 'string', 'max:5000'],
            'areaScopeType' => ['required', 'in:interior,exterior'],
            'areaSubstrateId' => ['nullable', 'exists:substrates,id'],
        ];
    }

    protected function surfaceRules(): array
    {
        return [
            'surfaceName' => ['required', 'string', 'max:255'],
            'surfaceType' => ['required', 'string', 'in:wall,ceiling,floor,trim,door,window,other'],
            'surfaceQuantity' => ['required', 'numeric', 'min:0.01'],
            'surfaceCoats' => ['required', 'integer', 'min:1', 'max:5'],
            'surfaceRateId' => ['required', 'exists:production_rates,id'],
        ];
    }

    protected function messages(): array
    {
        return [
            'items.*.description.required' => 'Each line item requires a description.',
            'items.*.quantity.required' => 'Each line item requires a quantity.',
            'items.*.quantity.min' => 'Quantity must be greater than 0.',
            'items.*.unit.required' => 'Each line item requires a unit.',
            'items.*.unit_price.required' => 'Each line item requires a unit price.',
            'areaName.required' => 'Area name is required.',
            'areaLength.required' => 'Length is required.',
            'areaWidth.required' => 'Width is required.',
            'areaHeight.required' => 'Height is required.',
            'surfaceName.required' => 'Surface name is required.',
            'surfaceRateId.required' => 'Please select a production rate.',
            'surfaceQuantity.required' => 'Quantity is required.',
        ];
    }

    public function mount(?Deal $deal = null, ?Estimate $estimate = null): void
    {
        if ($estimate && $estimate->exists) {
            $this->estimate = $estimate->load([
                'items',
                'areas.surfaces.productionRate',
                'areas.substrate',
                'areas.childAreas',
                'areas.nestedAreaTemplate',
            ]);
            $this->deal = $estimate->deal;
            $this->isEditing = true;

            $this->title = $estimate->title;
            $this->description = $estimate->description ?? '';
            $this->taxRate = (float) $estimate->tax_rate;
            $this->discountAmount = (float) $estimate->discount_amount;
            $this->notes = $estimate->notes ?? '';
            $this->terms = $estimate->terms ?? '';
            $this->validUntil = $estimate->valid_until?->format('Y-m-d');

            $this->items = $estimate->items->map(function ($item) {
                return [
                    'id' => $item->id,
                    'description' => $item->description,
                    'details' => $item->details ?? '',
                    'quantity' => (float) $item->quantity,
                    'unit' => $item->unit,
                    'unit_price' => (float) $item->unit_price,
                ];
            })->toArray();

            $this->loadAreas();
        } else {
            $this->deal = $deal;
            $this->addItem();
        }
    }

    public function loadAreas(): void
    {
        if (! $this->estimate) {
            return;
        }

        $this->areas = $this->estimate->areas()
            ->topLevel()
            ->orderBy('sort_order')
            ->get()
            ->map(function ($area) {
                return $this->mapAreaToArray($area);
            })->toArray();
    }

    /**
     * Map an EstimateArea model to array format for the component.
     */
    protected function mapAreaToArray(EstimateArea $area): array
    {
        return [
            'id' => $area->id,
            'name' => $area->name,
            'scope_type' => $area->scope_type ?? 'interior',
            'substrate_id' => $area->substrate_id,
            'substrate_name' => $area->substrate?->name,
            'length' => (float) $area->length,
            'width' => (float) $area->width,
            'height' => (float) $area->height,
            'wall_sqft' => (float) $area->wall_sqft,
            'ceiling_sqft' => (float) $area->ceiling_sqft,
            'floor_sqft' => (float) $area->floor_sqft,
            'notes' => $area->notes ?? '',
            'is_fixed_price' => (bool) $area->is_fixed_price,
            'fixed_price_amount' => (float) ($area->fixed_price_amount ?? 0),
            'nested_area_template_id' => $area->nested_area_template_id,
            'surfaces' => $area->surfaces->map(function ($surface) {
                return [
                    'id' => $surface->id,
                    'name' => $surface->name,
                    'surface_type' => $surface->surface_type,
                    'quantity' => (float) $surface->quantity,
                    'unit' => $surface->unit,
                    'coats' => (int) $surface->coats,
                    'production_rate_id' => $surface->production_rate_id,
                    'production_rate_value' => (float) $surface->production_rate_value,
                    'hourly_labor_rate' => (float) $surface->hourly_labor_rate,
                    'material_cost_per_unit' => (float) $surface->material_cost_per_unit,
                    'labor_hours' => (float) $surface->labor_hours,
                    'labor_cost' => (float) $surface->labor_cost,
                    'material_cost' => (float) $surface->material_cost,
                    'total' => (float) $surface->total,
                    'is_included' => (bool) $surface->is_included,
                    // Paint configuration
                    'application_method' => $surface->application_method ?? 'brush_roll',
                    'paint_brand' => $surface->paint_brand ?? 'sherwin_williams',
                    'paint_type' => $surface->paint_type ?? 'superpaint',
                    'paint_gallons' => (float) ($surface->paint_gallons ?? 0),
                    'paint_cost' => (float) ($surface->paint_cost ?? 0),
                ];
            })->toArray(),
            // Nested areas (closets, etc.)
            'nested_areas' => $area->childAreas->map(function ($child) {
                return [
                    'id' => $child->id,
                    'name' => $child->name,
                    'is_fixed_price' => (bool) $child->is_fixed_price,
                    'fixed_price_amount' => (float) ($child->fixed_price_amount ?? 0),
                    'template_name' => $child->nestedAreaTemplate?->name,
                ];
            })->toArray(),
        ];
    }

    public function addItem(): void
    {
        $this->items[] = [
            'id' => null,
            'description' => '',
            'details' => '',
            'quantity' => 1,
            'unit' => 'each',
            'unit_price' => 0,
        ];
    }

    public function removeItem(int $index): void
    {
        if (count($this->items) > 1) {
            unset($this->items[$index]);
            $this->items = array_values($this->items);
        }
    }

    public function moveItemUp(int $index): void
    {
        if ($index > 0) {
            $temp = $this->items[$index - 1];
            $this->items[$index - 1] = $this->items[$index];
            $this->items[$index] = $temp;
        }
    }

    public function moveItemDown(int $index): void
    {
        if ($index < count($this->items) - 1) {
            $temp = $this->items[$index + 1];
            $this->items[$index + 1] = $this->items[$index];
            $this->items[$index] = $temp;
        }
    }

    // Area Modal Methods
    public function openAreaModal(?int $id = null): void
    {
        $this->resetAreaForm();

        if ($id !== null) {
            $areaIndex = collect($this->areas)->search(fn ($a) => $a['id'] === $id);
            if ($areaIndex !== false) {
                $area = $this->areas[$areaIndex];
                $this->editingAreaId = $id;
                $this->areaName = $area['name'];
                $this->areaLength = $area['length'];
                $this->areaWidth = $area['width'];
                $this->areaHeight = $area['height'];
                $this->areaNotes = $area['notes'] ?? '';
                $this->areaScopeType = $area['scope_type'] ?? 'interior';
                $this->areaSubstrateId = $area['substrate_id'] ?? null;
            }
        }

        $this->showAreaModal = true;
    }

    public function closeAreaModal(): void
    {
        $this->showAreaModal = false;
        $this->resetAreaForm();
    }

    protected function resetAreaForm(): void
    {
        $this->editingAreaId = null;
        $this->areaName = '';
        $this->areaLength = 0;
        $this->areaWidth = 0;
        $this->areaHeight = 0;
        $this->areaNotes = '';
        $this->areaScopeType = 'interior';
        $this->areaSubstrateId = null;
        $this->resetValidation(['areaName', 'areaLength', 'areaWidth', 'areaHeight', 'areaNotes', 'areaScopeType', 'areaSubstrateId']);
    }

    public function saveArea(): void
    {
        $this->validate($this->areaRules());

        // Calculate sqft values
        $wallSqft = 2 * ($this->areaLength + $this->areaWidth) * $this->areaHeight;
        $ceilingSqft = $this->areaLength * $this->areaWidth;
        $floorSqft = $this->areaLength * $this->areaWidth;

        // Get substrate name for display
        $substrateName = null;
        if ($this->areaSubstrateId) {
            $substrateName = Substrate::find($this->areaSubstrateId)?->name;
        }

        if ($this->editingAreaId !== null) {
            // Update existing area
            $areaIndex = collect($this->areas)->search(fn ($a) => $a['id'] === $this->editingAreaId);
            if ($areaIndex !== false) {
                $this->areas[$areaIndex]['name'] = $this->areaName;
                $this->areas[$areaIndex]['length'] = $this->areaLength;
                $this->areas[$areaIndex]['width'] = $this->areaWidth;
                $this->areas[$areaIndex]['height'] = $this->areaHeight;
                $this->areas[$areaIndex]['wall_sqft'] = $wallSqft;
                $this->areas[$areaIndex]['ceiling_sqft'] = $ceilingSqft;
                $this->areas[$areaIndex]['floor_sqft'] = $floorSqft;
                $this->areas[$areaIndex]['notes'] = $this->areaNotes;
                $this->areas[$areaIndex]['scope_type'] = $this->areaScopeType;
                $this->areas[$areaIndex]['substrate_id'] = $this->areaSubstrateId;
                $this->areas[$areaIndex]['substrate_name'] = $substrateName;

                // Update in database if we have an ID
                if ($this->isEditing) {
                    EstimateArea::where('id', $this->editingAreaId)->update([
                        'name' => $this->areaName,
                        'length' => $this->areaLength,
                        'width' => $this->areaWidth,
                        'height' => $this->areaHeight,
                        'notes' => $this->areaNotes ?: null,
                        'scope_type' => $this->areaScopeType,
                        'substrate_id' => $this->areaSubstrateId,
                    ]);
                }
            }
        } else {
            // Create new area
            $newArea = [
                'id' => null,
                'name' => $this->areaName,
                'scope_type' => $this->areaScopeType,
                'substrate_id' => $this->areaSubstrateId,
                'substrate_name' => $substrateName,
                'length' => $this->areaLength,
                'width' => $this->areaWidth,
                'height' => $this->areaHeight,
                'wall_sqft' => $wallSqft,
                'ceiling_sqft' => $ceilingSqft,
                'floor_sqft' => $floorSqft,
                'notes' => $this->areaNotes,
                'is_fixed_price' => false,
                'fixed_price_amount' => 0,
                'surfaces' => [],
                'nested_areas' => [],
            ];

            // Save to database if editing existing estimate
            if ($this->isEditing && $this->estimate) {
                $area = $this->estimate->areas()->create([
                    'name' => $this->areaName,
                    'length' => $this->areaLength,
                    'width' => $this->areaWidth,
                    'height' => $this->areaHeight,
                    'notes' => $this->areaNotes ?: null,
                    'scope_type' => $this->areaScopeType,
                    'substrate_id' => $this->areaSubstrateId,
                    'sort_order' => count($this->areas),
                ]);
                $newArea['id'] = $area->id;
            }

            $this->areas[] = $newArea;
        }

        $this->closeAreaModal();
    }

    public function deleteArea(int $id): void
    {
        $areaIndex = collect($this->areas)->search(fn ($a) => $a['id'] === $id);
        if ($areaIndex !== false) {
            // Delete from database if it exists
            if ($id && $this->isEditing) {
                EstimateArea::where('id', $id)->delete();
            }

            unset($this->areas[$areaIndex]);
            $this->areas = array_values($this->areas);
        }
    }

    // Nested Area Template Methods
    public function openNestedAreaModal(int $parentAreaId): void
    {
        $this->parentAreaIdForNested = $parentAreaId;
        $this->selectedTemplateId = null;
        $this->showNestedAreaModal = true;
    }

    public function closeNestedAreaModal(): void
    {
        $this->showNestedAreaModal = false;
        $this->parentAreaIdForNested = null;
        $this->selectedTemplateId = null;
    }

    public function addNestedAreaFromTemplate(): void
    {
        if (! $this->selectedTemplateId || ! $this->parentAreaIdForNested) {
            return;
        }

        $template = NestedAreaTemplate::find($this->selectedTemplateId);
        if (! $template) {
            return;
        }

        $areaIndex = collect($this->areas)->search(fn ($a) => $a['id'] === $this->parentAreaIdForNested);
        if ($areaIndex === false) {
            return;
        }

        // Create nested area data
        $nestedArea = [
            'id' => null,
            'name' => $template->name,
            'is_fixed_price' => true,
            'fixed_price_amount' => (float) $template->fixed_price,
            'template_name' => $template->name,
        ];

        // Save to database if editing existing estimate
        if ($this->isEditing && $this->parentAreaIdForNested) {
            $calculatorService = app(PaintingEstimateCalculatorService::class);
            $areaData = $calculatorService->createAreaFromTemplate(
                $template,
                $this->estimate->id,
                $this->parentAreaIdForNested
            );

            $createdArea = EstimateArea::create($areaData);
            $nestedArea['id'] = $createdArea->id;
        }

        // Add to local areas array
        if (! isset($this->areas[$areaIndex]['nested_areas'])) {
            $this->areas[$areaIndex]['nested_areas'] = [];
        }
        $this->areas[$areaIndex]['nested_areas'][] = $nestedArea;

        $this->closeNestedAreaModal();
    }

    public function deleteNestedArea(int $parentAreaId, int $nestedAreaId): void
    {
        $areaIndex = collect($this->areas)->search(fn ($a) => $a['id'] === $parentAreaId);
        if ($areaIndex === false) {
            return;
        }

        $nestedIndex = collect($this->areas[$areaIndex]['nested_areas'] ?? [])
            ->search(fn ($n) => $n['id'] === $nestedAreaId);

        if ($nestedIndex !== false) {
            // Delete from database
            if ($nestedAreaId && $this->isEditing) {
                EstimateArea::where('id', $nestedAreaId)->delete();
            }

            unset($this->areas[$areaIndex]['nested_areas'][$nestedIndex]);
            $this->areas[$areaIndex]['nested_areas'] = array_values($this->areas[$areaIndex]['nested_areas']);
        }
    }

    /**
     * Get available nested area templates.
     */
    public function getNestedAreaTemplates(): Collection
    {
        return NestedAreaTemplate::active()->ordered()->get();
    }

    // Surface Modal Methods
    public function openSurfaceModal(int $areaId, ?int $surfaceId = null): void
    {
        $this->resetSurfaceForm();
        $this->editingAreaIdForSurface = $areaId;

        // Get area data for auto-fill
        $areaIndex = collect($this->areas)->search(fn ($a) => $a['id'] === $areaId);
        $area = $areaIndex !== false ? $this->areas[$areaIndex] : null;

        if ($surfaceId !== null && $area) {
            $surfaceIndex = collect($area['surfaces'])->search(fn ($s) => $s['id'] === $surfaceId);
            if ($surfaceIndex !== false) {
                $surface = $area['surfaces'][$surfaceIndex];
                $this->editingSurfaceId = $surfaceId;
                $this->surfaceName = $surface['name'];
                $this->surfaceType = $surface['surface_type'];
                $this->surfaceQuantity = $surface['quantity'];
                $this->surfaceUnit = $surface['unit'];
                $this->surfaceCoats = $surface['coats'];
                $this->surfaceRateId = $surface['production_rate_id'];
                // Load paint configuration
                $this->surfaceApplicationMethod = $surface['application_method'] ?? 'brush_roll';
                $this->surfacePaintBrand = $surface['paint_brand'] ?? 'sherwin_williams';
                $this->surfacePaintType = $surface['paint_type'] ?? 'superpaint';
            }
        } else {
            // Default to wall surface type and auto-fill quantity
            $this->surfaceType = 'wall';
            if ($area) {
                $this->surfaceQuantity = $area['wall_sqft'];
                // Auto-generate surface name from area name + surface type
                $this->surfaceName = $this->generateSurfaceName($area['name'], 'wall');
            }
        }

        $this->showSurfaceModal = true;
    }

    /**
     * Generate a surface name from area name and surface type.
     */
    protected function generateSurfaceName(string $areaName, string $surfaceType): string
    {
        $typeLabels = [
            'wall' => 'Walls',
            'ceiling' => 'Ceiling',
            'floor' => 'Floor',
            'trim' => 'Trim',
            'door' => 'Doors',
            'window' => 'Windows',
            'other' => 'Other',
        ];

        $typeLabel = $typeLabels[$surfaceType] ?? ucfirst($surfaceType);

        return "{$areaName} - {$typeLabel}";
    }

    public function closeSurfaceModal(): void
    {
        $this->showSurfaceModal = false;
        $this->resetSurfaceForm();
    }

    protected function resetSurfaceForm(): void
    {
        $this->editingAreaIdForSurface = null;
        $this->editingSurfaceId = null;
        $this->surfaceName = '';
        $this->surfaceType = 'wall';
        $this->surfaceQuantity = 0;
        $this->surfaceUnit = 'sqft';
        $this->surfaceCoats = 2;
        $this->surfaceRateId = null;
        // Reset paint fields
        $this->surfaceApplicationMethod = 'brush_roll';
        $this->surfacePaintBrand = 'sherwin_williams';
        $this->surfacePaintType = 'superpaint';
        $this->resetValidation(['surfaceName', 'surfaceType', 'surfaceQuantity', 'surfaceCoats', 'surfaceRateId']);
    }

    public function updatedSurfaceType(): void
    {
        // Auto-fill quantity based on surface type and area dimensions
        if ($this->editingAreaIdForSurface === null) {
            return;
        }

        $areaIndex = collect($this->areas)->search(fn ($a) => $a['id'] === $this->editingAreaIdForSurface);
        if ($areaIndex === false) {
            return;
        }

        $area = $this->areas[$areaIndex];

        $this->surfaceQuantity = match ($this->surfaceType) {
            'wall' => $area['wall_sqft'],
            'ceiling' => $area['ceiling_sqft'],
            'floor' => $area['floor_sqft'],
            default => $this->surfaceQuantity,
        };

        // Auto-update surface name when type changes (only for new surfaces)
        if ($this->editingSurfaceId === null) {
            $this->surfaceName = $this->generateSurfaceName($area['name'], $this->surfaceType);
        }
    }

    public function updatedSurfaceRateId(): void
    {
        // Update unit based on selected rate
        if ($this->surfaceRateId) {
            $rate = ProductionRate::find($this->surfaceRateId);
            if ($rate) {
                $this->surfaceUnit = $rate->unit_type;
                $this->surfaceCoats = $rate->default_coats ?? 2;
            }
        }
    }

    /**
     * Save surface using PBP (Painting Business Pricing) formula.
     *
     * Uses scope-specific rates from CompanySetting based on the area's scope_type.
     */
    public function saveSurface(): void
    {
        $this->validate($this->surfaceRules());

        $rate = ProductionRate::findOrFail($this->surfaceRateId);

        $areaIndex = collect($this->areas)->search(fn ($a) => $a['id'] === $this->editingAreaIdForSurface);
        if ($areaIndex === false) {
            return;
        }

        // Get scope type from area for PBP formula
        $scopeType = $this->areas[$areaIndex]['scope_type'] ?? 'interior';
        $settings = CompanySetting::getInstance();

        // PBP Formula: Calculate costs using scope-specific rates
        $coats = $this->surfaceCoats;
        $laborHours = ($this->surfaceQuantity / $rate->production_rate) * $coats;
        $effectiveHourlyRate = $settings->getEffectiveHourlyRateForScope($scopeType);
        $laborCost = $laborHours * $effectiveHourlyRate;
        $cleanupHours = $settings->calculateCleanupHours($laborHours, $scopeType);
        $cleanupCost = $cleanupHours * $effectiveHourlyRate;
        $sundriesCost = $settings->calculateSundriesCost($laborHours, $scopeType);
        $materialCost = $this->surfaceQuantity * $rate->material_cost_per_unit * $coats;
        $baseTotal = $laborCost + $cleanupCost + $sundriesCost + $materialCost;

        // Calculate paint needs
        $paintCalculator = app(PaintCalculatorService::class);
        $paintCalc = $paintCalculator->calculatePaintCost(
            $this->surfaceQuantity,
            $this->surfacePaintBrand,
            $this->surfacePaintType,
            $coats,
            $this->surfaceApplicationMethod
        );

        $surfaceData = [
            'name' => $this->surfaceName,
            'surface_type' => $this->surfaceType,
            'quantity' => $this->surfaceQuantity,
            'unit' => $this->surfaceUnit,
            'coats' => $coats,
            'production_rate_id' => $this->surfaceRateId,
            'production_rate_value' => (float) $rate->production_rate,
            'hourly_labor_rate' => round($effectiveHourlyRate, 2), // Store PBP effective rate
            'material_cost_per_unit' => (float) $rate->material_cost_per_unit,
            'labor_hours' => round($laborHours, 2),
            'labor_cost' => round($laborCost, 2),
            'material_cost' => round($materialCost, 2),
            'total' => round($baseTotal, 2), // Base total before margins
            'is_included' => true,
            // Paint configuration
            'application_method' => $this->surfaceApplicationMethod,
            'paint_brand' => $this->surfacePaintBrand,
            'paint_type' => $this->surfacePaintType,
            'paint_gallons' => $paintCalc['gallons_rounded'] ?? null,
            'paint_cost' => $paintCalc['total_cost'] ?? null,
        ];

        if ($this->editingSurfaceId !== null) {
            // Update existing surface
            $surfaceIndex = collect($this->areas[$areaIndex]['surfaces'])->search(fn ($s) => $s['id'] === $this->editingSurfaceId);
            if ($surfaceIndex !== false) {
                $surfaceData['id'] = $this->editingSurfaceId;
                $this->areas[$areaIndex]['surfaces'][$surfaceIndex] = $surfaceData;

                // Update in database
                if ($this->isEditing) {
                    EstimateAreaSurface::where('id', $this->editingSurfaceId)->update([
                        'production_rate_id' => $this->surfaceRateId,
                        'name' => $this->surfaceName,
                        'surface_type' => $this->surfaceType,
                        'quantity' => $this->surfaceQuantity,
                        'unit' => $this->surfaceUnit,
                        'coats' => $coats,
                        'production_rate_value' => $rate->production_rate,
                        'hourly_labor_rate' => round($effectiveHourlyRate, 2),
                        'material_cost_per_unit' => $rate->material_cost_per_unit,
                        'application_method' => $this->surfaceApplicationMethod,
                        'paint_brand' => $this->surfacePaintBrand,
                        'paint_type' => $this->surfacePaintType,
                        'paint_gallons' => $paintCalc['gallons_rounded'] ?? null,
                        'paint_cost' => $paintCalc['total_cost'] ?? null,
                    ]);
                }
            }
        } else {
            // Create new surface
            $surfaceData['id'] = null;

            // Save to database if editing existing estimate
            if ($this->isEditing && $this->editingAreaIdForSurface) {
                $surface = EstimateAreaSurface::create([
                    'area_id' => $this->editingAreaIdForSurface,
                    'production_rate_id' => $this->surfaceRateId,
                    'name' => $this->surfaceName,
                    'surface_type' => $this->surfaceType,
                    'quantity' => $this->surfaceQuantity,
                    'unit' => $this->surfaceUnit,
                    'coats' => $coats,
                    'production_rate_value' => $rate->production_rate,
                    'hourly_labor_rate' => round($effectiveHourlyRate, 2),
                    'material_cost_per_unit' => $rate->material_cost_per_unit,
                    'application_method' => $this->surfaceApplicationMethod,
                    'paint_brand' => $this->surfacePaintBrand,
                    'paint_type' => $this->surfacePaintType,
                    'paint_gallons' => $paintCalc['gallons_rounded'] ?? null,
                    'paint_cost' => $paintCalc['total_cost'] ?? null,
                    'sort_order' => count($this->areas[$areaIndex]['surfaces']),
                ]);
                $surfaceData['id'] = $surface->id;
                $surfaceData['labor_hours'] = (float) $surface->labor_hours;
                $surfaceData['labor_cost'] = (float) $surface->labor_cost;
                $surfaceData['material_cost'] = (float) $surface->material_cost;
                $surfaceData['total'] = (float) $surface->total;
            }

            $this->areas[$areaIndex]['surfaces'][] = $surfaceData;
        }

        $this->closeSurfaceModal();
    }

    public function deleteSurface(int $areaId, int $surfaceId): void
    {
        $areaIndex = collect($this->areas)->search(fn ($a) => $a['id'] === $areaId);
        if ($areaIndex === false) {
            return;
        }

        $surfaceIndex = collect($this->areas[$areaIndex]['surfaces'])->search(fn ($s) => $s['id'] === $surfaceId);
        if ($surfaceIndex !== false) {
            // Delete from database if it exists
            if ($surfaceId && $this->isEditing) {
                EstimateAreaSurface::where('id', $surfaceId)->delete();
            }

            unset($this->areas[$areaIndex]['surfaces'][$surfaceIndex]);
            $this->areas[$areaIndex]['surfaces'] = array_values($this->areas[$areaIndex]['surfaces']);
        }
    }

    public function toggleSurfaceIncluded(int $areaId, int $surfaceId): void
    {
        $areaIndex = collect($this->areas)->search(fn ($a) => $a['id'] === $areaId);
        if ($areaIndex === false) {
            return;
        }

        $surfaceIndex = collect($this->areas[$areaIndex]['surfaces'])->search(fn ($s) => $s['id'] === $surfaceId);
        if ($surfaceIndex !== false) {
            $this->areas[$areaIndex]['surfaces'][$surfaceIndex]['is_included'] =
                ! $this->areas[$areaIndex]['surfaces'][$surfaceIndex]['is_included'];

            // Update in database
            if ($surfaceId && $this->isEditing) {
                EstimateAreaSurface::where('id', $surfaceId)->update([
                    'is_included' => $this->areas[$areaIndex]['surfaces'][$surfaceIndex]['is_included'],
                ]);
            }
        }
    }

    public function quickAddSurface(int $areaId, string $surfaceType): void
    {
        $this->resetSurfaceForm();
        $this->editingAreaIdForSurface = $areaId;
        $this->editingSurfaceId = null;

        // Get area data for auto-fill
        $areaIndex = collect($this->areas)->search(fn ($a) => $a['id'] === $areaId);
        if ($areaIndex === false) {
            return;
        }

        $area = $this->areas[$areaIndex];

        // Set surface type
        $this->surfaceType = $surfaceType;

        // Auto-fill quantity based on surface type
        $this->surfaceQuantity = match ($surfaceType) {
            'wall' => $area['wall_sqft'],
            'ceiling' => $area['ceiling_sqft'],
            default => 0,
        };

        // Auto-generate surface name
        $this->surfaceName = $this->generateSurfaceName($area['name'], $surfaceType);

        // Reset other fields to defaults
        $this->surfaceUnit = 'sqft';
        $this->surfaceCoats = 2;
        $this->surfaceRateId = null;
        $this->surfaceApplicationMethod = 'brush_roll';
        $this->surfacePaintBrand = 'sherwin_williams';
        $this->surfacePaintType = 'superpaint';

        $this->showSurfaceModal = true;
    }

    public function getItemsSubtotalProperty(): float
    {
        return collect($this->items)->sum(function ($item) {
            return (float) ($item['quantity'] ?? 0) * (float) ($item['unit_price'] ?? 0);
        });
    }

    public function getAreasSubtotalProperty(): float
    {
        return collect($this->areas)->sum(function ($area) {
            // Sum surfaces
            $surfacesTotal = collect($area['surfaces'] ?? [])->sum(function ($surface) {
                return ($surface['is_included'] ?? true) ? (float) ($surface['total'] ?? 0) : 0;
            });

            // Add nested area fixed prices
            $nestedTotal = collect($area['nested_areas'] ?? [])->sum(function ($nested) {
                return ($nested['is_fixed_price'] ?? false) ? (float) ($nested['fixed_price_amount'] ?? 0) : 0;
            });

            return $surfacesTotal + $nestedTotal;
        });
    }

    public function getSubtotalProperty(): float
    {
        return $this->itemsSubtotal + $this->areasSubtotal;
    }

    public function getTaxAmountProperty(): float
    {
        return $this->subtotal * ($this->taxRate / 100);
    }

    public function getTotalProperty(): float
    {
        return $this->subtotal + $this->taxAmount - $this->discountAmount;
    }

    public function getProductionRates(): Collection
    {
        $query = ProductionRateCategory::active()
            ->with(['rates' => fn ($q) => $q->active()->orderBy('sort_order')])
            ->orderBy('sort_order');

        // Filter by scope type if not 'all'
        if ($this->ratesScopeFilter !== 'all') {
            $query->byType($this->ratesScopeFilter);
        }

        return $query->get();
    }

    public function getScopeTypeOptions(): array
    {
        return [
            'all' => 'All Categories',
            ProductionRateCategory::TYPE_INTERIOR => 'Interior',
            ProductionRateCategory::TYPE_EXTERIOR => 'Exterior',
            ProductionRateCategory::TYPE_SPECIALTY => 'Specialty',
        ];
    }

    /**
     * Get substrates for the area modal, optionally filtered by scope.
     */
    public function getSubstrates(?string $scopeType = null): Collection
    {
        $query = Substrate::active()->orderBy('sort_order');

        if ($scopeType) {
            $query->forScope($scopeType);
        }

        return $query->get();
    }

    /**
     * Get substrates filtered by current area scope type.
     */
    public function getFilteredSubstrates(): Collection
    {
        return $this->getSubstrates($this->areaScopeType);
    }

    /**
     * Get paint brands with their products.
     */
    public function getPaintBrandsWithProducts(?string $scopeType = null): Collection
    {
        return PaintBrand::active()
            ->with(['products' => function ($query) use ($scopeType) {
                $query->active()->orderBy('sort_order');
                if ($scopeType) {
                    $query->forScope($scopeType);
                }
            }])
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Get paint products as a flat list for selection.
     */
    public function getPaintProducts(?string $scopeType = null): Collection
    {
        $query = PaintProduct::active()
            ->with('brand')
            ->orderBy('paint_brand_id')
            ->orderBy('sort_order');

        if ($scopeType) {
            $query->forScope($scopeType);
        }

        return $query->get();
    }

    /**
     * Get paint product details for the currently selected product.
     */
    public function getSelectedPaintProduct(): ?PaintProduct
    {
        if (! $this->surfacePaintProductId) {
            return null;
        }

        return PaintProduct::with('brand')->find($this->surfacePaintProductId);
    }

    public function getPaintBrands(): array
    {
        return app(PaintCalculatorService::class)->getAvailableBrands();
    }

    public function getPaintTypesForBrand(): array
    {
        return app(PaintCalculatorService::class)->getPaintTypesForBrand($this->surfacePaintBrand);
    }

    public function getSelectedPaintDetails(): ?array
    {
        return app(PaintCalculatorService::class)->getPaintDetails(
            $this->surfacePaintBrand,
            $this->surfacePaintType
        );
    }

    public function getCoverageMultipliers(): array
    {
        return app(PaintCalculatorService::class)->getCoverageMultipliers();
    }

    public function updatedSurfacePaintBrand(): void
    {
        // Reset paint type to first available for this brand
        $types = $this->getPaintTypesForBrand();
        $this->surfacePaintType = $types[0] ?? 'superpaint';
    }

    public function getCalculatedAreaSqft(): array
    {
        if ($this->areaLength <= 0 || $this->areaWidth <= 0 || $this->areaHeight <= 0) {
            return [
                'wall_sqft' => 0,
                'ceiling_sqft' => 0,
                'floor_sqft' => 0,
            ];
        }

        return [
            'wall_sqft' => 2 * ($this->areaLength + $this->areaWidth) * $this->areaHeight,
            'ceiling_sqft' => $this->areaLength * $this->areaWidth,
            'floor_sqft' => $this->areaLength * $this->areaWidth,
        ];
    }

    /**
     * Calculate surface costs using PBP (Painting Business Pricing) formula.
     *
     * Uses scope-specific rates from CompanySetting:
     * - Interior: $21/hr wage × 1.35 burden, 50% markup, 16:1 cleanup, $3.30 sundries
     * - Exterior: $27/hr wage × 1.35 burden, 41% markup, 20:1 cleanup, $1.00 sundries
     *
     * @return array{labor_hours: float, labor_cost: float, cleanup_hours: float, cleanup_cost: float, sundries_cost: float, material_cost: float, base_total: float, with_margins: float, paint_gallons: float, paint_cost: float, paint_recycle_fee: float, paint_total: float}
     */
    public function getCalculatedSurfaceCosts(): array
    {
        $empty = [
            'labor_hours' => 0,
            'labor_cost' => 0,
            'cleanup_hours' => 0,
            'cleanup_cost' => 0,
            'sundries_cost' => 0,
            'material_cost' => 0,
            'base_total' => 0,
            'with_margins' => 0,
            'paint_gallons' => 0,
            'paint_cost' => 0,
            'paint_recycle_fee' => 0,
            'paint_total' => 0,
        ];

        if (! $this->surfaceRateId || $this->surfaceQuantity <= 0) {
            return $empty;
        }

        $rate = ProductionRate::find($this->surfaceRateId);
        if (! $rate) {
            return $empty;
        }

        // Get scope type from the area being edited
        $scopeType = 'interior';
        if ($this->editingAreaIdForSurface) {
            $areaIndex = collect($this->areas)->search(fn ($a) => $a['id'] === $this->editingAreaIdForSurface);
            if ($areaIndex !== false) {
                $scopeType = $this->areas[$areaIndex]['scope_type'] ?? 'interior';
            }
        }

        $settings = CompanySetting::getInstance();
        $coats = $this->surfaceCoats;

        // PBP Formula Step 1: Calculate labor hours
        $laborHours = ($this->surfaceQuantity / $rate->production_rate) * $coats;

        // PBP Formula Step 2: Get scope-specific effective hourly rate
        $effectiveHourlyRate = $settings->getEffectiveHourlyRateForScope($scopeType);

        // PBP Formula Step 3: Calculate labor cost
        $laborCost = $laborHours * $effectiveHourlyRate;

        // PBP Formula Step 4: Calculate cleanup hours and cost
        $cleanupHours = $settings->calculateCleanupHours($laborHours, $scopeType);
        $cleanupCost = $cleanupHours * $effectiveHourlyRate;

        // PBP Formula Step 5: Calculate sundries cost
        $sundriesCost = $settings->calculateSundriesCost($laborHours, $scopeType);

        // PBP Formula Step 6: Calculate material cost
        $materialCost = $this->surfaceQuantity * $rate->material_cost_per_unit * $coats;

        // PBP Formula Step 7: Calculate base total
        $baseTotal = $laborCost + $cleanupCost + $sundriesCost + $materialCost;

        // PBP Formula Step 8: Apply scope-specific margins
        $withMargins = $settings->calculateWithMarginsForScope($baseTotal, $scopeType);

        // Calculate paint costs (separate from PBP formula)
        $paintCalculator = app(PaintCalculatorService::class);
        $paintCalc = $paintCalculator->calculatePaintCost(
            $this->surfaceQuantity,
            $this->surfacePaintBrand,
            $this->surfacePaintType,
            $coats,
            $this->surfaceApplicationMethod
        );

        return [
            'labor_hours' => round($laborHours, 2),
            'labor_cost' => round($laborCost, 2),
            'cleanup_hours' => round($cleanupHours, 2),
            'cleanup_cost' => round($cleanupCost, 2),
            'sundries_cost' => round($sundriesCost, 2),
            'material_cost' => round($materialCost, 2),
            'base_total' => round($baseTotal, 2),
            'with_margins' => round($withMargins, 2),
            'paint_gallons' => $paintCalc['gallons_rounded'] ?? 0,
            'paint_cost' => $paintCalc['paint_cost'] ?? 0,
            'paint_recycle_fee' => $paintCalc['recycle_fee'] ?? 0,
            'paint_total' => $paintCalc['total_cost'] ?? 0,
        ];
    }

    // ===== EVENT LISTENERS FROM ROOMCONFIGURATOR =====

    /**
     * Refresh areas data when RoomConfigurator adds an area.
     */
    #[On('area-added')]
    public function onAreaAdded(): void
    {
        $this->refreshAreasFromConfigurator();
    }

    /**
     * Refresh areas data when RoomConfigurator updates an area.
     */
    #[On('area-updated')]
    public function onAreaUpdated(): void
    {
        $this->refreshAreasFromConfigurator();
    }

    /**
     * Refresh areas data when RoomConfigurator deletes an area.
     */
    #[On('area-deleted')]
    public function onAreaDeleted(): void
    {
        $this->refreshAreasFromConfigurator();
    }

    /**
     * Refresh areas data when RoomConfigurator toggles a surface.
     */
    #[On('surface-toggled')]
    public function onSurfaceToggled(): void
    {
        $this->refreshAreasFromConfigurator();
    }

    /**
     * Refresh areas data when RoomConfigurator updates a surface.
     */
    #[On('surface-updated')]
    public function onSurfaceUpdated(): void
    {
        $this->refreshAreasFromConfigurator();
    }

    /**
     * Refresh areas data when RoomConfigurator adds a nested item.
     */
    #[On('nested-item-added')]
    public function onNestedItemAdded(): void
    {
        $this->refreshAreasFromConfigurator();
    }

    /**
     * Refresh areas data when RoomConfigurator removes a nested item.
     */
    #[On('nested-item-removed')]
    public function onNestedItemRemoved(): void
    {
        $this->refreshAreasFromConfigurator();
    }

    /**
     * Refresh areas data from database after RoomConfigurator changes.
     * Also recalculates the areasSubtotal for the estimate totals.
     */
    protected function refreshAreasFromConfigurator(): void
    {
        if (! $this->estimate) {
            return;
        }

        // Reload the estimate and areas from database
        $this->estimate->refresh();
        $this->loadAreas();
    }

    public function save(bool $markAsSent = false): void
    {
        $this->validate();

        $estimateData = [
            'deal_id' => $this->deal->id,
            'title' => $this->title,
            'description' => $this->description ?: null,
            'tax_rate' => $this->taxRate,
            'discount_amount' => $this->discountAmount,
            'notes' => $this->notes ?: null,
            'terms' => $this->terms ?: null,
            'valid_until' => $this->validUntil ?: null,
            'items_subtotal' => $this->itemsSubtotal,
            'areas_subtotal' => $this->areasSubtotal,
            'subtotal' => $this->subtotal,
            'tax_amount' => $this->taxAmount,
            'total' => $this->total,
        ];

        if ($this->isEditing) {
            $this->estimate->update($estimateData);

            // Handle line items
            $existingIds = collect($this->items)->pluck('id')->filter()->toArray();
            $this->estimate->items()->whereNotIn('id', $existingIds)->delete();

            foreach ($this->items as $index => $item) {
                if ($item['id']) {
                    EstimateItem::where('id', $item['id'])->update([
                        'description' => $item['description'],
                        'details' => $item['details'] ?: null,
                        'quantity' => $item['quantity'],
                        'unit' => $item['unit'],
                        'unit_price' => $item['unit_price'],
                        'total' => $item['quantity'] * $item['unit_price'],
                        'sort_order' => $index,
                    ]);
                } else {
                    $this->estimate->items()->create([
                        'description' => $item['description'],
                        'details' => $item['details'] ?: null,
                        'quantity' => $item['quantity'],
                        'unit' => $item['unit'],
                        'unit_price' => $item['unit_price'],
                        'total' => $item['quantity'] * $item['unit_price'],
                        'sort_order' => $index,
                    ]);
                }
            }

            // Handle areas (new areas without IDs)
            foreach ($this->areas as $index => $areaData) {
                if (! $areaData['id']) {
                    $area = $this->estimate->areas()->create([
                        'name' => $areaData['name'],
                        'length' => $areaData['length'],
                        'width' => $areaData['width'],
                        'height' => $areaData['height'],
                        'notes' => $areaData['notes'] ?: null,
                        'sort_order' => $index,
                    ]);

                    // Create surfaces for this area
                    foreach ($areaData['surfaces'] as $surfaceIndex => $surfaceData) {
                        $area->surfaces()->create([
                            'production_rate_id' => $surfaceData['production_rate_id'],
                            'name' => $surfaceData['name'],
                            'surface_type' => $surfaceData['surface_type'],
                            'quantity' => $surfaceData['quantity'],
                            'unit' => $surfaceData['unit'],
                            'coats' => $surfaceData['coats'],
                            'production_rate_value' => $surfaceData['production_rate_value'],
                            'hourly_labor_rate' => $surfaceData['hourly_labor_rate'],
                            'material_cost_per_unit' => $surfaceData['material_cost_per_unit'],
                            'is_included' => $surfaceData['is_included'],
                            'sort_order' => $surfaceIndex,
                        ]);
                    }
                }
            }

            if ($markAsSent && $this->estimate->status === 'draft') {
                $this->estimate->markAsSent();
            }

            $this->dispatch('estimate-updated');
            session()->flash('success', 'Estimate updated successfully.');
        } else {
            $estimateData['created_by'] = auth()->id();
            $estimateData['status'] = $markAsSent ? 'sent' : 'draft';

            if ($markAsSent) {
                $estimateData['sent_at'] = now();
            }

            $this->estimate = Estimate::create($estimateData);

            // Create line items
            foreach ($this->items as $index => $item) {
                $this->estimate->items()->create([
                    'description' => $item['description'],
                    'details' => $item['details'] ?: null,
                    'quantity' => $item['quantity'],
                    'unit' => $item['unit'],
                    'unit_price' => $item['unit_price'],
                    'total' => $item['quantity'] * $item['unit_price'],
                    'sort_order' => $index,
                ]);
            }

            // Create areas and surfaces
            foreach ($this->areas as $areaIndex => $areaData) {
                $area = $this->estimate->areas()->create([
                    'name' => $areaData['name'],
                    'length' => $areaData['length'],
                    'width' => $areaData['width'],
                    'height' => $areaData['height'],
                    'notes' => $areaData['notes'] ?: null,
                    'sort_order' => $areaIndex,
                ]);

                foreach ($areaData['surfaces'] as $surfaceIndex => $surfaceData) {
                    $area->surfaces()->create([
                        'production_rate_id' => $surfaceData['production_rate_id'],
                        'name' => $surfaceData['name'],
                        'surface_type' => $surfaceData['surface_type'],
                        'quantity' => $surfaceData['quantity'],
                        'unit' => $surfaceData['unit'],
                        'coats' => $surfaceData['coats'],
                        'production_rate_value' => $surfaceData['production_rate_value'],
                        'hourly_labor_rate' => $surfaceData['hourly_labor_rate'],
                        'material_cost_per_unit' => $surfaceData['material_cost_per_unit'],
                        'is_included' => $surfaceData['is_included'],
                        'sort_order' => $surfaceIndex,
                    ]);
                }
            }

            $this->dispatch('estimate-created');
            session()->flash('success', 'Estimate created successfully.');
        }

        $this->redirect(route('estimates.show', $this->estimate), navigate: true);
    }

    public function saveAsDraft(): void
    {
        $this->save(false);
    }

    public function saveAndSend(): void
    {
        $this->save(true);
    }

    public function render(): View
    {
        $pageTitle = $this->isEditing
            ? 'Edit Estimate: '.$this->estimate->estimate_number
            : 'Create Estimate';

        return view('livewire.estimates.builder', [
            'units' => ['each', 'sq ft', 'linear ft', 'hour', 'day', 'gallon', 'job'],
            'surfaceTypes' => [
                'wall' => 'Wall',
                'ceiling' => 'Ceiling',
                'floor' => 'Floor',
                'trim' => 'Trim',
                'door' => 'Door',
                'window' => 'Window',
                'other' => 'Other',
            ],
            'productionRateCategories' => $this->getProductionRates(),
            'calculatedAreaSqft' => $this->getCalculatedAreaSqft(),
            'calculatedSurfaceCosts' => $this->getCalculatedSurfaceCosts(),
            'substrates' => $this->getFilteredSubstrates(),
            'nestedAreaTemplates' => $this->getNestedAreaTemplates(),
            'paintProducts' => $this->getPaintProducts($this->areaScopeType ?: null),
            'selectedPaintProduct' => $this->getSelectedPaintProduct(),
        ])->layout('layouts.app', ['title' => $pageTitle]);
    }
}
