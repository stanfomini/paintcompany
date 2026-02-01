<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaPaintSelection;
use App\Models\EstimateAreaSurface;
use App\Models\EstimateItem;
use App\Models\EstimateOptionalItem;
use App\Models\EstimateTemplate;
use App\Models\EstimateTemplateCategory;
use App\Models\EstimateTemplateUsage;
use App\Models\ProductionRate;
use App\Models\Substrate;

class EstimateTemplateService
{
    /**
     * Seed default estimate templates.
     *
     * Creates template categories and templates for rooms, surfaces, items, and add-ons.
     * Includes both interior and exterior templates.
     */
    public function seedDefaultTemplates(): void
    {
        // Create template categories
        $roomsCategory = $this->createCategory('Rooms', EstimateTemplateCategory::TYPE_ROOM, 'Room templates with standard dimensions', 1);
        $surfacesCategory = $this->createCategory('Surfaces', EstimateTemplateCategory::TYPE_SURFACE, 'Surface templates for trim and details', 2);
        $itemsCategory = $this->createCategory('Items', EstimateTemplateCategory::TYPE_ITEM, 'Item templates for specialty work', 3);
        $addonsCategory = $this->createCategory('Add-ons', EstimateTemplateCategory::TYPE_ADDON, 'Optional add-on templates', 4);

        // Get interior production rates
        $wallPaintingRate = ProductionRate::where('name', 'Wall Painting')->first();
        $trimPaintingRate = ProductionRate::where('name', 'Trim Painting')->first();
        $cabinetPaintingRate = ProductionRate::where('name', 'Cabinet Painting')->first();

        // Get exterior production rates
        $sidingPaintingRate = ProductionRate::where('name', 'Siding Painting')->first();
        $exteriorTrimRate = ProductionRate::where('name', 'Exterior Trim')->first();
        $deckStainingRate = ProductionRate::where('name', 'Deck Staining')->first();

        // Create interior room templates
        $this->createRoomTemplates($roomsCategory, $wallPaintingRate);

        // Create exterior room templates
        $this->createExteriorRoomTemplates($roomsCategory, $sidingPaintingRate);

        // Create interior surface templates
        $this->createSurfaceTemplates($surfacesCategory, $trimPaintingRate);

        // Create exterior surface templates
        $this->createExteriorSurfaceTemplates($surfacesCategory, $exteriorTrimRate);

        // Create item templates
        $this->createItemTemplates($itemsCategory, $cabinetPaintingRate);

        // Create exterior item templates
        $this->createExteriorItemTemplates($itemsCategory, $deckStainingRate);

        // Create add-on templates
        $this->createAddonTemplates($addonsCategory);
    }

    /**
     * Apply a template to an estimate and create the appropriate result.
     */
    public function applyTemplateToEstimate(
        Estimate $estimate,
        EstimateTemplate $template,
        float $quantity,
        int $coats,
        ?EstimateArea $area = null
    ): EstimateTemplateUsage {
        $categoryType = $template->templateCategory->type;

        // Use PBP formula for pricing (scope-specific rates from CompanySetting)
        $calculatedPrice = $this->calculatePBPPrice($template, $quantity, $coats);

        // Create the appropriate result based on category type
        $resultable = match ($categoryType) {
            EstimateTemplateCategory::TYPE_ROOM => $this->createAreaFromTemplate($estimate, $template, $quantity, $coats),
            EstimateTemplateCategory::TYPE_SURFACE => $this->createSurfaceFromTemplate($area, $template, $quantity, $coats),
            EstimateTemplateCategory::TYPE_ITEM => $this->createItemFromTemplate($estimate, $template, $quantity, $coats),
            EstimateTemplateCategory::TYPE_ADDON => $this->createOptionalItemFromTemplate($estimate, $template, $quantity),
            default => null,
        };

        // Create the template usage record
        $usage = EstimateTemplateUsage::create([
            'estimate_id' => $estimate->id,
            'template_id' => $template->id,
            'quantity' => $quantity,
            'coats' => $coats,
            'calculated_price' => $calculatedPrice,
            'resultable_type' => $resultable ? get_class($resultable) : null,
            'resultable_id' => $resultable?->id,
            'sort_order' => 0,
        ]);

        return $usage;
    }

