<?php

use App\Models\CompanySetting;
use App\Models\CorporateDocument;
use App\Models\Corporation;
use App\Models\User;
use App\Services\CorporateDocumentService;
use App\Services\PythonBridgeService;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
    $this->settings = CompanySetting::firstOrCreate([], ['company_name' => 'Test Company']);
    $this->corporation = Corporation::factory()->create(['company_setting_id' => $this->settings->id]);
    $this->user = User::factory()->internal()->create();

    $pdfPath = "corporate-documents/{$this->corporation->id}/test.pdf";
    Storage::put($pdfPath, '%PDF-1.4 fake');

    $this->document = CorporateDocument::factory()->for($this->corporation)->create([
        'type' => 'bylaws',
        'status' => 'ready',
        'version' => 1,
        'original_file_path' => $pdfPath,
        'parsed_content' => [['type' => 'paragraph', 'text' => 'Test content']],
    ]);
});

it('creates a new version when applying signatures', function () {
    $mock = Mockery::mock(PythonBridgeService::class);
    $mock->shouldReceive('isEnabled')->andReturn(true);
    $mock->shouldReceive('applySignaturesToPdf')
        ->once()
        ->andReturn('%PDF-1.4 signed content');
    app()->instance(PythonBridgeService::class, $mock);

    $placements = [
        ['type' => 'signature', 'page' => 1, 'x' => 0.15, 'y' => 0.85, 'width' => 0.25],
    ];

    $service = app(CorporateDocumentService::class);
    $newVersion = $service->applySignatures(
        $this->document,
        $placements,
        'data:image/png;base64,abc123',
        'drawn',
        'Matt Stanford',
    );

    expect($newVersion->version)->toBe(2);
    expect($newVersion->parent_document_id)->toBe($this->document->id);
    expect($newVersion->corporation_id)->toBe($this->document->corporation_id);
    Storage::assertExists($newVersion->original_file_path);
});

it('passes correct arguments to python bridge', function () {
    $placements = [
        ['type' => 'signature', 'page' => 1, 'x' => 0.15, 'y' => 0.85, 'width' => 0.25],
        ['type' => 'initials', 'page' => 2, 'x' => 0.80, 'y' => 0.92, 'width' => 0.08],
        ['type' => 'date', 'page' => 1, 'x' => 0.55, 'y' => 0.87, 'width' => 0.15],
    ];

    $mock = Mockery::mock(PythonBridgeService::class);
    $mock->shouldReceive('isEnabled')->andReturn(true);
    $mock->shouldReceive('applySignaturesToPdf')
        ->once()
        ->withArgs(function ($filePath, $p, $sigData, $sigType, $name) use ($placements) {
            return $p === $placements
                && $sigData === 'typed-sig'
                && $sigType === 'typed'
                && $name === 'Matt Stanford';
        })
        ->andReturn('%PDF-1.4 signed');
    app()->instance(PythonBridgeService::class, $mock);

    $service = app(CorporateDocumentService::class);
    $service->applySignatures(
        $this->document,
        $placements,
        'typed-sig',
        'typed',
        'Matt Stanford',
    );
});

it('throws when python service is disabled', function () {
    $mock = Mockery::mock(PythonBridgeService::class);
    $mock->shouldReceive('isEnabled')->andReturn(false);
    app()->instance(PythonBridgeService::class, $mock);

    $service = app(CorporateDocumentService::class);
    $service->applySignatures($this->document, [['type' => 'signature', 'page' => 1, 'x' => 0.1, 'y' => 0.9, 'width' => 0.2]]);
})->throws(\RuntimeException::class, 'Python service is not enabled');

it('throws when python service fails', function () {
    $mock = Mockery::mock(PythonBridgeService::class);
    $mock->shouldReceive('isEnabled')->andReturn(true);
    $mock->shouldReceive('applySignaturesToPdf')->andReturn(null);
    app()->instance(PythonBridgeService::class, $mock);

    $service = app(CorporateDocumentService::class);
    $service->applySignatures($this->document, [['type' => 'signature', 'page' => 1, 'x' => 0.1, 'y' => 0.9, 'width' => 0.2]]);
})->throws(\RuntimeException::class, 'Python service failed');

it('throws when document PDF does not exist in storage', function () {
    $docNoPdf = CorporateDocument::factory()->for($this->corporation)->create([
        'type' => 'bylaws',
        'status' => 'ready',
        'original_file_path' => 'corporate-documents/nonexistent.pdf',
    ]);

    $service = app(CorporateDocumentService::class);
    $service->applySignatures($docNoPdf, [['type' => 'signature', 'page' => 1, 'x' => 0.1, 'y' => 0.9, 'width' => 0.2]]);
})->throws(\RuntimeException::class, 'no PDF file');

it('preserves edited content in signed version', function () {
    $editedDoc = CorporateDocument::factory()->for($this->corporation)->create([
        'type' => 'bylaws',
        'status' => 'ready',
        'version' => 2,
        'parent_document_id' => $this->document->id,
        'original_file_path' => $this->document->original_file_path,
        'edited_content' => [['type' => 'paragraph', 'text' => 'Edited content']],
    ]);

    $mock = Mockery::mock(PythonBridgeService::class);
    $mock->shouldReceive('isEnabled')->andReturn(true);
    $mock->shouldReceive('applySignaturesToPdf')->andReturn('%PDF-1.4 signed');
    app()->instance(PythonBridgeService::class, $mock);

    $service = app(CorporateDocumentService::class);
    $newVersion = $service->applySignatures($editedDoc, [
        ['type' => 'signature', 'page' => 1, 'x' => 0.1, 'y' => 0.9, 'width' => 0.2],
    ]);

    expect($newVersion->version)->toBe(3);
    expect($newVersion->parent_document_id)->toBe($this->document->id);
    expect($newVersion->edited_content)->toBe([['type' => 'paragraph', 'text' => 'Edited content']]);
});
