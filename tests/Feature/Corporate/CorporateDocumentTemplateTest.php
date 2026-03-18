<?php

use App\Models\CompanySetting;
use App\Models\CorporateDocument;
use App\Models\Corporation;
use App\Models\User;
use App\Services\CorporateDocumentService;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
    $this->settings = CompanySetting::firstOrCreate([], ['company_name' => 'Test Company']);
    $this->corporation = Corporation::factory()->create(['company_setting_id' => $this->settings->id]);
    $this->user = User::factory()->internal()->create();
    $this->service = app(CorporateDocumentService::class);
});

// -- Board Resolution Template --

it('creates a board resolution from template', function () {
    $data = [
        'corporation_name' => 'Test Corp',
        'director_name' => 'John Doe',
        'shareholder_name' => 'Parent LLC',
        'share_class' => 'Common',
        'share_count' => 1000000,
        'consideration' => '$1,000',
        'resolution_date' => 'March 1, 2026',
        'state' => 'Colorado',
        'certificated' => false,
    ];

    $doc = $this->service->createFromTemplate(
        $this->corporation,
        'board_resolution',
        'Board Resolution',
        $data,
        $this->user,
    );

    expect($doc)
        ->toBeInstanceOf(CorporateDocument::class)
        ->title->toBe('Board Resolution')
        ->type->toBe('board_resolution')
        ->source->toBe('template')
        ->status->toBe('ready')
        ->version->toBe(1)
        ->template_data->toBe($data);
});

// -- Stock Ledger Template --

it('creates a stock ledger from template', function () {
    $data = [
        'corporation_name' => 'Test Corp',
        'authorized_shares' => [['class' => 'Common', 'authorized' => 5000000]],
        'entries' => [[
            'date' => 'March 1, 2026',
            'shareholder' => 'Parent LLC',
            'address' => '123 Main St',
            'class' => 'Common',
            'shares' => 2000000,
            'certificate_number' => '001',
            'consideration' => '$2,000',
        ]],
    ];

    $doc = $this->service->createFromTemplate(
        $this->corporation,
        'stock_ledger',
        'Stock Ledger',
        $data,
        $this->user,
    );

    expect($doc)
        ->toBeInstanceOf(CorporateDocument::class)
        ->title->toBe('Stock Ledger')
        ->type->toBe('stock_ledger')
        ->source->toBe('template')
        ->status->toBe('ready')
        ->version->toBe(1)
        ->template_data->toBe($data);
});

// -- PDF Storage --

it('stores PDF file in storage', function () {
    $data = [
        'corporation_name' => 'Test Corp',
        'director_name' => 'John Doe',
        'shareholder_name' => 'Parent LLC',
        'share_class' => 'Common',
        'share_count' => 1000000,
        'consideration' => '$1,000',
        'resolution_date' => 'March 1, 2026',
        'state' => 'Colorado',
        'certificated' => false,
    ];

    $doc = $this->service->createFromTemplate(
        $this->corporation,
        'board_resolution',
        'Board Resolution',
        $data,
        $this->user,
    );

    Storage::assertExists($doc->original_file_path);
    expect($doc->file_size)->toBeGreaterThan(0);
});

// -- Status & Source --

it('sets status to ready and source to template', function () {
    $data = [
        'corporation_name' => 'Test Corp',
        'director_name' => 'Jane Smith',
        'shareholder_name' => 'Holding Co',
        'share_class' => 'Common',
        'share_count' => 500,
        'consideration' => '$500',
        'resolution_date' => 'March 7, 2026',
        'state' => 'Delaware',
        'certificated' => true,
    ];

    $doc = $this->service->createFromTemplate(
        $this->corporation,
        'board_resolution',
        'Resolution Doc',
        $data,
    );

    expect($doc->status)->toBe('ready')
        ->and($doc->source)->toBe('template');
});

// -- Template Data Persistence --

it('stores template data correctly', function () {
    $data = [
        'corporation_name' => 'Data Corp',
        'authorized_shares' => [['class' => 'Preferred', 'authorized' => 100000]],
        'entries' => [[
            'date' => 'January 15, 2026',
            'shareholder' => 'Founder',
            'address' => '456 Elm St',
            'class' => 'Preferred',
            'shares' => 50000,
            'certificate_number' => '002',
            'consideration' => '$50,000',
        ]],
    ];

    $doc = $this->service->createFromTemplate(
        $this->corporation,
        'stock_ledger',
        'Ledger',
        $data,
        $this->user,
    );

    $fresh = $doc->fresh();
    expect($fresh->template_data)->toBe($data)
        ->and($fresh->template_data['corporation_name'])->toBe('Data Corp')
        ->and($fresh->template_data['entries'])->toHaveCount(1);
});

