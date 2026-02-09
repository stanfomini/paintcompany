<?php

namespace App\Services;

use App\Models\Estimate;
use App\Models\EstimateActivity;
use App\Models\EstimateAmendment;
use App\Models\User;
use App\Notifications\EstimateAmended;

class EstimateAmendmentService
{
    /**
     * Capture a snapshot of the estimate's current state for comparison.
     *
     * @return array{areas: array, packages: array, options: array, totals: array}
     */
    public function captureSnapshot(Estimate $estimate): array
    {
        $estimate->load([
            'areas.surfaces',
            'packages.items',
            'options',
        ]);

        $areas = [];
        foreach ($estimate->areas as $area) {
            $surfaces = [];
            foreach ($area->surfaces as $surface) {
                $surfaces[] = [
                    'id' => $surface->id,
                    'name' => $surface->name,
                    'coats' => (int) $surface->coats,
                    'quantity' => (float) $surface->quantity,
                    'unit' => $surface->unit,
                    'paint_product_id' => $surface->paint_product_id,
                    'production_rate_value' => (float) $surface->production_rate_value,
                    'is_included' => (bool) $surface->is_included,
                    'labor_cost' => (float) $surface->labor_cost,
                    'material_cost' => (float) $surface->material_cost,
                    'total' => (float) $surface->total,
                ];
            }

            $areas[] = [
                'id' => $area->id,
                'name' => $area->name,
                'scope_type' => $area->scope_type,
                'length' => (float) $area->length,
                'width' => (float) $area->width,
                'height' => (float) $area->height,
                'wall_sqft' => (float) $area->wall_sqft,
                'ceiling_sqft' => (float) $area->ceiling_sqft,
                'substrate_id' => $area->substrate_id,
                'surfaces' => $surfaces,
            ];
        }

        $packages = [];
        foreach ($estimate->packages as $package) {
            $items = [];
            foreach ($package->items as $item) {
                $items[] = [
                    'id' => $item->id,
                    'itemable_type' => $item->itemable_type,
                    'itemable_id' => $item->itemable_id,
                    'is_included' => (bool) $item->is_included,
                    'price_override' => $item->price_override ? (float) $item->price_override : null,
                ];
            }

            $packages[] = [
                'id' => $package->id,
                'name' => $package->name,
                'tier' => $package->tier,
                'total' => (float) $package->total,
                'warranty_years' => $package->warranty_years,
                'is_selected' => (bool) $package->is_selected,
                'items' => $items,
            ];
        }

        $options = [];
        foreach ($estimate->options as $option) {
            $options[] = [
                'id' => $option->id,
                'name' => $option->name,
                'pricing_type' => $option->pricing_type,
                'calculated_price' => (float) $option->calculated_price,
                'is_selected' => (bool) $option->is_selected,
            ];
        }

        return [
            'areas' => $areas,
            'packages' => $packages,
            'options' => $options,
            'totals' => [
                'subtotal' => (float) $estimate->subtotal,
                'tax_amount' => (float) $estimate->tax_amount,
                'discount_amount' => (float) $estimate->discount_amount,
                'total' => (float) $estimate->total,
                'deposit_percent' => (float) $estimate->deposit_percent,
            ],
        ];
    }

    /**
     * Compare a baseline snapshot to the current state and return structured changes.
     *
     * @param  array  $baseline  The original snapshot
     * @param  array  $current  The current snapshot
     * @return array<int, array{category: string, action: string, label: string, field?: string, old_value?: string, new_value?: string, details?: string}>
     */
    public function detectChanges(array $baseline, array $current): array
    {
        $changes = [];

        $this->detectAreaChanges($baseline['areas'] ?? [], $current['areas'] ?? [], $changes);
        $this->detectPackageChanges($baseline['packages'] ?? [], $current['packages'] ?? [], $changes);
        $this->detectOptionChanges($baseline['options'] ?? [], $current['options'] ?? [], $changes);
        $this->detectTotalChanges($baseline['totals'] ?? [], $current['totals'] ?? [], $changes);

        return $changes;
    }

