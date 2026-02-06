<?php

namespace App\Services;

use App\Models\AreaTemplateNestedOption;
use App\Models\AreaTemplateSurface;
use App\Models\CompanySetting;
use App\Models\EstimateTemplate;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AreaTemplateService
{
    /**
     * Create a new area template.
     */
    public function createTemplate(array $data, User $creator): EstimateTemplate
    {
        return DB::transaction(function () use ($data, $creator) {
            $template = EstimateTemplate::create([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'icon' => $data['icon'] ?? null,
                'scope_type' => $data['scope_type'] ?? EstimateTemplate::SCOPE_TYPE_INTERIOR,
                'template_category_id' => $data['template_category_id'] ?? null,
                'substrate_id' => $data['substrate_id'] ?? null,
                'default_paint_product_id' => $data['default_paint_product_id'] ?? null,
                'default_coats' => $data['default_coats'] ?? 2,
                'pricing_method' => EstimateTemplate::PRICING_PRODUCTION_RATE,
                'metadata' => $data['metadata'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'created_by' => $creator->id,
                'visibility' => $data['visibility'] ?? EstimateTemplate::VISIBILITY_COMPANY,
                'is_configurable' => true,
            ]);

            // Add surfaces if provided
            if (! empty($data['surfaces'])) {
                foreach ($data['surfaces'] as $index => $surfaceData) {
                    $this->addSurface($template, array_merge($surfaceData, ['sort_order' => $index]));
                }
            }

            // Add nested options if provided
            if (! empty($data['nested_options'])) {
                foreach ($data['nested_options'] as $index => $nestedData) {
                    $this->addNestedOption($template, array_merge($nestedData, ['sort_order' => $index]));
                }
            }

            return $template->fresh(['surfaces', 'nestedOptions']);
        });
    }

    /**
     * Update an existing area template.
     */
    public function updateTemplate(EstimateTemplate $template, array $data): EstimateTemplate
    {
        return DB::transaction(function () use ($template, $data) {
            $template->update([
                'name' => $data['name'] ?? $template->name,
                'description' => $data['description'] ?? $template->description,
                'icon' => $data['icon'] ?? $template->icon,
                'scope_type' => $data['scope_type'] ?? $template->scope_type,
                'template_category_id' => $data['template_category_id'] ?? $template->template_category_id,
                'substrate_id' => $data['substrate_id'] ?? $template->substrate_id,
                'default_paint_product_id' => $data['default_paint_product_id'] ?? $template->default_paint_product_id,
                'default_coats' => $data['default_coats'] ?? $template->default_coats,
                'metadata' => $data['metadata'] ?? $template->metadata,
                'is_active' => $data['is_active'] ?? $template->is_active,
                'visibility' => $data['visibility'] ?? $template->visibility,
            ]);

            // Update surfaces if provided
            if (isset($data['surfaces'])) {
                $this->syncSurfaces($template, $data['surfaces']);
            }

            // Update nested options if provided
            if (isset($data['nested_options'])) {
                $this->syncNestedOptions($template, $data['nested_options']);
            }

            return $template->fresh(['surfaces', 'nestedOptions']);
        });
    }

    /**
     * Clone an existing template for a new owner.
     */
    public function cloneTemplate(EstimateTemplate $template, User $newOwner, ?string $newName = null): EstimateTemplate
    {
        return DB::transaction(function () use ($template, $newOwner, $newName) {
            $clone = $template->replicate(['id', 'slug', 'created_at', 'updated_at']);
            $clone->name = $newName ?? $template->name.' (Copy)';
            $clone->created_by = $newOwner->id;
            $clone->visibility = EstimateTemplate::VISIBILITY_COMPANY;
            $clone->save();

            // Clone surfaces
            foreach ($template->surfaces as $surface) {
                $surfaceClone = $surface->replicate(['id', 'created_at', 'updated_at']);
                $surfaceClone->estimate_template_id = $clone->id;
                $surfaceClone->save();
            }

            // Clone nested options
            foreach ($template->nestedOptions as $option) {
                $optionClone = $option->replicate(['id', 'created_at', 'updated_at']);
                $optionClone->parent_template_id = $clone->id;
                $optionClone->save();
            }

            return $clone->fresh(['surfaces', 'nestedOptions']);
        });
    }

    /**
     * Add a surface to a template.
     */
    public function addSurface(EstimateTemplate $template, array $surfaceData): AreaTemplateSurface
    {
        return $template->surfaces()->create([
            'name' => $surfaceData['name'],
            'surface_type' => $surfaceData['surface_type'] ?? AreaTemplateSurface::SURFACE_TYPE_OTHER,
            'calculation_method' => $surfaceData['calculation_method'] ?? AreaTemplateSurface::CALC_METHOD_RATIO,
            'calculation_value' => $surfaceData['calculation_value'] ?? 1.0,
            'unit_type' => $surfaceData['unit_type'] ?? AreaTemplateSurface::UNIT_TYPE_SQFT,
            'production_rate_id' => $surfaceData['production_rate_id'] ?? null,
            'default_coats' => $surfaceData['default_coats'] ?? 2,
            'is_required' => $surfaceData['is_required'] ?? false,
            'is_default_enabled' => $surfaceData['is_default_enabled'] ?? true,
            'fixed_price' => $surfaceData['fixed_price'] ?? null,
            'sort_order' => $surfaceData['sort_order'] ?? 0,
        ]);
    }

    /**
     * Update a surface.
     */
    public function updateSurface(AreaTemplateSurface $surface, array $data): AreaTemplateSurface
    {
        $surface->update($data);

        return $surface->fresh();
    }

    /**
     * Remove a surface.
     */
    public function removeSurface(AreaTemplateSurface $surface): bool
    {
        return $surface->delete();
    }

    /**
     * Sync surfaces for a template (replace all with new set).
     */
    public function syncSurfaces(EstimateTemplate $template, array $surfaces): void
    {
        // Delete existing surfaces
        $template->surfaces()->delete();

        // Add new surfaces
        foreach ($surfaces as $index => $surfaceData) {
            $this->addSurface($template, array_merge($surfaceData, ['sort_order' => $index]));
        }
    }

    /**
     * Add a nested option to a template.
     */
    public function addNestedOption(EstimateTemplate $template, array $data): AreaTemplateNestedOption
    {
        return $template->nestedOptions()->create([
            'nested_template_id' => $data['nested_template_id'] ?? null,
            'nested_area_template_id' => $data['nested_area_template_id'] ?? null,
            'name' => $data['name'] ?? '',
            'is_default_included' => $data['is_default_included'] ?? false,
            'max_quantity' => $data['max_quantity'] ?? null,
            'sort_order' => $data['sort_order'] ?? 0,
        ]);
    }

    /**
     * Sync nested options for a template.
     */
    public function syncNestedOptions(EstimateTemplate $template, array $options): void
    {
        // Delete existing nested options
        $template->nestedOptions()->delete();

        // Add new nested options
        foreach ($options as $index => $optionData) {
            $this->addNestedOption($template, array_merge($optionData, ['sort_order' => $index]));
        }
    }

    /**
     * Calculate a pricing preview for a template.
     *
     * @return array{
     *   surfaces: array<array{name: string, quantity: float, unit: string, hours: float, cost: float}>,
     *   nested: array<array{name: string, quantity: int, price: float}>,
     *   totals: array{labor_hours: float, labor_cost: float, cleanup_cost: float, sundries_cost: float, material_cost: float, subtotal: float, with_margins: float}
     * }
     */
    public function calculatePreview(EstimateTemplate $template, float $sqft, int $coats, ?array $enabledSurfaces = null): array
    {
        $settings = CompanySetting::getInstance();
        $scope = $template->scope_type ?? EstimateTemplate::SCOPE_TYPE_INTERIOR;

        // Get effective rates for scope
        $hourlyRate = $settings->getEffectiveHourlyRateForScope($scope);

        $surfaceBreakdowns = [];
        $totalLaborHours = 0;
        $totalMaterialCost = 0;

        // Calculate each surface
        foreach ($template->surfaces as $surface) {
            // Skip disabled surfaces if not in enabled list
            if ($enabledSurfaces !== null && ! in_array($surface->id, $enabledSurfaces)) {
                continue;
            }

            // Skip if not enabled by default and no explicit list provided
            if ($enabledSurfaces === null && ! $surface->is_required && ! $surface->is_default_enabled) {
                continue;
            }

            $quantity = $surface->calculateQuantity($sqft);

            // Calculate labor hours
            if ($surface->calculation_method === AreaTemplateSurface::CALC_METHOD_FIXED_PRICE) {
                // Fixed price items don't have labor hours calculated
                $hours = 0;
                $cost = (float) $surface->fixed_price;
            } else {
                $productionRate = $surface->productionRate;
                $rate = $productionRate ? (float) $productionRate->production_rate : 100; // Default 100 sqft/hr

                // Apply coat multiplier
                $coatMultiplier = $this->getCoatMultiplier($coats);
                $hours = ($quantity / $rate) * $coatMultiplier;

                // Labor cost for this surface
                $cost = $hours * $hourlyRate;

                // Material cost (simplified: $0.15 per sqft per coat)
                $materialCostPerUnit = $productionRate ? (float) ($productionRate->material_cost_per_unit ?? 0.15) : 0.15;
                $totalMaterialCost += $quantity * $materialCostPerUnit * $coatMultiplier;
            }

            $totalLaborHours += $hours;

            $surfaceBreakdowns[] = [
                'id' => $surface->id,
                'name' => $surface->name,
                'quantity' => round($quantity, 2),
                'unit' => $surface->unit_type_label,
                'hours' => round($hours, 2),
                'cost' => round($cost, 2),
            ];
        }

        // Calculate nested items
        $nestedBreakdowns = [];
        $nestedTotal = 0;

        foreach ($template->nestedOptions as $option) {
            if (! $option->is_default_included) {
                continue;
            }

            $price = $option->getPrice();
            $nestedTotal += $price;

            $nestedBreakdowns[] = [
                'id' => $option->id,
                'name' => $option->display_name,
                'quantity' => 1,
                'price' => round($price, 2),
            ];
        }

        // Calculate totals
        $laborCost = $totalLaborHours * $hourlyRate;
        $cleanupRatio = $settings->getCleanupHoursRatioForScope($scope);
        $cleanupCost = $laborCost * $cleanupRatio;
        $sundriesCost = $settings->getSundriesCostForScope($scope);

        $subtotal = $laborCost + $cleanupCost + $sundriesCost + $totalMaterialCost + $nestedTotal;

        // Apply margin: OTD = COGS / (margin% / 100)
        $withMargins = $settings->calculateOTDFromCOGS($subtotal, $scope);

        return [
            'surfaces' => $surfaceBreakdowns,
            'nested' => $nestedBreakdowns,
            'totals' => [
                'labor_hours' => round($totalLaborHours, 2),
                'labor_cost' => round($laborCost, 2),
                'cleanup_cost' => round($cleanupCost, 2),
                'sundries_cost' => round($sundriesCost, 2),
                'material_cost' => round($totalMaterialCost, 2),
                'nested_cost' => round($nestedTotal, 2),
                'subtotal' => round($subtotal, 2),
                'with_margins' => round($withMargins, 2),
            ],
        ];
    }

    /**
     * Get the coat multiplier (diminishing returns for additional coats).
     */
    protected function getCoatMultiplier(int $coats): float
    {
        return match ($coats) {
            1 => 1.0,
            2 => 1.8, // Second coat is faster
            3 => 2.5,
            4 => 3.1,
            default => 1.0 + (($coats - 1) * 0.7),
        };
    }

    /**
     * Get all templates visible to a user.
     */
    public function getVisibleTemplates(User $user, ?string $scope = null, bool $configurableOnly = true): Collection
    {
        $query = EstimateTemplate::query()
            ->visibleTo($user)
            ->active();

        if ($configurableOnly) {
            $query->configurable();
        }

        if ($scope) {
            $query->byScope($scope);
        }

        return $query->orderBy('sort_order')->orderBy('name')->get();
    }

    /**
     * Delete a template (if allowed).
     */
    public function deleteTemplate(EstimateTemplate $template, User $user): bool
    {
        if (! $template->canBeDeletedBy($user)) {
            return false;
        }

        return $template->delete();
    }
}