    /**
     * Calculate price using the PBP (Painting Business Pricing) formula.
     *
     * This method uses scope-specific rates from CompanySetting:
     * - Interior: $21/hr base wage, 50% markup, 16:1 cleanup ratio, $3.30 sundries
     * - Exterior: $27/hr base wage, 41% markup, 20:1 cleanup ratio, $1.00 sundries
     *
     * Formula:
     * 1. labor_hours = quantity / production_rate × coat_multiplier
     * 2. labor_cost = labor_hours × effective_hourly_rate
     * 3. cleanup_cost = (labor_hours / cleanup_ratio) × effective_hourly_rate
     * 4. sundries_cost = labor_hours × sundries_rate
     * 5. material_cost = quantity × material_cost_per_unit × coat_multiplier
     * 6. base_total = labor + cleanup + sundries + material
     * 7. with_margins = base_total × (1 + profit%) × (1 + overhead%) × (1 + scope_markup%)
     */
    public function calculatePBPPrice(EstimateTemplate $template, float $quantity, int $coats): float
    {
        // For non-production-rate templates, use the template's own pricing
        if ($template->pricing_method !== EstimateTemplate::PRICING_PRODUCTION_RATE || ! $template->productionRate) {
            return $template->calculatePrice($quantity, $coats);
        }

        $rate = $template->productionRate;
        $settings = CompanySetting::getInstance();
        $scopeType = $template->scope_type ?? 'interior';

        // Get coat multiplier (1 coat = 1.0, 2 coats = 1.8, 3 coats = 2.5)
        $coatMultiplier = $rate->getCoatMultiplier($coats);

        // 1. Calculate labor hours
        $laborHours = ($quantity / $rate->production_rate) * $coatMultiplier;

        // 2. Get scope-specific effective hourly rate (wage × payroll burden)
        $effectiveHourlyRate = $settings->getEffectiveHourlyRateForScope($scopeType);

        // 3. Calculate labor cost
        $laborCost = $laborHours * $effectiveHourlyRate;

        // 4. Calculate cleanup hours and cost
        $cleanupHours = $settings->calculateCleanupHours($laborHours, $scopeType);
        $cleanupCost = $cleanupHours * $effectiveHourlyRate;

        // 5. Calculate sundries cost
        $sundriesCost = $settings->calculateSundriesCost($laborHours, $scopeType);

        // 6. Calculate material cost
        $materialCost = $quantity * $rate->material_cost_per_unit * $coatMultiplier;

        // 7. Calculate base total (before margins)
        $baseTotal = $laborCost + $cleanupCost + $sundriesCost + $materialCost;

        // 8. Apply scope-specific margins (profit × overhead × markup)
        return round($settings->calculateWithMarginsForScope($baseTotal, $scopeType), 2);
    }

    /**
     * Create an EstimateArea from a template.
     *
     * Automatically inherits substrate, paint product, and scope type from template
     * for full PBP calculation support.
     *
     * @param  float  $sqft  The square footage for the room
     */
    public function createAreaFromTemplate(
        Estimate $estimate,
        EstimateTemplate $template,
        float $sqft,
        int $coats
    ): EstimateArea {
        // Calculate dimensions from sqft (assuming 9ft height, square-ish room)
        $dimensions = $this->calculateDimensionsFromSqft($sqft);

        // Get substrate from template, or use default for scope
        $substrateId = $template->substrate_id;
        if (! $substrateId && $template->scope_type) {
            // Auto-select default substrate for scope (Previously Painted - Good)
            $substrateId = Substrate::active()
                ->forScope($template->scope_type)
                ->where('code', 'LIKE', 'PREV-GOOD%')
                ->value('id');
        }

        $area = EstimateArea::create([
            'estimate_id' => $estimate->id,
            'substrate_id' => $substrateId,
            'name' => $template->name,
            'scope_type' => $template->scope_type ?? EstimateArea::SCOPE_INTERIOR,
            'length' => $dimensions['length'],
            'width' => $dimensions['width'],
            'height' => $dimensions['height'],
            'notes' => $template->description,
            'sort_order' => 0,
        ]);

        // Create default surfaces if template has them and production rate exists
        if ($template->productionRate && ! empty($template->default_surfaces)) {
            $this->createDefaultSurfaces($area, $template, $coats);
        }

        // Create default paint selections if template has a default paint product
        if ($template->default_paint_product_id && ! empty($template->default_surfaces)) {
            $this->createDefaultPaintSelections($area, $template, $coats);
        }

        return $area;
    }

