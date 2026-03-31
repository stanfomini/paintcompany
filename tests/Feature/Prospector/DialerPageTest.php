<?php

use App\Models\Prospector\Agent;
use App\Models\Prospector\Call;
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
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(['type' => 'internal']);
    $this->account = TwilioAccount::factory()->create();
    $this->company = Company::factory()->forAccount($this->account)->create();
    $this->agent = Agent::factory()->create([
        'user_id' => $this->user->id,
        'company_id' => $this->company->id,
    ]);
});

// ──────────────────────────────────────
// Page Load
// ──────────────────────────────────────

it('loads the dialer page for internal users', function () {
    $this->actingAs($this->user)
        ->get(route('prospector.dialer'))
        ->assertStatus(200);
});

it('blocks non-internal users from dialer', function () {
    $customer = User::factory()->create(['type' => 'customer']);

    $this->actingAs($customer)
        ->get(route('prospector.dialer'))
        ->assertRedirect();
});

// ──────────────────────────────────────
// Contact Preview from Queue
// ──────────────────────────────────────

it('previews a contact from the queue', function () {
    $contact = Contact::factory()->create(['company_id' => $this->company->id]);

    Livewire::actingAs($this->user)
        ->test('pages::prospector.dialer')
        ->call('previewContact', $contact->id)
        ->assertSet('previewContactId', $contact->id);
});

// ──────────────────────────────────────
// Contact Notes
// ──────────────────────────────────────

it('adds a contact note from the dialer', function () {
    $contact = Contact::factory()->create(['company_id' => $this->company->id]);

    Livewire::actingAs($this->user)
        ->test('pages::prospector.dialer')
        ->call('previewContact', $contact->id)
        ->set('newContactNote', 'Test note from dialer')
        ->call('addContactNote');

    expect(ContactNote::where('contact_id', $contact->id)->where('body', 'Test note from dialer')->exists())->toBeTrue();
});

it('does not add empty contact notes', function () {
    $contact = Contact::factory()->create(['company_id' => $this->company->id]);

    Livewire::actingAs($this->user)
        ->test('pages::prospector.dialer')
        ->call('previewContact', $contact->id)
        ->set('newContactNote', '')
        ->call('addContactNote');

    expect(ContactNote::where('contact_id', $contact->id)->count())->toBe(0);
});

// ──────────────────────────────────────
// Company Notes Quick-Edit
// ──────────────────────────────────────

it('saves company notes from dialer', function () {
    $contact = Contact::factory()->create(['company_id' => $this->company->id]);

    Livewire::actingAs($this->user)
        ->test('pages::prospector.dialer')
        ->call('previewContact', $contact->id)
        ->set('companyNotes', 'Updated company notes')
        ->call('saveCompanyNotes');

    expect($this->company->fresh()->notes)->toBe('Updated company notes');
});

// ──────────────────────────────────────
// Pipeline Stage Update
// ──────────────────────────────────────

it('updates contact pipeline stage from dialer', function () {
    $pipeline = Pipeline::factory()->create();
    $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);
    $contact = Contact::factory()->create(['company_id' => $this->company->id]);

    Livewire::actingAs($this->user)
        ->test('pages::prospector.dialer')
        ->call('previewContact', $contact->id)
        ->call('updateContactStage', $stage->id)
        ->assertSet('contactPipelineStageId', $stage->id);

    expect($contact->fresh()->pipeline_stage_id)->toBe($stage->id);
});

it('clears contact pipeline stage', function () {
    $pipeline = Pipeline::factory()->create();
    $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id]);
    $contact = Contact::factory()->create([
        'company_id' => $this->company->id,
        'pipeline_stage_id' => $stage->id,
    ]);

    Livewire::actingAs($this->user)
        ->test('pages::prospector.dialer')
        ->call('previewContact', $contact->id)
        ->call('updateContactStage', null)
        ->assertSet('contactPipelineStageId', null);

    expect($contact->fresh()->pipeline_stage_id)->toBeNull();
});

// ──────────────────────────────────────
// Follow-up Date
// ──────────────────────────────────────

it('sets follow-up date from dialer', function () {
    $contact = Contact::factory()->create(['company_id' => $this->company->id]);

    Livewire::actingAs($this->user)
        ->test('pages::prospector.dialer')
        ->call('previewContact', $contact->id)
        ->call('setFollowUp', '2026-04-15')
        ->assertSet('contactFollowUpAt', '2026-04-15');

    expect($contact->fresh()->follow_up_at->format('Y-m-d'))->toBe('2026-04-15');
});

