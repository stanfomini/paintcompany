<?php

use App\Models\CompanySetting;
use App\Models\CorporateDocument;
use App\Models\Corporation;
use App\Models\CorporationOwner;
use App\Models\MeetingMinutes;
use App\Models\User;

beforeEach(function () {
    $this->settings = CompanySetting::firstOrCreate([], ['company_name' => 'Test Company']);
});

// -- Corporation Model --

it('creates a corporation with factory defaults', function () {
    $corp = Corporation::factory()->create(['company_setting_id' => $this->settings->id]);

    expect($corp)
        ->name->not->toBeEmpty()
        ->entity_type->toBe('LLC')
        ->is_active->toBeTrue()
        ->formation_date->toBeInstanceOf(\Carbon\CarbonInterface::class);
});

it('has ENTITY_TYPES constant with all types', function () {
    expect(Corporation::ENTITY_TYPES)->toHaveKeys([
        'LLC', 'S-Corp', 'C-Corp', 'Sole Prop', 'Partnership',
    ]);
});

it('belongs to a company setting', function () {
    $corp = Corporation::factory()->create(['company_setting_id' => $this->settings->id]);

    expect($corp->companySetting)->toBeInstanceOf(CompanySetting::class)
        ->and($corp->companySetting->id)->toBe($this->settings->id);
});

it('has many owners ordered by sort_order', function () {
    $corp = Corporation::factory()->create(['company_setting_id' => $this->settings->id]);

    CorporationOwner::factory()->for($corp)->create(['sort_order' => 2, 'name' => 'Second']);
    CorporationOwner::factory()->for($corp)->create(['sort_order' => 1, 'name' => 'First']);

    $owners = $corp->owners;

    expect($owners)->toHaveCount(2)
        ->and($owners->first()->name)->toBe('First')
        ->and($owners->last()->name)->toBe('Second');
});

it('has many documents', function () {
    $corp = Corporation::factory()->create(['company_setting_id' => $this->settings->id]);
    CorporateDocument::factory()->count(3)->for($corp)->create();

    expect($corp->documents)->toHaveCount(3);
});

it('has many meeting minutes', function () {
    $corp = Corporation::factory()->create(['company_setting_id' => $this->settings->id]);
    MeetingMinutes::factory()->count(2)->for($corp)->create();

    expect($corp->meetingMinutes)->toHaveCount(2);
});

it('scopes to active corporations only', function () {
    Corporation::factory()->create(['company_setting_id' => $this->settings->id, 'is_active' => true]);
    Corporation::factory()->create(['company_setting_id' => $this->settings->id, 'is_active' => false]);

    expect(Corporation::active()->count())->toBe(1);
});

it('creates S-Corp via factory state', function () {
    $corp = Corporation::factory()->sCorp()->create(['company_setting_id' => $this->settings->id]);

    expect($corp->entity_type)->toBe('S-Corp')
        ->and($corp->name)->toContain('Inc.');
});

it('creates C-Corp via factory state', function () {
    $corp = Corporation::factory()->cCorp()->create(['company_setting_id' => $this->settings->id]);

    expect($corp->entity_type)->toBe('C-Corp')
        ->and($corp->name)->toContain('Corp.');
});

it('creates inactive corporation via factory state', function () {
    $corp = Corporation::factory()->inactive()->create(['company_setting_id' => $this->settings->id]);

    expect($corp->is_active)->toBeFalse();
});

it('creates corporation with owners via factory state', function () {
    $corp = Corporation::factory()->withOwners(3)->create(['company_setting_id' => $this->settings->id]);

    expect($corp->owners)->toHaveCount(3);
});

it('cascades delete to owners, documents, and meeting minutes', function () {
    $corp = Corporation::factory()->create(['company_setting_id' => $this->settings->id]);
    CorporationOwner::factory()->for($corp)->create();
    CorporateDocument::factory()->for($corp)->create();
    MeetingMinutes::factory()->for($corp)->create();

    $corp->delete();

    expect(CorporationOwner::count())->toBe(0)
        ->and(CorporateDocument::count())->toBe(0)
        ->and(MeetingMinutes::count())->toBe(0);
});

