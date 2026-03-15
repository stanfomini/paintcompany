<?php

use App\Models\Customer;
use App\Models\Deal;
use App\Models\Estimate;
use App\Models\EstimateTemplate;
use App\Models\ProjectPreview;
use App\Models\ProjectPreviewPhoto;
use App\Services\ProjectPreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new ProjectPreviewService;
});

// =========================================================================
// createForPhone
// =========================================================================

it('creates a preview for a phone number', function () {
    $preview = $this->service->createForPhone('3035551234');

    expect($preview)->toBeInstanceOf(ProjectPreview::class)
        ->and($preview->phone)->toBe('3035551234')
        ->and($preview->status)->toBe('active')
        ->and($preview->uuid)->not->toBeEmpty()
        ->and($preview->public_token)->not->toBeEmpty();
});

it('links to existing customer when phone matches', function () {
    $customer = Customer::factory()->create(['phone' => '3035551234']);

    $preview = $this->service->createForPhone('3035551234');

    expect($preview->customer_id)->toBe($customer->id);
});

it('normalizes phone number before lookup', function () {
    $customer = Customer::factory()->create(['phone' => '3035551234']);

    $preview = $this->service->createForPhone('(303) 555-1234');

    expect($preview->phone)->toBe('3035551234');
});

it('uses provided customer_id over phone lookup', function () {
    $customer = Customer::factory()->create();

    $preview = $this->service->createForPhone('9999999999', $customer->id);

    expect($preview->customer_id)->toBe($customer->id);
});

it('passes lead_source_id through', function () {
    $leadSource = \App\Models\LeadSource::factory()->create();

    $preview = $this->service->createForPhone('3035551234', null, $leadSource->id);

    expect($preview->lead_source_id)->toBe($leadSource->id);
});

// =========================================================================
// createForCustomer
// =========================================================================

it('creates a preview from a customer', function () {
    $customer = Customer::factory()->create([
        'first_name' => 'John',
        'last_name' => 'Doe',
        'email' => 'john@example.com',
        'phone' => '3035551234',
    ]);

    $preview = $this->service->createForCustomer($customer);

    expect($preview->customer_id)->toBe($customer->id)
        ->and($preview->first_name)->toBe('John')
        ->and($preview->last_name)->toBe('Doe')
        ->and($preview->email)->toBe('john@example.com')
        ->and($preview->phone)->toBe('3035551234')
        ->and($preview->status)->toBe('active');
});

it('uses customer lead_source_id as fallback', function () {
    $leadSource = \App\Models\LeadSource::factory()->create();
    $customer = Customer::factory()->create(['lead_source_id' => $leadSource->id]);

    $preview = $this->service->createForCustomer($customer);

    expect($preview->lead_source_id)->toBe($leadSource->id);
});

it('overrides lead_source_id when provided', function () {
    $leadSource1 = \App\Models\LeadSource::factory()->create();
    $leadSource2 = \App\Models\LeadSource::factory()->create();
    $customer = Customer::factory()->create(['lead_source_id' => $leadSource1->id]);

    $preview = $this->service->createForCustomer($customer, $leadSource2->id);

    expect($preview->lead_source_id)->toBe($leadSource2->id);
});

// =========================================================================
// findOrCreateByPhone
// =========================================================================

it('returns existing active preview for phone', function () {
    $existing = ProjectPreview::factory()->create([
        'phone' => '3035551234',
        'status' => 'active',
    ]);

    $result = $this->service->findOrCreateByPhone('3035551234');

    expect($result->id)->toBe($existing->id);
});

it('creates new preview when none exists for phone', function () {
    $result = $this->service->findOrCreateByPhone('3035559999');

    expect($result)->toBeInstanceOf(ProjectPreview::class)
        ->and($result->phone)->toBe('3035559999')
        ->and($result->status)->toBe('active');
});