    /**
     * Create an EstimateItem from a template.
     */
    public function createItemFromTemplate(
        Estimate $estimate,
        EstimateTemplate $template,
        float $quantity,
        int $coats
    ): EstimateItem {
        // Use PBP formula for pricing
        $price = $this->calculatePBPPrice($template, $quantity, $coats);
        $unitPrice = $quantity > 0 ? $price / $quantity : $price;

        return EstimateItem::create([
            'estimate_id' => $estimate->id,
            'description' => $template->name,
            'details' => $template->description,
            'quantity' => $quantity,
            'unit' => $template->default_unit ?? 'sqft',
            'unit_price' => $unitPrice,
            'sort_order' => 0,
        ]);
    }

    /**
     * Create an EstimateAreaSurface from a template.
     *
     * Uses PBP formula: scope-specific hourly rates from CompanySetting.
     */
    public function createSurfaceFromTemplate(
        ?EstimateArea $area,
        EstimateTemplate $template,
        float $quantity,
        int $coats
    ): ?EstimateAreaSurface {
        if (! $area) {
            return null;
        }

        // Get scope-specific hourly rate from CompanySetting (PBP formula)
        $settings = CompanySetting::getInstance();
        $scopeType = $area->scope_type ?? $template->scope_type ?? 'interior';
        $effectiveHourlyRate = $settings->getEffectiveHourlyRateForScope($scopeType);

        $productionRate = $template->productionRate;
        if (! $productionRate) {
            // Use default values if no production rate
            return EstimateAreaSurface::create([
                'area_id' => $area->id,
                'name' => $template->name,
                'surface_type' => $template->default_unit ?? 'unit',
                'quantity' => $quantity,
                'unit' => $template->default_unit ?? 'unit',
                'coats' => $coats,
                'production_rate_value' => 1,
                'hourly_labor_rate' => $effectiveHourlyRate, // Use scope-specific rate
                'material_cost_per_unit' => 0,
                'is_included' => true,
                'sort_order' => 0,
            ]);
        }

        // Create surface with scope-specific hourly rate from CompanySetting
        return EstimateAreaSurface::create([
            'area_id' => $area->id,
            'production_rate_id' => $productionRate->id,
            'name' => $productionRate->name,
            'surface_type' => $productionRate->unit_type,
            'quantity' => $quantity,
            'unit' => $productionRate->unit_type,
            'coats' => $coats,
            'production_rate_value' => $productionRate->production_rate,
            'hourly_labor_rate' => $effectiveHourlyRate, // Use scope-specific rate from CompanySetting
            'material_cost_per_unit' => $productionRate->material_cost_per_unit,
            'is_included' => true,
            'sort_order' => 0,
        ]);
    }

    /**
     * Create an EstimateOptionalItem from a template.
     */
    public function createOptionalItemFromTemplate(
        Estimate $estimate,
        EstimateTemplate $template,
        float $quantity
    ): EstimateOptionalItem {
        $pricingType = match ($template->pricing_method) {
            EstimateTemplate::PRICING_PERCENT => EstimateOptionalItem::PRICING_PERCENT,
            default => EstimateOptionalItem::PRICING_FIXED,
        };

        $price = match ($template->pricing_method) {
            EstimateTemplate::PRICING_FIXED => (float) ($template->base_price ?? 0),
            EstimateTemplate::PRICING_PER_SQFT => $quantity * (float) ($template->per_sqft_rate ?? 0),
            default => (float) ($template->base_price ?? 0),
        };

        return EstimateOptionalItem::create([
            'estimate_id' => $estimate->id,
            'template_id' => $template->id,
            'name' => $template->name,
            'description' => $template->description,
            'pricing_type' => $pricingType,
            'price' => $price,
            'percent_of_total' => $template->percent_of_total,
            'is_selected_by_customer' => false,
            'sort_order' => 0,
        ]);
    }

