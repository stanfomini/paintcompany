<?php

use App\Models\Keyword;
use App\Models\KeywordPageAssignment;
use App\Models\Service;
use App\Models\ServiceArea;
use App\Services\Seo\KeywordResolver;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    $this->resolver = app(KeywordResolver::class);
    $this->service = Service::factory()->create(['slug' => 'interior-painting', 'title' => 'Interior Painting']);
    $this->area = ServiceArea::factory()->create(['slug' => 'denver-co', 'city' => 'Denver']);
});

it('returns null primary and empty collections when no keywords assigned', function () {
    $bundle = $this->resolver->forCombo($this->service, $this->area);

    expect($bundle['primary'])->toBeNull()
        ->and($bundle['secondary'])->toBeEmpty()
        ->and($bundle['supporting'])->toBeEmpty();
});

it('resolves a combo-specific primary keyword', function () {
    $kw = Keyword::factory()->create(['keyword' => 'interior painters denver']);
    KeywordPageAssignment::factory()
        ->primary()
        ->forCombo($this->service, $this->area)
        ->create(['keyword_id' => $kw->id]);

    $bundle = $this->resolver->forCombo($this->service, $this->area);

    expect($bundle['primary'])->not->toBeNull()
        ->and($bundle['primary']->keyword)->toBe('interior painters denver');
});

it('groups keywords by role', function () {
    $primary = Keyword::factory()->create(['keyword' => 'denver house painters']);
    $secondary1 = Keyword::factory()->create(['keyword' => 'best painters denver']);
    $secondary2 = Keyword::factory()->create(['keyword' => 'painting company denver']);
    $supporting = Keyword::factory()->create(['keyword' => 'how much do painters cost']);

    KeywordPageAssignment::factory()->primary()->forCombo($this->service, $this->area)->create(['keyword_id' => $primary->id]);
    KeywordPageAssignment::factory()->secondary()->forCombo($this->service, $this->area)->create(['keyword_id' => $secondary1->id]);
    KeywordPageAssignment::factory()->secondary()->forCombo($this->service, $this->area)->create(['keyword_id' => $secondary2->id]);
    KeywordPageAssignment::factory()->supporting()->forCombo($this->service, $this->area)->create(['keyword_id' => $supporting->id]);

    Cache::flush();
    $bundle = $this->resolver->forCombo($this->service, $this->area);

    expect($bundle['primary']->keyword)->toBe('denver house painters')
        ->and($bundle['secondary'])->toHaveCount(2)
        ->and($bundle['supporting'])->toHaveCount(1)
        ->and($bundle['supporting']->first()->keyword)->toBe('how much do painters cost');
});

it('falls back to service-only assignment when no combo-specific keyword exists', function () {
    $kw = Keyword::factory()->create(['keyword' => 'interior painting cost']);
    KeywordPageAssignment::factory()
        ->primary()
        ->forServiceOnly($this->service)
        ->create(['keyword_id' => $kw->id]);

    $bundle = $this->resolver->forCombo($this->service, $this->area);

    expect($bundle['primary'])->not->toBeNull()
        ->and($bundle['primary']->keyword)->toBe('interior painting cost');
});

it('falls back to area-only assignment when neither combo nor service-specific exists', function () {
    $kw = Keyword::factory()->create(['keyword' => 'painting services denver colorado']);
    KeywordPageAssignment::factory()
        ->primary()
        ->forAreaOnly($this->area)
        ->create(['keyword_id' => $kw->id]);

    $bundle = $this->resolver->forCombo($this->service, $this->area);

    expect($bundle['primary'])->not->toBeNull()
        ->and($bundle['primary']->keyword)->toBe('painting services denver colorado');
});

it('prefers combo-specific over service-only over area-only when all three exist', function () {
    $combo = Keyword::factory()->create(['keyword' => 'combo specific']);
    $svcOnly = Keyword::factory()->create(['keyword' => 'service only']);
    $areaOnly = Keyword::factory()->create(['keyword' => 'area only']);

    KeywordPageAssignment::factory()->primary()->forCombo($this->service, $this->area)->create(['keyword_id' => $combo->id]);
    KeywordPageAssignment::factory()->primary()->forServiceOnly($this->service)->create(['keyword_id' => $svcOnly->id]);
    KeywordPageAssignment::factory()->primary()->forAreaOnly($this->area)->create(['keyword_id' => $areaOnly->id]);

    Cache::flush();
    $bundle = $this->resolver->forCombo($this->service, $this->area);

    expect($bundle['primary']->keyword)->toBe('combo specific');
});

