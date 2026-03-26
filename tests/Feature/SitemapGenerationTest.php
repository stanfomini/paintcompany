<?php

use App\Models\Service;
use App\Models\ServiceArea;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('generates sitemap with area pages', function () {
    ServiceArea::factory()->create(['city' => 'Denver', 'slug' => 'denver', 'is_active' => true]);
    ServiceArea::factory()->create(['city' => 'Boulder', 'slug' => 'boulder', 'is_active' => true]);
    ServiceArea::factory()->create(['city' => 'Inactive City', 'slug' => 'inactive', 'is_active' => false]);

    $this->artisan('sitemap:generate')->assertExitCode(0);

    $sitemap = file_get_contents(public_path('sitemap.xml'));

    expect($sitemap)
        ->toContain('/areas/denver')
        ->toContain('/areas/boulder')
        ->not->toContain('/areas/inactive')
        ->toContain('/book')
        ->toContain('/careers');
});

it('generates sitemap with correct XML structure', function () {
    ServiceArea::factory()->create(['city' => 'Denver', 'slug' => 'denver', 'is_active' => true]);

    $this->artisan('sitemap:generate')->assertExitCode(0);

    $sitemap = file_get_contents(public_path('sitemap.xml'));

    expect($sitemap)
        ->toContain('<?xml version="1.0" encoding="UTF-8"?>')
        ->toContain('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">')
        ->toContain('</urlset>');
});

it('generates sitemap with static pages', function () {
    $this->artisan('sitemap:generate')->assertExitCode(0);

    $sitemap = file_get_contents(public_path('sitemap.xml'));

    $baseUrl = config('app.url');

    expect($sitemap)
        ->toContain("<loc>{$baseUrl}</loc>")
        ->toContain('/book')
        ->toContain('/areas')
        ->toContain('/calculator')
        ->toContain('/careers')
        ->toContain('/watch')
        ->toContain('/terms')
        ->toContain('/privacy')
        ->toContain('/referral-terms');
});

it('outputs the correct URL count', function () {
    ServiceArea::factory()->create(['city' => 'Denver', 'slug' => 'denver', 'is_active' => true]);
    ServiceArea::factory()->create(['city' => 'Boulder', 'slug' => 'boulder', 'is_active' => true]);

    // 13 static pages + 2 active areas = 15 URLs (no services created)
    $this->artisan('sitemap:generate')
        ->expectsOutputToContain('15 URLs')
        ->assertExitCode(0);
});

it('orders area pages alphabetically by city', function () {
    ServiceArea::factory()->create(['city' => 'Zephyr Hills', 'slug' => 'zephyr-hills', 'is_active' => true]);
    ServiceArea::factory()->create(['city' => 'Aurora', 'slug' => 'aurora', 'is_active' => true]);

    $this->artisan('sitemap:generate')->assertExitCode(0);

    $sitemap = file_get_contents(public_path('sitemap.xml'));

    $auroraPos = strpos($sitemap, '/areas/aurora');
    $zephyrPos = strpos($sitemap, '/areas/zephyr-hills');

    expect($auroraPos)->toBeLessThan($zephyrPos);
});

it('includes service pages sourced from Service model', function () {
    Service::factory()->create(['slug' => 'interior-painting', 'is_active' => true]);
    Service::factory()->create(['slug' => 'exterior-painting', 'is_active' => true]);
    Service::factory()->inactive()->create(['slug' => 'hidden-service']);

    $this->artisan('sitemap:generate')->assertExitCode(0);

    $sitemap = file_get_contents(public_path('sitemap.xml'));

    expect($sitemap)
        ->toContain('/services/interior-painting')
        ->toContain('/services/exterior-painting')
        ->not->toContain('/services/hidden-service');
});

it('includes area+service combo URLs', function () {
    ServiceArea::factory()->create(['slug' => 'denver', 'is_active' => true]);
    ServiceArea::factory()->create(['slug' => 'aurora', 'is_active' => true]);
    Service::factory()->create(['slug' => 'interior-painting', 'is_active' => true]);

    $this->artisan('sitemap:generate')->assertExitCode(0);

    $sitemap = file_get_contents(public_path('sitemap.xml'));

    expect($sitemap)
        ->toContain('/areas/denver/interior-painting')
        ->toContain('/areas/aurora/interior-painting');
});

it('excludes inactive areas from combo URLs', function () {
    ServiceArea::factory()->create(['slug' => 'active-city', 'is_active' => true]);
    ServiceArea::factory()->create(['slug' => 'inactive-city', 'is_active' => false]);
    Service::factory()->create(['slug' => 'test-service', 'is_active' => true]);

    $this->artisan('sitemap:generate')->assertExitCode(0);

    $sitemap = file_get_contents(public_path('sitemap.xml'));

    expect($sitemap)
        ->toContain('/areas/active-city/test-service')
        ->not->toContain('/areas/inactive-city/test-service');
});

it('generates correct URL count with combos', function () {
    ServiceArea::factory()->create(['slug' => 'denver', 'is_active' => true]);
    ServiceArea::factory()->create(['slug' => 'aurora', 'is_active' => true]);
    Service::factory()->create(['slug' => 'interior', 'is_active' => true]);
    Service::factory()->create(['slug' => 'exterior', 'is_active' => true]);

    // 13 static + 2 service pages + 2 area pages + 4 combo pages = 21
    $this->artisan('sitemap:generate')
        ->expectsOutputToContain('21 URLs')
        ->assertExitCode(0);
});
