<?php

use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\EstimateCostItem;
use App\Models\EstimateItem;
use App\Models\ProductionRate;
use App\Models\Property;
use App\Services\EstimateAiService;

beforeEach(function () {
    $this->service = new EstimateAiService;
    $this->estimate = Estimate::factory()->create([
        'status' => 'draft',
        'project_type' => 'interior',
    ]);

    CompanySetting::firstOrCreate([], [
        'company_name' => 'Test Painting Co',
        'interior_hourly_wage' => 21.00,
        'exterior_hourly_wage' => 27.00,
        'payroll_burden_multiplier' => 1.35,
        'interior_margin_percent' => 50.00,
        'exterior_margin_percent' => 41.00,
        'interior_cleanup_hours_ratio' => 16.00,
        'exterior_cleanup_hours_ratio' => 20.00,
        'interior_sundries_cost_per_hour' => 3.30,
        'exterior_sundries_cost_per_hour' => 1.00,
    ]);

    CompanySetting::clearInstanceCache();

    $this->area = EstimateArea::factory()->forEstimate($this->estimate)->create([
        'name' => 'Living Room',
        'scope_type' => 'interior',
    ]);

    $this->surface = EstimateAreaSurface::factory()->forArea($this->area)->create([
        'name' => 'Wall Painting',
        'surface_type' => 'wall',
    ]);
});

// =============================================================================
// addCostItem — area target
// =============================================================================

it('adds a cost item to an area', function () {
    $result = $this->service->addCostItem(
        estimate: $this->estimate,
        targetType: 'area',
        targetId: $this->area->id,
        name: 'Baseboard Material',
        costType: 'material',
        quantity: 10,
        unit: 'lnft',
        unitCost: 2.50,
        unitPrice: 5.00,
    );

    expect($result['success'])->toBeTrue()
        ->and($result['cost_item']['name'])->toBe('Baseboard Material')
        ->and($result['cost_item']['cost_type'])->toBe('material')
        ->and((float) $result['cost_item']['quantity'])->toBe(10.0)
        ->and((float) $result['cost_item']['unit_cost'])->toBe(2.50)
        ->and((float) $result['cost_item']['unit_price'])->toBe(5.00);

    expect(EstimateCostItem::where('costable_type', EstimateArea::class)
        ->where('costable_id', $this->area->id)
        ->where('name', 'Baseboard Material')
        ->exists()
    )->toBeTrue();
});

// =============================================================================
// addCostItem — surface target
// =============================================================================

it('adds a cost item to a surface', function () {
    $result = $this->service->addCostItem(
        estimate: $this->estimate,
        targetType: 'surface',
        targetId: $this->surface->id,
        name: 'Sprayer Rental',
        costType: 'rental',
        quantity: 1,
        unit: 'day',
        unitCost: 75.00,
        unitPrice: 150.00,
        tier: 'best',
        notes: 'Pro sprayer for finish coat',
    );

    expect($result['success'])->toBeTrue()
        ->and($result['cost_item']['name'])->toBe('Sprayer Rental')
        ->and($result['cost_item']['cost_type'])->toBe('rental')
        ->and($result['cost_item']['tier'])->toBe('best')
        ->and($result['cost_item']['notes'])->toBe('Pro sprayer for finish coat');

    expect(EstimateCostItem::where('costable_type', EstimateAreaSurface::class)
        ->where('costable_id', $this->surface->id)
        ->where('name', 'Sprayer Rental')
        ->exists()
    )->toBeTrue();
});

// =============================================================================
// addCostItem — invalid target type
// =============================================================================

it('throws when adding cost item with invalid target type', function () {
    $this->service->addCostItem(
        estimate: $this->estimate,
        targetType: 'estimate',
        targetId: $this->estimate->id,
        name: 'Something',
    );
})->throws(\InvalidArgumentException::class);

// =============================================================================
// addCostItem — auto-calculates totals
// =============================================================================

it('auto-calculates total_cost and total_price on creation', function () {
    $result = $this->service->addCostItem(
        estimate: $this->estimate,
        targetType: 'area',
        targetId: $this->area->id,
        name: 'Paint',
        quantity: 3,
        unit: 'gallon',
        unitCost: 40.00,
        unitPrice: 80.00,
    );

    expect((float) $result['cost_item']['total_cost'])->toBe(120.0)
        ->and((float) $result['cost_item']['total_price'])->toBe(240.0);
});

// =============================================================================
// removeCostItem — happy path
// =============================================================================

it('removes a cost item by id', function () {
    $item = $this->area->costItems()->create([
        'name' => 'Test Item',
        'cost_type' => 'material',
        'quantity' => 1,
        'unit' => 'each',
        'unit_cost' => 10.00,
        'unit_price' => 20.00,
    ]);

    $result = $this->service->removeCostItem(estimate: $this->estimate, costItemId: $item->id);

    expect($result['success'])->toBeTrue();
    expect(EstimateCostItem::find($item->id))->toBeNull();
});

