<?php

use App\Models\Service;
use App\Models\ServiceArea;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

describe('meta titles render in title tag', function () {
    it('renders title on the landing page via #[Title] attribute', function () {
        $this->get('/')
            ->assertSuccessful()
            ->assertSee('<title>Professional House Painting Denver | Painting Made Easy Denver</title>', escape: false);
    });

    it('renders meta_title on the areas index', function () {
        $this->get('/areas')
            ->assertSuccessful()
            ->assertSee('<title>Painting Service Areas | Denver Metro &amp; Front Range | Painting Made Easy Denver</title>', escape: false);
    });

    it('renders meta_title on the services index', function () {
        $this->get('/services')
            ->assertSuccessful()
            ->assertSee('<title>Painting Services Guide | Denver CO | Painting Made Easy Denver</title>', escape: false);
    });

    it('renders meta_title on area page', function () {
        ServiceArea::factory()->create([
            'slug' => 'denver-seo-co',
            'city' => 'Denver SEO',
            'meta_title' => 'House Painting Denver SEO CO | Interior & Exterior | Painting Made Easy Denver',
        ]);

        $this->get('/areas/denver-seo-co')
            ->assertSuccessful()
            ->assertSee('House Painting Denver SEO CO', escape: false);
    });

    it('renders meta_title on service page', function () {
        Service::factory()->create([
            'slug' => 'interior-seo-test',
            'title' => 'Interior Painting',
        ]);

        $this->get('/services/interior-seo-test')
            ->assertSuccessful()
            ->assertSee('<title>Interior Painting Denver CO | Painting Made Easy Denver</title>', escape: false);
    });

    it('renders meta_title on area+service combo page', function () {
        ServiceArea::factory()->create(['slug' => 'seo-city-co', 'city' => 'SEO City']);
        Service::factory()->create(['slug' => 'seo-service', 'title' => 'SEO Service']);

        $this->get('/areas/seo-city-co/seo-service')
            ->assertSuccessful()
            ->assertSee('<title>SEO Service SEO City CO | Painting Made Easy Denver</title>', escape: false);
    });
});

describe('single H1 per page', function () {
    it('has exactly one H1 on the landing page', function () {
        $html = $this->get('/')->assertSuccessful()->getContent();
        expect(substr_count(strtolower($html), '<h1'))->toBe(1);
    });

    it('has exactly one H1 on area pages', function () {
        ServiceArea::factory()->create(['slug' => 'h1-test-co', 'city' => 'H1 Test']);
        $html = $this->get('/areas/h1-test-co')->assertSuccessful()->getContent();
        expect(substr_count(strtolower($html), '<h1'))->toBe(1);
    });

    it('has exactly one H1 on service pages', function () {
        Service::factory()->create(['slug' => 'h1-service', 'title' => 'H1 Service']);
        $html = $this->get('/services/h1-service')->assertSuccessful()->getContent();
        expect(substr_count(strtolower($html), '<h1'))->toBe(1);
    });

    it('has exactly one H1 on area+service combo pages', function () {
        ServiceArea::factory()->create(['slug' => 'h1-combo-co', 'city' => 'H1 Combo']);
        Service::factory()->create(['slug' => 'h1-combo-svc', 'title' => 'H1 Combo Service']);
        $html = $this->get('/areas/h1-combo-co/h1-combo-svc')->assertSuccessful()->getContent();
        expect(substr_count(strtolower($html), '<h1'))->toBe(1);
    });

    it('has exactly one H1 on the areas index', function () {
        $html = $this->get('/areas')->assertSuccessful()->getContent();
        expect(substr_count(strtolower($html), '<h1'))->toBe(1);
    });

    it('has exactly one H1 on the services index', function () {
        $html = $this->get('/services')->assertSuccessful()->getContent();
        expect(substr_count(strtolower($html), '<h1'))->toBe(1);
    });
});

