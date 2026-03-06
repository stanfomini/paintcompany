<?php

use App\Jobs\ParseCorporateDocumentJob;
use App\Models\CompanySetting;
use App\Models\CorporateDocument;
use App\Models\Corporation;
use App\Services\PythonBridgeService;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
    $this->settings = CompanySetting::firstOrCreate([], ['company_name' => 'Test Company']);
    $this->corporation = Corporation::factory()->create(['company_setting_id' => $this->settings->id]);
});

it('marks document as error when python is disabled', function () {
    $doc = CorporateDocument::factory()->for($this->corporation)->processing()->create();

    $python = $this->mock(PythonBridgeService::class);
    $python->shouldReceive('isEnabled')->once()->andReturn(false);

    (new ParseCorporateDocumentJob($doc))->handle($python);

    $doc->refresh();
    expect($doc->status)->toBe('error')
        ->and($doc->parse_error)->toBe('Python service is not enabled');
});

it('marks document as error when file not found', function () {
    $doc = CorporateDocument::factory()->for($this->corporation)->processing()->create([
        'original_file_path' => 'corporate-documents/nonexistent.pdf',
    ]);

    $python = $this->mock(PythonBridgeService::class);
    $python->shouldReceive('isEnabled')->once()->andReturn(true);

    (new ParseCorporateDocumentJob($doc))->handle($python);

    $doc->refresh();
    expect($doc->status)->toBe('error')
        ->and($doc->parse_error)->toBe('PDF file not found in storage');
});

it('parses document successfully', function () {
    $filePath = 'corporate-documents/test.pdf';
    Storage::put($filePath, 'fake pdf content');

    $doc = CorporateDocument::factory()->for($this->corporation)->processing()->create([
        'original_file_path' => $filePath,
    ]);

    $python = $this->mock(PythonBridgeService::class);
    $python->shouldReceive('isEnabled')->once()->andReturn(true);
    $python->shouldReceive('parsePdf')
        ->once()
        ->andReturn([
            'success' => true,
            'sections' => [
                ['title' => 'Article I', 'content' => 'Formation clause'],
                ['title' => 'Article II', 'content' => 'Members'],
            ],
            'page_count' => 5,
        ]);

    (new ParseCorporateDocumentJob($doc))->handle($python);

    $doc->refresh();
    expect($doc->status)->toBe('ready')
        ->and($doc->parsed_content)->toHaveCount(2)
        ->and($doc->page_count)->toBe(5)
        ->and($doc->parse_error)->toBeNull();
});

it('marks document as error on parse failure', function () {
    $filePath = 'corporate-documents/bad.pdf';
    Storage::put($filePath, 'corrupted');

    $doc = CorporateDocument::factory()->for($this->corporation)->processing()->create([
        'original_file_path' => $filePath,
    ]);

    $python = $this->mock(PythonBridgeService::class);
    $python->shouldReceive('isEnabled')->once()->andReturn(true);
    $python->shouldReceive('parsePdf')
        ->once()
        ->andReturn([
            'success' => false,
            'error' => 'Unable to extract text from PDF',
        ]);

    (new ParseCorporateDocumentJob($doc))->handle($python);

    $doc->refresh();
    expect($doc->status)->toBe('error')
        ->and($doc->parse_error)->toBe('Unable to extract text from PDF');
});

it('handles job failure gracefully', function () {
    $doc = CorporateDocument::factory()->for($this->corporation)->processing()->create();

    $job = new ParseCorporateDocumentJob($doc);
    $job->failed(new RuntimeException('Connection refused'));

    $doc->refresh();
    expect($doc->status)->toBe('error')
        ->and($doc->parse_error)->toContain('Connection refused');
});

it('uses redis connection', function () {
    $doc = CorporateDocument::factory()->for($this->corporation)->processing()->create();

    $job = new ParseCorporateDocumentJob($doc);

    expect($job->connection)->toBe('redis');
});

it('has retry limit of 2', function () {
    $doc = CorporateDocument::factory()->for($this->corporation)->processing()->create();

    $job = new ParseCorporateDocumentJob($doc);

    expect($job->tries)->toBe(2);
});

it('has timeout of 120 seconds', function () {
    $doc = CorporateDocument::factory()->for($this->corporation)->processing()->create();

    $job = new ParseCorporateDocumentJob($doc);

    expect($job->timeout)->toBe(120);
});
