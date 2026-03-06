<?php

use App\Jobs\ParseCorporateDocumentJob;
use App\Models\CompanySetting;
use App\Models\CorporateDocument;
use App\Models\Corporation;
use App\Models\User;
use App\Services\CorporateDocumentService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
    $this->settings = CompanySetting::firstOrCreate([], ['company_name' => 'Test Company']);
    $this->corporation = Corporation::factory()->create(['company_setting_id' => $this->settings->id]);
    $this->user = User::factory()->internal()->create();
    $this->service = app(CorporateDocumentService::class);
});

// -- Upload Document --

it('uploads a document and creates a record', function () {
    Queue::fake();

    $file = UploadedFile::fake()->create('operating-agreement.pdf', 100, 'application/pdf');

    $doc = $this->service->uploadDocument(
        $this->corporation,
        $file,
        'Operating Agreement',
        'operating_agreement',
        $this->user,
    );

    expect($doc)
        ->toBeInstanceOf(CorporateDocument::class)
        ->title->toBe('Operating Agreement')
        ->type->toBe('operating_agreement')
        ->status->toBe('processing')
        ->version->toBe(1)
        ->uploaded_by->toBe($this->user->id)
        ->corporation_id->toBe($this->corporation->id)
        ->original_filename->toBe('operating-agreement.pdf')
        ->file_size->toBe($file->getSize());

    Storage::assertExists($doc->original_file_path);
});

it('dispatches parse job after upload', function () {
    Queue::fake();

    $file = UploadedFile::fake()->create('test.pdf', 50, 'application/pdf');

    $doc = $this->service->uploadDocument(
        $this->corporation,
        $file,
        'Test Doc',
        'other',
    );

    Queue::assertPushed(ParseCorporateDocumentJob::class, function ($job) use ($doc) {
        return $job->document->id === $doc->id;
    });
});

it('uploads without a user', function () {
    Queue::fake();

    $file = UploadedFile::fake()->create('bylaws.pdf', 80, 'application/pdf');

    $doc = $this->service->uploadDocument(
        $this->corporation,
        $file,
        'Bylaws',
        'bylaws',
    );

    expect($doc->uploaded_by)->toBeNull();
});

// -- Save Edits --

it('saves initial edits to a document as a new version', function () {
    $doc = CorporateDocument::factory()->for($this->corporation)->withParsedContent()->create([
        'edited_content' => null,
    ]);

    $newSections = ['sections' => [['title' => 'Article I', 'content' => 'Updated content']]];

    $result = $this->service->saveEdits($doc, $newSections);

    expect($result->id)->not->toBe($doc->id)
        ->and($result->version)->toBe(2)
        ->and($result->parent_document_id)->toBe($doc->id)
        ->and($result->edited_content)->toBe($newSections);
});

it('creates a new version when content was already edited', function () {
    $doc = CorporateDocument::factory()->for($this->corporation)->create([
        'parsed_content' => ['sections' => [['title' => 'Original']]],
        'edited_content' => ['sections' => [['title' => 'Edit v1']]],
        'version' => 1,
    ]);

    $newSections = ['sections' => [['title' => 'Edit v2']]];

    $result = $this->service->saveEdits($doc, $newSections);

    expect($result->id)->not->toBe($doc->id)
        ->and($result->version)->toBe(2)
        ->and($result->edited_content)->toBe($newSections)
        ->and($result->parent_document_id)->toBe($doc->id);
});

it('skips save when content is unchanged', function () {
    $sections = ['sections' => [['title' => 'Same']]];

    $doc = CorporateDocument::factory()->for($this->corporation)->create([
        'edited_content' => $sections,
    ]);

    $result = $this->service->saveEdits($doc, $sections);

    expect($result->id)->toBe($doc->id);
    expect(CorporateDocument::count())->toBe(1);
});

it('preserves parent_document_id when creating new version from child', function () {
    $parent = CorporateDocument::factory()->for($this->corporation)->create(['version' => 1]);

    $child = CorporateDocument::factory()->for($this->corporation)->create([
        'parent_document_id' => $parent->id,
        'version' => 2,
        'edited_content' => ['sections' => [['title' => 'v2']]],
    ]);

    $newSections = ['sections' => [['title' => 'v3']]];
    $result = $this->service->saveEdits($child, $newSections);

    expect($result->parent_document_id)->toBe($parent->id)
        ->and($result->version)->toBe(3);
});
