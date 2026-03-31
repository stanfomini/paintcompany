<?php

use App\Events\Prospector\CallbackDue;
use App\Events\Prospector\DialerCallEnded;
use App\Models\Prospector\Agent;
use App\Models\Prospector\Call;
use App\Models\Prospector\Campaign;
use App\Models\Prospector\CampaignContact;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Models\Prospector\RvmTemplate;
use App\Models\Prospector\Sequence;
use App\Models\Prospector\TwilioAccount;
use App\Services\Prospector\RvmService;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->account = TwilioAccount::factory()->create();
    $this->company = Company::factory()->forAccount($this->account)->create();
    $this->agent = Agent::factory()->forCompany($this->company)->available()->create();
    $this->contact = Contact::factory()->forCompany($this->company)->create();
    $this->campaign = Campaign::factory()->forCompany($this->company)->active()->create();
});

// ──────────────────────────────────────
// Callback Scheduling
// ──────────────────────────────────────

it('sets contact follow_up_at and campaign_contact to callback_scheduled', function () {
    $this->contact->update(['follow_up_at' => now()->addDay()]);

    $campaignContact = CampaignContact::factory()->create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $this->contact->id,
        'status' => 'completed',
    ]);

    $campaignContact->markCallbackScheduled();

    $campaignContact->refresh();
    expect($campaignContact->status)->toBe('callback_scheduled');
});

it('re-queues due callbacks at front of queue via ProcessCallbacks command', function () {
    Event::fake([CallbackDue::class]);

    // Create a callback_scheduled entry with due follow_up_at
    $this->contact->update(['follow_up_at' => now()->subMinutes(5)]);

    CampaignContact::factory()->create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $this->contact->id,
        'status' => 'callback_scheduled',
        'sort_order' => 10,
    ]);

    // Create other queued entries
    $otherContact = Contact::factory()->forCompany($this->company)->create();
    CampaignContact::factory()->create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $otherContact->id,
        'status' => 'queued',
        'sort_order' => 0,
    ]);

    $this->artisan('prospector:process-callbacks')
        ->assertSuccessful();

    $callbackEntry = CampaignContact::where('contact_id', $this->contact->id)->first();
    expect($callbackEntry->status)->toBe('queued');
    expect($callbackEntry->sort_order)->toBe(0);

    // Other contacts should have been pushed back
    $otherEntry = CampaignContact::where('contact_id', $otherContact->id)->first();
    expect($otherEntry->sort_order)->toBe(1);

    // Follow-up cleared
    $this->contact->refresh();
    expect($this->contact->follow_up_at)->toBeNull();
});

it('broadcasts CallbackDue event when re-queuing', function () {
    Event::fake([CallbackDue::class]);

    $this->contact->update(['follow_up_at' => now()->subMinute()]);

    CampaignContact::factory()->create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $this->contact->id,
        'status' => 'callback_scheduled',
    ]);

    $this->artisan('prospector:process-callbacks')->assertSuccessful();

    Event::assertDispatched(CallbackDue::class, function ($event) {
        return $event->contact->id === $this->contact->id
            && $event->campaignId === $this->campaign->id
            && $event->companyId === $this->company->id;
    });
});

it('does not re-queue callbacks that are not yet due', function () {
    $this->contact->update(['follow_up_at' => now()->addHour()]);

    CampaignContact::factory()->create([
        'campaign_id' => $this->campaign->id,
        'contact_id' => $this->contact->id,
        'status' => 'callback_scheduled',
    ]);

    $this->artisan('prospector:process-callbacks')->assertSuccessful();

    $entry = CampaignContact::where('contact_id', $this->contact->id)->first();
    expect($entry->status)->toBe('callback_scheduled');
});

// ──────────────────────────────────────
// Auto-VM Drop on AMD Detection
// ──────────────────────────────────────