it('creates new preview when existing one is converted', function () {
    ProjectPreview::factory()->create([
        'phone' => '3035551234',
        'status' => 'converted',
    ]);

    $result = $this->service->findOrCreateByPhone('3035551234');

    // Should create new, not return the converted one
    expect($result->status)->toBe('active');
    expect(ProjectPreview::where('phone', '3035551234')->count())->toBe(2);
});

// =========================================================================
// storePhoto
// =========================================================================

it('stores a photo for a preview', function () {
    $preview = ProjectPreview::factory()->create();

    $photo = $this->service->storePhoto($preview, 'previews/photo1.jpg');

    expect($photo)->toBeInstanceOf(ProjectPreviewPhoto::class)
        ->and($photo->project_preview_id)->toBe($preview->id)
        ->and($photo->original_path)->toBe('previews/photo1.jpg')
        ->and($photo->disk)->toBe('public')
        ->and($photo->sort_order)->toBe(1);
});

it('increments sort_order for subsequent photos', function () {
    $preview = ProjectPreview::factory()->create();

    $photo1 = $this->service->storePhoto($preview, 'previews/photo1.jpg');
    $photo2 = $this->service->storePhoto($preview, 'previews/photo2.jpg');

    expect($photo1->sort_order)->toBe(1)
        ->and($photo2->sort_order)->toBe(2);
});

it('stores photo with custom disk and mime type', function () {
    $preview = ProjectPreview::factory()->create();

    $photo = $this->service->storePhoto(
        $preview,
        'previews/photo.png',
        's3',
        'image/png',
        54321
    );

    expect($photo->disk)->toBe('s3')
        ->and($photo->mime_type)->toBe('image/png')
        ->and($photo->file_size)->toBe(54321);
});

// =========================================================================
// calculateBallpark
// =========================================================================

it('returns empty result when rooms_data is null', function () {
    $preview = ProjectPreview::factory()->create(['rooms_data' => null]);

    $result = $this->service->calculateBallpark($preview);

    expect($result['total_min'])->toBe(0.0)
        ->and($result['total_max'])->toBe(0.0)
        ->and($result['rooms'])->toBeEmpty();
});

it('skips rooms with no matching template', function () {
    $preview = ProjectPreview::factory()->create([
        'rooms_data' => [
            ['name' => 'Nonexistent Room XYZ'],
        ],
    ]);

    $result = $this->service->calculateBallpark($preview);

    expect($result['rooms'])->toBeEmpty();
});

it('calculates ballpark for rooms matching templates', function () {
    // Create a template with sizes metadata
    EstimateTemplate::factory()
        ->interior()
        ->perSqftPricing(2.50)
        ->create([
            'name' => 'Living Room',
            'is_active' => true,
            'metadata' => [
                'sizes' => [
                    'small' => ['sqft' => 150, 'label' => 'Small'],
                    'medium' => ['sqft' => 225, 'label' => 'Medium'],
                    'large' => ['sqft' => 350, 'label' => 'Large'],
                ],
                'default_size' => 'medium',
            ],
        ]);

    $preview = ProjectPreview::factory()->create([
        'scope_type' => 'interior',
        'rooms_data' => [
            ['name' => 'Living Room'],
        ],
    ]);

    $result = $this->service->calculateBallpark($preview);

    expect($result['rooms'])->toHaveCount(1)
        ->and($result['rooms'][0]['name'])->toBe('Living Room')
        ->and($result['rooms'][0]['min'])->toBeGreaterThan(0)
        ->and($result['rooms'][0]['max'])->toBeGreaterThan($result['rooms'][0]['min'])
        ->and($result['total_min'])->toBe($result['rooms'][0]['min'])
        ->and($result['total_max'])->toBe($result['rooms'][0]['max']);
});

// =========================================================================
// convertToEstimate
// =========================================================================

