<?php

use App\Models\Prospector\Call;
use App\Models\Prospector\Campaign;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Models\Prospector\Sequence;
use App\Models\Prospector\SequenceStep;
use App\Models\Prospector\TwilioAccount;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create(['type' => 'internal']);
    $this->account = TwilioAccount::factory()->create();
    $this->company = Company::factory()->forAccount($this->account)->create();
});

// ─── Smoke Tests: All Pages Load Without Errors ──────────

it('loads the dashboard page', function () {
    $this->actingAs($this->user)
        ->get(route('prospector.dashboard'))
        ->assertStatus(200);
});

it('loads the settings page', function () {
    $this->actingAs($this->user)
        ->get(route('prospector.settings'))
        ->assertStatus(200);
});

it('loads the companies page', function () {
    $this->actingAs($this->user)
        ->get(route('prospector.companies'))
        ->assertStatus(200);
});

it('loads the company detail page', function () {
    $this->actingAs($this->user)
        ->get(route('prospector.companies.show', $this->company->id))
        ->assertStatus(200);
});

it('loads the phone numbers page', function () {
    $this->actingAs($this->user)
        ->get(route('prospector.phone-numbers'))
        ->assertStatus(200);
});

it('loads the contacts page', function () {
    $this->actingAs($this->user)
        ->get(route('prospector.contacts'))
        ->assertStatus(200);
});

it('loads the campaigns page', function () {
    $this->actingAs($this->user)
        ->get(route('prospector.campaigns'))
        ->assertStatus(200);
});

it('loads the campaign detail page', function () {
    $campaign = Campaign::factory()->create(['company_id' => $this->company->id]);

    $this->actingAs($this->user)
        ->get(route('prospector.campaigns.show', $campaign->id))
        ->assertStatus(200);
});

it('loads the dialer page', function () {
    $this->actingAs($this->user)
        ->get(route('prospector.dialer'))
        ->assertStatus(200);
});

it('loads the call history page', function () {
    $this->actingAs($this->user)
        ->get(route('prospector.call-history'))
        ->assertStatus(200);
});

it('loads the sequences page', function () {
    $this->actingAs($this->user)
        ->get(route('prospector.sequences'))
        ->assertStatus(200);
});

it('loads the call intelligence page', function () {
    $this->actingAs($this->user)
        ->get(route('prospector.call-intelligence'))
        ->assertStatus(200);
});

it('loads the pipelines page', function () {
    $this->actingAs($this->user)
        ->get(route('prospector.pipelines'))
        ->assertStatus(200);
});

it('loads the pipeline view page', function () {
    $this->actingAs($this->user)
        ->get(route('prospector.pipeline-view'))
        ->assertStatus(200);
});

it('loads the rvm manager page', function () {
    $this->actingAs($this->user)
        ->get(route('prospector.rvm'))
        ->assertStatus(200);
});

// ─── Contacts: Bulk Actions ─────────────────────────────

it('bulk adds contacts to a campaign', function () {
    $campaign = Campaign::factory()->create([
        'company_id' => $this->company->id,
        'status' => 'active',
    ]);

    $contacts = Contact::factory()->forCompany($this->company)->count(3)->create();
    $contactIds = $contacts->pluck('id')->all();

    Livewire::actingAs($this->user)->test('pages::prospector.contacts')
        ->set('selectedContacts', $contactIds)
        ->call('openBulkCampaignModal')
        ->assertSet('showBulkCampaignModal', true)
        ->set('bulkCampaignCompanyId', $this->company->id)
        ->set('bulkCampaignId', $campaign->id)
        ->call('bulkAddToCampaign')
        ->assertSet('showBulkCampaignModal', false)
        ->assertSet('selectedContacts', []);

    // Verify contacts were added
    expect($campaign->contacts()->count())->toBe(3);
});