// -- Parsed Content Sections --

it('creates parsed content sections for editor', function () {
    $data = [
        'corporation_name' => 'Test Corp',
        'director_name' => 'John Doe',
        'shareholder_name' => 'Parent LLC',
        'share_class' => 'Common',
        'share_count' => 1000000,
        'consideration' => '$1,000',
        'resolution_date' => 'March 1, 2026',
        'state' => 'Colorado',
        'certificated' => false,
    ];

    $doc = $this->service->createFromTemplate(
        $this->corporation,
        'board_resolution',
        'Board Resolution',
        $data,
        $this->user,
    );

    expect($doc->parsed_content)->toBeArray()->not->toBeEmpty();
    expect($doc->parsed_content[0])->toMatchArray(['type' => 'heading', 'text' => 'Test Corp']);
});

// -- Edit Template Document --

it('allows template docs to be edited via saveEdits', function () {
    $data = [
        'corporation_name' => 'Test Corp',
        'director_name' => 'John Doe',
        'shareholder_name' => 'Parent LLC',
        'share_class' => 'Common',
        'share_count' => 1000000,
        'consideration' => '$1,000',
        'resolution_date' => 'March 1, 2026',
        'state' => 'Colorado',
        'certificated' => false,
    ];

    $doc = $this->service->createFromTemplate(
        $this->corporation,
        'board_resolution',
        'Board Resolution',
        $data,
        $this->user,
    );

    $edited = $doc->parsed_content;
    $edited[0]['text'] = 'Updated Corp Name';

    $v2 = $this->service->saveEdits($doc, $edited);

    expect($v2->id)->not->toBe($doc->id)
        ->and($v2->version)->toBe(2)
        ->and($v2->parent_document_id)->toBe($doc->id);
});

// -- Export PDF --

it('uses template view for export', function () {
    $data = [
        'corporation_name' => 'Export Corp',
        'director_name' => 'Jane Doe',
        'shareholder_name' => 'Export LLC',
        'share_class' => 'Common',
        'share_count' => 100,
        'consideration' => '$100',
        'resolution_date' => 'March 7, 2026',
        'state' => 'Colorado',
        'certificated' => false,
    ];

    $doc = $this->service->createFromTemplate(
        $this->corporation,
        'board_resolution',
        'Board Resolution',
        $data,
        $this->user,
    );

    $pdf = $this->service->exportToPdf($doc);

    expect($pdf)->toBeInstanceOf(\Barryvdh\DomPDF\PDF::class);
});

// -- Unknown Template Type --

it('throws for unknown template type', function () {
    $this->service->createFromTemplate(
        $this->corporation,
        'nonexistent_type',
        'Bad Template',
        ['corporation_name' => 'Test'],
        $this->user,
    );
})->throws(\InvalidArgumentException::class, 'Unknown template type: nonexistent_type');

// -- Without User --

it('creates document without user', function () {
    $data = [
        'corporation_name' => 'No User Corp',
        'director_name' => 'John Doe',
        'shareholder_name' => 'Parent LLC',
        'share_class' => 'Common',
        'share_count' => 1000,
        'consideration' => '$1,000',
        'resolution_date' => 'March 1, 2026',
        'state' => 'Colorado',
        'certificated' => false,
    ];

    $doc = $this->service->createFromTemplate(
        $this->corporation,
        'board_resolution',
        'Board Resolution',
        $data,
    );

    expect($doc->uploaded_by)->toBeNull();
});

// -- Certificate of Incumbency Template --