// -- CorporationOwner Model --

it('creates an owner with factory defaults', function () {
    $owner = CorporationOwner::factory()->create();

    expect($owner)
        ->name->not->toBeEmpty()
        ->percentage->toBe('50.00')
        ->role->toBe('Member');
});

it('has ROLES constant', function () {
    expect(CorporationOwner::ROLES)->toHaveKeys([
        'Member', 'Managing Member', 'President', 'VP', 'Secretary', 'Treasurer', 'Director',
    ]);
});

it('owner belongs to a corporation', function () {
    $corp = Corporation::factory()->create(['company_setting_id' => $this->settings->id]);
    $owner = CorporationOwner::factory()->for($corp)->create();

    expect($owner->corporation)->toBeInstanceOf(Corporation::class)
        ->and($owner->corporation->id)->toBe($corp->id);
});

it('scopes ordered by sort_order', function () {
    $corp = Corporation::factory()->create(['company_setting_id' => $this->settings->id]);
    CorporationOwner::factory()->for($corp)->create(['sort_order' => 3]);
    CorporationOwner::factory()->for($corp)->create(['sort_order' => 1]);

    $ordered = CorporationOwner::ordered()->get();

    expect($ordered->first()->sort_order)->toBe(1)
        ->and($ordered->last()->sort_order)->toBe(3);
});

it('creates managing member via factory state', function () {
    $owner = CorporationOwner::factory()->managingMember()->create();
    expect($owner->role)->toBe('Managing Member');
});

it('creates president via factory state', function () {
    $owner = CorporationOwner::factory()->president()->create();
    expect($owner->role)->toBe('President');
});

// -- CorporateDocument Model --

it('creates a document with factory defaults', function () {
    $doc = CorporateDocument::factory()->create();

    expect($doc)
        ->title->not->toBeEmpty()
        ->type->toBe('operating_agreement')
        ->status->toBe('ready')
        ->version->toBe(1);
});

it('has TYPES constant', function () {
    expect(CorporateDocument::TYPES)->toHaveKeys([
        'operating_agreement', 'articles_of_incorporation', 'bylaws',
        'resolution', 'amendment', 'annual_report', 'other',
    ]);
});

it('has STATUSES constant', function () {
    expect(CorporateDocument::STATUSES)->toHaveKeys([
        'processing', 'ready', 'error',
    ]);
});

it('document belongs to a corporation', function () {
    $corp = Corporation::factory()->create(['company_setting_id' => $this->settings->id]);
    $doc = CorporateDocument::factory()->for($corp)->create();

    expect($doc->corporation->id)->toBe($corp->id);
});

it('belongs to an uploader', function () {
    $user = User::factory()->internal()->create();
    $doc = CorporateDocument::factory()->create(['uploaded_by' => $user->id]);

    expect($doc->uploader)->toBeInstanceOf(User::class)
        ->and($doc->uploader->id)->toBe($user->id);
});

it('supports self-referential parent/child relationships', function () {
    $corp = Corporation::factory()->create(['company_setting_id' => $this->settings->id]);
    $parent = CorporateDocument::factory()->for($corp)->create();
    $child = CorporateDocument::factory()->for($corp)->create(['parent_document_id' => $parent->id, 'version' => 2]);

    expect($child->parent->id)->toBe($parent->id)
        ->and($parent->children)->toHaveCount(1)
        ->and($parent->children->first()->id)->toBe($child->id);
});

it('returns edited_content over parsed_content for export', function () {
    $doc = CorporateDocument::factory()->create([
        'parsed_content' => ['sections' => [['title' => 'Original']]],
        'edited_content' => ['sections' => [['title' => 'Edited']]],
    ]);

    expect($doc->getContentForExport()['sections'][0]['title'])->toBe('Edited');
});