it('bulk adds tags to contacts', function () {
    $contacts = Contact::factory()->forCompany($this->company)->count(2)->create([
        'tags' => ['existing'],
    ]);
    $contactIds = $contacts->pluck('id')->all();

    Livewire::actingAs($this->user)->test('pages::prospector.contacts')
        ->set('selectedContacts', $contactIds)
        ->call('openBulkTagModal')
        ->assertSet('showBulkTagModal', true)
        ->set('bulkTagInput', 'newtag, another')
        ->call('bulkAddTag')
        ->assertSet('showBulkTagModal', false);

    // Verify tags were added
    $updated = Contact::find($contacts->first()->id);
    expect($updated->tags)->toContain('existing');
    expect($updated->tags)->toContain('newtag');
    expect($updated->tags)->toContain('another');
});

it('bulk deletes contacts', function () {
    $contacts = Contact::factory()->forCompany($this->company)->count(3)->create();
    $contactIds = $contacts->pluck('id')->all();

    Livewire::actingAs($this->user)->test('pages::prospector.contacts')
        ->set('selectedContacts', $contactIds)
        ->call('openBulkDeleteModal')
        ->assertSet('showBulkDeleteModal', true)
        ->call('bulkDelete')
        ->assertSet('showBulkDeleteModal', false)
        ->assertSet('selectedContacts', []);

    // Contacts should be soft deleted
    expect(Contact::whereIn('id', $contactIds)->count())->toBe(0);
});

it('skips DNC contacts in bulk campaign add', function () {
    $campaign = Campaign::factory()->create([
        'company_id' => $this->company->id,
        'status' => 'active',
    ]);

    $activeContact = Contact::factory()->forCompany($this->company)->create(['do_not_call' => false]);
    $dncContact = Contact::factory()->forCompany($this->company)->create(['do_not_call' => true]);

    Livewire::actingAs($this->user)->test('pages::prospector.contacts')
        ->set('selectedContacts', [$activeContact->id, $dncContact->id])
        ->set('bulkCampaignCompanyId', $this->company->id)
        ->set('bulkCampaignId', $campaign->id)
        ->call('bulkAddToCampaign');

    // Only the active contact should be added
    expect($campaign->contacts()->count())->toBe(1);
    expect($campaign->contacts()->first()->id)->toBe($activeContact->id);
});

// ─── Campaigns: Clone ───────────────────────────────────

it('clones a campaign', function () {
    $original = Campaign::factory()->create([
        'company_id' => $this->company->id,
        'name' => 'Original Campaign',
        'status' => 'active',
        'priority' => 5,
    ]);

    Livewire::actingAs($this->user)->test('pages::prospector.campaigns')
        ->call('cloneCampaign', $original->id);

    $clone = Campaign::where('name', 'Original Campaign (Copy)')->first();
    expect($clone)->not->toBeNull();
    expect($clone->company_id)->toBe($original->company_id);
    expect($clone->status)->toBe('draft'); // Clones start as draft
    expect($clone->priority)->toBe(5);
});

// ─── Call History: Export ────────────────────────────────

it('exports call history as CSV', function () {
    $contact = Contact::factory()->forCompany($this->company)->create();

    Call::factory()->count(3)->create([
        'company_id' => $this->company->id,
        'contact_id' => $contact->id,
        'started_at' => now(),
    ]);

    Livewire::actingAs($this->user)->test('pages::prospector.call-history')
        ->call('exportCsv')
        ->assertFileDownloaded();
});

// ─── Contacts: Export CSV ───────────────────────────────

it('exports contacts as CSV', function () {
    Contact::factory()->forCompany($this->company)->count(3)->create();

    Livewire::actingAs($this->user)->test('pages::prospector.contacts')
        ->call('exportCsv')
        ->assertFileDownloaded();
});

// ─── Settings: Test Connection ─────────────────────────

it('has test connection action on settings page', function () {
    Livewire::actingAs($this->user)->test('pages::prospector.settings')
        ->assertSee('Test')
        ->assertSet('testResult', null)
        ->assertSet('testingAccountId', null);
});