it('creates a certificate of incumbency from template', function () {
    $this->corporation->officers()->createMany([
        ['name' => 'Alice Smith', 'title' => 'President', 'sort_order' => 0],
        ['name' => 'Bob Jones', 'title' => 'Secretary', 'sort_order' => 1],
    ]);

    $data = [
        'corporation_name' => $this->corporation->name,
        'entity_type' => 'LLC',
        'state' => 'Colorado',
        'formation_date' => 'January 15, 2020',
        'ein' => '12-3456789',
        'address' => '123 Main St',
        'city' => 'Denver',
        'state_abbr' => 'CO',
        'zip' => '80202',
        'registered_agent' => 'Agent Corp',
        'certifying_officer' => 'Alice Smith',
        'certifying_title' => 'President',
        'certificate_date' => 'March 18, 2026',
        'include_ownership' => true,
        'authority_statement' => 'The listed persons are authorized to act on behalf of the entity.',
        'officers' => [
            ['name' => 'Alice Smith', 'title' => 'President'],
            ['name' => 'Bob Jones', 'title' => 'Secretary'],
        ],
        'owners' => [
            ['name' => 'Alice Smith', 'role' => 'Managing Member', 'percentage' => 60],
            ['name' => 'Bob Jones', 'role' => 'Member', 'percentage' => 40],
        ],
    ];

    $doc = $this->service->createFromTemplate(
        $this->corporation,
        'certificate_of_incumbency',
        'Certificate of Incumbency',
        $data,
        $this->user,
    );

    expect($doc)
        ->toBeInstanceOf(CorporateDocument::class)
        ->title->toBe('Certificate of Incumbency')
        ->type->toBe('certificate_of_incumbency')
        ->source->toBe('template')
        ->status->toBe('ready')
        ->version->toBe(1)
        ->template_data->toBe($data);

    Storage::assertExists($doc->original_file_path);
});

it('builds parsed content sections for certificate of incumbency', function () {
    $data = [
        'corporation_name' => 'Test Corp LLC',
        'entity_type' => 'LLC',
        'state' => 'Colorado',
        'formation_date' => 'January 15, 2020',
        'ein' => '12-3456789',
        'address' => '123 Main St',
        'city' => 'Denver',
        'state_abbr' => 'CO',
        'zip' => '80202',
        'registered_agent' => 'Agent Corp',
        'certifying_officer' => 'Alice Smith',
        'certifying_title' => 'President',
        'certificate_date' => 'March 18, 2026',
        'include_ownership' => false,
        'authority_statement' => 'Authorized to act.',
        'officers' => [
            ['name' => 'Alice Smith', 'title' => 'President'],
        ],
    ];

    $sections = $this->service->buildSectionsForTemplate('certificate_of_incumbency', $data);

    expect($sections)->toBeArray()->not->toBeEmpty();
    expect($sections[0])->toMatchArray(['type' => 'heading', 'text' => 'Test Corp LLC']);
    expect($sections[1])->toMatchArray(['type' => 'heading', 'text' => 'Certificate of Incumbency']);

    // Should contain the officer
    $officerTexts = collect($sections)->pluck('text');
    expect($officerTexts->contains(fn ($t) => str_contains($t, 'Alice Smith')))->toBeTrue();
});

it('auto-includes corporation officers in certificate data', function () {
    $this->corporation->officers()->createMany([
        ['name' => 'Officer One', 'title' => 'President', 'sort_order' => 0],
        ['name' => 'Officer Two', 'title' => 'Secretary', 'sort_order' => 1],
    ]);

    $data = [
        'corporation_name' => $this->corporation->name,
        'entity_type' => 'S-Corp',
        'state' => 'Delaware',
        'certifying_officer' => 'Officer One',
        'certifying_title' => 'President',
        'certificate_date' => 'March 18, 2026',
        'include_ownership' => false,
        'authority_statement' => 'Authorized.',
        'officers' => $this->corporation->officers->map(fn ($o) => [
            'name' => $o->name,
            'title' => $o->title,
        ])->toArray(),
    ];

    $doc = $this->service->createFromTemplate(
        $this->corporation,
        'certificate_of_incumbency',
        'Certificate of Incumbency',
        $data,
        $this->user,
    );

    expect($doc->template_data['officers'])->toHaveCount(2);
    expect($doc->template_data['officers'][0]['name'])->toBe('Officer One');
    expect($doc->template_data['officers'][1]['name'])->toBe('Officer Two');
});

// -- isTemplateGenerated --

it('marks template generated documents correctly', function () {
    $templateDoc = CorporateDocument::factory()
        ->for($this->corporation)
        ->fromTemplate()
        ->create();

    $regularDoc = CorporateDocument::factory()
        ->for($this->corporation)
        ->create();

    expect($templateDoc->isTemplateGenerated())->toBeTrue()
        ->and($regularDoc->isTemplateGenerated())->toBeFalse();
});
