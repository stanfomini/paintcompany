<?php

use App\Models\Prospector\Campaign;
use App\Models\Prospector\CampaignContact;
use App\Models\Prospector\Company;
use App\Models\Prospector\Contact;
use App\Models\Prospector\Sequence;
use App\Models\Prospector\SequenceEnrollment;
use App\Models\Prospector\SequenceStep;
use App\Models\Prospector\SequenceStepLog;
use App\Models\Prospector\SmsMessage;
use App\Models\Prospector\TwilioAccount;
use App\Services\Prospector\SequenceEngine;

beforeEach(function () {
    $this->account = TwilioAccount::factory()->create();
    $this->company = Company::factory()->forAccount($this->account)->create([
        'timezone' => 'America/Denver',
        'business_hours_start' => '09:00',
        'business_hours_end' => '17:00',
        'business_days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
    ]);
    $this->contact = Contact::factory()->create(['company_id' => $this->company->id, 'first_name' => 'Jane', 'last_name' => 'Doe']);
    $this->engine = app(SequenceEngine::class);
});

it('enrolls a contact and sets first step', function () {
    $sequence = Sequence::factory()->active()->create(['company_id' => $this->company->id]);
    $step1 = SequenceStep::factory()->forSequence($sequence)->sms('Hello')->atOrder(0)->create();
    $step2 = SequenceStep::factory()->forSequence($sequence)->wait(1, 'days')->atOrder(1)->create();

    $enrollment = $this->engine->enrollContact($this->contact, $sequence);

    expect($enrollment->status)->toBe('active');
    expect($enrollment->current_step_id)->toBe($step1->id);
    expect($enrollment->next_execution_at)->not->toBeNull();
    expect($enrollment->enrolled_at)->not->toBeNull();
});

it('replaces variables in message text', function () {
    $result = $this->engine->replaceVariables(
        'Hi {first_name} {last_name}, from {company_name}',
        $this->contact,
        $this->company
    );

    expect($result)->toContain('Jane');
    expect($result)->toContain('Doe');
    expect($result)->toContain($this->company->name);
});

it('executes a wait step and calculates correct next_execution_at', function () {
    $sequence = Sequence::factory()->active()->create(['company_id' => $this->company->id]);
    $step1 = SequenceStep::factory()->forSequence($sequence)->wait(2, 'hours')->atOrder(0)->create();
    $step2 = SequenceStep::factory()->forSequence($sequence)->sms('Follow up')->atOrder(1)->create();

    $enrollment = $this->engine->enrollContact($this->contact, $sequence);
    $enrollment->update(['next_execution_at' => now()->subMinute()]);

    $this->engine->executeStep($enrollment);
    $enrollment->refresh();

    expect($enrollment->current_step_id)->toBe($step2->id);
    expect($enrollment->next_execution_at->gt(now()->addMinutes(119)))->toBeTrue();

    $log = SequenceStepLog::where('enrollment_id', $enrollment->id)->first();
    expect($log->status)->toBe('sent');
    expect($log->result['delay_minutes'])->toBe(120);
});

it('completes sequence when no more steps', function () {
    $sequence = Sequence::factory()->active()->create(['company_id' => $this->company->id]);
    $step = SequenceStep::factory()->forSequence($sequence)->wait(0, 'minutes')->atOrder(0)->create();

    $enrollment = $this->engine->enrollContact($this->contact, $sequence);
    $enrollment->update(['next_execution_at' => now()->subMinute()]);

    $this->engine->executeStep($enrollment);
    $enrollment->refresh();

    expect($enrollment->status)->toBe('completed');
    expect($enrollment->current_step_id)->toBeNull();
    expect($enrollment->completed_at)->not->toBeNull();
});

it('exits enrollment when contact replies', function () {
    $sequence = Sequence::factory()->active()->create(['company_id' => $this->company->id]);
    $step = SequenceStep::factory()->forSequence($sequence)->sms('Hey')->atOrder(0)->create();

    $enrollment = $this->engine->enrollContact($this->contact, $sequence);

    // Simulate inbound SMS after enrollment
    SmsMessage::create([
        'company_id' => $this->company->id,
        'contact_id' => $this->contact->id,
        'direction' => 'inbound',
        'from_number' => $this->contact->phone,
        'to_number' => '+13035550001',
        'body' => 'Stop texting me',
        'status' => 'received',
    ]);

    $exited = $this->engine->checkExitConditions($enrollment);

    expect($exited)->toBeTrue();
    $enrollment->refresh();
    expect($enrollment->status)->toBe('exited');
    expect($enrollment->exit_reason)->toBe('contact_replied');
});

it('exits enrollment when contact is DNC', function () {
    $this->contact->update(['do_not_call' => true]);
    $sequence = Sequence::factory()->active()->create(['company_id' => $this->company->id]);
    $step = SequenceStep::factory()->forSequence($sequence)->sms('Hey')->atOrder(0)->create();

    $enrollment = $this->engine->enrollContact($this->contact, $sequence);
    $exited = $this->engine->checkExitConditions($enrollment);

    expect($exited)->toBeTrue();
    $enrollment->refresh();
    expect($enrollment->status)->toBe('exited');
    expect($enrollment->exit_reason)->toBe('do_not_call');
});