describe('structured data is valid JSON-LD', function () {
    it('has valid JSON-LD on the landing page', function () {
        $html = $this->get('/')->assertSuccessful()->getContent();
        expect($html)->toContain('application/ld+json');
        preg_match_all('/<script type="application\/ld\+json">\s*(.*?)\s*<\/script>/s', $html, $matches);
        expect(count($matches[1]))->toBeGreaterThan(0);
        foreach ($matches[1] as $json) {
            $decoded = json_decode(trim($json), true);
            expect($decoded)->not->toBeNull('Invalid JSON-LD: '.substr(trim($json), 0, 200));
        }
    });

    it('has valid JSON-LD on area pages', function () {
        ServiceArea::factory()->create(['slug' => 'jsonld-co', 'city' => 'JSON City']);
        $html = $this->get('/areas/jsonld-co')->assertSuccessful()->getContent();
        preg_match_all('/<script type="application\/ld\+json">\s*(.*?)\s*<\/script>/s', $html, $matches);
        expect(count($matches[1]))->toBeGreaterThan(0);
        foreach ($matches[1] as $json) {
            $decoded = json_decode(trim($json), true);
            expect($decoded)->not->toBeNull('Invalid JSON-LD: '.substr(trim($json), 0, 200));
        }
    });

    it('has valid JSON-LD on service pages', function () {
        Service::factory()->create(['slug' => 'jsonld-service', 'title' => 'JSON Service']);
        $html = $this->get('/services/jsonld-service')->assertSuccessful()->getContent();
        preg_match_all('/<script type="application\/ld\+json">\s*(.*?)\s*<\/script>/s', $html, $matches);
        expect(count($matches[1]))->toBeGreaterThan(0);
        foreach ($matches[1] as $json) {
            $decoded = json_decode(trim($json), true);
            expect($decoded)->not->toBeNull('Invalid JSON-LD: '.substr(trim($json), 0, 200));
        }
    });

    it('has valid JSON-LD on area+service combo pages', function () {
        ServiceArea::factory()->create(['slug' => 'jsonld-combo-co', 'city' => 'JSON Combo']);
        Service::factory()->create(['slug' => 'jsonld-combo-svc', 'title' => 'JSON Combo Service']);
        $html = $this->get('/areas/jsonld-combo-co/jsonld-combo-svc')->assertSuccessful()->getContent();
        preg_match_all('/<script type="application\/ld\+json">\s*(.*?)\s*<\/script>/s', $html, $matches);
        expect(count($matches[1]))->toBeGreaterThan(0);
        foreach ($matches[1] as $json) {
            $decoded = json_decode(trim($json), true);
            expect($decoded)->not->toBeNull('Invalid JSON-LD: '.substr(trim($json), 0, 200));
        }
    });
});

describe('internal linking between page types', function () {
    it('area page links to area+service combo pages', function () {
        $area = ServiceArea::factory()->create(['slug' => 'link-city-co', 'city' => 'Link City']);
        $service = Service::factory()->create(['slug' => 'link-service', 'title' => 'Link Service']);
        $area->services()->attach($service->id);

        $this->get('/areas/link-city-co')
            ->assertSuccessful()
            ->assertSee('/areas/link-city-co/link-service', escape: false);
    });

    it('service page links to area+service combo pages', function () {
        $area = ServiceArea::factory()->create(['slug' => 'svc-link-co', 'city' => 'Svc Link']);
        $service = Service::factory()->create(['slug' => 'svc-link-svc', 'title' => 'Svc Link Service']);
        $area->services()->attach($service->id);

        $this->get('/services/svc-link-svc')
            ->assertSuccessful()
            ->assertSee('/areas/svc-link-co/svc-link-svc', escape: false);
    });

    it('area+service combo page links back to area parent', function () {
        ServiceArea::factory()->create(['slug' => 'parent-city-co', 'city' => 'Parent City']);
        Service::factory()->create(['slug' => 'parent-svc', 'title' => 'Parent Service']);

        $this->get('/areas/parent-city-co/parent-svc')
            ->assertSuccessful()
            ->assertSee('/areas/parent-city-co"', escape: false);
    });

    it('area+service combo page links to service guide', function () {
        ServiceArea::factory()->create(['slug' => 'guide-city-co', 'city' => 'Guide City']);
        Service::factory()->create(['slug' => 'guide-svc', 'title' => 'Guide Service']);

        $this->get('/areas/guide-city-co/guide-svc')
            ->assertSuccessful()
            ->assertSee('/services/guide-svc', escape: false);
    });
});

describe('sitemap includes all page types', function () {
    it('generates sitemap with area+service combo pages', function () {
        ServiceArea::factory()->create(['slug' => 'sitemap-co', 'city' => 'Sitemap City']);
        Service::factory()->create(['slug' => 'sitemap-svc', 'title' => 'Sitemap Service']);

        $this->artisan('sitemap:generate')->assertSuccessful();

        $sitemap = file_get_contents(public_path('sitemap.xml'));
        expect($sitemap)->toContain('/areas/sitemap-co/sitemap-svc');
    });

    it('generates sitemap with service pages from database', function () {
        Service::factory()->create(['slug' => 'db-service', 'title' => 'DB Service']);

        $this->artisan('sitemap:generate')->assertSuccessful();

        $sitemap = file_get_contents(public_path('sitemap.xml'));
        expect($sitemap)->toContain('/services/db-service');
    });
});