    /**
     * Create a template category if it doesn't exist.
     */
    private function createCategory(string $name, string $type, string $description, int $sortOrder): EstimateTemplateCategory
    {
        return EstimateTemplateCategory::firstOrCreate(
            ['name' => $name],
            [
                'type' => $type,
                'description' => $description,
                'is_active' => true,
                'sort_order' => $sortOrder,
            ]
        );
    }

    /**
     * Create room templates.
     */
    private function createRoomTemplates(EstimateTemplateCategory $category, ?ProductionRate $wallPaintingRate): void
    {
        $rooms = [
            ['name' => 'Standard Bathroom', 'sqft' => 80, 'icon' => 'sparkles'],
            ['name' => 'Standard Bedroom', 'sqft' => 150, 'icon' => 'home'],
            ['name' => 'Kitchen', 'sqft' => 200, 'icon' => 'fire'],
            ['name' => 'Living Room', 'sqft' => 300, 'icon' => 'tv'],
            ['name' => 'Dining Room', 'sqft' => 180, 'icon' => 'rectangle-stack'],
            ['name' => 'Master Bedroom', 'sqft' => 250, 'icon' => 'home-modern'],
            ['name' => 'Hallway', 'sqft' => 100, 'icon' => 'arrow-long-right'],
            ['name' => 'Closet', 'sqft' => 40, 'icon' => 'archive-box'],
        ];

        foreach ($rooms as $index => $room) {
            $this->createTemplate($category, [
                'name' => $room['name'],
                'description' => sprintf('Standard %s with approximately %d sq ft floor space', strtolower($room['name']), $room['sqft']),
                'icon' => $room['icon'],
                'default_quantity' => $room['sqft'],
                'default_unit' => 'sqft',
                'default_coats' => 2,
                'production_rate_id' => $wallPaintingRate?->id,
                'pricing_method' => $wallPaintingRate ? EstimateTemplate::PRICING_PRODUCTION_RATE : EstimateTemplate::PRICING_PER_SQFT,
                'per_sqft_rate' => $wallPaintingRate ? null : 2.50,
                'scope_type' => EstimateTemplate::SCOPE_TYPE_INTERIOR,
                'default_surfaces' => ['walls', 'ceiling'],
                'sort_order' => $index + 1,
            ]);
        }
    }

    /**
     * Create surface templates.
     */
    private function createSurfaceTemplates(EstimateTemplateCategory $category, ?ProductionRate $trimPaintingRate): void
    {
        $surfaces = [
            ['name' => 'Standard Door', 'quantity' => 1, 'unit' => 'unit'],
            ['name' => 'Door Frame', 'quantity' => 1, 'unit' => 'unit'],
            ['name' => 'Window', 'quantity' => 1, 'unit' => 'unit'],
            ['name' => 'Baseboards', 'quantity' => 20, 'unit' => 'lnft'],
            ['name' => 'Crown Molding', 'quantity' => 20, 'unit' => 'lnft'],
        ];

        foreach ($surfaces as $index => $surface) {
            $this->createTemplate($category, [
                'name' => $surface['name'],
                'description' => sprintf('%s painting', $surface['name']),
                'default_quantity' => $surface['quantity'],
                'default_unit' => $surface['unit'],
                'default_coats' => 2,
                'production_rate_id' => $trimPaintingRate?->id,
                'pricing_method' => $trimPaintingRate ? EstimateTemplate::PRICING_PRODUCTION_RATE : EstimateTemplate::PRICING_FIXED,
                'base_price' => $trimPaintingRate ? null : 75,
                'scope_type' => EstimateTemplate::SCOPE_TYPE_INTERIOR,
                'sort_order' => $index + 1,
            ]);
        }
    }

