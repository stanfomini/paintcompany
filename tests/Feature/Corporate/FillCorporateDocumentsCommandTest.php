<?php

use App\Models\CompanySetting;
use App\Models\CorporateDocument;
use App\Models\Corporation;
use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake();
    $this->settings = CompanySetting::firstOrCreate([], ['company_name' => 'Test Company']);
    $this->corporation = Corporation::factory()->create(['company_setting_id' => $this->settings->id]);
    $this->user = User::factory()->internal()->create();
});

it('fills bylaws sections correctly', function () {
    $doc = CorporateDocument::factory()->for($this->corporation)->create([
        'type' => 'bylaws',
        'status' => 'ready',
        'parsed_content' => [
            ['type' => 'heading', 'level' => 1, 'text' => '______________________________________________________________'],
            ['type' => 'paragraph', 'text' => 'Incorporated under the laws of the State of ________'],
            ['type' => 'paragraph', 'text' => '____________________________________________ Director'],
        ],
    ]);

    $this->artisan('corporate:fill-documents', ['--document' => $doc->id])
        ->assertExitCode(0);

    $v2 = CorporateDocument::where('parent_document_id', $doc->id)->first();

    expect($v2)->not->toBeNull();

    $sections = $v2->edited_content;

    expect($sections[0]['text'])->toBe('Painting Made Easy Denver Inc.');
    expect($sections[1]['text'])->toBe('Incorporated under the laws of the State of Colorado');
    expect($sections[2]['text'])->toBe('Matt Stanford, Director');
});

it('fills resolution sections correctly', function () {
    $doc = CorporateDocument::factory()->for($this->corporation)->create([
        'type' => 'resolution',
        'status' => 'ready',
        'parsed_content' => [
            ['type' => 'heading', 'level' => 1, 'text' => '______________________________________________________________'],
            ['type' => 'paragraph', 'text' => 'Incorporated in the State of ________'],
            ['type' => 'paragraph', 'text' => 'President: ____________________'],
            ['type' => 'paragraph', 'text' => 'Director: ____________________'],
            ['type' => 'paragraph', 'text' => 'Date: ____________________'],
        ],
    ]);

    $this->artisan('corporate:fill-documents', ['--document' => $doc->id])
        ->assertExitCode(0);

    $v2 = CorporateDocument::where('parent_document_id', $doc->id)->first();

    expect($v2)->not->toBeNull();

    $sections = $v2->edited_content;

    expect($sections[0]['text'])->toBe('Painting Made Easy Denver Inc.');
    expect($sections[1]['text'])->toBe('Incorporated in the State of Colorado');
    expect($sections[2]['text'])->toBe('President: Matt Stanford');
    expect($sections[3]['text'])->toBe('Director: Matt Stanford');
    expect($sections[4]['text'])->toBe('Date: March 3, 2026');
});

it('creates V2 versions', function () {
    $doc = CorporateDocument::factory()->for($this->corporation)->create([
        'type' => 'bylaws',
        'status' => 'ready',
        'version' => 1,
        'parsed_content' => [
            ['type' => 'paragraph', 'text' => 'State of ________'],
        ],
    ]);

    $this->artisan('corporate:fill-documents', ['--document' => $doc->id])
        ->assertExitCode(0);

    expect(CorporateDocument::where('parent_document_id', $doc->id)->count())->toBe(1);

    $v2 = CorporateDocument::where('parent_document_id', $doc->id)->first();

    expect($v2->version)->toBe(2);
    expect($v2->parent_document_id)->toBe($doc->id);
    expect($v2->corporation_id)->toBe($doc->corporation_id);
    expect($v2->type)->toBe('bylaws');
});

it('shows changes in dry run without saving', function () {
    $doc = CorporateDocument::factory()->for($this->corporation)->create([
        'type' => 'bylaws',
        'status' => 'ready',
        'parsed_content' => [
            ['type' => 'paragraph', 'text' => 'State of ________'],
            ['type' => 'paragraph', 'text' => '____________________________________________ Director'],
        ],
    ]);

    $this->artisan('corporate:fill-documents', ['--document' => $doc->id, '--dry-run' => true])
        ->assertExitCode(0)
        ->expectsOutputToContain('DRY RUN')
        ->expectsOutputToContain('State of Colorado')
        ->expectsOutputToContain('Matt Stanford, Director');

    expect(CorporateDocument::where('parent_document_id', $doc->id)->count())->toBe(0);
});

it('handles document with no blanks gracefully', function () {
    $doc = CorporateDocument::factory()->for($this->corporation)->create([
        'type' => 'bylaws',
        'status' => 'ready',
        'parsed_content' => [
            ['type' => 'heading', 'level' => 1, 'text' => 'Painting Made Easy Denver Inc.'],
            ['type' => 'paragraph', 'text' => 'Already filled content with no blanks.'],
        ],
    ]);

    $this->artisan('corporate:fill-documents', ['--document' => $doc->id])
        ->assertExitCode(0)
        ->expectsOutputToContain('No blanks found');

    expect(CorporateDocument::where('parent_document_id', $doc->id)->count())->toBe(0);
});

it('processes all fillable documents when no document option is given', function () {
    $bylawsDoc = CorporateDocument::factory()->for($this->corporation)->create([
        'type' => 'bylaws',
        'status' => 'ready',
        'parsed_content' => [
            ['type' => 'paragraph', 'text' => 'State of ________'],
        ],
    ]);

    $resolutionDoc = CorporateDocument::factory()->for($this->corporation)->create([
        'type' => 'resolution',
        'status' => 'ready',
        'parsed_content' => [
            ['type' => 'paragraph', 'text' => 'President: ____________________'],
        ],
    ]);

    $this->artisan('corporate:fill-documents')
        ->assertExitCode(0)
        ->expectsOutputToContain('Filled: 2');

    expect(CorporateDocument::where('parent_document_id', $bylawsDoc->id)->count())->toBe(1);
    expect(CorporateDocument::where('parent_document_id', $resolutionDoc->id)->count())->toBe(1);
});

it('skips documents that are not ready', function () {
    $doc = CorporateDocument::factory()->for($this->corporation)->create([
        'type' => 'bylaws',
        'status' => 'processing',
        'parsed_content' => [
            ['type' => 'paragraph', 'text' => 'State of ________'],
        ],
    ]);

    $this->artisan('corporate:fill-documents', ['--document' => $doc->id])
        ->assertExitCode(0)
        ->expectsOutputToContain('No fillable documents found');

    expect(CorporateDocument::where('parent_document_id', $doc->id)->count())->toBe(0);
});

it('fills remaining underscore blanks with corp name as fallback', function () {
    $doc = CorporateDocument::factory()->for($this->corporation)->create([
        'type' => 'bylaws',
        'status' => 'ready',
        'parsed_content' => [
            ['type' => 'paragraph', 'text' => 'Some unknown field: ________'],
        ],
    ]);

    $this->artisan('corporate:fill-documents', ['--document' => $doc->id])
        ->assertExitCode(0);

    $v2 = CorporateDocument::where('parent_document_id', $doc->id)->first();

    expect($v2)->not->toBeNull();
    expect($v2->edited_content[0]['text'])->not->toContain('____');
});
