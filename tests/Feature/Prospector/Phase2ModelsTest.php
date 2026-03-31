<?php

use App\Models\Prospector\CallScript;
use App\Models\Prospector\Campaign;
use App\Models\Prospector\Company;
use App\Models\Prospector\CompanyBookingLink;
use App\Models\Prospector\Contact;
use App\Models\Prospector\ContactNote;
use App\Models\Prospector\Pipeline;
use App\Models\Prospector\PipelineStage;
use App\Models\Prospector\TwilioAccount;
use App\Models\User;

beforeEach(function () {
    $this->account = TwilioAccount::factory()->create();
    $this->company = Company::factory()->forAccount($this->account)->create();
});

// ──────────────────────────────────────
// Pipeline
// ──────────────────────────────────────

it('creates a pipeline with factory', function () {
    $pipeline = Pipeline::factory()->create();

    expect($pipeline)->toBeInstanceOf(Pipeline::class);
    expect($pipeline->id)->toBeGreaterThan(0);
    expect($pipeline->name)->toBeString();
});

it('pipeline has stages relation', function () {
    $pipeline = Pipeline::factory()->create();
    $stage = PipelineStage::factory()->forPipeline($pipeline)->create();

    expect($pipeline->stages)->toHaveCount(1);
    expect($pipeline->stages->first()->id)->toBe($stage->id);
});

it('pipeline has contacts through stages', function () {
    $pipeline = Pipeline::factory()->create();
    $stage = PipelineStage::factory()->forPipeline($pipeline)->create();
    $contact = Contact::factory()->forCompany($this->company)->create([
        'pipeline_stage_id' => $stage->id,
    ]);

    expect($pipeline->contacts)->toHaveCount(1);
    expect($pipeline->contacts->first()->id)->toBe($contact->id);
});

it('pipeline scopes to default', function () {
    Pipeline::factory()->create(['is_default' => false]);
    $default = Pipeline::factory()->default()->create();

    expect(Pipeline::default()->count())->toBe(1);
    expect(Pipeline::default()->first()->id)->toBe($default->id);
});

// ──────────────────────────────────────
// PipelineStage
// ──────────────────────────────────────

it('creates a pipeline stage with factory', function () {
    $stage = PipelineStage::factory()->create();

    expect($stage)->toBeInstanceOf(PipelineStage::class);
    expect($stage->id)->toBeGreaterThan(0);
});

it('pipeline stage belongs to pipeline', function () {
    $pipeline = Pipeline::factory()->create();
    $stage = PipelineStage::factory()->forPipeline($pipeline)->create();

    expect($stage->pipeline)->toBeInstanceOf(Pipeline::class);
    expect($stage->pipeline->id)->toBe($pipeline->id);
});

it('pipeline stage has contacts', function () {
    $stage = PipelineStage::factory()->create();
    $contact = Contact::factory()->forCompany($this->company)->create([
        'pipeline_stage_id' => $stage->id,
    ]);

    expect($stage->contacts)->toHaveCount(1);
    expect($stage->contacts->first()->id)->toBe($contact->id);
});

// ──────────────────────────────────────
// CompanyBookingLink
// ──────────────────────────────────────

it('creates a company booking link with factory', function () {
    $link = CompanyBookingLink::factory()->forCompany($this->company)->create();

    expect($link)->toBeInstanceOf(CompanyBookingLink::class);
    expect($link->company_id)->toBe($this->company->id);
    expect($link->name)->toBeString();
    expect($link->url)->toBeString();
});

it('booking link belongs to company', function () {
    $link = CompanyBookingLink::factory()->forCompany($this->company)->create();

    expect($link->company)->toBeInstanceOf(Company::class);
    expect($link->company->id)->toBe($this->company->id);
});

it('company has many booking links', function () {
    CompanyBookingLink::factory()->count(3)->forCompany($this->company)->create();

    expect($this->company->bookingLinks)->toHaveCount(3);
});

// ──────────────────────────────────────
// ContactNote
// ──────────────────────────────────────

it('creates a contact note with factory', function () {
    $contact = Contact::factory()->forCompany($this->company)->create();
    $user = User::factory()->create();
    $note = ContactNote::factory()->forContact($contact)->byUser($user)->create();

    expect($note)->toBeInstanceOf(ContactNote::class);
    expect($note->contact_id)->toBe($contact->id);
    expect($note->user_id)->toBe($user->id);
    expect($note->body)->toBeString();
});

it('contact note belongs to contact', function () {
    $contact = Contact::factory()->forCompany($this->company)->create();
    $note = ContactNote::factory()->forContact($contact)->create();

    expect($note->contact)->toBeInstanceOf(Contact::class);
    expect($note->contact->id)->toBe($contact->id);
});