    /**
     * Create item templates.
     */
    private function createItemTemplates(EstimateTemplateCategory $category, ?ProductionRate $cabinetPaintingRate): void
    {
        $items = [
            ['name' => 'Kitchen Cabinets', 'quantity' => 50, 'unit' => 'sqft', 'use_rate' => true],
            ['name' => 'Built-in Shelving', 'quantity' => 30, 'unit' => 'sqft', 'use_rate' => true],
            ['name' => 'Staircase & Railing', 'quantity' => 1, 'unit' => 'unit', 'use_rate' => false, 'fixed_price' => 450],
            ['name' => 'Accent Wall', 'quantity' => 100, 'unit' => 'sqft', 'use_rate' => false, 'per_sqft' => 3.50],
        ];

        foreach ($items as $index => $item) {
            $templateData = [
                'name' => $item['name'],
                'description' => sprintf('%s painting and finishing', $item['name']),
                'default_quantity' => $item['quantity'],
                'default_unit' => $item['unit'],
                'default_coats' => 2,
                'scope_type' => EstimateTemplate::SCOPE_TYPE_INTERIOR,
                'sort_order' => $index + 1,
            ];

            if ($item['use_rate'] && $cabinetPaintingRate) {
                $templateData['production_rate_id'] = $cabinetPaintingRate->id;
                $templateData['pricing_method'] = EstimateTemplate::PRICING_PRODUCTION_RATE;
            } elseif (isset($item['fixed_price'])) {
                $templateData['pricing_method'] = EstimateTemplate::PRICING_FIXED;
                $templateData['base_price'] = $item['fixed_price'];
            } elseif (isset($item['per_sqft'])) {
                $templateData['pricing_method'] = EstimateTemplate::PRICING_PER_SQFT;
                $templateData['per_sqft_rate'] = $item['per_sqft'];
            } else {
                $templateData['pricing_method'] = EstimateTemplate::PRICING_FIXED;
                $templateData['base_price'] = 200;
            }

            $this->createTemplate($category, $templateData);
        }
    }

    /**
     * Create add-on templates.
     */
    private function createAddonTemplates(EstimateTemplateCategory $category): void
    {
        $addons = [
            ['name' => '1-Year Warranty', 'pricing_method' => EstimateTemplate::PRICING_FIXED, 'base_price' => 150, 'description' => 'One year warranty on all painted surfaces'],
            ['name' => '3-Year Warranty', 'pricing_method' => EstimateTemplate::PRICING_FIXED, 'base_price' => 350, 'description' => 'Three year warranty on all painted surfaces'],
            ['name' => '5-Year Warranty', 'pricing_method' => EstimateTemplate::PRICING_FIXED, 'base_price' => 500, 'description' => 'Five year warranty on all painted surfaces'],
            ['name' => 'Premium Paint Upgrade', 'pricing_method' => EstimateTemplate::PRICING_PER_SQFT, 'per_sqft_rate' => 2.00, 'description' => 'Upgrade to premium paint (+$2/sqft)'],
            ['name' => 'Move Furniture', 'pricing_method' => EstimateTemplate::PRICING_FIXED, 'base_price' => 150, 'description' => 'Move and protect furniture during painting'],
            ['name' => 'Color Consultation', 'pricing_method' => EstimateTemplate::PRICING_FIXED, 'base_price' => 100, 'description' => 'Professional color consultation with designer'],
        ];

        foreach ($addons as $index => $addon) {
            $this->createTemplate($category, [
                'name' => $addon['name'],
                'description' => $addon['description'],
                'pricing_method' => $addon['pricing_method'],
                'base_price' => $addon['base_price'] ?? null,
                'per_sqft_rate' => $addon['per_sqft_rate'] ?? null,
                'default_quantity' => 1,
                'default_unit' => 'unit',
                'sort_order' => $index + 1,
            ]);
        }
    }

