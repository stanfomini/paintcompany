<?php

use App\Models\CompanySetting;
use App\Models\CorporateDocument;
use App\Models\Corporation;
use App\Models\User;
use App\Services\PythonBridgeService;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake();
    $this->settings = CompanySetting::firstOrCreate([], ['company_name' => 'Test Company']);
    $this->corporation = Corporation::factory()->create(['company_setting_id' => $this->settings->id]);
    $this->user = User::factory()->internal()->create();
    $this->user->givePermissionTo(['business_plan.view', 'business_plan.manage']);
    $this->actingAs($this->user);

    // Set up user signature
    $this->user->internalUser->update([
        'signature_data' => 'data:image/png;base64,iVBORw0KGgo=',
        'signature_type' => 'drawn',
        'signature_updated_at' => now(),
    ]);

    // Create document with fake PDF
    $pdfPath = "corporate-documents/{$this->corporation->id}/test.pdf";
    Storage::put($pdfPath, '%PDF-1.4 fake');

    $this->document = CorporateDocument::factory()->for($this->corporation)->create([
        'type' => 'bylaws',
        'status' => 'ready',
        'version' => 1,
        'original_file_path' => $pdfPath,
        'parsed_content' => [['type' => 'paragraph', 'text' => 'Test']],
    ]);
});

it('shows sign document button for managers with signature', function () {
    Livewire::test('corporate.document-editor', ['documentId' => $this->document->id])
        ->assertSee('Sign Document');
});

it('hides sign document button for non-managers', function () {
    $viewer = User::factory()->internal()->create();
    $viewer->givePermissionTo('business_plan.view');
    $this->actingAs($viewer);

    Livewire::test('corporate.document-editor', ['documentId' => $this->document->id])
        ->assertDontSee('Sign Document');
});

it('shows sign document button even without stored signature', function () {
    $this->user->internalUser->update([
        'signature_data' => null,
        'signature_type' => null,
    ]);

    Livewire::test('corporate.document-editor', ['documentId' => $this->document->id])
        ->assertSee('Sign Document');
});

it('enters signing mode', function () {
    Livewire::test('corporate.document-editor', ['documentId' => $this->document->id])
        ->call('enterSigningMode')
        ->assertSet('signingMode', true)
        ->assertSet('activeTab', 'signing');
});

it('exits signing mode', function () {
    Livewire::test('corporate.document-editor', ['documentId' => $this->document->id])
        ->call('enterSigningMode')
        ->call('exitSigningMode')
        ->assertSet('signingMode', false)
        ->assertSet('activeTab', 'original');
});

it('saves signatures and creates new version', function () {
    $mock = Mockery::mock(PythonBridgeService::class);
    $mock->shouldReceive('isEnabled')->andReturn(true);
    $mock->shouldReceive('applySignaturesToPdf')->andReturn('%PDF-1.4 signed');
    app()->instance(PythonBridgeService::class, $mock);

    $placements = [
        ['type' => 'signature', 'page' => 1, 'x' => 0.15, 'y' => 0.85, 'width' => 0.25],
    ];

    Livewire::test('corporate.document-editor', ['documentId' => $this->document->id])
        ->call('enterSigningMode')
        ->call('saveSignatures', $placements, 'Matt Stanford', 'typed', 'Matt Stanford, Director')
        ->assertSet('signingMode', false);

    $newVersion = CorporateDocument::where('parent_document_id', $this->document->id)->first();
    expect($newVersion)->not->toBeNull();
    expect($newVersion->version)->toBe(2);
});

it('does nothing when saving empty placements', function () {
    Livewire::test('corporate.document-editor', ['documentId' => $this->document->id])
        ->call('enterSigningMode')
        ->call('saveSignatures', []);

    expect(CorporateDocument::where('parent_document_id', $this->document->id)->count())->toBe(0);
});

it('prevents non-managers from entering signing mode', function () {
    $viewer = User::factory()->internal()->create();
    $viewer->givePermissionTo('business_plan.view');
    $viewer->internalUser->update(['signature_data' => 'test', 'signature_type' => 'typed']);
    $this->actingAs($viewer);

    Livewire::test('corporate.document-editor', ['documentId' => $this->document->id])
        ->call('enterSigningMode')
        ->assertForbidden();
});

it('derives initials correctly', function () {
    $component = Livewire::test('corporate.document-editor', ['documentId' => $this->document->id])
        ->call('enterSigningMode');

    $preview = $component->get('signaturePreview');
    expect($preview)->toHaveKey('initials');
    expect(strlen($preview['initials']))->toBeGreaterThanOrEqual(1);
    expect(strlen($preview['initials']))->toBeLessThanOrEqual(2);
});