it('converts a preview to an estimate', function () {
    $customer = Customer::factory()->create();

    $preview = ProjectPreview::factory()->create([
        'customer_id' => $customer->id,
        'scope_type' => 'interior',
        'rooms_data' => [
            ['name' => 'Living Room', 'size' => 'medium'],
        ],
    ]);

    // Create matching template
    EstimateTemplate::factory()
        ->interior()
        ->perSqftPricing(2.50)
        ->create([
            'name' => 'Living Room',
            'is_active' => true,
            'default_quantity' => 225,
            'metadata' => [
                'sizes' => [
                    'small' => ['sqft' => 150],
                    'medium' => ['sqft' => 225],
                    'large' => ['sqft' => 350],
                ],
                'default_size' => 'medium',
            ],
        ]);

    $estimate = $this->service->convertToEstimate($preview);

    expect($estimate)->toBeInstanceOf(Estimate::class)
        ->and($estimate->status)->toBe('draft')
        ->and($estimate->project_type)->toBe('interior');

    // Verify deal was created
    $preview->refresh();
    expect($preview->status)->toBe('converted')
        ->and($preview->estimate_id)->toBe($estimate->id)
        ->and($preview->deal_id)->not->toBeNull();

    // Verify area was created
    expect($estimate->areas()->count())->toBe(1);
    $area = $estimate->areas()->first();
    expect($area->name)->toBe('Living Room')
        ->and($area->scope_type)->toBe('interior')
        ->and((float) $area->direct_sqft)->toBe(225.0);
});

it('creates a customer when preview has no customer_id', function () {
    $internalUser = \App\Models\User::factory()->internal()->create();

    $preview = ProjectPreview::factory()->create([
        'customer_id' => null,
        'estimator_id' => $internalUser->id,
        'phone' => '3035551234',
        'first_name' => 'Jane',
        'last_name' => 'Smith',
        'email' => 'jane@example.com',
        'rooms_data' => [],
    ]);

    $estimate = $this->service->convertToEstimate($preview);

    $preview->refresh();
    expect($preview->customer_id)->not->toBeNull();

    $customer = Customer::find($preview->customer_id);
    expect($customer->first_name)->toBe('Jane')
        ->and($customer->last_name)->toBe('Smith')
        ->and($customer->email)->toBe('jane@example.com');
});

it('reuses existing customer by phone during conversion', function () {
    $customer = Customer::factory()->create(['phone' => '3035551234']);

    $preview = ProjectPreview::factory()->create([
        'customer_id' => null,
        'phone' => '3035551234',
        'rooms_data' => [],
    ]);

    $this->service->convertToEstimate($preview);

    $preview->refresh();
    expect($preview->customer_id)->toBe($customer->id);
});

it('copies photos to estimate during conversion', function () {
    // Customer needs address/city so the Property auto-creation in Customer::saved fires
    $customer = Customer::factory()->create([
        'address' => '123 Main St',
        'city' => 'Denver',
        'state' => 'CO',
        'zip' => '80202',
    ]);

    $preview = ProjectPreview::factory()->create([
        'customer_id' => $customer->id,
        'estimator_id' => $customer->created_by,
        'rooms_data' => [],
    ]);

    ProjectPreviewPhoto::factory()->create([
        'project_preview_id' => $preview->id,
        'original_path' => 'previews/photo1.jpg',
        'room_label' => 'Kitchen',
        'disk' => 'public',
    ]);

    $estimate = $this->service->convertToEstimate($preview);

    expect($estimate->photos()->count())->toBe(1);
    $photo = $estimate->photos()->first();
    expect($photo->original_path)->toBe('previews/photo1.jpg')
        ->and($photo->caption)->toBe('Kitchen')
        ->and($photo->category)->toBe('reference')
        ->and($photo->show_on_proposal)->toBeTrue();
});

it('uses existing deal_id when preview has one', function () {
    $customer = Customer::factory()->create();
    $deal = Deal::factory()->create(['customer_id' => $customer->id]);

    $preview = ProjectPreview::factory()->create([
        'customer_id' => $customer->id,
        'deal_id' => $deal->id,
        'rooms_data' => [],
    ]);

    $estimate = $this->service->convertToEstimate($preview);

    expect($estimate->deal_id)->toBe($deal->id);
});
