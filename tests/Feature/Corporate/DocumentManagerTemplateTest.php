<?php

use App\Models\CorporateDocument;
use App\Models\Corporation;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake();
    $this->user = User::factory()->internal()->create();
    $this->user->givePermissionTo(['business_plan.view', 'business_plan.manage']);
    $this->actingAs($this->user);

    $this->corporation = Corporation::factory()->create(['is_active' => true]);
});

it('shows template options in dropdown for managers', function () {
    Livewire::test('corporate.document-manager', ['corporationId' => $this->corporation->id])
        ->assertStatus(200)
        ->assertSee('Add Document')
        ->assertSee('Upload PDF')
        ->assertSee('Board Resolution to Issue Shares')
        ->assertSee('Stock Ledger');
});

it('hides template options for non-managers', function () {
    $viewer = User::factory()->internal()->create();
    $viewer->givePermissionTo('business_plan.view');
    $this->actingAs($viewer);

    Livewire::test('corporate.document-manager', ['corporationId' => $this->corporation->id])
        ->assertStatus(200)
        ->assertDontSee('Add Document')
        ->assertDontSee('Upload PDF');
});

it('shows board resolution template form', function () {
    Livewire::test('corporate.document-manager', ['corporationId' => $this->corporation->id])
        ->call('showTemplate', 'board_resolution')
        ->assertSet('showTemplateForm', true)
        ->assertSet('templateType', 'board_resolution')
        ->assertSee('Director Name')
        ->assertSee('Resolution Date')
        ->assertSee('Shareholder Name')
        ->assertSee('Number of Shares');
});

it('shows stock ledger template form', function () {
    Livewire::test('corporate.document-manager', ['corporationId' => $this->corporation->id])
        ->call('showTemplate', 'stock_ledger')
        ->assertSet('showTemplateForm', true)
        ->assertSet('templateType', 'stock_ledger')
        ->assertSee('Authorized Shares')
        ->assertSee('Date Issued')
        ->assertSee('Shares Issued');
});

it('generates board resolution end-to-end', function () {
    Livewire::test('corporate.document-manager', ['corporationId' => $this->corporation->id])
        ->call('showTemplate', 'board_resolution')
        ->set('tplDirectorName', 'Matt Stanford')
        ->set('tplResolutionDate', 'March 3, 2026')
        ->set('tplShareholderName', 'Quick Technologies')
        ->set('tplShareholderEntityType', 'Wyoming C-Corp')
        ->set('tplShareholderEin', '39-3345899')
        ->set('tplShareClass', 'Common')
        ->set('tplShareCount', 2000000)
        ->set('tplConsideration', '$2,000 cash')
        ->call('generateFromTemplate')
        ->assertSet('showTemplateForm', false)
        ->assertHasNoErrors();

    $doc = CorporateDocument::where('corporation_id', $this->corporation->id)
        ->where('type', 'board_resolution')
        ->first();

    expect($doc)
        ->not->toBeNull()
        ->source->toBe('template')
        ->status->toBe('ready')
        ->template_data->toBeArray();

    expect($doc->template_data['director_name'])->toBe('Matt Stanford');
    expect($doc->template_data['shareholder_name'])->toBe('Quick Technologies');
    expect($doc->template_data['share_count'])->toBe(2000000);

    Storage::assertExists($doc->original_file_path);
});

it('generates stock ledger end-to-end', function () {
    Livewire::test('corporate.document-manager', ['corporationId' => $this->corporation->id])
        ->call('showTemplate', 'stock_ledger')
        ->set('tplAuthorizedShares', 5000000)
        ->set('tplLedgerShareClass', 'Common')
        ->set('tplDateIssued', 'March 3, 2026')
        ->set('tplLedgerShareholderName', 'Quick Technologies')
        ->set('tplShareholderAddress', '1500 N Grant St, Denver, CO')
        ->set('tplSharesIssued', 2000000)
        ->set('tplLedgerConsideration', '$2,000 cash')
        ->call('generateFromTemplate')
        ->assertSet('showTemplateForm', false)
        ->assertHasNoErrors();

    $doc = CorporateDocument::where('corporation_id', $this->corporation->id)
        ->where('type', 'stock_ledger')
        ->first();

    expect($doc)
        ->not->toBeNull()
        ->source->toBe('template')
        ->status->toBe('ready');

    expect($doc->template_data['entries'])->toHaveCount(1);
    expect($doc->template_data['authorized_shares'])->toHaveCount(1);

    Storage::assertExists($doc->original_file_path);
});

it('validates required fields for board resolution', function () {
    Livewire::test('corporate.document-manager', ['corporationId' => $this->corporation->id])
        ->call('showTemplate', 'board_resolution')
        ->call('generateFromTemplate')
        ->assertHasErrors(['tplDirectorName', 'tplResolutionDate', 'tplShareholderName', 'tplConsideration']);
});

it('validates required fields for stock ledger', function () {
    Livewire::test('corporate.document-manager', ['corporationId' => $this->corporation->id])
        ->call('showTemplate', 'stock_ledger')
        ->call('generateFromTemplate')
        ->assertHasErrors(['tplDateIssued', 'tplLedgerShareholderName', 'tplLedgerConsideration', 'tplAuthorizedShares']);
});

it('shows generated badge for template documents', function () {
    CorporateDocument::factory()
        ->for($this->corporation)
        ->fromTemplate('board_resolution')
        ->create(['title' => 'Test Board Resolution']);

    Livewire::test('corporate.document-manager', ['corporationId' => $this->corporation->id])
        ->assertSee('Test Board Resolution')
        ->assertSee('Generated');
});

it('cancels template form', function () {
    Livewire::test('corporate.document-manager', ['corporationId' => $this->corporation->id])
        ->call('showTemplate', 'board_resolution')
        ->assertSet('showTemplateForm', true)
        ->call('cancelTemplate')
        ->assertSet('showTemplateForm', false)
        ->assertSet('templateType', '');
});

it('prevents non-managers from generating templates', function () {
    $viewer = User::factory()->internal()->create();
    $viewer->givePermissionTo('business_plan.view');
    $this->actingAs($viewer);

    Livewire::test('corporate.document-manager', ['corporationId' => $this->corporation->id])
        ->call('showTemplate', 'board_resolution')
        ->set('tplDirectorName', 'Test')
        ->set('tplResolutionDate', 'March 3, 2026')
        ->set('tplShareholderName', 'Test Corp')
        ->set('tplShareClass', 'Common')
        ->set('tplShareCount', 1000)
        ->set('tplConsideration', '$100')
        ->call('generateFromTemplate')
        ->assertForbidden();
});