    /**
     * Create an amendment record if there are changes to a sent estimate.
     */
    public function createAmendment(Estimate $estimate, User $user): ?EstimateAmendment
    {
        if (! $estimate->sent_snapshot) {
            return null;
        }

        $currentSnapshot = $this->captureSnapshot($estimate);
        $changes = $this->detectChanges($estimate->sent_snapshot, $currentSnapshot);

        if (empty($changes)) {
            return null;
        }

        $nextNumber = ($estimate->amendments()->max('amendment_number') ?? 0) + 1;

        $amendment = EstimateAmendment::create([
            'estimate_id' => $estimate->id,
            'amended_by' => $user->id,
            'amendment_number' => $nextNumber,
            'changes' => $changes,
            'previous_total' => (float) ($estimate->sent_snapshot['totals']['total'] ?? 0),
            'new_total' => (float) $estimate->total,
        ]);

        EstimateActivity::log(
            $estimate,
            'amendment_created',
            "Amendment #{$nextNumber} created with ".count($changes).' change(s)',
            [
                'amendment_id' => $amendment->id,
                'amendment_number' => $nextNumber,
                'change_count' => count($changes),
            ]
        );

        // Notify customer
        $customer = $estimate->deal?->customer;
        if ($customer) {
            $customer->notify(new EstimateAmended($amendment));
        }

        return $amendment;
    }

    /**
     * Record customer acknowledgment of an amendment.
     */
    public function acknowledgeAmendment(EstimateAmendment $amendment, string $initials, string $ip): void
    {
        $amendment->update([
            'customer_acknowledged_at' => now(),
            'customer_initials' => $initials,
            'customer_ip_address' => $ip,
        ]);

        $estimate = $amendment->estimate;

        // If ALL amendments are now acknowledged, update the baseline snapshot
        $pendingCount = $estimate->amendments()
            ->whereNull('customer_acknowledged_at')
            ->count();

        if ($pendingCount === 0) {
            $estimate->sent_snapshot = $this->captureSnapshot($estimate);
            $estimate->save();
        }

        EstimateActivity::log(
            $estimate,
            'amendment_acknowledged',
            "Amendment #{$amendment->amendment_number} acknowledged by customer ({$initials})",
            [
                'amendment_id' => $amendment->id,
                'amendment_number' => $amendment->amendment_number,
                'customer_initials' => $initials,
            ]
        );
    }

    /**
     * Detect changes in areas and their surfaces.
     *
     * @param  array<int, array>  $baselineAreas
     * @param  array<int, array>  $currentAreas
     */
    protected function detectAreaChanges(array $baselineAreas, array $currentAreas, array &$changes): void
    {
        $baselineById = collect($baselineAreas)->keyBy('name')->toArray();
        $currentByName = collect($currentAreas)->keyBy('name')->toArray();

        // Detect removed areas
        foreach ($baselineById as $name => $baseArea) {
            if (! isset($currentByName[$name])) {
                $changes[] = [
                    'category' => 'area',
                    'action' => 'removed',
                    'label' => $name,
                    'details' => "Area removed: {$name}",
                ];
            }
        }

        // Detect added and modified areas
        foreach ($currentByName as $name => $currArea) {
            if (! isset($baselineById[$name])) {
                $changes[] = [
                    'category' => 'area',
                    'action' => 'added',
                    'label' => $name,
                    'details' => "New area added: {$name}",
                ];

                continue;
            }

            $baseArea = $baselineById[$name];

            // Check dimension changes
            foreach (['length', 'width', 'height', 'wall_sqft', 'ceiling_sqft'] as $field) {
                if (round((float) ($baseArea[$field] ?? 0), 2) !== round((float) ($currArea[$field] ?? 0), 2)) {
                    $changes[] = [
                        'category' => 'area',
                        'action' => 'modified',
                        'label' => $name,
                        'field' => ucfirst(str_replace('_', ' ', $field)),
                        'old_value' => number_format((float) ($baseArea[$field] ?? 0), 2),
                        'new_value' => number_format((float) ($currArea[$field] ?? 0), 2),
                    ];
                }
            }

            // Check scope type change
            if (($baseArea['scope_type'] ?? '') !== ($currArea['scope_type'] ?? '')) {
                $changes[] = [
                    'category' => 'area',
                    'action' => 'modified',
                    'label' => $name,
                    'field' => 'Scope',
                    'old_value' => ucfirst($baseArea['scope_type'] ?? 'interior'),
                    'new_value' => ucfirst($currArea['scope_type'] ?? 'interior'),
                ];
            }

            // Detect surface changes within the area
            $this->detectSurfaceChanges(
                $baseArea['surfaces'] ?? [],
                $currArea['surfaces'] ?? [],
                $name,
                $changes
            );
        }
    }

