<?php

use App\Models\Customer;
use App\Models\ProjectPreview;
use App\Services\ProjectPreviewService;

// createForPhone tests

it('creates a preview for a phone number', function () {
    $service = app(ProjectPreviewService::class);
    $preview = $service->createForPhone('+13035551234');

    expect($preview)->toBeInstanceOf(ProjectPreview::class)
        ->and($preview->phone)->toContain('3035551234')
        ->and($preview->status)->toBe('active')
        ->and($preview->uuid)->not->toBeEmpty()
        ->and($preview->public_token)->not->toBeEmpty();
});

it('links to existing customer by phone', function () {
    $customer = Customer::factory()->create(['phone' => '3035551234']);
    $service = app(ProjectPreviewService::class);
    $preview = $service->createForPhone('+13035551234', $customer->id);

    expect($preview->customer_id)->toBe($customer->id);
});

it('creates preview for customer model', function () {
    $customer = Customer::factory()->create();
    $service = app(ProjectPreviewService::class);
    $preview = $service->createForCustomer($customer);

    expect($preview->customer_id)->toBe($customer->id)
        ->and($preview->first_name)->toBe($customer->first_name)
        ->and($preview->phone)->not->toBeEmpty();
});

// storePhoto tests

it('stores a photo for a preview', function () {
    $preview = ProjectPreview::factory()->create();
    $service = app(ProjectPreviewService::class);

    $photo = $service->storePhoto($preview, 'previews/test.jpg', 'public', 'image/jpeg', 12345);

    expect($photo->project_preview_id)->toBe($preview->id)
        ->and($photo->original_path)->toBe('previews/test.jpg')
        ->and($photo->mime_type)->toBe('image/jpeg')
        ->and($photo->sort_order)->toBe(1);
});

it('increments photo sort order', function () {
    $preview = ProjectPreview::factory()->create();
    $service = app(ProjectPreviewService::class);

    $photo1 = $service->storePhoto($preview, 'previews/1.jpg');
    $photo2 = $service->storePhoto($preview, 'previews/2.jpg');

    expect($photo1->sort_order)->toBe(1)
        ->and($photo2->sort_order)->toBe(2);
});

// findOrCreateByPhone tests

it('finds existing active preview by phone', function () {
    $preview = ProjectPreview::factory()->active()->create(['phone' => '3035551234']);
    $service = app(ProjectPreviewService::class);

    $found = $service->findOrCreateByPhone('3035551234');

    expect($found->id)->toBe($preview->id);
});

it('creates new preview when none exists for phone', function () {
    $service = app(ProjectPreviewService::class);
    $preview = $service->findOrCreateByPhone('3035559999');

    expect($preview)->toBeInstanceOf(ProjectPreview::class)
        ->and($preview->status)->toBe('active');
});

// calculateBallpark tests

it('returns empty ballpark when rooms_data is null', function () {
    $preview = ProjectPreview::factory()->create(['rooms_data' => null]);
    $service = app(ProjectPreviewService::class);

    $result = $service->calculateBallpark($preview);

    expect($result['total_min'])->toBe(0.0)
        ->and($result['total_max'])->toBe(0.0)
        ->and($result['rooms'])->toBeEmpty();
});

it('returns empty ballpark when no rooms match templates', function () {
    $preview = ProjectPreview::factory()->create([
        'rooms_data' => [['name' => 'Nonexistent Room XYZ']],
    ]);
    $service = app(ProjectPreviewService::class);

    $result = $service->calculateBallpark($preview);

    expect($result['rooms'])->toBeEmpty();
});
