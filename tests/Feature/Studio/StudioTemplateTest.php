<?php

use App\Models\StudioTemplate;

it('creates a studio template', function () {
    $template = StudioTemplate::factory()->beforeAfter()->create();

    expect($template->category)->toBe('before_after');
    expect($template->is_active)->toBeTrue();
    expect($template->structure_json)->toBeArray();
    expect($template->structure_json)->toHaveKey('segments');
    expect($template->structure_json)->toHaveKey('overlays');
});

it('seeds default templates', function () {
    $this->artisan('db:seed', ['--class' => 'StudioTemplateSeeder']);

    expect(StudioTemplate::count())->toBe(6);
    expect(StudioTemplate::where('category', 'before_after')->exists())->toBeTrue();
    expect(StudioTemplate::where('category', 'walkthrough')->exists())->toBeTrue();
    expect(StudioTemplate::where('category', 'testimonial')->exists())->toBeTrue();
    expect(StudioTemplate::where('category', 'estimate_tour')->exists())->toBeTrue();
    expect(StudioTemplate::where('category', 'team_highlight')->exists())->toBeTrue();
    expect(StudioTemplate::where('category', 'promo')->exists())->toBeTrue();
});

it('seeder is idempotent', function () {
    $this->artisan('db:seed', ['--class' => 'StudioTemplateSeeder']);
    $this->artisan('db:seed', ['--class' => 'StudioTemplateSeeder']);

    expect(StudioTemplate::count())->toBe(6);
});

it('scopes to active templates', function () {
    StudioTemplate::factory()->create(['is_active' => true]);
    StudioTemplate::factory()->inactive()->create();

    expect(StudioTemplate::active()->count())->toBe(1);
});

it('scopes by category', function () {
    StudioTemplate::factory()->beforeAfter()->create();
    StudioTemplate::factory()->create(['category' => 'walkthrough']);
    StudioTemplate::factory()->create(['category' => 'promo']);

    expect(StudioTemplate::byCategory('before_after')->count())->toBe(1);
    expect(StudioTemplate::byCategory('walkthrough')->count())->toBe(1);
});

it('casts structure_json to array', function () {
    $template = StudioTemplate::factory()->create([
        'structure_json' => [
            'segments' => [
                ['type' => 'media', 'label' => 'Test shot'],
            ],
            'overlays' => [],
        ],
    ]);

    $template->refresh();

    expect($template->structure_json)->toBeArray();
    expect($template->structure_json['segments'])->toHaveCount(1);
    expect($template->structure_json['segments'][0]['type'])->toBe('media');
});

it('casts is_active to boolean', function () {
    $template = StudioTemplate::factory()->create(['is_active' => 1]);
    $template->refresh();

    expect($template->is_active)->toBeTrue();
    expect($template->is_active)->toBeBool();
});

it('has correct fillable attributes', function () {
    $template = StudioTemplate::factory()->create([
        'name' => 'Test Template',
        'description' => 'A test description',
        'category' => 'before_after',
        'thumbnail_path' => '/images/test.jpg',
        'system_prompt' => 'Test prompt',
        'is_active' => true,
        'sort_order' => 5,
    ]);

    expect($template->name)->toBe('Test Template');
    expect($template->description)->toBe('A test description');
    expect($template->category)->toBe('before_after');
    expect($template->thumbnail_path)->toBe('/images/test.jpg');
    expect($template->system_prompt)->toBe('Test prompt');
    expect($template->is_active)->toBeTrue();
    expect($template->sort_order)->toBe(5);
});
