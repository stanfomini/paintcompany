<?php

use App\Models\Keyword;
use App\Models\KeywordPageAssignment;
use App\Models\Service;
use App\Models\ServiceArea;
use App\Models\User;

beforeEach(function () {
    $this->internal = User::factory()->internal()->create();
});

describe('keywords index', function () {
    it('renders for internal users', function () {
        $this->actingAs($this->internal)
            ->get(route('seo.keywords.index'))
            ->assertSuccessful()
            ->assertSee('SEO Keywords');
    });

    it('shows the empty state when no keywords exist', function () {
        $this->actingAs($this->internal)
            ->get(route('seo.keywords.index'))
            ->assertSuccessful()
            ->assertSee('No keywords yet');
    });

    it('lists existing keywords', function () {
        Keyword::factory()->create(['keyword' => 'denver house painters']);

        $this->actingAs($this->internal)
            ->get(route('seo.keywords.index'))
            ->assertSuccessful()
            ->assertSee('denver house painters');
    });

    it('blocks guests', function () {
        $this->get(route('seo.keywords.index'))
            ->assertRedirect();
    });
});

describe('keyword import (paste flow)', function () {
    it('renders the import page', function () {
        $this->actingAs($this->internal)
            ->get(route('seo.keywords.import'))
            ->assertSuccessful()
            ->assertSee('Import Keywords');
    });

    it('imports a paste of keywords without page assignment', function () {
        $action = app(\App\Actions\Seo\ImportKeywordPasteAction::class);

        $result = $action->execute(
            pasteBody: "interior painters denver\ndenver house painting\nbest painters denver co",
            defaultStatus: 'researched',
            defaultSource: 'manual',
        );

        expect($result['created'])->toBe(3)
            ->and($result['existing'])->toBe(0)
            ->and($result['assigned'])->toBe(0)
            ->and(Keyword::count())->toBe(3)
            ->and(Keyword::where('keyword', 'interior painters denver')->exists())->toBeTrue();
    });

    it('imports and assigns keywords to a service+area combo', function () {
        $service = Service::factory()->create();
        $area = ServiceArea::factory()->create();
        $action = app(\App\Actions\Seo\ImportKeywordPasteAction::class);

        $result = $action->execute(
            pasteBody: "kw one\nkw two",
            service: $service,
            serviceArea: $area,
            defaultRole: 'secondary',
        );

        expect($result['created'])->toBe(2)
            ->and($result['assigned'])->toBe(2)
            ->and(KeywordPageAssignment::where('service_id', $service->id)->where('service_area_id', $area->id)->count())->toBe(2);
    });

    it('dedupes existing keywords by lowercased value', function () {
        Keyword::factory()->create(['keyword' => 'denver painters']);
        $action = app(\App\Actions\Seo\ImportKeywordPasteAction::class);

        $result = $action->execute(pasteBody: "Denver Painters\nnew keyword");

        expect($result['created'])->toBe(1)
            ->and($result['existing'])->toBe(1)
            ->and(Keyword::count())->toBe(2);
    });

    it('strips blank lines and lines longer than 255 characters', function () {
        $action = app(\App\Actions\Seo\ImportKeywordPasteAction::class);

        $longLine = str_repeat('x', 260);
        $result = $action->execute(pasteBody: "good\n\n  \n{$longLine}\nfine");

        expect($result['created'])->toBe(2);
    });
});

describe('AssignKeywordAction primary uniqueness', function () {
    it('demotes the existing primary when force-assigning a new primary on the same page', function () {
        $service = Service::factory()->create();
        $area = ServiceArea::factory()->create();
        $kw1 = Keyword::factory()->create();
        $kw2 = Keyword::factory()->create();
        $action = app(\App\Actions\Seo\AssignKeywordAction::class);

        $action->execute($kw1, $service, $area, role: 'primary');
        $action->execute($kw2, $service, $area, role: 'primary', force: true);

        expect(KeywordPageAssignment::where('keyword_id', $kw1->id)->first()->role)->toBe('secondary')
            ->and(KeywordPageAssignment::where('keyword_id', $kw2->id)->first()->role)->toBe('primary');
    });

    it('refuses to overwrite a primary when force is false', function () {
        $service = Service::factory()->create();
        $area = ServiceArea::factory()->create();
        $kw1 = Keyword::factory()->create();
        $kw2 = Keyword::factory()->create();
        $action = app(\App\Actions\Seo\AssignKeywordAction::class);

        $action->execute($kw1, $service, $area, role: 'primary');

        expect(fn () => $action->execute($kw2, $service, $area, role: 'primary', force: false))
            ->toThrow(InvalidArgumentException::class);
    });

    it('throws when neither service nor area is supplied', function () {
        $kw = Keyword::factory()->create();

        expect(fn () => app(\App\Actions\Seo\AssignKeywordAction::class)->execute($kw, null, null, role: 'secondary'))
            ->toThrow(InvalidArgumentException::class);
    });

    it('updates an existing assignment in place (idempotent dedup)', function () {
        $service = Service::factory()->create();
        $area = ServiceArea::factory()->create();
        $kw = Keyword::factory()->create();
        $action = app(\App\Actions\Seo\AssignKeywordAction::class);

        $action->execute($kw, $service, $area, role: 'secondary');
        $action->execute($kw, $service, $area, role: 'supporting');

        expect(KeywordPageAssignment::where('keyword_id', $kw->id)->count())->toBe(1)
            ->and(KeywordPageAssignment::where('keyword_id', $kw->id)->first()->role)->toBe('supporting');
    });
});