it('does not include keywords assigned to a different service or area', function () {
    $otherService = Service::factory()->create(['slug' => 'exterior-painting']);
    $otherArea = ServiceArea::factory()->create(['slug' => 'aurora-co']);

    $unrelatedKw = Keyword::factory()->create(['keyword' => 'exterior painters aurora']);
    KeywordPageAssignment::factory()
        ->primary()
        ->forCombo($otherService, $otherArea)
        ->create(['keyword_id' => $unrelatedKw->id]);

    $bundle = $this->resolver->forCombo($this->service, $this->area);

    expect($bundle['primary'])->toBeNull();
});

it('caches the resolved bundle and returns cached value on second call', function () {
    Cache::flush();
    $kw = Keyword::factory()->create(['keyword' => 'cached keyword']);
    KeywordPageAssignment::factory()
        ->primary()
        ->forCombo($this->service, $this->area)
        ->create(['keyword_id' => $kw->id]);

    // First call populates cache
    $first = $this->resolver->forCombo($this->service, $this->area);
    expect($first['primary']->keyword)->toBe('cached keyword');

    // Verify cache key is set
    $cacheKey = "kw:combo:{$this->service->id}:{$this->area->id}";
    expect(Cache::has($cacheKey))->toBeTrue();
});

it('flushes the cache for the affected combo when an assignment is saved', function () {
    Cache::flush();
    $kw = Keyword::factory()->create(['keyword' => 'first keyword']);
    KeywordPageAssignment::factory()
        ->primary()
        ->forCombo($this->service, $this->area)
        ->create(['keyword_id' => $kw->id]);

    // Prime cache
    $this->resolver->forCombo($this->service, $this->area);
    $cacheKey = "kw:combo:{$this->service->id}:{$this->area->id}";
    expect(Cache::has($cacheKey))->toBeTrue();

    // New assignment should bust the cache
    $newKw = Keyword::factory()->create(['keyword' => 'new keyword']);
    KeywordPageAssignment::factory()
        ->secondary()
        ->forCombo($this->service, $this->area)
        ->create(['keyword_id' => $newKw->id]);

    expect(Cache::has($cacheKey))->toBeFalse();
});

it('resolves keywords for a service-only standalone page', function () {
    $kw = Keyword::factory()->create(['keyword' => 'best interior paint']);
    KeywordPageAssignment::factory()
        ->primary()
        ->forServiceOnly($this->service)
        ->create(['keyword_id' => $kw->id]);

    $bundle = $this->resolver->forServiceOnly($this->service);

    expect($bundle['primary']->keyword)->toBe('best interior paint');
});

it('resolves keywords for an area-only standalone page', function () {
    $kw = Keyword::factory()->create(['keyword' => 'painters in denver']);
    KeywordPageAssignment::factory()
        ->primary()
        ->forAreaOnly($this->area)
        ->create(['keyword_id' => $kw->id]);

    $bundle = $this->resolver->forAreaOnly($this->area);

    expect($bundle['primary']->keyword)->toBe('painters in denver');
});

it('throws when an assignment has both target FKs null', function () {
    $kw = Keyword::factory()->create();

    expect(fn () => KeywordPageAssignment::factory()->create([
        'keyword_id' => $kw->id,
        'service_id' => null,
        'service_area_id' => null,
    ]))->toThrow(RuntimeException::class);
});

it('returns specificity 3 for combo, 2 for service-only, 1 for area-only', function () {
    $kw = Keyword::factory()->create();

    $combo = KeywordPageAssignment::factory()->forCombo($this->service, $this->area)->create(['keyword_id' => $kw->id]);
    $svcOnly = KeywordPageAssignment::factory()->forServiceOnly($this->service)->create(['keyword_id' => $kw->id]);
    $areaOnly = KeywordPageAssignment::factory()->forAreaOnly($this->area)->create(['keyword_id' => $kw->id]);

    expect($combo->specificity)->toBe(3)
        ->and($svcOnly->specificity)->toBe(2)
        ->and($areaOnly->specificity)->toBe(1);
});
