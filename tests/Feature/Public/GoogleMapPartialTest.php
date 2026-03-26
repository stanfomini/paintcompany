<?php

use App\Models\ServiceArea;

describe('Google Maps Partial', function () {
    it('has google_maps key in services config', function () {
        expect(config('services.google_maps'))->toBeArray()
            ->and(config('services.google_maps'))->toHaveKey('key');
    });

    it('renders without errors when given valid areas', function () {
        $areas = ServiceArea::factory()->count(3)->create([
            'latitude' => 39.7392,
            'longitude' => -104.9903,
        ]);

        $view = $this->blade(
            '@include("partials.google-map", ["areas" => $areas, "center" => [39.75, -104.99], "zoom" => 9, "highlight" => null, "lazy" => false])',
            ['areas' => $areas]
        );

        $view->assertSee('google-map-', escape: false)
            ->assertSee('new google.maps.Map', escape: false)
            ->assertSee('gestureHandling', escape: false)
            ->assertSee('maps.googleapis.com', escape: false);
    });

    it('renders lazy loading with IntersectionObserver', function () {
        $areas = ServiceArea::factory()->count(2)->create([
            'latitude' => 39.7392,
            'longitude' => -104.9903,
        ]);

        $view = $this->blade(
            '@include("partials.google-map", ["areas" => $areas, "center" => [39.75, -104.99], "zoom" => 9, "highlight" => null, "lazy" => true])',
            ['areas' => $areas]
        );

        $view->assertSee('Loading map...', escape: false)
            ->assertSee('IntersectionObserver', escape: false)
            ->assertSee('rootMargin', escape: false);
    });

    it('highlights a specific area marker', function () {
        $areas = ServiceArea::factory()->count(2)->create([
            'latitude' => 39.7392,
            'longitude' => -104.9903,
        ]);
        $highlight = $areas->first()->slug;

        $view = $this->blade(
            '@include("partials.google-map", ["areas" => $areas, "center" => [39.75, -104.99], "zoom" => 9, "highlight" => $highlight, "lazy" => false])',
            ['areas' => $areas, 'highlight' => $highlight]
        );

        $view->assertSee($highlight, escape: false);
    });

    it('includes HQ marker with company name', function () {
        $areas = ServiceArea::factory()->count(1)->create([
            'latitude' => 39.7392,
            'longitude' => -104.9903,
        ]);

        $view = $this->blade(
            '@include("partials.google-map", ["areas" => $areas, "center" => [39.75, -104.99], "zoom" => 9, "highlight" => null, "lazy" => false])',
            ['areas' => $areas]
        );

        $view->assertSee('Company HQ', escape: false)
            ->assertSee('39.7392', escape: false);
    });

    it('filters out areas without coordinates', function () {
        $withCoords = ServiceArea::factory()->create([
            'city' => 'Denver',
            'latitude' => 39.7392,
            'longitude' => -104.9903,
        ]);
        $withoutCoords = ServiceArea::factory()->create([
            'city' => 'NoCoords',
            'latitude' => null,
            'longitude' => null,
        ]);

        $areas = ServiceArea::whereIn('id', [$withCoords->id, $withoutCoords->id])->get();

        $view = $this->blade(
            '@include("partials.google-map", ["areas" => $areas, "center" => [39.75, -104.99], "zoom" => 9, "highlight" => null, "lazy" => false])',
            ['areas' => $areas]
        );

        $view->assertSee('Denver', escape: false)
            ->assertDontSee('NoCoords');
    });

    it('uses custom dark map styling', function () {
        $areas = ServiceArea::factory()->count(1)->create([
            'latitude' => 39.7392,
            'longitude' => -104.9903,
        ]);

        $view = $this->blade(
            '@include("partials.google-map", ["areas" => $areas, "center" => [39.75, -104.99], "zoom" => 9, "highlight" => null, "lazy" => false])',
            ['areas' => $areas]
        );

        $view->assertSee('#242f3e', escape: false)
            ->assertSee('#17263c', escape: false)
            ->assertSee('styles:', escape: false);
    });
});