    /**
     * Detect changes in surfaces within an area.
     *
     * @param  array<int, array>  $baselineSurfaces
     * @param  array<int, array>  $currentSurfaces
     */
    protected function detectSurfaceChanges(array $baselineSurfaces, array $currentSurfaces, string $areaName, array &$changes): void
    {
        $baseByName = collect($baselineSurfaces)->keyBy('name')->toArray();
        $currByName = collect($currentSurfaces)->keyBy('name')->toArray();

        // Removed surfaces
        foreach ($baseByName as $name => $baseSurface) {
            if (! isset($currByName[$name])) {
                $changes[] = [
                    'category' => 'surface',
                    'action' => 'removed',
                    'label' => "{$areaName} - {$name}",
                    'details' => "Surface removed: {$name} from {$areaName}",
                ];
            }
        }

        // Added and modified surfaces
        foreach ($currByName as $name => $currSurface) {
            if (! isset($baseByName[$name])) {
                $changes[] = [
                    'category' => 'surface',
                    'action' => 'added',
                    'label' => "{$areaName} - {$name}",
                    'details' => "New surface added: {$name} to {$areaName}",
                ];

                continue;
            }

            $baseSurface = $baseByName[$name];

            // Coats changed
            if ((int) ($baseSurface['coats'] ?? 0) !== (int) ($currSurface['coats'] ?? 0)) {
                $changes[] = [
                    'category' => 'surface',
                    'action' => 'modified',
                    'label' => "{$areaName} - {$name}",
                    'field' => 'Coats',
                    'old_value' => (string) ($baseSurface['coats'] ?? 0),
                    'new_value' => (string) ($currSurface['coats'] ?? 0),
                ];
            }

            // Quantity changed
            if (round((float) ($baseSurface['quantity'] ?? 0), 2) !== round((float) ($currSurface['quantity'] ?? 0), 2)) {
                $changes[] = [
                    'category' => 'surface',
                    'action' => 'modified',
                    'label' => "{$areaName} - {$name}",
                    'field' => 'Quantity',
                    'old_value' => number_format((float) ($baseSurface['quantity'] ?? 0), 2),
                    'new_value' => number_format((float) ($currSurface['quantity'] ?? 0), 2),
                ];
            }

            // Paint product changed
            if (($baseSurface['paint_product_id'] ?? null) !== ($currSurface['paint_product_id'] ?? null)) {
                $changes[] = [
                    'category' => 'surface',
                    'action' => 'modified',
                    'label' => "{$areaName} - {$name}",
                    'field' => 'Paint Product',
                    'old_value' => $baseSurface['paint_product_id'] ? "Product #{$baseSurface['paint_product_id']}" : 'None',
                    'new_value' => $currSurface['paint_product_id'] ? "Product #{$currSurface['paint_product_id']}" : 'None',
                ];
            }

            // Inclusion toggled
            if ((bool) ($baseSurface['is_included'] ?? true) !== (bool) ($currSurface['is_included'] ?? true)) {
                $action = ($currSurface['is_included'] ?? true) ? 'included' : 'excluded';
                $changes[] = [
                    'category' => 'surface',
                    'action' => 'modified',
                    'label' => "{$areaName} - {$name}",
                    'field' => 'Included',
                    'old_value' => ($baseSurface['is_included'] ?? true) ? 'Yes' : 'No',
                    'new_value' => ($currSurface['is_included'] ?? true) ? 'Yes' : 'No',
                    'details' => "Surface {$action}: {$name} in {$areaName}",
                ];
            }
        }
    }