it('auto-drops voicemail when AMD detects machine and campaign has auto_vm_drop enabled', function () {
    Event::fake([DialerCallEnded::class]);

    $template = RvmTemplate::factory()->forCompany($this->company)->create();
    $this->campaign->update([
        'auto_vm_drop' => true,
        'default_rvm_template_id' => $template->id,
    ]);

    $call = Call::factory()->create([
        'company_id' => $this->company->id,
        'agent_id' => $this->agent->id,
        'contact_id' => $this->contact->id,
        'campaign_id' => $this->campaign->id,
        'twilio_call_sid' => 'CA_amd_machine_test',
        'status' => 'ringing',
    ]);

    // Mock RvmService so we don't actually call Twilio
    $rvmMock = Mockery::mock(RvmService::class);
    $rvmMock->shouldReceive('drop')
        ->once()
        ->with(
            Mockery::on(fn ($c) => $c->id === $this->company->id),
            Mockery::on(fn ($c) => $c->id === $this->contact->id),
            Mockery::on(fn ($t) => $t->id === $template->id),
        );
    app()->instance(RvmService::class, $rvmMock);

    $response = $this->post(route('prospector.webhook.twilio.amd'), [
        'CallSid' => 'CA_amd_machine_test',
        'AnsweredBy' => 'machine_start',
    ]);

    $response->assertOk();

    $call->refresh();
    expect($call->status)->toBe('voicemail');
    expect($call->disposition)->toBe('voicemail');
    expect($call->disposition_notes)->toBe('Auto VM drop');
});

it('does not auto-drop when campaign has auto_vm_drop disabled', function () {
    Event::fake([DialerCallEnded::class]);

    $call = Call::factory()->create([
        'company_id' => $this->company->id,
        'agent_id' => $this->agent->id,
        'contact_id' => $this->contact->id,
        'campaign_id' => $this->campaign->id,
        'twilio_call_sid' => 'CA_amd_no_auto',
        'status' => 'ringing',
    ]);

    $response = $this->post(route('prospector.webhook.twilio.amd'), [
        'CallSid' => 'CA_amd_no_auto',
        'AnsweredBy' => 'machine_start',
    ]);

    $response->assertOk();

    $call->refresh();
    expect($call->status)->toBe('voicemail');
    expect($call->disposition)->toBeNull();
});

// ──────────────────────────────────────
// Campaign auto_vm_drop Toggle
// ──────────────────────────────────────

it('saves auto_vm_drop and default_rvm_template_id on campaign', function () {
    $template = RvmTemplate::factory()->forCompany($this->company)->create();

    $this->campaign->update([
        'auto_vm_drop' => true,
        'default_rvm_template_id' => $template->id,
    ]);

    $this->campaign->refresh();
    expect($this->campaign->auto_vm_drop)->toBeTrue();
    expect($this->campaign->default_rvm_template_id)->toBe($template->id);
    expect($this->campaign->defaultRvmTemplate->id)->toBe($template->id);
});

// ──────────────────────────────────────
// Disposition-Triggered Sequence Enrollment
// ──────────────────────────────────────

it('auto-enrolls contact in sequence when disposition has auto_sequence', function () {
    $sequence = Sequence::factory()->forCompany($this->company)->active()->create([
        'name' => 'VM Follow Up',
    ]);

    config(['prospector.dispositions.voicemail.auto_sequence' => 'VM Follow Up']);

    $call = Call::factory()->create([
        'company_id' => $this->company->id,
        'agent_id' => $this->agent->id,
        'contact_id' => $this->contact->id,
        'campaign_id' => $this->campaign->id,
        'status' => 'ringing',
    ]);

    Event::fake([\App\Events\Prospector\ContactDispositioned::class]);

    $service = app(\App\Services\Prospector\PowerDialerService::class);
    $service->dispositionCall($call, 'voicemail', 'Left VM');

    $enrollment = \App\Models\Prospector\SequenceEnrollment::where('contact_id', $this->contact->id)
        ->where('sequence_id', $sequence->id)
        ->first();

    expect($enrollment)->not->toBeNull();
});

it('does not enroll when disposition has no auto_sequence configured', function () {
    $call = Call::factory()->create([
        'company_id' => $this->company->id,
        'agent_id' => $this->agent->id,
        'contact_id' => $this->contact->id,
        'campaign_id' => $this->campaign->id,
        'status' => 'ringing',
    ]);

    Event::fake([\App\Events\Prospector\ContactDispositioned::class]);

    $service = app(\App\Services\Prospector\PowerDialerService::class);
    $service->dispositionCall($call, 'interested');

    $enrollmentCount = \App\Models\Prospector\SequenceEnrollment::where('contact_id', $this->contact->id)->count();
    expect($enrollmentCount)->toBe(0);
});

// ──────────────────────────────────────
// Disposition Config Format
// ──────────────────────────────────────

it('disposition config returns labels correctly in new array format', function () {
    $dispositions = config('prospector.dispositions');

    expect($dispositions['interested'])->toBeArray();
    expect($dispositions['interested']['label'])->toBe('Interested');
    expect($dispositions['callback']['label'])->toBe('Callback Requested');
});