it('executes condition step that passes', function () {
    $this->contact->update(['last_disposition' => 'interested']);

    $sequence = Sequence::factory()->active()->create(['company_id' => $this->company->id]);
    $step1 = SequenceStep::factory()->forSequence($sequence)->condition('last_disposition', 'equals', 'interested')->atOrder(0)->create();
    $step2 = SequenceStep::factory()->forSequence($sequence)->sms('Great news!')->atOrder(1)->create();

    $enrollment = SequenceEnrollment::factory()->forSequence($sequence)->forContact($this->contact)->create([
        'current_step_id' => $step1->id,
        'next_execution_at' => now()->subMinute(),
    ]);

    $this->engine->executeStep($enrollment);
    $enrollment->refresh();

    expect($enrollment->current_step_id)->toBe($step2->id);

    $log = SequenceStepLog::where('enrollment_id', $enrollment->id)->first();
    expect($log->status)->toBe('sent');
    expect($log->result['passed'])->toBeTrue();
});

it('executes condition step that fails and exits', function () {
    $this->contact->update(['last_disposition' => 'not_interested']);

    $sequence = Sequence::factory()->active()->create(['company_id' => $this->company->id]);
    $step1 = SequenceStep::factory()->forSequence($sequence)->condition('last_disposition', 'equals', 'interested')->atOrder(0)->create();
    $step2 = SequenceStep::factory()->forSequence($sequence)->sms('Great news!')->atOrder(1)->create();

    $enrollment = SequenceEnrollment::factory()->forSequence($sequence)->forContact($this->contact)->create([
        'current_step_id' => $step1->id,
        'next_execution_at' => now()->subMinute(),
    ]);

    $this->engine->executeStep($enrollment);
    $enrollment->refresh();

    expect($enrollment->status)->toBe('exited');

    $log = SequenceStepLog::where('enrollment_id', $enrollment->id)->first();
    expect($log->status)->toBe('skipped');
    expect($log->result['passed'])->toBeFalse();
});

it('processes due enrollments in batch', function () {
    $sequence = Sequence::factory()->active()->create(['company_id' => $this->company->id]);
    $step1 = SequenceStep::factory()->forSequence($sequence)->wait(0, 'minutes')->atOrder(0)->create();
    $step2 = SequenceStep::factory()->forSequence($sequence)->sms('Done')->atOrder(1)->create();

    // Create 3 due enrollments
    $contacts = Contact::factory()->count(3)->create(['company_id' => $this->company->id]);
    foreach ($contacts as $c) {
        SequenceEnrollment::factory()->forSequence($sequence)->forContact($c)->create([
            'current_step_id' => $step1->id,
            'next_execution_at' => now()->subMinute(),
        ]);
    }

    // Also create a not-due enrollment
    $notDue = Contact::factory()->create(['company_id' => $this->company->id]);
    SequenceEnrollment::factory()->forSequence($sequence)->forContact($notDue)->notDueYet()->create([
        'current_step_id' => $step1->id,
    ]);

    $count = $this->engine->processDueEnrollments();

    expect($count)->toBe(3);
    expect(SequenceStepLog::count())->toBe(3);
});

it('advances to next step correctly', function () {
    $sequence = Sequence::factory()->active()->create(['company_id' => $this->company->id]);
    $step1 = SequenceStep::factory()->forSequence($sequence)->sms('Step 1')->atOrder(0)->create();
    $step2 = SequenceStep::factory()->forSequence($sequence)->sms('Step 2')->atOrder(1)->create();
    $step3 = SequenceStep::factory()->forSequence($sequence)->sms('Step 3')->atOrder(2)->create();

    $enrollment = SequenceEnrollment::factory()->forSequence($sequence)->forContact($this->contact)->create([
        'current_step_id' => $step1->id,
    ]);

    $this->engine->advanceToNextStep($enrollment);
    $enrollment->refresh();
    expect($enrollment->current_step_id)->toBe($step2->id);

    $this->engine->advanceToNextStep($enrollment);
    $enrollment->refresh();
    expect($enrollment->current_step_id)->toBe($step3->id);

    $this->engine->advanceToNextStep($enrollment);
    $enrollment->refresh();
    expect($enrollment->status)->toBe('completed');
});

it('executes call step and adds contact to campaign queue', function () {
    $campaign = Campaign::factory()->active()->create(['company_id' => $this->company->id]);

    $sequence = Sequence::factory()->active()->create(['company_id' => $this->company->id]);
    $step = SequenceStep::factory()->forSequence($sequence)->call('Follow the script')->atOrder(0)->create();

    $enrollment = SequenceEnrollment::factory()->forSequence($sequence)->forContact($this->contact)->create([
        'current_step_id' => $step->id,
        'next_execution_at' => now()->subMinute(),
    ]);

    $this->engine->executeStep($enrollment);

    $campaignContact = CampaignContact::where('campaign_id', $campaign->id)
        ->where('contact_id', $this->contact->id)
        ->first();

    expect($campaignContact)->not->toBeNull();
    expect($campaignContact->status)->toBe('queued');
    expect($campaignContact->sort_order)->toBe(0);
});
