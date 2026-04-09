<?php

use App\Actions\Seo\AssignKeywordAction;
use App\Models\Keyword;
use App\Models\KeywordPageAssignment;
use App\Models\Service;
use App\Models\ServiceArea;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    $this->service = Service::factory()->create([
        'slug' => 'interior-painting',
        'title' => 'Interior Painting',
        'is_active' => true,
        'meta_description' => 'Quality interior painting in Denver, Colorado.',
    ]);
    $this->area = ServiceArea::factory()->create([
        'slug' => 'denver-co',
        'city' => 'Denver',
        'is_active' => true,
        'local_content' => [
            'neighborhoods' => ['Capitol Hill', 'Highlands', 'Wash Park'],
            'housing_types' => ['Victorians', 'Craftsman bungalows'],
            'challenges' => ['high altitude UV exposure'],
            'seasonal_notes' => 'Best season is May-October.',
            'why_local' => 'Denver has unique seasonal needs.',
        ],
    ]);
    $this->service->serviceAreas()->attach($this->area->id);
});

it('renders the combo page with no keyword assigned (fallback path)', function () {
    $this->get('/areas/denver-co/interior-painting')
        ->assertSuccessful()
        ->assertSee('Interior Painting Denver CO')
        ->assertSee('Quality interior painting in Denver, Colorado.', escape: false);
});

it('injects the primary keyword into the meta title and meta description', function () {
    $kw = Keyword::factory()->create(['keyword' => 'best interior painters denver']);
    app(AssignKeywordAction::class)->execute(
        keyword: $kw,
        service: $this->service,
        serviceArea: $this->area,
        role: 'primary',
    );

    $response = $this->get('/areas/denver-co/interior-painting');
    $response->assertSuccessful();
    $body = $response->getContent();

    expect($body)->toContain('Best Interior Painters Denver')
        ->and($body)->toContain('Looking for best interior painters denver?');
});

it('injects the auto-generated keyword sentence in the body', function () {
    $kw = Keyword::factory()->create(['keyword' => 'denver house painting']);
    app(AssignKeywordAction::class)->execute(
        keyword: $kw,
        service: $this->service,
        serviceArea: $this->area,
        role: 'primary',
    );

    $body = $this->get('/areas/denver-co/interior-painting')->getContent();

    expect($body)->toContain('When homeowners search for denver house painting in Denver');
});

it('uses the per-assignment custom_sentence override when present', function () {
    $kw = Keyword::factory()->create(['keyword' => 'denver house painting']);
    app(AssignKeywordAction::class)->execute(
        keyword: $kw,
        service: $this->service,
        serviceArea: $this->area,
        role: 'primary',
        customSentence: 'Hand-crafted custom sentence for this exact combo page.',
    );

    $body = $this->get('/areas/denver-co/interior-painting')->getContent();

    expect($body)->toContain('Hand-crafted custom sentence for this exact combo page.')
        ->and($body)->not->toContain('When homeowners search for denver house painting');
});

it('falls back to the global default_sentence when no per-assignment override exists', function () {
    $kw = Keyword::factory()->create([
        'keyword' => 'denver house painting',
        'default_sentence' => 'Global default sentence used when no override.',
    ]);
    app(AssignKeywordAction::class)->execute(
        keyword: $kw,
        service: $this->service,
        serviceArea: $this->area,
        role: 'primary',
    );

    $body = $this->get('/areas/denver-co/interior-painting')->getContent();

    expect($body)->toContain('Global default sentence used when no override.');
});

it('does not inject the meta_description prefix when the keyword already appears in the description', function () {
    $kw = Keyword::factory()->create(['keyword' => 'interior painting']);
    app(AssignKeywordAction::class)->execute(
        keyword: $kw,
        service: $this->service,
        serviceArea: $this->area,
        role: 'primary',
    );

    $body = $this->get('/areas/denver-co/interior-painting')->getContent();

    expect($body)->not->toContain('Looking for interior painting?')
        ->and($body)->toContain('Quality interior painting in Denver, Colorado.');
});

it('a service-only fallback keyword is used when no combo-specific exists', function () {
    $kw = Keyword::factory()->create(['keyword' => 'interior painting cost']);
    KeywordPageAssignment::factory()
        ->primary()
        ->forServiceOnly($this->service)
        ->create(['keyword_id' => $kw->id]);

    $body = $this->get('/areas/denver-co/interior-painting')->getContent();

    expect($body)->toContain('Interior Painting Cost');
});

it('renders the standalone area page with an area-only primary keyword', function () {
    $kw = Keyword::factory()->create(['keyword' => 'painters in denver colorado']);
    KeywordPageAssignment::factory()
        ->primary()
        ->forAreaOnly($this->area)
        ->create(['keyword_id' => $kw->id]);

    $body = $this->get('/areas/denver-co')->getContent();

    expect($body)->toContain('Painters In Denver Colorado')
        ->and($body)->toContain('When homeowners search for painters in denver colorado in Denver');
});

it('renders the standalone service page with a service-only primary keyword', function () {
    $kw = Keyword::factory()->create(['keyword' => 'best interior painters']);
    KeywordPageAssignment::factory()
        ->primary()
        ->forServiceOnly($this->service)
        ->create(['keyword_id' => $kw->id]);

    $body = $this->get('/services/interior-painting')->getContent();

    expect($body)->toContain('Best Interior Painters');
});
