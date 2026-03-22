<?php

use App\Models\CompanySetting;
use App\Models\ServiceArea;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    CompanySetting::getInstance();
});

afterEach(function () {
    $path = public_path('sitemap.xml');
    if (File::exists($path)) {
        File::delete($path);
    }
});

// ============================================================
// SITEMAP TESTS
// ============================================================

describe('Sitemap', function () {
    it('includes services index page', function () {
        $this->artisan('sitemap:generate')->assertSuccessful();
        $content = File::get(public_path('sitemap.xml'));

        expect($content)->toContain(url('/services'));
    });

    it('includes FAQ, how-it-works, and visualize pages', function () {
        $this->artisan('sitemap:generate')->assertSuccessful();
        $content = File::get(public_path('sitemap.xml'));

        expect($content)
            ->toContain(url('/faq'))
            ->toContain(url('/how-it-works'))
            ->toContain(url('/visualize'));
    });

    it('includes all 17 service guide pages', function () {
        $this->artisan('sitemap:generate')->assertSuccessful();
        $content = File::get(public_path('sitemap.xml'));

        $expectedSlugs = [
            'interior-painting', 'exterior-painting', 'cabinet-painting',
            'deck-fence-staining', 'garage-floor-coating', 'popcorn-ceiling-removal',
            'drywall-repair', 'trim-door-painting', 'wallpaper-removal',
            'commercial-interior', 'commercial-exterior', 'warehouse-industrial',
            'hoa-multiunit', 'line-striping', 'pressure-washing',
            'color-consultation', 'stucco-coating',
        ];

        foreach ($expectedSlugs as $slug) {
            expect($content)->toContain(url('/services/'.$slug));
        }
    });

    it('includes dynamic service area pages', function () {
        ServiceArea::factory()->create(['city' => 'Denver', 'slug' => 'denver-co', 'is_active' => true]);
        ServiceArea::factory()->create(['city' => 'Aurora', 'slug' => 'aurora-co', 'is_active' => true]);

        $this->artisan('sitemap:generate')->assertSuccessful();
        $content = File::get(public_path('sitemap.xml'));

        expect($content)
            ->toContain(url('/areas/denver-co'))
            ->toContain(url('/areas/aurora-co'));
    });

    it('includes I-25 corridor service area pages', function () {
        ServiceArea::factory()->create(['city' => 'Fort Collins', 'slug' => 'fort-collins-co', 'is_active' => true]);
        ServiceArea::factory()->create(['city' => 'Loveland', 'slug' => 'loveland-co', 'is_active' => true]);

        $this->artisan('sitemap:generate')->assertSuccessful();
        $content = File::get(public_path('sitemap.xml'));

        expect($content)
            ->toContain(url('/areas/fort-collins-co'))
            ->toContain(url('/areas/loveland-co'));
    });
});

// ============================================================
// LANDING PAGE SCHEMA TESTS
// ============================================================

describe('Landing page schema', function () {
    it('includes GeoCoordinates', function () {
        ServiceArea::factory()->create(['city' => 'Denver', 'slug' => 'denver-co', 'is_active' => true]);

        $response = $this->get('/');
        $response->assertSuccessful();

        $response->assertSee('GeoCoordinates', false);
        $response->assertSee('"latitude"', false);
        $response->assertSee('"longitude"', false);
    });

    it('includes openingHoursSpecification', function () {
        $response = $this->get('/');
        $response->assertSuccessful();

        $response->assertSee('OpeningHoursSpecification', false);
        $response->assertSee('"dayOfWeek"', false);
    });

    it('includes correct address fields from CompanySetting', function () {
        $company = CompanySetting::getInstance();
        $company->update([
            'address_line_1' => '123 Test Street',
            'city' => 'Denver',
            'state' => 'CO',
            'zip_code' => '80202',
        ]);

        $response = $this->get('/');
        $response->assertSuccessful();

        $response->assertSee('123 Test Street', false);
        $response->assertSee('80202', false);
    });

    it('includes dynamic areaServed from ServiceArea model', function () {
        ServiceArea::factory()->create(['city' => 'Lakewood', 'slug' => 'lakewood-co', 'is_active' => true]);
        ServiceArea::factory()->create(['city' => 'Arvada', 'slug' => 'arvada-co', 'is_active' => true]);

        $response = $this->get('/');
        $response->assertSuccessful();

        $response->assertSee('Lakewood', false);
        $response->assertSee('Arvada', false);
    });

    it('includes I-25 corridor cities in areaServed', function () {
        ServiceArea::factory()->create(['city' => 'Fort Collins', 'slug' => 'fort-collins-co', 'is_active' => true]);
        ServiceArea::factory()->create(['city' => 'Loveland', 'slug' => 'loveland-co', 'is_active' => true]);

        $response = $this->get('/');
        $response->assertSuccessful();

        $response->assertSee('Fort Collins', false);
        $response->assertSee('Loveland', false);
    });
});

// ============================================================
// SERVICE PAGE SCHEMA TESTS
// ============================================================

describe('Service page schema', function () {
    it('includes BreadcrumbList', function () {
        $response = $this->get('/services/interior-painting');
        $response->assertSuccessful();

        $response->assertSee('BreadcrumbList', false);
        $response->assertSee('"Services"', false);
        $response->assertSee('Interior House Painting', false);
    });

    it('includes expanded areaServed', function () {
        ServiceArea::factory()->create(['city' => 'Golden', 'slug' => 'golden-co', 'is_active' => true]);

        $response = $this->get('/services/exterior-painting');
        $response->assertSuccessful();

        $response->assertSee('Golden', false);
    });
});

// ============================================================
// AREA PAGE SCHEMA TESTS
// ============================================================

describe('Area page schema', function () {
    it('includes GeoCoordinates when area has coordinates', function () {
        ServiceArea::factory()->create([
            'city' => 'Boulder',
            'slug' => 'boulder-co',
            'is_active' => true,
            'latitude' => 40.0150,
            'longitude' => -105.2705,
        ]);

        $response = $this->get('/areas/boulder-co');
        $response->assertSuccessful();

        $response->assertSee('GeoCoordinates', false);
        $response->assertSee('40.015', false);
    });

    it('includes BreadcrumbList', function () {
        ServiceArea::factory()->create([
            'city' => 'Centennial',
            'slug' => 'centennial-co',
            'is_active' => true,
        ]);

        $response = $this->get('/areas/centennial-co');
        $response->assertSuccessful();

        $response->assertSee('BreadcrumbList', false);
        $response->assertSee('Service Areas', false);
        $response->assertSee('Centennial', false);
    });

    it('renders service area map when area has coordinates', function () {
        ServiceArea::factory()->create([
            'city' => 'Parker',
            'slug' => 'parker-co',
            'is_active' => true,
            'latitude' => 39.5186,
            'longitude' => -104.7614,
        ]);

        $response = $this->get('/areas/parker-co');
        $response->assertSuccessful();

        $response->assertSee('service-area-map-', false);
        $response->assertSee('Our Service Area Near Parker', false);
    });
});