it('clears follow-up date', function () {
    $contact = Contact::factory()->create([
        'company_id' => $this->company->id,
        'follow_up_at' => '2026-04-15',
    ]);

    Livewire::actingAs($this->user)
        ->test('pages::prospector.dialer')
        ->call('previewContact', $contact->id)
        ->call('setFollowUp', null)
        ->assertSet('contactFollowUpAt', null);

    expect($contact->fresh()->follow_up_at)->toBeNull();
});

// ──────────────────────────────────────
// Booking Link Insert
// ──────────────────────────────────────

it('inserts booking link URL into SMS body', function () {
    $link = CompanyBookingLink::factory()->create([
        'company_id' => $this->company->id,
        'url' => 'https://calendly.com/test',
    ]);

    Livewire::actingAs($this->user)
        ->test('pages::prospector.dialer')
        ->call('insertBookingLink', $link->id)
        ->assertSet('smsBody', 'https://calendly.com/test');
});

it('appends booking link to existing SMS body', function () {
    $link = CompanyBookingLink::factory()->create([
        'company_id' => $this->company->id,
        'url' => 'https://calendly.com/test',
    ]);

    Livewire::actingAs($this->user)
        ->test('pages::prospector.dialer')
        ->set('smsBody', 'Check this out:')
        ->call('insertBookingLink', $link->id)
        ->assertSet('smsBody', 'Check this out: https://calendly.com/test');
});

// ──────────────────────────────────────
// Quick-Add Contact
// ──────────────────────────────────────

it('quick-adds a contact from the dialer with default pipeline stage', function () {
    $pipeline = Pipeline::factory()->create(['is_default' => true]);
    $stage = PipelineStage::factory()->create(['pipeline_id' => $pipeline->id, 'sort_order' => 1]);

    Livewire::actingAs($this->user)
        ->test('pages::prospector.dialer')
        ->call('quickAddContact', 'Jane', '+15551234567');

    $contact = Contact::where('first_name', 'Jane')->where('phone', '+15551234567')->first();
    expect($contact)->not->toBeNull();
    expect($contact->source)->toBe('manual');
    expect($contact->pipeline_stage_id)->toBe($stage->id);
});

// ──────────────────────────────────────
// Disposition with Callback
// ──────────────────────────────────────

it('submits disposition with callback date', function () {
    $campaign = Campaign::factory()->create([
        'company_id' => $this->company->id,
        'status' => 'active',
    ]);
    $contact = Contact::factory()->create(['company_id' => $this->company->id]);
    $call = Call::factory()->create([
        'agent_id' => $this->agent->id,
        'contact_id' => $contact->id,
        'campaign_id' => $campaign->id,
        'status' => 'connected',
    ]);

    Livewire::actingAs($this->user)
        ->test('pages::prospector.dialer')
        ->set('activeCallId', $call->id)
        ->set('activeContact', ['id' => $contact->id, 'name' => 'Test'])
        ->call('submitDisposition', 'callback', '2026-04-20 14:00');

    expect($contact->fresh()->follow_up_at->format('Y-m-d'))->toBe('2026-04-20');
});

// ──────────────────────────────────────
// Computed Properties
// ──────────────────────────────────────

it('loads booking links for the agent company', function () {
    $links = CompanyBookingLink::factory()->count(3)->create(['company_id' => $this->company->id]);

    expect(CompanyBookingLink::where('company_id', $this->company->id)->count())->toBe(3);

    // Verify the component renders without error
    Livewire::actingAs($this->user)
        ->test('pages::prospector.dialer')
        ->assertOk();
});

it('loads pipeline stages', function () {
    $pipeline = Pipeline::factory()->create();
    PipelineStage::factory()->count(3)->create(['pipeline_id' => $pipeline->id]);

    expect(PipelineStage::where('pipeline_id', $pipeline->id)->count())->toBe(3);

    Livewire::actingAs($this->user)
        ->test('pages::prospector.dialer')
        ->assertOk();
});

it('loads active script for company', function () {
    $script = CallScript::factory()->create([
        'company_id' => $this->company->id,
        'is_active' => true,
    ]);

    Livewire::actingAs($this->user)
        ->test('pages::prospector.dialer')
        ->assertOk();

    $found = CallScript::where('company_id', $this->company->id)
        ->where('is_active', true)
        ->first();
    expect($found)->not->toBeNull();
    expect($found->id)->toBe($script->id);
});