    /**
     * Create exterior room templates (siding, etc.).
     */
    private function createExteriorRoomTemplates(EstimateTemplateCategory $category, ?ProductionRate $sidingPaintingRate): void
    {
        $exteriorAreas = [
            ['name' => 'Small House Exterior', 'sqft' => 1000, 'icon' => 'home', 'description' => 'Small house exterior siding (1,000 sq ft)'],
            ['name' => 'Medium House Exterior', 'sqft' => 1800, 'icon' => 'home', 'description' => 'Medium house exterior siding (1,800 sq ft)'],
            ['name' => 'Large House Exterior', 'sqft' => 2800, 'icon' => 'home', 'description' => 'Large house exterior siding (2,800 sq ft)'],
            ['name' => 'Garage Exterior', 'sqft' => 400, 'icon' => 'building-office', 'description' => 'Detached garage exterior siding (400 sq ft)'],
            ['name' => 'Shed Exterior', 'sqft' => 200, 'icon' => 'cube', 'description' => 'Outdoor shed exterior (200 sq ft)'],
            ['name' => 'Fence Section', 'sqft' => 150, 'icon' => 'view-columns', 'description' => '50 linear feet of 6ft fence (both sides)'],
        ];

        foreach ($exteriorAreas as $index => $area) {
            $this->createTemplate($category, [
                'name' => $area['name'],
                'description' => $area['description'],
                'icon' => $area['icon'],
                'default_quantity' => $area['sqft'],
                'default_unit' => 'sqft',
                'default_coats' => 2,
                'production_rate_id' => $sidingPaintingRate?->id,
                'pricing_method' => $sidingPaintingRate ? EstimateTemplate::PRICING_PRODUCTION_RATE : EstimateTemplate::PRICING_PER_SQFT,
                'per_sqft_rate' => $sidingPaintingRate ? null : 2.00,
                'scope_type' => EstimateTemplate::SCOPE_TYPE_EXTERIOR,
                'default_surfaces' => ['walls'],
                'sort_order' => $index + 20, // Start after interior rooms
            ]);
        }
    }

    /**
     * Create exterior surface templates (trim, fascia, etc.).
     */
    private function createExteriorSurfaceTemplates(EstimateTemplateCategory $category, ?ProductionRate $exteriorTrimRate): void
    {
        $surfaces = [
            ['name' => 'Fascia Board', 'quantity' => 100, 'unit' => 'lnft', 'description' => 'Fascia board painting (per linear foot)'],
            ['name' => 'Soffit', 'quantity' => 50, 'unit' => 'lnft', 'description' => 'Soffit painting (per linear foot)'],
            ['name' => 'Gutters', 'quantity' => 100, 'unit' => 'lnft', 'description' => 'Gutter painting (per linear foot)'],
            ['name' => 'Exterior Door', 'quantity' => 1, 'unit' => 'unit', 'description' => 'Single exterior door (both sides)'],
            ['name' => 'Garage Door (Single)', 'quantity' => 1, 'unit' => 'unit', 'description' => 'Single garage door'],
            ['name' => 'Garage Door (Double)', 'quantity' => 1, 'unit' => 'unit', 'description' => 'Double garage door'],
            ['name' => 'Window Trim (Exterior)', 'quantity' => 1, 'unit' => 'unit', 'description' => 'Single window exterior trim'],
            ['name' => 'Shutters (Pair)', 'quantity' => 1, 'unit' => 'unit', 'description' => 'Pair of exterior shutters'],
            ['name' => 'Porch Columns', 'quantity' => 1, 'unit' => 'unit', 'description' => 'Single porch column'],
            ['name' => 'Porch Railing', 'quantity' => 10, 'unit' => 'lnft', 'description' => 'Porch railing (per linear foot)'],
        ];

        foreach ($surfaces as $index => $surface) {
            $this->createTemplate($category, [
                'name' => $surface['name'],
                'description' => $surface['description'],
                'default_quantity' => $surface['quantity'],
                'default_unit' => $surface['unit'],
                'default_coats' => 2,
                'production_rate_id' => $exteriorTrimRate?->id,
                'pricing_method' => $exteriorTrimRate ? EstimateTemplate::PRICING_PRODUCTION_RATE : EstimateTemplate::PRICING_FIXED,
                'base_price' => $exteriorTrimRate ? null : 100,
                'scope_type' => EstimateTemplate::SCOPE_TYPE_EXTERIOR,
                'sort_order' => $index + 20, // Start after interior surfaces
            ]);
        }
    }

