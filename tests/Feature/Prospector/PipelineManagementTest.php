<?php

use App\Models\Prospector\CallScript;
use App\Models\Prospector\Campaign;
use App\Models\Prospector\Company;
use App\Models\Prospector\CompanyBookingLink;
use App\Models\Prospector\Pipeline;
use App\Models\Prospector\PipelineStage;
use App\Models\Prospector\TwilioAccount;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(['type' => 'internal']);
    $this->actingAs($this->user);

    $this->account = TwilioAccount::factory()->create();
    $this->company = Company::factory()->forAccount($this->account)->create();
});

// ──────────────────────────────────────
// Pipeline CRUD
// ──────────────────────────────────────

it('can create a pipeline', function () {
    Livewire::test('pages::prospector.pipelines')
        ->call('openCreatePipeline')
        ->set('pipelineName', 'Sales Pipeline')
        ->set('pipelineColor', '#ef4444')
        ->call('savePipeline');

    $this->assertDatabaseHas('prospector_pipelines', [
        'name' => 'Sales Pipeline',
        'color' => '#ef4444',
    ]);
});

it('can update a pipeline', function () {
    $pipeline = Pipeline::factory()->create(['name' => 'Old Name']);

    Livewire::test('pages::prospector.pipelines')
        ->call('openEditPipeline', $pipeline->id)
        ->set('pipelineName', 'New Name')
        ->call('savePipeline');

    expect($pipeline->fresh()->name)->toBe('New Name');
});

it('can delete a pipeline', function () {
    $pipeline = Pipeline::factory()->create();

    Livewire::test('pages::prospector.pipelines')
        ->call('confirmDeletePipeline', $pipeline->id)
        ->call('deletePipeline');

    $this->assertDatabaseMissing('prospector_pipelines', ['id' => $pipeline->id]);
});

it('can set a default pipeline', function () {
    $pipeline1 = Pipeline::factory()->default()->create();
    $pipeline2 = Pipeline::factory()->create(['is_default' => false]);

    Livewire::test('pages::prospector.pipelines')
        ->call('setDefault', $pipeline2->id);

    expect($pipeline1->fresh()->is_default)->toBeFalse();
    expect($pipeline2->fresh()->is_default)->toBeTrue();
});

// ──────────────────────────────────────
// Stage CRUD
// ──────────────────────────────────────

it('can create a stage', function () {
    $pipeline = Pipeline::factory()->create();

    Livewire::test('pages::prospector.pipelines')
        ->call('openCreateStage', $pipeline->id)
        ->set('stageName', 'New Lead')
        ->set('stageColor', '#22c55e')
        ->call('saveStage');

    $this->assertDatabaseHas('prospector_pipeline_stages', [
        'pipeline_id' => $pipeline->id,
        'name' => 'New Lead',
        'color' => '#22c55e',
    ]);
});

it('can update a stage', function () {
    $stage = PipelineStage::factory()->create(['name' => 'Old Stage']);

    Livewire::test('pages::prospector.pipelines')
        ->call('openEditStage', $stage->id)
        ->set('stageName', 'Contacted')
        ->call('saveStage');

    expect($stage->fresh()->name)->toBe('Contacted');
});

it('can delete a stage', function () {
    $stage = PipelineStage::factory()->create();

    Livewire::test('pages::prospector.pipelines')
        ->call('confirmDeleteStage', $stage->id)
        ->call('deleteStage');

    $this->assertDatabaseMissing('prospector_pipeline_stages', ['id' => $stage->id]);
});

it('can reorder stages', function () {
    $pipeline = Pipeline::factory()->create();
    $stage1 = PipelineStage::factory()->forPipeline($pipeline)->create(['sort_order' => 0]);
    $stage2 = PipelineStage::factory()->forPipeline($pipeline)->create(['sort_order' => 1]);
    $stage3 = PipelineStage::factory()->forPipeline($pipeline)->create(['sort_order' => 2]);

    Livewire::test('pages::prospector.pipelines')
        ->call('reorderStages', [$stage3->id, $stage1->id, $stage2->id]);

    expect($stage3->fresh()->sort_order)->toBe(0);
    expect($stage1->fresh()->sort_order)->toBe(1);
    expect($stage2->fresh()->sort_order)->toBe(2);
});

// ──────────────────────────────────────
// Pipeline View (Kanban)
// ──────────────────────────────────────

it('loads campaign list view', function () {
    $agent = \App\Models\Prospector\Agent::factory()->create([
        'user_id' => $this->user->id,
        'company_id' => $this->company->id,
    ]);
    $agent->companies()->attach($this->company->id);

    $campaign = Campaign::factory()->create([
        'company_id' => $this->company->id,
        'name' => 'New Leads',
        'status' => 'active',
    ]);

    Livewire::test('pages::prospector.pipeline-view')
        ->assertSee('New Leads');
});

// ──────────────────────────────────────
// Company Notes
// ──────────────────────────────────────

it('can save company notes', function () {
    Livewire::test('pages::prospector.company-detail', ['companyId' => $this->company->id])
        ->set('companyNotes', 'Important info about this company')
        ->call('saveNotes');

    expect($this->company->fresh()->notes)->toBe('Important info about this company');
});

it('can clear company notes', function () {
    $this->company->update(['notes' => 'Some old notes']);

    Livewire::test('pages::prospector.company-detail', ['companyId' => $this->company->id])
        ->set('companyNotes', '')
        ->call('saveNotes');

    expect($this->company->fresh()->notes)->toBeNull();
});

// ──────────────────────────────────────
// Booking Links
// ──────────────────────────────────────