// =============================================================================
// removeCostItem — not found
// =============================================================================

it('throws when removing a non-existent cost item', function () {
    $this->service->removeCostItem(estimate: $this->estimate, costItemId: 99999);
})->throws(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

// =============================================================================
// listCostItems — area target
// =============================================================================

it('lists cost items on an area', function () {
    $this->area->costItems()->create([
        'name' => 'Item A',
        'cost_type' => 'material',
        'quantity' => 1,
        'unit' => 'each',
        'unit_cost' => 10.00,
        'unit_price' => 20.00,
    ]);

    $this->area->costItems()->create([
        'name' => 'Item B',
        'cost_type' => 'service',
        'quantity' => 2,
        'unit' => 'hour',
        'unit_cost' => 50.00,
        'unit_price' => 100.00,
    ]);

    $result = $this->service->listCostItems(estimate: $this->estimate, targetType: 'area', targetId: $this->area->id);

    expect($result['cost_items'])->toHaveCount(2)
        ->and(collect($result['cost_items'])->pluck('name')->toArray())->toContain('Item A', 'Item B');
});

// =============================================================================
// listCostItems — surface target
// =============================================================================

it('lists cost items on a surface', function () {
    $this->surface->costItems()->create([
        'name' => 'Surface Item',
        'cost_type' => 'rental',
        'quantity' => 1,
        'unit' => 'day',
        'unit_cost' => 75.00,
        'unit_price' => 150.00,
    ]);

    $result = $this->service->listCostItems(estimate: $this->estimate, targetType: 'surface', targetId: $this->surface->id);

    expect($result['cost_items'])->toHaveCount(1)
        ->and($result['cost_items'][0]['name'])->toBe('Surface Item');
});

// =============================================================================
// listCostItems — empty list
// =============================================================================

it('returns empty cost_items array when none exist', function () {
    $result = $this->service->listCostItems(estimate: $this->estimate, targetType: 'area', targetId: $this->area->id);

    expect($result['cost_items'])->toBeArray()->toBeEmpty();
});

// =============================================================================
// listCostItems — invalid target type
// =============================================================================

it('throws when listing cost items with invalid target type', function () {
    $this->service->listCostItems(estimate: $this->estimate, targetType: 'estimate', targetId: $this->estimate->id);
})->throws(\InvalidArgumentException::class);

// =============================================================================
// updateAiContext
// =============================================================================

it('saves ai context to the estimate', function () {
    $result = $this->service->updateAiContext(
        estimate: $this->estimate,
        context: 'Customer prefers Benjamin Moore. Has a dog.',
    );

    expect($result['success'])->toBeTrue();

    $fresh = $this->estimate->fresh();
    expect($fresh->ai_context)->toBe('Customer prefers Benjamin Moore. Has a dog.');
});

it('overwrites existing ai context', function () {
    $this->estimate->update(['ai_context' => 'Old notes']);

    $this->service->updateAiContext(
        estimate: $this->estimate->fresh(),
        context: 'New notes',
    );

    expect($this->estimate->fresh()->ai_context)->toBe('New notes');
});

// =============================================================================
// updateAiSummary
// =============================================================================

it('saves ai summary to the estimate', function () {
    $result = $this->service->updateAiSummary(
        estimate: $this->estimate,
        summary: '## Scope of Work\n\nPaint 3 bedrooms.',
    );

    expect($result['success'])->toBeTrue();

    $fresh = $this->estimate->fresh();
    expect($fresh->ai_summary)->toBe('## Scope of Work\n\nPaint 3 bedrooms.');
});

it('overwrites existing ai summary', function () {
    $this->estimate->update(['ai_summary' => 'Old summary']);

    $this->service->updateAiSummary(
        estimate: $this->estimate->fresh(),
        summary: 'Updated summary',
    );

    expect($this->estimate->fresh()->ai_summary)->toBe('Updated summary');
});

// =============================================================================
// getProjectContext — with full customer/property/deal
// =============================================================================

it('returns customer and deal context when deal exists', function () {
    $customer = Customer::factory()->create([
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'jane@example.com',
        'phone' => '303-555-1234',
    ]);

    $deal = Deal::factory()->forCustomer($customer)->create([
        'stage' => 'quoted',
        'title' => 'Jane Doe House',
    ]);

    $estimate = Estimate::factory()->forDeal($deal)->create();

    $result = $this->service->getProjectContext($estimate);

    expect($result['customer'])->not->toBeNull()
        ->and($result['customer']['first_name'])->toBe('Jane')
        ->and($result['customer']['last_name'])->toBe('Doe')
        ->and($result['customer']['email'])->toBe('jane@example.com');

    expect($result['deal'])->not->toBeNull()
        ->and($result['deal']['id'])->toBe($deal->id)
        ->and($result['deal']['stage'])->toBe('quoted')
        ->and($result['deal']['title'])->toBe('Jane Doe House');
});

// =============================================================================
// getProjectContext — with property
// =============================================================================

it('returns property context when deal has a property', function () {
    $customer = Customer::factory()->create();

    $property = Property::factory()->create([
        'customer_id' => $customer->id,
        'address' => '123 Main St',
        'city' => 'Denver',
        'state' => 'CO',
        'zip' => '80201',
        'square_footage' => 2000,
        'year_built' => 1995,
    ]);

    $deal = Deal::factory()->forCustomer($customer)->forProperty($property)->create([
        'stage' => 'lead',
    ]);

    $estimate = Estimate::factory()->forDeal($deal)->create();

    $result = $this->service->getProjectContext($estimate);

    expect($result['property'])->not->toBeNull()
        ->and($result['property']['address'])->toBe('123 Main St')
        ->and($result['property']['city'])->toBe('Denver')
        ->and($result['property']['square_footage'])->toBe(2000)
        ->and($result['property']['year_built'])->toBe(1995);
});

// =============================================================================
// getProjectContext — deal with no customer (null returns)
// =============================================================================

it('returns null customer when deal has no customer', function () {
    // Use the existing estimate whose deal was created by the factory without
    // a property — manually unset the deal relationship to simulate no deal
    // by loading a fresh estimate and checking the deal relationship path.
    // Since deal_id is NOT NULL, we test null customer via a deal with no customer.
    $deal = Deal::factory()->create(['customer_id' => null, 'stage' => 'lead']);
    $estimate = Estimate::factory()->forDeal($deal)->create();

    $result = $this->service->getProjectContext($estimate);

    expect($result['customer'])->toBeNull()
        ->and($result['property'])->toBeNull()
        ->and($result['deal'])->not->toBeNull();
});

// =============================================================================
// getProjectContext — deal without property
// =============================================================================

it('returns null property when deal has no property', function () {
    $customer = Customer::factory()->create();
    $deal = Deal::factory()->forCustomer($customer)->create([
        'stage' => 'lead',
    ]);
    $estimate = Estimate::factory()->forDeal($deal)->create();

    $result = $this->service->getProjectContext($estimate);

    expect($result['customer'])->not->toBeNull()
        ->and($result['property'])->toBeNull()
        ->and($result['deal'])->not->toBeNull();
});

// =============================================================================
// Ownership validation — addCostItem rejects foreign area
// =============================================================================

it('throws when adding cost item to an area from a different estimate', function () {
    $otherEstimate = Estimate::factory()->create(['status' => 'draft', 'project_type' => 'interior']);
    $otherArea = EstimateArea::factory()->forEstimate($otherEstimate)->create(['name' => 'Other Room']);

    $this->service->addCostItem(
        estimate: $this->estimate,
        targetType: 'area',
        targetId: $otherArea->id,
        name: 'Intruder Item',
    );
})->throws(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

// =============================================================================
// Ownership validation — addCostItem rejects foreign surface
// =============================================================================

it('throws when adding cost item to a surface from a different estimate', function () {
    $otherEstimate = Estimate::factory()->create(['status' => 'draft', 'project_type' => 'interior']);
    $otherArea = EstimateArea::factory()->forEstimate($otherEstimate)->create(['name' => 'Other Room']);
    $otherSurface = EstimateAreaSurface::factory()->forArea($otherArea)->create(['name' => 'Wall']);

    $this->service->addCostItem(
        estimate: $this->estimate,
        targetType: 'surface',
        targetId: $otherSurface->id,
        name: 'Intruder Item',
    );
})->throws(\InvalidArgumentException::class);

// =============================================================================
// Ownership validation — listCostItems rejects foreign area
// =============================================================================

it('throws when listing cost items on an area from a different estimate', function () {
    $otherEstimate = Estimate::factory()->create(['status' => 'draft', 'project_type' => 'interior']);
    $otherArea = EstimateArea::factory()->forEstimate($otherEstimate)->create(['name' => 'Other Room']);

    $this->service->listCostItems(
        estimate: $this->estimate,
        targetType: 'area',
        targetId: $otherArea->id,
    );
})->throws(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

// =============================================================================
// Ownership validation — listCostItems rejects foreign surface
// =============================================================================

it('throws when listing cost items on a surface from a different estimate', function () {
    $otherEstimate = Estimate::factory()->create(['status' => 'draft', 'project_type' => 'interior']);
    $otherArea = EstimateArea::factory()->forEstimate($otherEstimate)->create(['name' => 'Other Room']);
    $otherSurface = EstimateAreaSurface::factory()->forArea($otherArea)->create(['name' => 'Wall']);

    $this->service->listCostItems(
        estimate: $this->estimate,
        targetType: 'surface',
        targetId: $otherSurface->id,
    );
})->throws(\InvalidArgumentException::class);

// =============================================================================
// Ownership validation — removeCostItem rejects cost item from a foreign area
// =============================================================================

it('throws when removing a cost item that belongs to a different estimate area', function () {
    $otherEstimate = Estimate::factory()->create(['status' => 'draft', 'project_type' => 'interior']);
    $otherArea = EstimateArea::factory()->forEstimate($otherEstimate)->create(['name' => 'Other Room']);
    $otherItem = $otherArea->costItems()->create([
        'name' => 'Foreign Item',
        'cost_type' => 'material',
        'quantity' => 1,
        'unit' => 'each',
        'unit_cost' => 10.00,
        'unit_price' => 20.00,
    ]);

    $this->service->removeCostItem(estimate: $this->estimate, costItemId: $otherItem->id);
})->throws(\InvalidArgumentException::class);

// =============================================================================
// Ownership validation — removeCostItem rejects cost item from a foreign surface
// =============================================================================

it('throws when removing a cost item that belongs to a different estimate surface', function () {
    $otherEstimate = Estimate::factory()->create(['status' => 'draft', 'project_type' => 'interior']);
    $otherArea = EstimateArea::factory()->forEstimate($otherEstimate)->create(['name' => 'Other Room']);
    $otherSurface = EstimateAreaSurface::factory()->forArea($otherArea)->create(['name' => 'Wall']);
    $otherItem = $otherSurface->costItems()->create([
        'name' => 'Foreign Surface Item',
        'cost_type' => 'rental',
        'quantity' => 1,
        'unit' => 'day',
        'unit_cost' => 75.00,
        'unit_price' => 150.00,
    ]);

    $this->service->removeCostItem(estimate: $this->estimate, costItemId: $otherItem->id);
})->throws(\InvalidArgumentException::class);

// =============================================================================
// createProductionRate — happy path
// =============================================================================

it('creates a production rate and persists it to the database', function () {
    $result = $this->service->createProductionRate(
        name: 'Sunroom Walls - Brush/Roll',
        scope: 'interior',
        surfaceCategory: 'walls',
        productionRate: 180.0,
        rateType: 'sqft_hr',
        unitType: 'sqft',
        defaultCoats: 2,
        description: 'Slower rate for sunroom glass transitions',
    );

    expect($result['success'])->toBeTrue()
        ->and($result['production_rate']['name'])->toBe('Sunroom Walls - Brush/Roll')
        ->and($result['production_rate']['surface_category'])->toBe('walls')
        ->and($result['production_rate']['scope'])->toBe('interior')
        ->and((float) $result['production_rate']['production_rate'])->toBe(180.0);

    expect(ProductionRate::where('name', 'Sunroom Walls - Brush/Roll')->exists())->toBeTrue();
});

// =============================================================================
// createProductionRate — invalid surface_category
// =============================================================================

it('throws when creating a production rate with an invalid surface_category', function () {
    $this->service->createProductionRate(
        name: 'Bad Rate',
        scope: 'interior',
        surfaceCategory: 'siding',
        productionRate: 200.0,
    );
})->throws(\InvalidArgumentException::class);

// =============================================================================
// createProductionRate — invalid rate_type
// =============================================================================

it('throws when creating a production rate with an invalid rate_type', function () {
    $this->service->createProductionRate(
        name: 'Bad Rate Type',
        scope: 'interior',
        surfaceCategory: 'walls',
        productionRate: 200.0,
        rateType: 'per_room',
    );
})->throws(\InvalidArgumentException::class);

// =============================================================================
// createEstimateItem — happy path
// =============================================================================

it('creates an estimate item that belongs to the estimate', function () {
    $result = $this->service->createEstimateItem(
        estimate: $this->estimate,
        description: 'Sprayer rental (2 days)',
        quantity: 2.0,
        unit: 'day',
        unitPrice: 150.00,
        cost: 75.00,
    );

    expect($result['success'])->toBeTrue()
        ->and($result['item']['description'])->toBe('Sprayer rental (2 days)')
        ->and((float) $result['item']['quantity'])->toBe(2.0)
        ->and($result['item']['unit'])->toBe('day')
        ->and((float) $result['item']['unit_price'])->toBe(150.0);

    expect(EstimateItem::where('estimate_id', $this->estimate->id)
        ->where('description', 'Sprayer rental (2 days)')
        ->exists()
    )->toBeTrue();
});
