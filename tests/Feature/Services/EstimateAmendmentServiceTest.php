<?php

use App\Models\CompanySetting;
use App\Models\Estimate;
use App\Models\EstimateActivity;
use App\Models\EstimateAmendment;
use App\Models\EstimateArea;
use App\Models\EstimateAreaSurface;
use App\Models\User;
use App\Notifications\EstimateAmended;
use App\Services\EstimateAmendmentService;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->service = new EstimateAmendmentService;
    CompanySetting::create(['company_name' => 'Test Company']);
});

it('captures snapshot with areas and surfaces', function () {
    $estimate = Estimate::factory()->sent()->create();
    $area = EstimateArea::factory()->forEstimate($estimate)->create(['name' => 'Living Room']);
    EstimateAreaSurface::factory()->forArea($area)->wall()->create(['coats' => 2, 'quantity' => 400]);
    EstimateAreaSurface::factory()->forArea($area)->ceiling()->create(['coats' => 1, 'quantity' => 150]);

    $snapshot = $this->service->captureSnapshot($estimate);

    expect($snapshot)->toHaveKeys(['areas', 'packages', 'options', 'totals'])
        ->and($snapshot['areas'])->toHaveCount(1)
        ->and($snapshot['areas'][0]['name'])->toBe('Living Room')
        ->and($snapshot['areas'][0]['surfaces'])->toHaveCount(2);
});

it('captures snapshot totals correctly', function () {
    $estimate = Estimate::factory()->sent()->create([
        'subtotal' => 5000,
        'tax_amount' => 400,
        'discount_amount' => 100,
        'total' => 5300,
        'deposit_percent' => 50,
    ]);

    $snapshot = $this->service->captureSnapshot($estimate);

    expect($snapshot['totals']['subtotal'])->toBe(5000.0)
        ->and($snapshot['totals']['tax_amount'])->toBe(400.0)
        ->and($snapshot['totals']['discount_amount'])->toBe(100.0)
        ->and($snapshot['totals']['total'])->toBe(5300.0)
        ->and($snapshot['totals']['deposit_percent'])->toBe(50.0);
});

it('detects added areas', function () {
    $baseline = [
        'areas' => [['name' => 'Living Room', 'surfaces' => [], 'scope_type' => 'interior', 'length' => 15, 'width' => 12, 'height' => 9, 'wall_sqft' => 486, 'ceiling_sqft' => 180, 'substrate_id' => null]],
        'packages' => [],
        'options' => [],
        'totals' => ['subtotal' => 1000, 'tax_amount' => 0, 'discount_amount' => 0, 'total' => 1000],
    ];

    $current = [
        'areas' => [
            ['name' => 'Living Room', 'surfaces' => [], 'scope_type' => 'interior', 'length' => 15, 'width' => 12, 'height' => 9, 'wall_sqft' => 486, 'ceiling_sqft' => 180, 'substrate_id' => null],
            ['name' => 'Kitchen', 'surfaces' => [], 'scope_type' => 'interior', 'length' => 10, 'width' => 10, 'height' => 9, 'wall_sqft' => 360, 'ceiling_sqft' => 100, 'substrate_id' => null],
        ],
        'packages' => [],
        'options' => [],
        'totals' => ['subtotal' => 1800, 'tax_amount' => 0, 'discount_amount' => 0, 'total' => 1800],
    ];

    $changes = $this->service->detectChanges($baseline, $current);

    $areaChanges = collect($changes)->where('category', 'area');
    expect($areaChanges->where('action', 'added')->where('label', 'Kitchen'))->toHaveCount(1);
});

it('detects removed areas', function () {
    $baseline = [
        'areas' => [
            ['name' => 'Living Room', 'surfaces' => [], 'scope_type' => 'interior', 'length' => 15, 'width' => 12, 'height' => 9, 'wall_sqft' => 486, 'ceiling_sqft' => 180, 'substrate_id' => null],
            ['name' => 'Kitchen', 'surfaces' => [], 'scope_type' => 'interior', 'length' => 10, 'width' => 10, 'height' => 9, 'wall_sqft' => 360, 'ceiling_sqft' => 100, 'substrate_id' => null],
        ],
        'packages' => [],
        'options' => [],
        'totals' => ['subtotal' => 1800, 'tax_amount' => 0, 'discount_amount' => 0, 'total' => 1800],
    ];

    $current = [
        'areas' => [
            ['name' => 'Living Room', 'surfaces' => [], 'scope_type' => 'interior', 'length' => 15, 'width' => 12, 'height' => 9, 'wall_sqft' => 486, 'ceiling_sqft' => 180, 'substrate_id' => null],
        ],
        'packages' => [],
        'options' => [],
        'totals' => ['subtotal' => 1000, 'tax_amount' => 0, 'discount_amount' => 0, 'total' => 1000],
    ];

    $changes = $this->service->detectChanges($baseline, $current);

    $areaChanges = collect($changes)->where('category', 'area');
    expect($areaChanges->where('action', 'removed')->where('label', 'Kitchen'))->toHaveCount(1);
});