    /**
     * Detect changes in packages.
     *
     * @param  array<int, array>  $baselinePackages
     * @param  array<int, array>  $currentPackages
     */
    protected function detectPackageChanges(array $baselinePackages, array $currentPackages, array &$changes): void
    {
        $baseByName = collect($baselinePackages)->keyBy('name')->toArray();
        $currByName = collect($currentPackages)->keyBy('name')->toArray();

        foreach ($currByName as $name => $currPkg) {
            if (! isset($baseByName[$name])) {
                $changes[] = [
                    'category' => 'package',
                    'action' => 'added',
                    'label' => $name,
                    'details' => "New package added: {$name}",
                ];

                continue;
            }

            $basePkg = $baseByName[$name];

            // Total changed
            if (round((float) ($basePkg['total'] ?? 0), 2) !== round((float) ($currPkg['total'] ?? 0), 2)) {
                $changes[] = [
                    'category' => 'package',
                    'action' => 'modified',
                    'label' => $name,
                    'field' => 'Total',
                    'old_value' => '$'.number_format((float) ($basePkg['total'] ?? 0), 2),
                    'new_value' => '$'.number_format((float) ($currPkg['total'] ?? 0), 2),
                ];
            }

            // Warranty changed
            if (($basePkg['warranty_years'] ?? null) !== ($currPkg['warranty_years'] ?? null)) {
                $changes[] = [
                    'category' => 'package',
                    'action' => 'modified',
                    'label' => $name,
                    'field' => 'Warranty',
                    'old_value' => ($basePkg['warranty_years'] ?? 0).' years',
                    'new_value' => ($currPkg['warranty_years'] ?? 0).' years',
                ];
            }

            // Check item inclusion changes
            $baseItems = collect($basePkg['items'] ?? [])->keyBy(fn ($i) => $i['itemable_type'].':'.$i['itemable_id'])->toArray();
            $currItems = collect($currPkg['items'] ?? [])->keyBy(fn ($i) => $i['itemable_type'].':'.$i['itemable_id'])->toArray();

            foreach ($currItems as $key => $currItem) {
                if (isset($baseItems[$key]) && (bool) $baseItems[$key]['is_included'] !== (bool) $currItem['is_included']) {
                    $action = $currItem['is_included'] ? 'included' : 'excluded';
                    $changes[] = [
                        'category' => 'package',
                        'action' => 'modified',
                        'label' => $name,
                        'field' => 'Item Inclusion',
                        'details' => "Item {$action} in {$name} package",
                    ];
                }
            }
        }

        // Removed packages
        foreach ($baseByName as $name => $basePkg) {
            if (! isset($currByName[$name])) {
                $changes[] = [
                    'category' => 'package',
                    'action' => 'removed',
                    'label' => $name,
                    'details' => "Package removed: {$name}",
                ];
            }
        }
    }

    /**
     * Detect changes in options.
     *
     * @param  array<int, array>  $baselineOptions
     * @param  array<int, array>  $currentOptions
     */
    protected function detectOptionChanges(array $baselineOptions, array $currentOptions, array &$changes): void
    {
        $baseByName = collect($baselineOptions)->keyBy('name')->toArray();
        $currByName = collect($currentOptions)->keyBy('name')->toArray();

        // Removed options
        foreach ($baseByName as $name => $baseOpt) {
            if (! isset($currByName[$name])) {
                $changes[] = [
                    'category' => 'option',
                    'action' => 'removed',
                    'label' => $name,
                    'details' => "Option removed: {$name} (\$".number_format((float) $baseOpt['calculated_price'], 2).')',
                ];
            }
        }

        // Added and modified options
        foreach ($currByName as $name => $currOpt) {
            if (! isset($baseByName[$name])) {
                $changes[] = [
                    'category' => 'option',
                    'action' => 'added',
                    'label' => $name,
                    'details' => "New option added: {$name} (\$".number_format((float) $currOpt['calculated_price'], 2).')',
                ];

                continue;
            }

            $baseOpt = $baseByName[$name];

            // Price changed
            if (round((float) ($baseOpt['calculated_price'] ?? 0), 2) !== round((float) ($currOpt['calculated_price'] ?? 0), 2)) {
                $changes[] = [
                    'category' => 'option',
                    'action' => 'modified',
                    'label' => $name,
                    'field' => 'Price',
                    'old_value' => '$'.number_format((float) $baseOpt['calculated_price'], 2),
                    'new_value' => '$'.number_format((float) $currOpt['calculated_price'], 2),
                ];
            }

            // Selection changed
            if ((bool) ($baseOpt['is_selected'] ?? false) !== (bool) ($currOpt['is_selected'] ?? false)) {
                $changes[] = [
                    'category' => 'option',
                    'action' => 'modified',
                    'label' => $name,
                    'field' => 'Selected',
                    'old_value' => ($baseOpt['is_selected'] ?? false) ? 'Yes' : 'No',
                    'new_value' => ($currOpt['is_selected'] ?? false) ? 'Yes' : 'No',
                ];
            }
        }
    }

    /**
     * Detect changes in totals.
     */
    protected function detectTotalChanges(array $baselineTotals, array $currentTotals, array &$changes): void
    {
        $fields = [
            'subtotal' => 'Subtotal',
            'tax_amount' => 'Tax',
            'discount_amount' => 'Discount',
            'total' => 'Total',
        ];

        foreach ($fields as $key => $label) {
            $oldVal = round((float) ($baselineTotals[$key] ?? 0), 2);
            $newVal = round((float) ($currentTotals[$key] ?? 0), 2);

            if ($oldVal !== $newVal) {
                $changes[] = [
                    'category' => 'total',
                    'action' => 'modified',
                    'label' => 'Estimate '.$label,
                    'field' => $label,
                    'old_value' => '$'.number_format($oldVal, 2),
                    'new_value' => '$'.number_format($newVal, 2),
                ];
            }
        }
    }
}