it('contact note belongs to user', function () {
    $user = User::factory()->create();
    $note = ContactNote::factory()->byUser($user)->create();

    expect($note->user)->toBeInstanceOf(User::class);
    expect($note->user->id)->toBe($user->id);
});

it('contact has many notes', function () {
    $contact = Contact::factory()->forCompany($this->company)->create();
    ContactNote::factory()->count(3)->forContact($contact)->create();

    expect($contact->notes)->toHaveCount(3);
});

// ──────────────────────────────────────
// CallScript
// ──────────────────────────────────────

it('creates a call script with factory', function () {
    $script = CallScript::factory()->forCompany($this->company)->create();

    expect($script)->toBeInstanceOf(CallScript::class);
    expect($script->company_id)->toBe($this->company->id);
    expect($script->name)->toBeString();
    expect($script->body)->toBeString();
    expect($script->is_active)->toBeTrue();
});

it('call script belongs to company', function () {
    $script = CallScript::factory()->forCompany($this->company)->create();

    expect($script->company)->toBeInstanceOf(Company::class);
    expect($script->company->id)->toBe($this->company->id);
});

it('call script belongs to campaign', function () {
    $campaign = Campaign::factory()->create(['company_id' => $this->company->id]);
    $script = CallScript::factory()->forCompany($this->company)->forCampaign($campaign)->create();

    expect($script->campaign)->toBeInstanceOf(Campaign::class);
    expect($script->campaign->id)->toBe($campaign->id);
});

it('company has many call scripts', function () {
    CallScript::factory()->count(2)->forCompany($this->company)->create();

    expect($this->company->callScripts)->toHaveCount(2);
});

it('campaign has many call scripts', function () {
    $campaign = Campaign::factory()->create(['company_id' => $this->company->id]);
    CallScript::factory()->count(2)->forCompany($this->company)->forCampaign($campaign)->create();

    expect($campaign->callScripts)->toHaveCount(2);
});

it('scopes call scripts to active', function () {
    CallScript::factory()->forCompany($this->company)->create(['is_active' => true]);
    CallScript::factory()->inactive()->forCompany($this->company)->create();

    expect(CallScript::active()->count())->toBe(1);
});

it('scopes call scripts for campaign', function () {
    $campaign = Campaign::factory()->create(['company_id' => $this->company->id]);
    CallScript::factory()->forCompany($this->company)->forCampaign($campaign)->create();
    CallScript::factory()->forCompany($this->company)->create();

    expect(CallScript::forCampaign($campaign->id)->count())->toBe(1);
});

it('creates a global call script', function () {
    $script = CallScript::factory()->global()->create();

    expect($script->company_id)->toBeNull();
    expect($script->company)->toBeNull();
});

// ──────────────────────────────────────
// Contact modifications
// ──────────────────────────────────────

it('contact belongs to pipeline stage', function () {
    $stage = PipelineStage::factory()->create();
    $contact = Contact::factory()->forCompany($this->company)->create([
        'pipeline_stage_id' => $stage->id,
    ]);

    expect($contact->pipelineStage)->toBeInstanceOf(PipelineStage::class);
    expect($contact->pipelineStage->id)->toBe($stage->id);
});

it('contact casts follow_up_at as datetime', function () {
    $contact = Contact::factory()->forCompany($this->company)->create([
        'follow_up_at' => '2026-04-01 10:00:00',
    ]);

    expect($contact->follow_up_at)->toBeInstanceOf(\Carbon\CarbonInterface::class);
    expect($contact->follow_up_at->format('Y-m-d'))->toBe('2026-04-01');
});

it('contact wasContactedToday returns true when contacted today', function () {
    $contact = Contact::factory()->forCompany($this->company)->create([
        'last_contacted_at' => now(),
    ]);

    expect($contact->wasContactedToday())->toBeTrue();
});

it('contact wasContactedToday returns false when contacted yesterday', function () {
    $contact = Contact::factory()->forCompany($this->company)->create([
        'last_contacted_at' => now()->subDay(),
    ]);

    expect($contact->wasContactedToday())->toBeFalse();
});

it('contact wasContactedToday returns false when never contacted', function () {
    $contact = Contact::factory()->forCompany($this->company)->create([
        'last_contacted_at' => null,
    ]);

    expect($contact->wasContactedToday())->toBeFalse();
});

// ──────────────────────────────────────
// Company modifications
// ──────────────────────────────────────

it('company has default campaign relation', function () {
    $campaign = Campaign::factory()->create(['company_id' => $this->company->id]);
    $this->company->update(['default_campaign_id' => $campaign->id]);

    expect($this->company->fresh()->defaultCampaign)->toBeInstanceOf(Campaign::class);
    expect($this->company->fresh()->defaultCampaign->id)->toBe($campaign->id);
});

it('company stores notes', function () {
    $this->company->update(['notes' => 'Important company notes']);

    expect($this->company->fresh()->notes)->toBe('Important company notes');
});