// ─── Settings: Connection Status Indicator ─────────────

it('shows connection status indicator on settings page', function () {
    $this->actingAs($this->user)
        ->get(route('prospector.settings'))
        ->assertStatus(200)
        ->assertSee('Twilio Connected');
});

// ─── Companies: Stats Columns ──────────────────────────

it('shows contacts and campaigns count on companies page', function () {
    Contact::factory()->forCompany($this->company)->count(2)->create();
    Campaign::factory()->create(['company_id' => $this->company->id]);

    $this->actingAs($this->user)
        ->get(route('prospector.companies'))
        ->assertStatus(200)
        ->assertSee('Contacts')
        ->assertSee('Campaigns');
});

// ─── Company Detail: IVR Configuration ─────────────────

it('shows IVR configuration section on company detail page', function () {
    $this->actingAs($this->user)
        ->get(route('prospector.companies.show', $this->company->id))
        ->assertStatus(200)
        ->assertSee('IVR Configuration');
});

it('saves IVR settings on company detail page', function () {
    Livewire::actingAs($this->user)->test('pages::prospector.company-detail', ['companyId' => $this->company->id])
        ->set('ivrEnabled', true)
        ->set('bhGreeting', 'Press 1 for sales.')
        ->set('ahGreeting', 'We are closed. Leave a message.')
        ->call('saveIvr');

    $this->company->refresh();
    expect(data_get($this->company->settings, 'ivr.enabled'))->toBeTrue();
    expect(data_get($this->company->settings, 'ivr.business_hours.greeting'))->toBe('Press 1 for sales.');
    expect(data_get($this->company->settings, 'ivr.after_hours.greeting'))->toBe('We are closed. Leave a message.');
});

// ─── Dashboard: Live Calls Panel Always Visible ────────

it('shows live calls panel even with no active calls', function () {
    $this->actingAs($this->user)
        ->get(route('prospector.dashboard'))
        ->assertStatus(200)
        ->assertSee('Live Calls')
        ->assertSee('No live calls at the moment.');
});

// ─── Sequences: Clone ─────────────────────────────────

it('clones a sequence with steps', function () {
    $original = Sequence::factory()->forCompany($this->company)->create([
        'name' => 'Original Sequence',
        'status' => 'active',
        'trigger_type' => 'new_contact',
        'business_hours_only' => true,
    ]);

    SequenceStep::factory()->forSequence($original)->sms('Hello!')->atOrder(0)->create();
    SequenceStep::factory()->forSequence($original)->wait(2, 'days')->atOrder(1)->create();
    SequenceStep::factory()->forSequence($original)->sms('Follow up')->atOrder(2)->create();

    Livewire::actingAs($this->user)->test('pages::prospector.sequences')
        ->call('cloneSequence', $original->id);

    $clone = Sequence::where('name', 'Original Sequence (Copy)')->first();
    expect($clone)->not->toBeNull();
    expect($clone->company_id)->toBe($original->company_id);
    expect($clone->status)->toBe('draft');
    expect($clone->trigger_type)->toBe('new_contact');
    expect($clone->business_hours_only)->toBeTrue();
    expect($clone->steps()->count())->toBe(3);
});

// ─── Call Intelligence: Sorting ────────────────────────

it('sorts call intelligence by lead score', function () {
    Livewire::actingAs($this->user)->test('pages::prospector.call-intelligence')
        ->call('sortByColumn', 'lead_score')
        ->assertSet('sortBy', 'lead_score')
        ->assertSet('sortDir', 'desc')
        ->call('sortByColumn', 'lead_score')
        ->assertSet('sortDir', 'asc');
});

it('sorts call intelligence by sentiment', function () {
    Livewire::actingAs($this->user)->test('pages::prospector.call-intelligence')
        ->call('sortByColumn', 'sentiment_overall')
        ->assertSet('sortBy', 'sentiment_overall')
        ->assertSet('sortDir', 'desc');
});
