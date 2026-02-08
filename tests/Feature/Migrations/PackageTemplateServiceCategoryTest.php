<?php

use App\Models\PackageTemplate;
use App\Models\ServiceCategory;
use Illuminate\Support\Facades\Schema;

it('package_templates has service_category_id column', function () {
    expect(Schema::hasColumn('package_templates', 'service_category_id'))->toBeTrue();
});

it('estimate_packages has service_category_id column', function () {
    expect(Schema::hasColumn('estimate_packages', 'service_category_id'))->toBeTrue();
});

it('can create package templates with different service categories for same tier', function () {
    $painting = ServiceCategory::firstOrCreate(
        ['slug' => 'painting'],
        ['name' => 'Painting', 'icon' => 'paint-brush', 'color' => 'blue', 'is_active' => true, 'sort_order' => 1]
    );
    $cleaning = ServiceCategory::firstOrCreate(
        ['slug' => 'cleaning'],
        ['name' => 'Cleaning', 'icon' => 'sparkles', 'color' => 'green', 'is_active' => true, 'sort_order' => 2]
    );

    $template1 = PackageTemplate::factory()->good()->forService($painting)->create();
    $template2 = PackageTemplate::factory()->good()->forService($cleaning)->create();

    expect($template1->service_category_id)->toBe($painting->id);
    expect($template2->service_category_id)->toBe($cleaning->id);
    expect($template1->tier)->toBe($template2->tier);
});