    /**
     * Create exterior item templates (deck staining, etc.).
     */
    private function createExteriorItemTemplates(EstimateTemplateCategory $category, ?ProductionRate $deckStainingRate): void
    {
        $items = [
            ['name' => 'Small Deck', 'quantity' => 150, 'unit' => 'sqft', 'use_rate' => true, 'description' => 'Small deck staining (approx 150 sq ft)'],
            ['name' => 'Medium Deck', 'quantity' => 300, 'unit' => 'sqft', 'use_rate' => true, 'description' => 'Medium deck staining (approx 300 sq ft)'],
            ['name' => 'Large Deck', 'quantity' => 500, 'unit' => 'sqft', 'use_rate' => true, 'description' => 'Large deck staining (approx 500 sq ft)'],
            ['name' => 'Deck Stairs', 'quantity' => 1, 'unit' => 'unit', 'use_rate' => false, 'fixed_price' => 150, 'description' => 'Deck stairs staining (per set)'],
            ['name' => 'Deck Railing', 'quantity' => 20, 'unit' => 'lnft', 'use_rate' => false, 'per_sqft' => 5.00, 'description' => 'Deck railing staining (per linear foot)'],
            ['name' => 'Pergola', 'quantity' => 100, 'unit' => 'sqft', 'use_rate' => true, 'description' => 'Pergola staining'],
            ['name' => 'Outdoor Furniture', 'quantity' => 1, 'unit' => 'unit', 'use_rate' => false, 'fixed_price' => 200, 'description' => 'Set of outdoor furniture (table + chairs)'],
        ];

        foreach ($items as $index => $item) {
            $templateData = [
                'name' => $item['name'],
                'description' => $item['description'],
                'default_quantity' => $item['quantity'],
                'default_unit' => $item['unit'],
                'default_coats' => 2,
                'scope_type' => EstimateTemplate::SCOPE_TYPE_EXTERIOR,
                'sort_order' => $index + 10, // Start after interior items
            ];

            if ($item['use_rate'] && $deckStainingRate) {
                $templateData['production_rate_id'] = $deckStainingRate->id;
                $templateData['pricing_method'] = EstimateTemplate::PRICING_PRODUCTION_RATE;
            } elseif (isset($item['fixed_price'])) {
                $templateData['pricing_method'] = EstimateTemplate::PRICING_FIXED;
                $templateData['base_price'] = $item['fixed_price'];
            } elseif (isset($item['per_sqft'])) {
                $templateData['pricing_method'] = EstimateTemplate::PRICING_PER_SQFT;
                $templateData['per_sqft_rate'] = $item['per_sqft'];
            } else {
                $templateData['pricing_method'] = EstimateTemplate::PRICING_FIXED;
                $templateData['base_price'] = 200;
            }

            $this->createTemplate($category, $templateData);
        }
    }

    /**
     * Create a template if it doesn't exist.
     *
     * @param  array<string, mixed>  $data
     */
    private function createTemplate(EstimateTemplateCategory $category, array $data): EstimateTemplate
    {
        return EstimateTemplate::firstOrCreate(
            [
                'template_category_id' => $category->id,
                'name' => $data['name'],
            ],
            array_merge($data, [
                'template_category_id' => $category->id,
                'is_active' => true,
            ])
        );
    }