it('can create a booking link', function () {
    Livewire::test('pages::prospector.company-detail', ['companyId' => $this->company->id])
        ->call('openCreateBookingLink')
        ->set('bookingLinkName', 'Calendly')
        ->set('bookingLinkUrl', 'https://calendly.com/example')
        ->call('saveBookingLink');

    $this->assertDatabaseHas('prospector_company_booking_links', [
        'company_id' => $this->company->id,
        'name' => 'Calendly',
        'url' => 'https://calendly.com/example',
    ]);
});

it('can update a booking link', function () {
    $link = CompanyBookingLink::factory()->forCompany($this->company)->create([
        'name' => 'Old Name',
        'url' => 'https://old.example.com',
    ]);

    Livewire::test('pages::prospector.company-detail', ['companyId' => $this->company->id])
        ->call('openEditBookingLink', $link->id)
        ->set('bookingLinkName', 'New Name')
        ->set('bookingLinkUrl', 'https://new.example.com')
        ->call('saveBookingLink');

    expect($link->fresh()->name)->toBe('New Name');
    expect($link->fresh()->url)->toBe('https://new.example.com');
});

it('can delete a booking link', function () {
    $link = CompanyBookingLink::factory()->forCompany($this->company)->create();

    Livewire::test('pages::prospector.company-detail', ['companyId' => $this->company->id])
        ->call('confirmDeleteBookingLink', $link->id)
        ->call('deleteBookingLink');

    $this->assertDatabaseMissing('prospector_company_booking_links', ['id' => $link->id]);
});

// ──────────────────────────────────────
// Webhook URL
// ──────────────────────────────────────

it('displays webhook URL with correct format', function () {
    Livewire::test('pages::prospector.company-detail', ['companyId' => $this->company->id])
        ->assertSee("/prospector/webhook/{$this->company->ulid}/leads");
});

// ──────────────────────────────────────
// Default Campaign
// ──────────────────────────────────────

it('can set default campaign', function () {
    $campaign = Campaign::factory()->create(['company_id' => $this->company->id]);

    Livewire::test('pages::prospector.company-detail', ['companyId' => $this->company->id])
        ->set('defaultCampaignId', $campaign->id)
        ->call('saveDefaultCampaign');

    expect($this->company->fresh()->default_campaign_id)->toBe($campaign->id);
});

it('can clear default campaign', function () {
    $campaign = Campaign::factory()->create(['company_id' => $this->company->id]);
    $this->company->update(['default_campaign_id' => $campaign->id]);

    Livewire::test('pages::prospector.company-detail', ['companyId' => $this->company->id])
        ->set('defaultCampaignId', null)
        ->call('saveDefaultCampaign');

    expect($this->company->fresh()->default_campaign_id)->toBeNull();
});

// ──────────────────────────────────────
// Call Scripts
// ──────────────────────────────────────

it('can create a call script', function () {
    Livewire::test('pages::prospector.company-detail', ['companyId' => $this->company->id])
        ->call('openCreateScript')
        ->set('scriptName', 'Cold Call Intro')
        ->set('scriptBody', 'Hello, my name is...')
        ->call('saveCallScript');

    $this->assertDatabaseHas('prospector_call_scripts', [
        'company_id' => $this->company->id,
        'name' => 'Cold Call Intro',
        'body' => 'Hello, my name is...',
        'is_active' => true,
    ]);
});

it('can update a call script', function () {
    $script = CallScript::factory()->forCompany($this->company)->create(['name' => 'Old Script']);

    Livewire::test('pages::prospector.company-detail', ['companyId' => $this->company->id])
        ->call('openEditScript', $script->id)
        ->set('scriptName', 'Updated Script')
        ->call('saveCallScript');

    expect($script->fresh()->name)->toBe('Updated Script');
});

it('can toggle script active state', function () {
    $script = CallScript::factory()->forCompany($this->company)->create(['is_active' => true]);

    Livewire::test('pages::prospector.company-detail', ['companyId' => $this->company->id])
        ->call('toggleScriptActive', $script->id);

    expect($script->fresh()->is_active)->toBeFalse();
});

it('can delete a call script', function () {
    $script = CallScript::factory()->forCompany($this->company)->create();

    Livewire::test('pages::prospector.company-detail', ['companyId' => $this->company->id])
        ->call('confirmDeleteScript', $script->id)
        ->call('deleteCallScript');

    $this->assertDatabaseMissing('prospector_call_scripts', ['id' => $script->id]);
});

it('can assign script to specific campaign', function () {
    $campaign = Campaign::factory()->create(['company_id' => $this->company->id]);

    Livewire::test('pages::prospector.company-detail', ['companyId' => $this->company->id])
        ->call('openCreateScript')
        ->set('scriptName', 'Campaign Script')
        ->set('scriptBody', 'For this campaign...')
        ->set('scriptCampaignId', $campaign->id)
        ->call('saveCallScript');

    $this->assertDatabaseHas('prospector_call_scripts', [
        'company_id' => $this->company->id,
        'campaign_id' => $campaign->id,
        'name' => 'Campaign Script',
    ]);
});

// ──────────────────────────────────────
// Auth guard
// ──────────────────────────────────────

it('blocks non-internal users from pipeline page', function () {
    $customer = User::factory()->create(['type' => 'customer']);

    $this->actingAs($customer);

    Livewire::test('pages::prospector.pipelines')
        ->assertForbidden();
});

it('blocks non-internal users from campaign list view', function () {
    $customer = User::factory()->create(['type' => 'customer']);

    $this->actingAs($customer);

    Livewire::test('pages::prospector.pipeline-view')
        ->assertForbidden();
});