it('detects surface coat changes', function () {
    $baseline = [
        'areas' => [[
            'name' => 'Living Room',
            'scope_type' => 'interior',
            'length' => 15, 'width' => 12, 'height' => 9,
            'wall_sqft' => 486, 'ceiling_sqft' => 180, 'substrate_id' => null,
            'surfaces' => [
                ['name' => 'Wall Painting', 'coats' => 2, 'quantity' => 400, 'unit' => 'sqft', 'paint_product_id' => null, 'production_rate_value' => 200, 'is_included' => true, 'labor_cost' => 100, 'material_cost' => 50, 'total' => 150],
            ],
        ]],
        'packages' => [],
        'options' => [],
        'totals' => ['subtotal' => 1000, 'tax_amount' => 0, 'discount_amount' => 0, 'total' => 1000],
    ];

    $current = $baseline;
    $current['areas'][0]['surfaces'][0]['coats'] = 3;

    $changes = $this->service->detectChanges($baseline, $current);

    $surfaceChanges = collect($changes)->where('category', 'surface');
    expect($surfaceChanges)->toHaveCount(1)
        ->and($surfaceChanges->first()['field'])->toBe('Coats')
        ->and($surfaceChanges->first()['old_value'])->toBe('2')
        ->and($surfaceChanges->first()['new_value'])->toBe('3');
});

it('detects surface quantity changes', function () {
    $baseline = [
        'areas' => [[
            'name' => 'Living Room',
            'scope_type' => 'interior',
            'length' => 15, 'width' => 12, 'height' => 9,
            'wall_sqft' => 486, 'ceiling_sqft' => 180, 'substrate_id' => null,
            'surfaces' => [
                ['name' => 'Wall Painting', 'coats' => 2, 'quantity' => 400.00, 'unit' => 'sqft', 'paint_product_id' => null, 'production_rate_value' => 200, 'is_included' => true, 'labor_cost' => 100, 'material_cost' => 50, 'total' => 150],
            ],
        ]],
        'packages' => [],
        'options' => [],
        'totals' => ['subtotal' => 1000, 'tax_amount' => 0, 'discount_amount' => 0, 'total' => 1000],
    ];

    $current = $baseline;
    $current['areas'][0]['surfaces'][0]['quantity'] = 500.00;

    $changes = $this->service->detectChanges($baseline, $current);

    $surfaceChanges = collect($changes)->where('category', 'surface')->where('field', 'Quantity');
    expect($surfaceChanges)->toHaveCount(1);
});

it('detects total changes', function () {
    $baseline = [
        'areas' => [],
        'packages' => [],
        'options' => [],
        'totals' => ['subtotal' => 5000, 'tax_amount' => 400, 'discount_amount' => 0, 'total' => 5400],
    ];

    $current = [
        'areas' => [],
        'packages' => [],
        'options' => [],
        'totals' => ['subtotal' => 5500, 'tax_amount' => 440, 'discount_amount' => 0, 'total' => 5940],
    ];

    $changes = $this->service->detectChanges($baseline, $current);

    $totalChanges = collect($changes)->where('category', 'total');
    expect($totalChanges)->toHaveCount(3) // subtotal, tax, total changed
        ->and($totalChanges->where('field', 'Total'))->toHaveCount(1);
});

it('detects option changes', function () {
    $baseline = [
        'areas' => [],
        'packages' => [],
        'options' => [
            ['name' => 'Premium Paint', 'pricing_type' => 'fixed', 'calculated_price' => 250.00, 'is_selected' => true],
        ],
        'totals' => ['subtotal' => 1000, 'tax_amount' => 0, 'discount_amount' => 0, 'total' => 1000],
    ];

    $current = [
        'areas' => [],
        'packages' => [],
        'options' => [],
        'totals' => ['subtotal' => 750, 'tax_amount' => 0, 'discount_amount' => 0, 'total' => 750],
    ];

    $changes = $this->service->detectChanges($baseline, $current);

    $optionChanges = collect($changes)->where('category', 'option');
    expect($optionChanges->where('action', 'removed'))->toHaveCount(1);
});

it('returns null when no changes detected', function () {
    Notification::fake();

    $estimate = Estimate::factory()->sent()->create();
    $area = EstimateArea::factory()->forEstimate($estimate)->create(['name' => 'Living Room']);
    EstimateAreaSurface::factory()->forArea($area)->wall()->create();

    // Set snapshot to current state (no changes)
    $estimate->sent_snapshot = $this->service->captureSnapshot($estimate);
    $estimate->save();

    $user = User::factory()->internal()->create();
    $amendment = $this->service->createAmendment($estimate, $user);

    expect($amendment)->toBeNull();
    Notification::assertNothingSent();
});