    /**
     * Calculate room dimensions from square footage.
     *
     * Assumes a 9ft ceiling height and a roughly square room.
     *
     * @return array{length: float, width: float, height: float}
     */
    private function calculateDimensionsFromSqft(float $sqft): array
    {
        $height = 9.0;
        $sideLength = sqrt($sqft);

        return [
            'length' => round($sideLength, 2),
            'width' => round($sideLength, 2),
            'height' => $height,
        ];
    }

    /**
     * Create default surfaces for an area based on template configuration.
     *
     * Uses PBP formula: scope-specific hourly rates from CompanySetting instead of
     * the production rate's hourly_labor_rate.
     *
     * Applies substrate multipliers for prep and paint time if a substrate is set.
     */
    private function createDefaultSurfaces(EstimateArea $area, EstimateTemplate $template, int $coats): void
    {
        $defaultSurfaces = $template->default_surfaces ?? [];
        $productionRate = $template->productionRate;

        if (! $productionRate) {
            return;
        }

        // Get substrate multipliers if available
        $substrate = $area->substrate ?? $template->substrate;
        $paintMultiplier = $substrate ? (float) $substrate->paint_time_multiplier : 1.0;

        // Get scope-specific hourly rate from CompanySetting (PBP formula)
        $settings = CompanySetting::getInstance();
        $scopeType = $area->scope_type ?? 'interior';
        $effectiveHourlyRate = $settings->getEffectiveHourlyRateForScope($scopeType);

        foreach ($defaultSurfaces as $index => $surfaceType) {
            $quantity = match ($surfaceType) {
                'walls' => (float) $area->wall_sqft,
                'ceiling' => (float) $area->ceiling_sqft,
                'floor' => (float) $area->floor_sqft,
                default => (float) $template->default_quantity,
            };

            $surfaceName = match ($surfaceType) {
                'walls' => 'Walls',
                'ceiling' => 'Ceiling',
                'floor' => 'Floor',
                default => ucfirst($surfaceType),
            };

            // Adjust production rate by paint time multiplier
            // Higher multiplier = slower painting = lower effective production rate
            $adjustedProductionRate = $productionRate->production_rate / $paintMultiplier;

            EstimateAreaSurface::create([
                'area_id' => $area->id,
                'production_rate_id' => $productionRate->id,
                'name' => $surfaceName,
                'surface_type' => $productionRate->unit_type,
                'quantity' => $quantity,
                'unit' => $productionRate->unit_type,
                'coats' => $coats,
                'production_rate_value' => $adjustedProductionRate,
                'hourly_labor_rate' => $effectiveHourlyRate, // Use scope-specific rate from CompanySetting
                'material_cost_per_unit' => $productionRate->material_cost_per_unit,
                'is_included' => true,
                'sort_order' => $index,
            ]);
        }
    }

    /**
     * Create default paint selections for an area based on template configuration.
     *
     * Creates paint selections for each surface type using the template's default paint product.
     */
    private function createDefaultPaintSelections(EstimateArea $area, EstimateTemplate $template, int $coats): void
    {
        $defaultSurfaces = $template->default_surfaces ?? [];
        $paintProductId = $template->default_paint_product_id;

        if (! $paintProductId || empty($defaultSurfaces)) {
            return;
        }

        foreach ($defaultSurfaces as $surfaceType) {
            $sqft = match ($surfaceType) {
                'walls' => (float) $area->wall_sqft,
                'ceiling' => (float) $area->ceiling_sqft,
                'floor' => (float) $area->floor_sqft,
                default => 0,
            };

            if ($sqft <= 0) {
                continue;
            }

            $paintSelection = EstimateAreaPaintSelection::create([
                'estimate_area_id' => $area->id,
                'paint_product_id' => $paintProductId,
                'surface_type' => $surfaceType,
                'coats' => $coats,
                'sqft_covered' => $sqft,
                'gallons_needed' => 0,
                'paint_cost' => 0,
                'recycle_fee' => 0,
                'total_material_cost' => 0,
            ]);

            // Calculate the paint cost using the product's calculation method
            $paintSelection->calculateCost();
            $paintSelection->save();
        }
    }
}