it('returns parsed_content when no edits exist', function () {
    $doc = CorporateDocument::factory()->create([
        'parsed_content' => ['sections' => [['title' => 'Parsed']]],
        'edited_content' => null,
    ]);

    expect($doc->getContentForExport()['sections'][0]['title'])->toBe('Parsed');
});

it('returns null when no content exists', function () {
    $doc = CorporateDocument::factory()->create([
        'parsed_content' => null,
        'edited_content' => null,
    ]);

    expect($doc->getContentForExport())->toBeNull();
});

it('creates processing document via factory state', function () {
    $doc = CorporateDocument::factory()->processing()->create();
    expect($doc->status)->toBe('processing');
});

it('creates error document via factory state', function () {
    $doc = CorporateDocument::factory()->error()->create();

    expect($doc->status)->toBe('error')
        ->and($doc->parse_error)->not->toBeEmpty();
});

it('creates document with parsed content via factory state', function () {
    $doc = CorporateDocument::factory()->withParsedContent()->create();

    expect($doc->parsed_content)->toBeArray()
        ->and($doc->parsed_content)->toHaveKey('sections')
        ->and($doc->parsed_content['sections'])->toHaveCount(2);
});

// -- MeetingMinutes Model --

it('creates meeting minutes with factory defaults', function () {
    $meeting = MeetingMinutes::factory()->create();

    expect($meeting)
        ->title->not->toBeEmpty()
        ->meeting_type->toBe('annual')
        ->summary_status->toBe('pending')
        ->meeting_date->toBeInstanceOf(\Carbon\CarbonInterface::class);
});

it('has MEETING_TYPES constant', function () {
    expect(MeetingMinutes::MEETING_TYPES)->toHaveKeys([
        'annual', 'special', 'board', 'emergency',
    ]);
});

it('has SUMMARY_STATUSES constant', function () {
    expect(MeetingMinutes::SUMMARY_STATUSES)->toHaveKeys([
        'pending', 'processing', 'completed', 'failed',
    ]);
});

it('meeting minutes belongs to a corporation', function () {
    $corp = Corporation::factory()->create(['company_setting_id' => $this->settings->id]);
    $meeting = MeetingMinutes::factory()->for($corp)->create();

    expect($meeting->corporation->id)->toBe($corp->id);
});

it('belongs to a recorder user', function () {
    $user = User::factory()->internal()->create();
    $meeting = MeetingMinutes::factory()->create(['recorded_by' => $user->id]);

    expect($meeting->recorder)->toBeInstanceOf(User::class)
        ->and($meeting->recorder->id)->toBe($user->id);
});

it('creates meeting with audio via factory state', function () {
    $meeting = MeetingMinutes::factory()->withAudio()->create();

    expect($meeting->audio_path)->not->toBeEmpty()
        ->and($meeting->audio_duration)->toBeGreaterThan(0);
});

it('creates meeting with transcript via factory state', function () {
    $meeting = MeetingMinutes::factory()->withTranscript()->create();

    expect($meeting->transcript)->not->toBeEmpty()
        ->and($meeting->transcript_segments)->toBeArray()
        ->and($meeting->transcript_segments)->toHaveCount(3);
});

it('creates meeting with summary via factory state', function () {
    $meeting = MeetingMinutes::factory()->withSummary()->create();

    expect($meeting->executive_summary)->not->toBeEmpty()
        ->and($meeting->summary_status)->toBe('completed');
});

it('nullifies recorded_by when user is deleted', function () {
    $user = User::factory()->internal()->create();
    $meeting = MeetingMinutes::factory()->create(['recorded_by' => $user->id]);

    $user->delete();
    $meeting->refresh();

    expect($meeting->recorded_by)->toBeNull();
});

it('nullifies uploaded_by when user is deleted on documents', function () {
    $user = User::factory()->internal()->create();
    $doc = CorporateDocument::factory()->create(['uploaded_by' => $user->id]);

    $user->delete();
    $doc->refresh();

    expect($doc->uploaded_by)->toBeNull();
});
