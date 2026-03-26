<?php

use App\Models\Service;
use Illuminate\Support\Facades\File;

afterEach(function () {
    $path = public_path('sitemap.xml');
    if (File::exists($path)) {
        File::delete($path);
    }
});

it('generates sitemap.xml via artisan command', function () {
    $this->artisan('sitemap:generate')
        ->expectsOutputToContain('Sitemap generated')
        ->assertSuccessful();

    expect(File::exists(public_path('sitemap.xml')))->toBeTrue();
});

it('sitemap contains expected URLs', function () {
    Service::factory()->create(['slug' => 'interior-painting']);
    Service::factory()->create(['slug' => 'exterior-painting']);
    Service::factory()->create(['slug' => 'stucco-coating']);

    $this->artisan('sitemap:generate')->assertSuccessful();

    $content = File::get(public_path('sitemap.xml'));

    expect($content)
        ->toContain('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">')
        ->toContain(url('/'))
        ->toContain(url('/calculator'))
        ->toContain(url('/careers'))
        ->toContain(url('/terms'))
        ->toContain(url('/privacy'))
        ->toContain(url('/services'))
        ->toContain(url('/faq'))
        ->toContain(url('/how-it-works'))
        ->toContain(url('/visualize'))
        ->toContain(url('/services/interior-painting'))
        ->toContain(url('/services/exterior-painting'))
        ->toContain(url('/services/stucco-coating'));
});

it('sitemap is valid XML', function () {
    $this->artisan('sitemap:generate')->assertSuccessful();

    $content = File::get(public_path('sitemap.xml'));
    $xml = simplexml_load_string($content);

    expect($xml)->not->toBeFalse();
    expect($xml->getName())->toBe('urlset');
});