it('creates amendment with correct sequential number', function () {
    Notification::fake();

    $estimate = Estimate::factory()->sent()->create(['total' => 5000]);
    $area = EstimateArea::factory()->forEstimate($estimate)->create(['name' => 'Living Room']);
    EstimateAreaSurface::factory()->forArea($area)->wall()->create();

    // Set an initial snapshot with no areas
    $estimate->sent_snapshot = [
        'areas' => [],
        'packages' => [],
        'options' => [],
        'totals' => ['subtotal' => 3000, 'tax_amount' => 0, 'discount_amount' => 0, 'total' => 3000],
    ];
    $estimate->save();

    $user = User::factory()->internal()->create();

    $amendment1 = $this->service->createAmendment($estimate, $user);
    expect($amendment1)->not->toBeNull()
        ->and($amendment1->amendment_number)->toBe(1);

    // Change something else (update snapshot to current, then modify again)
    $estimate->sent_snapshot = $this->service->captureSnapshot($estimate);
    $estimate->total = 6000;
    $estimate->subtotal = 6000;
    $estimate->save();

    $amendment2 = $this->service->createAmendment($estimate, $user);
    expect($amendment2)->not->toBeNull()
        ->and($amendment2->amendment_number)->toBe(2);
});

it('sends notification on amendment creation', function () {
    Notification::fake();

    $estimate = Estimate::factory()->sent()->create(['total' => 5000]);
    $customer = $estimate->deal->customer;

    $estimate->sent_snapshot = [
        'areas' => [],
        'packages' => [],
        'options' => [],
        'totals' => ['subtotal' => 3000, 'tax_amount' => 0, 'discount_amount' => 0, 'total' => 3000],
    ];
    $estimate->save();

    $user = User::factory()->internal()->create();
    $this->service->createAmendment($estimate, $user);

    Notification::assertSentTo($customer, EstimateAmended::class);
});

it('logs activity on amendment creation', function () {
    Notification::fake();

    $estimate = Estimate::factory()->sent()->create(['total' => 5000]);
    $estimate->sent_snapshot = [
        'areas' => [],
        'packages' => [],
        'options' => [],
        'totals' => ['subtotal' => 3000, 'tax_amount' => 0, 'discount_amount' => 0, 'total' => 3000],
    ];
    $estimate->save();

    $user = User::factory()->internal()->create();
    $this->actingAs($user);

    $this->service->createAmendment($estimate, $user);

    $activity = EstimateActivity::where('estimate_id', $estimate->id)
        ->where('activity_type', 'amendment_created')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->metadata['amendment_number'])->toBe(1);
});

it('records acknowledgment correctly', function () {
    $estimate = Estimate::factory()->sent()->create();
    $amendment = EstimateAmendment::factory()->forEstimate($estimate)->pending()->create();

    $this->service->acknowledgeAmendment($amendment, 'JD', '192.168.1.1');

    $amendment->refresh();
    expect($amendment->isAcknowledged())->toBeTrue()
        ->and($amendment->customer_initials)->toBe('JD')
        ->and($amendment->customer_ip_address)->toBe('192.168.1.1')
        ->and($amendment->customer_acknowledged_at)->not->toBeNull();
});

it('updates snapshot after all amendments acknowledged', function () {
    $estimate = Estimate::factory()->sent()->create();
    $area = EstimateArea::factory()->forEstimate($estimate)->create(['name' => 'Kitchen']);
    EstimateAreaSurface::factory()->forArea($area)->wall()->create();

    $estimate->sent_snapshot = [
        'areas' => [],
        'packages' => [],
        'options' => [],
        'totals' => ['subtotal' => 1000, 'tax_amount' => 0, 'discount_amount' => 0, 'total' => 1000],
    ];
    $estimate->save();

    $amendment = EstimateAmendment::factory()->forEstimate($estimate)->pending()->create();

    // Before acknowledgment - snapshot is the old one
    expect($estimate->fresh()->sent_snapshot['areas'])->toBeEmpty();

    $this->service->acknowledgeAmendment($amendment, 'JD', '127.0.0.1');

    // After all acknowledged - snapshot updated to current state
    $estimate->refresh();
    expect($estimate->sent_snapshot['areas'])->toHaveCount(1)
        ->and($estimate->sent_snapshot['areas'][0]['name'])->toBe('Kitchen');
});

it('logs activity on acknowledgment', function () {
    $estimate = Estimate::factory()->sent()->create();
    $amendment = EstimateAmendment::factory()->forEstimate($estimate)->pending()->create([
        'amendment_number' => 1,
    ]);

    $this->service->acknowledgeAmendment($amendment, 'AB', '10.0.0.1');

    $activity = EstimateActivity::where('estimate_id', $estimate->id)
        ->where('activity_type', 'amendment_acknowledged')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->metadata['customer_initials'])->toBe('AB');
});
