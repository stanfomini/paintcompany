<?php

use App\Models\WebhookEndpoint;
use App\Models\WebhookFieldMapping;
use App\Services\WebhookMappingPresetService;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

it('applies split_first_name transform', function () {
    $mapping = WebhookFieldMapping::factory()->withTransform('split_first_name')->make();

    expect($mapping->applyTransform('John Doe'))->toBe('John');
});

it('applies split_last_name transform', function () {
    $mapping = WebhookFieldMapping::factory()->withTransform('split_last_name')->make();

    expect($mapping->applyTransform('John Doe'))->toBe('Doe');
});

it('splits name with multiple parts correctly', function () {
    $mapping = WebhookFieldMapping::factory()->withTransform('split_first_name')->make();
    expect($mapping->applyTransform('Mary Jane Watson'))->toBe('Mary');

    $lastMapping = WebhookFieldMapping::factory()->withTransform('split_last_name')->make();
    expect($lastMapping->applyTransform('Mary Jane Watson'))->toBe('Jane Watson');
});

it('applies format_phone transform', function () {
    $mapping = WebhookFieldMapping::factory()->withTransform('format_phone')->make();

    expect($mapping->applyTransform('(555) 123-4567'))->toBe('5551234567');
});

it('formats phone with various formats', function () {
    $mapping = WebhookFieldMapping::factory()->withTransform('format_phone')->make();

    expect($mapping->applyTransform('555.123.4567'))->toBe('5551234567');
    expect($mapping->applyTransform('+1 555-123-4567'))->toBe('15551234567');
    expect($mapping->applyTransform('5551234567'))->toBe('5551234567');
});

it('applies join_details transform with array', function () {
    $mapping = WebhookFieldMapping::factory()->withTransform('join_details')->make();

    expect($mapping->applyTransform(['interior', 'exterior', 'deck']))->toBe('interior, exterior, deck');
});

it('applies join_details transform with json string', function () {
    $mapping = WebhookFieldMapping::factory()->withTransform('join_details')->make();

    expect($mapping->applyTransform('["paint", "stain"]'))->toBe('paint, stain');
});

it('returns default_value when value is empty', function () {
    $mapping = WebhookFieldMapping::factory()->make([
        'transform' => null,
        'default_value' => 'Fallback',
    ]);

    expect($mapping->applyTransform(null))->toBe('Fallback');
    expect($mapping->applyTransform(''))->toBe('Fallback');
});

it('returns value when not empty even with default', function () {
    $mapping = WebhookFieldMapping::factory()->make([
        'transform' => null,
        'default_value' => 'Fallback',
    ]);

    expect($mapping->applyTransform('Actual Value'))->toBe('Actual Value');
});

it('has angi preset', function () {
    $service = app(WebhookMappingPresetService::class);

    $preset = $service->getPresetFor('angi');

    expect($preset)->not->toBeNull();
    expect($preset)->toBeArray();
    expect(count($preset))->toBeGreaterThan(0);

    $fields = collect($preset)->pluck('target_field')->toArray();
    expect($fields)->toContain('first_name');
    expect($fields)->toContain('email');
    expect($fields)->toContain('external_id');
});

it('has craftjack preset', function () {
    $service = app(WebhookMappingPresetService::class);

    $preset = $service->getPresetFor('craftjack');

    expect($preset)->not->toBeNull();
    expect($preset)->toBeArray();

    $fields = collect($preset)->pluck('target_field')->toArray();
    expect($fields)->toContain('first_name');
    expect($fields)->toContain('email');
    expect($fields)->toContain('external_id');
});

it('has thumbtack preset', function () {
    $service = app(WebhookMappingPresetService::class);

    $preset = $service->getPresetFor('thumbtack');

    expect($preset)->not->toBeNull();
    expect($preset)->toBeArray();

    // Thumbtack uses split transforms for name
    $transforms = collect($preset)->pluck('transform')->filter()->toArray();
    expect($transforms)->toContain('split_first_name');
    expect($transforms)->toContain('split_last_name');
});

it('returns null for unknown preset', function () {
    $service = app(WebhookMappingPresetService::class);

    expect($service->getPresetFor('unknown_source'))->toBeNull();
});

it('applies preset to endpoint', function () {
    $endpoint = WebhookEndpoint::factory()->create();
    $service = app(WebhookMappingPresetService::class);

    $service->applyPreset($endpoint, 'angi');

    $mappings = $endpoint->fieldMappings()->get();
    expect($mappings->count())->toBeGreaterThan(0);

    $firstMapping = $mappings->first();
    expect($firstMapping->source_path)->toBe('firstName');
    expect($firstMapping->target_model)->toBe('customer');
    expect($firstMapping->target_field)->toBe('first_name');
    expect($firstMapping->sort_order)->toBe(1);
});
